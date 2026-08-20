<?php
/**
 * CloudScale Analytics - Site Health Metrics  v4.0.0
 *
 * Computes two metric groups across 4 time windows (1 Day, 7 Days, 28 Days, 3 Months)
 * compared against the prior equivalent period:
 *
 *   1. Traffic Growth: total views current vs previous period, % change
 *   2. Hot Pages: how many top pages exceed 50% of total traffic per window
 *
 * All calculations use ONLY beacon logged data (wp_cspv_views table).
 * Results cached in wp_options for 1 hour.
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.PHP.DevelopmentFunctions.error_log_error_log -- analytics plugin: all interpolated vars are internal table/column names; direct queries on custom time-series tables are required

/**
 * Return site health metrics, serving a 1-hour cached result when available.
 *
 * @since 1.0.0
 * @return array Metrics array with keys growth, hot_pages, overall, data_days.
 */
function cspv_get_site_health() {
    $cache = get_option( 'cspv_site_health_cache', array() );
    if (
        ! empty( $cache )
        && isset( $cache['computed_at'] )
        && ( time() - $cache['computed_at'] ) < 3600
        && isset( $cache['version'] ) && $cache['version'] === CSPV_VERSION
    ) {
        return $cache['data'];
    }

    $data = cspv_compute_site_health();
    update_option( 'cspv_site_health_cache', array(
        'computed_at' => time(),
        'version'     => CSPV_VERSION,
        'data'        => $data,
    ), false );

    return $data;
}

/**
 * Compute raw site health metrics from the log table.
 *
 * Calculates traffic growth and hot-pages concentration across four
 * time windows (1 Day, 7 Days, 28 Days, 90 Days) compared to the
 * prior equivalent period.
 *
 * @since 1.0.0
 * @return array Metrics array with keys growth, hot_pages, overall, data_days.
 */
function cspv_compute_site_health() {
    global $wpdb;
    $table = esc_sql( cspv_views_table() );
    $today = current_time( 'Y-m-d' );

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query on analytics custom table

    $earliest  = null;
    $data_days = 0;
    if ( $table_exists ) {
        $earliest = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted internal table name
    }
    $today_ts = strtotime( $today );
    if ( $earliest ) {
        $data_days = floor( ( $today_ts - strtotime( $earliest ) ) / 86400 );
    }

    $periods = array(
        '1 Day'    => 1,
        '7 Days'   => 7,
        '28 Days'  => 28,
        '90 Days'  => 90,
    );

    $growth    = array();
    $hot_pages = array();

    foreach ( $periods as $label => $days ) {
        $required_days   = $days * 2;
        $has_enough_data = ( $table_exists && $data_days >= $required_days );

        // ── Traffic Growth ──
        $current  = 0;
        $previous = 0;
        $has_data = false;

        if ( $has_enough_data ) {
            if ( $days === 1 ) {
                // 1 Day: shared rolling 24h function
                $r24      = cspv_rolling_24h_views();
                $current  = $r24['current'];
                $previous = $r24['prior'];
            } elseif ( $days === 28 ) {
                // 28 Days: shared rolling 28d function (also used by dashboard widget)
                $r28      = cspv_rolling_28d_views();
                $current  = $r28['current'];
                $previous = $r28['prior'];
            } else {
                $start = wp_date( 'Y-m-d', strtotime( "-{$days} days", $today_ts ) ) . ' 00:00:00';
                $current = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted internal table name
                    "SELECT COALESCE(SUM(view_count),0) FROM `{$table}` WHERE viewed_at >= %s", $start ) );

                $prev_end   = $start;
                $prev_start = wp_date( 'Y-m-d', strtotime( "-{$required_days} days", $today_ts ) ) . ' 00:00:00';
                $previous = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted internal table name
                    "SELECT COALESCE(SUM(view_count),0) FROM `{$table}` WHERE viewed_at >= %s AND viewed_at < %s",
                    $prev_start, $prev_end ) );
            }

            $has_data = ( $previous > 0 );
        }

        if ( $has_data ) {
            $pct = round( ( ( $current - $previous ) / $previous ) * 100, 1 );
            if ( $pct < -5 )      { $rag = 'red'; }
            elseif ( $pct > 5 )   { $rag = 'green'; }
            else                  { $rag = 'amber'; }
        } else {
            $pct = null;
            $rag = null;
        }

        $growth[ $label ] = array(
            'days'        => $days,
            'current'     => $current,
            'previous'    => $previous,
            'daily_avg'   => $days > 0 ? round( $current / $days ) : 0,
            'pct_change'  => $pct,
            'rag'         => $rag,
            'sufficient'  => $has_data,
        );

        // ── Hot Pages ──
        $hp_current  = null;
        $hp_previous = null;
        $hp_has_data = false;

        if ( $has_enough_data ) {
            $hp_current  = cspv_count_hot_pages( $table, $today_ts, $days, 0 );
            $hp_previous = cspv_count_hot_pages( $table, $today_ts, $days, $days );
            $hp_has_data = ( $hp_previous !== null && $hp_previous['total_views'] > 0
                         && $hp_current !== null && $hp_current['total_views'] > 0
                         && $hp_previous['hot_count'] > 0 );
        }

        if ( $hp_has_data ) {
            $hp_pct = round( ( ( $hp_current['hot_count'] - $hp_previous['hot_count'] ) / $hp_previous['hot_count'] ) * 100, 1 );
        } else {
            $hp_pct = null;
        }

        if ( $hp_has_data && $hp_pct !== null ) {
            if ( $hp_pct > 5 )      { $hp_rag = 'green'; }
            elseif ( $hp_pct < -5 ) { $hp_rag = 'red'; }
            else                    { $hp_rag = 'amber'; }
        } else {
            $hp_rag = null;
        }

        $hot_pages[ $label ] = array(
            'days'             => $days,
            'current_count'    => $hp_current ? $hp_current['hot_count'] : 0,
            'current_pct'      => $hp_current ? $hp_current['hot_pct'] : 0,
            'current_total'    => $hp_current ? $hp_current['total_with_views'] : 0,
            'previous_count'   => $hp_previous ? $hp_previous['hot_count'] : 0,
            'pct_change'       => $hp_pct,
            'rag'              => $hp_rag,
            'sufficient'       => $hp_has_data,
        );
    }

    // Overall RAG
    $all_rags = array();
    foreach ( $growth as $g ) {
        if ( $g['sufficient'] ) { $all_rags[] = $g['rag']; }
    }
    foreach ( $hot_pages as $h ) {
        if ( $h['sufficient'] ) { $all_rags[] = $h['rag']; }
    }

    if ( empty( $all_rags ) ) {
        $overall = 'nodata';
    } else {
        $all_green = ( count( array_filter( $all_rags, function($r) { return $r === 'green'; } ) ) === count( $all_rags ) );
        $all_red   = ( count( array_filter( $all_rags, function($r) { return $r === 'red'; } ) ) === count( $all_rags ) );
        if ( $all_green ) { $overall = 'green'; }
        elseif ( $all_red ) { $overall = 'red'; }
        else { $overall = 'amber'; }
    }

    return array(
        'growth'    => $growth,
        'hot_pages' => $hot_pages,
        'overall'   => $overall,
        'data_days' => $data_days,
    );
}

/**
 * Count how many top pages account for 50% of traffic in a given window.
 *
 * @since 1.0.0
 * @param string $table    Log table name.
 * @param int    $today_ts Unix timestamp for "today".
 * @param int    $days     Length of the window in days.
 * @param int    $offset   Days to offset the window end from today (0 = current window).
 * @return array|null Array with hot_count, hot_pct, total_views, total_with_views, or null if no data.
 */
function cspv_count_hot_pages( $table, $today_ts, $days, $offset ) {
    global $wpdb;

    $end   = wp_date( 'Y-m-d', strtotime( "-{$offset} days", $today_ts ) ) . ' 23:59:59';
    $start = wp_date( 'Y-m-d', strtotime( '-' . ( $offset + $days ) . ' days', $today_ts ) ) . ' 00:00:00';

    $post_views = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- trusted internal table name
        "SELECT post_id, COALESCE(SUM(view_count),0) AS views FROM `{$table}`
         WHERE viewed_at >= %s AND viewed_at <= %s
         GROUP BY post_id ORDER BY views DESC", $start, $end ) );

    if ( empty( $post_views ) ) { return null; }

    $total_with_views = count( $post_views );
    $total_views      = 0;
    foreach ( $post_views as $pv ) { $total_views += (int) $pv->views; }
    if ( $total_views === 0 ) { return null; }

    $half      = $total_views * 0.5;
    $cumul     = 0;
    $hot_count = 0;
    foreach ( $post_views as $pv ) {
        $cumul += (int) $pv->views;
        $hot_count++;
        if ( $cumul >= $half ) { break; }
    }

    return array(
        'hot_count'        => $hot_count,
        'hot_pct'          => round( ( $cumul / $total_views ) * 100, 1 ),
        'total_views'      => $total_views,
        'total_with_views' => $total_with_views,
    );
}

/**
 * Render the overall RAG badge, plus the tracking-data age when known.
 *
 * Split out of cspv_render_site_health_html() so a host that already shows a
 * "Site Health" heading (the stats page panel header) can hang the badge there
 * instead of spending another row on it.
 *
 * @since 4.1.0
 * @param array|null $health   Pre-computed metrics, to avoid a second lookup.
 * @param bool       $compact  Shorten the data-age wording for tight headers.
 * @return void
 */
function cspv_render_site_health_badge( $health = null, $compact = false ) {
    if ( ! is_array( $health ) ) {
        $health = cspv_get_site_health();
    }

    $rag_colors = array( 'green' => '#059669', 'amber' => '#d97706', 'red' => '#e53e3e', 'nodata' => '#6b7280' );
    $rag_bg     = array(
        'green'  => 'linear-gradient(135deg,#d1fae5,#a7f3d0)',
        'amber'  => 'linear-gradient(135deg,#fef3c7,#fde68a)',
        'red'    => 'linear-gradient(135deg,#fee2e2,#fecaca)',
        'nodata' => 'linear-gradient(135deg,#f3f4f6,#e5e7eb)',
    );
    $rag_emoji  = array( 'green' => '🟢', 'amber' => '🟡', 'red' => '🔴', 'nodata' => '⏳' );

    $key   = $health['overall'];
    $label = 'nodata' === $key ? 'AWAITING DATA' : strtoupper( $key );

    $badge_style = 'background:' . $rag_bg[ $key ] . ';color:' . $rag_colors[ $key ]
        . ';font-size:12px;font-weight:800;padding:3px 10px;border-radius:12px;text-transform:uppercase;'
        . 'white-space:nowrap;letter-spacing:0;box-shadow:0 1px 4px rgba(0,0,0,.08);';
    ?>
    <span style="display:inline-flex;align-items:center;gap:8px;">
        <span style="<?php echo esc_attr( $badge_style ); ?>">
            <?php echo esc_html( $rag_emoji[ $key ] ); ?> <?php echo esc_html( $label ); ?>
        </span>
        <?php if ( $health['data_days'] > 0 ) : ?>
        <span style="font-size:12px;font-weight:400;text-transform:none;letter-spacing:0;white-space:nowrap;opacity:.9;">
            <?php echo (int) $health['data_days']; ?> days<?php echo $compact ? '' : ' of tracking data'; ?>
        </span>
        <?php endif; ?>
    </span>
    <?php
}

/**
 * Render the site health metrics HTML cards.
 *
 * @since 1.0.0
 * @param string $context Rendering context: 'widget' (dashboard) or 'page' (stats page).
 * @return void
 */
function cspv_render_site_health_html( $context = 'widget' ) {
    $health = cspv_get_site_health();

    // Vibrant colours per period: cyan, purple, green, yellow
    $period_colors = array(
        '1 Day'    => array( 'grad' => 'linear-gradient(135deg,#0891b2,#22d3ee)', 'text' => '#0e7490', 'light' => '#ecfeff', 'border' => '#a5f3fc', 'insuf' => '#06b6d4' ),
        '7 Days'   => array( 'grad' => 'linear-gradient(135deg,#7c3aed,#a78bfa)', 'text' => '#6d28d9', 'light' => '#f5f3ff', 'border' => '#c4b5fd', 'insuf' => '#8b5cf6' ),
        '28 Days'  => array( 'grad' => 'linear-gradient(135deg,#059669,#34d399)', 'text' => '#047857', 'light' => '#ecfdf5', 'border' => '#6ee7b7', 'insuf' => '#10b981' ),
        '90 Days'  => array( 'grad' => 'linear-gradient(135deg,#d97706,#fbbf24)', 'text' => '#b45309', 'light' => '#fffbeb', 'border' => '#fcd34d', 'insuf' => '#f59e0b' ),
    );

    $rag_colors = array(
        'green'  => '#059669',
        'amber'  => '#d97706',
        'red'    => '#e53e3e',
        'nodata' => '#6b7280',
    );
    $w  = $context === 'widget';
    $gs = $w ? '6'  : '8';
    $ps = $w ? '8px 6px' : '9px 10px';

    /*
     * Type scale. The widget stays compact because it shares a narrow dashboard
     * column; the stats page gets legible sizes. The two detail lines are merged
     * onto one row in both contexts, so the larger type costs no extra height.
     */
    $f_sec   = $w ? 10 : 12; // Section heading.
    $f_lbl   = $w ? 9 : 12;  // Period label inside a card.
    $f_hero  = $w ? 16 : 20; // Headline percentage.
    $f_insuf = $w ? 11 : 14; // "Insufficient Data".
    $f_det   = $w ? 9 : 12;  // Detail line under the headline.

    $card_style  = 'background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:' . $ps . ';text-align:center;border-top:3px solid ';
    $sec_style   = 'font-size:' . $f_sec . 'px;font-weight:700;text-transform:uppercase;color:#6b7280;letter-spacing:.05em;';
    $lbl_style   = 'font-size:' . $f_lbl . 'px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;';
    $hero_style  = 'font-size:' . $f_hero . 'px;font-weight:900;font-variant-numeric:tabular-nums;line-height:1.15;color:';
    $det_style   = 'font-size:' . $f_det . 'px;color:#4b5563;margin-top:3px;font-weight:600;font-variant-numeric:tabular-nums;';
    $insuf_style = 'font-size:' . $f_insuf . 'px;font-weight:700;color:#d97706;line-height:1.2;';
    $muted_style = 'color:#9ca3af;font-weight:500;';
    $grid_style  = 'display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:' . (int) $gs . 'px;';
    ?>
    <?php // The stats page already carries a "Site Health" panel header, so the title row is widget-only. ?>
    <?php if ( $w ) : ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;color:#6b7280;">
        <span style="font-size:14px;font-weight:800;color:#1a2332;">🏥 Site Health</span>
        <?php cspv_render_site_health_badge( $health ); ?>
    </div>
    <?php endif; ?>

    <?php // Growth and hot pages sit side by side on the stats page and stack below ~900px. ?>
    <div style="display:flex;flex-wrap:wrap;gap:<?php echo (int) ( $w ? 0 : 20 ); ?>px;margin-bottom:<?php echo (int) ( $w ? 10 : 12 ); ?>px;">

        <div style="flex:1 1 460px;min-width:0;">
            <div style="<?php echo esc_attr( $sec_style ); ?>margin-bottom:5px;">Traffic Growth per Time Window</div>
            <div style="<?php echo esc_attr( $grid_style ); ?>">
            <?php foreach ( $health['growth'] as $label => $g ) : ?>
                <div style="<?php echo esc_attr( $card_style . $period_colors[ $label ]['insuf'] ); ?>">
                    <div style="<?php echo esc_attr( $lbl_style ); ?>"><?php echo esc_html( $label ); ?></div>
                    <?php if ( $g['sufficient'] ) : ?>
                    <div style="<?php echo esc_attr( $hero_style . $rag_colors[ $g['rag'] ] ); ?>">
                        <?php echo esc_html( $g['pct_change'] >= 0 ? '▲' : '▼' ); ?> <?php echo esc_html( abs( $g['pct_change'] ) ); ?>%
                    </div>
                    <div style="<?php echo esc_attr( $det_style ); ?>">
                        <?php echo esc_html( number_format( $g['current'] ) ); ?>
                        <span style="<?php echo esc_attr( $muted_style ); ?>">vs <?php echo esc_html( number_format( $g['previous'] ) ); ?></span>
                    </div>
                    <?php else : ?>
                    <div style="<?php echo esc_attr( $insuf_style ); ?>">Insufficient Data</div>
                    <div style="<?php echo esc_attr( $det_style . $muted_style ); ?>">need <?php echo (int) ( $g['days'] * 2 ); ?> days</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <?php if ( ! $w ) : // Hot Pages - full stats page only, not the widget. ?>
        <div style="flex:1 1 460px;min-width:0;">
            <div style="<?php echo esc_attr( $sec_style ); ?>margin-bottom:5px;">Hot Pages per Time Window</div>
            <div style="<?php echo esc_attr( $grid_style ); ?>">
            <?php foreach ( $health['hot_pages'] as $label => $h ) : ?>
                <div style="<?php echo esc_attr( $card_style . $period_colors[ $label ]['insuf'] ); ?>">
                    <div style="<?php echo esc_attr( $lbl_style ); ?>"><?php echo esc_html( $label ); ?></div>
                    <?php if ( $h['sufficient'] ) : ?>
                    <div style="<?php echo esc_attr( $hero_style . $rag_colors[ $h['rag'] ] ); ?>">
                        <?php echo esc_html( $h['pct_change'] >= 0 ? '▲' : '▼' ); ?> <?php echo esc_html( abs( $h['pct_change'] ) ); ?>%
                    </div>
                    <div style="<?php echo esc_attr( $det_style ); ?>">
                        <?php echo (int) $h['current_count']; ?> hot pages
                        <span style="<?php echo esc_attr( $muted_style ); ?>">vs <?php echo (int) $h['previous_count']; ?></span>
                    </div>
                    <?php else : ?>
                    <div style="<?php echo esc_attr( $insuf_style ); ?>">Insufficient Data</div>
                    <div style="<?php echo esc_attr( $det_style . $muted_style ); ?>">need <?php echo (int) ( $h['days'] * 2 ); ?> days</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <?php
}
