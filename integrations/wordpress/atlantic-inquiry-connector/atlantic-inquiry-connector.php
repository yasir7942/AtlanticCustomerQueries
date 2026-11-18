<?php
/**
 * Plugin Name:       Atlantic Inquiry Connector
 * Description:       Forwards Contact Form 7, Elementor Pro and custom PHP form submissions to the Inquiry Manager API (server-to-server). Existing form emails keep working.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Atlantic
 * License:           Proprietary
 * Text Domain:       atlantic-inquiry-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIQ_CONNECTOR_VERSION', '1.0.0' );
define( 'AIQ_CONNECTOR_FILE', __FILE__ );
define( 'AIQ_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-settings.php';
require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-client.php';
require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-outbox.php';
require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-mapper.php';
require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-integrations.php';
require_once AIQ_CONNECTOR_DIR . 'includes/class-aiq-admin.php';
require_once AIQ_CONNECTOR_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'AIQ_Outbox', 'install' ) );
register_deactivation_hook( __FILE__, array( 'AIQ_Outbox', 'unschedule' ) );

add_filter( 'cron_schedules', array( 'AIQ_Outbox', 'cron_schedules' ) );
add_action( AIQ_Outbox::CRON_HOOK, array( 'AIQ_Outbox', 'process_due' ) );
add_action( 'init', array( 'AIQ_Outbox', 'ensure_scheduled' ) );
add_action( 'plugins_loaded', array( 'AIQ_Outbox', 'maybe_upgrade' ) );

AIQ_Integrations::register();

if ( is_admin() ) {
	AIQ_Admin::register();
}
