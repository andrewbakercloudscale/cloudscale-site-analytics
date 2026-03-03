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
 * Return the active views table name.
 * Always uses the V2 hourly bucket schema.
 */
function cspv_views_table() {
    global $wpdb;
    return $wpdb->prefix . 'cspv_views_v2';
}

/**
 * Return the SQL expression that counts views.
 * V2 schema uses SUM(view_count) over hourly buckets.
 */
function cspv_count_expr() {
    return 'COALESCE(SUM(view_count),0)';
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
