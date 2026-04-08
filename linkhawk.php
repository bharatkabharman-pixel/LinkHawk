<?php
/**
 * Plugin Name: LinkHawk - Broken Link Checker
 * Plugin URI:  https://trsoftech.com
 * Description: Automatically scan your WordPress site for broken links and fix them with 301 redirects.
 * Version:     1.1.0
 * Author:      Kamlesh Kumar Jangir
 * Author URI:  https://trsoftech.com
 * Text Domain: linkhawk
 * Requires at least: 6.0
 * Requires PHP: 8.2
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKHAWK_VERSION', '1.1.0' );
define( 'LINKHAWK_FILE', __FILE__ );
define( 'LINKHAWK_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKHAWK_URL', plugin_dir_url( __FILE__ ) );

// Backward-compat aliases so all included files work during migration.
define( 'LINKGUARD_VERSION', LINKHAWK_VERSION );
define( 'LINKGUARD_FILE',    LINKHAWK_FILE );
define( 'LINKGUARD_DIR',     LINKHAWK_DIR );
define( 'LINKGUARD_URL',     LINKHAWK_URL );

require_once LINKHAWK_DIR . 'includes/database.php';
require_once LINKHAWK_DIR . 'includes/scanner.php';
require_once LINKHAWK_DIR . 'includes/redirects.php';
require_once LINKHAWK_DIR . 'admin/menu.php';
require_once LINKHAWK_DIR . 'admin/ajax.php';

// ── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook( LINKHAWK_FILE, 'lgp_activate' );
function lgp_activate() {
    lgp_create_tables();
    if ( ! wp_next_scheduled( 'lgp_daily_scan' ) ) {
        wp_schedule_event( time(), 'daily', 'lgp_daily_scan' );
    }
}

register_deactivation_hook( LINKHAWK_FILE, 'lgp_deactivate' );
function lgp_deactivate() {
    $timestamp = wp_next_scheduled( 'lgp_daily_scan' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'lgp_daily_scan' );
    }
}

// ── Cron ─────────────────────────────────────────────────────────────────────

add_action( 'lgp_daily_scan', 'lgp_run_scan' );

// ── Redirects on every request ────────────────────────────────────────────────

add_action( 'init', 'lgp_handle_redirects' );
