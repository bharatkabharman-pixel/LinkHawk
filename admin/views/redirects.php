<?php
defined( 'ABSPATH' ) || exit;

$form_error   = '';
$form_success = '';

if ( isset( $_POST['lgp_add_redirect_submit'] ) ) {
    check_admin_referer( 'lgp_add_redirect_form' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'linkguard' ) );
    }

    $source = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
    $target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';

    if ( empty( $source ) || empty( $target ) ) {
        $form_error = __( 'Both source and target URLs are required.', 'linkguard' );
    } else {
        $new_id = lgp_insert_redirect( $source, $target );
        if ( false === $new_id ) {
            $form_error = __( 'A redirect for that source URL already exists.', 'linkguard' );
        } else {
            $form_success = __( 'Redirect added successfully.', 'linkguard' );
        }
    }
}

$prefill_source = isset( $_GET['source'] ) ? esc_url_raw( wp_unslash( $_GET['source'] ) ) : '';

$per_page    = 15;
$page        = max( 1, absint( $_GET['redir_page'] ?? 1 ) );
$search      = isset( $_GET['redir_search'] ) ? sanitize_text_field( wp_unslash( $_GET['redir_search'] ) ) : '';
$orderby     = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
$order       = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';

$total     = $search ? lgp_count_redirects_search( $search ) : lgp_count_redirects();
$redirects = lgp_get_redirects_paged( $page, $per_page, $orderby, $order, $search );
$pages     = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

// Delete nonce embedded directly — guarantees availability regardless of asset loading.
$delete_nonce = wp_create_nonce( 'lgp_delete_redirect' );

/**
 * Build a sortable column header link.
 */
function lgp_redir_sort_link( $col, $label, $current_col, $current_order, $search, $page ) {
    $new_order = ( $col === $current_col && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
    $arrow     = '';
    if ( $col === $current_col ) {
        $arrow = $current_order === 'ASC' ? ' ▲' : ' ▼';
    }
    $url = add_query_arg( [
        'page'         => 'linkguard-redirects',
        'orderby'      => $col,
        'order'        => $new_order,
        'redir_search' => urlencode( $search ),
        'redir_page'   => 1,
    ], admin_url( 'admin.php' ) );

    return '<a href="' . esc_url( $url ) . '" class="lgp-sort-link' . ( $col === $current_col ? ' lgp-sort-active' : '' ) . '">'
        . esc_html( $label ) . '<span class="lgp-sort-arrow">' . $arrow . '</span></a>';
}
?>
<div class="wrap lgp-wrap">

    <!-- ── Page Header ── -->
    <div class="lgp-page-header">
        <h1 class="lgp-page-title">
            <span class="dashicons dashicons-randomize"></span>
            <?php esc_html_e( 'LinkHawk — 301 Redirects', 'linkguard' ); ?>
        </h1>
        <div class="lgp-header-actions">
            <span class="lgp-redir-total-badge">
                <?php printf( esc_html__( '%d active redirect(s)', 'linkguard' ), lgp_count_redirects() ); ?>
            </span>
        </div>
    </div>

    <!-- ── Add Redirect Form ── -->
    <div class="lgp-card lgp-add-redirect-card">
        <h2>
            <span class="dashicons dashicons-plus-alt" style="color:var(--wp-admin-theme-color,#2271b1);margin-right:4px;"></span>
            <?php esc_html_e( 'Add New Redirect', 'linkguard' ); ?>
        </h2>

        <?php if ( $form_error ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $form_error ); ?></p></div>
        <?php endif; ?>
        <?php if ( $form_success ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( $form_success ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="" id="lgp-add-redirect-form">
            <?php wp_nonce_field( 'lgp_add_redirect_form' ); ?>
            <div class="lgp-redirect-form-row">
                <div class="lgp-redirect-form-field">
                    <label for="source_url">
                        <span class="lgp-field-icon dashicons dashicons-warning"></span>
                        <?php esc_html_e( 'Broken / Old URL', 'linkguard' ); ?>
                    </label>
                    <input type="url" name="source_url" id="source_url" class="large-text"
                        value="<?php echo esc_attr( $prefill_source ); ?>"
                        placeholder="https://example.com/old-page/" required />
                    <p class="description"><?php esc_html_e( 'The URL you want to redirect away from.', 'linkguard' ); ?></p>
                </div>
                <div class="lgp-redirect-arrow">
                    <span class="dashicons dashicons-arrow-right-alt"></span>
                </div>
                <div class="lgp-redirect-form-field">
                    <label for="target_url">
                        <span class="lgp-field-icon dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Redirect To (Target)', 'linkguard' ); ?>
                    </label>
                    <input type="url" name="target_url" id="target_url" class="large-text"
                        placeholder="https://example.com/new-page/" required />
                    <p class="description"><?php esc_html_e( 'Visitors will be 301-redirected here.', 'linkguard' ); ?></p>
                </div>
                <div class="lgp-redirect-submit">
                    <button type="submit" name="lgp_add_redirect_submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-randomize" style="margin-top:3px;margin-right:4px;"></span>
                        <?php esc_html_e( 'Add Redirect', 'linkguard' ); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Active Redirects Table ── -->
    <div class="lgp-card">
        <div class="lgp-redirects-header">
            <h2 style="margin:0;border:none;padding:0;">
                <?php esc_html_e( 'Active Redirects', 'linkguard' ); ?>
                <?php if ( $total > 0 ) : ?>
                    <span class="lgp-count-bubble"><?php echo esc_html( $total ); ?></span>
                <?php endif; ?>
            </h2>

            <!-- Search -->
            <form method="get" action="" class="lgp-search-form">
                <input type="hidden" name="page" value="linkguard-redirects" />
                <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
                <input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
                <div class="lgp-search-wrap">
                    <span class="dashicons dashicons-search lgp-search-icon"></span>
                    <input type="search" name="redir_search" id="lgp-redir-search"
                        value="<?php echo esc_attr( $search ); ?>"
                        placeholder="<?php esc_attr_e( 'Search redirects…', 'linkguard' ); ?>"
                        class="lgp-search-input"
                    />
                    <button type="submit" class="button"><?php esc_html_e( 'Search', 'linkguard' ); ?></button>
                    <?php if ( $search ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=linkguard-redirects' ) ); ?>" class="button">&times; <?php esc_html_e( 'Clear', 'linkguard' ); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ( $search && $total === 0 ) : ?>
            <p class="lgp-no-results">
                <?php printf( esc_html__( 'No redirects found for "%s".', 'linkguard' ), esc_html( $search ) ); ?>
            </p>
        <?php elseif ( empty( $redirects ) && ! $search ) : ?>
            <p class="lgp-no-results"><?php esc_html_e( 'No redirects configured yet. Add one above!', 'linkguard' ); ?></p>
        <?php else : ?>

            <div class="lgp-table-wrap">
            <table class="wp-list-table widefat fixed striped lgp-redirects-table" id="lgp-redirects-table">
                <thead>
                    <tr>
                        <th class="lgp-th-source">
                            <?php echo lgp_redir_sort_link( 'source_url', __( 'Source URL', 'linkguard' ), $orderby, $order, $search, $page ); // phpcs:ignore ?>
                        </th>
                        <th class="lgp-th-target">
                            <?php echo lgp_redir_sort_link( 'target_url', __( 'Target URL', 'linkguard' ), $orderby, $order, $search, $page ); // phpcs:ignore ?>
                        </th>
                        <th class="lgp-th-hits">
                            <?php echo lgp_redir_sort_link( 'hit_count', __( 'Hits', 'linkguard' ), $orderby, $order, $search, $page ); // phpcs:ignore ?>
                        </th>
                        <th class="lgp-th-date">
                            <?php echo lgp_redir_sort_link( 'created_at', __( 'Created', 'linkguard' ), $orderby, $order, $search, $page ); // phpcs:ignore ?>
                        </th>
                        <th class="lgp-th-action"><?php esc_html_e( 'Action', 'linkguard' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $redirects as $redir ) : ?>
                    <tr id="lgp-redir-row-<?php echo esc_attr( $redir->id ); ?>">
                        <td class="lgp-url-cell lgp-th-source">
                            <span class="lgp-source-badge">FROM</span>
                            <?php echo esc_html( $redir->source_url ); ?>
                        </td>
                        <td class="lgp-url-cell lgp-th-target">
                            <span class="lgp-target-badge">TO</span>
                            <a href="<?php echo esc_url( $redir->target_url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( $redir->target_url ); ?>
                            </a>
                        </td>
                        <td class="lgp-th-hits">
                            <span class="lgp-hits-badge <?php echo (int) $redir->hit_count > 0 ? 'lgp-hits-badge--active' : ''; ?>">
                                <?php echo esc_html( number_format_i18n( (int) $redir->hit_count ) ); ?>
                            </span>
                        </td>
                        <td class="lgp-th-date">
                            <?php echo esc_html( $redir->created_at ); ?>
                        </td>
                        <td class="lgp-th-action">
                            <button
                                class="button button-small lgp-delete-redirect-btn"
                                data-id="<?php echo esc_attr( $redir->id ); ?>"
                                data-nonce="<?php echo esc_attr( $delete_nonce ); ?>"
                            >
                                <span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;margin-top:3px;"></span>
                                <?php esc_html_e( 'Delete', 'linkguard' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Pagination -->
            <?php if ( $pages > 1 ) : ?>
            <div class="lgp-pagination lgp-redir-pagination">
                <?php
                $base_args = [
                    'page'         => 'linkguard-redirects',
                    'orderby'      => $orderby,
                    'order'        => $order,
                    'redir_search' => $search,
                ];
                ?>
                <?php if ( $page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'redir_page' => $page - 1 ] ), admin_url( 'admin.php' ) ) ); ?>" class="button">&laquo; <?php esc_html_e( 'Prev', 'linkguard' ); ?></a>
                <?php endif; ?>

                <span class="lgp-page-info">
                    <?php printf( esc_html__( 'Page %1$d of %2$d', 'linkguard' ), $page, $pages ); ?>
                    &nbsp;·&nbsp;
                    <?php printf( esc_html__( '%d total', 'linkguard' ), $total ); ?>
                </span>

                <?php if ( $page < $pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'redir_page' => $page + 1 ] ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Next', 'linkguard' ); ?> &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

</div>
