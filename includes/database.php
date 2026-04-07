<?php
defined( 'ABSPATH' ) || exit;

/**
 * Create or upgrade plugin database tables.
 */
function lgp_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql_links = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}linkguard_links (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        post_title  TEXT NOT NULL,
        post_url    TEXT NOT NULL,
        broken_url  TEXT NOT NULL,
        anchor_text TEXT NOT NULL,
        http_status VARCHAR(20) NOT NULL DEFAULT '',
        detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id (post_id)
    ) $charset_collate;";

    $sql_redirects = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}linkguard_redirects (
        id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        source_url VARCHAR(2083) NOT NULL,
        target_url VARCHAR(2083) NOT NULL,
        hit_count  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY source_url (source_url(191))
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_links );
    dbDelta( $sql_redirects );

    update_option( 'linkguard_db_version', LINKGUARD_VERSION );
}

/**
 * Drop all plugin tables (called from uninstall.php).
 */
function lgp_drop_tables() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}linkguard_links" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}linkguard_redirects" );
}

// ── Broken links ──────────────────────────────────────────────────────────────

/**
 * Return broken links with optional pagination.
 *
 * @param int|null $post_id  Filter by post.
 * @return array
 */
function lgp_get_broken_links( $post_id = null ) {
    global $wpdb;

    if ( $post_id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}linkguard_links WHERE post_id = %d ORDER BY detected_at DESC",
                $post_id
            )
        );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}linkguard_links ORDER BY detected_at DESC"
    );
}

/**
 * Return a single page of broken links.
 *
 * @param int $page     1-based page number.
 * @param int $per_page Rows per page.
 * @return array
 */
function lgp_get_broken_links_paged( $page = 1, $per_page = 20 ) {
    global $wpdb;

    $offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}linkguard_links ORDER BY detected_at DESC LIMIT %d OFFSET %d",
            (int) $per_page,
            $offset
        )
    );
}

/**
 * Count total broken links.
 *
 * @return int
 */
function lgp_count_broken_links() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}linkguard_links" );
}

/**
 * Count broken links grouped by http_status.
 *
 * @return array  e.g. [ '404' => 5, 'timeout' => 2 ]
 */
function lgp_count_by_status() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        "SELECT http_status, COUNT(*) AS cnt FROM {$wpdb->prefix}linkguard_links GROUP BY http_status"
    );

    $map = [];
    foreach ( $rows as $row ) {
        $map[ $row->http_status ] = (int) $row->cnt;
    }
    return $map;
}

/**
 * Count distinct posts that have at least one broken link.
 *
 * @return int
 */
function lgp_count_affected_posts() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}linkguard_links"
    );
}

/**
 * Insert or update a broken link record.
 *
 * @param array $data
 */
function lgp_upsert_link( array $data ) {
    global $wpdb;

    $table = $wpdb->prefix . 'linkguard_links';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND broken_url = %s LIMIT 1",
            (int) $data['post_id'],
            $data['broken_url']
        )
    );

    if ( $existing_id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'http_status' => sanitize_text_field( $data['http_status'] ),
                'anchor_text' => sanitize_text_field( $data['anchor_text'] ),
                'detected_at' => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $existing_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'post_id'     => (int) $data['post_id'],
                'post_title'  => sanitize_text_field( $data['post_title'] ),
                'post_url'    => esc_url_raw( $data['post_url'] ),
                'broken_url'  => esc_url_raw( $data['broken_url'] ),
                'anchor_text' => sanitize_text_field( $data['anchor_text'] ),
                'http_status' => sanitize_text_field( $data['http_status'] ),
                'detected_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }
}

/**
 * Delete a single broken-link record.
 *
 * @param int $id
 */
function lgp_delete_link( $id ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete(
        $wpdb->prefix . 'linkguard_links',
        [ 'id' => (int) $id ],
        [ '%d' ]
    );
}

/**
 * Truncate the broken links table before a fresh scan.
 */
function lgp_clear_links() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}linkguard_links" );
}

// ── Redirects ─────────────────────────────────────────────────────────────────

/**
 * Return all redirect rules.
 *
 * @return array
 */
function lgp_get_redirects() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}linkguard_redirects ORDER BY created_at DESC"
    );
}

/**
 * Insert a new redirect rule.
 *
 * @param string $source
 * @param string $target
 * @return int|false
 */
function lgp_insert_redirect( $source, $target ) {
    global $wpdb;

    $source = trailingslashit( esc_url_raw( $source ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}linkguard_redirects WHERE source_url = %s LIMIT 1",
            $source
        )
    );

    if ( $exists ) {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert(
        $wpdb->prefix . 'linkguard_redirects',
        [
            'source_url' => $source,
            'target_url' => esc_url_raw( $target ),
            'hit_count'  => 0,
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%d', '%s' ]
    );

    return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
}

/**
 * Delete a redirect rule.
 *
 * @param int $id
 */
function lgp_delete_redirect( $id ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete(
        $wpdb->prefix . 'linkguard_redirects',
        [ 'id' => (int) $id ],
        [ '%d' ]
    );
}

/**
 * Increment hit counter for a redirect.
 *
 * @param int $id
 */
function lgp_increment_redirect_hits( $id ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}linkguard_redirects SET hit_count = hit_count + 1 WHERE id = %d",
            (int) $id
        )
    );
}
