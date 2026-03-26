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
 * @return string Fully-qualified table name, e.g. wp_cspv_views_v2.
 */
function cspv_views_table() {
    global $wpdb;
    return $wpdb->prefix . 'cspv_views_v2';
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
        'table' => $wpdb->prefix . 'cspv_referrers_v2',
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
    $table = $wpdb->prefix . 'cspv_geo_v2';

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
    $table = $wpdb->prefix . 'cspv_geo_v2';

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
    $table = $wpdb->prefix . 'cspv_visitors_v2';

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
 * @since  2.9.161
 * @param  string $from_str  Start datetime or date (Y-m-d H:i:s or Y-m-d).
 * @param  string $to_str    End datetime or date.
 * @return array|null { p50, p95, p99, avg, max, sessions } or null.
 */
function cspv_session_depth_percentiles( $from_str, $to_str ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_sessions_v2';

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
 * Return unique visitor count per post for a date range.
 *
 * @since 1.0.0
 * @param  int    $post_id   Post ID.
 * @param  string $from_str  Start date (Y-m-d or Y-m-d H:i:s).
 * @param  string $to_str    End date (Y-m-d or Y-m-d H:i:s).
 * @return int
 */
function cspv_unique_visitors_for_post( $post_id, $from_str, $to_str ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_visitors_v2';

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
