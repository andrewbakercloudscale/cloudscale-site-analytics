<?php
/**
 * CloudScale Page Views - Dashboard Widget  v2.1.0
 *
 * WordPress admin dashboard widget showing:
 *   - Today's view count + delta vs yesterday
 *   - Last 7 days total
 *   - Time-period chart: 7 Hours / 1 Day / 7 Days / 1 Month / 6 Months
 *   - Top 3 posts and top 3 referrers for today (side by side)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_dashboard_setup', 'cspv_register_dashboard_widget' );

function cspv_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'cspv_dashboard_widget',
        '☁ CloudScale Page Views',
        'cspv_render_dashboard_widget',
        null,
        null,
        'normal',
        'high'
    );
}

function cspv_render_dashboard_widget() {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    $today   = current_time( 'Y-m-d' );
    $today_s = $today . ' 00:00:00';
    $today_e = $today . ' 23:59:59';
    $yest    = date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );
    $yest_s  = $yest . ' 00:00:00';
    $yest_e  = $yest . ' 23:59:59';
    $week_s  = date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ) . ' 00:00:00';

    $today_views    = 0;
    $yest_views     = 0;
    $week_views     = 0;
    $top_today      = array();
    $top_referrers  = array();
    $top_ref_pages  = array();

    if ( $table_exists ) {
        $today_views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $today_s, $today_e ) );

        $yest_views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $yest_s, $yest_e ) );

        $week_views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at >= %s", $week_s ) );

        $top_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, COUNT(*) AS views FROM `{$table}`
             WHERE viewed_at BETWEEN %s AND %s
             GROUP BY post_id ORDER BY views DESC LIMIT 3",
            $today_s, $today_e ) );

        // Top 3 referrers today (requires referrer column)
        $has_referrer = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'referrer' ) );
        if ( $has_referrer ) {
            $own_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $ref_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT referrer, COUNT(*) AS view_count FROM `{$table}`
                 WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
                 GROUP BY referrer ORDER BY view_count DESC LIMIT 30",
                $today_s, $today_e ) );
            if ( is_array( $ref_rows ) ) {
                $host_totals = array();
                foreach ( $ref_rows as $r ) {
                    $host = wp_parse_url( $r->referrer, PHP_URL_HOST );
                    if ( ! $host ) { $host = $r->referrer; }
                    if ( $own_host && strcasecmp( $host, $own_host ) === 0 ) { continue; }
                    if ( ! isset( $host_totals[ $host ] ) ) { $host_totals[ $host ] = 0; }
                    $host_totals[ $host ] += (int) $r->view_count;
                    $top_ref_pages[] = array(
                        'url'   => $r->referrer,
                        'views' => (int) $r->view_count,
                    );
                }
                arsort( $host_totals );
                $top_referrers = array_slice( $host_totals, 0, 3, true );
                usort( $top_ref_pages, function( $a, $b ) { return $b['views'] - $a['views']; } );
                $top_ref_pages = array_slice( $top_ref_pages, 0, 3 );
            }
        }
    }

    // Delta badge (initial state shown before JS takes over)
    $delta_html = '';
    if ( $yest_views > 0 ) {
        $delta = $today_views - $yest_views;
        $pct   = round( ( $delta / $yest_views ) * 100 );
        $arrow = $delta >= 0 ? '↑' : '↓';
        $color = $delta >= 0 ? '#1db954' : '#e53e3e';
        $delta_html = '<span style="font-size:11px;color:' . $color . ';font-weight:700;margin-left:6px;white-space:nowrap;">'
                    . $arrow . ' ' . abs( $pct ) . '% vs prior period</span>';
    }

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
            $hour_values[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
                $hr_s, $hr_e ) );
        } else {
            $hour_values[] = 0;
        }
    }

    // 1 Day: 24 hourly buckets from this hour yesterday to this hour today
    $day1_labels = array();
    $day1_values = array();
    for ( $h = 23; $h >= 0; $h-- ) {
        $ts       = strtotime( "-{$h} hours", strtotime( current_time( 'Y-m-d H:00:00' ) ) );
        $hr       = (int) date( 'G', $ts );
        $d        = date( 'Y-m-d', $ts );
        $label    = sprintf( '%02d:00', $hr );
        $day1_labels[] = $label;
        if ( $table_exists ) {
            $hr_s = $d . ' ' . sprintf( '%02d:00:00', $hr );
            $hr_e = $d . ' ' . sprintf( '%02d:59:59', $hr );
            $day1_values[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
                $hr_s, $hr_e ) );
        } else {
            $day1_values[] = 0;
        }
    }

    // Previous 24 hours (for comparison)
    $prev_day1_views = 0;
    if ( $table_exists ) {
        $day1_end   = current_time( 'Y-m-d H:00:00' );
        $day1_start = date( 'Y-m-d H:i:s', strtotime( '-24 hours', strtotime( $day1_end ) ) );
        $prev_start = date( 'Y-m-d H:i:s', strtotime( '-48 hours', strtotime( $day1_end ) ) );
        $prev_day1_views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $prev_start, $day1_start ) );
    }

    // 7 Days
    $day7_labels = array();
    $day7_values = array();
    $prev7_views = 0;
    for ( $i = 6; $i >= 0; $i-- ) {
        $d = date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
        $day7_labels[] = date( 'j M', strtotime( $d ) );
        if ( $table_exists ) {
            $day7_values[] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE DATE(viewed_at) = %s", $d ) );
        } else {
            $day7_values[] = 0;
        }
    }
    if ( $table_exists ) {
        $prev7_start = date( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) ) . ' 00:00:00';
        $prev7_end   = date( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) ) . ' 23:59:59';
        $prev7_views = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
            $prev7_start, $prev7_end ) );
    }

    // 1 Month (28 days) — query once, fill array
    $month_labels = array();
    $month_values = array();
    $m28_s        = date( 'Y-m-d', strtotime( '-27 days', strtotime( $today ) ) ) . ' 00:00:00';
    $raw_month    = array();
    if ( $table_exists ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(viewed_at) AS day, COUNT(*) AS views
             FROM `{$table}` WHERE viewed_at >= %s
             GROUP BY day", $m28_s ) );
        foreach ( $rows as $r ) { $raw_month[ $r->day ] = (int) $r->views; }
    }
    for ( $i = 27; $i >= 0; $i-- ) {
        $d              = date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
        $dow            = (int) date( 'N', strtotime( $d ) );
        $month_labels[] = $dow === 1 ? date( 'j M', strtotime( $d ) ) : date( 'j', strtotime( $d ) );
        $month_values[] = $raw_month[ $d ] ?? 0;
    }

    // 6 Months — group by week (26 weeks)
    $m6_labels = array();
    $m6_values = array();
    $m6_s      = date( 'Y-m-d', strtotime( '-181 days', strtotime( $today ) ) ) . ' 00:00:00';
    $raw_6m    = array();
    if ( $table_exists ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(viewed_at, '%%Y-%%u') AS week_key,
                    MIN(DATE(viewed_at)) AS week_start,
                    COUNT(*) AS views
             FROM `{$table}` WHERE viewed_at >= %s
             GROUP BY week_key ORDER BY week_key ASC", $m6_s ) );
        foreach ( $rows as $r ) { $raw_6m[ $r->week_key ] = array( 'views' => (int) $r->views, 'start' => $r->week_start ); }
    }
    // Build 26 week slots
    for ( $i = 25; $i >= 0; $i-- ) {
        $week_start     = date( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days', strtotime( $today ) ) );
        $wk             = date( 'Y-W', strtotime( $week_start ) );
        $m6_labels[]    = date( 'j M', strtotime( $week_start ) );
        $m6_values[]    = isset( $raw_6m[ str_replace( '-', '-', $wk ) ] )
                            ? $raw_6m[ str_replace( '-', '-', $wk ) ]['views'] : 0;
    }
    // Fill 6m from raw (simpler — just use ordered results directly)
    if ( ! empty( $raw_6m ) ) {
        $m6_labels = array();
        $m6_values = array();
        foreach ( $raw_6m as $wk => $data ) {
            $m6_labels[] = date( 'j M', strtotime( $data['start'] ) );
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
            $d           = date( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days', strtotime( $today ) ) );
            $m6_labels[] = date( 'j M', strtotime( $d ) );
            $m6_values[] = 0;
        }
    }

    $stats_url   = admin_url( 'tools.php?page=cloudscale-page-views' );
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

<style>
#cspv_dashboard_widget .inside { padding: 0; margin: 0; }

.cspv-dw-banner {
    background: linear-gradient(135deg, #2d1b69 0%, #5b21b6 50%, #7c3aed 100%);
    padding: 14px 16px 12px;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
}
.cspv-dw-today-count { font-size: 38px; font-weight: 800; color: #1db954; line-height: 1; }
.cspv-dw-today-label {
    font-size: 10px; color: rgba(255,255,255,.7);
    text-transform: uppercase; letter-spacing: .06em;
    margin-top: 3px; display: flex; align-items: center; flex-wrap: wrap; gap: 4px;
}
.cspv-dw-week-block { text-align: right; }
.cspv-dw-week-num   { font-size: 20px; font-weight: 700; color: #0d9488; line-height: 1; }
.cspv-dw-week-label { font-size: 10px; color: rgba(255,255,255,.65); text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

/* Period buttons */
.cspv-dw-periods {
    display: flex; gap: 0; border-bottom: 1px solid #eee;
    background: #fafafa;
}
.cspv-dw-period-btn {
    flex: 1; padding: 7px 4px; font-size: 11px; font-weight: 600;
    text-align: center; cursor: pointer; border: none; background: transparent;
    color: #999; border-bottom: 2px solid transparent;
    transition: color .15s, border-color .15s; white-space: nowrap;
    font-family: inherit;
}
.cspv-dw-period-btn:hover  { color: #059669; }
.cspv-dw-period-btn.active { color: #059669; border-bottom-color: #10b981; background: #fff; }

/* Chart */
.cspv-dw-chart-wrap {
    padding: 8px 14px 0; background: #fff;
    border-bottom: 1px solid #f0f0f0;
    position: relative; height: 120px;
}
.cspv-dw-canvas { display: block; width: 100%; height: 110px; }

/* Top posts */
.cspv-dw-list-header {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: #aaa;
    padding: 7px 16px 3px; display: flex; justify-content: space-between;
}
.cspv-dw-row {
    display: flex; align-items: center;
    padding: 5px 16px; border-top: 1px solid #f5f5f5;
    font-size: 12px; gap: 8px;
}
.cspv-dw-row-title {
    flex: 1; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; color: #1a2332; text-decoration: none; font-weight: 600;
}
.cspv-dw-row-title:hover { text-decoration: underline; color: #059669; }
.cspv-dw-row-bar  { height: 3px; background: #d1fae5; border-radius: 2px; flex-shrink: 0; width: 48px; overflow: hidden; }
.cspv-dw-row-fill { height: 100%; background: linear-gradient(90deg, #059669, #34d399); border-radius: 2px; }
.cspv-dw-row-num  { font-weight: 700; color: #059669; min-width: 24px; text-align: right; flex-shrink: 0; }
.cspv-dw-empty    { padding: 10px 16px; color: #bbb; font-size: 12px; font-style: italic; }

/* Two column lists layout */
.cspv-dw-lists     { display: flex; gap: 0; border-bottom: 1px solid #f0f0f0; }
.cspv-dw-list-col  { flex: 1; min-width: 0; }
.cspv-dw-list-col + .cspv-dw-list-col { border-left: 1px solid #f0f0f0; }
.cspv-dw-list-col .cspv-dw-list-header { padding: 7px 12px 3px; }
.cspv-dw-list-col .cspv-dw-row         { padding: 4px 12px; }
.cspv-dw-list-col .cspv-dw-empty       { padding: 8px 12px; }
.cspv-dw-ref-host {
    flex: 1; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; color: #1a2332; font-weight: 600; font-size: 12px;
}
.cspv-dw-ref-link {
    flex: 1; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; font-weight: 600; font-size: 12px;
    color: #1a3a8f; text-decoration: none;
}
.cspv-dw-ref-link:hover { text-decoration: underline; color: #059669; }
.cspv-dw-ref-toggle-wrap { display: inline-flex; gap: 0; margin-left: auto; }
.cspv-dw-ref-toggle {
    background: rgba(0,0,0,.08); border: none; color: #999;
    font-size: 9px; font-weight: 500; text-transform: uppercase; letter-spacing: .03em;
    padding: 2px 7px; cursor: pointer; transition: background .15s, color .15s;
    line-height: 1.4;
}
.cspv-dw-ref-toggle:first-child { border-radius: 3px 0 0 3px; }
.cspv-dw-ref-toggle:last-child  { border-radius: 0 3px 3px 0; }
.cspv-dw-ref-toggle:hover { background: rgba(0,0,0,.12); }
.cspv-dw-ref-toggle.active { background: #059669; color: #fff; font-weight: 800; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }

.cspv-dw-footer {
    padding: 8px 16px; border-top: 1px solid #eee;
    display: flex; justify-content: space-between; align-items: center;
}
.cspv-dw-link {
    display: inline-block;
    padding: 5px 12px;
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    border-radius: 20px;
    letter-spacing: .03em;
    transition: opacity .15s;
}
.cspv-dw-link:hover { opacity: .85; color: #fff; text-decoration: none; }
.cspv-dw-shield     { font-size: 11px; }
.cspv-dw-shield.on  { color: #1db954; font-weight: 600; }
.cspv-dw-shield.off { color: #e53e3e; }
</style>

<!-- Banner -->
<div class="cspv-dw-banner">
    <div>
        <div class="cspv-dw-today-count" id="cspv-dw-main-count"><?php echo number_format( $today_views ); ?></div>
        <div class="cspv-dw-today-label">
            <span id="cspv-dw-main-label">Views today</span>
            <span id="cspv-dw-delta"><?php echo $delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
        </div>
    </div>
    <div class="cspv-dw-week-block">
        <div class="cspv-dw-week-num" id="cspv-dw-side-count"><?php echo number_format( $yest_views ); ?></div>
        <div class="cspv-dw-week-label" id="cspv-dw-side-label">Yesterday</div>
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

<!-- Top posts + Top referrers (side by side) -->
<div class="cspv-dw-lists">
    <!-- Left: Top 3 posts today -->
    <div class="cspv-dw-list-col">
        <?php if ( empty( $top_today ) ) : ?>
            <div class="cspv-dw-list-header"><span>Top pages today</span></div>
            <div class="cspv-dw-empty">No views yet.</div>
        <?php else : ?>
            <div class="cspv-dw-list-header"><span>Top pages today</span><span>Views</span></div>
            <?php
            $max = (int) $top_today[0]->views;
            foreach ( $top_today as $row ) :
                $pid   = absint( $row->post_id );
                $post  = get_post( $pid );
                $title = $post ? $post->post_title : 'Post #' . $pid;
                $url   = ( $post && 'publish' === $post->post_status ) ? get_permalink( $post ) : '';
                $pct   = $max > 0 ? round( ( (int) $row->views / $max ) * 100 ) : 0;
            ?>
            <div class="cspv-dw-row">
                <?php if ( $url ) : ?>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="cspv-dw-row-title"><?php echo esc_html( $title ); ?></a>
                <?php else : ?>
                    <span class="cspv-dw-row-title"><?php echo esc_html( $title ); ?></span>
                <?php endif; ?>
                <div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:<?php echo (int) $pct; ?>%"></div></div>
                <span class="cspv-dw-row-num"><?php echo number_format( (int) $row->views ); ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Right: Top 3 referrers today (with sites/pages toggle) -->
    <div class="cspv-dw-list-col">
        <div class="cspv-dw-list-header">
            <span>Top referrers today</span>
            <span class="cspv-dw-ref-toggle-wrap">
                <button class="cspv-dw-ref-toggle active" data-ref-view="sites">Sites</button>
                <button class="cspv-dw-ref-toggle" data-ref-view="pages">Pages</button>
            </span>
        </div>
        <!-- Sites view (default) -->
        <div id="cspv-dw-ref-sites">
            <?php if ( empty( $top_referrers ) ) : ?>
                <div class="cspv-dw-empty">No referrers yet.</div>
            <?php else : ?>
                <?php
                $ref_max = reset( $top_referrers );
                foreach ( $top_referrers as $host => $views ) :
                    $pct = $ref_max > 0 ? round( ( $views / $ref_max ) * 100 ) : 0;
                ?>
                <div class="cspv-dw-row">
                    <span class="cspv-dw-ref-host"><?php echo esc_html( $host ); ?></span>
                    <div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:<?php echo (int) $pct; ?>%"></div></div>
                    <span class="cspv-dw-row-num"><?php echo number_format( $views ); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <!-- Pages view (hidden by default) -->
        <div id="cspv-dw-ref-pages" style="display:none;">
            <?php if ( empty( $top_ref_pages ) ) : ?>
                <div class="cspv-dw-empty">No referrer pages yet.</div>
            <?php else : ?>
                <?php
                $rp_max = $top_ref_pages[0]['views'];
                foreach ( $top_ref_pages as $rp ) :
                    $pct = $rp_max > 0 ? round( ( $rp['views'] / $rp_max ) * 100 ) : 0;
                    $display = $rp['url'];
                    $parsed  = wp_parse_url( $rp['url'] );
                    if ( ! empty( $parsed['host'] ) ) {
                        $display = $parsed['host'] . ( isset( $parsed['path'] ) ? $parsed['path'] : '' );
                        $display = rtrim( $display, '/' );
                    }
                ?>
                <div class="cspv-dw-row">
                    <a href="<?php echo esc_url( $rp['url'] ); ?>" target="_blank" class="cspv-dw-ref-link" title="<?php echo esc_attr( $rp['url'] ); ?>"><?php echo esc_html( $display ); ?></a>
                    <div class="cspv-dw-row-bar"><div class="cspv-dw-row-fill" style="width:<?php echo (int) $pct; ?>%"></div></div>
                    <span class="cspv-dw-row-num"><?php echo number_format( $rp['views'] ); ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
    <span class="cspv-dw-shield <?php echo $throttle_on ? 'on' : 'off'; ?>">
        <?php echo $throttle_on
            ? '🛡 ' . ( $blocked > 0 ? number_format( $blocked ) . ' blocked' : 'Protection on' )
            : '⚠ Protection off'; ?>
    </span>
</div>

<script>
(function() {
    var canvasId  = <?php echo wp_json_encode( $widget_id ); ?>;
    var periodsId = <?php echo wp_json_encode( $widget_id . '-periods' ); ?>;

    var datasets = <?php echo wp_json_encode( $periods ); ?>;
    var chartInst = null;
    var activePeriod = 'hours';

    function makeColors(values, period) {
        return values.map(function(v, i) {
            var isLast = i === values.length - 1;
            if (period === 'days' || period === 'hours' || period === 'day') {
                return isLast ? '#059669' : 'rgba(5,150,105,0.25)';
            }
            return 'rgba(5,150,105,0.35)';
        });
    }

    function makeHoverColors(values, period) {
        return values.map(function(v, i) {
            var isLast = i === values.length - 1;
            if (period === 'days' || period === 'hours' || period === 'day') {
                return isLast ? '#10b981' : 'rgba(5,150,105,0.55)';
            }
            return '#10b981';
        });
    }

    var currentPeriod = 'hours';
    var todayViews = <?php echo (int) $today_views; ?>;
    var yestViews  = <?php echo (int) $yest_views; ?>;
    var weekViews  = <?php echo (int) $week_views; ?>;
    var prev7Views = <?php echo (int) $prev7_views; ?>;
    var prevDay1Views = <?php echo (int) $prev_day1_views; ?>;

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
        var sideCount = document.getElementById('cspv-dw-side-count');
        var sideLabel = document.getElementById('cspv-dw-side-label');
        var deltaEl   = document.getElementById('cspv-dw-delta');
        if (!mainCount) return;

        // Left side: always show the period total with its label
        mainCount.textContent = total.toLocaleString();
        mainLabel.textContent = data.summary;

        // Right side + delta: contextual comparison
        if (period === 'hours') {
            // 7 Hours: show today's total, compare vs yesterday
            sideCount.textContent = yestViews.toLocaleString();
            sideLabel.textContent = 'Yesterday';
            if (deltaEl) deltaEl.innerHTML = yestViews > 0 ? formatDelta(todayViews, yestViews) : '';
        } else if (period === 'day') {
            // 1 Day: show last 24h total, compare vs previous 24h
            sideCount.textContent = prevDay1Views.toLocaleString();
            sideLabel.textContent = 'Prior 24 hours';
            if (deltaEl) deltaEl.innerHTML = prevDay1Views > 0 ? formatDelta(total, prevDay1Views) : '';
        } else if (period === 'days') {
            // 7 Days: show 7 day total, compare vs previous 7 days
            sideCount.textContent = prev7Views.toLocaleString();
            sideLabel.textContent = 'Prior 7 days';
            if (deltaEl) deltaEl.innerHTML = prev7Views > 0 ? formatDelta(total, prev7Views) : '';
        } else {
            // Month / 6 Months: just show total, no comparison
            sideCount.textContent = '';
            sideLabel.textContent = '';
            if (deltaEl) deltaEl.innerHTML = '';
        }
    }

    function drawChart(period) {
        currentPeriod = period;
        updateBanner(period);
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
                            color: '#ec4899',
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
                            color: '#ec4899',
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

    if (window.Chart) {
        init();
    } else {
        var s    = document.createElement('script');
        s.src    = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        s.onload = init;
        document.head.appendChild(s);
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

    document.querySelectorAll('.cspv-dw-ref-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mode = btn.dataset.refView;
            applyRefView(mode);
            try { localStorage.setItem('cspv_dw_ref_view', mode); } catch(e) {}
        });
    });
})();
</script>
    <?php
}
