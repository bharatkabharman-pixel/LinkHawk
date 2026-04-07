<?php
/**
 * Plugin Name: LinkHawk - Broken Link Checker
 * Plugin URI:  https://trsoftech.com
 * Description: Automatically scan your WordPress site for broken links and fix them with 301 redirects.
 * Version:     1.0.0
 * Author:      Kamlesh Kumar Jangir
 * Author URI:  https://trsoftech.com
 * Text Domain: linkguard
 * Requires at least: 6.0
 * Requires PHP: 8.2
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKGUARD_VERSION', '1.0.0' );
define( 'LINKGUARD_FILE', __FILE__ );
define( 'LINKGUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKGUARD_URL', plugin_dir_url( __FILE__ ) );

require_once LINKGUARD_DIR . 'includes/database.php';
require_once LINKGUARD_DIR . 'includes/scanner.php';
require_once LINKGUARD_DIR . 'includes/redirects.php';
require_once LINKGUARD_DIR . 'admin/menu.php';
require_once LINKGUARD_DIR . 'admin/ajax.php';

// ── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook( LINKGUARD_FILE, 'lgp_activate' );
function lgp_activate() {
    lgp_create_tables();
    if ( ! wp_next_scheduled( 'lgp_daily_scan' ) ) {
        wp_schedule_event( time(), 'daily', 'lgp_daily_scan' );
    }
}

register_deactivation_hook( LINKGUARD_FILE, 'lgp_deactivate' );
function lgp_deactivate() {
    $timestamp = wp_next_scheduled( 'lgp_daily_scan' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'lgp_daily_scan' );
    }
}

// ── Cron hook ────────────────────────────────────────────────────────────────

add_action( 'lgp_daily_scan', 'lgp_run_scan' );

// ── Boot redirects on every request ─────────────────────────────────────────

add_action( 'init', 'lgp_handle_redirects' );
