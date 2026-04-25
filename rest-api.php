<?php
/**
 * CloudScale Analytics - REST API
 *
 * Registers the POST endpoint that the beacon calls.
 * Multiple cache-bypass headers ensure Cloudflare never caches this route.
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'cspv_register_endpoint' );

// Allow our public tracking endpoints to be called even when another plugin
// uses rest_authentication_errors to restrict the REST API to logged-in users.
// Our endpoints use permission_callback => '__return_true' (public by design)
// but WordPress checks rest_authentication_errors before the permission callback,
// so a WP_Error from another plugin blocks our routes before we get a say.
add_filter( 'rest_authentication_errors', 'cspv_allow_beacon_without_auth', 20 );

/**
 * Clear REST authentication errors for our public tracking routes.
 *
 * Runs at priority 20 so most security plugins (priority 10) have already
 * set their auth error, letting us selectively clear it for our own routes.
 *
 * @since  2.9.187
 * @param  WP_Error|null|true $result  Current auth result.
 * @return WP_Error|null|true
 */
function cspv_allow_beacon_without_auth( $result ) {
    if ( ! is_wp_error( $result ) ) {
        return $result;
    }
    $rest_prefix = rest_get_url_prefix();
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    if ( false !== strpos( $request_uri, '/' . $rest_prefix . '/cloudscale-wordpress-free-analytics/v1/' ) ) {
        return null; // Let __return_true permission_callback decide
    }
    return $result;
}

/**
 * Return the view count for a post, respecting the ignore Jetpack toggle.
 *
 * When the toggle is on, returns tracked-only views from the V2 table.
 * When off, returns the denormalised post meta value which includes any
 * Jetpack-imported lifetime total.
 *
 * @since  1.0.0
 * @param  int $post_id  Post ID.
 * @return int           View count.
 */
function cspv_public_view_count( $post_id ) {
    return (int) get_post_meta( $post_id, CSPV_META_KEY, true );
}

/**
 * Register the record and ping REST API routes.
 *
 * Hooked to rest_api_init.
 *
 * @since  1.0.0
 * @return void
 */
function cspv_register_endpoint() {
    register_rest_route(
        'cloudscale-wordpress-free-analytics/v1',
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
        'cloudscale-wordpress-free-analytics/v1',
        '/ping',
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_ping',
            'permission_callback' => '__return_true',
        )
    );
}

/**
 * REST callback for POST /cloudscale-wordpress-free-analytics/v1/record/{id}.
 *
 * Validates the post, checks the IP throttle, writes the hourly view bucket,
 * referrer, geo, and unique-visitor rows, then increments the denormalised
 * post meta counter.
 *
 * @since  1.0.0
 * @param  WP_REST_Request $request  Incoming REST request.
 * @return WP_REST_Response          JSON response with post_id, views, logged.
 */
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
    $track_types = get_option( 'cspv_track_post_types', array( 'post', 'page' ) );
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
    $referrer   = '';
    $session_id = '';
    $body       = $request->get_json_params();
    if ( ! empty( $body['referrer'] ) && is_string( $body['referrer'] ) ) {
        $referrer = esc_url_raw( substr( $body['referrer'], 0, 2048 ) );
    } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
        $referrer = esc_url_raw( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 2048 ) );
    }
    if ( ! empty( $body['session_id'] ) && is_string( $body['session_id'] ) ) {
        // Strip everything except alphanumeric — the token contains only [a-z0-9]
        $session_id = substr( preg_replace( '/[^a-z0-9]/i', '', $body['session_id'] ), 0, 64 );
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

    // Confirm V2 table exists — result cached for 1 hour to avoid SHOW TABLES on every view.
    $table_exists = get_transient( 'cspv_v2_table_exists' );
    if ( $table_exists === false ) {
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) ) ? '1' : '0';
        set_transient( 'cspv_v2_table_exists', $table_exists, HOUR_IN_SECONDS );
    }
    if ( $table_exists !== '1' ) {
        if ( function_exists( 'cspv_create_table_v2' ) ) {
            cspv_create_table_v2();
        }
        $created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) );
        if ( ! $created ) {
            return new WP_REST_Response( array( 'error' => 'Database table unavailable.' ), 500 );
        }
        set_transient( 'cspv_v2_table_exists', '1', HOUR_IN_SECONDS );
    }

    // Upsert hourly view bucket
    $result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "INSERT INTO `{$v2_table}` (post_id, viewed_at, view_count, source)
         VALUES (%d, %s, 1, 'tracked')
         ON DUPLICATE KEY UPDATE view_count = view_count + 1",
        $post_id, $hour_bucket
    ) );

    if ( $result === false ) {
        return new WP_REST_Response( array(
            'post_id' => $post_id,
            'views'   => cspv_public_view_count( $post_id ),
            'logged'  => false,
            'error'   => 'Insert failed.',
        ), 200 );
    }

    // Upsert referrer bucket (only if referrer is non empty)
    if ( $referrer !== '' ) {
        $ref_table = $wpdb->prefix . 'cspv_referrers_v2';
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
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
            $country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
        }
        // Fall back to DB-IP mmdb lookup (unless cloudflare only)
        if ( $country === '' && $geo_source !== 'cloudflare' ) {
            $raw_ip  = $request->get_header( 'X-Forwarded-For' ) ?: wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via filter_var(FILTER_VALIDATE_IP) below
            $raw_ip  = trim( explode( ',', $raw_ip )[0] );
            $safe_ip = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
            if ( $safe_ip !== '' ) {
                $country = cspv_geo_lookup_dbip( $safe_ip );
            }
        }
        // Tor/anonymous exits map to ZZ (unknown) rather than being dropped.
        if ( $country === 'XX' || $country === 'T1' || $country === '' ) {
            $country = 'ZZ';
        }
        // Write to geo table — every view is recorded, ZZ = unknown/unresolved.
        $geo_table = $wpdb->prefix . 'cspv_geo_v2';
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "INSERT INTO `{$geo_table}` (post_id, viewed_at, country_code, view_count)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $post_id, $hour_bucket, $country
        ) );
    }

    // Track unique visitor (hashed IP, one row per visitor per post per day)
    $visitor_raw = $request->get_header( 'X-Forwarded-For' ) ?: wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via filter_var below
    $visitor_ip  = filter_var( trim( explode( ',', $visitor_raw )[0] ), FILTER_VALIDATE_IP ) ?: '';
    if ( $visitor_ip !== '' && $visitor_ip !== '127.0.0.1' && $visitor_ip !== '::1' ) {
        $visitor_hash  = hash( 'sha256', $visitor_ip . wp_salt() );
        $visitor_table = $wpdb->prefix . 'cspv_visitors_v2';
        $visitor_date  = current_time( 'Y-m-d' );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "INSERT IGNORE INTO `{$visitor_table}` (visitor_hash, post_id, viewed_at)
             VALUES (%s, %d, %s)",
            $visitor_hash, $post_id, $visitor_date
        ) );
    }

    // Session depth tracking (one row per session+post, INSERT IGNORE deduplicates)
    if ( $session_id !== '' ) {
        $sess_table  = $wpdb->prefix . 'cspv_sessions_v2';
        $sess_exists = get_transient( 'cspv_sessions_table_exists' );
        if ( $sess_exists === false ) {
            $sess_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sess_table ) ) ? '1' : '0';
            set_transient( 'cspv_sessions_table_exists', $sess_exists, HOUR_IN_SECONDS );
        }
        if ( $sess_exists === '1' ) {
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name
                "INSERT IGNORE INTO `{$sess_table}` (session_id, post_id, viewed_at) VALUES (%s, %d, %s)",
                $session_id, $post_id, current_time( 'Y-m-d' )
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


/**
 * REST callback for GET /cloudscale-wordpress-free-analytics/v1/ping.
 *
 * Returns plugin version and current server time. Used by the Statistics page
 * to confirm the REST endpoint is reachable and not cached by the CDN.
 *
 * @since  1.1.0
 * @param  WP_REST_Request $request  Incoming REST request (unused).
 * @return WP_REST_Response          JSON response with status, version, time.
 */
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
 * Send cache-bypass headers before returning any REST response.
 *
 * Sets Cache-Control, Pragma, and CDN-specific no-store directives so that
 * Cloudflare, Fastly, CloudFront, Varnish, and other intermediaries never
 * cache this response. Must be called before PHP output buffering flushes.
 *
 * @since  1.1.0
 * @return void
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
// GET /wp-json/cloudscale-wordpress-free-analytics/v1/counts?ids=1,2,3,4
// Returns: { "1": 42, "2": 7, ... }
// -------------------------------------------------------------------------
add_action( 'rest_api_init', 'cspv_register_counts_endpoint' );

/**
 * Register the public counts GET route.
 *
 * Hooked to rest_api_init.
 *
 * @since  2.0.0
 * @return void
 */
function cspv_register_counts_endpoint() {
    register_rest_route(
        'cloudscale-wordpress-free-analytics/v1',
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

/**
 * REST callback for GET /cloudscale-wordpress-free-analytics/v1/counts?ids=1,2,3.
 *
 * Returns a map of post ID → view count for up to 50 IDs per request.
 * Used by the archive/home page beacon to refresh counts on Cloudflare-cached
 * HTML without requiring a full page reload.
 *
 * @since  2.0.0
 * @param  WP_REST_Request $request  Incoming REST request; ids param is sanitised array of int.
 * @return WP_REST_Response          JSON object keyed by string post ID.
 */
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
// GET  /wp-json/cloudscale-wordpress-free-analytics/v1/cache-test
//   Returns the current counter value.
//
// POST /wp-json/cloudscale-wordpress-free-analytics/v1/cache-test
//   Increments the counter and returns the new value.
//
// The counter is stored as a transient that expires in 5 minutes.
// If this endpoint is cached by Cloudflare the counter will never
// change and the test will correctly report the bypass as broken.
// -------------------------------------------------------------------------
add_action( 'rest_api_init', 'cspv_register_cache_test_endpoint' );

/**
 * Register the cache-bypass test GET/POST routes.
 *
 * Hooked to rest_api_init.
 *
 * @since  2.6.4
 * @return void
 */
function cspv_register_cache_test_endpoint() {
    register_rest_route( 'cloudscale-wordpress-free-analytics/v1', '/cache-test', array(
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

/**
 * REST callback for GET /cloudscale-wordpress-free-analytics/v1/cache-test.
 *
 * Returns the current in-memory counter value. If Cloudflare caches this
 * response the counter will never change and the bypass test will correctly
 * report a failure.
 *
 * @since  2.6.4
 * @param  WP_REST_Request $request  Incoming REST request (unused).
 * @return WP_REST_Response          JSON with counter and unix timestamp.
 */
function cspv_cache_test_get( WP_REST_Request $request ) {
    cspv_send_nocache_headers();
    $val = (int) get_transient( 'cspv_cache_test_counter' );
    return new WP_REST_Response( array(
        'counter'   => $val,
        'timestamp' => time(),
    ), 200 );
}

/**
 * REST callback for POST /cloudscale-wordpress-free-analytics/v1/cache-test.
 *
 * Increments the counter transient (5 min TTL) and returns the new value.
 * Requires manage_options capability.
 *
 * @since  2.6.4
 * @param  WP_REST_Request $request  Incoming REST request (unused).
 * @return WP_REST_Response          JSON with updated counter and unix timestamp.
 */
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
