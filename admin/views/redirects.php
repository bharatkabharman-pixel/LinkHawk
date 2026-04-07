<?php
defined( 'ABSPATH' ) || exit;

// Handle form submission (add redirect via standard POST).
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

// Pre-fill source URL if arriving from dashboard "Add 301 Redirect" button.
$prefill_source = isset( $_GET['source'] ) ? esc_url_raw( wp_unslash( $_GET['source'] ) ) : '';

$redirects = lgp_get_redirects();
?>
<div class="wrap lgp-wrap">

    <h1 class="lgp-page-title">
        <span class="dashicons dashicons-randomize"></span>
        <?php esc_html_e( 'LinkGuard — 301 Redirects', 'linkguard' ); ?>
    </h1>

    <!-- Add redirect form -->
    <div class="lgp-card">
        <h2><?php esc_html_e( 'Add New Redirect', 'linkguard' ); ?></h2>

        <?php if ( $form_error ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $form_error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $form_success ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( $form_success ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="" id="lgp-add-redirect-form">
            <?php wp_nonce_field( 'lgp_add_redirect_form' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="source_url"><?php esc_html_e( 'Source URL (old / broken)', 'linkguard' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="url"
                            name="source_url"
                            id="source_url"
                            class="regular-text"
                            value="<?php echo esc_attr( $prefill_source ); ?>"
                            placeholder="https://example.com/old-page/"
                            required
                        />
                        <p class="description"><?php esc_html_e( 'The URL you want to redirect away from.', 'linkguard' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="target_url"><?php esc_html_e( 'Target URL (destination)', 'linkguard' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="url"
                            name="target_url"
                            id="target_url"
                            class="regular-text"
                            placeholder="https://example.com/new-page/"
                            required
                        />
                        <p class="description"><?php esc_html_e( 'Visitors will be 301-redirected here.', 'linkguard' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="lgp_add_redirect_submit" class="button button-primary">
                    <?php esc_html_e( 'Add Redirect', 'linkguard' ); ?>
                </button>
            </p>
        </form>
    </div>

    <!-- Redirects table -->
    <div class="lgp-card">
        <h2><?php esc_html_e( 'Active Redirects', 'linkguard' ); ?></h2>

        <?php if ( empty( $redirects ) ) : ?>
            <p class="lgp-no-results"><?php esc_html_e( 'No redirects configured yet.', 'linkguard' ); ?></p>
        <?php else : ?>
            <div class="lgp-table-wrap">
            <table class="wp-list-table widefat fixed striped lgp-redirects-table" id="lgp-redirects-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Source URL', 'linkguard' ); ?></th>
                        <th><?php esc_html_e( 'Target URL', 'linkguard' ); ?></th>
                        <th class="lgp-col-hits"><?php esc_html_e( 'Hits', 'linkguard' ); ?></th>
                        <th><?php esc_html_e( 'Created At', 'linkguard' ); ?></th>
                        <th class="lgp-col-action"><?php esc_html_e( 'Delete', 'linkguard' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $redirects as $redir ) : ?>
                    <tr id="lgp-redir-row-<?php echo esc_attr( $redir->id ); ?>">
                        <td class="lgp-url-cell"><?php echo esc_html( $redir->source_url ); ?></td>
                        <td class="lgp-url-cell">
                            <a href="<?php echo esc_url( $redir->target_url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( $redir->target_url ); ?>
                            </a>
                        </td>
                        <td class="lgp-col-hits">
                            <span class="lgp-hits-badge"><?php echo esc_html( number_format_i18n( (int) $redir->hit_count ) ); ?></span>
                        </td>
                        <td><?php echo esc_html( $redir->created_at ); ?></td>
                        <td class="lgp-col-action">
                            <button
                                class="button button-small lgp-delete-redirect-btn"
                                data-id="<?php echo esc_attr( $redir->id ); ?>"
                            ><?php esc_html_e( 'Delete', 'linkguard' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

</div><!-- .wrap -->
