<?php
/**
 * Remove plugin data when the plugin is deleted from WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
delete_option( 'aiq_settings' );
delete_option( 'aiq_delivery_log' );
delete_option( 'aiq_db_version' );
wp_clear_scheduled_hook( 'aiq_process_outbox' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiq_outbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
