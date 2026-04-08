<?php
defined( 'ABSPATH' ) || exit;

// Handle settings save.
$saved = false;
$error = '';

if ( isset( $_POST['lgp_settings_submit'] ) ) {
    check_admin_referer( 'lgp_save_settings' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'linkhawk' ) );
    }

    $timeout          = max( 5, min( 60, absint( $_POST['lgp_timeout'] ?? 10 ) ) );
    $per_page         = max( 5, min( 200, absint( $_POST['lgp_per_page'] ?? 20 ) ) );
    $email_notify     = isset( $_POST['lgp_email_notify'] ) ? '1' : '0';
    $notify_email     = sanitize_email( wp_unslash( $_POST['lgp_notify_email'] ?? '' ) );
    $excluded_domains = sanitize_textarea_field( wp_unslash( $_POST['lgp_excluded_domains'] ?? '' ) );

    if ( '1' === $email_notify && ! is_email( $notify_email ) ) {
        $error = __( 'Please enter a valid notification email address.', 'linkhawk' );
    } else {
        update_option( 'lgp_timeout', $timeout );
        update_option( 'lgp_per_page', $per_page );
        update_option( 'lgp_email_notify', $email_notify );
        update_option( 'lgp_notify_email', $notify_email ?: get_option( 'admin_email' ) );
        update_option( 'lgp_excluded_domains', $excluded_domains );
        $saved = true;
    }
}

$opt_timeout      = (int) get_option( 'lgp_timeout', 10 );
$opt_per_page     = (int) get_option( 'lgp_per_page', 20 );
$opt_email_notify = get_option( 'lgp_email_notify', '0' );
$opt_notify_email = get_option( 'lgp_notify_email', get_option( 'admin_email' ) );
$opt_excluded     = get_option( 'lgp_excluded_domains', '' );

$next_cron = wp_next_scheduled( 'lgp_daily_scan' );
?>
<div class="wrap lgp-wrap">

    <h1 class="lgp-page-title">
        <span class="dashicons dashicons-admin-settings"></span>
        <?php esc_html_e( 'LinkHawk — Settings', 'linkhawk' ); ?>
    </h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved successfully.', 'linkhawk' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'lgp_save_settings' ); ?>

        <!-- ── Scanner Settings ── -->
        <div class="lgp-card">
            <h2><?php esc_html_e( 'Scanner Settings', 'linkhawk' ); ?></h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="lgp_timeout"><?php esc_html_e( 'Request Timeout', 'linkhawk' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            name="lgp_timeout"
                            id="lgp_timeout"
                            value="<?php echo esc_attr( $opt_timeout ); ?>"
                            min="5"
                            max="60"
                            class="small-text"
                        /> <?php esc_html_e( 'seconds', 'linkhawk' ); ?>
                        <p class="description"><?php esc_html_e( 'How long to wait before marking a URL as timed out. (5–60 seconds)', 'linkhawk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lgp_per_page"><?php esc_html_e( 'Links Per Page', 'linkhawk' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            name="lgp_per_page"
                            id="lgp_per_page"
                            value="<?php echo esc_attr( $opt_per_page ); ?>"
                            min="5"
                            max="200"
                            class="small-text"
                        />
                        <p class="description"><?php esc_html_e( 'Number of broken links to show per page in the dashboard. (5–200)', 'linkhawk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Excluded Domains', 'linkhawk' ); ?>
                    </th>
                    <td>
                        <textarea
                            name="lgp_excluded_domains"
                            id="lgp_excluded_domains"
                            rows="6"
                            class="large-text code"
                            placeholder="example.com&#10;cdn.example.org"
                        ><?php echo esc_textarea( $opt_excluded ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One domain per line. Links pointing to these domains will be skipped during scans.', 'linkhawk' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Cron Status ── -->
        <div class="lgp-card">
            <h2><?php esc_html_e( 'Automatic Scan Schedule', 'linkhawk' ); ?></h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Daily Scan', 'linkhawk' ); ?></th>
                    <td>
                        <?php if ( $next_cron ) : ?>
                            <span class="lgp-badge lgp-badge-ok"><?php esc_html_e( 'Active', 'linkhawk' ); ?></span>
                            <span style="margin-left:8px;color:#646970;">
                                <?php
                                printf(
                                    /* translators: %s: human-readable time diff */
                                    esc_html__( 'Next run in %s', 'linkhawk' ),
                                    esc_html( human_time_diff( time(), $next_cron ) )
                                );
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="lgp-badge lgp-badge-error"><?php esc_html_e( 'Not scheduled', 'linkhawk' ); ?></span>
                            <p class="description"><?php esc_html_e( 'Deactivate and re-activate the plugin to re-schedule.', 'linkhawk' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Email Notifications ── -->
        <div class="lgp-card">
            <h2><?php esc_html_e( 'Email Notifications', 'linkhawk' ); ?></h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable Notifications', 'linkhawk' ); ?></th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="lgp_email_notify"
                                id="lgp_email_notify"
                                value="1"
                                <?php checked( '1', $opt_email_notify ); ?>
                            />
                            <?php esc_html_e( 'Send an email when broken links are found after a scan', 'linkhawk' ); ?>
                        </label>
                    </td>
                </tr>
                <tr id="lgp-email-row" <?php echo '1' !== $opt_email_notify ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="lgp_notify_email"><?php esc_html_e( 'Notification Email', 'linkhawk' ); ?></label>
                    </th>
                    <td>
                        <input
                            type="email"
                            name="lgp_notify_email"
                            id="lgp_notify_email"
                            value="<?php echo esc_attr( $opt_notify_email ); ?>"
                            class="regular-text"
                        />
                        <p class="description"><?php esc_html_e( 'Defaults to the WordPress admin email if left empty.', 'linkhawk' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Ignored URLs ── -->
        <div class="lgp-card">
            <h2><?php esc_html_e( 'Ignored URLs', 'linkhawk' ); ?></h2>
            <p class="description" style="margin-bottom:16px;">
                <?php esc_html_e( 'These URLs are permanently skipped during scans. Click "Remove" to re-enable checking.', 'linkhawk' ); ?>
            </p>
            <?php $ignored = lgp_get_ignored_urls(); ?>
            <?php if ( empty( $ignored ) ) : ?>
                <p class="lgp-no-results"><?php esc_html_e( 'No URLs ignored yet. Click "Ignore" on any broken link to add it here.', 'linkhawk' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" id="lgp-ignored-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Ignored URL', 'linkhawk' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Ignored At', 'linkhawk' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Action', 'linkhawk' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $ignored as $item ) : ?>
                        <tr id="lgp-ignored-row-<?php echo esc_attr( $item->id ); ?>">
                            <td class="lgp-url-cell">
                                <a href="<?php echo esc_url( $item->url ); ?>" target="_blank" rel="noopener nofollow">
                                    <?php echo esc_html( $item->url ); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( $item->ignored_at ); ?></td>
                            <td>
                                <button class="button button-small lgp-unignore-btn" data-id="<?php echo esc_attr( $item->id ); ?>">
                                    <?php esc_html_e( 'Remove', 'linkhawk' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- ── About ── -->
        <div class="lgp-card lgp-about-card">
            <div class="lgp-about-logo">
                <span class="dashicons dashicons-admin-links"></span>
            </div>
            <div class="lgp-about-text">
                <strong>LinkHawk – Broken Link Checker</strong> v<?php echo esc_html( LINKGUARD_VERSION ); ?><br>
                <?php esc_html_e( 'Built by', 'linkhawk' ); ?>
                <a href="https://trsoftech.com" target="_blank" rel="noopener">Kamlesh Kumar Jangir — TRSoftech</a>
            </div>
        </div>

        <?php submit_button( __( 'Save Settings', 'linkhawk' ), 'primary large', 'lgp_settings_submit' ); ?>

    </form>
</div>

<script>
( function() {
    var cb  = document.getElementById( 'lgp_email_notify' );
    var row = document.getElementById( 'lgp-email-row' );
    if ( ! cb || ! row ) return;
    cb.addEventListener( 'change', function() {
        row.style.display = cb.checked ? '' : 'none';
    } );
} )();
</script>
