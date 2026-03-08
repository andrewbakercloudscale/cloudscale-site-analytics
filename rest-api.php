<?php
/**
 * CloudScale Page Views - REST API
 *
 * Registers the POST endpoint that the beacon calls.
 * Multiple cache-bypass headers ensure Cloudflare never caches this route.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'cspv_register_endpoint' );

/**
 * Return the view count for a post, respecting the ignore Jetpack toggle.
 * When the toggle is on, returns tracked-only views from V2.
 * When off, returns the post meta value (includes Jetpack imported total).
 */
function cspv_public_view_count( $post_id ) {
    if ( get_option( 'cspv_ignore_jetpack', '0' ) === '1' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cspv_views_v2';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(view_count),0) FROM `{$table}` WHERE post_id = %d AND source = 'tracked'",
            $post_id
        ) );
    }
    return cspv_public_view_count( $post_id );
}

function cspv_register_endpoint() {
    register_rest_route(
        'cloudscale-page-views/v1',
        '/record/(?P<id>\d+)',
        array(
            'methods'             => 'POST',
            'callback'            => 'cspv_record_view',
            'permission_callback' => '__return_true',
            'args'                => array(
                'id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && (int) $param > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );

    // Diagnostics endpoint — used by the stats page to confirm beacon is reachable
    register_rest_route(
        'cloudscale-page-views/v1',
        '/ping',
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_ping',
            'permission_callback' => '__return_true',
        )
    );
}

function cspv_record_view( WP_REST_Request $request ) {
    cspv_send_nocache_headers();

    // Emergency kill switch — reject all recording
    if ( function_exists( 'cspv_tracking_paused' ) && cspv_tracking_paused() ) {
        return new WP_REST_Response( array(
            'post_id' => absint( $request->get_param( 'id' ) ),
            'views'   => 0,
            'logged'  => false,
            'paused'  => true,
        ), 200 );
    }

    // --- Validate post ID ------------------------------------------
    $post_id = absint( $request->get_param( 'id' ) );
    if ( $post_id <= 0 ) {
        return new WP_REST_Response( array( 'error' => 'Invalid post ID.' ), 400 );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_REST_Response( array( 'error' => 'Post not found.' ), 404 );
    }
    if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
        return new WP_REST_Response( array( 'error' => 'Post is not published.' ), 404 );
    }

    // Check post type filter — only record views for tracked types
    $track_types = get_option( 'cspv_track_post_types', array( 'post' ) );
    if ( ! empty( $track_types ) && ! in_array( $post->post_type, $track_types, true ) ) {
        return new WP_REST_Response( array(
            'post_id' => $post_id,
            'views'   => cspv_public_view_count( $post_id ),
            'logged'  => false,
        ), 200 );
    }

    // --- Extract real IP (Cloudflare-aware) -------------------------
    $raw_ip = '';
    $ip_headers = array(
        'HTTP_CF_CONNECTING_IP',   // Real IP passed by Cloudflare
        'HTTP_X_FORWARDED_FOR',    // Standard proxy header
        'HTTP_X_REAL_IP',          // Nginx proxy header
        'REMOTE_ADDR',             // Direct connection fallback
    );
    foreach ( $ip_headers as $header ) {
        if ( ! empty( $_SERVER[ $header ] ) ) {
            // X-Forwarded-For can be a comma-separated list — take first entry
            $raw_ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
            break;
        }
    }

    // Validate it looks like an IP address before hashing
    if ( $raw_ip && ! filter_var( $raw_ip, FILTER_VALIDATE_IP ) ) {
        $raw_ip = '';
    }

    $ip_hash = $raw_ip ? hash( 'sha256', $raw_ip . wp_salt() ) : '';

    $ua = '';
    if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
        $ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
    }

    // --- Capture referrer --------------------------------------------
    // Prefer the value sent by the beacon (document.referrer) because
    // the HTTP Referer header on the REST POST is the current page, not
    // the original referring site. Fall back to HTTP_REFERER only when
    // the beacon sends nothing.
    $referrer = '';
    $body     = $request->get_json_params();
    if ( ! empty( $body['referrer'] ) && is_string( $body['referrer'] ) ) {
        $referrer = esc_url_raw( substr( $body['referrer'], 0, 2048 ) );
    } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
        $referrer = esc_url_raw( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 2048 ) );
    }

    // --- IP throttle check -----------------------------------------
    if ( cspv_is_throttled( $ip_hash ) ) {
        // Silent accept — attacker gets no signal
        return new WP_REST_Response( array(
            'post_id' => $post_id,
            'views'   => cspv_public_view_count( $post_id ),
            'logged'  => false,
        ), 200 );
    }

    // --- Write to database -----------------------------------------
    global $wpdb;
    $v2_table    = $wpdb->prefix . 'cspv_views_v2';
    $hour_bucket = current_time( 'Y-m-d H' ) . ':00:00';

    // Confirm V2 table exists
    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) );
    if ( ! $table_exists ) {
        if ( function_exists( 'cspv_create_table_v2' ) ) {
            cspv_create_table_v2();
        }
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) );
        if ( ! $table_exists ) {
            return new WP_REST_Response( array( 'error' => 'Database table unavailable.' ), 500 );
        }
    }

    // Upsert hourly view bucket
    $result = $wpdb->query( $wpdb->prepare(
        "INSERT INTO `{$v2_table}` (post_id, viewed_at, view_count, source)
         VALUES (%d, %s, 1, 'tracked')
         ON DUPLICATE KEY UPDATE view_count = view_count + 1",
        $post_id, $hour_bucket
    ) );

    if ( $result === false ) {
        return new WP_REST_Response( array(
            'post_id'  => $post_id,
            'views'    => cspv_public_view_count( $post_id ),
            'logged'   => false,
            'error'    => 'Insert failed.',
            'db_error' => $wpdb->last_error,
        ), 200 );
    }

    // Upsert referrer bucket (only if referrer is non empty)
    if ( $referrer !== '' ) {
        $ref_table = $wpdb->prefix . 'cspv_referrers_v2';
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO `{$ref_table}` (post_id, viewed_at, referrer, view_count)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $post_id, $hour_bucket, substr( $referrer, 0, 512 )
        ) );
    }

    // Upsert geo bucket (resolve country from CF header or DB-IP mmdb)
    $geo_source = get_option( 'cspv_geo_source', 'auto' );
    $country    = '';
    if ( $geo_source !== 'disabled' ) {
        // Try CloudFlare header first (unless dbip only)
        if ( $geo_source !== 'dbip' && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $country = strtoupper( substr( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ), 0, 2 ) );
        }
        // Fall back to DB-IP mmdb lookup (unless cloudflare only)
        if ( $country === '' && $geo_source !== 'cloudflare' ) {
            $country = cspv_geo_lookup_dbip( $request->get_header( 'X-Forwarded-For' ) ?: $_SERVER['REMOTE_ADDR'] ?? '' );
        }
        // Write to geo table if we resolved a valid country
        if ( $country !== '' && $country !== 'XX' && $country !== 'T1' ) {
            $geo_table = $wpdb->prefix . 'cspv_geo_v2';
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO `{$geo_table}` (post_id, viewed_at, country_code, view_count)
                 VALUES (%d, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE view_count = view_count + 1",
                $post_id, $hour_bucket, $country
            ) );
        }
    }

    // Increment denormalised meta counter
    $current   = cspv_public_view_count( $post_id );
    $new_count = $current + 1;
    update_post_meta( $post_id, CSPV_META_KEY, $new_count );

    return new WP_REST_Response( array(
        'post_id' => $post_id,
        'views'   => cspv_public_view_count( $post_id ),
        'logged'  => true,
    ), 200 );
}


function cspv_ping( WP_REST_Request $request ) {
    cspv_send_nocache_headers();
    return new WP_REST_Response(
        array(
            'status'  => 'ok',
            'version' => CSPV_VERSION,
            'time'    => current_time( 'mysql' ),
        ),
        200
    );
}

/**
 * Send headers that tell Cloudflare and every other CDN/proxy never to
 * cache this response. Called before WP_REST_Response is returned so the
 * headers arrive before PHP output buffering flushes.
 */
function cspv_send_nocache_headers() {
    if ( headers_sent() ) {
        return;
    }
    // Standard HTTP cache-control — tells every intermediate cache to bypass
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    // Cloudflare-specific override
    header( 'Cloudflare-CDN-Cache-Control: no-store' );
    // Generic CDN override (Fastly, CloudFront, etc.)
    header( 'CDN-Cache-Control: no-store' );
    // Surrogate-Control used by Varnish and some CDNs
    header( 'Surrogate-Control: no-store' );
    // Vary: Cookie tells Cloudflare this response differs per session
    header( 'Vary: Cookie' );
}

// -------------------------------------------------------------------------
// Public GET endpoint: fetch view counts for one or more post IDs
// Used by the archive/home page JS to update counts client-side after
// Cloudflare serves a cached page.
//
// GET /wp-json/cloudscale-page-views/v1/counts?ids=1,2,3,4
// Returns: { "1": 42, "2": 7, ... }
// -------------------------------------------------------------------------
add_action( 'rest_api_init', 'cspv_register_counts_endpoint' );

function cspv_register_counts_endpoint() {
    register_rest_route(
        'cloudscale-page-views/v1',
        '/counts',
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_get_counts',
            'permission_callback' => '__return_true',
            'args'                => array(
                'ids' => array(
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        // Must be comma-separated integers
                        return (bool) preg_match( '/^[\d,]+$/', $param );
                    },
                    'sanitize_callback' => function( $param ) {
                        return array_filter( array_map( 'absint', explode( ',', $param ) ) );
                    },
                ),
            ),
        )
    );
}

function cspv_get_counts( WP_REST_Request $request ) {
    cspv_send_nocache_headers();

    $ids = $request->get_param( 'ids' );

    // get_param returns the sanitized value from the registered arg
    if ( empty( $ids ) || ! is_array( $ids ) ) {
        return new WP_REST_Response( (object) array(), 200 );
    }

    // Extra safety: ensure every element is a positive integer
    $ids = array_filter(
        array_map( 'absint', $ids ),
        function( $id ) { return $id > 0; }
    );

    if ( empty( $ids ) ) {
        return new WP_REST_Response( (object) array(), 200 );
    }

    // Cap at 50 IDs per request
    $ids    = array_slice( array_unique( $ids ), 0, 50 );
    $counts = array();

    foreach ( $ids as $id ) {
        $counts[ (string) $id ] = cspv_public_view_count( $id );
    }

    return new WP_REST_Response( $counts, 200 );
}

// -------------------------------------------------------------------------
// Cache bypass test endpoint
//
// GET  /wp-json/cloudscale-page-views/v1/cache-test
//   Returns the current counter value.
//
// POST /wp-json/cloudscale-page-views/v1/cache-test
//   Increments the counter and returns the new value.
//
// The counter is stored as a transient that expires in 5 minutes.
// If this endpoint is cached by Cloudflare the counter will never
// change and the test will correctly report the bypass as broken.
// -------------------------------------------------------------------------
add_action( 'rest_api_init', 'cspv_register_cache_test_endpoint' );

function cspv_register_cache_test_endpoint() {
    register_rest_route( 'cloudscale-page-views/v1', '/cache-test', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_cache_test_get',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods'             => 'POST',
            'callback'            => 'cspv_cache_test_post',
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ),
    ) );
}

function cspv_cache_test_get( WP_REST_Request $request ) {
    cspv_send_nocache_headers();
    $val = (int) get_transient( 'cspv_cache_test_counter' );
    return new WP_REST_Response( array(
        'counter'   => $val,
        'timestamp' => time(),
    ), 200 );
}

function cspv_cache_test_post( WP_REST_Request $request ) {
    cspv_send_nocache_headers();
    $val = (int) get_transient( 'cspv_cache_test_counter' );
    $val++;
    set_transient( 'cspv_cache_test_counter', $val, 300 ); // expires in 5 min
    return new WP_REST_Response( array(
        'counter'   => $val,
        'timestamp' => time(),
    ), 200 );
}
