<?php
/**
 * Settings > Inquiry Connector. Requires manage_options; every action is nonce-protected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Admin {

	const PAGE = 'aiq-connector';
	const CAP  = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_post_aiq_send_test', array( __CLASS__, 'send_test' ) );
		add_action( 'admin_post_aiq_process_outbox', array( __CLASS__, 'process_outbox' ) );
		add_action( 'admin_post_aiq_retry_failed', array( __CLASS__, 'retry_failed' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AIQ_CONNECTOR_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'Inquiry Connector', 'Inquiry Connector', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function settings() {
		register_setting(
			'aiq_settings_group',
			AIQ_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'AIQ_Settings', 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => AIQ_Settings::defaults(),
			)
		);
	}

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $notice ) {
		// Stored per user (not in the URL) so a crafted link cannot inject notice text.
		set_transient( 'aiq_notice_' . get_current_user_id(), (string) $notice, 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function send_test() {
		self::guard( 'aiq_send_test' );
		$user   = wp_get_current_user();
		$result = aiq_forward_inquiry(
			array(
				'form_type' => 'contact',
				'form_id'   => 'wordpress-connection-test',
				'full_name' => 'WordPress connection test',
				'message'   => 'Test inquiry from ' . home_url( '/' ) . ' sent by ' . $user->user_login . ' at ' . gmdate( 'c' ),
				'test'      => true,
			),
			array( 'label' => 'Connection test' )
		);
		if ( is_wp_error( $result ) ) {
			self::back( 'error:' . $result->get_error_message() );
		}
		if ( $result['ok'] ) {
			self::back( 'success:Test inquiry delivered (inquiry #' . (int) $result['inquiry_id'] . '). Check the Inquiry Manager inbox.' );
		}
		self::back( 'error:Test failed: HTTP ' . (int) $result['status'] . ' ' . $result['code'] . ' — ' . $result['message'] . ( $result['queued'] ? ' (queued for retry)' : '' ) );
	}

	public static function process_outbox() {
		self::guard( 'aiq_process_outbox' );
		$n = AIQ_Outbox::process_due();
		self::back( 'success:Processed ' . (int) $n . ' queued inquiries.' );
	}

	public static function retry_failed() {
		self::guard( 'aiq_retry_failed' );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = AIQ_Outbox::retry_failed( $id );
		self::back( ( $result['ok'] ? 'success:Delivered.' : 'error:Still failing: ' . $result['code'] ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.' ), 403 );
		}
		$s       = AIQ_Settings::all();
		$counts  = AIQ_Outbox::counts();
		$log     = get_option( AIQ_Outbox::LOG_OPTION, array() );
		$failed  = AIQ_Outbox::failed_rows();
		$rules   = is_array( $s['rules'] ) ? $s['rules'] : array();
		$rules[] = array( 'source' => 'cf7', 'form' => '', 'form_type' => 'contact', 'map' => '', 'enabled' => 1 );
		$rules[] = array( 'source' => 'elementor', 'form' => '', 'form_type' => 'quote', 'map' => '', 'enabled' => 1 );
		$name    = AIQ_Settings::OPTION;
		$notice  = (string) get_transient( 'aiq_notice_' . get_current_user_id() );
		delete_transient( 'aiq_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>Inquiry Connector</h1>
			<?php if ( '' !== $notice ) : list( $type, $msg ) = array_pad( explode( ':', $notice, 2 ), 2, '' ); ?>
				<div class="notice notice-<?php echo 'success' === $type ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( AIQ_Settings::OPTION ); ?>

			<p>Forwards selected form submissions to the Inquiry Manager from this server. The API key never reaches visitors' browsers. Form emails keep working as before.</p>
			<p><strong>One method per form:</strong> forward a form either with a rule below <em>or</em> with a <code>functions.php</code> snippet, not both. Snippets call <code>aiq_connector_handles()</code> and skip forms that have a rule here.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'aiq_settings_group' ); ?>
				<h2>Connection</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aiq-endpoint">Endpoint URL</label></th>
						<td>
							<?php if ( defined( 'AIQ_ENDPOINT' ) && AIQ_ENDPOINT ) : ?>
								<code><?php echo esc_html( AIQ_ENDPOINT ); ?></code> <span class="description">(set in wp-config.php)</span>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>">
							<?php else : ?>
								<input type="url" class="regular-text code" id="aiq-endpoint" name="<?php echo esc_attr( $name ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://inquiries.example.com/api/v1/submissions">
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiq-key">Website API key</label></th>
						<td>
							<?php if ( AIQ_Settings::key_from_constant() ) : ?>
								<code><?php echo esc_html( AIQ_Settings::masked_key() ); ?></code> <span class="description">(defined as <code>AIQ_API_KEY</code> in wp-config.php — recommended)</span>
							<?php else : ?>
								<?php if ( '' !== $s['api_key'] ) : ?>
									<p>Stored key: <code><?php echo esc_html( AIQ_Settings::masked_key() ); ?></code></p>
								<?php endif; ?>
								<input type="password" class="regular-text code" id="aiq-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" autocomplete="new-password" placeholder="<?php echo '' !== $s['api_key'] ? 'Leave blank to keep the stored key' : 'aiq_…'; ?>">
								<?php if ( '' !== $s['api_key'] ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1"> Remove stored key</label>
								<?php endif; ?>
								<p class="description">Safer: add <code>define( 'AIQ_API_KEY', 'aiq_…' );</code> to wp-config.php instead of storing the key in the database.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiq-timeout">Timeout (seconds)</label></th>
						<td><input type="number" min="3" max="15" id="aiq-timeout" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row">Options</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[send_visitor_ip]" value="1" <?php checked( $s['send_visitor_ip'] ); ?>> Send the visitor's IP address</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[forward_unmapped]" value="1" <?php checked( $s['forward_unmapped'] ); ?>> Forward unmapped fields as custom answers</label>
						</td>
					</tr>
				</table>

				<h2>Forms to forward</h2>
				<p class="description">Contact Form 7: use the form ID (the number in the shortcode, e.g. <code>123</code>), its hash ID, or its title. Elementor: use the <em>Form Name</em> set in the widget (Content &gt; Form Fields &gt; Form Name).</p>
				<p class="description">Field map, one per line: <code>your-name = full_name</code>. Targets: <code>full_name company email phone country address city postcode message</code>, <code>products[1].name</code>, <code>products[1].quantity</code>, <code>products[1].sku</code>, <code>fields.Your label</code>, <code>ignore</code>.</p>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>On</th><th>Source</th><th>Form</th><th>Type</th><th>Field map</th></tr></thead>
					<tbody>
					<?php foreach ( $rules as $i => $rule ) : $p = $name . '[rules][' . (int) $i . ']'; ?>
						<tr>
							<td><input type="checkbox" name="<?php echo esc_attr( $p ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> aria-label="Enabled"></td>
							<td><select name="<?php echo esc_attr( $p ); ?>[source]" aria-label="Source">
								<?php foreach ( AIQ_Settings::SOURCES as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['source'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><input type="text" name="<?php echo esc_attr( $p ); ?>[form]" value="<?php echo esc_attr( $rule['form'] ); ?>" placeholder="<?php echo '' === $rule['form'] ? 'Add a form…' : ''; ?>" aria-label="Form identifier"></td>
							<td><select name="<?php echo esc_attr( $p ); ?>[form_type]" aria-label="Inquiry type">
								<?php foreach ( AIQ_Settings::FORM_TYPES as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rule['form_type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><textarea name="<?php echo esc_attr( $p ); ?>[map]" rows="4" cols="48" class="code" aria-label="Field map" placeholder="your-name = full_name&#10;your-email = email&#10;your-message = message"><?php echo esc_textarea( $rule['map'] ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Clear the form identifier to delete a rule. Save to get more empty rows.</p>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2>Test and status</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:1em">
				<input type="hidden" name="action" value="aiq_send_test">
				<?php wp_nonce_field( 'aiq_send_test' ); ?>
				<?php submit_button( 'Send test inquiry', 'secondary', 'submit', false, AIQ_Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="aiq_process_outbox">
				<?php wp_nonce_field( 'aiq_process_outbox' ); ?>
				<?php submit_button( 'Retry queued now', 'secondary', 'submit', false ); ?>
			</form>
			<p>Queued for retry: <strong><?php echo (int) $counts['pending']; ?></strong> · Failed (needs attention): <strong><?php echo (int) $counts['failed']; ?></strong>.
				Retries run every 5 minutes through WP-Cron (triggered by site visits; set a real cron for low-traffic sites).</p>

			<?php if ( $failed ) : ?>
				<h3>Failed deliveries</h3>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>When (UTC)</th><th>Form</th><th>Result</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $failed as $f ) : ?>
						<tr>
							<td><?php echo esc_html( $f['updated_at'] ); ?></td>
							<td><?php echo esc_html( $f['form_label'] ); ?></td>
							<td><code><?php echo esc_html( $f['last_status'] . ' ' . $f['last_error'] ); ?></code></td>
							<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="aiq_retry_failed"><input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
								<?php wp_nonce_field( 'aiq_retry_failed' ); ?>
								<button class="button button-small">Retry</button></form></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3>Recent deliveries</h3>
			<?php if ( ! $log ) : ?>
				<p>No deliveries yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>When</th><th>Source</th><th>Form</th><th>Outcome</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $l ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $l['time'] ) ); ?></td>
							<td><?php echo esc_html( $l['source'] ); ?></td>
							<td><?php echo esc_html( $l['form'] ); ?></td>
							<td><?php echo $l['ok'] ? 'Delivered' . ( $l['inquiry_id'] ? ' (#' . (int) $l['inquiry_id'] . ')' : '' ) : esc_html( 'HTTP ' . $l['status'] . ' ' . $l['code'] . ( $l['queued'] ? ' — queued for retry' : '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
