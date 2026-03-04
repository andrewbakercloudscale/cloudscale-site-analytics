<?php
/**
 * CloudScale Page Views - Shared Stats Library  v1.0.0
 *
 * Single source of truth for all rolling time window calculations.
 * Every consumer (dashboard widget, statistics page, site health) calls
 * these functions so numbers are always identical across the UI.
 *
 * Functions exposed:
 *   cspv_rolling_24h_views()   → array { current, prior }
 *   cspv_rolling_window_views( $seconds ) → int
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether the V2 schema is active.
 *
 * @return bool
 */
function cspv_use_v2() {
    return get_option( 'cspv_use_v2', '0' ) === '1';
}

/**
 * Return the active views table name.
 * Old schema: wp_cspv_views (one row per view).
 * New schema: wp_cspv_views_v2 (hourly buckets with view_count).
 */
function cspv_views_table() {
    global $wpdb;
    return cspv_use_v2()
        ? $wpdb->prefix . 'cspv_views_v2'
        : $wpdb->prefix . 'cspv_views';
}

/**
 * Return the SQL expression that counts views.
 * Old schema: COUNT(*)
 * New schema: COALESCE(SUM(view_count),0)
 */
function cspv_count_expr() {
    return cspv_use_v2() ? 'COALESCE(SUM(view_count),0)' : 'COUNT(*)';
}

/**
 * Return the active referrer table and count expression.
 * V2: wp_cspv_referrers_v2 with SUM(view_count)
 * V1: wp_cspv_views with COUNT(*)
 *
 * @return array { table, cnt }
 */
function cspv_referrer_source() {
    global $wpdb;
    if ( cspv_use_v2() ) {
        return array(
            'table' => $wpdb->prefix . 'cspv_referrers_v2',
            'cnt'   => 'COALESCE(SUM(view_count),0)',
        );
    }
    return array(
        'table' => $wpdb->prefix . 'cspv_views',
        'cnt'   => 'COUNT(*)',
    );
}

/**
 * Return top referrer domains for a date range.
 *
 * Shared by stats page and dashboard widget so numbers are always identical.
 *
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

    $has_referrer = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$ref_table}` LIKE %s", 'referrer' ) );
    if ( ! $has_referrer ) {
        return array();
    }

    $ref_rows = $wpdb->get_results( $wpdb->prepare(
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

    $has_referrer = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$ref_table}` LIKE %s", 'referrer' ) );
    if ( ! $has_referrer ) {
        return array();
    }

    $ref_rows = $wpdb->get_results( $wpdb->prepare(
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

    $current = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from_str, $to_str
    ) );

    $prior = (int) $wpdb->get_var( $wpdb->prepare(
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

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $from->format( 'Y-m-d H:i:s' ),
        $now->format( 'Y-m-d H:i:s' )
    ) );
}
