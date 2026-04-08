<?php
defined( 'ABSPATH' ) || exit;

$total        = lgp_count_broken_links();
$by_status    = lgp_count_by_status();
$by_type      = lgp_count_by_type();
$affected     = lgp_count_affected_posts();
$last_scan    = get_option( 'linkguard_last_scan', '' );
$per_page     = (int) get_option( 'lgp_per_page', 20 );
$current_page = max( 1, absint( $_GET['lgp_page'] ?? 1 ) );
$links        = lgp_get_broken_links_paged( $current_page, $per_page );

$count_404     = $by_status['404']     ?? 0;
$count_timeout = $by_status['timeout'] ?? 0;
$count_images  = $by_type['image']     ?? 0;
$count_links   = $by_type['link']      ?? 0;
?>
<div class="wrap lgp-wrap">

    <!-- ── Page Header ── -->
    <div class="lgp-page-header">
        <h1 class="lgp-page-title">
            <span class="dashicons dashicons-admin-links"></span>
            <?php esc_html_e( 'LinkHawk — Broken Links', 'linkguard' ); ?>
        </h1>
        <div class="lgp-header-actions">
            <?php if ( $total > 0 ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=lgp_export_csv&nonce=' . wp_create_nonce( 'lgp_export_csv' ) ) ); ?>" class="button">
                <span class="dashicons dashicons-download" style="margin-top:3px;margin-right:3px;"></span>
                <?php esc_html_e( 'Export CSV', 'linkguard' ); ?>
            </a>
            <?php endif; ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=linkguard-settings' ) ); ?>" class="button">
                <span class="dashicons dashicons-admin-settings" style="margin-top:3px;margin-right:3px;"></span>
                <?php esc_html_e( 'Settings', 'linkguard' ); ?>
            </a>
        </div>
    </div>

    <!-- ── Stats Cards ── -->
    <div class="lgp-stats-bar">

        <div class="lgp-stat-card <?php echo $total > 0 ? 'lgp-stat-card--alert' : 'lgp-stat-card--ok'; ?>">
            <span class="lgp-stat-icon dashicons <?php echo $total > 0 ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>"></span>
            <div>
                <span class="lgp-stat-number" id="lgp-broken-count"><?php echo esc_html( $total ); ?></span>
                <span class="lgp-stat-label"><?php esc_html_e( 'Total Broken', 'linkguard' ); ?></span>
            </div>
        </div>

        <div class="lgp-stat-card">
            <span class="lgp-stat-icon dashicons dashicons-admin-page"></span>
            <div>
                <span class="lgp-stat-number" id="lgp-affected-count"><?php echo esc_html( $affected ); ?></span>
                <span class="lgp-stat-label"><?php esc_html_e( 'Affected Pages', 'linkguard' ); ?></span>
            </div>
        </div>

        <div class="lgp-stat-card lgp-stat-card--404">
            <span class="lgp-stat-icon dashicons dashicons-dismiss"></span>
            <div>
                <span class="lgp-stat-number"><?php echo esc_html( $count_404 ); ?></span>
                <span class="lgp-stat-label"><?php esc_html_e( '404 Not Found', 'linkguard' ); ?></span>
            </div>
        </div>

        <div class="lgp-stat-card lgp-stat-card--timeout">
            <span class="lgp-stat-icon dashicons dashicons-clock"></span>
            <div>
                <span class="lgp-stat-number"><?php echo esc_html( $count_timeout ); ?></span>
                <span class="lgp-stat-label"><?php esc_html_e( 'Timeouts / Errors', 'linkguard' ); ?></span>
            </div>
        </div>

        <div class="lgp-stat-card lgp-stat-card--image">
            <span class="lgp-stat-icon dashicons dashicons-format-image"></span>
            <div>
                <span class="lgp-stat-number"><?php echo esc_html( $count_images ); ?></span>
                <span class="lgp-stat-label"><?php esc_html_e( 'Broken Images', 'linkguard' ); ?></span>
            </div>
        </div>

        <div class="lgp-stat-card lgp-stat-card--scan">
            <span class="lgp-stat-icon dashicons dashicons-calendar-alt"></span>
            <div>
                <span class="lgp-stat-number lgp-last-scan-val" id="lgp-last-scan">
                    <?php echo $last_scan ? esc_html( $last_scan ) : esc_html__( 'Never', 'linkguard' ); ?>
                </span>
                <span class="lgp-stat-label"><?php esc_html_e( 'Last Scan', 'linkguard' ); ?></span>
            </div>
        </div>

    </div>

    <!-- ── Scan Toolbar ── -->
    <div class="lgp-toolbar">
        <button id="lgp-scan-btn" class="button button-primary button-hero">
            <span class="dashicons dashicons-update lgp-btn-icon"></span>
            <?php esc_html_e( 'Scan Now', 'linkguard' ); ?>
        </button>

        <div id="lgp-scan-message" class="lgp-scan-message" style="display:none;" role="alert" aria-live="polite"></div>
    </div>

    <!-- ── Progress Bar ── -->
    <div id="lgp-progress-wrap" style="display:none;">
        <div class="lgp-progress-header">
            <span id="lgp-progress-label"><?php esc_html_e( 'Starting scan…', 'linkguard' ); ?></span>
            <span id="lgp-progress-counter">0 / 0</span>
        </div>
        <div class="lgp-progress-bar-track">
            <div class="lgp-progress-bar-fill" id="lgp-progress-fill" style="width:0%"></div>
        </div>
        <div class="lgp-progress-stats">
            <span><?php esc_html_e( 'Broken found:', 'linkguard' ); ?> <strong id="lgp-progress-broken">0</strong></span>
            <span><?php esc_html_e( 'Posts scanned:', 'linkguard' ); ?> <strong id="lgp-progress-scanned">0</strong></span>
        </div>
    </div>

    <!-- ── Broken Links Table ── -->
    <div id="lgp-links-container">
        <?php lgp_output_links_table( $links, $total, $current_page, $per_page ); ?>
    </div>

</div>

<!-- ── Add 301 Redirect Modal ── -->
<div id="lgp-redirect-modal" class="lgp-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="lgp-modal-title">
    <div class="lgp-modal-backdrop"></div>
    <div class="lgp-modal-box">
        <button class="lgp-modal-close" id="lgp-modal-cancel" aria-label="<?php esc_attr_e( 'Close', 'linkguard' ); ?>">&times;</button>
        <h2 id="lgp-modal-title">
            <span class="dashicons dashicons-randomize"></span>
            <?php esc_html_e( 'Add 301 Redirect', 'linkguard' ); ?>
        </h2>
        <p class="lgp-modal-desc"><?php esc_html_e( 'Visitors hitting the broken URL will be permanently redirected to your target.', 'linkguard' ); ?></p>
        <div class="lgp-modal-fields">
            <div class="lgp-modal-field">
                <label for="lgp-modal-source"><?php esc_html_e( 'Source (broken URL)', 'linkguard' ); ?></label>
                <input type="url" id="lgp-modal-source" class="widefat" readonly />
                <input type="hidden" id="lgp-modal-link-id" value="" />
            </div>
            <div class="lgp-modal-field">
                <label for="lgp-modal-target"><?php esc_html_e( 'Redirect To', 'linkguard' ); ?></label>
                <input type="url" id="lgp-modal-target" class="widefat" placeholder="https://example.com/new-page/" required />
                <p class="description"><?php esc_html_e( 'Full URL including https://', 'linkguard' ); ?></p>
            </div>
        </div>
        <p id="lgp-modal-error" class="lgp-modal-error" style="display:none;"></p>
        <div class="lgp-modal-footer">
            <button id="lgp-modal-save" class="button button-primary button-large">
                <span class="dashicons dashicons-yes" style="margin-top:3px;"></span>
                <?php esc_html_e( 'Save Redirect', 'linkguard' ); ?>
            </button>
            <button class="button button-large lgp-modal-cancel-btn"><?php esc_html_e( 'Cancel', 'linkguard' ); ?></button>
        </div>
    </div>
</div>
