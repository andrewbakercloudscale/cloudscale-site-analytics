<?php
/**
 * CloudScale Analytics - Shared Stats Library  v1.0.0
 *
 * Single source of truth for all rolling time window calculations.
 * Every consumer (dashboard widget, statistics page, site health) calls
 * these functions so numbers are always identical across the UI.
 *
 * Functions exposed:
 *   cspv_rolling_24h_views()   → array { current, prior }
 *   cspv_rolling_window_views( $seconds ) → int
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether the V2 hourly-bucket schema is active.
 *
 * V2 has been the only schema since v2.9.0. This function always returns
 * true and exists only to avoid breaking any third-party code that may call
 * it. The cspv_use_v2 option is no longer consulted.
 *
 * @since  1.0.0
 * @return bool Always true.
 */
function cspv_use_v2() {
    return true;
}

/**
 * Return the active views table name (always the V2 hourly-bucket table).
 *
 * @since  1.0.0
 * @return string Fully-qualified table name, e.g. wp_cs_analytics_views_v2.
 */
function cspv_views_table() {
    global $wpdb;
    return $wpdb->prefix . 'cs_analytics_views_v2';
}

/**
 * Return the SQL aggregate expression that counts views for the active schema.
 *
 * V2 stores one row per post per hour with a view_count column, so a SUM is
 * required rather than COUNT(*).
 *
 * @since  1.0.0
 * @return string SQL expression, e.g. COALESCE(SUM(view_count),0).
 */
function cspv_count_expr() {
    return 'COALESCE(SUM(view_count),0)';
}

/**
 * Return the referrer table name and aggregate count expression.
 *
 * @since  1.0.0
 * @return array { string $table, string $cnt }
 */
function cspv_referrer_source() {
    global $wpdb;
    return array(
        'table' => $wpdb->prefix . 'cs_analytics_referrers_v2',
        'cnt'   => 'COALESCE(SUM(view_count),0)',
    );
}

/**
 * Return top referrer domains for a date range.
 *
 * Shared by stats page and dashboard widget so numbers are always identical.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $limit     Max domains to return.
 * @return array  Array of { host, views } sorted by views desc.
 */
function cspv_top_referrer_domains( $from_str, $to_str, $limit = 10 ) {
    global $wpdb;
    $src = cspv_referrer_source();
    $ref_table = $src['table'];
    $cnt       = $src['cnt'];

    $has_referrer = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$ref_table}` LIKE %s", 'referrer' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ref_table is a trusted internal table name
    if ( ! $has_referrer ) {
        return array();
    }

    $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cnt is a trusted aggregate expression, $ref_table is an internal table name
        "SELECT referrer, {$cnt} AS view_count FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
         GROUP BY referrer ORDER BY view_count DESC LIMIT 200", $from_str, $to_str ) );

    if ( empty( $ref_rows ) || ! is_array( $ref_rows ) ) {
        return array();
    }

    $host_totals = array();
    $own_host    = wp_parse_url( home_url(), PHP_URL_HOST );
    foreach ( $ref_rows as $r ) {
        $host = wp_parse_url( $r->referrer, PHP_URL_HOST );
        if ( ! $host ) { $host = $r->referrer; }
        if ( $own_host && strcasecmp( $host, $own_host ) === 0 ) { continue; }
        if ( ! isset( $host_totals[ $host ] ) ) { $host_totals[ $host ] = 0; }
        $host_totals[ $host ] += (int) $r->view_count;
    }
    arsort( $host_totals );

    $result = array();
    $i = 0;
    foreach ( $host_totals as $host => $views ) {
        if ( $i >= $limit ) break;
        $result[] = array( 'host' => esc_html( $host ), 'views' => $views );
        $i++;
    }
    return $result;
}

/**
 * Return top referrer pages (full URLs) for a date range.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $limit     Max pages to return.
 * @return array  Array of { url, host, views } sorted by views desc.
 */
function cspv_top_referrer_pages( $from_str, $to_str, $limit = 20 ) {
    global $wpdb;
    $src = cspv_referrer_source();
    $ref_table = $src['table'];
    $cnt       = $src['cnt'];

    $has_referrer = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$ref_table}` LIKE %s", 'referrer' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ref_table is a trusted internal table name
    if ( ! $has_referrer ) {
        return array();
    }

    $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cnt is a trusted aggregate expression, $ref_table is an internal table name
        "SELECT referrer, {$cnt} AS view_count FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
         GROUP BY referrer ORDER BY view_count DESC LIMIT 200", $from_str, $to_str ) );

    if ( empty( $ref_rows ) || ! is_array( $ref_rows ) ) {
        return array();
    }

    $own_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $pages = array();
    foreach ( $ref_rows as $r ) {
        $host = wp_parse_url( $r->referrer, PHP_URL_HOST );
        if ( ! $host ) { $host = $r->referrer; }
        if ( $own_host && strcasecmp( $host, $own_host ) === 0 ) { continue; }
        $pages[] = array(
            'url'   => esc_url( $r->referrer ),
            'host'  => esc_html( $host ),
            'views' => (int) $r->view_count,
        );
    }
    usort( $pages, function( $a, $b ) { return $b['views'] - $a['views']; } );
    return array_slice( $pages, 0, $limit );
}

/**
 * Return top pages (by post) that received traffic from a given referrer hostname.
 *
 * @since 2.9.186
 * @param  string $host      Referrer hostname (e.g. "www.google.com").
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $limit     Max pages to return.
 * @return array  Array of { title, url, views } sorted by views desc.
 */
function cspv_top_pages_by_referrer_host( $host, $from_str, $to_str, $limit = 10 ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];
    $cnt       = $src['cnt'];

    $http_like  = 'http://'  . $wpdb->esc_like( $host ) . '%';
    $https_like = 'https://' . $wpdb->esc_like( $host ) . '%';

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cnt and $ref_table are trusted internal values
        "SELECT post_id, {$cnt} AS views
         FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s
           AND ( referrer LIKE %s OR referrer LIKE %s )
         GROUP BY post_id ORDER BY views DESC LIMIT %d",
        $from_str, $to_str, $http_like, $https_like, $limit ) );

    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return array();
    }

    $result = array();
    foreach ( $rows as $r ) {
        $pid      = absint( $r->post_id );
        $post     = get_post( $pid );
        $result[] = array(
            'title' => $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : 'Post #' . $pid,
            'url'   => ( $post && 'publish' === $post->post_status ) ? get_permalink( $post ) : '',
            'views' => (int) $r->views,
        );
    }
    return $result;
}

/**
 * Return view counts for the rolling 24-hour window and its prior 24-hour period.
 *
 * Uses WordPress timezone. Results are memoised in a static variable so multiple
 * callers within the same request only hit the database once.
 *
 * @since 1.0.0
 * @return array {
 *     @type int $current  Views in the last 24 hours (NOW-24h → NOW).
 *     @type int $prior    Views in the prior 24 hours (NOW-48h → NOW-24h).
 *     @type string $from_str  Start of current window (Y-m-d H:i:s, WP timezone).
 *     @type string $to_str    End of current window (Y-m-d H:i:s, WP timezone).
 * }
 */
function cspv_rolling_24h_views() {
    static $cache = null;
    if ( $cache !== null ) {
        return $cache;
    }

    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        $cache = array( 'current' => 0, 'prior' => 0, 'from_str' => '', 'to_str' => '' );
        return $cache;
    }

    $now    = new DateTime( 'now', wp_timezone() );
    $ago24  = clone $now; $ago24->modify( '-24 hours' );
    $ago48  = clone $now; $ago48->modify( '-48 hours' );

    $to_str      = $now->format( 'Y-m-d H:i:s' );
    $from_str    = $ago24->format( 'Y-m-d H:i:s' );
    $prior_start = $ago48->format( 'Y-m-d H:i:s' );

    $current = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from_str, $to_str
    ) );

    $prior = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $prior_start, $from_str
    ) );

    $cache = array(
        'current'  => $current,
        'prior'    => $prior,
        'from_str' => $from_str,
        'to_str'   => $to_str,
    );

    return $cache;
}

/**
 * Return the view count for any arbitrary rolling window in seconds.
 *
 * @since 1.0.0
 * @param  int $seconds  Window length in seconds (e.g. 7 * DAY_IN_SECONDS).
 * @return int
 */
function cspv_rolling_window_views( $seconds ) {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return 0;
    }

    $now  = new DateTime( 'now', wp_timezone() );
    $from = clone $now;
    $from->modify( '-' . (int) $seconds . ' seconds' );

    return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from->format( 'Y-m-d H:i:s' ),
        $now->format( 'Y-m-d H:i:s' )
    ) );
}

/**
 * Return total views for a date range.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @return int
 */
function cspv_views_for_range( $from_str, $to_str ) {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();
    return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from_str, $to_str ) );
}

/**
 * Return unique post count for a date range.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @return int
 */
function cspv_unique_posts_for_range( $from_str, $to_str ) {
    global $wpdb;
    $table = cspv_views_table();
    return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT COUNT(DISTINCT post_id) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from_str, $to_str ) );
}

/**
 * Return count of distinct posts with at least $min_views views in a date range.
 *
 * Used for the "Hot Pages" summary card: pages that received genuine engagement
 * (more than a single hit) within the selected period.
 *
 * @since 2.9.135
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $min_views Minimum views threshold (default 2).
 * @return int
 */
function cspv_hot_pages_for_range( $from_str, $to_str, $min_views = 2 ) {
    global $wpdb;
    $table      = cspv_views_table();
    $cnt        = cspv_count_expr();
    $post_views = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT post_id, {$cnt} AS views FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY views DESC",
        $from_str, $to_str ) );

    if ( empty( $post_views ) ) { return 0; }

    $total_views = 0;
    foreach ( $post_views as $pv ) { $total_views += (int) $pv->views; }
    if ( $total_views === 0 ) { return 0; }

    $half      = $total_views * 0.5;
    $cumul     = 0;
    $hot_count = 0;
    foreach ( $post_views as $pv ) {
        $cumul += (int) $pv->views;
        $hot_count++;
        if ( $cumul >= $half ) { break; }
    }

    return $hot_count;
}

/**
 * Return top pages for a date range with title, URL, and view count.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $limit     Max pages to return.
 * @return array  Array of { title, url, views }.
 */
function cspv_top_pages( $from_str, $to_str, $limit = 3 ) {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();
    $rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cnt and $table are trusted internal values
        "SELECT post_id, {$cnt} AS views FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY views DESC LIMIT %d",
        $from_str, $to_str, $limit ) );

    $result = array();
    foreach ( (array) $rows as $r ) {
        $pid  = absint( $r->post_id );
        $post = get_post( $pid );
        $result[] = array(
            'title' => $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : 'Post #' . $pid,
            'url'   => ( $post && 'publish' === $post->post_status ) ? get_permalink( $post ) : '',
            'views' => (int) $r->views,
        );
    }
    return $result;
}

/**
 * Return top countries by view count for a date range.
 *
 * @since 1.0.0
 * @param  string $from_str  Start datetime (Y-m-d H:i:s).
 * @param  string $to_str    End datetime (Y-m-d H:i:s).
 * @param  int    $limit     Max countries to return (0 = all).
 * @return array  Array of { country_code, views }.
 */
function cspv_top_countries( $from_str, $to_str, $limit = 20 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_geo_v2';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return array();
    }

    $limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $limit_sql are trusted internal values
        "SELECT country_code, COALESCE(SUM(view_count),0) AS views
         FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s AND country_code <> ''
         GROUP BY country_code ORDER BY views DESC{$limit_sql}",
        $from_str, $to_str ) );

    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return array();
    }

    $result = array();
    foreach ( $rows as $r ) {
        $result[] = array(
            'country_code' => $r->country_code,
            'views'        => (int) $r->views,
        );
    }
    return $result;
}

/**
 * Return top pages for a specific country within a date range.
 *
 * @since 1.0.0
 * @param  string $country_code  Two letter ISO country code.
 * @param  string $from_str      Start datetime (Y-m-d H:i:s).
 * @param  string $to_str        End datetime (Y-m-d H:i:s).
 * @param  int    $limit         Max pages to return.
 * @return array  Array of { title, url, views }.
 */
function cspv_top_pages_by_country( $country_code, $from_str, $to_str, $limit = 10 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_geo_v2';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return array();
    }

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT post_id, COALESCE(SUM(view_count),0) AS views
         FROM `{$table}`
         WHERE country_code = %s AND viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY views DESC LIMIT %d",
        strtoupper( $country_code ), $from_str, $to_str, $limit ) );

    if ( empty( $rows ) || ! is_array( $rows ) ) {
        return array();
    }

    $result = array();
    foreach ( $rows as $r ) {
        $pid  = absint( $r->post_id );
        $post = get_post( $pid );
        $result[] = array(
            'title' => $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : 'Post #' . $pid,
            'url'   => ( $post && 'publish' === $post->post_status ) ? get_permalink( $post ) : '',
            'views' => (int) $r->views,
        );
    }
    return $result;
}

/**
 * Look up country code from IP address using DB-IP Lite mmdb file.
 *
 * Returns a 2 letter ISO country code or empty string if lookup fails.
 * The mmdb file must exist at wp-content/uploads/cspv-geo/dbip-city-lite.mmdb.
 *
 * @since 1.0.0
 * @param  string $ip  IP address to look up.
 * @return string      Two letter country code or ''.
 */
function cspv_geo_lookup_dbip( $ip ) {
    if ( empty( $ip ) ) {
        return '';
    }

    // Strip port and take first IP from X-Forwarded-For chain
    $ip = trim( explode( ',', $ip )[0] );
    $ip = preg_replace( '/:\d+$/', '', $ip );

    // Skip private/localhost IPs
    if ( in_array( $ip, array( '127.0.0.1', '::1', '' ), true ) ) {
        return '';
    }
    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
        return '';
    }

    $upload_dir = wp_upload_dir();
    $mmdb_path  = $upload_dir['basedir'] . '/cspv-geo/dbip-city-lite.mmdb';
    if ( ! file_exists( $mmdb_path ) ) {
        return '';
    }

    // Load the MaxMind DB reader
    $autoload = dirname( __FILE__ ) . '/lib/maxmind-db/autoload.php';
    if ( ! file_exists( $autoload ) ) {
        return '';
    }
    require_once $autoload;

    static $reader = null;
    static $reader_path = '';
    if ( $reader === null || $reader_path !== $mmdb_path ) {
        try {
            $reader      = new \MaxMind\Db\Reader( $mmdb_path );
            $reader_path = $mmdb_path;
        } catch ( \Exception $e ) {
            return '';
        }
    }

    try {
        $record = $reader->get( $ip );
        if ( is_array( $record ) && isset( $record['country']['iso_code'] ) ) {
            return strtoupper( substr( $record['country']['iso_code'], 0, 2 ) );
        }
    } catch ( \Exception $e ) {
        // Invalid IP or DB error — silently return empty
    }

    return '';
}

/**
 * Return unique visitor count for a date range.
 *
 * Counts distinct visitor hashes (SHA256 of IP) from the visitors table.
 *
 * @since 1.0.0
 * @param  string $from_str  Start date (Y-m-d).
 * @param  string $to_str    End date (Y-m-d).
 * @return int
 */
function cspv_unique_visitors_for_range( $from_str, $to_str ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_visitors_v2';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return 0;
    }

    // Extract just the date portion in case full datetime is passed
    $from_date = substr( $from_str, 0, 10 );
    $to_date   = substr( $to_str, 0, 10 );

    return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT COUNT(DISTINCT visitor_hash) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from_date, $to_date
    ) );
}

/**
 * Return pages-per-session percentiles (P50, P95, P99) for a date range.
 *
 * Queries the sessions table for distinct post counts per session_id, sorts
 * the distribution in PHP, and returns the requested percentiles. Also
 * returns avg, max, and total session count.
 *
 * Returns null when the sessions table does not exist (pre-upgrade).
 * Returns an array with all zeros when no sessions are recorded yet.
 *
 * @since  2.9.167
 * @param  string $from_str  Start datetime or date (Y-m-d H:i:s or Y-m-d).
 * @param  string $to_str    End datetime or date.
 * @return array|null { p50, p95, p99, avg, max, sessions } or null.
 */
function cspv_session_depth_percentiles( $from_str, $to_str ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_sessions_v2';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return null; // Table not yet created — caller should hide the UI
    }

    $from_date = substr( $from_str, 0, 10 );
    $to_date   = substr( $to_str,   0, 10 );

    // One count per session: how many distinct pages did this session view?
    $counts = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name
        "SELECT COUNT(post_id) AS pages FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY session_id",
        $from_date, $to_date
    ) );

    if ( empty( $counts ) ) {
        return array( 'p50' => 0, 'p95' => 0, 'p99' => 0, 'avg' => 0.0, 'max' => 0, 'sessions' => 0 );
    }

    $counts = array_map( 'intval', $counts );
    sort( $counts, SORT_NUMERIC );
    $n = count( $counts );

    $pct = function( $p ) use ( $counts, $n ) {
        return $counts[ (int) floor( $p * ( $n - 1 ) ) ];
    };

    return array(
        'p50'      => $pct( 0.50 ),
        'p95'      => $pct( 0.95 ),
        'p99'      => $pct( 0.99 ),
        'avg'      => round( array_sum( $counts ) / $n, 1 ),
        'max'      => max( $counts ),
        'sessions' => $n,
    );
}

/**
 * Return top posts with trend data for the Insights tab.
 *
 * @since 1.0.0
 * @param  string $from_str       Current period start (Y-m-d H:i:s).
 * @param  string $to_str         Current period end   (Y-m-d H:i:s).
 * @param  string $prev_from_str  Previous period start.
 * @param  string $prev_to_str    Previous period end.
 * @param  int    $limit          Max posts to evaluate.
 * @return array  { top, trending_up, trending_down }
 */
function cspv_insights_top_pages( $from_str, $to_str, $prev_from_str, $prev_to_str, $limit = 20 ) {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT post_id, {$cnt} AS views FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY views DESC LIMIT %d",
        $from_str, $to_str, $limit ) );

    if ( empty( $rows ) ) {
        return array( 'top' => array(), 'trending_up' => array(), 'trending_down' => array() );
    }

    $post_ids = array_map( function( $r ) { return (int) $r->post_id; }, $rows );
    $ids_str  = implode( ',', $post_ids );

    $prev_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression, $ids_str contains only integers
        "SELECT post_id, {$cnt} AS views FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s AND post_id IN ({$ids_str})
         GROUP BY post_id",
        $prev_from_str, $prev_to_str ) );

    $prev_map = array();
    foreach ( (array) $prev_rows as $r ) {
        $prev_map[ (int) $r->post_id ] = (int) $r->views;
    }

    $result = array();
    foreach ( $rows as $r ) {
        $pid        = absint( $r->post_id );
        $post       = get_post( $pid );
        $views      = (int) $r->views;
        $prev_views = isset( $prev_map[ $pid ] ) ? $prev_map[ $pid ] : 0;
        $pct_change = $prev_views > 0 ? (int) round( ( ( $views - $prev_views ) / $prev_views ) * 100 ) : null;

        $thumb_url = '';
        if ( $post ) {
            $thumb_id = get_post_thumbnail_id( $pid );
            if ( $thumb_id ) {
                $img = wp_get_attachment_image_src( $thumb_id, array( 48, 48 ) );
                if ( $img ) { $thumb_url = $img[0]; }
            }
        }

        $result[] = array(
            'title'      => $post ? html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ) : 'Post #' . $pid,
            'url'        => ( $post && 'publish' === $post->post_status ) ? get_permalink( $post ) : '',
            'thumbnail'  => $thumb_url,
            'views'      => $views,
            'prev_views' => $prev_views,
            'pct_change' => $pct_change,
        );
    }

    $trending_up   = array_values( array_filter( $result, function( $i ) { return $i['pct_change'] !== null && $i['pct_change'] > 0; } ) );
    $trending_down = array_values( array_filter( $result, function( $i ) { return $i['pct_change'] !== null && $i['pct_change'] < 0; } ) );

    usort( $trending_up,   function( $a, $b ) { return $b['pct_change'] - $a['pct_change']; } );
    usort( $trending_down, function( $a, $b ) { return $a['pct_change'] - $b['pct_change']; } );

    return array(
        'top'          => $result,
        'trending_up'  => $trending_up,
        'trending_down' => $trending_down,
    );
}

/**
 * Return unique visitor count per post for a date range.
 *
 * @since 1.0.0
 * @param  int    $post_id   Post ID.
 * @param  string $from_str  Start date (Y-m-d or Y-m-d H:i:s).
 * @param  string $to_str    End date (Y-m-d or Y-m-d H:i:s).
 * @return int
 */
// ── Insights Dashboard helpers ───────────────────────────────────────────────

/**
 * Return canonical display label for a referrer hostname.
 */
function cspv_insights_label( $host ) {
    static $map = null;
    if ( null === $map ) {
        $map = array(
            'google'     => 'Google',
            'bing'       => 'Bing',
            'yahoo'      => 'Yahoo',
            'duckduckgo' => 'DuckDuckGo',
            'ecosia'     => 'Ecosia',
            'yandex'     => 'Yandex',
            'baidu'      => 'Baidu',
            'linkedin'   => 'LinkedIn',
            'facebook'   => 'Facebook',
            'instagram'  => 'Instagram',
            'twitter'    => 'Twitter/X',
            'x.com'      => 'Twitter/X',
            't.co'       => 'Twitter/X',
            'reddit'     => 'Reddit',
            'pinterest'  => 'Pinterest',
            'youtube'    => 'YouTube',
        );
    }
    $h = strtolower( $host );
    foreach ( $map as $needle => $label ) {
        if ( strpos( $h, $needle ) !== false ) { return $label; }
    }
    return $host;
}

/**
 * Build labeled referrer totals from raw referrer rows.
 * Returns array( label => views ) with Self optionally included.
 *
 * @param  array  $ref_rows     Objects with ->referrer and ->views.
 * @param  string $own_host     Site hostname.
 * @param  bool   $include_self Include own-domain traffic.
 * @return array
 */
function cspv_insights_label_refs( $ref_rows, $own_host, $include_self = true ) {
    $labeled = array();
    foreach ( $ref_rows as $r ) {
        $host    = (string) wp_parse_url( $r->referrer, PHP_URL_HOST );
        if ( ! $host ) { $host = $r->referrer; }
        $is_self = $own_host && ( strcasecmp( $host, $own_host ) === 0 || stripos( $host, $own_host ) !== false );
        if ( ! $include_self && $is_self ) { continue; }
        $label   = $is_self ? 'Self' : cspv_insights_label( $host );
        if ( ! isset( $labeled[ $label ] ) ) { $labeled[ $label ] = 0; }
        $labeled[ $label ] += (int) $r->views;
    }
    arsort( $labeled );
    return $labeled;
}

/**
 * Return KPI summary for the Insights dashboard.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  string $own_host
 * @return array
 */
function cspv_insights_kpi( $from_str, $to_str, $own_host ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];

    $total_views     = (int) cspv_views_for_range( $from_str, $to_str );
    $unique_visitors = (int) cspv_unique_visitors_for_range( $from_str, $to_str );

    $countries  = cspv_top_countries( $from_str, $to_str, 1 );
    $top_country = ! empty( $countries ) ? $countries[0] : null;

    $has_ref = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) );
    $top_ref = null;
    $top_ref_no_self = null;
    if ( $has_ref ) {
        $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT referrer, COALESCE(SUM(view_count),0) AS views FROM `{$ref_table}`
             WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
             GROUP BY referrer ORDER BY views DESC LIMIT 200",
            $from_str, $to_str ) );
        if ( ! empty( $ref_rows ) ) {
            $all  = cspv_insights_label_refs( $ref_rows, $own_host, true );
            $ext  = cspv_insights_label_refs( $ref_rows, $own_host, false );
            if ( ! empty( $all ) ) {
                $label = key( $all );
                $top_ref = array( 'label' => $label, 'views' => current( $all ), 'is_self' => ( $label === 'Self' ) );
            }
            if ( ! empty( $ext ) ) {
                $label = key( $ext );
                $top_ref_no_self = array( 'label' => $label, 'views' => current( $ext ), 'is_self' => false );
            }
        }
    }

    return array(
        'total_views'          => $total_views,
        'unique_visitors'      => $unique_visitors,
        'top_country'          => $top_country,
        'top_referrer'         => $top_ref,
        'top_referrer_no_self' => $top_ref_no_self,
    );
}

/**
 * Return traffic sources for the Insights doughnut chart.
 * Includes Direct (untracked referrer), Self, and labeled externals.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  string $own_host
 * @return array  Array of { label, views, is_self }
 */
function cspv_insights_traffic_sources( $from_str, $to_str, $own_host ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];

    $total = (int) cspv_views_for_range( $from_str, $to_str );
    $has_ref = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) );
    if ( ! $has_ref ) {
        return array( array( 'label' => 'Direct', 'views' => $total, 'is_self' => false ) );
    }

    $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT referrer, COALESCE(SUM(view_count),0) AS views FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
         GROUP BY referrer ORDER BY views DESC LIMIT 500",
        $from_str, $to_str ) );

    $labeled   = cspv_insights_label_refs( $ref_rows, $own_host, true );
    $ref_total = array_sum( $labeled );
    $direct    = max( 0, $total - $ref_total );

    $result = array();
    if ( $direct > 0 ) {
        $result[] = array( 'label' => 'Direct', 'views' => $direct, 'is_self' => false );
    }
    foreach ( $labeled as $label => $views ) {
        $result[] = array( 'label' => $label, 'views' => $views, 'is_self' => ( $label === 'Self' ) );
    }
    usort( $result, function( $a, $b ) { return $b['views'] - $a['views']; } );
    return $result;
}

/**
 * Return referrer growth time-series for the Insights line chart.
 * Returns { dates, series: [{ label, data, is_self }] }.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  string $own_host
 * @param  int    $period   Days in the window (controls bucketing).
 * @return array
 */
function cspv_insights_referrer_growth( $from_str, $to_str, $own_host, $period ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];

    $has_ref = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) );
    if ( ! $has_ref ) { return array( 'dates' => array(), 'series' => array() ); }

    $date_expr = $period > 30
        ? "DATE(DATE_SUB(viewed_at, INTERVAL WEEKDAY(viewed_at) DAY))"
        : "DATE(viewed_at)";

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT {$date_expr} AS bucket, referrer, COALESCE(SUM(view_count),0) AS views
         FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
         GROUP BY bucket, referrer ORDER BY bucket ASC, views DESC LIMIT 5000",
        $from_str, $to_str ) );

    if ( empty( $rows ) ) { return array( 'dates' => array(), 'series' => array() ); }

    $buckets      = array();
    $label_totals = array();
    foreach ( $rows as $r ) {
        $host    = (string) wp_parse_url( $r->referrer, PHP_URL_HOST );
        if ( ! $host ) { $host = $r->referrer; }
        $is_self = $own_host && ( strcasecmp( $host, $own_host ) === 0 || stripos( $host, $own_host ) !== false );
        $label   = $is_self ? 'Self' : cspv_insights_label( $host );
        $b       = $r->bucket;
        if ( ! isset( $buckets[ $b ] ) ) { $buckets[ $b ] = array(); }
        if ( ! isset( $buckets[ $b ][ $label ] ) ) { $buckets[ $b ][ $label ] = 0; }
        $buckets[ $b ][ $label ] += (int) $r->views;
        if ( ! isset( $label_totals[ $label ] ) ) { $label_totals[ $label ] = 0; }
        $label_totals[ $label ] += (int) $r->views;
    }
    arsort( $label_totals );
    $top_labels = array_keys( array_slice( $label_totals, 0, 8, true ) );

    $dates = array_keys( $buckets );
    sort( $dates );

    $series = array();
    foreach ( $top_labels as $label ) {
        $data = array();
        foreach ( $dates as $d ) {
            $data[] = isset( $buckets[ $d ][ $label ] ) ? (int) $buckets[ $d ][ $label ] : 0;
        }
        $series[] = array( 'label' => $label, 'data' => $data, 'is_self' => ( $label === 'Self' ) );
    }
    return array( 'dates' => $dates, 'series' => $series );
}

/**
 * Return top posts for the Insights bar chart.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  int    $limit
 * @return array  Array of { title, url, views }
 */
function cspv_insights_top_posts_data( $from_str, $to_str, $limit = 15 ) {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT post_id, {$cnt} AS views FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY views DESC LIMIT %d",
        $from_str, $to_str, $limit ) );

    $result = array();
    foreach ( $rows as $r ) {
        $pid  = absint( $r->post_id );
        $post = get_post( $pid );
        if ( ! $post ) { continue; }
        $result[] = array(
            'title' => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
            'url'   => get_permalink( $pid ),
            'views' => (int) $r->views,
        );
    }
    return $result;
}

/**
 * Return top posts × referrer matrix for the Insights table.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  string $own_host
 * @param  int    $max_posts
 * @param  int    $max_refs
 * @return array  { headers: string[], rows: [{ title, url, counts: int[] }] }
 */
function cspv_insights_posts_by_referrer( $from_str, $to_str, $own_host, $max_posts = 15, $max_refs = 8 ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];

    $has_ref     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) );
    $has_post_id = $has_ref ? $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$ref_table}` LIKE %s", 'post_id' ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if ( ! $has_post_id ) { return array( 'headers' => array(), 'rows' => array() ); }

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT post_id, referrer, COALESCE(SUM(view_count),0) AS views
         FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> '' AND post_id > 0
         GROUP BY post_id, referrer ORDER BY views DESC LIMIT 3000",
        $from_str, $to_str ) );

    if ( empty( $rows ) ) { return array( 'headers' => array(), 'rows' => array() ); }

    $post_ref    = array();
    $ref_totals  = array();
    $post_totals = array();

    foreach ( $rows as $r ) {
        $pid     = absint( $r->post_id );
        $host    = (string) wp_parse_url( $r->referrer, PHP_URL_HOST );
        if ( ! $host ) { $host = $r->referrer; }
        $is_self = $own_host && ( strcasecmp( $host, $own_host ) === 0 || stripos( $host, $own_host ) !== false );
        $label   = $is_self ? 'Self' : cspv_insights_label( $host );

        if ( ! isset( $post_ref[ $pid ] ) ) { $post_ref[ $pid ] = array(); }
        if ( ! isset( $post_ref[ $pid ][ $label ] ) ) { $post_ref[ $pid ][ $label ] = 0; }
        $post_ref[ $pid ][ $label ]   += (int) $r->views;
        if ( ! isset( $ref_totals[ $label ] ) ) { $ref_totals[ $label ] = 0; }
        $ref_totals[ $label ]         += (int) $r->views;
        if ( ! isset( $post_totals[ $pid ] ) ) { $post_totals[ $pid ] = 0; }
        $post_totals[ $pid ]          += (int) $r->views;
    }

    arsort( $ref_totals );
    $top_refs = array_keys( array_slice( $ref_totals, 0, $max_refs, true ) );
    arsort( $post_totals );
    $top_pids = array_keys( array_slice( $post_totals, 0, $max_posts, true ) );

    $result_rows = array();
    foreach ( $top_pids as $pid ) {
        $post = get_post( $pid );
        if ( ! $post ) { continue; }
        $counts = array();
        foreach ( $top_refs as $ref ) {
            $counts[] = isset( $post_ref[ $pid ][ $ref ] ) ? (int) $post_ref[ $pid ][ $ref ] : 0;
        }
        $result_rows[] = array(
            'title'  => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
            'url'    => get_permalink( $pid ),
            'counts' => $counts,
        );
    }
    return array( 'headers' => $top_refs, 'rows' => $result_rows );
}

/**
 * Return top referrer domains with labels (including Self) for the bar chart.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  string $own_host
 * @param  int    $limit
 * @return array  Array of { label, views, is_self }
 */
function cspv_insights_referrer_domains_full( $from_str, $to_str, $own_host, $limit = 15 ) {
    global $wpdb;
    $src       = cspv_referrer_source();
    $ref_table = $src['table'];

    $has_ref = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) );
    if ( ! $has_ref ) { return array(); }

    $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT referrer, COALESCE(SUM(view_count),0) AS views FROM `{$ref_table}`
         WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
         GROUP BY referrer ORDER BY views DESC LIMIT 300",
        $from_str, $to_str ) );

    $labeled = cspv_insights_label_refs( $ref_rows, $own_host, true );
    $result  = array();
    $i       = 0;
    foreach ( $labeled as $label => $views ) {
        if ( $i >= $limit ) { break; }
        $result[] = array( 'label' => $label, 'views' => $views, 'is_self' => ( $label === 'Self' ) );
        $i++;
    }
    return $result;
}

/**
 * Return country traffic over time for the Insights line chart.
 *
 * @param  string $from_str
 * @param  string $to_str
 * @param  int    $period
 * @param  int    $max_countries
 * @return array  { dates, series: [{ label, data }] }
 */
function cspv_insights_countries_over_time( $from_str, $to_str, $period, $max_countries = 5 ) {
    global $wpdb;
    $geo_table = $wpdb->prefix . 'cs_analytics_geo_v2';

    $has_geo = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $geo_table ) );
    if ( ! $has_geo ) { return array( 'dates' => array(), 'series' => array() ); }

    $date_expr = $period > 30
        ? "DATE(DATE_SUB(viewed_at, INTERVAL WEEKDAY(viewed_at) DAY))"
        : "DATE(viewed_at)";

    $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        "SELECT {$date_expr} AS bucket, country_code, COALESCE(SUM(view_count),0) AS views
         FROM `{$geo_table}`
         WHERE viewed_at BETWEEN %s AND %s AND country_code IS NOT NULL AND country_code <> ''
         GROUP BY bucket, country_code ORDER BY bucket ASC LIMIT 3000",
        $from_str, $to_str ) );

    if ( empty( $rows ) ) { return array( 'dates' => array(), 'series' => array() ); }

    $country_totals = array();
    $buckets        = array();
    foreach ( $rows as $r ) {
        $cc = $r->country_code;
        if ( ! isset( $country_totals[ $cc ] ) ) { $country_totals[ $cc ] = 0; }
        $country_totals[ $cc ] += (int) $r->views;
        if ( ! isset( $buckets[ $r->bucket ] ) ) { $buckets[ $r->bucket ] = array(); }
        if ( ! isset( $buckets[ $r->bucket ][ $cc ] ) ) { $buckets[ $r->bucket ][ $cc ] = 0; }
        $buckets[ $r->bucket ][ $cc ] += (int) $r->views;
    }
    arsort( $country_totals );
    $top_cc = array_keys( array_slice( $country_totals, 0, $max_countries, true ) );

    $dates = array_keys( $buckets );
    sort( $dates );

    $series = array();
    foreach ( $top_cc as $cc ) {
        $data = array();
        foreach ( $dates as $d ) {
            $data[] = isset( $buckets[ $d ][ $cc ] ) ? (int) $buckets[ $d ][ $cc ] : 0;
        }
        $series[] = array( 'label' => $cc, 'data' => $data );
    }
    return array( 'dates' => $dates, 'series' => $series );
}

function cspv_unique_visitors_for_post( $post_id, $from_str, $to_str ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_visitors_v2';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        return 0;
    }

    $from_date = substr( $from_str, 0, 10 );
    $to_date   = substr( $to_str, 0, 10 );

    return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT COUNT(DISTINCT visitor_hash) FROM `{$table}` WHERE post_id = %d AND viewed_at BETWEEN %s AND %s",
        $post_id, $from_date, $to_date
    ) );
}
