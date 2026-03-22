<?php
/**
 * CloudScale Page Views - Dashboard Widget  v2.1.0
 *
 * WordPress admin dashboard widget showing:
 *   - Today's view count + delta vs yesterday
 *   - Last 7 days total
 *   - Time-period chart: 7 Hours / 1 Day / 7 Days / 1 Month / 6 Months
 *   - Top 3 posts and top 3 referrers for today (side by side)
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_enqueue_scripts', 'cspv_dashboard_widget_enqueue' );

/**
 * Enqueue Chart.js and widget scripts on the WordPress dashboard only.
 *
 * @since 1.0.0
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function cspv_dashboard_widget_enqueue( $hook ) {
    if ( 'index.php' !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'cspv-chartjs',
        CSPV_PLUGIN_URL . 'assets/js/chart.umd.min.js',
        array(),
        '4.4.1',
        true
    );

    // Script handle for inline JS added in the render callback.
    wp_register_script( 'cspv-dashboard-widget', false, array( 'cspv-chartjs' ), CSPV_VERSION, true );
    wp_enqueue_script( 'cspv-dashboard-widget' );

    $css = '#cspv_dashboard_widget .inside{padding:0;margin:0;}'
         . '.cspv-dw-banner{background:linear-gradient(135deg,#2d1b69 0%,#5b21b6 50%,#7c3aed 100%);padding:14px 16px 12px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}'
         . '.cspv-dw-today-count{font-size:38px;font-weight:800;color:#1db954;line-height:1;}'
         . '.cspv-dw-today-label{font-size:10px;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.06em;margin-top:3px;display:flex;align-items:center;flex-wrap:wrap;gap:4px;}'
         . '.cspv-dw-counts{margin-top:6px;line-height:1;}'
         . '.cspv-dw-periods{display:flex;gap:0;border-bottom:1px solid #eee;background:#fafafa;}'
         . '.cspv-dw-period-btn{flex:1;padding:7px 4px;font-size:11px;font-weight:600;text-align:center;cursor:pointer;border:none;background:transparent;color:#999;border-bottom:2px solid transparent;transition:color .15s,border-color .15s;white-space:nowrap;font-family:inherit;}'
         . '.cspv-dw-period-btn:hover{color:#059669;}'
         . '.cspv-dw-period-btn.active{color:#059669;border-bottom-color:#10b981;background:#fff;}'
         . '.cspv-dw-chart-wrap{padding:8px 14px 0;background:#fff;border-bottom:1px solid #f0f0f0;position:relative;height:120px;}'
         . '.cspv-dw-canvas{display:block;width:100%;height:110px;}'
         . '.cspv-dw-list-header{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#aaa;padding:7px 16px 3px;display:flex;justify-content:space-between;}'
         . '.cspv-dw-row{display:flex;align-items:center;padding:5px 16px;border-top:1px solid #f5f5f5;font-size:12px;gap:8px;}'
         . '.cspv-dw-row-title{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1a2332;text-decoration:none;font-weight:600;}'
         . '.cspv-dw-row-title:hover{text-decoration:underline;color:#059669;}'
         . '.cspv-dw-row-bar{height:3px;background:#d1fae5;border-radius:2px;flex-shrink:0;width:48px;overflow:hidden;}'
         . '.cspv-dw-row-fill{height:100%;background:linear-gradient(90deg,#059669,#34d399);border-radius:2px;}'
         . '.cspv-dw-row-num{font-weight:700;color:#059669;min-width:24px;text-align:right;flex-shrink:0;}'
         . '.cspv-dw-empty{padding:10px 16px;color:#bbb;font-size:12px;font-style:italic;}'
         . '.cspv-dw-lists{display:flex;gap:0;border-bottom:1px solid #f0f0f0;}'
         . '.cspv-dw-list-col{flex:1;min-width:0;}'
         . '.cspv-dw-list-col+.cspv-dw-list-col{border-left:1px solid #f0f0f0;}'
         . '.cspv-dw-list-col .cspv-dw-list-header{padding:7px 12px 3px;}'
         . '.cspv-dw-list-col .cspv-dw-row{padding:4px 12px;}'
         . '.cspv-dw-list-col .cspv-dw-empty{padding:8px 12px;}'
         . '.cspv-dw-ref-host{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1a2332;font-weight:600;font-size:12px;}'
         . '.cspv-dw-ref-link{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;font-size:12px;color:#1a3a8f;text-decoration:none;}'
         . '.cspv-dw-ref-link:hover{text-decoration:underline;color:#059669;}'
         . '.cspv-dw-ref-toggle-wrap{display:inline-flex;gap:0;margin-left:auto;}'
         . '.cspv-dw-ref-toggle{background:rgba(0,0,0,.08);border:none;color:#999;font-size:9px;font-weight:500;text-transform:uppercase;letter-spacing:.03em;padding:2px 7px;cursor:pointer;transition:background .15s,color .15s;line-height:1.4;}'
         . '.cspv-dw-ref-toggle:first-child{border-radius:3px 0 0 3px;}'
         . '.cspv-dw-ref-toggle:last-child{border-radius:0 3px 3px 0;}'
         . '.cspv-dw-ref-toggle:hover{background:rgba(0,0,0,.12);}'
         . '.cspv-dw-ref-toggle.active{background:#059669;color:#fff;font-weight:800;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}'
         . '.cspv-dw-footer{padding:8px 16px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center;}'
         . '.cspv-dw-link{display:inline-block;padding:5px 12px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:11px;font-weight:700;text-decoration:none;border-radius:20px;letter-spacing:.03em;transition:opacity .15s;}'
         . '.cspv-dw-link:hover{opacity:.85;color:#fff;text-decoration:none;}'
         . '.cspv-dw-shield{font-size:11px;}'
         . '.cspv-dw-shield.on{color:#1db954;font-weight:600;}'
         . '.cspv-dw-shield.off{color:#e53e3e;}';

    wp_register_style( 'cspv-dashboard-widget', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-dashboard-widget' );
    wp_add_inline_style( 'cspv-dashboard-widget', $css );
}

add_action( 'wp_dashboard_setup', 'cspv_register_dashboard_widget' );

/**
 * Register the CloudScale Page Views dashboard widget.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'cspv_dashboard_widget',
        '☁ CloudScale Analytics v' . CSPV_VERSION,
        'cspv_render_dashboard_widget',
        null,
        null,
        'normal',
        'high'
    );
}

/**
 * Render the dashboard widget HTML including the chart and summary stats.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_render_dashboard_widget() {
    global $wpdb;
    $table = cspv_views_table();
    $cnt   = cspv_count_expr();

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    $today   = current_time( 'Y-m-d' );
    $today_s = $today . ' 00:00:00';
    $today_e = $today . ' 23:59:59';
    $yest    = wp_date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
    $yest_s  = $yest . ' 00:00:00';
    $yest_e  = $yest . ' 23:59:59';
    $week_s  = wp_date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ) . ' 00:00:00';

    $today_views    = 0;
    $yest_views     = 0;
    $week_views     = 0;


    // Days of tracking data (used to gate period comparisons)
    $data_days = 0;
    if ( $table_exists ) {
        $earliest = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name
        if ( $earliest ) {
            $data_days = (int) floor( ( time() - strtotime( $earliest ) ) / 86400 );
        }
    }

    if ( $table_exists ) {
        $today_views = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $today_s, $today_e ) );

        $yest_views = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $yest_s, $yest_e ) );

        $week_views = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE viewed_at >= %s", $week_s ) );

        // Rolling 24h: shared stats library (single source of truth)
        $r24               = cspv_rolling_24h_views();
        $rolling_24h_views = $r24['current'];
        $prev_rolling_24h  = $r24['prior'];

        // Top pages and referrers are now fetched via AJAX on tab switch
    }

    // Delta badge placeholder (computed after rolling 24h data is ready)
    $delta_html = '';

    // Build all four period datasets in PHP so no AJAX needed
    // 7 Hours: last 7 complete hours + current hour
    $hour_labels = array();
    $hour_values = array();
    $now_hour    = (int) current_time( 'G' ); // 0-23
    for ( $h = 6; $h >= 0; $h-- ) {
        $hr       = ( $now_hour - $h + 24 ) % 24;
        $label    = sprintf( '%02d:00', $hr );
        $hour_labels[] = $label;
        if ( $table_exists ) {
            $hr_s = current_time( 'Y-m-d' ) . ' ' . sprintf( '%02d:00:00', $hr );
            $hr_e = current_time( 'Y-m-d' ) . ' ' . sprintf( '%02d:59:59', $hr );
            // Handle midnight wrap: if hour rolled back to yesterday
            if ( $h > $now_hour ) {
                $hr_s = $yest . ' ' . sprintf( '%02d:00:00', $hr );
                $hr_e = $yest . ' ' . sprintf( '%02d:59:59', $hr );
            }
            $hour_values[] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
                $hr_s, $hr_e ) );
        } else {
            $hour_values[] = 0;
        }
    }

    // Prior 7 hours: same hours yesterday for comparison
    $prev_7h_views = 0;
    if ( $table_exists ) {
        for ( $h = 6; $h >= 0; $h-- ) {
            $hr = ( $now_hour - $h + 24 ) % 24;
            $hr_s = $yest . ' ' . sprintf( '%02d:00:00', $hr );
            $hr_e = $yest . ' ' . sprintf( '%02d:59:59', $hr );
            $prev_7h_views += (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
                $hr_s, $hr_e ) );
        }
    }

    // 1 Day: 24 hourly buckets from this hour yesterday to this hour today
    $day1_labels = array();
    $day1_values = array();
    for ( $h = 23; $h >= 0; $h-- ) {
        $ts       = strtotime( "-{$h} hours", strtotime( current_time( 'Y-m-d H:00:00' ) ) );
        $hr       = (int) wp_date( 'G', $ts );
        $d        = wp_date( 'Y-m-d', $ts );
        $label    = sprintf( '%02d:00', $hr );
        $day1_labels[] = $label;
        if ( $table_exists ) {
            $hr_s = $d . ' ' . sprintf( '%02d:00:00', $hr );
            $hr_e = $d . ' ' . sprintf( '%02d:59:59', $hr );
            $day1_values[] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
                $hr_s, $hr_e ) );
        } else {
            $day1_values[] = 0;
        }
    }

    // Previous 24 hours (for comparison)
    $prev_day1_views = 0;
    if ( $table_exists ) {
        $day1_end   = current_time( 'Y-m-d H:00:00' );
        $day1_start = wp_date( 'Y-m-d H:i:s', strtotime( '-24 hours', strtotime( $day1_end ) ) );
        $prev_start = wp_date( 'Y-m-d H:i:s', strtotime( '-48 hours', strtotime( $day1_end ) ) );
        $prev_day1_views = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $prev_start, $day1_start ) );
    }

    // Delta badge: today vs yesterday (initial render is 7 Hours tab)
    // Rolling 24h values available for JS when switching to 1 Day tab
    $rolling_24h = isset( $rolling_24h_views ) ? $rolling_24h_views : array_sum( $day1_values );
    $prev_24h    = isset( $prev_rolling_24h ) ? $prev_rolling_24h : $prev_day1_views;
    // Initial render is 7 Hours tab, so use 7h values
    $init_current = array_sum( $hour_values );
    $init_prev    = $prev_7h_views;
    if ( $init_prev > 0 && $data_days >= 2 ) {
        $delta = $init_current - $init_prev;
        $pct   = round( ( $delta / $init_prev ) * 100 );
        $arrow = $delta >= 0 ? '↑' : '↓';
        $color = $delta >= 0 ? '#1db954' : '#e53e3e';
        $delta_html = '<span style="color:' . esc_attr( $color ) . ';">' . esc_html( $arrow ) . ' ' . absint( $pct ) . '%</span>';
    } else {
        $delta_html = '<span style="color:rgba(255,255,255,.6);font-size:24px;">Insufficient Data</span>';
    }

    // 7 Days
    $day7_labels = array();
    $day7_values = array();
    $prev7_views = 0;
    for ( $i = 6; $i >= 0; $i-- ) {
        $d = wp_date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
        $day7_labels[] = wp_date( 'j M', strtotime( $d ) );
        if ( $table_exists ) {
            $day7_values[] = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT {$cnt} FROM `{$table}` WHERE DATE(viewed_at) = %s", $d ) );
        } else {
            $day7_values[] = 0;
        }
    }
    if ( $table_exists ) {
        $prev7_start = wp_date( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) ) . ' 00:00:00';
        $prev7_end   = wp_date( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) ) . ' 23:59:59';
        $prev7_views = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT {$cnt} FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $prev7_start, $prev7_end ) );
    }

    // 1 Month (28 days) — query once, fill array
    $month_labels = array();
    $month_values = array();
    $m28_s        = wp_date( 'Y-m-d', strtotime( '-27 days', strtotime( $today ) ) ) . ' 00:00:00';
    $raw_month    = array();
    if ( $table_exists ) {
        $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
            "SELECT DATE(viewed_at) AS day, {$cnt} AS views
             FROM `{$table}` WHERE viewed_at >= %s
             GROUP BY day", $m28_s ) );
        foreach ( $rows as $r ) { $raw_month[ $r->day ] = (int) $r->views; }
    }
    for ( $i = 27; $i >= 0; $i-- ) {
        $d              = wp_date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
        $dow            = (int) wp_date( 'N', strtotime( $d ) );
        $month_labels[] = $dow === 1 ? wp_date( 'j M', strtotime( $d ) ) : wp_date( 'j', strtotime( $d ) );
        $month_values[] = $raw_month[ $d ] ?? 0;
    }

    // 6 Months — group by week (26 weeks)
    $m6_labels = array();
    $m6_values = array();
    $m6_s      = wp_date( 'Y-m-d', strtotime( '-181 days', strtotime( $today ) ) ) . ' 00:00:00';
    $raw_6m    = array();
    if ( $table_exists ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(viewed_at, '%%Y-%%u') AS week_key,
                    MIN(DATE(viewed_at)) AS week_start,
                    {$cnt} AS views
             FROM `{$table}` WHERE viewed_at >= %s
             GROUP BY week_key ORDER BY week_key ASC", $m6_s ) );
        foreach ( $rows as $r ) { $raw_6m[ $r->week_key ] = array( 'views' => (int) $r->views, 'start' => $r->week_start ); }
    }
    // Build 26 week slots
    for ( $i = 25; $i >= 0; $i-- ) {
        $week_start     = wp_date( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days', strtotime( $today ) ) );
        $wk             = wp_date( 'Y-W', strtotime( $week_start ) );
        $m6_labels[]    = wp_date( 'j M', strtotime( $week_start ) );
        $m6_values[]    = isset( $raw_6m[ str_replace( '-', '-', $wk ) ] )
                            ? $raw_6m[ str_replace( '-', '-', $wk ) ]['views'] : 0;
    }
    // Fill 6m from raw (simpler — just use ordered results directly)
    if ( ! empty( $raw_6m ) ) {
        $m6_labels = array();
        $m6_values = array();
        foreach ( $raw_6m as $wk => $data ) {
            $m6_labels[] = wp_date( 'j M', strtotime( $data['start'] ) );
            $m6_values[] = $data['views'];
        }
        // Ensure exactly 26 entries, pad front with zeros if needed
        while ( count( $m6_labels ) < 26 ) {
            array_unshift( $m6_labels, '' );
            array_unshift( $m6_values, 0 );
        }
    } else {
        // No data — still generate 26 labeled slots
        $m6_labels = array();
        $m6_values = array();
        for ( $i = 25; $i >= 0; $i-- ) {
            $d           = wp_date( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days', strtotime( $today ) ) );
            $m6_labels[] = wp_date( 'j M', strtotime( $d ) );
            $m6_values[] = 0;
        }
    }

    $stats_url   = admin_url( 'tools.php?page=cloudscale-wordpress-free-analytics' );
    $throttle_on = cspv_throttle_enabled();
    $blocked     = count( cspv_get_blocklist() );
    $widget_id   = 'cspv-dw-' . substr( md5( uniqid() ), 0, 6 );

    $periods = array(
        'hours'  => array( 'label' => '7 Hours',  'labels' => $hour_labels,  'values' => $hour_values,  'total' => $today_views,  'summary' => 'Views today' ),
        'day'    => array( 'label' => '1 Day',    'labels' => $day1_labels,  'values' => $day1_values,  'total' => array_sum( $day1_values ),  'summary' => 'Last 24 hours' ),
        'days'   => array( 'label' => '7 Days',   'labels' => $day7_labels,  'values' => $day7_values,  'total' => array_sum( $day7_values ),  'summary' => 'Last 7 days' ),
        'month'  => array( 'label' => '1 Month',  'labels' => $month_labels, 'values' => $month_values, 'total' => array_sum( $month_values ), 'summary' => 'Last 30 days' ),
        'months' => array( 'label' => '6 Months', 'labels' => $m6_labels,    'values' => $m6_values,    'total' => array_sum( $m6_values ),    'summary' => 'Last 6 months' ),
    );
    ?>

<!-- Banner -->
<div class="cspv-dw-banner">
    <div>
        <div class="cspv-dw-today-count" id="cspv-dw-main-count"><?php echo wp_kses_post( $delta_html ); ?></div>
        <div class="cspv-dw-today-label">
            <span id="cspv-dw-main-label">Last 7 hours</span>
        </div>
        <div class="cspv-dw-counts" id="cspv-dw-counts"><?php
            if ( $init_prev > 0 ) {
                echo '<span style="color:rgba(255,255,255,.85);font-size:14px;font-weight:600;">'
                   . number_format( $init_current ) . '</span>'
                   . '<span style="color:rgba(255,255,255,.5);font-size:12px;"> vs </span>'
                   . '<span style="color:rgba(255,255,255,.65);font-size:14px;font-weight:600;">'
                   . number_format( $init_prev ) . '</span>';
            } else {
                echo '<span style="color:rgba(255,255,255,.5);font-size:12px;">'
                   . number_format( $init_current ) . ' views recorded</span>';
            }
        ?></div>
    </div>
</div>

<!-- Period selector -->
<div class="cspv-dw-periods" id="<?php echo esc_attr( $widget_id ); ?>-periods">
    <?php foreach ( $periods as $key => $p ) : ?>
    <button class="cspv-dw-period-btn<?php echo $key === 'hours' ? ' active' : ''; ?>"
            data-period="<?php echo esc_attr( $key ); ?>">
        <?php echo esc_html( $p['label'] ); ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- Chart -->
<div class="cspv-dw-chart-wrap">
    <canvas id="<?php echo esc_attr( $widget_id ); ?>" class="cspv-dw-canvas"></canvas>
</div>

<!-- Top posts + Top referrers (side by side, JS driven) -->
<div class="cspv-dw-lists">
    <div class="cspv-dw-list-col">
        <div class="cspv-dw-list-header"><span id="cspv-dw-pages-header">Top pages 7h</span><span>Views</span></div>
        <div id="cspv-dw-top-pages">
            <div class="cspv-dw-empty" style="color:#999;">Loading...</div>
        </div>
    </div>
    <div class="cspv-dw-list-col">
        <div class="cspv-dw-list-header">
            <span id="cspv-dw-ref-header-label">Top referrers 7h</span>
            <span class="cspv-dw-ref-toggle-wrap">
                <button class="cspv-dw-ref-toggle active" data-ref-view="sites">Sites</button>
                <button class="cspv-dw-ref-toggle" data-ref-view="pages">Pages</button>
            </span>
        </div>
        <div id="cspv-dw-ref-sites">
            <div class="cspv-dw-empty" style="color:#999;">Loading...</div>
        </div>
        <div id="cspv-dw-ref-pages" style="display:none;">
            <div class="cspv-dw-empty" style="color:#999;">Loading...</div>
        </div>
    </div>
</div>

<!-- Site Health -->
<div style="padding:12px 16px 0;border-top:1px solid #f0f0f0;">
    <?php cspv_render_site_health_html( 'widget' ); ?>
</div>

<!-- Footer -->
<div class="cspv-dw-footer">
    <a href="<?php echo esc_url( $stats_url ); ?>" class="cspv-dw-link">View Full Statistics</a>
    <span class="cspv-dw-shield <?php echo esc_attr( $throttle_on ? 'on' : 'off' ); ?>">
        <?php echo esc_html( $throttle_on
            ? '🛡 ' . ( $blocked > 0 ? number_format( $blocked ) . ' blocked' : 'Protection on' )
            : '⚠ Protection off' ); ?>
    </span>
</div>

<?php
    $js_init = 'var cspvDW=' . wp_json_encode( array(
        'canvasId'      => $widget_id,
        'periodsId'     => $widget_id . '-periods',
        'datasets'      => $periods,
        'nonce'         => wp_create_nonce( 'cspv_widget_lists' ),
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'todayViews'    => (int) $today_views,
        'yestViews'     => (int) $yest_views,
        'prev7hViews'   => (int) $prev_7h_views,
        'dataDays'      => (int) $data_days,
        'weekViews'     => (int) $week_views,
        'prev7Views'    => (int) $prev7_views,
        'prevDay1Views' => (int) $prev_day1_views,
        'rolling24h'    => (int) $rolling_24h,
        'prevRolling24h'=> (int) $prev_24h,
    ) ) . ';';

    wp_add_inline_script( 'cspv-dashboard-widget', $js_init );
    ob_start();
    ?>
(function() {
    var canvasId  = cspvDW.canvasId;
    var periodsId = cspvDW.periodsId;

    var datasets = cspvDW.datasets;
    var chartInst = null;
    var activePeriod = "hours";

    function makeColors(values, period) {
        return values.map(function(v, i) {
            var isLast = i === values.length - 1;
            if (period === "days" || period === "hours" || period === "day") {
                return isLast ? "#059669" : "rgba(5,150,105,0.25)";
            }
            return "rgba(5,150,105,0.35)";
        });
    }

    function makeHoverColors(values, period) {
        return values.map(function(v, i) {
            var isLast = i === values.length - 1;
            if (period === "days" || period === "hours" || period === "day") {
                return isLast ? "#10b981" : "rgba(5,150,105,0.55)";
            }
            return "#10b981";
        });
    }

    var currentPeriod = "hours";
    var widgetNonce = cspvDW.nonce;
    var widgetAjax  = cspvDW.ajaxUrl;
    var listsCache  = {};
    var todayViews = cspvDW.todayViews;
    var yestViews  = cspvDW.yestViews;
    var prev7hViews = cspvDW.prev7hViews;
    var dataDays    = cspvDW.dataDays;
    // Minimum data_days needed to show a comparison (same as site-health: days * 2)
    var requiredDays = { hours: 2, day: 2, days: 14, month: 56, months: 360 };
    var weekViews  = cspvDW.weekViews;
    var prev7Views = cspvDW.prev7Views;
    var prevDay1Views = cspvDW.prevDay1Views;
    var rolling24h    = cspvDW.rolling24h;
    var prevRolling24h = cspvDW.prevRolling24h;

    function formatDelta(current, previous) {
        if (previous <= 0) return '';
        var delta = current - previous;
        var pct   = Math.round((delta / previous) * 100);
        var arrow = delta >= 0 ? '↑' : '↓';
        var color = delta >= 0 ? '#1db954' : '#e53e3e';
        return '<span style="font-size:11px;color:' + color + ';font-weight:700;margin-left:6px;white-space:nowrap;">'
            + arrow + ' ' + Math.abs(pct) + '% vs prior period</span>';
    }

    function updateBanner(period) {
        var data  = datasets[period];
        var total = data.total || 0;
        var mainCount = document.getElementById('cspv-dw-main-count');
        var mainLabel = document.getElementById('cspv-dw-main-label');
        var countsEl  = document.getElementById('cspv-dw-counts');
        if (!mainCount) return;

        var current = 0, previous = 0, label = '';
        if (period === 'hours') {
            current = total; previous = prev7hViews; label = 'Last 7 hours';
        } else if (period === 'day') {
            current = rolling24h; previous = prevRolling24h; label = 'Last 24 hours';
        } else if (period === 'days') {
            current = total; previous = prev7Views; label = 'Last 7 days';
        } else {
            current = total; previous = 0; label = data.summary;
        }

        // Hero: percentage or insufficient data
        // Gate: must have enough historical data (same rule as Site Health: days * 2)
        var hasEnoughData = previous > 0 && dataDays >= (requiredDays[period] || 2);
        if (hasEnoughData) {
            var pct   = Math.round(((current - previous) / previous) * 100);
            var arrow = pct >= 0 ? '↑' : '↓';
            var color = pct >= 0 ? '#1db954' : '#e53e3e';
            mainCount.innerHTML = '<span style="color:' + color + ';">' + arrow + ' ' + Math.abs(pct) + '%</span>';
            countsEl.innerHTML = '<span style="color:rgba(255,255,255,.85);font-size:14px;font-weight:600;">'
                + current.toLocaleString() + '</span>'
                + '<span style="color:rgba(255,255,255,.5);font-size:12px;"> vs </span>'
                + '<span style="color:rgba(255,255,255,.65);font-size:14px;font-weight:600;">'
                + previous.toLocaleString() + '</span>';
        } else {
            mainCount.innerHTML = '<span style="color:rgba(255,255,255,.6);font-size:24px;">Insufficient Data</span>';
            var needed = (requiredDays[period] || 2);
            var extra = needed - dataDays;
            countsEl.innerHTML = '<span style="color:rgba(255,255,255,.5);font-size:12px;">'
                + current.toLocaleString() + ' views recorded'
                + (extra > 0 ? ' &middot; need ' + needed + ' days' : '') + '</span>';
        }
        mainLabel.textContent = label;
    }

    var periodLabels = { hours: '7h', day: '24h', days: '7 days', month: '30 days', months: '6 months' };

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function renderLists(data, period) {
        var pLabel = periodLabels[period] || '24h';
        var pagesEl = document.getElementById('cspv-dw-top-pages');
        var refSitesEl = document.getElementById('cspv-dw-ref-sites');
        var refPagesEl = document.getElementById('cspv-dw-ref-pages');
        var pagesHeaderEl = document.getElementById('cspv-dw-pages-header');
        var refHeaderEl   = document.getElementById('cspv-dw-ref-header-label');

        // Update headers
        if (pagesHeaderEl) pagesHeaderEl.textContent = 'Top pages ' + pLabel;
        if (refHeaderEl)   refHeaderEl.textContent   = 'Top referrers ' + pLabel;

        // Render top pages
        if (!data.top_pages || data.top_pages.length === 0) {
            pagesEl.innerHTML = '<div class="cspv-dw-empty">No views yet.</div>';
        } else {
            var maxV = data.top_pages[0].views;
            var h = '';
            for (var i = 0; i < data.top_pages.length; i++) {
                var p = data.top_pages[i];
                var pct = maxV > 0 ? Math.round((p.views / maxV) * 100) : 0;
                var titleHtml = p.url
                    ? '<a href="' + esc(p.url) + '" target="_blank" class="cspv-dw-row-title">' + esc(p.title) + '</a>'
                    : '<span class="cspv-dw-row-title">' + esc(p.title) + '</span>';
                h += '<div class="cspv-dw-row">' + titleHtml
                   + '<div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:' + pct + '%"></div></div>'
                   + '<span class="cspv-dw-row-num">' + p.views.toLocaleString() + '</span></div>';
            }
            pagesEl.innerHTML = h;
        }

        // Render referrer domains
        if (!data.ref_domains || data.ref_domains.length === 0) {
            refSitesEl.innerHTML = '<div class="cspv-dw-empty">No referrers yet.</div>';
        } else {
            var maxR = data.ref_domains[0].views;
            var rh = '';
            for (var j = 0; j < data.ref_domains.length; j++) {
                var d = data.ref_domains[j];
                var rpct = maxR > 0 ? Math.round((d.views / maxR) * 100) : 0;
                rh += '<div class="cspv-dw-row"><span class="cspv-dw-ref-host">' + esc(d.host) + '</span>'
                    + '<div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:' + rpct + '%"></div></div>'
                    + '<span class="cspv-dw-row-num">' + d.views.toLocaleString() + '</span></div>';
            }
            refSitesEl.innerHTML = rh;
        }

        // Render referrer pages
        if (!data.ref_pages || data.ref_pages.length === 0) {
            refPagesEl.innerHTML = '<div class="cspv-dw-empty">No referrer pages yet.</div>';
        } else {
            var maxP = data.ref_pages[0].views;
            var ph = '';
            for (var k = 0; k < data.ref_pages.length; k++) {
                var rp = data.ref_pages[k];
                var ppct = maxP > 0 ? Math.round((rp.views / maxP) * 100) : 0;
                var display = rp.host;
                try { var u = new URL(rp.url); display = u.hostname + u.pathname.replace(/\/$/, ''); } catch(e) {}
                ph += '<div class="cspv-dw-row">'
                    + '<a href="' + esc(rp.url) + '" target="_blank" class="cspv-dw-ref-link" title="' + esc(rp.url) + '">' + esc(display) + '</a>'
                    + '<div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:' + ppct + '%"></div></div>'
                    + '<span class="cspv-dw-row-num">' + rp.views.toLocaleString() + '</span></div>';
            }
            refPagesEl.innerHTML = ph;
        }
    }

    function fetchLists(period) {
        if (listsCache[period]) {
            renderLists(listsCache[period], period);
            return;
        }
        // Show loading state
        var pagesEl = document.getElementById('cspv-dw-top-pages');
        if (pagesEl) pagesEl.innerHTML = '<div class="cspv-dw-empty" style="color:#999;">Loading...</div>';

        var fd = new FormData();
        fd.append('action', 'cspv_widget_lists');
        fd.append('nonce', widgetNonce);
        fd.append('period', period);
        fetch(widgetAjax, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success && resp.data) {
                    listsCache[period] = resp.data;
                    renderLists(resp.data, period);
                }
            });
    }

    function drawChart(period) {
        currentPeriod = period;
        updateBanner(period);
        fetchLists(period);
        var canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) { return; }

        var data   = datasets[period];
        var labels = data.labels;
        var values = data.values;

        // Determine which x-axis labels to show based on dataset size
        // Always show at least a few — never blank axis even if all values are 0
        var maxTicks = labels.length <= 7 ? labels.length : (labels.length <= 28 ? 7 : 8);

        if (chartInst) { chartInst.destroy(); }

        chartInst = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: makeColors(values, period),
                    hoverBackgroundColor: makeHoverColors(values, period),
                    borderRadius: 2,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#2d1b69',
                        titleColor: 'rgba(255,255,255,.65)',
                        bodyColor: '#fff',
                        bodyFont: { size: 12, weight: '700' },
                        padding: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(items) {
                                var lbl = items[0].label || '';
                                if ((currentPeriod === 'hours' || currentPeriod === 'day') && lbl.indexOf(':') !== -1) {
                                    var h = parseInt(lbl.split(':')[0], 10);
                                    var suffix = h >= 12 ? ' PM' : ' AM';
                                    return lbl + suffix;
                                }
                                return lbl;
                            },
                            label: function(c) { return c.parsed.y.toLocaleString() + ' views'; }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#7a5230',
                            font: { size: 12, weight: '600' },
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: maxTicks,
                            padding: 4,
                        }
                    },
                    y: {
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        suggestedMax: Math.max(1, Math.max.apply(null, values)),
                        grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false },
                        border: { display: false },
                        ticks: {
                            color: '#7a5230',
                            font: { size: 11, weight: '600' },
                            maxTicksLimit: 4,
                            padding: 4,
                            precision: 0,
                        }
                    }
                },
                layout: { padding: { top: 2, left: 0, right: 2, bottom: 0 } }
            }
        });
    }

    function init() {
        var canvas = document.getElementById(canvasId);
        if (!canvas) { return; }

        // Restore saved period from localStorage
        var savedPeriod = null;
        try { savedPeriod = localStorage.getItem('cspv_dw_period'); } catch(e) {}
        if (savedPeriod && datasets[savedPeriod]) {
            activePeriod = savedPeriod;
        }

        // Wire period buttons
        var btns = document.getElementById(periodsId);
        if (btns) {
            btns.querySelectorAll('.cspv-dw-period-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    btns.querySelectorAll('.cspv-dw-period-btn').forEach(function(b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    activePeriod = btn.dataset.period;
                    try { localStorage.setItem('cspv_dw_period', activePeriod); } catch(e) {}
                    drawChart(activePeriod);
                });
            });

            // Set the correct button active on load
            btns.querySelectorAll('.cspv-dw-period-btn').forEach(function(b) {
                b.classList.remove('active');
                if (b.dataset.period === activePeriod) { b.classList.add('active'); }
            });
        }

        drawChart(activePeriod);
    }

    // Chart.js is enqueued locally via wp_enqueue_script (cspv-chartjs).
    if (window.Chart) {
        init();
    }

    // ── Referrer sites/pages toggle ─────────────────────────────
    var savedRefView = null;
    try { savedRefView = localStorage.getItem('cspv_dw_ref_view'); } catch(e) {}

    function applyRefView(mode) {
        document.querySelectorAll('.cspv-dw-ref-toggle').forEach(function(b) {
            b.classList.remove('active');
            if (b.dataset.refView === mode) { b.classList.add('active'); }
        });
        var sites = document.getElementById('cspv-dw-ref-sites');
        var pages = document.getElementById('cspv-dw-ref-pages');
        if (sites && pages) {
            sites.style.display = (mode === 'pages') ? 'none' : '';
            pages.style.display = (mode === 'pages') ? '' : 'none';
        }
    }

    if (savedRefView) { applyRefView(savedRefView); }

    document.querySelectorAll(".cspv-dw-ref-toggle").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var mode = btn.dataset.refView;
            applyRefView(mode);
            try { localStorage.setItem("cspv_dw_ref_view", mode); } catch(e) {}
        });
    });
})();
    <?php
    wp_add_inline_script( 'cspv-dashboard-widget', ob_get_clean() );
}

/* ─── AJAX: fetch top pages + referrers for a period ─────────────── */
add_action( 'wp_ajax_cspv_widget_lists', 'cspv_ajax_widget_lists' );
/**
 * AJAX handler: return top pages and referrers for a given time period.
 *
 * Requires manage_options capability and a valid nonce. Returns JSON
 * with pages and referrers arrays for the requested period.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_widget_lists() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden' );
    }
    check_ajax_referer( 'cspv_widget_lists', 'nonce' );

    $period = sanitize_text_field( wp_unslash( $_POST['period'] ?? 'day' ) );

    // Compute date range based on period
    $now = new DateTime( 'now', wp_timezone() );
    switch ( $period ) {
        case 'hours':
            $from = clone $now;
            $from->modify( '-7 hours' );
            break;
        case 'day':
            $from = clone $now;
            $from->modify( '-24 hours' );
            break;
        case 'days':
            $from = clone $now;
            $from->modify( '-7 days' );
            break;
        case 'month':
            $from = clone $now;
            $from->modify( '-30 days' );
            break;
        case 'months':
            $from = clone $now;
            $from->modify( '-6 months' );
            break;
        default:
            $from = clone $now;
            $from->modify( '-24 hours' );
    }
    $from_str = $from->format( 'Y-m-d H:i:s' );
    $to_str   = $now->format( 'Y-m-d H:i:s' );

    // Top pages (shared function)
    $top_pages = cspv_top_pages( $from_str, $to_str, 3 );

    // Referrers (domains + pages)
    $ref_domains = cspv_top_referrer_domains( $from_str, $to_str, 3 );
    $ref_pages   = cspv_top_referrer_pages( $from_str, $to_str, 3 );

    wp_send_json_success( array(
        'top_pages'    => $top_pages,
        'ref_domains'  => $ref_domains,
        'ref_pages'    => $ref_pages,
    ) );
}
