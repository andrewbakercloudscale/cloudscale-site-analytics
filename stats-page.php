<?php
/**
 * CloudScale Analytics - Statistics Dashboard
 *
 * @package CloudScale_Free_Analytics
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu',            'cspv_add_tools_page' );
add_action( 'admin_enqueue_scripts', 'cspv_enqueue_admin_assets' );
add_action( 'admin_head',            'cspv_admin_menu_styles' );
add_action( 'admin_enqueue_scripts', 'cspv_admin_menu_enqueue' );
add_action( 'wp_enqueue_scripts',    'cspv_frontend_nav_enqueue' );
add_action( 'wp_ajax_cspv_chart_data', 'cspv_ajax_chart_data' );
add_action( 'wp_ajax_cspv_post_history', 'cspv_ajax_post_history' );
add_action( 'wp_ajax_cspv_post_search', 'cspv_ajax_post_search' );
add_action( 'wp_ajax_cspv_resync_meta', 'cspv_ajax_resync_meta_from_stats' );
add_action( 'wp_ajax_cspv_country_drill',   'cspv_ajax_country_drill' );
add_action( 'wp_ajax_cspv_referrer_drill', 'cspv_ajax_referrer_drill' );
add_action( 'wp_ajax_cspv_download_dbip', 'cspv_ajax_download_dbip' );
add_action( 'wp_ajax_cspv_purge_visitors', 'cspv_ajax_purge_visitors' );

/**
 * Inject viewport meta tag on the plugin page so mobile media queries fire correctly.
 *
 * The WP admin does not output a viewport meta tag by default, causing phones to
 * render the page at the default 980px viewport where max-width:782px never fires.
 *
 * @since 2.9.135
 * @return void
 */
function cspv_admin_menu_styles() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'cloudscale-wordpress-free-analytics' ) {
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    }
}

/**
 * Enqueue inline CSS to highlight CloudScale menu items in Tools with a light blue colour.
 *
 * @since 2.9.135
 * @return void
 */
function cspv_admin_menu_enqueue() {
    wp_register_style( 'cspv-admin-menu', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle, version set
    wp_enqueue_style( 'cspv-admin-menu' );
    wp_add_inline_style(
        'cspv-admin-menu',
        '#adminmenu a[href*="cloudscale"], #adminmenu a[href*="cs-seo-optimizer"] { color: #7dd3fc !important; }
        #adminmenu a[href*="cloudscale"]:hover, #adminmenu a[href*="cs-seo-optimizer"]:hover { color: #fff !important; }'
    );
}

/**
 * Enqueue inline CSS to style the CloudScale nav menu item on the frontend.
 *
 * @since 2.9.135
 * @return void
 */
function cspv_frontend_nav_enqueue() {
    wp_register_style( 'cspv-frontend-nav', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle, version set
    wp_enqueue_style( 'cspv-frontend-nav' );
    wp_add_inline_style(
        'cspv-frontend-nav',
        '.cs-cloudscale-menu > a { color: #93c5fd !important; font-weight: 700 !important; }
        .cs-cloudscale-menu > a:hover { color: #bfdbfe !important; }'
    );
}

/**
 * Register the plugin stats page under Tools.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_add_tools_page() {
    add_management_page(
        'CloudScale Site Analytics',
        '📊 Site Analytics',
        'manage_options',
        'cloudscale-wordpress-free-analytics',
        'cspv_render_stats_page'
    );
}

/**
 * Enqueue Chart.js, Leaflet and plugin CSS/JS on the stats page.
 *
 * @since 1.0.0
 * @param string $hook Current admin page hook.
 * @return void
 */
function cspv_enqueue_admin_assets( $hook ) {
    if ( 'tools_page_cloudscale-wordpress-free-analytics' !== $hook ) { return; }
    wp_enqueue_script( 'cspv-chartjs',
        CSPV_PLUGIN_URL . 'assets/js/chart.umd.min.js',
        array(), '4.4.1', true );
    wp_enqueue_style( 'cspv-leaflet-css',
        CSPV_PLUGIN_URL . 'assets/css/leaflet.min.css',
        array(), '1.9.4' );
    wp_enqueue_script( 'cspv-leaflet-js',
        CSPV_PLUGIN_URL . 'assets/js/leaflet.min.js',
        array(), '1.9.4', true );
    wp_enqueue_style( 'cspv-stats-page',
        CSPV_PLUGIN_URL . 'assets/css/stats-page.css',
        array(), CSPV_VERSION );
    wp_register_script( 'cspv-stats-page', false,
        array( 'cspv-chartjs', 'cspv-leaflet-js' ), CSPV_VERSION, true );
    wp_enqueue_script( 'cspv-stats-page' );
}

// ---------------------------------------------------------------------------
// AJAX — chart data
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
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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
        $raw = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE_FORMAT(viewed_at,'%%Y-%%m-%%d %%H') AS hr_key, {$cnt} AS views
              FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s
              GROUP BY hr_key ORDER BY hr_key ASC",
            $from_str, $to_str ) );
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
            $raw = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT DATE_FORMAT(viewed_at,'%%Y-%%m-%%d %%H') AS hr_key, {$cnt} AS views
                  FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s
                  GROUP BY hr_key ORDER BY hr_key ASC",
                $from_str, $to_str ) );
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
            $raw = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT DATE_FORMAT(viewed_at,'%%H') AS hr, {$cnt} AS views
                  FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s
                  GROUP BY hr",
                $from_str, $to_str ) );
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
        $raw = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE(viewed_at) AS ymd, {$cnt} AS views
              FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s
              GROUP BY ymd",
            $from_str, $to_str ) );
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
        $raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(viewed_at,'%%Y-%%u') AS wk,
                     MIN(DATE(viewed_at)) AS wk_start,
                     {$cnt} AS views
              FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s
              GROUP BY wk ORDER BY wk ASC",
            $from_str, $to_str ) );
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
    $lifetime_total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name
        "SELECT SUM(view_count) FROM `{$table}`"
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
        'countries'       => $countries,
        'geo_source'      => get_option( 'cspv_geo_source', 'auto' ),
        'geo_source_actual' => ( function() {
            $s = get_option( 'cspv_geo_source', 'auto' );
            if ( 'cloudflare' === $s ) { return 'cloudflare'; }
            if ( 'dbip'       === $s ) { return 'dbip'; }
            if ( 'disabled'   === $s ) { return 'disabled'; }
            // auto: CF wins if header present, else DB-IP if mmdb exists, else none
            if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) { return 'cloudflare'; }
            $mmdb = WP_CONTENT_DIR . '/uploads/cspv-geo/dbip-city-lite.mmdb';
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
        $s_table = cspv_views_table();
        $s_cnt   = cspv_count_expr();
        $s_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $s_table ) );
        if ( $s_table_exists ) {
            $s_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT post_id, {$s_cnt} AS cnt FROM `{$s_table}` WHERE post_id IN ({$s_ids_str}) GROUP BY post_id" );
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

    global $wpdb;
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Invalid post ID' ) ); }

    $table = cspv_views_table();
    $cnt   = cspv_count_expr();
    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    $meta_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
    $log_count  = 0;
    $first_log  = null;
    $last_log   = null;
    $daily      = array();
    $hourly     = array();
    // WordPress timezone timestamps for queries (viewed_at is stored in WP timezone)
    $wp_now    = current_time( 'mysql' );
    $wp_180d   = wp_date( 'Y-m-d H:i:s', strtotime( $wp_now ) - ( 180 * 86400 ) );
    $wp_48h    = wp_date( 'Y-m-d H:i:s', strtotime( $wp_now ) - 172800 );

    if ( $table_exists ) {
        $log_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE post_id = %d", $post_id ) );

        $first_log = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT MIN(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) );

        $last_log = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT MAX(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) );

        // Daily views for last 180 days
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE(viewed_at) AS day, {$cnt} AS views
             FROM `{$table}`
             WHERE post_id = %d AND viewed_at >= %s
             GROUP BY day ORDER BY day ASC", $post_id, $wp_180d ) );
        foreach ( (array) $rows as $r ) {
            $daily[] = array( 'day' => $r->day, 'views' => (int) $r->views );
        }

        // Hourly views for last 48 hours
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE_FORMAT(viewed_at, '%%Y-%%m-%%d %%H:00') AS hour, {$cnt} AS views
             FROM `{$table}`
             WHERE post_id = %d AND viewed_at >= %s
             GROUP BY hour ORDER BY hour ASC", $post_id, $wp_48h ) );
        foreach ( (array) $rows as $r ) {
            $hourly[] = array( 'hour' => $r->hour, 'views' => (int) $r->views );
        }

        // 180 day daily timeline with top referrer per day
        $timeline_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE(viewed_at) AS day, {$cnt} AS views
             FROM `{$table}`
             WHERE post_id = %d AND viewed_at >= %s
             GROUP BY day ORDER BY day DESC", $post_id, $wp_180d ) );

        // Top referrer per day (uses shared referrer source)
        $ref_src  = cspv_referrer_source();
        $ref_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE(viewed_at) AS day, referrer, {$ref_src['cnt']} AS cnt
             FROM `{$ref_src['table']}`
             WHERE post_id = %d AND viewed_at >= %s AND referrer != ''
             GROUP BY day, referrer ORDER BY day DESC, cnt DESC", $post_id, $wp_180d ) );

        // Split referrers into self (own domain) and top external per day
        $site_host   = preg_replace( '/^www\./', '', parse_url( home_url(), PHP_URL_HOST ) );
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

    global $wpdb;
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! $post_id ) { wp_send_json_error( array( 'message' => 'Invalid post ID' ) ); }

    $table     = cspv_views_table();
    $cnt       = cspv_count_expr();
    $log_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "SELECT {$cnt} FROM `{$table}` WHERE post_id = %d", $post_id ) );
    $old_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );

    update_post_meta( $post_id, CSPV_META_KEY, $log_count );

    wp_send_json_success( array(
        'post_id'   => $post_id,
        'old_count' => $old_count,
        'new_count' => $log_count,
        'log_rows'  => $log_count,
    ) );
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

    $country = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
    $from    = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
    $to      = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );

    if ( strlen( $country ) !== 2 || ! $from || ! $to ) {
        wp_send_json_error( 'Invalid parameters' );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ||
         ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
        wp_send_json_error( 'Invalid date format.' );
    }

    $from_str = $from . ' 00:00:00';
    $to_str   = $to . ' 23:59:59';

    $pages = cspv_top_pages_by_country( $country, $from_str, $to_str, 10 );
    wp_send_json_success( array( 'pages' => $pages ) );
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

    $host       = sanitize_text_field( wp_unslash( $_POST['host']      ?? '' ) );
    $from       = sanitize_text_field( wp_unslash( $_POST['from']      ?? '' ) );
    $to         = sanitize_text_field( wp_unslash( $_POST['to']        ?? '' ) );
    $rolling24h = ! empty( $_POST['rolling24h'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rolling24h'] ) );

    if ( ! $host || ! $from || ! $to ) {
        wp_send_json_error( 'Invalid parameters' );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ||
         ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
        wp_send_json_error( 'Invalid date format.' );
    }

    if ( $rolling24h && $from === $to ) {
        $tz       = wp_timezone();
        $now      = new DateTime( 'now', $tz );
        $to_str   = $now->format( 'Y-m-d H:i:s' );
        $from_str = ( clone $now )->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
    } else {
        $from_str = $from . ' 00:00:00';
        $to_str   = $to   . ' 23:59:59';
    }

    $pages = cspv_top_pages_by_referrer_host( $host, $from_str, $to_str, 25 );
    wp_send_json_success( array( 'pages' => $pages ) );
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
    $mmdb_dir  = WP_CONTENT_DIR . '/uploads/cspv-geo';
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
    $out = fopen( $mmdb_path, 'wb' );
    if ( ! $out ) {
        gzclose( $gz );
        if ( file_exists( $gz_path ) ) { wp_delete_file( $gz_path ); }
        return new WP_Error( 'write_failed', 'Failed to write mmdb file.' );
    }
    while ( ! gzeof( $gz ) ) {
        fwrite( $out, gzread( $gz, 8192 ) );
    }
    gzclose( $gz );
    fclose( $out );
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

    $result = cspv_download_dbip_file();
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    } else {
        wp_send_json_success( $result );
    }
}

// ---------------------------------------------------------------------------
// WP-Cron: auto-update DB-IP Lite once per month
// ---------------------------------------------------------------------------
add_action( 'cspv_dbip_auto_update', 'cspv_dbip_auto_update_run' );

/**
 * Cron callback: download a fresh DB-IP Lite file when the installed copy
 * is from a previous calendar month.
 *
 * Only runs when geo source is 'auto' or 'dbip' — skipped for sites using
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

    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_visitors_v2';
    $days  = absint( wp_unslash( $_POST['days'] ?? 90 ) );

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        wp_send_json_error( 'Visitors table does not exist.' );
    }

    if ( $days === 0 ) {
        $deleted = $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        wp_send_json_success( array( 'deleted' => 'all', 'remaining' => 0 ) );
    }

    $cutoff = ( new DateTime( 'now', wp_timezone() ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
    $deleted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
        "DELETE FROM `{$table}` WHERE viewed_at < %s", $cutoff
    ) );
    $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression

    wp_send_json_success( array(
        'deleted'   => (int) $deleted,
        'cutoff'    => $cutoff,
        'remaining' => $remaining,
    ) );
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
/**
 * Render the full stats page HTML.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_render_stats_page() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    global $wpdb;

    // Handle display settings save
    if ( isset( $_POST['cspv_display_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['cspv_display_nonce'] ), 'cspv_display_save' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce value, not user content
        $valid_positions = array( 'before_content', 'after_content', 'both', 'off' );
        $pos = isset( $_POST['cspv_auto_display'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_auto_display'] ) ) : 'before_content';
        update_option( 'cspv_auto_display', in_array( $pos, $valid_positions, true ) ? $pos : 'before_content' );

        $valid_styles = array( 'badge', 'pill', 'minimal' );
        $sty = isset( $_POST['cspv_display_style'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_style'] ) ) : 'badge';
        update_option( 'cspv_display_style', in_array( $sty, $valid_styles, true ) ? $sty : 'badge' );

        update_option( 'cspv_display_icon', isset( $_POST['cspv_display_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_icon'] ) ) : '👁' );
        update_option( 'cspv_display_suffix', isset( $_POST['cspv_display_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_suffix'] ) ) : ' views' );

        $pt = isset( $_POST['cspv_display_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_display_post_types'] ) : array( 'post' );
        update_option( 'cspv_display_post_types', $pt );

        $tpt = isset( $_POST['cspv_track_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_track_post_types'] ) : array( 'post', 'page' );
        update_option( 'cspv_track_post_types', $tpt );

        $valid_colors = array( 'blue', 'pink', 'red', 'purple', 'grey' );
        $col = isset( $_POST['cspv_display_color'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_display_color'] ) ) : 'blue';
        update_option( 'cspv_display_color', in_array( $col, $valid_colors, true ) ? $col : 'blue' );

        // Geography source
        $valid_geo = array( 'auto', 'cloudflare', 'dbip', 'disabled' );
        $geo = isset( $_POST['cspv_geo_source'] ) ? sanitize_text_field( wp_unslash( $_POST['cspv_geo_source'] ) ) : 'auto';
        update_option( 'cspv_geo_source', in_array( $geo, $valid_geo, true ) ? $geo : 'auto' );
        update_option( 'cspv_dbip_auto_update', isset( $_POST['cspv_dbip_auto_update'] ) ? 'yes' : 'no' );

        // Auto-download DB-IP when source requires it and the file is missing
        $geo_notice = '';
        if ( in_array( $geo, array( 'auto', 'dbip' ), true ) ) {
            $mmdb_path = WP_CONTENT_DIR . '/uploads/cspv-geo/dbip-city-lite.mmdb';
            if ( ! file_exists( $mmdb_path ) ) {
                $dl = cspv_download_dbip_file();
                if ( is_wp_error( $dl ) ) {
                    $geo_notice = ' DB-IP download failed: ' . esc_html( $dl->get_error_message() );
                } else {
                    $geo_notice = ' DB-IP Lite (' . esc_html( $dl['size'] ) . ') downloaded automatically.';
                }
            }
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__( 'Display settings saved.', 'cloudscale-wordpress-free-analytics' ) . $geo_notice
        );
    }

    $ajax_url        = admin_url( 'admin-ajax.php' );
    $ajax_nonce      = wp_create_nonce( 'cspv_chart_data' );
    $throttle_nonce  = wp_create_nonce( 'cspv_throttle' );
    $today           = current_time( 'Y-m-d' );
    $throttle_enabled = cspv_throttle_enabled();
    $throttle_limit   = cspv_throttle_limit();
    $throttle_window  = cspv_throttle_window_seconds();
    $blocklist        = cspv_get_blocklist();
    $block_log        = cspv_get_block_log();
    $ftb_enabled      = cspv_ftb_enabled();
    $ftb_page_limit   = cspv_ftb_page_limit();
    $ftb_window       = cspv_ftb_window_seconds();
    $ftb_block_dur    = cspv_ftb_block_duration();
    $ftb_rules        = cspv_ftb_get_rules();
    $ftb_blocklist    = cspv_ftb_get_blocklist();
    $ftb_log          = cspv_ftb_get_log();
    $tracking_paused  = cspv_tracking_paused();
    $dedup_val        = get_option( 'cspv_dedup_enabled', 'yes' );
    $dedup_enabled    = ( $dedup_val !== 'no' );
    $dedup_window     = (int) get_option( 'cspv_dedup_window', 86400 );

    // Top 100 posts by view count for Post History tab
    $ph_top_posts = get_posts( array(
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'meta_key'       => CSPV_META_KEY,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    ) );

    // Display settings
    $dsp_position    = get_option( 'cspv_auto_display', 'before_content' );
    $dsp_post_types  = get_option( 'cspv_display_post_types', array( 'post' ) );
    $dsp_icon        = get_option( 'cspv_display_icon', '👁' );
    $dsp_suffix      = get_option( 'cspv_display_suffix', ' views' );
    $dsp_style       = get_option( 'cspv_display_style', 'badge' );
    $dsp_track_types = get_option( 'cspv_track_post_types', array( 'post', 'page' ) );
    $dsp_all_types   = get_post_types( array( 'public' => true ), 'objects' );
    $dsp_color       = get_option( 'cspv_display_color', 'blue' );
    ?>
<!DOCTYPE html>
<div id="cspv-app">

    <!-- ═══════════════════════ HEADER BANNER ═══════════════════════ -->
    <div id="cspv-banner">
        <div id="cspv-banner-left">
            <div id="cspv-banner-title"><img src="<?php echo esc_url( plugins_url( 'cloudscaleanalytics.png', __FILE__ ) ); ?>" style="height:22px;width:auto;vertical-align:middle;margin-right:8px;position:relative;top:-1px;" alt=""> CloudScale Site Analytics v<?php echo esc_html( CSPV_VERSION ); ?></div>
            <div id="cspv-banner-sub">Cloudflare-accurate view tracking · v<?php echo esc_html( CSPV_VERSION ); ?></div>
        </div>
        <div id="cspv-banner-right">
            <span class="cspv-badge cspv-badge-green">● Site Online</span>
            <a href="https://your-wordpress-site.example.com/2026/02/27/cloudscale-free-wordpress-analytics-analytics-that-work-behind-cloudflare/" target="_blank" class="cspv-badge cspv-badge-orange" style="text-decoration:none;"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?></a>
            <button id="cspv-help-btn" title="Help" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:2px solid rgba(255,255,255,0.5);background:rgba(255,255,255,0.15);color:#fff;font-size:15px;font-weight:800;cursor:pointer;line-height:1;padding:0;transition:background .15s;">?</button>
        </div>
    </div>

    <!-- ═══════════════════════ TAB BAR ═════════════════════════════ -->
    <div id="cspv-tab-bar">
        <button class="cspv-tab active" data-tab="stats">📊 Statistics</button>
        <button class="cspv-tab" data-tab="display">👁 Display</button>
        <button class="cspv-tab" data-tab="throttle">🛡 IP Throttle</button>
        <button class="cspv-tab" data-tab="history">🔍 Post History</button>
        <span class="cspv-tab-spacer"></span>
    </div>

    <!-- ═══════════════════════ STATS TAB ═══════════════════════════ -->
    <div id="cspv-tab-stats" class="cspv-tab-content active">

        <!-- Date range bar -->
        <div id="cspv-date-bar">
            <div id="cspv-quick-btns">
                <button class="cspv-quick" data-range="12h">12 Hours</button>
                <button class="cspv-quick" data-range="today">Last 24h</button>
                <button class="cspv-quick" data-range="7">1 Week</button>
                <button class="cspv-quick" data-range="30">1 Month</button>
                <button class="cspv-quick" data-range="90">3 Months</button>
                <button class="cspv-quick" data-range="180">6 Months</button>
            </div>
            <div id="cspv-date-inputs">
                <label>From <input type="date" id="cspv-from" value="<?php echo esc_attr( $today ); ?>"></label>
                <label>To&nbsp;&nbsp; <input type="date" id="cspv-to" value="<?php echo esc_attr( $today ); ?>"></label>
                <button id="cspv-apply" class="cspv-btn-primary">Apply</button>
            </div>
        </div>

        <!-- Summary cards -->
        <div id="cspv-cards">
            <div class="cspv-card" id="cspv-card-views">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="cspv-card-icon" style="margin:0;">👁</div>
                    <div class="cspv-card-label" style="margin:0;font-weight:700;">Views</div>
                </div>
                <div class="cspv-card-value" id="stat-delta">—</div>
                <div class="cspv-card-sub" id="stat-views-detail" style="font-size:13px;color:#6b7280;margin-top:4px;"></div>
            </div>
            <div class="cspv-card" id="cspv-card-posts">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="cspv-card-icon" style="margin:0;">📄</div>
                    <div class="cspv-card-label" style="margin:0;font-weight:700;">Posts Viewed</div>
                </div>
                <div class="cspv-card-value" id="stat-posts-delta">—</div>
                <div class="cspv-card-sub" id="stat-posts-detail" style="font-size:13px;color:#6b7280;margin-top:4px;"></div>
            </div>
            <div class="cspv-card" id="cspv-card-visitors">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="cspv-card-icon" style="margin:0;">👤</div>
                    <div class="cspv-card-label" style="margin:0;font-weight:700;">Unique Visitors</div>
                </div>
                <div class="cspv-card-value" id="stat-visitors-delta">—</div>
                <div class="cspv-card-sub" id="stat-visitors-detail" style="font-size:13px;color:#6b7280;margin-top:4px;"></div>
            </div>
            <div class="cspv-card" id="cspv-card-hotpages">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="cspv-card-icon" style="margin:0;">🔥</div>
                    <div class="cspv-card-label" style="margin:0;font-weight:700;">Hot Pages</div>
                </div>
                <div class="cspv-card-value" id="stat-hotpages-delta">—</div>
                <div class="cspv-card-sub" id="stat-hotpages-detail" style="font-size:13px;color:#6b7280;margin-top:4px;"></div>
            </div>
            <div class="cspv-card" id="cspv-card-depth">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="cspv-card-icon" style="margin:0;">📊</div>
                    <div class="cspv-card-label" style="margin:0;font-weight:700;">Pages per Session</div>
                </div>
                <div class="cspv-card-sub" id="stat-depth-avg" style="font-size:17px;font-weight:700;color:#0066ff;margin-top:6px;"></div>
                <div class="cspv-card-sub" id="stat-depth-max" style="font-size:17px;font-weight:700;color:#0066ff;margin-top:2px;"></div>
                <div class="cspv-card-sub" id="stat-depth-sessions" style="font-size:13px;color:#6b7280;margin-top:6px;"></div>
            </div>
        </div>

        <!-- Lifetime stats bar -->
        <div id="cspv-lifetime-bar">
            <div class="cspv-lifetime-stat">
                <span class="cspv-lifetime-label">🏆 All Time Views</span>
                <span class="cspv-lifetime-value" id="stat-lifetime-views">—</span>
            </div>
            <div class="cspv-lifetime-stat">
                <span class="cspv-lifetime-label">👤 All Time Unique Visitors</span>
                <span class="cspv-lifetime-value" id="stat-lifetime-visitors">—</span>
            </div>
        </div>

        <!-- Chart -->
        <div id="cspv-chart-box">
            <div class="cspv-section-header">
                <span>Views <a class="cspv-info-btn" data-info="stats-overview" title="Info">i</a></span>
                <span id="cspv-chart-range-label" class="cspv-range-label"></span>
            </div>
            <div id="cspv-chart-wrap">
                <canvas id="cspv-chart"></canvas>
                <div id="cspv-chart-msg">Loading…</div>
            </div>
        </div>

        <!-- Bottom panels -->
        <div id="cspv-panels">
            <div class="cspv-panel">
                <div class="cspv-section-header cspv-section-header-green">
                    <span>Most Viewed (Period) <a class="cspv-info-btn" data-info="top-posts" title="Info">i</a></span><span>Views <a class="cspv-info-btn" data-info="stats-overview" title="Info">i</a></span>
                </div>
                <div id="cspv-top-posts"></div>
            </div>
            <div class="cspv-panel">
                <div class="cspv-section-header cspv-section-header-orange">
                    <span>Referrers <a class="cspv-info-btn" data-info="referrers" title="Info">i</a></span>
                    <span class="cspv-ref-toggle-wrap">
                        <button class="cspv-ref-toggle active" data-ref-mode="sites">Sites</button>
                        <button class="cspv-ref-toggle" data-ref-mode="pages">Pages</button>
                    </span>
                </div>
                <div id="cspv-referrers"></div>
            </div>
        </div>

        <!-- Geography panel -->
        <div id="cspv-geo-panel" style="margin-top:16px;">
            <div class="cspv-panel" style="flex:1;">
                <div class="cspv-section-header" style="color:#fff;background:linear-gradient(135deg,#0f766e,#14b8a6);border-radius:6px 6px 0 0;">
                    <span>🌍 Geography <span id="cspv-geo-range" style="font-size:11px;font-weight:400;opacity:0.8;"></span><span id="cspv-geo-source-badge" style="display:none;margin-left:8px;font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px;vertical-align:middle;letter-spacing:0.03em;"></span></span>
                    <a href="#" id="cspv-geo-reset" style="font-size:11px;color:rgba(255,255,255,.8);text-decoration:underline;font-weight:400;">Reset Map</a>
                </div>
                <div id="cspv-geo-map" style="height:300px;width:100%;background:#f0fdf4;"></div>
                <div id="cspv-geo-list" style="padding:8px 0;"></div>
                <div id="cspv-geo-drill" style="display:none;padding:8px 16px 12px;border-top:1px solid #f0f0f0;">
                    <div id="cspv-geo-drill-header" style="font-size:13px;font-weight:700;color:#0f766e;margin-bottom:8px;"></div>
                    <div id="cspv-geo-drill-list"></div>
                    <button id="cspv-geo-drill-back" style="margin-top:8px;font-size:11px;color:#6b7280;cursor:pointer;border:none;background:none;padding:0;text-decoration:underline;">Back to all countries</button>
                </div>
            </div>
        </div>

        <!-- Session depth panel -->
        <div id="cspv-depth-panel" style="margin-top:16px;">
            <div class="cspv-panel" style="flex:1;">
                <div class="cspv-section-header" style="color:#fff;background:linear-gradient(135deg,#7c3aed,#a78bfa);border-radius:6px 6px 0 0;">
                    <span>📊 Pages Served Per Session: <span id="cspv-depth-range" style="font-weight:400;opacity:0.8;"></span></span>
                </div>
                <div id="cspv-depth-content" style="padding:16px;">
                    <div id="cspv-depth-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;"></div>
                    <div id="cspv-depth-no-data" style="display:none;color:#6b7280;font-size:13px;padding:8px 0;">No session data yet for this period.</div>
                </div>
            </div>
        </div>

        <!-- All time panel -->
        <div id="cspv-panels-alltime">
            <div class="cspv-panel" style="flex:1;">
                <div class="cspv-section-header" style="color:#fff;background:linear-gradient(135deg,#1a3a8f,#1e6fd9);border-radius:6px 6px 0 0;">
                    <span>🏆 All Time Top Posts <a class="cspv-info-btn" data-info="all-time" title="Info">i</a></span>
                    <span>Total Views</span>
                </div>
                <div id="cspv-lifetime-top"></div>
            </div>
        </div>

        <!-- CF cache bypass test -->
        <div id="cspv-cf-notice">
            <div id="cspv-cf-header">
                <div id="cspv-cf-title">
                    <strong>Cloudflare Cache Bypass</strong> <a class="cspv-info-btn-dark cspv-info-btn" data-info="cache-test" title="Info" style="display:inline-flex;">i</a>
                    <span id="cspv-cf-status-badge"></span>
                </div>
                <button id="cspv-cf-test-btn" class="cspv-btn-primary" style="font-size:11px;padding:5px 14px;">
                    Test Cache Bypass
                </button>
            </div>
            <div id="cspv-cf-rule">
                Required Cache Rule: URI Path <code>contains</code>
                <code>/wp-json/cloudscale-wordpress-free-analytics/</code> → Cache Status: <strong>Bypass</strong>
            </div>
            <div id="cspv-cf-test-log"></div>
        </div>

        <!-- 404 Tracking -->
        <div class="cspv-panel" style="margin-top:24px;">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,#7f1d1d,#dc2626);">
                <span>🚫 404 Error Log</span>
            </div>
            <div id="cspv-404-inner" style="padding:20px 24px;">
                <?php cspv_render_404_html(); ?>
            </div>
        </div>

        <!-- Site Health -->
        <div class="cspv-panel" style="margin-top:24px;">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,#065f46,#059669);">
                <span>🏥 Site Health</span>
            </div>
            <div style="padding:20px 24px;">
                <?php cspv_render_site_health_html( 'full' ); ?>
            </div>
        </div>

    </div><!-- /stats tab -->

    <!-- ═══════════════════════ DISPLAY TAB ═════════════════════════ -->
    <div id="cspv-tab-display" class="cspv-tab-content">

        <form method="post" action="">
            <?php wp_nonce_field( 'cspv_display_save', 'cspv_display_nonce' ); ?>

            <div class="cspv-section-header" style="background:linear-gradient(135deg,#2d1b69,#7c3aed);">
                <span>👁 View Counter Display <a class="cspv-info-btn" data-info="display-position" title="Info">i</a></span>
            </div>

            <!-- Position -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">📍 Display Position <a class="cspv-info-btn cspv-info-btn-dark" data-info="display-position" title="Info">i</a></h3>
                <div class="cspv-dsp-radios">
                    <label><input type="radio" name="cspv_auto_display" value="before_content" <?php checked( $dsp_position, 'before_content' ); ?>> Before post content</label>
                    <label><input type="radio" name="cspv_auto_display" value="after_content" <?php checked( $dsp_position, 'after_content' ); ?>> After post content</label>
                    <label><input type="radio" name="cspv_auto_display" value="both" <?php checked( $dsp_position, 'both' ); ?>> Both (before and after)</label>
                    <label><input type="radio" name="cspv_auto_display" value="off" <?php checked( $dsp_position, 'off' ); ?>> <strong>Off</strong> — hide view counter</label>
                </div>
            </div>

            <!-- Style -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">🎨 Counter Style <a class="cspv-info-btn cspv-info-btn-dark" data-info="display-style" title="Info">i</a></h3>
                <div class="cspv-dsp-styles">
                    <label class="cspv-dsp-style-card<?php echo esc_attr( $dsp_style === 'badge' ? ' active' : '' ); ?>">
                        <input type="radio" name="cspv_display_style" value="badge" <?php checked( $dsp_style, 'badge' ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#1a3a8f,#1e6fd9);color:#fff;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:700;">👁 1,234 <span style="opacity:.8;font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name">Badge</span>
                    </label>
                    <label class="cspv-dsp-style-card<?php echo esc_attr( $dsp_style === 'pill' ? ' active' : '' ); ?>">
                        <input type="radio" name="cspv_display_style" value="pill" <?php checked( $dsp_style, 'pill' ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:#f0f6ff;border:1px solid #d0dfff;color:#1a3a8f;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:600;">👁 1,234 <span style="color:#5a7abf;font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name">Pill</span>
                    </label>
                    <label class="cspv-dsp-style-card<?php echo esc_attr( $dsp_style === 'minimal' ? ' active' : '' ); ?>">
                        <input type="radio" name="cspv_display_style" value="minimal" <?php checked( $dsp_style, 'minimal' ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;color:#6b7280;font-size:12px;">👁 1,234 <span style="font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name">Minimal</span>
                    </label>
                </div>
            </div>

            <!-- Color -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">🎨 Badge Colour <a class="cspv-info-btn cspv-info-btn-dark" data-info="display-color" title="Info">i</a></h3>
                <div class="cspv-dsp-styles">
                    <?php
                    $color_map = array(
                        'blue'   => array( 'grad' => 'linear-gradient(135deg,#1a3a8f,#1e6fd9)', 'text' => '#fff', 'label' => 'Blue' ),
                        'pink'   => array( 'grad' => 'linear-gradient(135deg,#db2777,#f472b6)', 'text' => '#fff', 'label' => 'Pink' ),
                        'red'    => array( 'grad' => 'linear-gradient(135deg,#b91c1c,#ef4444)', 'text' => '#fff', 'label' => 'Red' ),
                        'purple' => array( 'grad' => 'linear-gradient(135deg,#6b21a8,#a855f7)', 'text' => '#fff', 'label' => 'Purple' ),
                        'grey'   => array( 'grad' => 'linear-gradient(135deg,#4b5563,#9ca3af)', 'text' => '#fff', 'label' => 'Grey' ),
                    );
                    foreach ( $color_map as $ckey => $cval ) : ?>
                    <label class="cspv-dsp-style-card<?php echo esc_attr( $dsp_color === $ckey ? ' active' : '' ); ?>">
                        <input type="radio" name="cspv_display_color" value="<?php echo esc_attr( $ckey ); ?>" <?php checked( $dsp_color, $ckey ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:<?php echo esc_attr( $cval['grad'] ); ?>;color:<?php echo esc_attr( $cval['text'] ); ?>;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:700;">👁 1,234 <span style="opacity:.8;font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name"><?php echo esc_html( $cval['label'] ); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Icon & Suffix -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">✏️ Customise Text <a class="cspv-info-btn cspv-info-btn-dark" data-info="display-text" title="Info">i</a></h3>
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#555;">Icon</label><br>
                        <input type="text" name="cspv_display_icon" value="<?php echo esc_attr( $dsp_icon ); ?>" style="width:60px;font-size:16px;text-align:center;margin-top:4px;">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#555;">Suffix</label><br>
                        <input type="text" name="cspv_display_suffix" value="<?php echo esc_attr( $dsp_suffix ); ?>" style="width:160px;margin-top:4px;" class="regular-text">
                    </div>
                </div>
            </div>

            <!-- Display Post Types -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">📄 Show Counter On <a class="cspv-info-btn cspv-info-btn-dark" data-info="display-types" title="Info">i</a></h3>
                <div class="cspv-dsp-checks">
                    <?php foreach ( $dsp_all_types as $pt ) :
                        if ( in_array( $pt->name, array( 'attachment' ), true ) ) continue;
                        $chk = in_array( $pt->name, $dsp_post_types, true );
                    ?>
                    <label><input type="checkbox" name="cspv_display_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( $chk ); ?>> <?php echo esc_html( $pt->labels->singular_name ); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Track Post Types -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;border-color:#ffd6a0;">
                <h3 style="margin:0 0 10px;font-size:14px;">🛡️ Tracking Filter <a class="cspv-info-btn cspv-info-btn-dark" data-info="tracking-filter" title="Info">i</a></h3>
                <p style="font-size:12px;color:#666;margin:0 0 10px;">Only record views on these post types. Unselected types will not record views.</p>
                <div class="cspv-dsp-checks">
                    <?php foreach ( $dsp_all_types as $pt ) :
                        if ( in_array( $pt->name, array( 'attachment' ), true ) ) continue;
                        $trk = in_array( $pt->name, $dsp_track_types, true );
                    ?>
                    <label><input type="checkbox" name="cspv_track_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( $trk ); ?>> <?php echo esc_html( $pt->labels->singular_name ); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Manual Integration -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">🔧 Manual Theme Integration</h3>
                <p style="font-size:12px;color:#666;margin:0 0 8px;">If position is set to <strong>Off</strong>, add this to your theme template:</p>
                <code style="display:block;background:#1e1e2e;color:#cdd6f4;padding:10px 14px;border-radius:6px;font-size:12px;">&lt;?php cspv_the_views(); ?&gt;</code>
            </div>

            <!-- Geography Source -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin:16px 0;">
                <h3 style="margin:0 0 10px;font-size:14px;">🌍 Geography Source</h3>
                <p style="font-size:12px;color:#666;margin:0 0 12px;">Choose how visitor country is resolved. CloudFlare provides the <code>CF-IPCountry</code> header automatically. DB-IP Lite uses a local database file for sites not behind CloudFlare.</p>
                <?php $geo_src = get_option( 'cspv_geo_source', 'auto' ); ?>
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">
                    <label style="font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="cspv_geo_source" value="auto" <?php checked( $geo_src, 'auto' ); ?>>
                        <strong>Auto</strong> <span style="color:#666;">(try CloudFlare first, fall back to DB-IP)</span>
                    </label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="cspv_geo_source" value="cloudflare" <?php checked( $geo_src, 'cloudflare' ); ?>>
                        <strong>CloudFlare Only</strong> <span style="color:#666;">(requires site behind CloudFlare)</span>
                    </label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="cspv_geo_source" value="dbip" <?php checked( $geo_src, 'dbip' ); ?>>
                        <strong>DB-IP Only</strong> <span style="color:#666;">(local database lookup)</span>
                    </label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="radio" name="cspv_geo_source" value="disabled" <?php checked( $geo_src, 'disabled' ); ?>>
                        <strong>Disabled</strong> <span style="color:#666;">(no geography tracking)</span>
                    </label>
                </div>
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <div>
                            <strong style="font-size:13px;">DB-IP Lite Database</strong><br>
                            <?php
                            $mmdb_dir  = WP_CONTENT_DIR . '/uploads/cspv-geo';
                            $mmdb_path = $mmdb_dir . '/dbip-city-lite.mmdb';
                            $mmdb_last = get_option( 'cspv_dbip_last_updated', '' );
                            if ( file_exists( $mmdb_path ) ) {
                                $mmdb_size = size_format( filesize( $mmdb_path ) );
                                echo '<span style="font-size:12px;color:#059669;">✅ Installed (' . esc_html( $mmdb_size ) . ')';
                                if ( $mmdb_last ) {
                                    echo ' &mdash; updated ' . esc_html( wp_date( 'j M Y', strtotime( $mmdb_last ) ) );
                                }
                                echo '</span>';
                            } else {
                                echo '<span style="font-size:12px;color:#dc2626;">❌ Not installed</span>';
                            }
                            $auto_update_on = get_option( 'cspv_dbip_auto_update', 'yes' ) === 'yes';
                            $next_cron      = wp_next_scheduled( 'cspv_dbip_auto_update' );
                            if ( $auto_update_on && $next_cron ) {
                                echo '<br><span style="font-size:11px;color:#6b7280;">Next auto-check: ' . esc_html( wp_date( 'j M Y H:i', $next_cron ) ) . '</span>';
                            }
                            ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                            <button type="button" id="cspv-download-dbip" style="background:#0f766e;color:#fff;border:none;padding:6px 16px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
                                <?php echo esc_html( file_exists( $mmdb_path ) ? '🔄 Update DB-IP Lite' : '⬇️ Download DB-IP Lite' ); ?>
                            </button>
                            <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;">
                                <input type="checkbox" name="cspv_dbip_auto_update" value="yes" <?php checked( get_option( 'cspv_dbip_auto_update', 'yes' ), 'yes' ); ?>>
                                Auto-update monthly
                            </label>
                        </div>
                    </div>
                    <div id="cspv-dbip-status" style="font-size:11px;color:#666;margin-top:6px;"></div>
                </div>
            </div>

            <p><button type="submit" style="background:linear-gradient(135deg,#2d1b69,#7c3aed);color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;">💾 Save Display Settings</button></p>
        </form>

        <!-- Data Management -->
        <div style="margin-top:24px;">
            <div class="cspv-section-header" style="color:#fff;background:linear-gradient(135deg,#7c3aed,#a855f7);border-radius:6px 6px 0 0;">
                <span>🗑 Data Management</span>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 6px 6px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:200px;">
                        <strong style="font-size:13px;">Purge Visitor Hashes</strong><br>
                        <span style="font-size:12px;color:#666;">Remove unique visitor tracking data older than a specified number of days. This frees storage but removes historical unique visitor counts.</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <label style="font-size:12px;color:#666;">Older than</label>
                        <select id="cspv-purge-days" style="padding:4px 28px 4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">1 year</option>
                            <option value="0">All data</option>
                        </select>
                        <button type="button" id="cspv-purge-visitors" style="background:#dc2626;color:#fff;border:none;padding:6px 16px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">🗑 Purge</button>
                    </div>
                </div>
                <div id="cspv-purge-status" style="font-size:11px;color:#666;margin-top:8px;"></div>
                <?php
                global $wpdb;
                $vis_table = $wpdb->prefix . 'cs_analytics_visitors_v2';
                $vis_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vis_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                if ( $vis_exists ) {
                    $vis_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$vis_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    $vis_unique = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT visitor_hash) FROM `{$vis_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    $vis_min = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$vis_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    $vis_max = $wpdb->get_var( "SELECT MAX(viewed_at) FROM `{$vis_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    echo '<div style="font-size:11px;color:#888;margin-top:4px;">'
                       . 'Visitor table: ' . number_format( $vis_rows ) . ' rows, '
                       . number_format( $vis_unique ) . ' unique hashes';
                    if ( $vis_min ) {
                        echo ' (' . esc_html( $vis_min ) . ' to ' . esc_html( $vis_max ) . ')';
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>

    </div><!-- /display tab -->

    <!-- ═══════════════════════ THROTTLE TAB ════════════════════════ -->
    <div id="cspv-tab-throttle" class="cspv-tab-content">

        <div id="cspv-throttle-inner">
            <div class="cspv-section-header cspv-section-header-red">
                <span>🛡 IP Throttle Protection <a class="cspv-info-btn" data-info="throttle" title="Info">i</a></span>
            </div>
            <div id="cspv-throttle-body">
                <p class="cspv-throttle-desc">Automatically block IPs that exceed the request threshold within a rolling window. Blocks auto-expire after <strong>1 hour</strong> — no manual cleanup needed. Blocked IPs receive HTTP 200 (silent drop) so attackers have no signal to change behaviour.</p>

                <div class="cspv-throttle-row">
                    <span class="cspv-throttle-label">Enable protection</span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-throttle-enabled" <?php checked( $throttle_enabled ); ?>>
                        <span class="cspv-toggle"></span>
                        <span id="cspv-toggle-label" class="cspv-toggle-text"><?php echo esc_html( $throttle_enabled ? 'Enabled' : 'Disabled' ); ?></span>
                    </label>
                </div>

                <div class="cspv-throttle-row">
                    <label class="cspv-throttle-label" for="cspv-throttle-limit">
                        Block after<br><small>Max requests per IP in window</small>
                    </label>
                    <div class="cspv-throttle-control">
                        <input type="number" id="cspv-throttle-limit" min="1" max="10000"
                               value="<?php echo esc_attr( $throttle_limit ); ?>">
                        <span class="cspv-unit">requests</span>
                    </div>
                </div>

                <div class="cspv-throttle-row">
                    <label class="cspv-throttle-label" for="cspv-throttle-window">
                        Time window<br><small>Rolling window for counting requests</small>
                    </label>
                    <div class="cspv-throttle-control">
                        <select id="cspv-throttle-window">
                            <option value="600"   <?php selected( $throttle_window, 600 ); ?>>10 minutes</option>
                            <option value="1800"  <?php selected( $throttle_window, 1800 ); ?>>30 minutes</option>
                            <option value="3600"  <?php selected( $throttle_window, 3600 ); ?>>1 hour</option>
                            <option value="7200"  <?php selected( $throttle_window, 7200 ); ?>>2 hours</option>
                            <option value="86400" <?php selected( $throttle_window, 86400 ); ?>>24 hours</option>
                        </select>
                    </div>
                </div>

                <div class="cspv-throttle-actions">
                    <button id="cspv-save-throttle" class="cspv-btn-primary">Save Settings</button>
                    <span id="cspv-save-status"></span>
                </div>
            </div>

            <!-- View Deduplication -->
            <div class="cspv-section-header" style="margin-top:24px;background:linear-gradient(135deg,#1a5276,#2980b9);">
                <span>🔁 View Deduplication <a class="cspv-info-btn" data-info="dedup" title="Info">i</a></span>
                <span class="cspv-ftb-status-pill <?php echo esc_attr( $dedup_enabled ? 'cspv-ftb-on' : 'cspv-ftb-off' ); ?>" id="cspv-dedup-status">
                    <?php echo esc_html( $dedup_enabled ? 'DEDUP ON' : 'DEDUP OFF' ); ?>
                </span>
            </div>
            <div id="cspv-dedup-body" style="background:#fff;padding:16px 24px 20px;border:1.5px solid #dce3ef;border-top:none;border-radius:0 0 8px 8px;">
                <p class="cspv-throttle-desc">Prevents the same visitor from inflating view counts by visiting the same post multiple times. Works at two levels: client side (localStorage, per browser) and server side (IP + post ID lookup in the database). Catches duplicate views from in app browsers like WhatsApp opening a link and then the user opening it again in Chrome.</p>

                <div class="cspv-throttle-row">
                    <span class="cspv-throttle-label">Enable deduplication</span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-dedup-enabled" <?php checked( $dedup_enabled ); ?>>
                        <span class="cspv-toggle"></span>
                        <span id="cspv-dedup-toggle-label" class="cspv-toggle-text"><?php echo esc_html( $dedup_enabled ? 'Enabled' : 'Disabled' ); ?></span>
                    </label>
                </div>

                <div class="cspv-throttle-row">
                    <label class="cspv-throttle-label" for="cspv-dedup-window">
                        Dedup window<br><small>Same IP + same post ignored within this period</small>
                    </label>
                    <div class="cspv-throttle-control">
                        <select id="cspv-dedup-window">
                            <option value="3600"   <?php selected( $dedup_window, 3600 ); ?>>1 hour</option>
                            <option value="7200"   <?php selected( $dedup_window, 7200 ); ?>>2 hours</option>
                            <option value="21600"  <?php selected( $dedup_window, 21600 ); ?>>6 hours</option>
                            <option value="43200"  <?php selected( $dedup_window, 43200 ); ?>>12 hours</option>
                            <option value="86400"  <?php selected( $dedup_window, 86400 ); ?>>24 hours</option>
                            <option value="172800" <?php selected( $dedup_window, 172800 ); ?>>48 hours</option>
                        </select>
                    </div>
                </div>

                <div class="cspv-throttle-actions">
                    <button id="cspv-save-dedup" class="cspv-btn-primary">Save Dedup Settings</button>
                    <span id="cspv-dedup-save-status"></span>
                </div>
            </div>

            <!-- Blocklist -->
            <div class="cspv-section-header cspv-section-header-red" style="margin-top:24px;">
                <span>Blocked IPs <a class="cspv-info-btn" data-info="blocklist" title="Info">i</a> <span class="cspv-badge-count"><?php echo (int) count( $blocklist ); ?></span></span>
                <?php if ( ! empty( $blocklist ) ) : ?>
                <button id="cspv-clear-blocklist" class="cspv-btn-danger-sm">Clear All</button>
                <?php endif; ?>
            </div>
            <div id="cspv-blocklist-body">
                <?php if ( empty( $blocklist ) ) : ?>
                    <p class="cspv-empty">No IPs currently blocked.</p>
                <?php else : ?>
                    <p class="cspv-blocklist-note">Stored as one-way SHA-256 hashes — cannot be reversed to a real IP.</p>
                    <?php foreach ( $blocklist as $hash => $data ) :
                        $at      = isset( $data['blocked_at'] ) ? $data['blocked_at'] : '—';
                        $expires = isset( $data['expires'] ) ? $data['expires'] : 0;
                        $mins    = $expires > 0 ? max( 0, (int) round( ( $expires - time() ) / 60 ) ) : 0;
                        $exp_lbl = $mins > 0 ? 'expires in ' . $mins . 'm' : 'expired';
                    ?>
                    <div class="cspv-block-row" id="cspv-row-<?php echo esc_attr( $hash ); ?>">
                        <span class="cspv-hash"><?php echo esc_html( $hash ); ?></span>
                        <span class="cspv-block-at"><?php echo esc_html( $at ); ?> <em style="color:#f47c20;font-style:normal;">(<?php echo esc_html( $exp_lbl ); ?>)</em></span>
                        <button class="cspv-btn-unblock cspv-unblock-btn" data-hash="<?php echo esc_attr( $hash ); ?>">Unblock</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════ EMERGENCY TRACKING PAUSE ═══════════════════════ -->
        <div style="margin-top:24px;background:#fff;border:2px solid <?php echo esc_attr( $tracking_paused ? '#fecaca' : '#dce3ef' ); ?>;border-radius:8px;overflow:hidden;" id="cspv-pause-wrapper">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,<?php echo esc_attr( $tracking_paused ? '#991b1b,#dc2626' : '#374151,#6b7280' ); ?>);" id="cspv-pause-header">
                <span>⏸ Page Tracking <a class="cspv-info-btn" data-info="tracking-pause" title="Info">i</a></span>
                <span class="cspv-ftb-status-pill <?php echo esc_attr( $tracking_paused ? 'cspv-ftb-on' : 'cspv-ftb-off' ); ?>" id="cspv-pause-status" style="<?php echo esc_attr( $tracking_paused ? 'background:rgba(255,255,255,.3);' : '' ); ?>">
                    <?php echo esc_html( $tracking_paused ? '⏸ TRACKING PAUSED' : '● TRACKING ACTIVE' ); ?>
                </span>
            </div>
            <div style="padding:20px 24px;">
                <p class="cspv-throttle-desc" style="margin-bottom:16px;">Emergency kill switch. When paused, the tracking script is not loaded on any page and the recording API silently rejects all requests. Use this to instantly stop all view tracking during an attack. Historical data is preserved.</p>
                <div class="cspv-throttle-row" style="border-bottom:none;">
                    <span class="cspv-throttle-label">Pause all tracking<br><small>Stops tracking + API recording immediately</small></span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-tracking-paused" <?php checked( $tracking_paused ); ?>>
                        <span class="cspv-toggle" style="<?php echo esc_attr( $tracking_paused ? 'background:#dc2626;' : '' ); ?>" id="cspv-pause-toggle"></span>
                        <span id="cspv-pause-label" class="cspv-toggle-text" style="<?php echo esc_attr( $tracking_paused ? 'color:#dc2626;' : '' ); ?>"><?php echo esc_html( $tracking_paused ? 'Paused' : 'Active' ); ?></span>
                    </label>
                </div>
                <div class="cspv-throttle-actions" style="margin-top:8px;">
                    <button id="cspv-save-pause" class="cspv-btn-primary">Save</button>
                    <span id="cspv-pause-save-status"></span>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════ FAIL2BAN SECTION ═══════════════════════ -->
        <div id="cspv-ftb-inner" style="margin-top:24px;">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,#b91c1c,#dc2626);">
                <span>🔥 Fail2Ban Protection <a class="cspv-info-btn" data-info="ftb" title="Info">i</a></span>
                <span class="cspv-ftb-status-pill <?php echo esc_attr( $ftb_enabled ? 'cspv-ftb-on' : 'cspv-ftb-off' ); ?>" id="cspv-ftb-status-pill">
                    <?php echo esc_html( $ftb_enabled ? '● FTB ACTIVE' : '○ FTB OFF' ); ?>
                </span>
            </div>
            <div id="cspv-ftb-body">
                <p class="cspv-throttle-desc">Second tier protection. When an IP exceeds the configurable page limit within the throttle time window, it is blocked for <strong>2 hours</strong> (auto clears). Unlike tier 1 throttle (1 hour), FTB gives a longer cooling off period for persistent abusers.</p>

                <div class="cspv-throttle-row">
                    <span class="cspv-throttle-label">Enable Fail2Ban</span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-ftb-enabled" <?php checked( $ftb_enabled ); ?>>
                        <span class="cspv-toggle"></span>
                        <span id="cspv-ftb-toggle-label" class="cspv-toggle-text"><?php echo esc_html( $ftb_enabled ? 'Enabled' : 'Disabled' ); ?></span>
                    </label>
                </div>

                <div class="cspv-throttle-row">
                    <label class="cspv-throttle-label" for="cspv-ftb-page-limit">
                        Page limit<br><small>Block for 2 hours after this many pages in window</small>
                    </label>
                    <div class="cspv-throttle-control">
                        <input type="number" id="cspv-ftb-page-limit" min="1" max="100000"
                               value="<?php echo esc_attr( $ftb_page_limit ); ?>">
                        <span class="cspv-unit">pages</span>
                    </div>
                </div>

                <div class="cspv-throttle-actions">
                    <button id="cspv-save-ftb" class="cspv-btn-primary">Save FTB Settings</button>
                    <button id="cspv-test-ftb" class="cspv-btn-primary" style="background:linear-gradient(135deg,#92400e,#d97706);">🧪 Test Fail2Ban</button>
                    <span id="cspv-ftb-save-status"></span>
                </div>
                <div id="cspv-ftb-test-results" style="display:none;margin-top:16px;background:#f8fafc;border:1.5px solid #dce3ef;border-radius:6px;padding:14px 18px;font-size:12px;line-height:1.9;"></div>
            </div>

            <!-- FTB Current Rules -->
            <div class="cspv-section-header" style="margin-top:24px;background:linear-gradient(135deg,#92400e,#d97706);">
                <span>📋 FTB Rules <a class="cspv-info-btn" data-info="ftb-rules" title="Info">i</a></span>
            </div>
            <div id="cspv-ftb-rules-body" style="padding:16px 24px;">
                <div class="cspv-ftb-rule-card">
                    <div class="cspv-ftb-rule-status <?php echo esc_attr( $ftb_rules['enabled'] ? 'cspv-ftb-active' : 'cspv-ftb-inactive' ); ?>">
                        <?php echo esc_html( $ftb_rules['enabled'] ? '● Active' : '○ Inactive' ); ?>
                    </div>
                    <div class="cspv-ftb-rule-summary" id="cspv-ftb-rule-summary">
                        <?php echo esc_html( $ftb_rules['summary'] ); ?>
                    </div>
                    <div class="cspv-ftb-rule-details">
                        <span>Page limit: <strong><?php echo esc_html( number_format( $ftb_rules['page_limit'] ) ); ?></strong></span>
                        <span>Window: <strong><?php echo esc_html( $ftb_rules['window_label'] ); ?></strong></span>
                        <span>Block duration: <strong>2 hours (auto clear)</strong></span>
                    </div>
                </div>
            </div>

            <!-- FTB Blocked IPs -->
            <div class="cspv-section-header" style="margin-top:24px;background:linear-gradient(135deg,#b91c1c,#dc2626);">
                <span>🚫 FTB Blocked IPs <a class="cspv-info-btn" data-info="ftb-blocklist" title="Info">i</a> <span class="cspv-badge-count"><?php echo count( $ftb_blocklist ); ?></span></span>
                <?php if ( ! empty( $ftb_blocklist ) ) : ?>
                <button id="cspv-ftb-clear-blocklist" class="cspv-btn-danger-sm">Clear FTB Blocks</button>
                <?php endif; ?>
            </div>
            <div id="cspv-ftb-blocklist-body">
                <?php if ( empty( $ftb_blocklist ) ) : ?>
                    <p class="cspv-empty">No IPs on the Fail2Ban blocklist.</p>
                <?php else : ?>
                    <p class="cspv-blocklist-note">Blocks auto clear after 2 hours. Remove individually or clear all to lift early.</p>
                    <?php foreach ( $ftb_blocklist as $hash => $data ) :
                        $at      = isset( $data['blocked_at'] ) ? $data['blocked_at'] : '—';
                        $expires = isset( $data['expires'] ) ? $data['expires'] : 0;
                        $mins    = $expires > 0 ? max( 0, (int) round( ( $expires - time() ) / 60 ) ) : 0;
                        $exp_lbl = $mins > 0 ? 'expires in ' . $mins . 'm' : 'expired';
                    ?>
                    <div class="cspv-block-row" id="cspv-ftb-row-<?php echo esc_attr( $hash ); ?>">
                        <span class="cspv-hash"><?php echo esc_html( $hash ); ?></span>
                        <span class="cspv-block-at"><?php echo esc_html( $at ); ?> <em style="color:#dc2626;font-style:normal;">(<?php echo esc_html( $exp_lbl ); ?>)</em></span>
                        <button class="cspv-btn-unblock cspv-ftb-unblock-btn" data-hash="<?php echo esc_attr( $hash ); ?>">Unblock</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════ CLEAR ALL IP ADDRESSES ═══════════════════════ -->
        <div style="margin-top:24px;background:#fff;border:2px solid #fecaca;border-radius:8px;overflow:hidden;">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,#7f1d1d,#991b1b);">
                <span>🗑 Clear IP Addresses <a class="cspv-info-btn" data-info="clear-all-ips" title="Info">i</a></span>
            </div>
            <div style="padding:20px 24px;">
                <p class="cspv-throttle-desc" style="margin-bottom:16px;">Remove <strong>all</strong> IP blocks and counters across both tiers. This clears throttle blocks (tier 1), Fail2Ban blocks (tier 2), all request counters, and all event logs. This action cannot be undone.</p>
                <button id="cspv-clear-all-ips" class="cspv-btn-danger" style="font-size:14px;padding:10px 24px;">🗑 Clear All IP Addresses</button>
                <span id="cspv-clear-all-status" style="margin-left:12px;font-size:12px;font-weight:700;"></span>
            </div>
        </div>

    </div><!-- /throttle tab -->

    <!-- ═══════════════════════ POST HISTORY TAB ═══════════════════ -->
    <div id="cspv-tab-history" class="cspv-tab-content">

        <div class="cspv-section-header" style="background:linear-gradient(135deg,#1a5276,#2e86c1);">
            <span>🔍 Post View History <a class="cspv-info-btn" data-info="post-history" title="Info">i</a></span>
        </div>

        <div style="padding:20px 24px;">

            <!-- Search bar with button -->
            <div style="display:flex;gap:8px;margin-bottom:16px;max-width:600px;">
                <input type="text" id="cspv-ph-search" placeholder="Search posts by title..." autocomplete="off"
                       style="flex:1;padding:10px 14px;border:2px solid #2e86c1;border-radius:6px;font-size:14px;">
                <button id="cspv-ph-search-btn" style="padding:10px 20px;background:linear-gradient(135deg,#1a5276,#2e86c1);
                    color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap;">Search Posts</button>
            </div>

            <!-- Post list -->
            <div id="cspv-ph-list" style="max-height:400px;overflow-y:auto;border:1px solid #e8ecf0;border-radius:8px;margin-bottom:20px;">
                <?php if ( empty( $ph_top_posts ) ) : ?>
                    <div style="padding:20px;text-align:center;color:#888;">No posts with views found.</div>
                <?php else : ?>
                    <!-- Column headers (sortable) -->
                    <div id="cspv-ph-header" style="display:flex;align-items:center;padding:4px 16px;background:#1a5276;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;position:sticky;top:0;z-index:1;">
                        <div class="cspv-ph-sort" data-col="title" style="flex:1;cursor:pointer;">Post ▼</div>
                        <div class="cspv-ph-sort" data-col="views" style="width:100px;text-align:right;cursor:pointer;">Total Views</div>
                    </div>
                    <?php foreach ( $ph_top_posts as $i => $p ) :
                        $views     = (int) get_post_meta( $p->ID, CSPV_META_KEY, true );
                        $bg = $i % 2 === 0 ? '#fff' : '#f8f9fa';
                    ?>
                    <div class="cspv-ph-row" data-id="<?php echo (int) $p->ID; ?>"
                         data-title="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>"
                         data-views="<?php echo esc_attr( (int) $views ); ?>"
                         data-url="<?php echo esc_attr( get_permalink( $p->ID ) ); ?>"
                         style="display:flex;align-items:center;
                        padding:2px 16px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background .1s;line-height:1.3;">
                        <div style="min-width:0;flex:1;font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo esc_html( $p->post_title ); ?> <span style="color:#aaa;font-weight:400;font-size:11px;"><?php echo esc_html( $p->post_type ); ?></span>
                            <a class="cspv-ph-view-link" href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" target="_blank" rel="noopener" style="color:#2e86c1;font-size:11px;font-weight:400;margin-left:6px;text-decoration:none;" title="View post">↗</a>
                        </div>
                        <div style="width:100px;text-align:right;font-weight:800;font-size:14px;color:#2e86c1;font-variant-numeric:tabular-nums;">
                            <?php echo esc_html( number_format( $views ) ); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

    </div><!-- /history tab -->

    <!-- ══════════════════ REFERRER DRILL MODAL ═════════════════════ -->
    <div class="cspv-modal-overlay" id="cspv-ref-drill-modal">
        <div class="cspv-modal" style="max-width:580px;">
            <div class="cspv-modal-header" style="background:linear-gradient(135deg,#c2410c,#ea580c);border-radius:12px 12px 0 0;">
                <h3 id="cspv-ref-drill-title" style="color:#fff;font-size:15px;"></h3>
                <div style="display:flex;align-items:center;gap:4px;">
                    <button class="cspv-modal-copy" id="cspv-ref-drill-copy" style="color:#fff;" title="Copy to clipboard">Copy</button>
                    <button class="cspv-modal-close" id="cspv-ref-drill-close" style="color:rgba(255,255,255,.8);">&times;</button>
                </div>
            </div>
            <div class="cspv-modal-body" style="padding:16px 0 8px;" id="cspv-ref-drill-list"></div>
        </div>
    </div>

    <!-- ═══════════════════════ INFO MODAL ══════════════════════════ -->
    <div class="cspv-modal-overlay" id="cspv-modal">
        <div class="cspv-modal">
            <div class="cspv-modal-header">
                <h3 id="cspv-modal-title"></h3>
                <button class="cspv-modal-close" id="cspv-modal-close">&times;</button>
            </div>
            <div class="cspv-modal-body" id="cspv-modal-body"></div>
        </div>
    </div>

    <!-- ═══════════════════════ HELP MODAL ══════════════════════════ -->
    <div class="cspv-modal-overlay" id="cspv-help-modal">
        <div class="cspv-modal" style="max-width:680px;">
            <div class="cspv-modal-header" style="background:#1a2332;border-radius:12px 12px 0 0;">
                <h3 id="cspv-help-modal-title" style="color:#fff;text-transform:uppercase;letter-spacing:.5px;"></h3>
                <button class="cspv-modal-close" id="cspv-help-modal-close" style="color:rgba(255,255,255,.7);">&times;</button>
            </div>
            <div class="cspv-modal-body" id="cspv-help-modal-body" style="padding:24px;"></div>
            <div style="padding:0 24px 20px;display:flex;align-items:center;justify-content:space-between;">
                <a href="https://your-wordpress-site.example.com/wordpress-plugin-help/analytics-help/" target="_blank" rel="noopener" style="font-size:13px;color:#4a9eff;text-decoration:none;">&#x1F4D6; Full documentation</a>
                <button id="cspv-help-modal-ok" class="cspv-btn-primary" style="padding:8px 28px;">Got it</button>
            </div>
        </div>
    </div>

</div><!-- /#cspv-app -->

<?php
// CSS is enqueued via cspv_enqueue_admin_assets() → assets/css/stats-page.css
wp_add_inline_script( 'cspv-stats-page', 'var cspvStats=' . wp_json_encode( array(
    'ajaxUrl'       => $ajax_url,
    'nonce'         => $ajax_nonce,
    'throttleNonce' => $throttle_nonce,
) ) . ';' );
ob_start();
?>
(function () {
    'use strict';

    var ajaxUrl      = cspvStats.ajaxUrl;
    var nonce        = cspvStats.nonce;
    var throttleNonce = cspvStats.throttleNonce;
    var chartInst    = null;

    // ── Tab switching ──────────────────────────────────────────────
    document.querySelectorAll('.cspv-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.cspv-tab').forEach(function(b){ b.classList.remove('active'); });
            document.querySelectorAll('.cspv-tab-content').forEach(function(c){ c.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('cspv-tab-' + btn.dataset.tab).classList.add('active');
        });
    });

    // ── Display tab: style card toggles ─────────────────────────────
    document.querySelectorAll('.cspv-dsp-style-card').forEach(function(card) {
        card.addEventListener('click', function() {
            document.querySelectorAll('.cspv-dsp-style-card').forEach(function(c){ c.classList.remove('active'); });
            card.classList.add('active');
            var radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        });
    });

    // ── Date helpers ───────────────────────────────────────────────
    function wpToday() {
        // Use the server-side date to avoid UTC vs local timezone issues
        return <?php echo wp_json_encode( $today ); ?>;
    }
    function daysAgo(n) {
        // Parse the server today date and subtract days
        var parts = wpToday().split('-');
        var d = new Date( parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]) );
        d.setDate( d.getDate() - n );
        var mm = String(d.getMonth()+1).padStart(2,'0');
        var dd = String(d.getDate()).padStart(2,'0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }
    function fmtDate(s) {
        var p = s.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return parseInt(p[2]) + ' ' + months[parseInt(p[1])-1] + ' ' + p[0];
    }

    // ── Quick buttons (with localStorage persistence) ─────────────
    document.querySelectorAll('.cspv-quick').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.cspv-quick').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            var r = btn.dataset.range;
            var t = wpToday();
            document.getElementById('cspv-to').value   = t;
            if (r === '12h' || r === 'today') {
                document.getElementById('cspv-from').value = t;
            } else {
                document.getElementById('cspv-from').value = daysAgo(parseInt(r) - 1);
            }
            try { localStorage.setItem('cspv_date_range', r); } catch(e) {}
            loadData();
        });
    });

    document.getElementById('cspv-apply').addEventListener('click', function() {
        document.querySelectorAll('.cspv-quick').forEach(function(b){ b.classList.remove('active'); });
        try {
            localStorage.setItem('cspv_date_range', 'custom');
            localStorage.setItem('cspv_date_from', document.getElementById('cspv-from').value);
            localStorage.setItem('cspv_date_to', document.getElementById('cspv-to').value);
        } catch(e) {}
        loadData();
    });

    // ── Load data ──────────────────────────────────────────────────
    window.loadData = function() { loadData(); }; // expose for external callers

    function loadData() {
        var from = document.getElementById('cspv-from').value;
        var to   = document.getElementById('cspv-to').value;

        // Auto swap if from > to
        if (from > to) {
            var tmp = from; from = to; to = tmp;
            document.getElementById('cspv-from').value = from;
            document.getElementById('cspv-to').value   = to;
        }

        // Reset UI
        document.getElementById('cspv-chart-msg').classList.remove('hidden');
        document.getElementById('cspv-chart-msg').textContent = 'Loading…';
        document.getElementById('stat-delta').textContent = '—';
        document.getElementById('stat-views-detail').textContent = '';
        document.getElementById('stat-posts-delta').textContent = '—';
        document.getElementById('stat-posts-detail').textContent = '';
        document.getElementById('stat-visitors-delta').textContent = '—';
        document.getElementById('stat-visitors-detail').textContent = '';
        document.getElementById('stat-hotpages-delta').textContent = '—';
        document.getElementById('stat-hotpages-detail').textContent = '';
        document.getElementById('cspv-top-posts').innerHTML   = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('cspv-referrers').innerHTML   = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('cspv-lifetime-top').innerHTML = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('stat-lifetime-views').textContent = '—';
        document.getElementById('cspv-chart-range-label').textContent = '';

        var fd = new FormData();
        fd.append('action',    'cspv_chart_data');
        fd.append('nonce',     nonce);
        fd.append('date_from', from);
        fd.append('date_to',   to);
        // If "Last 24h" is active, tell the server to use rolling window
        var todayBtn = document.querySelector('.cspv-quick[data-range="today"]');
        if (todayBtn && todayBtn.classList.contains('active') && from === to) {
            fd.append('rolling24h', '1');
        }
        // If "12 Hours" is active, tell the server
        var twelveHBtn = document.querySelector('.cspv-quick[data-range="12h"]');
        if (twelveHBtn && twelveHBtn.classList.contains('active')) {
            fd.append('rolling12h', '1');
        }

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function(resp) {
                if (!resp || !resp.success) {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Server error.';
                    showError(msg);
                    return;
                }
                renderAll(resp.data, from, to);
            })
            .catch(function(err) {
                showError('Could not load data: ' + err.message);
            });
    }

    function showError(msg) {
        document.getElementById('cspv-chart-msg').textContent = '⚠ ' + msg;
        document.getElementById('cspv-top-posts').innerHTML = '<div class="cspv-empty">No data.</div>';
        document.getElementById('cspv-referrers').innerHTML  = '<div class="cspv-empty">No data.</div>';
        document.getElementById('cspv-lifetime-top').innerHTML = '<div class="cspv-empty">No data.</div>';
    }

    // ── Referrer data + toggle state ──────────────────────────────
    var lastRefSites = [];
    var lastRefPages = [];
    var refMode      = 'sites';

    // ── Render ─────────────────────────────────────────────────────
    function renderAll(data, from, to) {
        // Range label
        var lbl = (from === to) ? fmtDate(from) : fmtDate(from) + ' – ' + fmtDate(to);
        document.getElementById('cspv-chart-range-label').textContent = lbl;

        // Cards

        // Posts Viewed card: percentage as hero, counts as detail line
        var postsDeltaEl  = document.getElementById('stat-posts-delta');
        var postsDetailEl = document.getElementById('stat-posts-detail');
        if (data.prev_posts > 0) {
            var postsPct   = Math.round(((data.unique_posts - data.prev_posts) / data.prev_posts) * 100);
            var postsArrow = postsPct > 0 ? '↑' : (postsPct < 0 ? '↓' : '–');
            var postsCls   = postsPct > 0 ? 'cspv-delta-up' : (postsPct < 0 ? 'cspv-delta-down' : 'cspv-delta-same');
            var postsAbsPct = Math.abs(postsPct);
            var postsDisplay = postsAbsPct > 999 ? '>999' : postsAbsPct;
            postsDeltaEl.textContent = postsArrow + ' ' + postsDisplay + '%';
            postsDeltaEl.className   = 'cspv-card-value ' + postsCls;
            postsDetailEl.textContent = data.unique_posts.toLocaleString() + ' (was ' + data.prev_posts.toLocaleString() + ')';
        } else {
            postsDeltaEl.textContent = data.unique_posts.toLocaleString();
            postsDeltaEl.className   = 'cspv-card-value';
            postsDetailEl.textContent = '';
        }

        // Unique Visitors card: percentage as hero, counts as detail line
        var visDeltaEl  = document.getElementById('stat-visitors-delta');
        var visDetailEl = document.getElementById('stat-visitors-detail');
        if (data.prev_visitors > 0) {
            var visPct   = Math.round(((data.unique_visitors - data.prev_visitors) / data.prev_visitors) * 100);
            var visArrow = visPct > 0 ? '↑' : (visPct < 0 ? '↓' : '–');
            var visCls   = visPct > 0 ? 'cspv-delta-up' : (visPct < 0 ? 'cspv-delta-down' : 'cspv-delta-same');
            var visAbsPct = Math.abs(visPct);
            var visDisplay = visAbsPct > 999 ? '>999' : visAbsPct;
            visDeltaEl.textContent = visArrow + ' ' + visDisplay + '%';
            visDeltaEl.className   = 'cspv-card-value ' + visCls;
            visDetailEl.textContent = data.unique_visitors.toLocaleString() + ' (was ' + data.prev_visitors.toLocaleString() + ')';
        } else {
            visDeltaEl.textContent = data.unique_visitors.toLocaleString();
            visDeltaEl.className   = 'cspv-card-value';
            visDetailEl.textContent = '';
        }

        // Hot Pages card: percentage as hero, counts as detail line
        var hotDeltaEl  = document.getElementById('stat-hotpages-delta');
        var hotDetailEl = document.getElementById('stat-hotpages-detail');
        if (data.prev_hot_pages > 0) {
            var hotPct   = Math.round(((data.hot_pages - data.prev_hot_pages) / data.prev_hot_pages) * 100);
            var hotArrow = hotPct > 0 ? '↑' : (hotPct < 0 ? '↓' : '–');
            var hotCls   = hotPct > 0 ? 'cspv-delta-up' : (hotPct < 0 ? 'cspv-delta-down' : 'cspv-delta-same');
            var hotAbsPct = Math.abs(hotPct);
            var hotDisplay = hotAbsPct > 999 ? '>999' : hotAbsPct;
            hotDeltaEl.textContent = hotArrow + ' ' + hotDisplay + '%';
            hotDeltaEl.className   = 'cspv-card-value ' + hotCls;
            hotDetailEl.textContent = data.hot_pages.toLocaleString() + ' pages (was ' + data.prev_hot_pages.toLocaleString() + ')';
        } else {
            hotDeltaEl.textContent = data.hot_pages.toLocaleString();
            hotDeltaEl.className   = 'cspv-card-value';
            hotDetailEl.textContent = '';
        }

        // Views card: percentage as hero, counts as detail line
        var deltaEl  = document.getElementById('stat-delta');
        var detailEl = document.getElementById('stat-views-detail');
        if (data.prev_total > 0) {
            var pct   = Math.round(((data.total_views - data.prev_total) / data.prev_total) * 100);
            var arrow = pct > 0 ? '↑' : (pct < 0 ? '↓' : '–');
            var cls   = pct > 0 ? 'cspv-delta-up' : (pct < 0 ? 'cspv-delta-down' : 'cspv-delta-same');
            var absPct = Math.abs(pct);
            var pctDisplay = absPct > 999 ? '>999' : absPct;
            deltaEl.textContent = arrow + ' ' + pctDisplay + '%';
            deltaEl.className   = 'cspv-card-value ' + cls;
            detailEl.textContent = data.total_views.toLocaleString() + ' (was ' + data.prev_total.toLocaleString() + ')';
        } else {
            deltaEl.textContent = data.total_views.toLocaleString();
            deltaEl.className   = 'cspv-card-value';
            detailEl.textContent = '';
        }

        // Render lists BEFORE chart so they always appear even if Chart.js
        // has not loaded yet (fixes blank page on initial Tools menu load)
        renderList('cspv-top-posts', data.top_posts, true);

        // Store referrer data for toggle switching
        lastRefSites = data.referrers || [];
        lastRefPages = data.referrer_pages || [];
        renderReferrers();

        // Geography
        renderGeo(data.countries || [], from, to, data.geo_source || 'auto', data.geo_source_actual || '');

        // Session depth percentiles
        renderDepth(data.session_depth || null, data.prev_session_depth || null, from, to);

        // Lifetime totals
        document.getElementById('stat-lifetime-views').textContent =
            (data.lifetime_total || 0).toLocaleString();
        document.getElementById('stat-lifetime-visitors').textContent =
            (data.lifetime_visitors || 0).toLocaleString();
        renderList('cspv-lifetime-top', data.lifetime_top || [], true);

        // Chart last — wrapped in try/catch so a Chart.js load failure
        // does not prevent the rest of the page from rendering
        try {
            renderChart(data.chart, data.label_fmt, data.total_views);
        } catch(e) {
            // Chart.js may not have loaded yet; retry once after a short delay
            setTimeout(function() {
                try { renderChart(data.chart, data.label_fmt, data.total_views); } catch(e2) {}
            }, 1000);
        }
    }

    // ── Chart ──────────────────────────────────────────────────────
    function renderChart(rows, labelFmt, total) {
        document.getElementById('cspv-chart-msg').classList.add('hidden');

        if (!rows || rows.length === 0) {
            var msg = document.getElementById('cspv-chart-msg');
            msg.textContent = 'No views recorded in this date range.';
            msg.classList.remove('hidden');
            if (chartInst) { chartInst.destroy(); chartInst = null; }
            return;
        }
        // total===0 is fine — render empty bars so the x-axis dates always show

        var labels = rows.map(function(r){ return String(r.period); });
        var values = rows.map(function(r){ return parseInt(r.views, 10) || 0; });

        if (chartInst) { chartInst.destroy(); }

        var ctx = document.getElementById('cspv-chart').getContext('2d');
        chartInst = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Views',
                    data: values,
                    backgroundColor: function(context) {
                        var chart = context.chart;
                        var ctx2  = chart.ctx;
                        var gradient = ctx2.createLinearGradient(0, 0, 0, 220);
                        gradient.addColorStop(0,   '#1e6fd9');
                        gradient.addColorStop(1,   '#0fb8e0');
                        return gradient;
                    },
                    borderRadius: 4,
                    borderSkipped: false,
                    hoverBackgroundColor: '#6db8f5',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1a2d6b',
                        titleColor: 'rgba(255,255,255,.7)',
                        bodyColor: '#fff',
                        bodyFont: { size: 13, weight: '700' },
                        padding: 10,
                        callbacks: {
                            label: function(c) { return ' ' + c.parsed.y.toLocaleString() + ' views'; }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#7a8aaa', font: { size: 11 }, maxTicksLimit: 14 }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: Math.max(1, Math.max.apply(null, values)),
                        grid: { color: '#f0f4ff' },
                        ticks: { color: '#7a8aaa', font: { size: 11 }, precision: 0 }
                    }
                }
            }
        });
    }

    // ── Post / referrer lists ──────────────────────────────────────
    function renderList(elId, items, isPost) {
        var el = document.getElementById(elId);
        if (!items || items.length === 0) {
            el.innerHTML = '<div class="cspv-empty">' + (isPost
                ? 'No posts viewed in this period.'
                : 'No referrers recorded in this period.') + '</div>';
            return;
        }
        var max = items[0].views || 1;
        el.innerHTML = items.map(function(item) {
            var pct   = Math.round((item.views / max) * 100);
            var label = isPost
                ? (item.url
                    ? '<a href="' + esc(item.url) + '" target="_blank">' + esc(item.title || item.url) + '</a>'
                    : esc(item.title))
                : esc(item.host);
            var drillBtn = isPost ? '' : '<button class="cspv-ref-drill-btn" data-host="' + esc(item.host) + '">Details</button>';
            return '<div class="cspv-row">'
                 + '<div class="cspv-bar-wrap">'
                 +   '<div class="cspv-bar-fill" style="width:' + pct + '%"></div>'
                 +   '<span class="cspv-bar-label">' + label + '</span>'
                 + '</div>'
                 + drillBtn
                 + '<span class="cspv-row-views">' + item.views.toLocaleString() + '</span>'
                 + '</div>';
        }).join('');
    }

    function esc(s) {
        var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML;
    }

    // ── Referrer rendering (sites vs pages toggle) ────────────────
    function renderReferrers() {
        var el = document.getElementById('cspv-referrers');
        if (refMode === 'pages') {
            renderRefPages(el, lastRefPages);
        } else {
            renderList('cspv-referrers', lastRefSites, false);
        }
    }

    function renderRefPages(el, items) {
        if (!items || items.length === 0) {
            el.innerHTML = '<div class="cspv-empty">No referrer pages recorded in this period.</div>';
            return;
        }
        var max = items[0].views || 1;
        el.innerHTML = items.map(function(item) {
            var pct  = Math.round((item.views / max) * 100);
            // Show the path after the host, or the full URL if parsing fails
            var display = item.url;
            try {
                var u = new URL(item.url);
                display = u.hostname + u.pathname + u.search;
                // Trim trailing slash for cleanliness
                if (display.slice(-1) === '/') { display = display.slice(0, -1); }
            } catch(e) {}
            return '<div class="cspv-row">'
                 + '<div class="cspv-bar-wrap">'
                 +   '<div class="cspv-bar-fill" style="width:' + pct + '%"></div>'
                 +   '<span class="cspv-bar-label"><a href="' + esc(item.url) + '" target="_blank" title="' + esc(item.url) + '">' + esc(display) + '</a></span>'
                 + '</div>'
                 + '<span class="cspv-row-views">' + item.views.toLocaleString() + '</span>'
                 + '</div>';
        }).join('');
    }

    // Wire up sites/pages toggle buttons
    document.querySelectorAll('.cspv-ref-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.cspv-ref-toggle').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            refMode = btn.dataset.refMode;
            renderReferrers();
        });
    });

    // ── Modal helpers (body scroll lock) ─────────────────────────
    function openModal(el) {
        el.classList.add('active');
        document.body.classList.add('cspv-modal-open');
    }
    function closeModal(el) {
        el.classList.remove('active');
        if (!document.querySelector('.cspv-modal-overlay.active')) {
            document.body.classList.remove('cspv-modal-open');
        }
    }

    // ── Referrer drill modal ──────────────────────────────────────
    function closeRefDrillModal() {
        closeModal(document.getElementById('cspv-ref-drill-modal'));
    }

    function drillReferrer(host) {
        var modal   = document.getElementById('cspv-ref-drill-modal');
        var titleEl = document.getElementById('cspv-ref-drill-title');
        var listEl  = document.getElementById('cspv-ref-drill-list');
        var fromVal = document.getElementById('cspv-from').value;
        var toVal   = document.getElementById('cspv-to').value;
        titleEl.textContent = host + ' \u2014 Top Pages';
        listEl.innerHTML = '<div class="cspv-loading" style="padding:20px 20px 12px;">Loading\u2026</div>';
        openModal(modal);
        var fd = new FormData();
        fd.append('action', 'cspv_referrer_drill');
        fd.append('nonce', nonce);
        fd.append('host', host);
        fd.append('from', fromVal);
        fd.append('to', toVal);
        var todayBtn2 = document.querySelector('.cspv-quick[data-range="today"]');
        if (todayBtn2 && todayBtn2.classList.contains('active') && fromVal === toVal) {
            fd.append('rolling24h', '1');
        }
        fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success && resp.data && resp.data.pages) {
                    if (resp.data.pages.length === 0) {
                        listEl.innerHTML = '<div class="cspv-empty" style="padding:20px;">No page data for this referrer.</div>';
                        return;
                    }
                    var mx = resp.data.pages[0].views || 1;
                    listEl.innerHTML = resp.data.pages.map(function(p) {
                        var pct  = Math.round((p.views / mx) * 100);
                        var link = p.url
                            ? '<a href="' + esc(p.url) + '" target="_blank">' + esc(p.title) + '</a>'
                            : esc(p.title);
                        return '<div class="cspv-row">'
                             + '<div class="cspv-bar-wrap">'
                             +   '<div class="cspv-bar-fill" style="width:' + pct + '%;background:#fff3e8;"></div>'
                             +   '<span class="cspv-bar-label">' + link + '</span>'
                             + '</div>'
                             + '<span class="cspv-row-views">' + p.views.toLocaleString() + '</span>'
                             + '</div>';
                    }).join('');
                } else {
                    listEl.innerHTML = '<div class="cspv-empty" style="padding:20px;">Error loading data.</div>';
                }
            });
    }

    // Close: × button, backdrop click, Escape key
    document.getElementById('cspv-ref-drill-close').addEventListener('click', closeRefDrillModal);
    document.getElementById('cspv-ref-drill-modal').addEventListener('click', function(e) {
        if (e.target === this) { closeRefDrillModal(); }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeRefDrillModal(); }
    });

    // Copy to clipboard
    document.getElementById('cspv-ref-drill-copy').addEventListener('click', function() {
        var btn     = this;
        var titleEl = document.getElementById('cspv-ref-drill-title');
        var listEl  = document.getElementById('cspv-ref-drill-list');
        var lines   = [titleEl.textContent, ''];
        listEl.querySelectorAll('.cspv-row').forEach(function(row) {
            var label = row.querySelector('.cspv-bar-label');
            var views = row.querySelector('.cspv-row-views');
            if (label && views) {
                lines.push(label.textContent.trim() + '\t' + views.textContent.trim());
            }
        });
        navigator.clipboard.writeText(lines.join('\n')).then(function() {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 2000);
        });
    });

    document.getElementById('cspv-referrers').addEventListener('click', function(e) {
        var btn = e.target.closest('.cspv-ref-drill-btn');
        if (btn) { drillReferrer(btn.dataset.host); }
    });


    // ── Geography rendering ───────────────────────────────────────
    var countryNames = {AF:'Afghanistan',AL:'Albania',DZ:'Algeria',AO:'Angola',AR:'Argentina',AM:'Armenia',AT:'Austria',AU:'Australia',AZ:'Azerbaijan',BA:'Bosnia and Herzegovina',BD:'Bangladesh',BE:'Belgium',BF:'Burkina Faso',BG:'Bulgaria',BJ:'Benin',BO:'Bolivia',BR:'Brazil',BS:'Bahamas',BW:'Botswana',BY:'Belarus',BZ:'Belize',CA:'Canada',CD:'DR Congo',CI:'Côte d\'Ivoire',CM:'Cameroon',CH:'Switzerland',CL:'Chile',CN:'China',CO:'Colombia',CR:'Costa Rica',CZ:'Czechia',DE:'Germany',DK:'Denmark',DO:'Dominican Republic',EC:'Ecuador',EG:'Egypt',ET:'Ethiopia',ES:'Spain',FI:'Finland',FR:'France',GB:'United Kingdom',GH:'Ghana',GR:'Greece',GT:'Guatemala',HK:'Hong Kong',HN:'Honduras',HR:'Croatia',HU:'Hungary',ID:'Indonesia',IE:'Ireland',IL:'Israel',IN:'India',IQ:'Iraq',IR:'Iran',IT:'Italy',JP:'Japan',KE:'Kenya',KG:'Kyrgyzstan',KH:'Cambodia',KZ:'Kazakhstan',KR:'South Korea',LK:'Sri Lanka',MA:'Morocco',MD:'Moldova',MM:'Myanmar',MN:'Mongolia',MO:'Macao',MW:'Malawi',MX:'Mexico',MY:'Malaysia',MZ:'Mozambique',NA:'Namibia',NG:'Nigeria',NL:'Netherlands',NO:'Norway',NP:'Nepal',NZ:'New Zealand',OM:'Oman',PA:'Panama',PE:'Peru',PH:'Philippines',PK:'Pakistan',PL:'Poland',PT:'Portugal',PY:'Paraguay',QA:'Qatar',RO:'Romania',RS:'Serbia',RU:'Russia',RW:'Rwanda',SA:'Saudi Arabia',SD:'Sudan',SE:'Sweden',SG:'Singapore',SK:'Slovakia',SN:'Senegal',SV:'El Salvador',TH:'Thailand',TN:'Tunisia',TR:'Turkey',TW:'Taiwan',TZ:'Tanzania',UA:'Ukraine',UG:'Uganda',US:'United States',UY:'Uruguay',UZ:'Uzbekistan',VN:'Vietnam',YE:'Yemen',ZA:'South Africa',ZM:'Zambia',ZW:'Zimbabwe'};
    function countryFlag(cc) {
        if (!cc || cc.length !== 2) return '';
        return String.fromCodePoint(... Array.from(cc.toUpperCase()).map(function(c){ return 0x1F1E6 - 65 + c.charCodeAt(0); })) + ' ';
    }
    function countryName(cc) { return countryNames[cc] || cc; }

    // Country centroids for map markers
    var countryCentroids = {AF:[33,65],AL:[41,20],DZ:[28,3],AO:[-12.5,18.5],AR:[-34,-64],AT:[47.5,14],AU:[-25,134],BD:[24,90],BE:[50.8,4.5],BG:[43,25],BR:[-10,-55],CA:[56,-96],CH:[47,8],CL:[-35.5,-71],CN:[35,105],CO:[4,-72],CZ:[49.8,15.5],DE:[51,10],DK:[56,10],EG:[27,30],ES:[40,-4],FI:[64,26],FR:[46.5,2.5],GB:[54,-2],GH:[8,-1.5],GR:[39,22],HK:[22.3,114.2],HU:[47,19],ID:[-5,120],IE:[53.5,-8],IL:[31.5,34.8],IN:[22,79],IQ:[33,44],IR:[32,53],IT:[42.5,12.5],JP:[36,138],KE:[-1,38],KR:[36,128],MA:[32,-6],MX:[23,-102],MY:[4.2,101.9],NG:[10,8],NL:[52.5,5.7],NO:[64,12],NZ:[-42,174],PH:[12,122],PK:[30,70],PL:[52,20],PT:[39.5,-8],RO:[46,25],RU:[60,100],SA:[24,45],SE:[63,16],SG:[1.35,103.8],TH:[15.5,101],TR:[39,35],TW:[23.7,121],TZ:[-6.5,35],UA:[49,32],US:[39,-98],VN:[16,106],ZA:[-29,24],ZW:[-19.5,29.8]};

    // Leaflet map instance
    var geoMap = null;
    var geoMarkers = [];

    function initGeoMap() {
        if (geoMap) return;
        if (typeof L === 'undefined') { console.warn('[CSPV Geo] Leaflet not loaded'); return; }
        var mapEl = document.getElementById('cspv-geo-map');
        if (!mapEl) { console.warn('[CSPV Geo] Map element not found'); return; }
        // Guard: if Leaflet already init'd on this element (e.g. from a previous error), remove it
        if (mapEl._leaflet_id) {
            try { mapEl._leaflet_id = undefined; mapEl.innerHTML = ''; } catch(e) {}
        }
        try {
            geoMap = L.map('cspv-geo-map', {
                center: [20, 10],
                zoom: 2,
                minZoom: 1,
                maxZoom: 6,
                scrollWheelZoom: false,
                attributionControl: false
            });
            // Enable scroll-to-zoom only while the map has focus (click to engage,
            // click/scroll outside to release) so two-finger page scrolling still works.
            geoMap.on('click', function() { geoMap.scrollWheelZoom.enable(); });
            mapEl.addEventListener('mouseleave', function() { geoMap.scrollWheelZoom.disable(); });
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png', {
                subdomains: 'abcd',
                maxZoom: 19
            }).addTo(geoMap);
            L.control.attribution({ prefix: false }).addTo(geoMap);
            setTimeout(function() { if (geoMap) geoMap.invalidateSize(); }, 200);
            setTimeout(function() { if (geoMap) geoMap.invalidateSize(); }, 1000);
        } catch(e) {
            console.error('[CSPV Geo] Map init failed:', e.message);
            geoMap = null;
        }
    }

    function updateGeoMap(items) {
        initGeoMap();
        if (!geoMap) return;
        geoMarkers.forEach(function(m) { geoMap.removeLayer(m); });
        geoMarkers = [];
        if (!items || items.length === 0) return;

        var maxViews = items[0].views || 1;
        items.forEach(function(item) {
            var coords = countryCentroids[item.country_code];
            if (!coords) return;
            var ratio = item.views / maxViews;
            var radius = Math.max(6, Math.min(30, 6 + ratio * 24));
            var marker = L.circleMarker(coords, {
                radius: radius,
                fillColor: '#0f766e',
                color: '#065f56',
                weight: 1.5,
                fillOpacity: 0.3 + ratio * 0.5,
                opacity: 0.8
            }).addTo(geoMap);
            marker.bindTooltip(
                '<strong>' + countryFlag(item.country_code) + countryName(item.country_code) + '</strong><br>'
                + item.views.toLocaleString() + ' views',
                { direction: 'top', offset: [0, -radius] }
            );
            marker.on('click', function() { drillCountry(item.country_code); });
            geoMarkers.push(marker);
        });

        // Fit map to show all markers — fixes mobile where center:[20,10] zoom:2
        // clips the USA off the left edge of a narrow viewport.
        function fitToMarkers() {
            if (!geoMap || geoMarkers.length === 0) return;
            var group = L.featureGroup(geoMarkers);
            var bounds = group.getBounds();
            if (bounds.isValid()) {
                geoMap.fitBounds(bounds.pad(0.25), { maxZoom: 4, animate: false });
            }
        }
        setTimeout(function() { if (geoMap) { geoMap.invalidateSize(); fitToMarkers(); } }, 200);
        setTimeout(function() { if (geoMap) { geoMap.invalidateSize(); fitToMarkers(); } }, 1000);
    }

    function renderDepth(depth, prev, from, to) {
        var statsEl   = document.getElementById('cspv-depth-stats');
        var noDataEl  = document.getElementById('cspv-depth-no-data');
        var rangeEl   = document.getElementById('cspv-depth-range');
        var p50El      = null;
        var avgEl      = document.getElementById('stat-depth-avg');
        var maxEl      = document.getElementById('stat-depth-max');
        var sessionsEl = document.getElementById('stat-depth-sessions');

        if ( rangeEl ) {
            var activeBtn = document.querySelector('.cspv-quick.active');
            var r = activeBtn ? activeBtn.dataset.range : null;
            var rangeLabels = { '12h': '12 hrs', 'today': '24 hrs', '7': '7 days', '30': '30 days', '90': '90 days', '180': '180 days' };
            if (r && rangeLabels[r]) {
                rangeEl.textContent = rangeLabels[r];
            } else {
                var fd = from.slice(0, 10), td = to.slice(0, 10);
                rangeEl.textContent = fd === td ? fd : fd + ' \u2013 ' + td;
            }
        }

        if ( ! depth || depth.sessions === 0 ) {
            if (statsEl)  statsEl.style.display  = 'none';
            if (noDataEl) noDataEl.style.display  = '';
            if (p50El)      p50El.textContent      = '—';
            if (avgEl)      avgEl.textContent      = '';
            if (maxEl)      maxEl.textContent      = '';
            if (sessionsEl) sessionsEl.textContent = 'No data';
            return;
        }

        if (noDataEl) noDataEl.style.display = 'none';
        if (statsEl)  statsEl.style.display  = 'grid';

        var hasPrev = prev != null;
        var metrics = [
            { label: 'Median (P50)', value: depth.p50,      prev: hasPrev ? prev.p50      : null },
            { label: 'P95',          value: depth.p95,      prev: hasPrev ? prev.p95      : null },
            { label: 'P99',          value: depth.p99,      prev: hasPrev ? prev.p99      : null },
            { label: 'Average',      value: depth.avg,      prev: hasPrev ? prev.avg      : null },
            { label: 'Max',          value: depth.max,      prev: hasPrev ? prev.max      : null },
            { label: 'Sessions',     value: depth.sessions, prev: hasPrev ? prev.sessions : null },
        ];

        statsEl.innerHTML = metrics.map(function(m) {
            var cmpHtml = '';
            if (m.prev !== null) {
                var diff = Number(m.value) - Number(m.prev);
                var cmpColor = diff > 0 ? '#16a34a' : (diff < 0 ? '#dc2626' : '#9ca3af');
                var cmpArrow = diff > 0 ? '↑' : (diff < 0 ? '↓' : '=');
                cmpHtml = ' <span style="font-size:13px;font-weight:500;color:' + cmpColor + ';">' + cmpArrow + ' ' + Number(m.prev).toLocaleString() + '</span>';
            }
            return '<div style="background:#f5f3ff;border-radius:8px;padding:12px 10px;text-align:center;">'
                + '<div style="font-size:22px;font-weight:700;color:#7c3aed;">' + Number(m.value).toLocaleString() + cmpHtml + '</div>'
                + '<div style="font-size:12px;font-weight:600;color:#374151;margin-top:4px;">' + m.label + '</div>'
                + '</div>';
        }).join('');

        if (p50El)      p50El.textContent      = depth.p50 + ' Median';
        if (avgEl)      avgEl.textContent      = depth.avg + ' Average';
        if (maxEl)      maxEl.textContent      = depth.max + ' Max';
        if (sessionsEl) sessionsEl.textContent = depth.sessions.toLocaleString() + ' Sessions';
    }

    function renderGeo(items, from, to, geoSource, geoSourceActual) {
        var el = document.getElementById('cspv-geo-list');
        var drillEl = document.getElementById('cspv-geo-drill');
        var rangeEl = document.getElementById('cspv-geo-range');
        var badgeEl = document.getElementById('cspv-geo-source-badge');
        if (drillEl) drillEl.style.display = 'none';
        if (rangeEl && from && to) {
            rangeEl.textContent = (from === to) ? fmtDate(from) : fmtDate(from) + ' to ' + fmtDate(to);
        }
        if (badgeEl) {
            // For auto mode, show the actual source in use; otherwise show the setting
            var effective = (geoSource === 'auto' && geoSourceActual) ? geoSourceActual : geoSource;
            var badgeMap = {
                cloudflare: { label: 'CF-IPCountry', bg: 'rgba(249,115,22,0.85)', color: '#fff' },
                dbip:       { label: 'DB-IP Lite',   bg: 'rgba(255,255,255,0.25)', color: '#fff' },
                none:       { label: 'Auto (no source)', bg: 'rgba(0,0,0,0.25)', color: '#fff' },
                disabled:   { label: 'Disabled',      bg: 'rgba(0,0,0,0.25)',       color: '#fff' },
                auto:       { label: 'Auto',           bg: 'rgba(255,255,255,0.25)', color: '#fff' }
            };
            var b = badgeMap[effective] || badgeMap['auto'];
            // Prefix "Auto → " when setting is auto but actual source is known
            var label = b.label;
            if (geoSource === 'auto' && (effective === 'cloudflare' || effective === 'dbip')) {
                label = 'Auto → ' + b.label;
            }
            badgeEl.textContent = label;
            badgeEl.style.background = b.bg;
            badgeEl.style.color = b.color;
            badgeEl.style.display = 'inline-block';
        }
        updateGeoMap(items);
        if (!items || items.length === 0) {
            var geoMsg = 'No geography data for this period.';
            if (geoSource === 'disabled') {
                geoMsg = 'Geography tracking is disabled. Enable it in Settings → Geo Source.';
            } else if (geoSource === 'cloudflare') {
                geoMsg = 'No geography data yet. Ensure your site is proxied through Cloudflare so the CF-IPCountry header is forwarded.';
            } else if (geoSource === 'dbip') {
                geoMsg = 'No geography data yet. Ensure the DB-IP Lite database is installed (Settings → Download DB-IP).';
            }
            el.innerHTML = '<div class="cspv-empty" style="padding:12px 16px;">' + geoMsg + '</div>';
            return;
        }
        var max = items[0].views || 1;
        el.innerHTML = items.map(function(item) {
            var pct = Math.round((item.views / max) * 100);
            return '<div class="cspv-row" style="cursor:pointer;" data-country="' + esc(item.country_code) + '">'
                 + '<div class="cspv-bar-wrap">'
                 +   '<div class="cspv-bar-fill" style="width:' + pct + '%;background:linear-gradient(90deg,#6ee7b7,#a7f3d0);"></div>'
                 +   '<span class="cspv-bar-label">' + countryFlag(item.country_code) + esc(countryName(item.country_code)) + '</span>'
                 + '</div>'
                 + '<span class="cspv-row-views">' + item.views.toLocaleString() + '</span>'
                 + '</div>';
        }).join('');
        el.querySelectorAll('.cspv-row[data-country]').forEach(function(row) {
            row.addEventListener('click', function() { drillCountry(this.dataset.country, this); });
        });
    }

    function drillCountry(cc, rowEl) {
        var drillEl  = document.getElementById('cspv-geo-drill');
        var headerEl = document.getElementById('cspv-geo-drill-header');
        var listEl   = document.getElementById('cspv-geo-drill-list');
        var fromVal  = document.getElementById('cspv-from').value;
        var toVal    = document.getElementById('cspv-to').value;
        // Toggle off if clicking the already-open country
        if (drillEl.style.display !== 'none' && drillEl.dataset.openCountry === cc) {
            drillEl.style.display = 'none';
            drillEl.dataset.openCountry = '';
            return;
        }
        drillEl.dataset.openCountry = cc;
        headerEl.textContent = countryFlag(cc) + countryName(cc) + ' — Top Pages';
        listEl.innerHTML = '<div class="cspv-loading">Loading…</div>';
        // Move drill panel to appear directly under the clicked country row
        if (rowEl && rowEl.parentNode) {
            rowEl.parentNode.insertBefore(drillEl, rowEl.nextSibling);
        }
        drillEl.style.display = 'block';
        var fd = new FormData();
        fd.append('action', 'cspv_country_drill');
        fd.append('nonce', nonce);
        fd.append('country', cc);
        fd.append('from', fromVal);
        fd.append('to', toVal);
        fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success && resp.data && resp.data.pages) {
                    if (resp.data.pages.length === 0) {
                        listEl.innerHTML = '<div class="cspv-empty">No page data for this country.</div>';
                        return;
                    }
                    var mx = resp.data.pages[0].views || 1;
                    listEl.innerHTML = resp.data.pages.map(function(p) {
                        var pct = Math.round((p.views / mx) * 100);
                        var link = p.url ? '<a href="' + esc(p.url) + '" target="_blank">' + esc(p.title) + '</a>' : esc(p.title);
                        return '<div class="cspv-row">'
                             + '<div class="cspv-bar-wrap">'
                             +   '<div class="cspv-bar-fill" style="width:' + pct + '%"></div>'
                             +   '<span class="cspv-bar-label">' + link + '</span>'
                             + '</div>'
                             + '<span class="cspv-row-views">' + p.views.toLocaleString() + '</span>'
                             + '</div>';
                    }).join('');
                } else {
                    listEl.innerHTML = '<div class="cspv-empty">Error loading data.</div>';
                }
            });
    }

    document.getElementById('cspv-geo-drill-back').addEventListener('click', function() {
        var drillEl = document.getElementById('cspv-geo-drill');
        drillEl.style.display = 'none';
        drillEl.dataset.openCountry = '';
    });

    document.getElementById('cspv-geo-reset').addEventListener('click', function(e) {
        e.preventDefault();
        if (geoMap) {
            if (geoMarkers.length > 0) {
                var group = L.featureGroup(geoMarkers);
                var bounds = group.getBounds();
                if (bounds.isValid()) { geoMap.fitBounds(bounds.pad(0.25), { maxZoom: 4 }); return; }
            }
            geoMap.fitWorld();
        }
    });

    // DB-IP download button
    var dbipBtn = document.getElementById('cspv-download-dbip');
    if (dbipBtn) {
        dbipBtn.addEventListener('click', function() {
            var statusEl = document.getElementById('cspv-dbip-status');
            dbipBtn.disabled = true;
            dbipBtn.textContent = 'Downloading...';
            statusEl.textContent = 'Downloading DB-IP Lite database (~30MB). This may take a minute...';
            statusEl.style.color = '#666';
            var fd = new FormData();
            fd.append('action', 'cspv_download_dbip');
            fd.append('nonce', nonce);
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        statusEl.style.color = '#059669';
                        statusEl.textContent = 'Downloaded successfully (' + resp.data.size + '). Page will reload...';
                        dbipBtn.textContent = 'Done';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        statusEl.style.color = '#dc2626';
                        statusEl.textContent = 'Error: ' + (resp.data || 'Unknown error');
                        dbipBtn.disabled = false;
                        dbipBtn.textContent = 'Retry Download';
                    }
                })
                .catch(function(err) {
                    statusEl.style.color = '#dc2626';
                    statusEl.textContent = 'Network error: ' + err.message;
                    dbipBtn.disabled = false;
                    dbipBtn.textContent = 'Retry Download';
                });
        });
    }

    // ── Purge visitors ─────────────────────────────────────────────
    var purgeBtn = document.getElementById('cspv-purge-visitors');
    if (purgeBtn) {
        purgeBtn.addEventListener('click', function() {
            var days = document.getElementById('cspv-purge-days').value;
            var label = days === '0' ? 'ALL visitor data' : 'visitor data older than ' + days + ' days';
            if (!confirm('Are you sure you want to delete ' + label + '? This cannot be undone.')) return;
            var statusEl = document.getElementById('cspv-purge-status');
            purgeBtn.disabled = true;
            purgeBtn.textContent = 'Purging...';
            statusEl.textContent = 'Deleting ' + label + '...';
            statusEl.style.color = '#666';
            var fd = new FormData();
            fd.append('action', 'cspv_purge_visitors');
            fd.append('nonce', nonce);
            fd.append('days', days);
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        statusEl.style.color = '#059669';
                        var msg = resp.data.deleted === 'all' ? 'All data purged.' : resp.data.deleted.toLocaleString() + ' rows deleted.';
                        statusEl.textContent = msg + ' ' + resp.data.remaining.toLocaleString() + ' rows remaining.';
                        purgeBtn.disabled = false;
                        purgeBtn.textContent = '\ud83d\uddd1 Purge';
                    } else {
                        statusEl.style.color = '#dc2626';
                        statusEl.textContent = 'Error: ' + (resp.data || 'Unknown error');
                        purgeBtn.disabled = false;
                        purgeBtn.textContent = '\ud83d\uddd1 Purge';
                    }
                });
        });
    }

    // ── Throttle settings ──────────────────────────────────────────
    var enabledCb = document.getElementById('cspv-throttle-enabled');
    enabledCb.addEventListener('change', function() {
        document.getElementById('cspv-toggle-label').textContent = this.checked ? 'Enabled' : 'Disabled';
    });

    document.getElementById('cspv-save-throttle').addEventListener('click', function() {
        var btn = this, status = document.getElementById('cspv-save-status');
        btn.disabled = true; status.textContent = 'Saving…'; status.className = '';
        var fd = new FormData();
        fd.append('action',  'cspv_save_throttle_settings');
        fd.append('nonce',   throttleNonce);
        fd.append('enabled', enabledCb.checked ? '1' : '');
        fd.append('limit',   document.getElementById('cspv-throttle-limit').value);
        fd.append('window',  document.getElementById('cspv-throttle-window').value);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                status.textContent = resp.success ? '✓ Saved' : '✗ Failed';
                status.className   = resp.success ? 'ok' : 'err';
            })
            .catch(function(){ status.textContent = '✗ Network error'; status.className = 'err'; })
            .finally(function(){ btn.disabled = false; setTimeout(function(){ status.textContent=''; }, 3000); });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.classList.contains('cspv-unblock-btn')) { return; }
        var btn = e.target, hash = btn.dataset.hash;
        btn.disabled = true; btn.textContent = '…';
        var fd = new FormData();
        fd.append('action', 'cspv_unblock_ip'); fd.append('nonce', throttleNonce); fd.append('ip_hash', hash);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    var row = document.getElementById('cspv-row-' + hash);
                    if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(function(){ row.remove(); }, 300); }
                }
            });
    });

    var clearBtn = document.getElementById('cspv-clear-blocklist');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (!confirm('Clear all blocked IPs?')) { return; }
            var fd = new FormData();
            fd.append('action', 'cspv_clear_blocklist'); fd.append('nonce', throttleNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        document.getElementById('cspv-blocklist-body').innerHTML = '<p class="cspv-empty">All IPs unblocked.</p>';
                        clearBtn.remove();
                    }
                });
        });
    }

    // ── FTB toggle label ───────────────────────────────────────────
    var ftbEnabledCb = document.getElementById('cspv-ftb-enabled');
    if (ftbEnabledCb) {
        ftbEnabledCb.addEventListener('change', function() {
            document.getElementById('cspv-ftb-toggle-label').textContent = this.checked ? 'Enabled' : 'Disabled';
        });
    }

    // ── Save FTB settings ──────────────────────────────────────────
    var saveFtbBtn = document.getElementById('cspv-save-ftb');
    if (saveFtbBtn) {
        saveFtbBtn.addEventListener('click', function() {
            var btn = this, status = document.getElementById('cspv-ftb-save-status');
            btn.disabled = true; status.textContent = 'Saving…'; status.className = '';
            var fd = new FormData();
            fd.append('action',     'cspv_save_ftb_settings');
            fd.append('nonce',      throttleNonce);
            fd.append('enabled',    ftbEnabledCb.checked ? '1' : '');
            fd.append('page_limit', document.getElementById('cspv-ftb-page-limit').value);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (resp.success && resp.data && resp.data.rules) {
                        var rules = resp.data.rules;
                        var summaryEl = document.getElementById('cspv-ftb-rule-summary');
                        if (summaryEl) summaryEl.textContent = rules.summary;
                    }
                    status.textContent = resp.success ? '✓ Saved' : '✗ Failed';
                    status.className   = resp.success ? 'ok' : 'err';
                })
                .catch(function(){ status.textContent = '✗ Network error'; status.className = 'err'; })
                .finally(function(){ btn.disabled = false; setTimeout(function(){ status.textContent=''; }, 3000); });
        });
    }

    // ── FTB unblock individual IP ──────────────────────────────────
    document.addEventListener('click', function(e) {
        if (!e.target.classList.contains('cspv-ftb-unblock-btn')) { return; }
        var btn = e.target, hash = btn.dataset.hash;
        btn.disabled = true; btn.textContent = '…';
        var fd = new FormData();
        fd.append('action', 'cspv_ftb_unblock_ip'); fd.append('nonce', throttleNonce); fd.append('ip_hash', hash);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    var row = document.getElementById('cspv-ftb-row-' + hash);
                    if (row) { row.style.opacity = '0'; row.style.transition = 'opacity .3s'; setTimeout(function(){ row.remove(); }, 300); }
                }
            });
    });

    // ── Clear FTB blocklist ────────────────────────────────────────
    var clearFtbBtn = document.getElementById('cspv-ftb-clear-blocklist');
    if (clearFtbBtn) {
        clearFtbBtn.addEventListener('click', function() {
            if (!confirm('Clear all Fail2Ban blocked IPs? This cannot be undone.')) { return; }
            var fd = new FormData();
            fd.append('action', 'cspv_ftb_clear_blocklist'); fd.append('nonce', throttleNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        document.getElementById('cspv-ftb-blocklist-body').innerHTML = '<p class="cspv-empty">All FTB blocks cleared.</p>';
                        clearFtbBtn.remove();
                    }
                });
        });
    }

    // ── Clear ALL IP Addresses (nuclear option) ────────────────────
    var clearAllBtn = document.getElementById('cspv-clear-all-ips');
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function() {
            if (!confirm('Clear ALL IP addresses? This removes:\n\n• All throttle blocks and counters\n• All Fail2Ban blocks and counters\n• All event logs\n\nThis cannot be undone.')) { return; }
            var btn = this, status = document.getElementById('cspv-clear-all-status');
            btn.disabled = true; status.textContent = 'Clearing…'; status.style.color = '';
            var fd = new FormData();
            fd.append('action', 'cspv_clear_all_ip_data'); fd.append('nonce', throttleNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        status.textContent = '✓ All IP data cleared';
                        status.style.color = '#1db954';
                        // Refresh blocklist displays
                        document.getElementById('cspv-blocklist-body').innerHTML = '<p class="cspv-empty">No IPs currently blocked.</p>';
                        document.getElementById('cspv-ftb-blocklist-body').innerHTML = '<p class="cspv-empty">No IPs on the Fail2Ban blocklist.</p>';
                        var cb = document.getElementById('cspv-clear-blocklist'); if (cb) cb.remove();
                        var fb = document.getElementById('cspv-ftb-clear-blocklist'); if (fb) fb.remove();
                    } else {
                        status.textContent = '✗ Failed';
                        status.style.color = '#e53e3e';
                    }
                })
                .catch(function(){ status.textContent = '✗ Network error'; status.style.color = '#e53e3e'; })
                .finally(function(){ btn.disabled = false; setTimeout(function(){ status.textContent=''; }, 4000); });
        });
    }

    // ── Tracking pause toggle ──────────────────────────────────────
    var pauseCb = document.getElementById('cspv-tracking-paused');
    if (pauseCb) {
        pauseCb.addEventListener('change', function() {
            var on = this.checked;
            var label = document.getElementById('cspv-pause-label');
            var pill  = document.getElementById('cspv-pause-status');
            var toggle = document.getElementById('cspv-pause-toggle');
            var header = document.getElementById('cspv-pause-header');
            var wrapper = document.getElementById('cspv-pause-wrapper');
            label.textContent = on ? 'Paused' : 'Active';
            label.style.color = on ? '#dc2626' : '';
            toggle.style.background = on ? '#dc2626' : '';
            pill.textContent = on ? '⏸ TRACKING PAUSED' : '● TRACKING ACTIVE';
            pill.className = 'cspv-ftb-status-pill ' + (on ? 'cspv-ftb-on' : 'cspv-ftb-off');
            if (on) { pill.style.background = 'rgba(255,255,255,.3)'; } else { pill.style.background = ''; }
            header.style.background = on ? 'linear-gradient(135deg,#991b1b,#dc2626)' : 'linear-gradient(135deg,#374151,#6b7280)';
            wrapper.style.borderColor = on ? '#fecaca' : '#dce3ef';
        });
    }
    document.getElementById('cspv-save-pause').addEventListener('click', function() {
        var btn = this, status = document.getElementById('cspv-pause-save-status');
        btn.disabled = true; status.textContent = 'Saving…'; status.className = '';
        var fd = new FormData();
        fd.append('action', 'cspv_set_tracking_pause');
        fd.append('nonce',  throttleNonce);
        fd.append('paused', pauseCb.checked ? '1' : '');
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                status.textContent = resp.success ? '✓ Saved' : '✗ Failed';
                status.style.color = resp.success ? '#1db954' : '#e53e3e';
            })
            .catch(function(){ status.textContent = '✗ Network error'; status.style.color = '#e53e3e'; })
            .finally(function(){ btn.disabled = false; setTimeout(function(){ status.textContent=''; }, 3000); });
    });

    // ── Dedup settings ──────────────────────────────────────────────
    var dedupCb = document.getElementById('cspv-dedup-enabled');
    dedupCb.addEventListener('change', function() {
        document.getElementById('cspv-dedup-toggle-label').textContent = this.checked ? 'Enabled' : 'Disabled';
    });

    document.getElementById('cspv-save-dedup').addEventListener('click', function() {
        var btn = this, status = document.getElementById('cspv-dedup-save-status');
        btn.disabled = true; status.textContent = 'Saving…'; status.className = '';
        var fd = new FormData();
        fd.append('action',  'cspv_save_dedup_settings');
        fd.append('nonce',   throttleNonce);
        fd.append('enabled', dedupCb.checked ? '1' : '');
        fd.append('window',  document.getElementById('cspv-dedup-window').value);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    status.textContent = '✓ Saved (stored: ' + resp.data.stored + ')';
                    status.style.color = '#1db954';
                    var pill = document.getElementById('cspv-dedup-status');
                    if (resp.data.enabled) {
                        pill.textContent = 'DEDUP ON';
                        pill.className = 'cspv-ftb-status-pill cspv-ftb-on';
                    } else {
                        pill.textContent = 'DEDUP OFF';
                        pill.className = 'cspv-ftb-status-pill cspv-ftb-off';
                    }
                } else {
                    status.textContent = '✗ Failed: ' + ((resp.data && resp.data.message) || 'unknown');
                    status.style.color = '#e53e3e';
                }
            })
            .catch(function(){ status.textContent = '✗ Network error'; status.style.color = '#e53e3e'; })
            .finally(function(){ btn.disabled = false; setTimeout(function(){ status.textContent=''; }, 3000); });
    });

    // ── Test Fail2Ban ───────────────────────────────────────────────
    document.getElementById('cspv-test-ftb').addEventListener('click', function() {
        var btn = this, resultsEl = document.getElementById('cspv-ftb-test-results');
        btn.disabled = true; btn.textContent = '🧪 Testing…';
        resultsEl.style.display = 'block';
        resultsEl.innerHTML = '<em style="color:#888;">Running diagnostics…</em>';
        var fd = new FormData();
        fd.append('action', 'cspv_test_ftb');
        fd.append('nonce',  throttleNonce);
        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                if (!resp.success) {
                    resultsEl.innerHTML = '<span style="color:#e53e3e;">✗ Test failed: ' + ((resp.data && resp.data.message) || 'Unknown error') + '</span>';
                    return;
                }
                var d = resp.data;
                var html = d.results.map(function(r) {
                    var icon = r.pass ? '✅' : '❌';
                    var color = r.pass ? '#065f46' : '#991b1b';
                    return '<div style="display:flex;align-items:baseline;gap:6px;margin-bottom:4px;">'
                         + '<span style="flex-shrink:0;">' + icon + '</span>'
                         + '<span><strong>' + r.test + '</strong> — <span style="color:' + color + ';">' + r.detail + '</span></span>'
                         + '</div>';
                }).join('');
                var summaryColor = d.all_pass ? '#065f46' : '#991b1b';
                var summaryBg    = d.all_pass ? '#d1fae5' : '#fee2e2';
                html += '<div style="margin-top:10px;padding:8px 12px;background:' + summaryBg + ';border-radius:4px;font-weight:700;color:' + summaryColor + ';">' + (d.all_pass ? '✅ ' : '❌ ') + d.summary + '</div>';
                resultsEl.innerHTML = html;
            })
            .catch(function(err) {
                resultsEl.innerHTML = '<span style="color:#e53e3e;">✗ Network error: ' + err.message + '</span>';
            })
            .finally(function() {
                btn.disabled = false; btn.textContent = '🧪 Test Fail2Ban';
            });
    });

    // ── FTB status pill live update ────────────────────────────────
    if (ftbEnabledCb) {
        ftbEnabledCb.addEventListener('change', function() {
            var pill = document.getElementById('cspv-ftb-status-pill');
            if (pill) {
                pill.textContent = this.checked ? '● FTB ACTIVE' : '○ FTB OFF';
                pill.className = 'cspv-ftb-status-pill ' + (this.checked ? 'cspv-ftb-on' : 'cspv-ftb-off');
            }
        });
    }

    // ── Help modal system (per-tab, card layout) ───────────────────
    var helpData = {
        'stats': {
            title: 'Statistics Dashboard — How It Works',
            cards: [
                { title: 'Summary Cards', badge: 'info', body: 'The summary cards show <strong>total views</strong>, <strong>posts viewed</strong>, <strong>unique visitors</strong>, and <strong>hot pages</strong> for the selected date range. Use the quick range buttons (12h, Last 24h, 1 Week, 1 Month, 3 Months, 6 Months) or the custom date picker to change the period.' },
                { title: 'Chart', badge: 'info', body: 'The chart displays views over time. Short ranges show hourly breakdown, medium ranges show daily bars, and longer ranges show weekly aggregation. All data comes from the page views log table.' },
                { title: 'Most Viewed Posts', badge: 'info', body: 'Top 10 posts ranked by view count within the selected period. Only views recorded by the JavaScript beacon are counted here. Click any title to visit the post.' },
                { title: 'All Time Statistics', badge: 'info', body: 'The All Time banner shows your lifetime total views tracked by the beacon. The All Time Top Posts list ranks by lifetime total tracked views.' },
                { title: 'Top Referrers', badge: 'info', body: 'Shows the top referring domains for the selected period. Direct visits and your own domain are excluded. Common sources include Google, social media, and external links.' },
                { title: 'Cloudflare Cache Bypass', badge: 'tip', body: 'The diagnostic test confirms your Cloudflare Cache Rule is correctly bypassing cache for the REST API. If the counter does not increment, add a Cache Rule: URI Path contains <code>/wp-json/cloudscale-wordpress-free-analytics/</code> → Bypass Cache.' },
                { title: 'Installation', badge: 'required', body: 'No additional installation required. The plugin creates its database table automatically on activation. Ensure your Cloudflare Cache Rule is set up (see Cache Bypass above) for accurate counting behind a CDN.' }
            ]
        },
        'display': {
            title: 'Display Settings — How It Works',
            cards: [
                { title: 'Display Position', badge: 'info', body: '<strong>Before post content</strong> places the badge above the post. <strong>After post content</strong> appends it below. <strong>Both</strong> shows it in both positions. <strong>Off</strong> hides it entirely — use the template function <code>&lt;?php cspv_the_views(); ?&gt;</code> for manual placement.' },
                { title: 'Counter Style', badge: 'info', body: '<strong>Badge</strong> uses a solid gradient background. <strong>Pill</strong> uses a light tinted background for a subtler look. <strong>Minimal</strong> is plain coloured text with no background.' },
                { title: 'Badge Colour', badge: 'optional', body: 'Choose from five gradient colour schemes: Blue (default), Pink, Red, Purple, and Grey. The colour applies to all three styles.' },
                { title: 'Customise Text', badge: 'optional', body: '<strong>Icon</strong> is the emoji shown before the count (default: 👁). <strong>Suffix</strong> is the text after the number (default: "views"). Leave either empty to hide it.' },
                { title: 'Show Counter On', badge: 'info', body: 'Select which post types display the badge on the front end. This is independent of the Tracking Filter — you might display on Pages but only track Posts.' },
                { title: 'Tracking Filter', badge: 'info', body: 'Controls which post types <strong>record views</strong> in the database. Untracked post types silently skip tracking. Separate from the display setting.' },
                { title: 'Installation', badge: 'required', body: 'No additional setup needed. Styles are applied automatically. If you use a caching plugin, purge your page cache after changing display settings.' }
            ]
        },
        'throttle': {
            title: 'IP Throttle & Fail2Ban — How It Works',
            cards: [
                { title: 'Emergency Tracking Pause', badge: 'tip', body: 'The <strong>Page Tracking</strong> kill switch at the top instantly stops all view recording across your entire site. When paused, the tracking script is not loaded and the API silently rejects requests. Use this during sustained attacks to protect your database from junk data. Historical data is preserved.' },
                { title: 'Tier 1: Throttle (Soft Block)', badge: 'info', body: 'After an IP exceeds the <strong>request limit</strong> (default: 50) within the <strong>time window</strong> (default: 1 hour), it is silently blocked for 1 hour. The block auto expires — no manual cleanup needed. Attackers receive HTTP 200 so they have no signal to adapt.' },
                { title: 'Tier 2: Fail2Ban (Hard Block)', badge: 'info', body: 'When an IP exceeds the <strong>page limit</strong> (default: 1,000) within the same time window, it is blocked for <strong>2 hours</strong>. FTB blocks auto clear after 2 hours. This catches persistent abusers who keep returning after throttle blocks expire.' },
                { title: 'How to Know if FTB is Running', badge: 'tip', body: 'Look for the <strong>● FTB ACTIVE</strong> or <strong>○ FTB OFF</strong> status pill in the Fail2Ban section header. The FTB Rules card also shows whether the rule is Active or Inactive, along with the current page limit and window. Use the <strong>🧪 Test Fail2Ban</strong> button to run a full diagnostic.' },
                { title: 'Test Fail2Ban', badge: 'info', body: 'The <strong>🧪 Test Fail2Ban</strong> button runs a five point diagnostic: it writes and reads a test transient (the engine behind FTB blocks), checks options table access (blocklist storage), verifies FTB is enabled, and confirms the block duration. If all five tests pass, Fail2Ban is fully operational.' },
                { title: 'FTB Installation', badge: 'required', body: '<strong>No external software is required.</strong> CloudScale Fail2Ban is entirely built in to the plugin. It does <strong>not</strong> use the Linux fail2ban service or any server side packages. It works purely through WordPress transients and the options table, which means it runs on any WordPress host including shared hosting, managed WordPress, and VPS.<br><br><strong>Requirements:</strong><br>• WordPress 6.0+ with a working database<br>• The plugin activated (no additional configuration files)<br>• Transients must work (they do on all standard WordPress installs; if you use an object cache like Redis or Memcached, transients are stored there instead and still work correctly)<br><br><strong>No server configuration, no firewall rules, no cron jobs needed.</strong> Enable the toggle above and FTB starts protecting immediately.' },
                { title: 'Clear IP Addresses', badge: 'tip', body: 'The <strong>Clear All IP Addresses</strong> button at the bottom is a nuclear option that wipes all throttle blocks, FTB blocks, all request counters, and all event logs across both tiers. Use this to start fresh after configuration changes or testing.' }
            ]
        },
        'history': {
            title: 'Post View History — How It Works',
            cards: [
                { title: 'Browsing Posts', badge: 'info', body: 'The top list shows your 100 most-viewed posts ranked by total view count. Click any row to load that post\'s detail panel. Click the <strong>↗</strong> link next to a title to open the post on your site in a new tab. Use the search box to find posts by title if they are not in the top 100.' },
                { title: 'Timeline Slider', badge: 'tip', body: 'Use the <strong>Window</strong> slider (7–180 days) to control how much history is shown in the daily chart and the Audit Trail below it. Drag left to zoom in on recent activity, or right to see the full 6 month picture. The slider is hidden in "Last 48 hours" mode.' },
                { title: 'View Counts Explained', badge: 'info', body: '<strong>Total Views</strong> is the number stored in <code>_cspv_view_count</code> post meta — the count visitors see on the front end. <strong>Page Views</strong> is the number of rows in the tracking log table for this post.' },
                { title: 'Daily Chart vs Hourly', badge: 'info', body: 'The <strong>Daily Chart</strong> button shows views per day within the slider window (up to 180 days). The <strong>Last 48 hours</strong> button shows an hour-by-hour breakdown of the last 2 days. Both draw from the tracking log table.' },
                { title: 'Audit Trail', badge: 'info', body: 'The Audit Trail below the chart shows every day in the slider window with a view count and the top referring domain for that day. Days with zero views are shown in grey. The row highlighted in blue marks the post\'s published date.' }
            ]
        },
    };

    // Determine current active tab
    function getActiveTab() {
        var active = document.querySelector('.cspv-tab.active');
        return active ? active.dataset.tab : 'stats';
    }

    function renderHelpCards(cards) {
        var badgeMap = {
            'info':     'cspv-help-badge-info',
            'optional': 'cspv-help-badge-optional',
            'required': 'cspv-help-badge-required',
            'tip':      'cspv-help-badge-tip'
        };
        return cards.map(function(card) {
            var badgeCls = badgeMap[card.badge] || 'cspv-help-badge-info';
            var badgeLabel = card.badge === 'required' ? '⚙ Required'
                          : card.badge === 'tip' ? '💡 Tip'
                          : card.badge === 'optional' ? '◻ Optional'
                          : 'ℹ Info';
            return '<div class="cspv-help-card">'
                 + '<div class="cspv-help-card-header">'
                 +   '<span class="cspv-help-card-title">' + card.title + '</span>'
                 +   '<span class="cspv-help-card-badge ' + badgeCls + '">' + badgeLabel + '</span>'
                 + '</div>'
                 + '<div class="cspv-help-card-body">' + card.body + '</div>'
                 + '</div>';
        }).join('');
    }

    document.getElementById('cspv-help-btn').addEventListener('click', function() {
        var tab = getActiveTab();
        var data = helpData[tab];
        if (!data) return;
        document.getElementById('cspv-help-modal-title').textContent = data.title;
        document.getElementById('cspv-help-modal-body').innerHTML = renderHelpCards(data.cards);
        openModal(document.getElementById('cspv-help-modal'));
    });
    document.getElementById('cspv-help-modal-close').addEventListener('click', function() {
        closeModal(document.getElementById('cspv-help-modal'));
    });
    document.getElementById('cspv-help-modal-ok').addEventListener('click', function() {
        closeModal(document.getElementById('cspv-help-modal'));
    });
    document.getElementById('cspv-help-modal').addEventListener('click', function(e) {
        if (e.target === this) closeModal(this);
    });

    // ── Boot (restore saved date range) ──────────────────────────
    (function() {
        var saved = null;
        try { saved = localStorage.getItem('cspv_date_range'); } catch(e) {}
        if (saved && saved !== 'custom') {
            var qBtn = document.querySelector('.cspv-quick[data-range="' + saved + '"]');
            if (qBtn) { qBtn.click(); return; }
        }
        if (saved === 'custom') {
            try {
                var sf = localStorage.getItem('cspv_date_from');
                var st = localStorage.getItem('cspv_date_to');
                if (sf && st) {
                    document.getElementById('cspv-from').value = sf;
                    document.getElementById('cspv-to').value   = st;
                    loadData();
                    return;
                }
            } catch(e) {}
        }
        document.querySelector('.cspv-quick[data-range="today"]').click();
    })();

    // ── Cache bypass test ──────────────────────────────────────────
    (function() {
        var testUrl  = <?php echo wp_json_encode( rest_url( 'cloudscale-wordpress-free-analytics/v1/cache-test' ) ); ?>;
        var wpNonce  = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        var badge    = document.getElementById('cspv-cf-status-badge');
        var log      = document.getElementById('cspv-cf-test-log');
        var btn      = document.getElementById('cspv-cf-test-btn');

        function setStep(el, state, text) {
            el.className = 'cspv-cf-step ' + state;
            el.querySelector('.cspv-cf-step-text').textContent = text;
        }

        function addStep(text) {
            var div = document.createElement('div');
            div.className = 'cspv-cf-step pending';
            div.innerHTML = '<span class="cspv-cf-step-icon"></span>'
                          + '<span class="cspv-cf-step-text">' + text + '</span>';
            log.appendChild(div);
            return div;
        }

        btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = 'Testing…';
            badge.className = 'testing';
            badge.textContent = 'Testing…';
            log.innerHTML = '';
            log.classList.add('visible');

            var s1 = addStep('Reading current counter…');
            var s2, s3, s4;
            var before;

            // Step 1: GET — read current value
            fetch(testUrl, {
                method: 'GET',
                headers: { 'X-WP-Nonce': wpNonce },
                credentials: 'same-origin',
                cache: 'no-store',
            })
            .then(function(r) {
                if (!r.ok) { throw new Error('GET failed — HTTP ' + r.status); }
                return r.json();
            })
            .then(function(data) {
                before = data.counter;
                setStep(s1, 'ok', 'Read counter: ' + before);

                // Step 2: POST — increment
                s2 = addStep('Sending increment request to bypass endpoint…');
                return fetch(testUrl, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify({}),
                });
            })
            .then(function(r) {
                if (!r.ok) { throw new Error('POST failed — HTTP ' + r.status + '. Check your Cloudflare Cache Rule.'); }
                return r.json();
            })
            .then(function(data) {
                var after = data.counter;
                setStep(s2, 'ok', 'Increment sent — server returned: ' + after);

                // Step 3: GET again — verify value changed
                s3 = addStep('Re-reading counter to verify bypass…');
                return fetch(testUrl + '?t=' + Date.now(), {
                    method: 'GET',
                    headers: { 'X-WP-Nonce': wpNonce },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
            })
            .then(function(r) {
                if (!r.ok) { throw new Error('Verification GET failed — HTTP ' + r.status); }
                return r.json();
            })
            .then(function(data) {
                var verified = data.counter;
                s4 = addStep('');

                if (verified > before) {
                    setStep(s3, 'ok', 'Verified counter is now: ' + verified);
                    setStep(s4, 'ok', 'Cache bypass is working correctly.');
                    badge.className = 'pass';
                    badge.textContent = '✓ Bypass working';
                    btn.textContent = 'Test Cache Bypass';
                } else {
                    setStep(s3, 'fail',
                        'Counter unchanged (' + verified + '). '
                        + 'Cloudflare is caching the endpoint — add the Cache Rule.');
                    setStep(s4, 'fail', 'Cache bypass NOT working. See rule below.');
                    badge.className = 'fail';
                    badge.textContent = '✗ Bypass broken';
                    btn.textContent = 'Test Cache Bypass';
                }
                btn.disabled = false;
            })
            .catch(function(err) {
                var errStep = addStep('Error: ' + err.message);
                errStep.className = 'cspv-cf-step fail';
                badge.className = 'fail';
                badge.textContent = '✗ Test failed';
                btn.disabled = false;
                btn.textContent = 'Test Cache Bypass';
            });
        });
    })();

    // ── Info modal system ──────────────────────────────────────────
    var infoData = {
        'stats-overview': {
            title: '📊 Statistics Overview',
            body: '<p>The <strong>summary cards</strong> show total views, unique posts viewed, and average views per day for the selected date range. Use the quick buttons or custom date picker to change the period.</p><p>The <strong>chart</strong> shows views over time with tabs for 12 Hours, 7 Days, 1 Month, and 6 Months. All chart data comes from the page views log table, reflecting actual recorded views.</p>'
        },
        'top-posts': {
            title: '🏆 Most Viewed Posts',
            body: '<p>Shows the top 10 posts ranked by view count within the selected date range. Only views recorded by the beacon tracker are counted.</p><p>Click any post title to visit it on your site. The view count reflects the selected period, not all time totals.</p>'
        },
        'all-time': {
            title: '🏆 All Time Statistics',
            body: '<p>The <strong>All Time Views</strong> banner shows your total lifetime views tracked by the beacon across all posts.</p><p>The <strong>All Time Top Posts</strong> list ranks posts by their lifetime tracked view count. This is useful for seeing your historically most popular content.</p>'
        },
        'referrers': {
            title: '🔗 Top Referrers',
            body: '<p>Shows the top referring domains sending traffic to your site during the selected period. The referrer is captured from the HTTP Referer header when a visitor arrives at your post.</p><p>Direct visits (no referrer) and visits from your own domain are excluded. Common sources include Google, social media platforms, and other sites linking to your content.</p>'
        },
        'cache-test': {
            title: '☁ Cloudflare Cache Test',
            body: '<p>This diagnostic tests whether your Cloudflare Cache Rule is correctly bypassing the cache for the CloudScale REST API endpoint.</p><p>Click <strong>Run Test</strong> to send a POST followed by a GET to the cache test endpoint. If the counter increments, the endpoint is not cached and your Cache Rule is working. If the counter stays the same on repeated tests, Cloudflare is caching the API response and views will not be recorded.</p><p>To fix, add a Cache Rule in Cloudflare: URI Path contains <code>/wp-json/cloudscale-wordpress-free-analytics/</code> → Bypass Cache.</p>'
        },
        'display-position': {
            title: '📍 Display Position',
            body: '<p><strong>Before post content</strong> places the view counter above the post title, aligned to the right.</p><p><strong>After post content</strong> appends the counter below the post body.</p><p><strong>Both</strong> shows the counter in both positions.</p><p><strong>Off</strong> hides the counter entirely. You can still use the template function <code>&lt;?php cspv_the_views(); ?&gt;</code> in your theme for manual placement.</p>'
        },
        'display-style': {
            title: '🎨 Counter Style',
            body: '<p><strong>Badge</strong> uses a solid gradient background with white text. Best for sites that want the counter to be prominent and eye catching.</p><p><strong>Pill</strong> uses a light tinted background with coloured text. A softer, more subtle look that still stands out.</p><p><strong>Minimal</strong> is plain coloured text with no background. For sites that want counts visible but completely unobtrusive.</p>'
        },
        'display-color': {
            title: '🎨 Badge Colour',
            body: '<p>Choose from five gradient colour schemes: <strong>Blue</strong> (default), <strong>Pink</strong>, <strong>Red</strong>, <strong>Purple</strong>, and <strong>Grey</strong>.</p><p>The selected colour applies to all three styles. The badge gets the full gradient, the pill gets a matching tinted background and border, and the minimal style uses the solid base colour for text.</p>'
        },
        'display-text': {
            title: '✏️ Customise Text',
            body: '<p><strong>Icon</strong> is the emoji or text shown before the count. The default is the eye emoji 👁. Leave empty to hide the icon.</p><p><strong>Suffix</strong> is the text after the number. The default is "views". You could change it to "reads", "hits", or leave it empty to show just the number.</p>'
        },
        'display-types': {
            title: '📄 Show Counter On',
            body: '<p>Select which post types display the view counter badge on the front end. By default only <strong>Posts</strong> are selected.</p><p>This setting is independent of the Tracking Filter. You might display counts on Pages but only track Posts, or vice versa.</p>'
        },
        'tracking-filter': {
            title: '🛡️ Tracking Filter',
            body: '<p>Controls which post types actually <strong>record views</strong> in the database. When a visitor views an untracked post type, no view is recorded.</p><p>This is separate from the display setting. The Tracking Filter controls what gets counted. The Show Counter On setting controls what displays a badge. You can track Pages without displaying a counter on them, keeping your stats comprehensive while keeping your page layouts clean.</p>'
        },
        'throttle': {
            title: '🛡 IP Throttle Protection',
            body: '<p>Rate limits how many views a single IP address can generate within a rolling time window. This prevents view count inflation from bots, scrapers, or deliberate abuse.</p><p><strong>Request limit</strong> is how many views per IP before blocking (default: 50). <strong>Time window</strong> is how long the counter accumulates (default: 1 hour).</p><p>Blocked IPs are silently accepted (the attacker gets no signal) but not recorded. All blocks auto expire after 1 hour. Logged in administrators bypass the throttle entirely.</p>'
        },
        'blocklist': {
            title: '🚫 Blocked IPs',
            body: '<p>Shows IP hashes currently blocked by the throttle system. Each entry shows when it was blocked and when the block expires.</p><p>IP addresses are never stored raw. They are hashed with SHA256 combined with your site salt before storage, so the actual IP cannot be recovered.</p><p>You can unblock individual IPs or clear the entire list. All blocks expire automatically after 1 hour even without manual intervention.</p>'
        },
        'block-log': {
            title: '📋 Block Event Log',
            body: '<p>A chronological history of the last 100 block events. Useful for identifying patterns of abuse, such as repeated blocks from the same IP hash or clusters of blocks at specific times.</p><p>The log is informational only. It does not affect any active blocks.</p>'
        },
        'ftb': {
            title: '🔥 Fail2Ban Protection',
            body: '<p>Fail2Ban (FTB) is a second tier of IP protection that operates above the standard throttle. While throttle blocks auto-expire after 1 hour, FTB blocks last <strong>2 hours</strong> and auto clear.</p><p>When an IP exceeds the configurable page limit (default: 1,000 pages) within the throttle time window, it is added to the FTB blocklist for 2 hours. This catches persistent abusers who keep returning after throttle blocks expire.</p><p>The <strong>FTB ACTIVE / FTB OFF</strong> pill in the section header shows you at a glance whether Fail2Ban is currently running.</p>'
        },
        'ftb-rules': {
            title: '📋 FTB Rules',
            body: '<p>Shows the current Fail2Ban rule configuration. The rule combines the page limit setting with the time window from the throttle settings to determine when an IP is blocked.</p><p>When the rule is <strong>Active</strong>, any IP that hits the page limit within the window gets blocked for 2 hours (auto clear). When <strong>Inactive</strong>, page tracking is paused and no FTB blocks are created.</p>'
        },
        'ftb-blocklist': {
            title: '🚫 FTB Blocked IPs',
            body: '<p>IPs currently blocked by the Fail2Ban system. FTB blocks last <strong>2 hours</strong> and auto clear. Each entry shows when it was blocked and when the block expires.</p><p>You can unblock individual IPs or clear the entire FTB blocklist to lift all blocks early.</p>'
        },
        'clear-all-ips': {
            title: '🗑 Clear All IP Addresses',
            body: '<p>This is a nuclear option that clears <strong>everything</strong> related to IP blocking across both tiers:</p><p><strong>Throttle (Tier 1):</strong> All blocked IPs, request counters, and block event logs are cleared. Transient based blocks are removed immediately.</p><p><strong>Fail2Ban (Tier 2):</strong> All FTB blocked IPs, page counters, and FTB event logs are cleared.</p><p>Use this if you need to start fresh after a configuration change or if blocks were created during testing. This action cannot be undone.</p>'
        },
        'tracking-pause': {
            title: '⏸ Page Tracking Kill Switch',
            body: '<p>This is an <strong>emergency kill switch</strong> that instantly stops all page view tracking across your entire site.</p><p>When activated:<br>• The tracking script is not loaded on any page<br>• The recording REST API silently rejects all requests (returns HTTP 200 with logged: false)<br>• No new views are counted or stored<br>• All historical data is preserved</p><p>Use this when your site is under a sustained bot attack and you want to prevent the database from being flooded with junk view data. Re-enable tracking once the attack subsides.</p>'
        },
        'dedup': {
            title: '🔁 View Deduplication',
            body: '<p>Prevents the same visitor from being counted multiple times for the same post within a configurable time window.</p><p><strong>Client side:</strong> The tracker stores a timestamp in localStorage for each post viewed. Repeat visits within the window skip the API entirely. This handles same browser tab/window reopens.</p><p><strong>Server side:</strong> Before inserting a view, the API checks whether the same IP hash + post ID combination already exists in the database within the dedup window. This catches cross browser duplicates, such as a WhatsApp in app browser followed by opening the same link in Chrome.</p><p>When disabled, every page load records a view (subject only to IP throttle limits). The default window is 24 hours.</p>'
        },
        'post-history': {
            title: '🔍 Post View History',
            body: '<p>Browse or search for any post to see a detailed breakdown of its view metrics.</p><p><strong>Displayed count</strong> is the number stored in <code>_cspv_view_count</code> post meta, which is what visitors see on the front end.</p><p><strong>Tracked Views</strong> is the actual number of beacon view records in the log table for this post.</p><p>The <strong>timeline slider</strong> (7–80 days) controls the window shown in the daily chart and the Audit Trail. The chart can also be switched to an hourly view for the last 48 hours. Click the <strong>↗</strong> link next to any post title to open it on your site.</p>'
        }
    };

    document.querySelectorAll('.cspv-info-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var key = btn.getAttribute('data-info');
            var info = infoData[key];
            if (!info) return;
            document.getElementById('cspv-modal-title').textContent = info.title;
            document.getElementById('cspv-modal-body').innerHTML = info.body;
            openModal(document.getElementById('cspv-modal'));
        });
    });
    document.getElementById('cspv-modal-close').addEventListener('click', function() {
        closeModal(document.getElementById('cspv-modal'));
    });
    document.getElementById('cspv-modal').addEventListener('click', function(e) {
        if (e.target === this) closeModal(this);
    });

    // ── Post History tab ────────────────────────────────────────────
    (function() {
        var searchInput = document.getElementById('cspv-ph-search');
        var searchBtn   = document.getElementById('cspv-ph-search-btn');
        var listBox     = document.getElementById('cspv-ph-list');
        if (!searchInput || !listBox) return;

        function wireRowClicks() {
            listBox.querySelectorAll('.cspv-ph-row').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    if (e.target.classList.contains('cspv-ph-view-link')) return;
                    var postId = parseInt(el.dataset.id);
                    // Collapse if already open
                    if (el.classList.contains('active')) {
                        el.classList.remove('active');
                        var exp = el.nextElementSibling;
                        if (exp && exp.classList.contains('cspv-ph-expand')) exp.parentNode.removeChild(exp);
                        return;
                    }
                    // Collapse any other open row
                    listBox.querySelectorAll('.cspv-ph-row.active').forEach(function(r) {
                        r.classList.remove('active');
                        var exp = r.nextElementSibling;
                        if (exp && exp.classList.contains('cspv-ph-expand')) exp.parentNode.removeChild(exp);
                    });
                    el.classList.add('active');
                    loadPostExpand(postId, el);
                });
            });
        }
        wireRowClicks();

        // Sortable column headers
        var phSortCol = 'views';
        var phSortAsc = false;
        document.querySelectorAll('.cspv-ph-sort').forEach(function(hdr) {
            hdr.addEventListener('click', function() {
                var col = hdr.dataset.col;
                if (phSortCol === col) { phSortAsc = !phSortAsc; } else { phSortCol = col; phSortAsc = (col === 'title'); }
                var rows = Array.from(listBox.querySelectorAll('.cspv-ph-row'));
                rows.sort(function(a, b) {
                    var av, bv;
                    if (col === 'title') { av = a.dataset.title; bv = b.dataset.title; return phSortAsc ? av.localeCompare(bv) : bv.localeCompare(av); }
                    av = parseInt(a.dataset[col]) || 0; bv = parseInt(b.dataset[col]) || 0;
                    return phSortAsc ? av - bv : bv - av;
                });
                var header = document.getElementById('cspv-ph-header');
                rows.forEach(function(r, i) { r.style.background = i % 2 === 0 ? '#fff' : '#f8f9fa'; header.parentNode.appendChild(r); });
                document.querySelectorAll('.cspv-ph-sort').forEach(function(h) {
                    var label = h.textContent.replace(/ [\u25B2\u25BC]$/, '');
                    if (h.dataset.col === col) { label += phSortAsc ? ' \u25B2' : ' \u25BC'; }
                    h.textContent = label;
                });
            });
        });

        // Search button click
        function doSearch() {
            var q = searchInput.value.trim();
            if (q.length < 2) return;
            searchBtn.disabled = true;
            searchBtn.textContent = 'Searching...';
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cspv_post_search&nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(q)
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                searchBtn.disabled = false;
                searchBtn.textContent = 'Search Posts';
                if (!resp.success || !resp.data.length) {
                    listBox.innerHTML = '<div style="padding:20px;text-align:center;color:#888;">No posts found for \u201c' + q + '\u201d</div>';
                    return;
                }
                var html = '<div style="display:flex;align-items:center;padding:4px 16px;background:#1a5276;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;">' +
                    '<div style="flex:1;">Post</div><div style="width:120px;text-align:right;">Tracked Views</div></div>';
                resp.data.forEach(function(p, i) {
                    var bg = i % 2 === 0 ? '#fff' : '#f8f9fa';
                    var viewLink = p.url ? ' <a class="cspv-ph-view-link" href="' + escHtml(p.url) + '" target="_blank" rel="noopener" style="color:#2e86c1;font-size:11px;font-weight:400;margin-left:6px;text-decoration:none;" title="View post">\u2197</a>' : '';
                    html += '<div class="cspv-ph-row" data-id="' + p.id + '" data-url="' + escHtml(p.url || '') + '" style="display:flex;align-items:center;' +
                        'padding:2px 16px;background:' + bg + ';cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background .1s;line-height:1.3;">' +
                        '<div style="min-width:0;flex:1;font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' +
                        escHtml(p.title) + ' <span style="color:#aaa;font-weight:400;font-size:11px;">' + p.type + '</span>' + viewLink + '</div>' +
                        '<div style="width:120px;text-align:right;font-weight:800;font-size:14px;color:#2e86c1;font-variant-numeric:tabular-nums;">' + (p.pageviews || 0).toLocaleString() + '</div></div>';
                });
                listBox.innerHTML = html;
                wireRowClicks();
            })
            .catch(function() { searchBtn.disabled = false; searchBtn.textContent = 'Search Posts'; });
        }

        searchBtn.addEventListener('click', doSearch);
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });

        function escHtml(s) {
            var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
        }

        function loadPostExpand(postId, rowEl) {
            var expandDiv = document.createElement('div');
            expandDiv.className = 'cspv-ph-expand';
            expandDiv.style.cssText = 'padding:12px 16px;background:#f0f7ff;border-bottom:2px solid #2e86c1;font-size:12px;color:#888;';
            expandDiv.textContent = 'Loading…';
            rowEl.parentNode.insertBefore(expandDiv, rowEl.nextSibling);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cspv_post_history&nonce=' + encodeURIComponent(nonce) + '&post_id=' + postId
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp.success) { expandDiv.textContent = 'Failed to load.'; return; }
                renderExpand(expandDiv, resp.data);
            })
            .catch(function() { expandDiv.textContent = 'Network error.'; });
        }

        function renderExpand(expandDiv, data) {
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var tl = (data.timeline || []).filter(function(r) { return r.views > 0; }).slice(0, 30);
            var maxV = 0;
            tl.forEach(function(r) { if (r.views > maxV) maxV = r.views; });

            var html = '<div style="display:flex;align-items:center;padding:8px 16px 6px;justify-content:space-between;">' +
                '<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1a5276;">Last 30 days with views</span>' +
                '<span style="font-size:11px;color:#555;">Total: <strong style="color:#059669;">' + (data.meta_count || 0).toLocaleString() + '</strong></span>' +
                '</div>';

            if (tl.length === 0) {
                html += '<div style="padding:4px 16px 12px;color:#888;font-size:12px;">No views recorded in the last 30 days.</div>';
            } else {
                tl.forEach(function(r, i) {
                    var dp = r.day.split('-');
                    var dayStr = parseInt(dp[2]) + ' ' + months[parseInt(dp[1])-1];
                    var barW = maxV > 0 ? Math.round((r.views / maxV) * 100) : 0;
                    var selfHits = r.self_hits || 0;
                    var extStr = '', extHost = '';
                    if (r.top_ref) {
                        try { var u = new URL(r.top_ref); extHost = u.hostname.replace(/^www\./, ''); }
                        catch(e2) { extHost = r.top_ref.substring(0, 30); }
                        extStr = extHost + (r.ref_hits > 1 ? ' (' + r.ref_hits + ')' : '');
                    }
                    var hasSelf = selfHits > 0, hasExt = extStr !== '';
                    // Split bar: green = self proportion, blue = external proportion
                    var total = selfHits + (r.ref_hits || 0);
                    var selfPct = (hasSelf && total > 0) ? Math.round((selfHits / total) * barW) : (hasSelf ? barW : 0);
                    var extPct  = (hasExt  && total > 0) ? Math.round(((r.ref_hits || 0) / total) * barW) : (hasExt ? barW : 0);
                    var barHtml = '';
                    if (hasSelf) barHtml += '<div style="height:5px;width:' + selfPct + '%;background:linear-gradient(90deg,#059669,#6ee7b7);border-radius:3px;max-width:80px;min-width:2px;"></div>';
                    if (hasExt)  barHtml += '<div style="height:5px;width:' + extPct  + '%;background:linear-gradient(90deg,#2e86c1,#85c1e9);border-radius:3px;max-width:80px;min-width:2px;"></div>';
                    if (!hasSelf && !hasExt) barHtml = '<div style="height:5px;width:' + barW + '%;background:linear-gradient(90deg,#2e86c1,#85c1e9);border-radius:3px;max-width:120px;min-width:2px;"></div>';
                    var labelsHtml = '';
                    if (hasSelf) labelsHtml += '<span style="font-size:11px;font-weight:600;color:#059669;white-space:nowrap;">self: ' + selfHits + '</span>';
                    if (hasExt)  labelsHtml += '<span style="font-size:11px;font-weight:600;color:#2e86c1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(extStr) + '</span>';
                    var bg = i % 2 === 0 ? '#f8fbff' : '#fff';
                    html += '<div style="display:flex;align-items:center;padding:5px 16px;background:' + bg + ';border-bottom:1px solid #e8f0f8;font-size:12px;">' +
                        '<div style="width:75px;font-weight:600;color:#333;">' + dayStr + '</div>' +
                        '<div style="width:55px;text-align:right;font-weight:800;color:#2e86c1;font-variant-numeric:tabular-nums;">' + r.views.toLocaleString() + '</div>' +
                        '<div style="flex:1;padding-left:12px;display:flex;align-items:center;gap:4px;">' +
                        barHtml + labelsHtml +
                        '</div></div>';
                });
            }

            expandDiv.style.cssText = 'background:#f0f7ff;border-bottom:2px solid #2e86c1;';
            expandDiv.innerHTML = html;
        }


    })();

})();
<?php
wp_add_inline_script( 'cspv-stats-page', ob_get_clean() );
}
