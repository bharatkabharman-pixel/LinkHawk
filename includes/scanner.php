<?php
defined( 'ABSPATH' ) || exit;

/**
 * Full scan — used by cron and legacy fallback.
 */
function lgp_run_scan() {
    lgp_clear_links();

    $posts          = lgp_get_scannable_posts();
    $total_broken   = 0;
    $excluded       = lgp_get_excluded_domains();
    $ignored_hashes = lgp_get_ignored_url_hashes();
    $timeout        = (int) get_option( 'lgp_timeout', 10 );

    foreach ( $posts as $post ) {
        $total_broken += lgp_scan_single_post( $post, $excluded, $ignored_hashes, $timeout );
    }

    update_option( 'linkhawk_last_scan', current_time( 'mysql' ) );

    return [
        'scanned' => count( $posts ),
        'broken'  => $total_broken,
        'posts'   => count( $posts ),
    ];
}

// ── Chunked scan (AJAX real-time) ─────────────────────────────────────────────

/**
 * Return all post IDs to scan — initialise chunked AJAX scan.
 *
 * @return int[]
 */
function lgp_get_scan_post_ids() {
    $posts = lgp_get_scannable_posts();
    return array_map( fn( $p ) => (int) $p->ID, $posts );
}

/**
 * Scan a single post by ID. Used by chunked AJAX scan.
 *
 * @param int $post_id
 * @return array
 */
function lgp_scan_post_by_id( $post_id ) {
    $post = get_post( (int) $post_id );

    if ( ! $post || 'publish' !== $post->post_status ) {
        return [ 'broken' => 0, 'checked' => 0, 'post_title' => '' ];
    }

    $excluded       = lgp_get_excluded_domains();
    $ignored_hashes = lgp_get_ignored_url_hashes();
    $timeout        = (int) get_option( 'lgp_timeout', 10 );
    $items          = lgp_extract_all_items( $post->post_content );

    $broken  = lgp_scan_single_post( $post, $excluded, $ignored_hashes, $timeout );
    $checked = count( array_filter( $items, fn( $i ) => ! lgp_should_skip( $i['url'], $excluded ) ) );

    return [
        'broken'     => $broken,
        'checked'    => $checked,
        'post_title' => $post->post_title,
    ];
}

/**
 * Internal: scan one WP_Post and record broken items.
 *
 * @param WP_Post  $post
 * @param string[] $excluded
 * @param string[] $ignored_hashes
 * @param int      $timeout
 * @return int  Broken items found.
 */
function lgp_scan_single_post( WP_Post $post, array $excluded, array $ignored_hashes, int $timeout ) {
    $items  = lgp_extract_all_items( $post->post_content );
    $broken = 0;

    foreach ( $items as $item ) {
        $url = $item['url'];

        if ( lgp_should_skip( $url, $excluded ) ) {
            continue;
        }

        if ( ! empty( $ignored_hashes ) && in_array( md5( esc_url_raw( $url ) ), $ignored_hashes, true ) ) {
            continue;
        }

        $status = lgp_check_url( $url, $timeout );

        if ( lgp_is_broken( $status ) ) {
            lgp_upsert_link( [
                'post_id'     => $post->ID,
                'post_title'  => $post->post_title,
                'post_url'    => get_permalink( $post->ID ),
                'broken_url'  => $url,
                'anchor_text' => $item['anchor'],
                'http_status' => (string) $status,
                'link_type'   => $item['type'],
            ] );
            $broken++;
        }
    }

    return $broken;
}

// ── Extraction ────────────────────────────────────────────────────────────────

/**
 * Fetch all published posts and pages.
 *
 * @return WP_Post[]
 */
function lgp_get_scannable_posts() {
    return get_posts( [
        'post_type'      => [ 'post', 'page' ],
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'all',
    ] );
}

/**
 * Extract all checkable items: <a href> links + <img src> images.
 *
 * @param string $content
 * @return array  [ [ 'url', 'anchor', 'type' ], … ]
 */
function lgp_extract_all_items( $content ) {
    if ( empty( $content ) ) {
        return [];
    }

    $content = do_shortcode( $content );
    $items   = [];

    // <a href> links.
    if ( preg_match_all(
        '/<a\s[^>]*href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        $content,
        $matches,
        PREG_SET_ORDER
    ) ) {
        foreach ( $matches as $m ) {
            $url    = trim( $m[1] );
            $anchor = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[2] ) ) );

            if ( $url ) {
                $items[] = [
                    'url'    => $url,
                    'anchor' => $anchor !== '' ? $anchor : '(no text)',
                    'type'   => 'link',
                ];
            }
        }
    }

    // <img src> images.
    if ( preg_match_all(
        '/<img\s[^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is',
        $content,
        $img_matches,
        PREG_SET_ORDER
    ) ) {
        foreach ( $img_matches as $m ) {
            $url = trim( $m[1] );
            $alt = '';

            if ( preg_match( '/alt=["\']([^"\']*)["\']/', $m[0], $alt_match ) ) {
                $alt = trim( $alt_match[1] );
            }

            if ( $url ) {
                $items[] = [
                    'url'    => $url,
                    'anchor' => $alt !== '' ? $alt : '(no alt text)',
                    'type'   => 'image',
                ];
            }
        }
    }

    // De-duplicate by URL.
    $seen   = [];
    $unique = [];
    foreach ( $items as $item ) {
        if ( ! isset( $seen[ $item['url'] ] ) ) {
            $seen[ $item['url'] ] = true;
            $unique[]             = $item;
        }
    }

    return $unique;
}

/**
 * Parse excluded domains from settings.
 *
 * @return string[]
 */
function lgp_get_excluded_domains() {
    $raw = get_option( 'lgp_excluded_domains', '' );
    if ( empty( $raw ) ) {
        return [];
    }

    $list = [];
    foreach ( explode( "\n", $raw ) as $line ) {
        $domain = trim( strtolower( $line ) );
        if ( $domain !== '' ) {
            $list[] = $domain;
        }
    }

    return $list;
}

/**
 * Decide whether a URL should be skipped.
 *
 * @param string   $url
 * @param string[] $excluded_domains
 * @return bool
 */
function lgp_should_skip( $url, array $excluded_domains = [] ) {
    if ( empty( $url ) ) {
        return true;
    }

    $lower = strtolower( $url );

    if ( str_starts_with( $lower, '#' ) ) {
        return true;
    }

    foreach ( [ 'mailto:', 'tel:', 'javascript:', 'fax:', 'sms:', 'data:' ] as $scheme ) {
        if ( str_starts_with( $lower, $scheme ) ) {
            return true;
        }
    }

    if ( str_starts_with( $url, '/' ) ) {
        return false;
    }

    if ( ! str_starts_with( $lower, 'http://' ) && ! str_starts_with( $lower, 'https://' ) ) {
        return true;
    }

    if ( ! empty( $excluded_domains ) ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        foreach ( $excluded_domains as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Make HTTP request and return status code.
 *
 * @param string $url
 * @param int    $timeout
 * @return int|string
 */
function lgp_check_url( $url, $timeout = 10 ) {
    if ( str_starts_with( $url, '/' ) ) {
        $url = home_url( $url );
    }

    $args = [
        'timeout'     => max( 5, (int) $timeout ),
        'redirection' => 3,
        'user-agent'  => 'LinkHawk/' . LINKHAWK_VERSION . ' (WordPress broken-link checker; +https://trsoftech.com)',
        'sslverify'   => false,
    ];

    $response = wp_remote_head( $url, $args );

    if ( is_wp_error( $response ) ) {
        return lgp_wp_error_to_status( $response );
    }

    $status = (int) wp_remote_retrieve_response_code( $response );

    if ( 405 === $status ) {
        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return lgp_wp_error_to_status( $response );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
    }

    return $status;
}

/**
 * Map WP_Error to status string.
 */
function lgp_wp_error_to_status( WP_Error $error ) {
    $code    = $error->get_error_code();
    $message = $error->get_error_message();

    if ( str_contains( (string) $code, 'timeout' ) || str_contains( $message, 'timed out' ) ) {
        return 'timeout';
    }

    return 'error';
}

/**
 * Return true if status represents a broken link or image.
 */
function lgp_is_broken( $status ) {
    if ( in_array( $status, [ 'timeout', 'error' ], true ) ) {
        return true;
    }
    return is_int( $status ) && $status >= 400;
}
