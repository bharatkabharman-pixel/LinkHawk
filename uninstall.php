<?php
/**
 * Runs automatically when the plugin is deleted from the WordPress admin.
 * Removes all database tables and options created by LinkHawk.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/database.php';

lgp_drop_tables();

// Remove all plugin options.
$options = [
    'linkhawk_db_version',
    'linkhawk_last_scan',
    'lgp_timeout',
    'lgp_per_page',
    'lgp_email_notify',
    'lgp_notify_email',
    'lgp_excluded_domains',
    'lgp_scan_image_links',
];

foreach ( $options as $option ) {
    delete_option( $option );
}
