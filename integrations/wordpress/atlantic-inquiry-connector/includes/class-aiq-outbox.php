<?php
/**
 * Durable outbox: every inquiry is stored first, then delivered. Failed transient deliveries are
 * retried by WP-Cron with the SAME submission_id, so the Inquiry Manager never creates duplicates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIQ_Outbox {

	const CRON_HOOK  = 'aiq_process_outbox';
	const DB_VERSION = '1';
	const MAX_ATTEMPTS = 10;
	const LOG_OPTION = 'aiq_delivery_log';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aiq_outbox';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				submission_id varchar(128) NOT NULL,
				source varchar(20) NOT NULL DEFAULT '',
				form_label varchar(190) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NOT NULL,
				last_status smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY submission_id (submission_id),
				KEY status_next (status, next_attempt_at)
			) {$charset};"
		);
		update_option( 'aiq_db_version', self::DB_VERSION, false );
		self::ensure_scheduled();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'aiq_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules['aiq_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (Inquiry Connector)',
		);
		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'aiq_five_minutes', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Store and immediately try to deliver one payload.
	 *
	 * @return array delivery result (see AIQ_Client::send) plus 'queued' => bool
	 */
	public static function submit( array $payload, $source, $form_label ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'submission_id'   => $payload['submission_id'],
				'source'          => substr( (string) $source, 0, 20 ),
				'form_label'      => substr( (string) $form_label, 0, 190 ),
				'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			// Table missing or insert failed: still try to deliver directly.
			$result           = AIQ_Client::send( $payload );
			$result['queued'] = false;
			self::log( $source, $form_label, $result );
			return $result;
		}
		return self::deliver( $id );
	}

	/** Deliver one stored row. */
	public static function deliver( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'ok' => false, 'retryable' => false, 'status' => 0, 'code' => 'missing', 'message' => 'Outbox row not found.', 'queued' => false );
		}
		$payload = json_decode( $row['payload'], true );
		$result  = AIQ_Client::send( is_array( $payload ) ? $payload : array() );
		$now     = current_time( 'mysql', true );
		$attempt = (int) $row['attempts'] + 1;

		if ( $result['ok'] ) {
			// Delivered: remove the personal data from WordPress.
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			$result['queued'] = false;
		} elseif ( $result['retryable'] && $attempt < self::MAX_ATTEMPTS ) {
			$delay = isset( $result['retry_after'] ) && $result['retry_after'] ? max( 60, (int) $result['retry_after'] ) : min( DAY_IN_SECONDS, 300 * ( 2 ** ( $attempt - 1 ) ) );
			$wpdb->update(
				$table,
				array(
					'attempts'        => $attempt,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					'last_status'     => (int) $result['status'],
					'last_error'      => substr( $result['code'] . ': ' . $result['message'], 0, 255 ),
					'updated_at'      => $now,
				),
				array( 'id' => $id )
			);
			$result['queued'] = true;
		} else {
			// Permanent failure (4xx) or too many attempts: keep for review, never retried automatically.
			$wpdb->update(
				$table,
				array(
					'status'      => 'failed',
					'attempts'    => $attempt,
					'last_status' => (int) $result['status'],
					'last_error'  => substr( $result['code'] . ': ' . $result['message'] . ( $result['fields'] ? ' [' . implode( ', ', array_keys( $result['fields'] ) ) . ']' : '' ), 0, 255 ),
					'updated_at'  => $now,
				),
				array( 'id' => $id )
			);
			$result['queued'] = false;
		}
		self::log( $row['source'], $row['form_label'], $result );
		return $result;
	}

	/** WP-Cron handler: retry due rows and purge old failures. */
	public static function process_due() {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT 20", current_time( 'mysql', true ) )
		);
		foreach ( $ids as $id ) {
			self::deliver( (int) $id );
		}
		// Failed rows keep personal data only for 14 days.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'failed' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ) );
		return count( $ids );
	}

	public static function counts() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array( 'pending' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function failed_rows( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, submission_id, source, form_label, attempts, last_status, last_error, updated_at FROM {$table} WHERE status = 'failed' ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public static function retry_failed( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
		return self::deliver( (int) $id );
	}

	/** Short delivery log without personal data (time, form, outcome). */
	private static function log( $source, $form_label, array $result ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'       => time(),
				'source'     => (string) $source,
				'form'       => (string) $form_label,
				'ok'         => (bool) $result['ok'],
				'status'     => (int) $result['status'],
				'code'       => (string) $result['code'],
				'queued'     => ! empty( $result['queued'] ),
				'inquiry_id' => isset( $result['inquiry_id'] ) ? $result['inquiry_id'] : null,
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, 25 ), false );
	}
}
