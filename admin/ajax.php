<?php
defined( 'ABSPATH' ) || exit;

// ── Scan Now ──────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_scan_now', 'lgp_ajax_scan_now' );
function lgp_ajax_scan_now() {
    check_ajax_referer( 'lgp_scan_now', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkguard' ) ], 403 );
    }

    $result = lgp_run_scan();

    // Fire email notification if enabled and broken links found.
    $notify = get_option( 'lgp_email_notify', '0' );
    if ( '1' === $notify && $result['broken'] > 0 ) {
        lgp_send_notification_email( $result );
    }

    wp_send_json_success( [
        'scanned'   => (int) $result['scanned'],
        'broken'    => (int) $result['broken'],
        'last_scan' => get_option( 'linkguard_last_scan', '' ),
        'html'      => lgp_render_links_table_html(),
    ] );
}

// ── Delete single broken-link record ─────────────────────────────────────────

add_action( 'wp_ajax_lgp_delete_link', 'lgp_ajax_delete_link' );
function lgp_ajax_delete_link() {
    check_ajax_referer( 'lgp_delete_link', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkguard' ) ], 403 );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'linkguard' ) ] );
    }

    lgp_delete_link( $id );
    wp_send_json_success( [ 'id' => $id ] );
}

// ── Bulk delete broken-link records ──────────────────────────────────────────

add_action( 'wp_ajax_lgp_bulk_delete', 'lgp_ajax_bulk_delete' );
function lgp_ajax_bulk_delete() {
    check_ajax_referer( 'lgp_bulk_delete', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkguard' ) ], 403 );
    }

    $raw_ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : [];
    $ids     = array_filter( array_map( 'absint', $raw_ids ) );

    if ( empty( $ids ) ) {
        wp_send_json_error( [ 'message' => __( 'No IDs provided.', 'linkguard' ) ] );
    }

    foreach ( $ids as $id ) {
        lgp_delete_link( $id );
    }

    wp_send_json_success( [
        'ids'     => $ids,
        'deleted' => count( $ids ),
    ] );
}

// ── Add redirect ──────────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_add_redirect', 'lgp_ajax_add_redirect' );
function lgp_ajax_add_redirect() {
    check_ajax_referer( 'lgp_add_redirect', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkguard' ) ], 403 );
    }

    $source = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
    $target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';

    if ( empty( $source ) || empty( $target ) ) {
        wp_send_json_error( [ 'message' => __( 'Both source and target URLs are required.', 'linkguard' ) ] );
    }

    $new_id = lgp_insert_redirect( $source, $target );

    if ( false === $new_id ) {
        wp_send_json_error( [ 'message' => __( 'A redirect for this source URL already exists.', 'linkguard' ) ] );
    }

    wp_send_json_success( [
        'id'      => $new_id,
        'message' => __( 'Redirect added successfully.', 'linkguard' ),
    ] );
}

// ── Delete redirect ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_lgp_delete_redirect', 'lgp_ajax_delete_redirect' );
function lgp_ajax_delete_redirect() {
    check_ajax_referer( 'lgp_delete_redirect', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'linkguard' ) ], 403 );
    }

    $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'linkguard' ) ] );
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

    $links = lgp_get_broken_links();

    $filename = 'linkhawk-broken-links-' . gmdate( 'Y-m-d' ) . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    // UTF-8 BOM for Excel compatibility.
    echo "\xEF\xBB\xBF";

    $out = fopen( 'php://output', 'w' );

    fputcsv( $out, [ 'Post Title', 'Post URL', 'Broken URL', 'Anchor Text', 'HTTP Status', 'Detected At' ] );

    foreach ( $links as $link ) {
        fputcsv( $out, [
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

// ── Email notification helper ─────────────────────────────────────────────────

function lgp_send_notification_email( array $result ) {
    $to      = get_option( 'lgp_notify_email', get_option( 'admin_email' ) );
    $subject = sprintf(
        /* translators: %s: site name */
        __( '[%s] LinkHawk: %d broken link(s) found', 'linkguard' ),
        get_bloginfo( 'name' ),
        $result['broken']
    );

    $dashboard_url = admin_url( 'admin.php?page=linkguard' );
    $message  = sprintf( __( 'LinkHawk completed a scan of %s.', 'linkguard' ), get_bloginfo( 'name' ) ) . "\n\n";
    $message .= sprintf( __( 'Posts/pages scanned: %d', 'linkguard' ), $result['scanned'] ) . "\n";
    $message .= sprintf( __( 'Broken links found: %d', 'linkguard' ), $result['broken'] ) . "\n\n";
    $message .= __( 'View broken links:', 'linkguard' ) . "\n" . $dashboard_url . "\n";

    wp_mail( $to, $subject, $message );
}

// ── Render table HTML (used after AJAX scan) ──────────────────────────────────

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
 *
 * @param array $links
 * @param int   $total
 * @param int   $current_page
 * @param int   $per_page
 */
function lgp_output_links_table( $links, $total, $current_page, $per_page ) {
    if ( empty( $links ) ) {
        echo '<p class="lgp-no-results">' . esc_html__( 'No broken links found. Your site looks healthy!', 'linkguard' ) . '</p>';
        return;
    }

    $total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
    ?>
    <div class="lgp-table-toolbar">
        <label class="lgp-select-all-wrap">
            <input type="checkbox" id="lgp-select-all" />
            <?php esc_html_e( 'Select All', 'linkguard' ); ?>
        </label>
        <button id="lgp-bulk-dismiss" class="button">
            <?php esc_html_e( 'Dismiss Selected', 'linkguard' ); ?>
        </button>
        <span class="lgp-table-count">
            <?php
            printf(
                /* translators: 1: count */
                esc_html__( '%d broken link(s) total', 'linkguard' ),
                (int) $total
            );
            ?>
        </span>
    </div>

    <div class="lgp-table-wrap">
    <table class="wp-list-table widefat fixed striped lgp-links-table">
        <thead>
            <tr>
                <th class="lgp-col-cb"><input type="checkbox" disabled aria-hidden="true" /></th>
                <th><?php esc_html_e( 'Post / Page', 'linkguard' ); ?></th>
                <th><?php esc_html_e( 'Broken URL', 'linkguard' ); ?></th>
                <th><?php esc_html_e( 'Status', 'linkguard' ); ?></th>
                <th><?php esc_html_e( 'Anchor Text', 'linkguard' ); ?></th>
                <th><?php esc_html_e( 'Detected At', 'linkguard' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'linkguard' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $links as $link ) : ?>
            <tr id="lgp-link-row-<?php echo esc_attr( $link->id ); ?>">
                <td class="lgp-col-cb">
                    <input type="checkbox" class="lgp-row-cb" value="<?php echo esc_attr( $link->id ); ?>" />
                </td>
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
                    <button
                        class="button button-small lgp-add-redirect-btn"
                        data-source="<?php echo esc_attr( $link->broken_url ); ?>"
                        data-link-id="<?php echo esc_attr( $link->id ); ?>"
                    ><?php esc_html_e( 'Add 301', 'linkguard' ); ?></button>
                    <button
                        class="button button-small lgp-delete-link-btn"
                        data-id="<?php echo esc_attr( $link->id ); ?>"
                    ><?php esc_html_e( 'Dismiss', 'linkguard' ); ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="lgp-pagination" data-current="<?php echo esc_attr( $current_page ); ?>" data-total="<?php echo esc_attr( $total_pages ); ?>">
        <?php if ( $current_page > 1 ) : ?>
            <button class="button lgp-page-btn" data-page="<?php echo esc_attr( $current_page - 1 ); ?>">&laquo; <?php esc_html_e( 'Prev', 'linkguard' ); ?></button>
        <?php endif; ?>

        <span class="lgp-page-info">
            <?php
            printf(
                /* translators: 1: current page 2: total pages */
                esc_html__( 'Page %1$d of %2$d', 'linkguard' ),
                (int) $current_page,
                (int) $total_pages
            );
            ?>
        </span>

        <?php if ( $current_page < $total_pages ) : ?>
            <button class="button lgp-page-btn" data-page="<?php echo esc_attr( $current_page + 1 ); ?>"><?php esc_html_e( 'Next', 'linkguard' ); ?> &raquo;</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
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

/**
 * Coloured status badge — returns safe HTML string.
 *
 * @param string $status
 * @return string
 */
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
