<?php
defined( 'ABSPATH' ) || exit;

// ── Chunked scan: Step 1 — Init (returns post IDs, clears table) ──────────────

add_action( 'wp_ajax_lgp_scan_init', 'lgp_ajax_scan_init' );
function lgp_ajax_scan_init() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    lgp_clear_links();

    $post_ids = lgp_get_scan_post_ids();

    wp_send_json_success( [
        'post_ids' => $post_ids,
        'total'    => count( $post_ids ),
    ] );
}

// ── Chunked scan: Step 2 — Scan one post ─────────────────────────────────────

add_action( 'wp_ajax_lgp_scan_post', 'lgp_ajax_scan_post' );
function lgp_ajax_scan_post() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'linkhawk' ) ] );
    }

    $result = lgp_scan_post_by_id( $post_id );

    wp_send_json_success( $result );
}

// ── Chunked scan: Step 3 — Complete (save timestamp, send final data) ─────────

add_action( 'wp_ajax_lgp_scan_complete', 'lgp_ajax_scan_complete' );
function lgp_ajax_scan_complete() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    update_option( 'linkhawk_last_scan', current_time( 'mysql' ) );

    // Email notification if enabled.
    $broken = lgp_count_broken_links();
    if ( '1' === get_option( 'lgp_email_notify', '0' ) && $broken > 0 ) {
        $scanned = isset( $_POST['scanned'] ) ? absint( $_POST['scanned'] ) : 0;
        lgp_send_notification_email( [ 'scanned' => $scanned, 'broken' => $broken ] );
    }

    $per_page = (int) get_option( 'lgp_per_page', 20 );

    wp_send_json_success( [
        'broken'    => $broken,
        'last_scan' => get_option( 'linkhawk_last_scan', '' ),
        'affected'  => lgp_count_affected_posts(),
        'by_type'   => lgp_count_by_type(),
        'html'      => lgp_render_links_table_html(),
    ] );
}

// ── Legacy single-request scan (used by cron) ─────────────────────────────────

add_action( 'wp_ajax_lgp_scan_now', 'lgp_ajax_scan_now' );
function lgp_ajax_scan_now() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $result = lgp_run_scan();

    if ( '1' === get_option( 'lgp_email_notify', '0' ) && $result['broken'] > 0 ) {
        lgp_send_notification_email( $result );
    }

    wp_send_json_success( [
        'scanned'   => (int) $result['scanned'],
        'broken'    => (int) $result['broken'],
        'last_scan' => get_option( 'linkhawk_last_scan', '' ),
        'html'      => lgp_render_links_table_html(),
    ] );
}

// ── Delete single broken-link record ─────────────────────────────────────────

add_action( 'wp_ajax_lgp_delete_link', 'lgp_ajax_delete_link' );
function lgp_ajax_delete_link() {
    check_ajax_referer( 'lgp_delete_link', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'linkhawk' ) ] );
    }

    lgp_delete_link( $id );
    wp_send_json_success( [ 'id' => $id ] );
}

// ── Bulk delete ───────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_bulk_delete', 'lgp_ajax_bulk_delete' );
function lgp_ajax_bulk_delete() {
    check_ajax_referer( 'lgp_bulk_delete', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $raw_ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : [];
    $ids     = array_filter( array_map( 'absint', $raw_ids ) );

    if ( empty( $ids ) ) {
        wp_send_json_error( [ 'message' => __( 'No IDs provided.', 'linkhawk' ) ] );
    }

    foreach ( $ids as $id ) {
        lgp_delete_link( $id );
    }

    wp_send_json_success( [ 'ids' => $ids, 'deleted' => count( $ids ) ] );
}

// ── Ignore URL ────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_ignore_url', 'lgp_ajax_ignore_url' );
function lgp_ajax_ignore_url() {
    check_ajax_referer( 'lgp_ignore_url', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;

    if ( empty( $url ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid URL.', 'linkhawk' ) ] );
    }

    lgp_ignore_url( $url );

    // Also remove from broken links table so it disappears immediately.
    if ( $link_id ) {
        lgp_delete_link( $link_id );
    }

    wp_send_json_success( [ 'link_id' => $link_id ] );
}

// ── Unignore URL ──────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_unignore_url', 'lgp_ajax_unignore_url' );
function lgp_ajax_unignore_url() {
    check_ajax_referer( 'lgp_ignore_url', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'linkhawk' ) ] );
    }

    lgp_unignore_url( $id );
    wp_send_json_success( [ 'id' => $id ] );
}

// ── Add redirect ──────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_add_redirect', 'lgp_ajax_add_redirect' );
function lgp_ajax_add_redirect() {
    check_ajax_referer( 'lgp_add_redirect', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $source = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
    $target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';

    if ( empty( $source ) || empty( $target ) ) {
        wp_send_json_error( [ 'message' => __( 'Both source and target URLs are required.', 'linkhawk' ) ] );
    }

    $new_id = lgp_insert_redirect( $source, $target );

    if ( false === $new_id ) {
        wp_send_json_error( [ 'message' => __( 'A redirect for this source URL already exists.', 'linkhawk' ) ] );
    }

    wp_send_json_success( [ 'id' => $new_id, 'message' => __( 'Redirect added.', 'linkhawk' ) ] );
}

// ── Delete redirect ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_delete_redirect', 'lgp_ajax_delete_redirect' );
function lgp_ajax_delete_redirect() {
    check_ajax_referer( 'lgp_delete_redirect', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkhawk' ) ], 403 );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'linkhawk' ) ] );
    }

    lgp_delete_redirect( $id );
    wp_send_json_success( [ 'id' => $id ] );
}

// ── Export CSV ────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_export_csv', 'lgp_ajax_export_csv' );
function lgp_ajax_export_csv() {
    check_ajax_referer( 'lgp_export_csv', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.', 403 );
    }

    $links    = lgp_get_broken_links();
    $filename = 'linkhawk-broken-links-' . gmdate( 'Y-m-d' ) . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.

    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'Type', 'Post Title', 'Post URL', 'Broken URL', 'Anchor/Alt Text', 'HTTP Status', 'Detected At' ] );

    foreach ( $links as $link ) {
        fputcsv( $out, [
            $link->link_type ?? 'link',
            $link->post_title,
            $link->post_url,
            $link->broken_url,
            $link->anchor_text,
            $link->http_status,
            $link->detected_at,
        ] );
    }

    fclose( $out );
    exit;
}

// ── AJAX pagination ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_get_page', 'lgp_ajax_get_page' );
function lgp_ajax_get_page() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [], 403 );
    }

    $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
    $per_page = (int) get_option( 'lgp_per_page', 20 );
    $total    = lgp_count_broken_links();
    $links    = lgp_get_broken_links_paged( $page, $per_page );

    ob_start();
    lgp_output_links_table( $links, $total, $page, $per_page );
    $html = ob_get_clean();

    wp_send_json_success( [ 'html' => $html ] );
}

// ── Email notification ────────────────────────────────────────────────────────

function lgp_send_notification_email( array $result ) {
    $to      = get_option( 'lgp_notify_email', get_option( 'admin_email' ) );
    $subject = sprintf(
        __( '[%s] LinkHawk: %d broken link(s) found', 'linkhawk' ),
        get_bloginfo( 'name' ),
        $result['broken']
    );

    $message  = sprintf( __( 'LinkHawk completed a scan of %s.', 'linkhawk' ), get_bloginfo( 'name' ) ) . "\n\n";
    $message .= sprintf( __( 'Posts/pages scanned: %d', 'linkhawk' ), $result['scanned'] ) . "\n";
    $message .= sprintf( __( 'Broken links found: %d', 'linkhawk' ), $result['broken'] ) . "\n\n";
    $message .= __( 'View broken links:', 'linkhawk' ) . "\n" . admin_url( 'admin.php?page=linkhawk' ) . "\n";

    wp_mail( $to, $subject, $message );
}

// ── Render helpers ────────────────────────────────────────────────────────────

function lgp_render_links_table_html() {
    $per_page = (int) get_option( 'lgp_per_page', 20 );
    $links    = lgp_get_broken_links_paged( 1, $per_page );
    $total    = lgp_count_broken_links();

    ob_start();
    lgp_output_links_table( $links, $total, 1, $per_page );
    return ob_get_clean();
}

/**
 * Render the broken-links table with pagination.
 */
function lgp_output_links_table( $links, $total, $current_page, $per_page ) {
    if ( empty( $links ) ) {
        echo '<p class="lgp-no-results">' . esc_html__( 'No broken links found. Your site looks healthy!', 'linkhawk' ) . '</p>';
        return;
    }

    $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
    ?>
    <div class="lgp-table-toolbar">
        <label class="lgp-select-all-wrap">
            <input type="checkbox" id="lgp-select-all" />
            <?php esc_html_e( 'Select All', 'linkhawk' ); ?>
        </label>
        <button id="lgp-bulk-dismiss" class="button">
            <?php esc_html_e( 'Dismiss Selected', 'linkhawk' ); ?>
        </button>
        <span class="lgp-table-count">
            <?php printf( esc_html__( '%d item(s) total', 'linkhawk' ), (int) $total ); ?>
        </span>
    </div>

    <div class="lgp-table-wrap">
    <table class="wp-list-table widefat fixed striped lgp-links-table">
        <thead>
            <tr>
                <th class="lgp-col-cb"></th>
                <th><?php esc_html_e( 'Type', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Post / Page', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Broken URL', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Status', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Anchor / Alt', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Detected At', 'linkhawk' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'linkhawk' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $links as $link ) : ?>
            <tr id="lgp-link-row-<?php echo esc_attr( $link->id ); ?>">
                <td class="lgp-col-cb">
                    <input type="checkbox" class="lgp-row-cb" value="<?php echo esc_attr( $link->id ); ?>" />
                </td>
                <td><?php echo lgp_type_badge( $link->link_type ?? 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td>
                    <a href="<?php echo esc_url( $link->post_url ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $link->post_title ); ?>
                    </a>
                </td>
                <td class="lgp-url-cell">
                    <a href="<?php echo esc_url( $link->broken_url ); ?>" target="_blank" rel="noopener nofollow">
                        <?php echo esc_html( $link->broken_url ); ?>
                    </a>
                </td>
                <td><?php echo lgp_status_badge( $link->http_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td><?php echo esc_html( $link->anchor_text ); ?></td>
                <td><?php echo esc_html( $link->detected_at ); ?></td>
                <td class="lgp-actions">
                    <button class="button button-small button-primary lgp-add-redirect-btn"
                        data-source="<?php echo esc_attr( $link->broken_url ); ?>"
                        data-link-id="<?php echo esc_attr( $link->id ); ?>"
                        title="<?php echo ( $link->link_type ?? 'link' ) === 'image' ? esc_attr__( 'Redirect broken image URL', 'linkhawk' ) : esc_attr__( 'Add 301 redirect', 'linkhawk' ); ?>"
                    >
                        <span class="dashicons <?php echo ( $link->link_type ?? 'link' ) === 'image' ? 'dashicons-format-image' : 'dashicons-randomize'; ?>" style="font-size:12px;width:12px;height:12px;margin-top:4px;margin-right:2px;"></span>
                        301
                    </button>
                    <button class="button button-small lgp-ignore-btn"
                        data-url="<?php echo esc_attr( $link->broken_url ); ?>"
                        data-id="<?php echo esc_attr( $link->id ); ?>"
                    ><?php esc_html_e( 'Ignore', 'linkhawk' ); ?></button>
                    <button class="button button-small lgp-delete-link-btn"
                        data-id="<?php echo esc_attr( $link->id ); ?>"
                    ><?php esc_html_e( 'Dismiss', 'linkhawk' ); ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="lgp-pagination">
        <?php if ( $current_page > 1 ) : ?>
            <button class="button lgp-page-btn" data-page="<?php echo esc_attr( $current_page - 1 ); ?>">&laquo; <?php esc_html_e( 'Prev', 'linkhawk' ); ?></button>
        <?php endif; ?>
        <span class="lgp-page-info">
            <?php printf( esc_html__( 'Page %1$d of %2$d', 'linkhawk' ), (int) $current_page, (int) $total_pages ); ?>
        </span>
        <?php if ( $current_page < $total_pages ) : ?>
            <button class="button lgp-page-btn" data-page="<?php echo esc_attr( $current_page + 1 ); ?>"><?php esc_html_e( 'Next', 'linkhawk' ); ?> &raquo;</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
}

function lgp_status_badge( $status ) {
    $label = esc_html( $status );
    $class = 'lgp-badge lgp-badge-other';

    if ( '404' === (string) $status ) {
        $class = 'lgp-badge lgp-badge-404';
        $label = '404 Not Found';
    } elseif ( 'timeout' === $status ) {
        $class = 'lgp-badge lgp-badge-timeout';
        $label = 'Timeout';
    } elseif ( 'error' === $status ) {
        $class = 'lgp-badge lgp-badge-error';
        $label = 'Error';
    } elseif ( is_numeric( $status ) && (int) $status >= 500 ) {
        $class = 'lgp-badge lgp-badge-5xx';
        $label = esc_html( $status ) . ' Server Error';
    } elseif ( is_numeric( $status ) && (int) $status >= 400 ) {
        $class = 'lgp-badge lgp-badge-4xx';
    }

    return '<span class="' . esc_attr( $class ) . '">' . $label . '</span>';
}

function lgp_type_badge( $type ) {
    if ( 'image' === $type ) {
        return '<span class="lgp-badge lgp-badge-image"><span class="dashicons dashicons-format-image" style="font-size:11px;width:11px;height:11px;vertical-align:middle;margin-right:2px;"></span>Image</span>';
    }
    return '<span class="lgp-badge lgp-badge-link"><span class="dashicons dashicons-admin-links" style="font-size:11px;width:11px;height:11px;vertical-align:middle;margin-right:2px;"></span>Link</span>';
}
