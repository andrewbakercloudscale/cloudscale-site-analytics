<?php
/**
 * CloudScale Analytics - AJAX Handlers
 *
 * @package CloudScale_Free_Analytics
 * @since   2.9.308
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_cspv_chart_data', 'cspv_ajax_chart_data' );
add_action( 'wp_ajax_cspv_post_history', 'cspv_ajax_post_history' );
add_action( 'wp_ajax_cspv_post_search', 'cspv_ajax_post_search' );
add_action( 'wp_ajax_cspv_resync_meta', 'cspv_ajax_resync_meta_from_stats' );
add_action( 'wp_ajax_cspv_country_drill',   'cspv_ajax_country_drill' );
add_action( 'wp_ajax_cspv_referrer_drill', 'cspv_ajax_referrer_drill' );
add_action( 'wp_ajax_cspv_download_dbip', 'cspv_ajax_download_dbip' );
add_action( 'wp_ajax_cspv_purge_visitors',           'cspv_ajax_purge_visitors' );
add_action( 'wp_ajax_cspv_save_display_settings',   'cspv_ajax_save_display_settings' );
add_action( 'wp_ajax_cspv_insights',               'cspv_ajax_insights' );
add_action( 'wp_ajax_cspv_insights_dashboard',    'cspv_ajax_insights_dashboard' );
add_action( 'wp_ajax_cspv_post_geo_map',          'cspv_ajax_post_geo_map' );
add_action( 'wp_ajax_cspv_version',              'cspv_ajax_version' );
add_action( 'wp_ajax_nopriv_cspv_version',       'cspv_ajax_version' );

// ---------------------------------------------------------------------------
// AJAX, chart data
// ---------------------------------------------------------------------------
/**
 * AJAX handler: return chart data for the stats dashboard.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_chart_data() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    try {
        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
        $date_to   = isset( $_POST['date_to'] )   ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )   : '';

        if ( empty( $date_from ) || empty( $date_to ) ) {
            wp_send_json_error( array( 'message' => 'date_from and date_to are required.' ), 400 );
            return;
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            wp_send_json_error( array( 'message' => 'Dates must be in YYYY-MM-DD format.' ), 400 );
            return;
        }

        $from = date_create_from_format( 'Y-m-d', $date_from );
        $to   = date_create_from_format( 'Y-m-d', $date_to );

        if ( $from === false || $to === false ) {
            wp_send_json_error( array( 'message' => 'Could not parse date values.' ), 400 );
            return;
        }
        if ( $from > $to ) {
            // Auto swap instead of rejecting
            $tmp  = $from;
            $from = $to;
            $to   = $tmp;
        }

        $diff_days = (int) date_diff( $from, $to )->days;
        if ( $diff_days > 730 ) {
            wp_send_json_error( array( 'message' => 'Date range cannot exceed 2 years.' ), 400 );
            return;
        }

        global $wpdb;
        $table = esc_sql( cspv_views_table() );
        $cnt   = esc_sql( cspv_count_expr() );

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
        if ( ! $table_exists ) {
            wp_send_json_success( array(
                'chart' => array(), 'label_fmt' => 'day', 'total_views' => 0,
                'unique_posts' => 0, 'prev_total' => 0, 'prev_posts' => 0, 'unique_visitors' => 0, 'prev_visitors' => 0, 'lifetime_visitors' => 0, 'diff_days' => $diff_days,
                'top_posts' => array(), 'referrers' => array(),
                'notice' => 'Database table not found. Deactivate and reactivate the plugin.',
            ) );
            return;
        }

        $rolling24h = ! empty( $_POST['rolling24h'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rolling24h'] ) );
        $rolling12h = ! empty( $_POST['rolling12h'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rolling12h'] ) );

        if ( $rolling12h ) {
            // Rolling 12h: from NOW-12h to NOW, bucketed by hour
            $now_dt    = new DateTime( 'now', wp_timezone() );
            $from_12   = clone $now_dt;
            $from_12->modify( '-12 hours' );
            $from_str  = $from_12->format( 'Y-m-d H:i:s' );
            $to_str    = $now_dt->format( 'Y-m-d H:i:s' );
        } elseif ( $rolling24h && $diff_days === 0 ) {
            // Rolling 24h: from NOW-24h to NOW, bucketed by hour
            $now_dt     = new DateTime( 'now', wp_timezone() );
            $from_24    = clone $now_dt;
            $from_24->modify( '-24 hours' );
            $from_str   = $from_24->format( 'Y-m-d H:i:s' );
            $to_str     = $now_dt->format( 'Y-m-d H:i:s' );
        } else {
            $from_str = $from->format( 'Y-m-d' ) . ' 00:00:00';
            $to_str   = $to->format( 'Y-m-d' )   . ' 23:59:59';
        }

        // Grouping: single day = hourly, <=90d = daily, >90d = weekly
        if ( $rolling12h ) {
            // ── 12 Hours: build 12 hourly slots ──
            $label_fmt = 'hour';
            $raw = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(viewed_at,'%%Y-%%m-%%d %%H') AS hr_key, {$cnt} AS views FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s GROUP BY hr_key ORDER BY hr_key ASC", $from_str, $to_str ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
            $by_hour = array();
            foreach ( (array) $raw as $r ) { $by_hour[ $r->hr_key ] = (int) $r->views; }
            $chart_rows = array();
            $cur = clone $from_12;
            for ( $i = 0; $i < 12; $i++ ) {
                $key         = $cur->format( 'Y-m-d H' );
                $obj         = new stdClass();
                $obj->period = $cur->format( 'H:00' );
                $obj->views  = isset( $by_hour[ $key ] ) ? $by_hour[ $key ] : 0;
                $chart_rows[] = $obj;
                $cur->modify( '+1 hour' );
            }
        } elseif ( $diff_days === 0 ) {
            // ── Hourly: build 24 slots from rolling window or calendar day ──
            $label_fmt = 'hour';

            if ( $rolling24h ) {
                // Rolling: bucket by hour across the 24h window
                $raw = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(viewed_at,'%%Y-%%m-%%d %%H') AS hr_key, {$cnt} AS views FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s GROUP BY hr_key ORDER BY hr_key ASC", $from_str, $to_str ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
                $by_hour = array();
                foreach ( (array) $raw as $r ) { $by_hour[ $r->hr_key ] = (int) $r->views; }

                $chart_rows = array();
                $cur = clone $from_24;
                for ( $i = 0; $i < 24; $i++ ) {
                    $key         = $cur->format( 'Y-m-d H' );
                    $obj         = new stdClass();
                    $obj->period = $cur->format( 'H:00' );
                    $obj->views  = isset( $by_hour[ $key ] ) ? $by_hour[ $key ] : 0;
                    $chart_rows[] = $obj;
                    $cur->modify( '+1 hour' );
                }
            } else {
                $raw = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(viewed_at,'%%H') AS hr, {$cnt} AS views FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s GROUP BY hr", $from_str, $to_str ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
                $by_hour = array();
                foreach ( (array) $raw as $r ) { $by_hour[ (int) $r->hr ] = (int) $r->views; }
                $chart_rows = array();
                for ( $h = 0; $h < 24; $h++ ) {
                    $obj         = new stdClass();
                    $obj->period = sprintf( '%02d:00', $h );
                    $obj->views  = isset( $by_hour[ $h ] ) ? $by_hour[ $h ] : 0;
                    $chart_rows[] = $obj;
                }
            }
        } elseif ( $diff_days <= 90 ) {
            // ── Daily: build every date in range, fill from DB ────────────
            $label_fmt = 'day';
            $raw = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(viewed_at) AS ymd, {$cnt} AS views FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s GROUP BY ymd", $from_str, $to_str ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
            $by_date = array();
            foreach ( (array) $raw as $r ) { $by_date[ $r->ymd ] = (int) $r->views; }
            $chart_rows = array();
            $cur = clone $from;
            while ( $cur <= $to ) {
                $ymd        = $cur->format( 'Y-m-d' );
                $obj         = new stdClass();
                $obj->period = date_i18n( 'j M', $cur->getTimestamp() );
                $obj->views  = isset( $by_date[ $ymd ] ) ? $by_date[ $ymd ] : 0;
                $chart_rows[] = $obj;
                $cur->modify( '+1 day' );
            }
        } else {
            // ── Weekly: group by ISO week, fill gaps ──────────────────────
            $label_fmt = 'week';
            $raw = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(viewed_at,'%%Y-%%u') AS wk, MIN(DATE(viewed_at)) AS wk_start, {$cnt} AS views FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s GROUP BY wk ORDER BY wk ASC", $from_str, $to_str ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
            $by_week = array();
            foreach ( (array) $raw as $r ) { $by_week[ $r->wk ] = array( 'views' => (int) $r->views, 'start' => $r->wk_start ); }
            // Walk week-by-week across the range
            $chart_rows = array();
            $cur = clone $from;
            // Align to Monday of first week
            $dow = (int) $cur->format( 'N' );
            if ( $dow > 1 ) { $cur->modify( '-' . ( $dow - 1 ) . ' days' ); }
            while ( $cur <= $to ) {
                $wk_key = $cur->format( 'Y' ) . '-' . $cur->format( 'W' );
                $obj         = new stdClass();
                $obj->period = date_i18n( 'j M', $cur->getTimestamp() );
                $obj->views  = isset( $by_week[ $wk_key ] ) ? $by_week[ $wk_key ]['views'] : 0;
                $chart_rows[] = $obj;
                $cur->modify( '+7 days' );
            }
        }

        $total_views  = cspv_views_for_range( $from_str, $to_str );
        $unique_posts = cspv_unique_posts_for_range( $from_str, $to_str );

        $top_posts = cspv_top_pages( $from_str, $to_str, 10 );

        if ( $rolling12h ) {
            // Rolling 12h prior: same 12h window shifted back 24h (matches dashboard widget)
            $prev_12h_from = ( new DateTime( 'now', wp_timezone() ) )->modify( '-36 hours' )->format( 'Y-m-d H:i:s' );
            $prev_12h_to   = ( new DateTime( 'now', wp_timezone() ) )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
            $prev_from_str = $prev_12h_from;
            $prev_to_str   = $prev_12h_to;
            $prev_total    = cspv_views_for_range( $prev_12h_from, $prev_12h_to );
            $prev_posts    = cspv_unique_posts_for_range( $prev_12h_from, $prev_12h_to );
        } elseif ( $rolling24h && $diff_days === 0 ) {
            // Rolling 24h: use shared stats library so total matches banner + site health
            $r24         = cspv_rolling_24h_views();
            $total_views = $r24['current'];  // override the BETWEEN query above
            $prev_total  = $r24['prior'];
            $prev_48h    = ( new DateTime( 'now', wp_timezone() ) )->modify( '-48 hours' )->format( 'Y-m-d H:i:s' );
            $prev_24h_ts = ( new DateTime( 'now', wp_timezone() ) )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
            $prev_posts  = cspv_unique_posts_for_range( $prev_48h, $prev_24h_ts );
        } else {
            $period_days = max( 1, $diff_days );
            $prev_from   = clone $from; $prev_from->modify( '-' . $period_days . ' days' );
            $prev_to     = clone $to;   $prev_to->modify(   '-' . $period_days . ' days' );
            $prev_from_str = $prev_from->format( 'Y-m-d' ) . ' 00:00:00';
            $prev_to_str   = $prev_to->format( 'Y-m-d' )   . ' 23:59:59';
            $prev_total  = cspv_views_for_range( $prev_from_str, $prev_to_str );
            $prev_posts  = cspv_unique_posts_for_range( $prev_from_str, $prev_to_str );
        }

        $hot_pages      = cspv_hot_pages_for_range( $from_str, $to_str );
        $prev_hot_pages = isset( $prev_from_str )
            ? cspv_hot_pages_for_range( $prev_from_str, $prev_to_str )
            : cspv_hot_pages_for_range( $prev_48h, $prev_24h_ts );

        $referrers      = cspv_top_referrer_domains( $from_str, $to_str, 10 );
        $referrer_pages = cspv_top_referrer_pages( $from_str, $to_str, 20 );
        $countries      = cspv_top_countries( $from_str, $to_str, 20 );
        $session_depth = cspv_session_depth_percentiles( $from_str, $to_str );
        if ( $rolling12h ) {
            // Sessions table is DATE-only; compare today vs yesterday
            $prev_day = ( new DateTime( 'now', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
            $prev_session_depth = cspv_session_depth_percentiles( $prev_day, $prev_day );
        } elseif ( isset( $prev_from_str ) ) {
            $prev_session_depth = cspv_session_depth_percentiles( $prev_from_str, $prev_to_str );
        } elseif ( isset( $prev_48h ) ) {
            $prev_session_depth = cspv_session_depth_percentiles( $prev_48h, $prev_24h_ts );
        } else {
            $prev_session_depth = null;
        }

        // ── Unique visitors ──────────────────────────────────────────────
        $unique_visitors      = cspv_unique_visitors_for_range( $from_str, $to_str );
        $prev_visitors        = 0;
        if ( $rolling24h && $diff_days === 0 ) {
            $prev_48h_dt = ( new DateTime( 'now', wp_timezone() ) )->modify( '-48 hours' )->format( 'Y-m-d' );
            $prev_24h_dt = ( new DateTime( 'now', wp_timezone() ) )->modify( '-24 hours' )->format( 'Y-m-d' );
            $prev_visitors = cspv_unique_visitors_for_range( $prev_48h_dt, $prev_24h_dt );
        } elseif ( isset( $prev_from_str ) ) {
            $prev_visitors = cspv_unique_visitors_for_range( $prev_from_str, $prev_to_str );
        }
        $lifetime_visitors = cspv_unique_visitors_for_range( '2000-01-01', '2099-12-31' );

        // ── Lifetime totals from beacon log ─────────────────────────────
        $lifetime_total = (int) $wpdb->get_var( "SELECT SUM(view_count) FROM `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name
        );
        $lifetime_top   = cspv_top_pages( '2000-01-01 00:00:00', '2099-12-31 23:59:59', 10 );

        wp_send_json_success( array(
            'chart'          => array_values( $chart_rows ),
            'label_fmt'      => $label_fmt,
            'total_views'    => $total_views,
            'unique_posts'   => $unique_posts,
            'prev_total'     => $prev_total,
            'prev_posts'     => $prev_posts,
            'diff_days'      => $diff_days,
            'top_posts'      => $top_posts,
            'referrers'       => $referrers,
            'referrer_pages'  => $referrer_pages,
            'query_from'      => $from_str,
            'query_to'        => $to_str,
            'countries'       => $countries,
            'geo_source'      => get_option( 'cspv_geo_source', 'auto' ),
            'geo_source_actual' => ( function() {
                $s = get_option( 'cspv_geo_source', 'auto' );
                if ( 'cloudflare' === $s ) { return 'cloudflare'; }
                if ( 'dbip'       === $s ) { return 'dbip'; }
                if ( 'disabled'   === $s ) { return 'disabled'; }
                // auto: CF wins if header present, else DB-IP if mmdb exists, else none
                if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) { return 'cloudflare'; }
                $mmdb = wp_upload_dir()['basedir'] . '/cspv-geo/dbip-city-lite.mmdb';
                return file_exists( $mmdb ) ? 'dbip' : 'none';
            } )(),
            'session_depth'      => $session_depth,
            'prev_session_depth' => $prev_session_depth,
            'hot_pages'          => $hot_pages,
            'prev_hot_pages'     => $prev_hot_pages,
            'unique_visitors'    => $unique_visitors,
            'prev_visitors'      => $prev_visitors,
            'lifetime_visitors'  => $lifetime_visitors,
            'lifetime_total' => $lifetime_total,
            'lifetime_top'   => $lifetime_top,
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

// ---------------------------------------------------------------------------
// Post search AJAX (for post history tab)
// ---------------------------------------------------------------------------
/**
 * AJAX handler: search for posts by title for the post-history lookup.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_post_search() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 ); return; }

    try {
        global $wpdb;
        $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
        if ( strlen( $q ) < 2 ) { wp_send_json_success( array() ); }

        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 10,
            'orderby'        => 'relevance',
        );
        $posts = get_posts( $args );
        // Get log counts for each result
        $search_log_counts = array();
        if ( ! empty( $posts ) ) {
            $s_ids_str = implode( ',', array_map( function( $p ) { return (int) $p->ID; }, $posts ) );
            $s_table = esc_sql( cspv_views_table() );
            $s_cnt   = esc_sql( cspv_count_expr() );
            $s_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $s_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
            if ( $s_table_exists ) {
                $s_rows = $wpdb->get_results( "SELECT post_id, {$s_cnt} AS cnt FROM `{$s_table}` WHERE post_id IN ({$s_ids_str}) GROUP BY post_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
                foreach ( (array) $s_rows as $sr ) {
                    $search_log_counts[ (int) $sr->post_id ] = (int) $sr->cnt;
                }
            }
        }
        $results = array();
        foreach ( $posts as $p ) {
            $views   = (int) get_post_meta( $p->ID, CSPV_META_KEY, true );
            $log_cnt = isset( $search_log_counts[ $p->ID ] ) ? $search_log_counts[ $p->ID ] : 0;
            $results[] = array(
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'type'     => $p->post_type,
                'date'     => get_the_date( 'j M Y', $p ),
                'views'    => $views,
                'pageviews' => $log_cnt,
                'url'      => get_permalink( $p->ID ),
            );
        }
        wp_send_json_success( $results );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

// ---------------------------------------------------------------------------
// Post history AJAX
// ---------------------------------------------------------------------------
/**
 * AJAX handler: return view history for a single post.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_post_history() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 ); return; }

    try {
        global $wpdb;
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Invalid post ID' ) ); return; }

        $table = esc_sql( cspv_views_table() );
        $cnt   = esc_sql( cspv_count_expr() );
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table

        $meta_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
        $log_count  = 0;
        $first_log  = null;
        $last_log   = null;
        $daily      = array();
        $hourly     = array();
        // WordPress timezone timestamps for queries (viewed_at is stored in WP timezone)
        $wp_now    = current_time( 'mysql' );
        $wp_180d   = wp_date( 'Y-m-d H:i:s', time() - ( 180 * 86400 ) );
        $wp_48h    = wp_date( 'Y-m-d H:i:s', time() - 172800 );

        if ( $table_exists ) {
            $log_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT {$cnt} FROM `{$table}` WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression

            $first_log = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression

            $last_log = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression

            // Daily views for last 180 days
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(viewed_at) AS day, {$cnt} AS views FROM `{$table}` WHERE post_id = %d AND viewed_at >= %s GROUP BY day ORDER BY day ASC", $post_id, $wp_180d ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
            foreach ( (array) $rows as $r ) {
                $daily[] = array( 'day' => $r->day, 'views' => (int) $r->views );
            }

            // Hourly views for last 48 hours
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(viewed_at, '%%Y-%%m-%%d %%H:00') AS hour, {$cnt} AS views FROM `{$table}` WHERE post_id = %d AND viewed_at >= %s GROUP BY hour ORDER BY hour ASC", $post_id, $wp_48h ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
            foreach ( (array) $rows as $r ) {
                $hourly[] = array( 'hour' => $r->hour, 'views' => (int) $r->views );
            }

            // 180 day daily timeline with top referrer per day
            $timeline_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(viewed_at) AS day, {$cnt} AS views FROM `{$table}` WHERE post_id = %d AND viewed_at >= %s GROUP BY day ORDER BY day DESC", $post_id, $wp_180d ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name

            // Top referrer per day (uses shared referrer source)
            $ref_src  = cspv_referrer_source();
            $ref_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(viewed_at) AS day, referrer, {$ref_src['cnt']} AS cnt FROM `{$ref_src['table']}` WHERE post_id = %d AND viewed_at >= %s AND referrer != '' GROUP BY day, referrer ORDER BY day DESC, cnt DESC", $post_id, $wp_180d ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name

            // Split referrers into self (own domain) and top external per day
            $site_host   = preg_replace( '/^www\./', '', wp_parse_url( home_url(), PHP_URL_HOST ) );
            $self_hits   = array();   // day => count
            $top_ext     = array();   // day => ['ref' => url, 'cnt' => n]
            foreach ( (array) $ref_rows as $rr ) {
                $parsed   = wp_parse_url( $rr->referrer );
                $ref_host = isset( $parsed['host'] ) ? preg_replace( '/^www\./', '', $parsed['host'] ) : '';
                if ( $ref_host === $site_host ) {
                    $self_hits[ $rr->day ] = ( isset( $self_hits[ $rr->day ] ) ? $self_hits[ $rr->day ] : 0 ) + (int) $rr->cnt;
                } elseif ( ! isset( $top_ext[ $rr->day ] ) ) {
                    $top_ext[ $rr->day ] = array( 'ref' => $rr->referrer, 'cnt' => (int) $rr->cnt );
                }
            }

            $timeline = array();
            foreach ( (array) $timeline_rows as $tr ) {
                $ext_info  = isset( $top_ext[ $tr->day ] ) ? $top_ext[ $tr->day ] : null;
                $timeline[] = array(
                    'day'       => $tr->day,
                    'views'     => (int) $tr->views,
                    'top_ref'   => $ext_info ? $ext_info['ref'] : null,
                    'ref_hits'  => $ext_info ? $ext_info['cnt'] : 0,
                    'self_hits' => isset( $self_hits[ $tr->day ] ) ? $self_hits[ $tr->day ] : 0,
                );
            }
        }

        $post = get_post( $post_id );

        wp_send_json_success( array(
            'post_id'       => $post_id,
            'title'         => $post ? $post->post_title : '(deleted)',
            'url'           => $post ? get_permalink( $post_id ) : '',
            'published'     => $post ? get_the_date( 'j M Y', $post ) : '',
            'published_ymd' => $post ? get_the_date( 'Y-m-d', $post ) : '',
            'meta_count'    => $meta_count,
            'log_count'     => $log_count,
            'first_log'     => $first_log,
            'last_log'      => $last_log,
            'daily'         => $daily,
            'hourly'        => $hourly,
            'timeline'      => isset( $timeline ) ? $timeline : array(),
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

// ---------------------------------------------------------------------------
// Resync meta from stats page
// ---------------------------------------------------------------------------
/**
 * AJAX handler: re-sync post meta view counts from the beacon log.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_resync_meta_from_stats() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 ); return; }

    try {
        global $wpdb;
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Invalid post ID' ) ); return; }

        $table     = esc_sql( cspv_views_table() );
        $cnt       = esc_sql( cspv_count_expr() );
        $log_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT {$cnt} FROM `{$table}` WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
        $old_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
        $new_count = max( $old_count, $log_count );
        update_post_meta( $post_id, CSPV_META_KEY, $new_count );

        wp_send_json_success( array(
            'post_id'   => $post_id,
            'old_count' => $old_count,
            'new_count' => $new_count,
            'log_rows'  => $log_count,
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}


/**
 * AJAX handler: return per-post breakdown for a selected country.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_country_drill() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
        return;
    }

    try {
        $country    = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
        $exact_from = sanitize_text_field( wp_unslash( $_POST['exact_from'] ?? '' ) );
        $exact_to   = sanitize_text_field( wp_unslash( $_POST['exact_to']   ?? '' ) );
        $from       = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
        $to         = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );

        if ( strlen( $country ) !== 2 ) {
            wp_send_json_error( 'Invalid parameters' ); return;
        }

        // Prefer the exact datetime window the chart was rendered with. This
        // keeps the drill aligned with the country bars in rolling-24h mode,
        // where the bars span NOW-24h..NOW rather than a calendar day. Mirrors
        // cspv_ajax_referrer_drill().
        $dt_re = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if ( $exact_from && $exact_to &&
             preg_match( $dt_re, $exact_from ) && preg_match( $dt_re, $exact_to ) ) {
            $from_str = $exact_from;
            $to_str   = $exact_to;
        } elseif ( $from && $to &&
                   preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) &&
                   preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $from_str = $from . ' 00:00:00';
            $to_str   = $to . ' 23:59:59';
        } else {
            wp_send_json_error( 'Invalid date format.' ); return;
        }

        $pages = cspv_top_pages_by_country( $country, $from_str, $to_str, 10 );
        wp_send_json_success( array( 'pages' => $pages ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * AJAX handler: return per-post breakdown for a selected referrer hostname.
 *
 * @since 2.9.186
 * @return void
 */
function cspv_ajax_referrer_drill() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
        return;
    }

    try {
        $host        = sanitize_text_field( wp_unslash( $_POST['host']       ?? '' ) );
        $exact_from  = sanitize_text_field( wp_unslash( $_POST['exact_from'] ?? '' ) );
        $exact_to    = sanitize_text_field( wp_unslash( $_POST['exact_to']   ?? '' ) );
        $from        = sanitize_text_field( wp_unslash( $_POST['from']       ?? '' ) );
        $to          = sanitize_text_field( wp_unslash( $_POST['to']         ?? '' ) );
        $rolling24h  = ! empty( $_POST['rolling24h'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rolling24h'] ) );

        if ( ! $host ) {
            wp_send_json_error( 'Invalid parameters' ); return;
        }

        // Prefer the exact datetime window sent by the client (computed when chart loaded).
        // This avoids rolling-24h boundary drift when the drill request arrives later.
        $dt_re = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if ( $exact_from && $exact_to &&
             preg_match( $dt_re, $exact_from ) && preg_match( $dt_re, $exact_to ) ) {
            $from_str = $exact_from;
            $to_str   = $exact_to;
        } elseif ( $from && $to &&
                   preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) &&
                   preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            if ( $rolling24h && $from === $to ) {
                $tz       = wp_timezone();
                $now      = new DateTime( 'now', $tz );
                $to_str   = $now->format( 'Y-m-d H:i:s' );
                $from_str = ( clone $now )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
            } else {
                $from_str = $from . ' 00:00:00';
                $to_str   = $to   . ' 23:59:59';
            }
        } else {
            wp_send_json_error( 'Invalid date parameters.' );
            return;
        }

        $pages = cspv_top_pages_by_referrer_host( $host, $from_str, $to_str, 25 );
        wp_send_json_success( array( 'pages' => $pages ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * Download and install the DB-IP Lite mmdb file for the current month.
 *
 * Returns an associative array on success or WP_Error on failure.
 * Called by the AJAX handler (manual button) and the daily cron.
 *
 * @since  2.9.187
 * @return array|WP_Error  { size, updated, ip_version, node_count } on success.
 */
function cspv_download_dbip_file() {
    $upload    = wp_upload_dir();
    $mmdb_dir  = $upload['basedir'] . '/cspv-geo';
    $mmdb_path = $mmdb_dir . '/dbip-city-lite.mmdb';
    $gz_path   = $mmdb_dir . '/dbip-city-lite.mmdb.gz';

    if ( ! file_exists( $mmdb_dir ) ) {
        wp_mkdir_p( $mmdb_dir );
    }

    $year  = gmdate( 'Y' );
    $month = gmdate( 'm' );
    $url   = "https://download.db-ip.com/free/dbip-city-lite-{$year}-{$month}.mmdb.gz";

    $response = wp_remote_get( $url, array(
        'timeout'  => 120,
        'stream'   => true,
        'filename' => $gz_path,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'download_failed', 'Download failed: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        if ( file_exists( $gz_path ) ) { wp_delete_file( $gz_path ); }
        return new WP_Error( 'http_error', "Download failed with HTTP {$code}. The file may not be available yet for this month." );
    }

    $gz = gzopen( $gz_path, 'rb' );
    if ( ! $gz ) {
        if ( file_exists( $gz_path ) ) { wp_delete_file( $gz_path ); }
        return new WP_Error( 'gz_open_failed', 'Failed to open gzipped file.' );
    }
    $out = fopen( $mmdb_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP Filesystem has no gzopen; streaming gzip decompression requires native fopen/fwrite/fclose.
    if ( ! $out ) {
        gzclose( $gz );
        if ( file_exists( $gz_path ) ) { wp_delete_file( $gz_path ); }
        return new WP_Error( 'write_failed', 'Failed to write mmdb file.' );
    }
    while ( ! gzeof( $gz ) ) {
        fwrite( $out, gzread( $gz, 8192 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- paired with fopen above; no WP Filesystem equivalent for gzip streaming.
    }
    gzclose( $gz );
    fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the fopen/gzopen above; no WP Filesystem equivalent for this gzip streaming pattern
    if ( file_exists( $gz_path ) ) { wp_delete_file( $gz_path ); }

    $size = filesize( $mmdb_path );
    if ( $size < 1000000 ) {
        if ( file_exists( $mmdb_path ) ) { wp_delete_file( $mmdb_path ); }
        return new WP_Error( 'file_too_small', 'Downloaded file is too small (' . size_format( $size ) . '). May be corrupt.' );
    }

    try {
        require_once plugin_dir_path( __FILE__ ) . 'lib/maxmind-db/autoload.php';
        $reader = new \MaxMind\Db\Reader( $mmdb_path );
        $meta   = $reader->metadata();
        $reader->close();
    } catch ( \Exception $e ) {
        if ( file_exists( $mmdb_path ) ) { wp_delete_file( $mmdb_path ); }
        return new WP_Error( 'db_invalid', 'Database file invalid: ' . $e->getMessage() );
    }

    $now = current_time( 'mysql' );
    update_option( 'cspv_dbip_last_updated', $now );
    // Record the year-month of the installed file so the cron can skip same-month re-downloads
    update_option( 'cspv_dbip_installed_ym', gmdate( 'Y-m' ) );

    return array(
        'size'       => size_format( $size ),
        'updated'    => $now,
        'ip_version' => $meta->ipVersion,
        'node_count' => $meta->nodeCount,
    );
}

/**
 * AJAX handler: download and install the DB-IP Lite geolocation database.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_download_dbip() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
        return;
    }

    try {
        $result = cspv_download_dbip_file();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( $result );
        }
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

// ---------------------------------------------------------------------------
// WP-Cron: auto-update DB-IP Lite once per month
// ---------------------------------------------------------------------------
add_action( 'cspv_dbip_auto_update', function() {
    try {
        cspv_dbip_auto_update_run();
    } catch ( \Throwable $e ) {
        error_log( sprintf( '[cloudscale-site-analytics] cron cspv_dbip_auto_update failed (%s): %s', get_class( $e ), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional cron error logging
        if ( class_exists( 'CloudScale_Telegram' ) ) {
            CloudScale_Telegram::send(
                "DB-IP geolocation auto-update cron failed.\n\nError: " . $e->getMessage(),
                'Site Analytics',
                'error'
            );
        }
    }
} );

/**
 * Cron callback: download a fresh DB-IP Lite file when the installed copy
 * is from a previous calendar month.
 *
 * Only runs when geo source is 'auto' or 'dbip', skipped for sites using
 * Cloudflare-only or with geo tracking disabled entirely.
 *
 * @since  2.9.187
 * @return void
 */
function cspv_dbip_auto_update_run() {
    if ( get_option( 'cspv_dbip_auto_update', 'yes' ) !== 'yes' ) {
        return;
    }

    $geo_source = get_option( 'cspv_geo_source', 'auto' );
    if ( 'cloudflare' === $geo_source || 'disabled' === $geo_source ) {
        return;
    }

    $installed_ym = get_option( 'cspv_dbip_installed_ym', '' );

    // Only ever auto-refresh a database the user has already downloaded once
    // by hand. The first download is never performed automatically, so no
    // external request to DB-IP occurs without an explicit user action.
    // See the readme "External services" section.
    if ( '' === $installed_ym ) {
        return;
    }

    if ( $installed_ym === gmdate( 'Y-m' ) ) {
        return; // Already on the current month's database
    }

    cspv_download_dbip_file();
}

/**
 * AJAX handler: purge the unique-visitors table.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_ajax_purge_visitors() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
        return;
    }

    try {
        global $wpdb;
        $table = esc_sql( $wpdb->prefix . 'cs_analytics_visitors_v2' );
        $days  = absint( wp_unslash( $_POST['days'] ?? 90 ) );

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table
        if ( ! $table_exists ) {
            wp_send_json_error( 'Visitors table does not exist.' ); return;
        }

        if ( $days === 0 ) {
            $deleted = $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression
            wp_send_json_success( array( 'deleted' => 'all', 'remaining' => 0 ) );
        }

        $cutoff = ( new DateTime( 'now', wp_timezone() ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE viewed_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table/column name
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name/expression

        wp_send_json_success( array(
            'deleted'   => (int) $deleted,
            'cutoff'    => $cutoff,
            'remaining' => $remaining,
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * Persist display + geo settings from $_POST. Returns a geo-notice string (may be empty).
 * Called by both the AJAX handler and the POST-based form handler.
 *
 * @since 2.9.308
 * @return string  Admin notice suffix, e.g. ' DB-IP Lite (45 MB) downloaded automatically.'
 */
function cspv_save_display_settings() {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is always verified by every caller before invoking this function
    $valid_positions = array( 'before_content', 'after_content', 'both', 'off' );
    $pos = isset( $_POST['cspv_auto_display'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_auto_display'] ) ) : 'before_content';
    update_option( 'cspv_auto_display', in_array( $pos, $valid_positions, true ) ? $pos : 'before_content' );

    $valid_styles = array( 'badge', 'pill', 'minimal' );
    $sty = isset( $_POST['cspv_display_style'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_style'] ) ) : 'badge';
    update_option( 'cspv_display_style', in_array( $sty, $valid_styles, true ) ? $sty : 'badge' );

    update_option( 'cspv_display_icon',   isset( $_POST['cspv_display_icon'] )   ? sanitize_text_field( wp_unslash( $_POST['cspv_display_icon'] ) )   : '👁' );
    update_option( 'cspv_display_suffix', isset( $_POST['cspv_display_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_suffix'] ) ) : ' views' );

    $pt = isset( $_POST['cspv_display_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_display_post_types'] ) : array( 'post' );
    update_option( 'cspv_display_post_types', $pt );

    $tpt = isset( $_POST['cspv_track_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_track_post_types'] ) : array( 'post', 'page' );
    update_option( 'cspv_track_post_types', $tpt );

    $valid_colors = array( 'blue', 'pink', 'red', 'purple', 'grey' );
    $col = isset( $_POST['cspv_display_color'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_color'] ) ) : 'blue';
    update_option( 'cspv_display_color', in_array( $col, $valid_colors, true ) ? $col : 'blue' );

    $valid_geo = array( 'auto', 'cloudflare', 'dbip', 'disabled' );
    $geo = isset( $_POST['cspv_geo_source'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_geo_source'] ) ) : 'auto';
    update_option( 'cspv_geo_source', in_array( $geo, $valid_geo, true ) ? $geo : 'auto' );
    update_option( 'cspv_dbip_auto_update', isset( $_POST['cspv_dbip_auto_update'] ) ? 'yes' : 'no' );

    $geo_notice = '';
    if ( in_array( $geo, array( 'auto', 'dbip' ), true ) ) {
        $mmdb_path = wp_upload_dir()['basedir'] . '/cspv-geo/dbip-city-lite.mmdb';
        if ( ! file_exists( $mmdb_path ) ) {
            $dl = cspv_download_dbip_file();
            if ( is_wp_error( $dl ) ) {
                $geo_notice = ' DB-IP download failed: ' . esc_html( $dl->get_error_message() );
            } else {
                $geo_notice = ' DB-IP Lite (' . esc_html( $dl['size'] ) . ') downloaded automatically.';
            }
        }
    }

    // phpcs:enable WordPress.Security.NonceVerification.Missing
    return $geo_notice;
}

function cspv_ajax_save_display_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
        return;
    }
    if ( ! check_ajax_referer( 'cspv_display_save', 'nonce', false ) ) {
        wp_send_json_error( 'Security check failed.', 403 );
        return;
    }

    try {
        cspv_save_display_settings();
        wp_send_json_success( array( 'message' => 'Display settings saved.' ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * AJAX handler: top posts with trend data for the Insights tab.
 *
 * @since 1.0.0
 */
function cspv_ajax_insights() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
        return;
    }
    check_ajax_referer( 'cspv_insights', 'nonce' );

    try {
        $from_raw = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
        $to_raw   = isset( $_POST['to']   ) ? sanitize_text_field( wp_unslash( $_POST['to']   ) ) : '';

        $from = DateTime::createFromFormat( 'Y-m-d', $from_raw, wp_timezone() );
        $to   = DateTime::createFromFormat( 'Y-m-d', $to_raw,   wp_timezone() );
        if ( ! $from || ! $to ) { wp_send_json_error( 'Invalid dates', 400 ); return; }
        if ( $from > $to ) { $tmp = $from; $from = $to; $to = $tmp; }

        $from_str      = $from->format( 'Y-m-d' ) . ' 00:00:00';
        $to_str        = $to->format( 'Y-m-d' )   . ' 23:59:59';
        $diff_days     = max( 1, (int) date_diff( $from, $to )->days );
        $prev_from     = clone $from; $prev_from->modify( '-' . $diff_days . ' days' );
        $prev_to       = clone $to;   $prev_to->modify(   '-' . $diff_days . ' days' );

        wp_send_json_success( cspv_insights_top_pages(
            $from_str, $to_str,
            $prev_from->format( 'Y-m-d' ) . ' 00:00:00',
            $prev_to->format( 'Y-m-d' )   . ' 23:59:59',
            100
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * AJAX handler: full Insights dashboard data.
 *
 * @since 1.0.0
 */
function cspv_ajax_insights_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
        return;
    }
    check_ajax_referer( 'cspv_insights_dashboard', 'nonce' );

    try {
        $period = min( 360, max( 7, (int) ( isset( $_POST['period'] ) ? absint( $_POST['period'] ) : 30 ) ) );

        $to   = new DateTime( 'now', wp_timezone() );
        $from = clone $to;
        $from->modify( '-' . ( $period - 1 ) . ' days' );
        $from->setTime( 0, 0, 0 );
        $to->setTime( 23, 59, 59 );

        $from_str = $from->format( 'Y-m-d H:i:s' );
        $to_str   = $to->format( 'Y-m-d H:i:s' );
        $own_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        $kpi = cspv_insights_kpi( $from_str, $to_str, $own_host );
        wp_send_json_success( array(
            'period'                  => $period,
            'kpi'                     => $kpi,
            'smart_summary'           => cspv_insights_smart_summary( $from_str, $to_str, $own_host, $period, $kpi ),
            'traffic_sources'         => cspv_insights_traffic_sources( $from_str, $to_str, $own_host ),
            'referrer_growth'         => cspv_insights_referrer_growth( $from_str, $to_str, $own_host, $period ),
            'peak_hours'              => cspv_insights_peak_hours( $from_str, $to_str ),
            'top_posts'               => cspv_insights_top_posts_data( $from_str, $to_str ),
            'top_posts_by_referrer'   => cspv_insights_posts_by_referrer( $from_str, $to_str, $own_host ),
            'referrer_landing_pages'  => cspv_insights_referrer_landing_pages( $from_str, $to_str, $own_host ),
            'views_by_country'        => cspv_top_countries( $from_str, $to_str, 10 ),
            'top_countries_over_time' => cspv_insights_countries_over_time( $from_str, $to_str, $period ),
            'top_referrer_domains'    => cspv_insights_referrer_domains_full( $from_str, $to_str, $own_host ),
        ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

/**
 * AJAX: return country breakdown for a single post (all-time).
 * Used by the Post Analytics geo map in the Insights tab.
 *
 * @since 2.9.318
 * @return void
 */
function cspv_ajax_post_geo_map() {
    if ( ! check_ajax_referer( 'cspv_chart_data', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 ); return; }

    try {
        global $wpdb;
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Invalid post ID' ) ); return; }

        $table = esc_sql( $wpdb->prefix . 'cs_analytics_geo_v2' );
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( ! $table_exists ) { wp_send_json_success( array() ); return; }

        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name
            "SELECT country_code AS cc, COALESCE(SUM(view_count),0) AS v
             FROM `{$table}`
             WHERE post_id = %d AND country_code <> ''
             GROUP BY country_code ORDER BY v DESC",
            $post_id
        ) );

        $geo = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $geo[] = array( 'cc' => $r->cc, 'v' => (int) $r->v );
            }
        }

        // Top 5 referrers for this post.
        $ref_table = esc_sql( $wpdb->prefix . 'cs_analytics_referrers_v2' );
        $ref_rows  = array();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name
                "SELECT SUBSTRING_INDEX(referrer, '?', 1) AS r, SUM(view_count) AS v
                 FROM `{$ref_table}`
                 WHERE post_id = %d AND referrer <> ''
                 GROUP BY SUBSTRING_INDEX(referrer, '?', 1) ORDER BY v DESC LIMIT 10",
                $post_id
            ) );
        }
        $refs = array();
        if ( is_array( $ref_rows ) ) {
            foreach ( $ref_rows as $r ) {
                $refs[] = array( 'r' => $r->r, 'v' => (int) $r->v );
            }
        }

        wp_send_json_success( array( 'geo' => $geo, 'refs' => $refs ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

function cspv_ajax_version() {
    wp_send_json( array( 'v' => CSPV_VERSION ) );
}
