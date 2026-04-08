<?php
defined( 'ABSPATH' ) || exit;

/**
 * Check whether the current request matches a stored redirect and,
 * if so, send a 301 and exit.
 *
 * Hooked to 'init' in the main plugin file.
 */
function lgp_handle_redirects() {
    // Only act on front-end requests that are not admin or AJAX.
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return;
    }

    global $wpdb;

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

    // Strip query string.
    $path = strtok( $request_uri, '?' );

    // Build 4 variants to match against whatever format was saved in DB:
    // full URL with/without trailing slash + path with/without trailing slash.
    $scheme   = is_ssl() ? 'https' : 'http';
    $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
    $full_url = trailingslashit( $scheme . '://' . $host . $path );
    $full_url_no_slash = rtrim( $full_url, '/' );
    $path_slash    = trailingslashit( $path );
    $path_no_slash = rtrim( $path, '/' );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $redirect = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, target_url
               FROM {$wpdb->prefix}linkhawk_redirects
              WHERE source_url = %s
                 OR source_url = %s
                 OR source_url = %s
                 OR source_url = %s
              LIMIT 1",
            $full_url,
            $full_url_no_slash,
            $path_slash,
            $path_no_slash
        )
    );

    if ( ! $redirect ) {
        return;
    }

    // Increment hit counter asynchronously (best-effort, no fatal on failure).
    lgp_increment_redirect_hits( (int) $redirect->id );

    $target = esc_url_raw( $redirect->target_url );

    if ( empty( $target ) ) {
        return;
    }

    wp_redirect( $target, 301 );
    exit;
}
