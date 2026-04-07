<?php
defined( 'ABSPATH' ) || exit;

/**
 * Run a full site scan.
 *
 * @return array  [ 'scanned' => int, 'broken' => int, 'posts' => int ]
 */
function lgp_run_scan() {
    lgp_clear_links();

    $posts        = lgp_get_scannable_posts();
    $total_broken = 0;
    $excluded     = lgp_get_excluded_domains();
    $timeout      = (int) get_option( 'lgp_timeout', 10 );

    foreach ( $posts as $post ) {
        $links = lgp_extract_links( $post->post_content );

        foreach ( $links as $link ) {
            $url    = $link['url'];
            $anchor = $link['anchor'];

            if ( lgp_should_skip( $url, $excluded ) ) {
                continue;
            }

            $status = lgp_check_url( $url, $timeout );

            if ( lgp_is_broken( $status ) ) {
                lgp_upsert_link( [
                    'post_id'     => $post->ID,
                    'post_title'  => $post->post_title,
                    'post_url'    => get_permalink( $post->ID ),
                    'broken_url'  => $url,
                    'anchor_text' => $anchor,
                    'http_status' => (string) $status,
                ] );
                $total_broken++;
            }
        }
    }

    update_option( 'linkguard_last_scan', current_time( 'mysql' ) );

    return [
        'scanned' => count( $posts ),
        'broken'  => $total_broken,
        'posts'   => count( $posts ),
    ];
}

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
 * Parse excluded domains from settings (one per line).
 *
 * @return string[]
 */
function lgp_get_excluded_domains() {
    $raw = get_option( 'lgp_excluded_domains', '' );
    if ( empty( $raw ) ) {
        return [];
    }

    $lines = explode( "\n", $raw );
    $list  = [];

    foreach ( $lines as $line ) {
        $domain = trim( strtolower( $line ) );
        if ( $domain !== '' ) {
            $list[] = $domain;
        }
    }

    return $list;
}

/**
 * Extract all <a href> links from post HTML.
 *
 * @param string $content
 * @return array  [ [ 'url' => string, 'anchor' => string ], … ]
 */
function lgp_extract_links( $content ) {
    if ( empty( $content ) ) {
        return [];
    }

    $content = do_shortcode( $content );
    $links   = [];

    if ( ! preg_match_all(
        '/<a\s[^>]*href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/is',
        $content,
        $matches,
        PREG_SET_ORDER
    ) ) {
        return [];
    }

    foreach ( $matches as $match ) {
        $url    = trim( $match[1] );
        $anchor = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $match[2] ) ) );

        if ( $url ) {
            $links[] = [
                'url'    => $url,
                'anchor' => $anchor !== '' ? $anchor : '(no text)',
            ];
        }
    }

    // De-duplicate by URL.
    $seen   = [];
    $unique = [];
    foreach ( $links as $link ) {
        if ( ! isset( $seen[ $link['url'] ] ) ) {
            $seen[ $link['url'] ] = true;
            $unique[]             = $link;
        }
    }

    return $unique;
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

    // Pure fragment.
    if ( str_starts_with( $lower, '#' ) ) {
        return true;
    }

    // Non-web schemes.
    foreach ( [ 'mailto:', 'tel:', 'javascript:', 'fax:', 'sms:' ] as $scheme ) {
        if ( str_starts_with( $lower, $scheme ) ) {
            return true;
        }
    }

    // Relative paths are fine — resolve against home URL.
    if ( str_starts_with( $url, '/' ) ) {
        return false;
    }

    // Must be http(s).
    if ( ! str_starts_with( $lower, 'http://' ) && ! str_starts_with( $lower, 'https://' ) ) {
        return true;
    }

    // Excluded domains.
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
 * HTTP HEAD (→ GET fallback) with configurable timeout.
 *
 * @param string $url
 * @param int    $timeout  Seconds.
 * @return int|string  HTTP status code, or 'timeout' / 'error'.
 */
function lgp_check_url( $url, $timeout = 10 ) {
    if ( str_starts_with( $url, '/' ) ) {
        $url = home_url( $url );
    }

    $args = [
        'timeout'     => max( 5, (int) $timeout ),
        'redirection' => 3,
        'user-agent'  => 'LinkHawk/' . LINKGUARD_VERSION . ' (WordPress broken-link checker; +https://trsoftech.com)',
        'sslverify'   => false,
    ];

    $response = wp_remote_head( $url, $args );

    if ( is_wp_error( $response ) ) {
        return lgp_wp_error_to_status( $response );
    }

    $status = (int) wp_remote_retrieve_response_code( $response );

    // Some servers reject HEAD — fall back to GET.
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
 * Map a WP_Error from wp_remote_* to a string status token.
 *
 * @param WP_Error $error
 * @return string  'timeout' or 'error'
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
 * Return true if the status represents a broken link.
 *
 * @param int|string $status
 * @return bool
 */
function lgp_is_broken( $status ) {
    if ( in_array( $status, [ 'timeout', 'error' ], true ) ) {
        return true;
    }
    return is_int( $status ) && $status >= 400;
}
