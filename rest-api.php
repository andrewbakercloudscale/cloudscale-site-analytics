<?php
/**
 * CloudScale Analytics - REST API
 *
 * Registers the POST endpoint that the beacon calls.
 * Multiple cache-bypass headers ensure Cloudflare never caches this route.
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.PHP.DevelopmentFunctions.error_log_error_log -- analytics plugin: all interpolated vars are internal table/column names; direct queries on custom time-series tables are required

add_action( 'rest_api_init', 'cspv_register_endpoint' );

// ---------------------------------------------------------------------------
// Cloudflare IP validation, prevents CF header spoofing on non-CF traffic
// ---------------------------------------------------------------------------

/**
 * Return the current Cloudflare egress IP ranges (CIDR notation).
 *
 * Uses a bundled static list of Cloudflare's published egress ranges.
 * No external request is made — the list ships with the plugin.
 *
 * @since 2.9.318
 * @return string[]
 */
function cspv_get_cf_ip_ranges() {
    return array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14',   '172.64.0.0/13',   '131.0.72.0/22',
        '2400:cb00::/32',  '2606:4700::/32',   '2803:f800::/32',  '2405:b500::/32',
        '2405:8100::/32',  '2a06:98c0::/29',   '2c0f:f248::/32',
    );
}

/**
 * Check whether an IP address falls within any Cloudflare egress CIDR range.
 *
 * @since 2.9.318
 * @param  string $ip  Raw IP address (IPv4 or IPv6).
 * @return bool
 */
function cspv_is_cloudflare_ip( $ip ) {
    static $cache = array();
    if ( ! $ip ) {
        return false;
    }
    if ( isset( $cache[ $ip ] ) ) {
        return $cache[ $ip ];
    }
    $is_ipv6 = strpos( $ip, ':' ) !== false;
    foreach ( cspv_get_cf_ip_ranges() as $cidr ) {
        list( $subnet, $bits ) = explode( '/', $cidr );
        $bits = (int) $bits;
        if ( $is_ipv6 ) {
            if ( strpos( $subnet, ':' ) === false ) { continue; }
            $ip_bin     = inet_pton( $ip );
            $subnet_bin = inet_pton( $subnet );
            if ( $ip_bin === false || $subnet_bin === false ) { continue; }
            $full_bytes = intdiv( $bits, 8 );
            $rem_bits   = $bits % 8;
            $mask       = str_repeat( "\xff", $full_bytes );
            if ( $rem_bits ) { $mask .= chr( 0xff << ( 8 - $rem_bits ) ); }
            $mask = str_pad( $mask, 16, "\x00" );
            if ( ( $ip_bin & $mask ) === ( $subnet_bin & $mask ) ) {
                $cache[ $ip ] = true;
                return true;
            }
        } else {
            if ( strpos( $subnet, ':' ) !== false ) { continue; }
            $ip_long     = ip2long( $ip );
            $subnet_long = ip2long( $subnet );
            if ( $ip_long === false || $subnet_long === false ) { continue; }
            $mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) );
            if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
                $cache[ $ip ] = true;
                return true;
            }
        }
    }
    $cache[ $ip ] = false;
    return false;
}

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
    if ( false !== strpos( $request_uri, '/' . $rest_prefix . '/cloudscale-site-analytics/v1/' ) ) {
        return null; // Let __return_true permission_callback decide
    }
    return $result;
}

/**
 * Return the view count for a post.
 *
 * Returns the denormalised post meta value (_cspv_view_count).
 *
 * @since  1.0.0
 * @param  int $post_id  Post ID.
 * @return int           View count.
 */
function cspv_public_view_count( $post_id ) {
    return (int) get_post_meta( $post_id, CSPV_META_KEY, true );
}

/**
 * Whether the beacon authenticity gate (valid wp_rest nonce) is enforced.
 *
 * Defaults to enabled. Doubles as an instant kill switch: if it ever rejects
 * legitimate views, disable it WITHOUT a redeploy via:
 *   wp option update cspv_beacon_auth 0
 * or in code via the 'cspv_beacon_auth_required' filter.
 *
 * @since 2.9.318
 * @return bool
 */
function cspv_beacon_auth_required() {
    $enabled = '0' !== (string) get_option( 'cspv_beacon_auth', '1' );
    return (bool) apply_filters( 'cspv_beacon_auth_required', $enabled );
}

/**
 * Resolve the real client IP for the current request (Cloudflare-aware).
 *
 * Only trusts CF-Connecting-IP when REMOTE_ADDR is a confirmed Cloudflare
 * egress IP (forged headers are otherwise trivial); falls back to
 * X-Forwarded-For / X-Real-IP / REMOTE_ADDR. Returns '' when no valid IP can
 * be determined. Single source of truth for IP resolution across endpoints.
 *
 * @since 2.9.368
 * @return string  Validated IP address, or '' if none.
 */
function cspv_get_client_ip() {
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated via filter_var; REMOTE_ADDR is server-set, not user-supplied
    $raw_ip      = '';
    if ( cspv_is_cloudflare_ip( $remote_addr ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $raw_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $raw_ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0] );
    } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
        $raw_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) );
    } else {
        $raw_ip = $remote_addr;
    }
    return ( $raw_ip && filter_var( $raw_ip, FILTER_VALIDATE_IP ) ) ? $raw_ip : '';
}

/**
 * Per-IP rate limit for the public read endpoints (counts, ping).
 *
 * Coarse fixed-window counter, generous by default (120 requests / 60s) so
 * legitimate listing-page traffic is never affected — only trivial flood loops
 * from a single IP are blocked. Both bounds are filterable; a limit of 0
 * disables the check.
 *
 * Stored in the object cache and gated on a *persistent* object cache: without
 * Redis/Memcached an app-layer counter would add a DB write per request and
 * still not persist meaningfully, so we skip it (real volumetric defense for a
 * public beacon belongs at the CDN/WAF edge). No external request is made.
 *
 * @since 2.9.368
 * @return bool  True when the current IP has exceeded the limit this window.
 */
function cspv_read_rate_limited() {
    $limit  = (int) apply_filters( 'cspv_read_rate_limit', 120 );
    $window = (int) apply_filters( 'cspv_read_rate_window', 60 );
    if ( $limit <= 0 || $window <= 0 || ! wp_using_ext_object_cache() ) {
        return false;
    }
    $raw_ip = cspv_get_client_ip();
    if ( '' === $raw_ip ) {
        return false; // Can't identify the caller — don't block.
    }
    $key   = 'cspv_rl_' . hash( 'sha256', $raw_ip . wp_salt() );
    $count = (int) wp_cache_get( $key, 'cspv_ratelimit' );
    if ( $count >= $limit ) {
        return true;
    }
    wp_cache_set( $key, $count + 1, 'cspv_ratelimit', $window );
    return false;
}

/**
 * ---------------------------------------------------------------------------
 * WordPress.org reviewer note — public ("__return_true") endpoints
 * ---------------------------------------------------------------------------
 * Frontend view tracking relies on a JavaScript beacon (beacon.js) that fires
 * from every visitor's browser, including anonymous (logged-out) visitors and
 * on Cloudflare-cached pages where PHP never runs. The routes below are
 * therefore intentionally public — a capability or login check would defeat
 * their purpose. They are hardened, not unguarded:
 *
 *   POST /v1/record/{id}  Records one page view.
 *                         - {id} validated (numeric > 0) and absint-sanitised;
 *                         - target must be an existing published (publish) post;
 *                         - a valid wp_rest nonce is required by default
 *                           (cspv_beacon_auth — see cspv_beacon_auth_required());
 *                         - per-IP throttle + session dedup prevent flooding;
 *                         - the only visitor data stored is a hashed IP
 *                           (SHA-256 + wp_salt, never raw) plus the post ID.
 *   GET  /v1/counts       Read-only. Returns view counts for published posts
 *                         only; non-published IDs are silently omitted.
 *                         Input capped at 50 absint IDs per call.
 *   GET  /v1/ping         Read-only reachability probe; returns no site data.
 *   GET  /v1/cache-test   Read-only cache-bypass probe; its POST counterpart is
 *                         gated behind current_user_can( 'manage_options' ).
 *
 * No public endpoint exposes private content or accepts unsanitised input.
 * Each '__return_true' below is deliberate and scoped to one read or to the
 * guarded record write described above.
 * ---------------------------------------------------------------------------
 */

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
        'cloudscale-site-analytics/v1',
        '/record/(?P<id>\d+)',
        array(
            'methods'             => 'POST',
            'callback'            => 'cspv_record_view',
            'permission_callback' => '__return_true', // Public by design: anonymous visitor beacon. Hardened — see reviewer note above (validated ID + wp_rest nonce + per-IP throttle).
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

    // Diagnostics endpoint, used by the stats page to confirm beacon is reachable
    register_rest_route(
        'cloudscale-site-analytics/v1',
        '/ping',
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_ping',
            'permission_callback' => '__return_true', // Public by design: read-only reachability probe, returns no site data (see reviewer note above).
        )
    );
}

/**
 * REST callback for POST /cloudscale-site-analytics/v1/record/{id}.
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

    // Reject oversized request bodies. The beacon only sends a tiny JSON
    // payload (referrer + session_id); anything larger is rejected before any
    // work is done. Filterable; 0 disables the cap.
    $max_bytes = (int) apply_filters( 'cspv_max_body_bytes', 4096 );
    if ( $max_bytes > 0 && strlen( (string) $request->get_body() ) > $max_bytes ) {
        return new WP_REST_Response( array( 'error' => 'Request body too large.' ), 413 );
    }

    // Emergency kill switch, reject all recording
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
    if ( 'publish' !== $post->post_status ) {
        return new WP_REST_Response( array( 'error' => 'Post is not published.' ), 404 );
    }

    // Check post type filter, only record views for tracked types
    $track_types = get_option( 'cspv_track_post_types', array( 'post', 'page' ) );
    if ( ! empty( $track_types ) && ! in_array( $post->post_type, $track_types, true ) ) {
        return new WP_REST_Response( array(
            'post_id' => $post_id,
            'views'   => cspv_public_view_count( $post_id ),
            'logged'  => false,
        ), 200 );
    }

    // --- Extract real IP (Cloudflare-aware) -------------------------
    // $is_cf is also reused below to decide whether CF-IPCountry can be
    // trusted for geolocation; cspv_get_client_ip() centralises IP resolution.
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- server-set value, validated via cspv_is_cloudflare_ip() CIDR check
    $is_cf       = cspv_is_cloudflare_ip( $remote_addr );
    $raw_ip      = cspv_get_client_ip();
    $ip_hash     = $raw_ip ? hash( 'sha256', $raw_ip . wp_salt() ) : '';

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
        // Strip everything except alphanumeric, the token contains only [a-z0-9]
        $session_id = substr( preg_replace( '/[^a-z0-9]/i', '', $body['session_id'] ), 0, 64 );
    }

    // --- Beacon authenticity gate ----------------------------------
    // A genuine view comes from beacon.js, which fires with an X-WP-Nonce
    // ('wp_rest') minted into the page. Scrapers/crawlers that POST straight
    // to this public endpoint don't carry a valid nonce, so we reject them
    // silently (200, logged:false), the caller gets no signal.
    //
    // Cache-safe: HTML is edge/nginx cached for 2h (max-age=7200), well inside
    // the ~24h wp_rest nonce lifetime, so cached pages still carry a valid
    // nonce. session_id is intentionally NOT a gate: getSessionId() returns ''
    // when sessionStorage/crypto is unavailable (private mode, old browsers),
    // so requiring it would drop real visitors. It is also client-generated and
    // trivially forged, so it carries no authenticity value.
    //
    // Instant kill switch (no redeploy):  wp option update cspv_beacon_auth 0
    if ( cspv_beacon_auth_required() ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response( array(
                'post_id' => $post_id,
                'views'   => cspv_public_view_count( $post_id ),
                'logged'  => false,
            ), 200 );
        }
    }

    // --- IP throttle check -----------------------------------------
    if ( cspv_is_throttled( $ip_hash ) ) {
        // Silent accept, attacker gets no signal
        return new WP_REST_Response( array(
            'post_id' => $post_id,
            'views'   => cspv_public_view_count( $post_id ),
            'logged'  => false,
        ), 200 );
    }

    // --- Write to database -----------------------------------------
    global $wpdb;
    $v2_table    = esc_sql( $wpdb->prefix . 'cs_analytics_views_v2' );
    $hour_bucket = current_time( 'Y-m-d H' ) . ':00:00';

    // Confirm V2 table exists, result cached for 1 hour to avoid SHOW TABLES on every view.
    $table_exists = get_transient( 'cspv_v2_table_exists' );
    if ( $table_exists === false ) {
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) ) ? '1' : '0'; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
        set_transient( 'cspv_v2_table_exists', $table_exists, HOUR_IN_SECONDS );
    }
    if ( $table_exists !== '1' ) {
        if ( function_exists( 'cspv_create_table_v2' ) ) {
            cspv_create_table_v2();
        }
        $created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v2_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
        if ( ! $created ) {
            return new WP_REST_Response( array( 'error' => 'Database table unavailable.' ), 500 );
        }
        set_transient( 'cspv_v2_table_exists', '1', HOUR_IN_SECONDS );
    }

    // Upsert hourly view bucket
    $result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
        "INSERT INTO `{$v2_table}` (post_id, viewed_at, view_count)
         VALUES (%d, %s, 1)
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
        $ref_table = esc_sql( $wpdb->prefix . 'cs_analytics_referrers_v2' );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
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
        // Only trust CF-IPCountry when the connection is confirmed Cloudflare
        if ( $is_cf && $geo_source !== 'dbip' && isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
        }
        // Fall back to DB-IP mmdb lookup (unless cloudflare only), reuse already-resolved $raw_ip
        if ( $country === '' && $geo_source !== 'cloudflare' ) {
            $safe_ip = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
            if ( $safe_ip !== '' ) {
                $country = cspv_geo_lookup_dbip( $safe_ip );
            }
        }
        // Tor/anonymous exits map to ZZ (unknown) rather than being dropped.
        if ( $country === 'XX' || $country === 'T1' || $country === '' ) {
            $country = 'ZZ';
        }
        // Write to geo table, every view is recorded, ZZ = unknown/unresolved.
        $geo_table = esc_sql( $wpdb->prefix . 'cs_analytics_geo_v2' );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
            "INSERT INTO `{$geo_table}` (post_id, viewed_at, country_code, view_count)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $post_id, $hour_bucket, $country
        ) );
    }

    // Track unique visitor (hashed IP, one row per visitor per post per day), reuse already-resolved $raw_ip
    $visitor_ip = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
    if ( $visitor_ip !== '' && $visitor_ip !== '127.0.0.1' && $visitor_ip !== '::1' ) {
        $visitor_hash  = hash( 'sha256', $visitor_ip . wp_salt() );
        $visitor_table = esc_sql( $wpdb->prefix . 'cs_analytics_visitors_v2' );
        $visitor_date  = current_time( 'Y-m-d' );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
            "INSERT IGNORE INTO `{$visitor_table}` (visitor_hash, post_id, viewed_at)
             VALUES (%s, %d, %s)",
            $visitor_hash, $post_id, $visitor_date
        ) );
    }

    // Session depth tracking (one row per session+post, INSERT IGNORE deduplicates)
    if ( $session_id !== '' ) {
        $sess_table  = esc_sql( $wpdb->prefix . 'cs_analytics_sessions_v2' );
        $sess_exists = get_transient( 'cspv_sessions_table_exists' );
        if ( $sess_exists !== '1' ) {
            $found       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sess_table ) ) ? '1' : '0'; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
            $sess_exists = $found;
            // Only cache a positive result, a negative could be stale after table creation
            if ( $found === '1' ) {
                set_transient( 'cspv_sessions_table_exists', '1', HOUR_IN_SECONDS );
            }
        }
        if ( $sess_exists === '1' ) {
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name
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
 * REST callback for GET /cloudscale-site-analytics/v1/ping.
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

    if ( cspv_read_rate_limited() ) {
        return new WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
    }

    return new WP_REST_Response(
        array(
            'status'  => 'ok',
            'version' => current_user_can( 'manage_options' ) ? CSPV_VERSION : null,
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
    // Standard HTTP cache-control, tells every intermediate cache to bypass
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
// GET /wp-json/cloudscale-site-analytics/v1/counts?ids=1,2,3,4
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
        'cloudscale-site-analytics/v1',
        '/counts',
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_get_counts',
            'permission_callback' => '__return_true', // Public by design: read-only, returns counts already shown publicly on the frontend (max 50 absint IDs — see reviewer note above).
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
 * REST callback for GET /cloudscale-site-analytics/v1/counts?ids=1,2,3.
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

    if ( cspv_read_rate_limited() ) {
        return new WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
    }

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
    $ids = array_slice( array_unique( $ids ), 0, 50 );

    // Short-lived cache of the count map, keyed by the (order-independent) ID
    // set. Bounds DB/meta lookups under repeated hammering from cached listing
    // pages; a 60s TTL keeps numbers fresh enough for archive/home views.
    sort( $ids );
    $cache_key = 'cspv_counts_' . md5( implode( ',', $ids ) );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return new WP_REST_Response( $cached, 200 );
    }

    $counts = array();
    foreach ( $ids as $id ) {
        if ( 'publish' === get_post_status( $id ) ) {
            $counts[ (string) $id ] = cspv_public_view_count( $id );
        }
    }

    set_transient( $cache_key, $counts, (int) apply_filters( 'cspv_counts_cache_ttl', 60 ) );

    return new WP_REST_Response( $counts, 200 );
}

// -------------------------------------------------------------------------
// Cache bypass test endpoint
//
// GET  /wp-json/cloudscale-site-analytics/v1/cache-test
//   Returns the current counter value.
//
// POST /wp-json/cloudscale-site-analytics/v1/cache-test
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
    register_rest_route( 'cloudscale-site-analytics/v1', '/cache-test', array(
        array(
            'methods'             => 'GET',
            'callback'            => 'cspv_cache_test_get',
            'permission_callback' => '__return_true', // Public by design: read-only cache-bypass probe, no sensitive data; the POST counterpart below is gated to manage_options (see reviewer note above).
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
 * REST callback for GET /cloudscale-site-analytics/v1/cache-test.
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
 * REST callback for POST /cloudscale-site-analytics/v1/cache-test.
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
