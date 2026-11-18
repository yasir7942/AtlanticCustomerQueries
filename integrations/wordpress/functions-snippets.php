<?php
/**
 * Inquiry Manager — copy-ready snippets for a CHILD THEME's functions.php
 * (use these INSTEAD of the plugin for a given form, never both for the same form).
 *
 * 1. In wp-config.php, above "That's all, stop editing!":
 *      define( 'AIQ_ENDPOINT', 'https://inquiries.example.com/api/v1/submissions' );
 *      define( 'AIQ_API_KEY',  'aiq_xxxxxxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
 * 2. Paste section A (required) and then B, C and/or D into your child theme's functions.php.
 * 3. Edit the form IDs/names and field maps marked "EDIT".
 *
 * The key stays in wp-config.php on the server. Nothing here prints it or sends it to browsers.
 */

/* ---------------------------------------------------------------------------
 * A. Sender with retries (required by B, C, D)
 * ------------------------------------------------------------------------ */

if ( ! function_exists( 'aiqs_send' ) ) {
	/**
	 * Send a payload. Tries twice immediately; on a transient failure schedules a WP-Cron retry
	 * that reuses the SAME submission_id, so the Inquiry Manager stores it only once.
	 */
	function aiqs_send( array $payload, $attempt = 1 ) {
		if ( ! defined( 'AIQ_ENDPOINT' ) || ! defined( 'AIQ_API_KEY' ) ) {
			error_log( 'Inquiry snippet: AIQ_ENDPOINT / AIQ_API_KEY not defined in wp-config.php' );
			return false;
		}
		if ( empty( $payload['submission_id'] ) ) {
			$payload['submission_id'] = 'wp-' . substr( md5( home_url( '/' ) ), 0, 8 ) . '-' . wp_generate_uuid4();
		}
		for ( $try = 1; $try <= 2; $try++ ) {
			$response = wp_remote_post(
				AIQ_ENDPOINT,
				array(
					'timeout'     => 6,
					'redirection' => 0,
					'headers'     => array(
						'Authorization'   => 'Bearer ' . AIQ_API_KEY,
						'Content-Type'    => 'application/json',
						'Idempotency-Key' => $payload['submission_id'],
					),
					'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				)
			);
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code || 201 === $code ) {
				return true;                       // 201 created, 200 = duplicate retry already stored
			}
			if ( $code >= 400 && $code < 500 && 429 !== $code ) {
				// Validation or key problem: retrying will not help. Log field names only (no personal data).
				$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				error_log( 'Inquiry snippet rejected: HTTP ' . $code . ' ' . ( isset( $body['error']['code'] ) ? $body['error']['code'] : '' ) . ' ' . implode( ',', array_keys( isset( $body['error']['fields'] ) ? (array) $body['error']['fields'] : array() ) ) );
				return false;
			}
			sleep( 1 );
		}
		if ( $attempt < 6 ) {
			// Payload is kept in the cron option until delivered.
			wp_schedule_single_event( time() + 300 * $attempt, 'aiqs_retry', array( $payload, $attempt + 1 ) );
		}
		return false;
	}
	add_action( 'aiqs_retry', 'aiqs_send', 10, 2 );
}

if ( ! function_exists( 'aiqs_map' ) ) {
	/**
	 * Apply a field map: array( 'form-field' => 'full_name' | 'products[1].quantity' | 'fields.Label' ).
	 * Unmapped, non-internal fields are added as custom answers.
	 */
	function aiqs_map( array $values, array $map, array $base ) {
		$payload = $base;
		foreach ( $values as $name => $value ) {
			if ( '' === (string) $name || '_' === substr( (string) $name, 0, 1 ) || in_array( $name, array( 'g-recaptcha-response', 'cf-turnstile-response' ), true ) ) {
				continue;
			}
			$text   = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : trim( (string) $value );
			$target = isset( $map[ $name ] ) ? $map[ $name ] : 'fields.' . $name;
			if ( '' === $text || 'ignore' === $target ) {
				continue;
			}
			if ( preg_match( '/^products\[(\d+)\]\.(name|sku|variant|quantity|unit|notes)$/', $target, $m ) ) {
				$payload['products'][ (int) $m[1] ][ $m[2] ] = 'quantity' === $m[2] && is_numeric( $text ) ? 0 + $text : $text;
			} elseif ( 0 === strpos( $target, 'fields.' ) ) {
				$label                     = substr( $target, 7 );
				$payload['fields'][ $label ] = array( 'label' => $label, 'value' => is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : $text );
			} else {
				$payload[ $target ] = isset( $payload[ $target ] ) ? $payload[ $target ] . ' ' . $text : $text;
			}
		}
		if ( ! empty( $payload['products'] ) ) {
			ksort( $payload['products'] );
			$payload['products'] = array_values( $payload['products'] );
		}
		return $payload;
	}
}

/* ---------------------------------------------------------------------------
 * B. Contact Form 7 — forwards after CF7 has sent (or failed to send) its own email.
 * ------------------------------------------------------------------------ */

add_action( 'wpcf7_mail_sent', function ( $contact_form ) { aiqs_cf7( $contact_form, true ); } );
add_action( 'wpcf7_mail_failed', function ( $contact_form ) { aiqs_cf7( $contact_form, false ); } );

function aiqs_cf7( $contact_form, $mail_sent ) {
	// EDIT: CF7 form ID => form type and field map ("your-name" etc. are the names in the form template).
	$forms = array(
		123 => array(
			'form_type' => 'contact',
			'map'       => array(
				'your-name'    => 'full_name',
				'your-email'   => 'email',
				'your-phone'   => 'phone',
				'your-company' => 'company',
				'your-message' => 'message',
				'your-subject' => 'fields.Subject',
			),
		),
	);
	$id = (int) $contact_form->id();
	if ( ! isset( $forms[ $id ] ) ) {
		return;
	}
	// Skip if the Atlantic Inquiry Connector plugin already forwards this form.
	if ( function_exists( 'aiq_connector_handles' ) && aiq_connector_handles( 'cf7', (string) $id ) ) {
		return;
	}
	$submission = WPCF7_Submission::get_instance();
	if ( ! $submission ) {
		return;
	}
	$payload = aiqs_map(
		(array) $submission->get_posted_data(),
		$forms[ $id ]['map'],
		array(
			'form_type' => $forms[ $id ]['form_type'],
			'form_id'   => 'cf7-' . $id,
			'form_name' => $contact_form->title(),
			'locale'    => str_replace( '_', '-', get_locale() ),
			'source'    => array(
				'page_url' => (string) $submission->get_meta( 'url' ),
				'ip'       => (string) $submission->get_meta( 'remote_ip' ),
				'email'    => array( 'sent' => (bool) $mail_sent, 'attempts' => 1 ),
			),
		)
	);
	aiqs_send( $payload );
}

/* ---------------------------------------------------------------------------
 * C. Elementor Pro Forms — runs after the form's actions (Email, etc.) have completed.
 * ------------------------------------------------------------------------ */

add_action(
	'elementor_pro/forms/new_record',
	function ( $record, $handler ) {
		// EDIT: Elementor "Form Name" => form type and map of field IDs (Advanced > ID of each field).
		$forms = array(
			'Quote Request' => array(
				'form_type' => 'quote',
				'map'       => array(
					'name'      => 'full_name',
					'email'     => 'email',
					'tel'       => 'phone',
					'company'   => 'company',
					'message'   => 'message',
					'product_1' => 'products[1].name',
					'qty_1'     => 'products[1].quantity',
					'product_2' => 'products[2].name',
					'qty_2'     => 'products[2].quantity',
				),
			),
		);
		$form_name = (string) $record->get_form_settings( 'form_name' );
		if ( ! isset( $forms[ $form_name ] ) ) {
			return;
		}
		if ( function_exists( 'aiq_connector_handles' ) && aiq_connector_handles( 'elementor', $form_name ) ) {
			return;
		}
		$values = array();
		foreach ( (array) $record->get( 'fields' ) as $field_id => $field ) {
			$values[ $field_id ] = isset( $field['value'] ) ? $field['value'] : '';
		}
		$payload = aiqs_map(
			$values,
			$forms[ $form_name ]['map'],
			array(
				'form_type' => $forms[ $form_name ]['form_type'],
				'form_id'   => 'elementor-' . sanitize_title( $form_name ),
				'form_name' => $form_name,
				'locale'    => str_replace( '_', '-', get_locale() ),
				'source'    => array( 'page_url' => (string) wp_get_referer() ),
			)
		);
		aiqs_send( $payload );
	},
	10,
	2
);

/* ---------------------------------------------------------------------------
 * D. Custom PHP form (e.g. a page template handling its own POST)
 * ------------------------------------------------------------------------ */

/*
add_action( 'admin_post_nopriv_my_sample_request', 'my_sample_request_handler' );
add_action( 'admin_post_my_sample_request', 'my_sample_request_handler' );
function my_sample_request_handler() {
	check_admin_referer( 'my_sample_request' );            // your form prints wp_nonce_field( 'my_sample_request' )
	aiqs_send( array(
		'submission_id' => 'wp-sample-' . sanitize_key( $_POST['form_token'] ),   // hidden field generated once per page view
		'form_type'     => 'sample',
		'form_id'       => 'sample-request',
		'full_name'     => sanitize_text_field( wp_unslash( $_POST['name'] ) ),
		'email'         => sanitize_email( wp_unslash( $_POST['email'] ) ),
		'fields'        => array(
			'swatches' => array( 'label' => 'Swatches', 'value' => array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['swatches'] ) ) ),
		),
	) );
	wp_safe_redirect( home_url( '/thank-you/' ) );
	exit;
}
*/
