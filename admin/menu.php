<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'lgp_register_admin_menu' );
function lgp_register_admin_menu() {
    add_menu_page(
        __( 'LinkHawk', 'linkguard' ),
        __( 'LinkHawk', 'linkguard' ),
        'manage_options',
        'linkguard',
        'lgp_render_dashboard',
        'dashicons-admin-links',
        80
    );

    add_submenu_page(
        'linkguard',
        __( 'Broken Links', 'linkguard' ),
        __( 'Broken Links', 'linkguard' ),
        'manage_options',
        'linkguard',
        'lgp_render_dashboard'
    );

    add_submenu_page(
        'linkguard',
        __( '301 Redirects', 'linkguard' ),
        __( '301 Redirects', 'linkguard' ),
        'manage_options',
        'linkguard-redirects',
        'lgp_render_redirects_page'
    );

    add_submenu_page(
        'linkguard',
        __( 'Settings', 'linkguard' ),
        __( 'Settings', 'linkguard' ),
        'manage_options',
        'linkguard-settings',
        'lgp_render_settings_page'
    );
}

add_action( 'admin_enqueue_scripts', 'lgp_enqueue_admin_assets' );
function lgp_enqueue_admin_assets( $hook ) {
    $our_hooks = [
        'toplevel_page_linkguard',
        'linkguard_page_linkguard-redirects',
        'linkguard_page_linkguard-settings',
    ];

    if ( ! in_array( $hook, $our_hooks, true ) ) {
        return;
    }

    wp_enqueue_style(
        'linkguard-admin',
        LINKGUARD_URL . 'assets/css/admin.css',
        [],
        LINKGUARD_VERSION
    );

    wp_enqueue_script(
        'linkguard-admin',
        LINKGUARD_URL . 'assets/js/admin.js',
        [ 'jquery' ],
        LINKGUARD_VERSION,
        true
    );

    wp_localize_script( 'linkguard-admin', 'lgpData', [
        'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
        'scanNonce'       => wp_create_nonce( 'lgp_scan_now' ),
        'deleteNonce'     => wp_create_nonce( 'lgp_delete_link' ),
        'bulkDeleteNonce' => wp_create_nonce( 'lgp_bulk_delete' ),
        'addRedNonce'     => wp_create_nonce( 'lgp_add_redirect' ),
        'delRedNonce'     => wp_create_nonce( 'lgp_delete_redirect' ),
        'exportNonce'     => wp_create_nonce( 'lgp_export_csv' ),
        'redirectsUrl'    => admin_url( 'admin.php?page=linkguard-redirects' ),
        'exportUrl'       => admin_url( 'admin-ajax.php?action=lgp_export_csv&nonce=' . wp_create_nonce( 'lgp_export_csv' ) ),
        'i18n'            => [
            'scanning'    => __( 'Scanning…', 'linkguard' ),
            'scanDone'    => __( 'Scan complete!', 'linkguard' ),
            'scanError'   => __( 'Scan failed. Please try again.', 'linkguard' ),
            'confirm'     => __( 'Are you sure?', 'linkguard' ),
            'bulkConfirm' => __( 'Dismiss all selected broken links?', 'linkguard' ),
            'noneSelected'=> __( 'Please select at least one link.', 'linkguard' ),
        ],
    ] );
}

// ── Render callbacks ──────────────────────────────────────────────────────────

function lgp_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'linkguard' ) );
    }
    require_once LINKGUARD_DIR . 'admin/views/dashboard.php';
}

function lgp_render_redirects_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'linkguard' ) );
    }
    require_once LINKGUARD_DIR . 'admin/views/redirects.php';
}

function lgp_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'linkguard' ) );
    }
    require_once LINKGUARD_DIR . 'admin/views/settings.php';
}
