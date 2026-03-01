<?php
/**
 * CloudScale Page Views - Site Health Metrics  v4.0.0
 *
 * Computes two metric groups across 4 time windows (1 Day, 7 Days, 28 Days, 3 Months)
 * compared against the prior equivalent period:
 *
 *   1. Traffic Growth: total views current vs previous period, % change
 *   2. Hot Pages: how many top pages exceed 50% of total traffic per window
 *
 * All calculations use ONLY beacon logged data (wp_cspv_views table).
 * Results cached in wp_options for 1 hour.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cspv_get_site_health() {
    $cache = get_option( 'cspv_site_health_cache', array() );
    if (
        ! empty( $cache )
        && isset( $cache['computed_at'] )
        && ( time() - $cache['computed_at'] ) < 3600
    ) {
        return $cache['data'];
    }

    $data = cspv_compute_site_health();
    update_option( 'cspv_site_health_cache', array(
        'computed_at' => time(),
        'data'        => $data,
    ), false );

    return $data;
}

function cspv_compute_site_health() {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';
    $today = current_time( 'Y-m-d' );

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    $earliest  = null;
    $data_days = 0;
    if ( $table_exists ) {
        $earliest = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" );
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
            $start = date( 'Y-m-d', strtotime( "-{$days} days", $today_ts ) ) . ' 00:00:00';
            $current = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at >= %s", $start ) );

            $prev_end   = $start;
            $prev_start = date( 'Y-m-d', strtotime( "-{$required_days} days", $today_ts ) ) . ' 00:00:00';
            $previous = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at >= %s AND viewed_at < %s",
                $prev_start, $prev_end ) );

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

function cspv_count_hot_pages( $table, $today_ts, $days, $offset ) {
    global $wpdb;

    $end   = date( 'Y-m-d', strtotime( "-{$offset} days", $today_ts ) ) . ' 23:59:59';
    $start = date( 'Y-m-d', strtotime( "-" . ( $offset + $days ) . " days", $today_ts ) ) . ' 00:00:00';

    $post_views = $wpdb->get_results( $wpdb->prepare(
        "SELECT post_id, COUNT(*) AS views FROM `{$table}`
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
    $rag_bg = array(
        'green'  => 'linear-gradient(135deg,#d1fae5,#a7f3d0)',
        'amber'  => 'linear-gradient(135deg,#fef3c7,#fde68a)',
        'red'    => 'linear-gradient(135deg,#fee2e2,#fecaca)',
        'nodata' => 'linear-gradient(135deg,#f3f4f6,#e5e7eb)',
    );
    $rag_emoji = array(
        'green'  => '🟢',
        'amber'  => '🟡',
        'red'    => '🔴',
        'nodata' => '⏳',
    );

    $overall_color = $rag_colors[ $health['overall'] ];
    $overall_label = $health['overall'] === 'nodata' ? 'AWAITING DATA' : strtoupper( $health['overall'] );
    $overall_emoji = $rag_emoji[ $health['overall'] ];

    $w  = $context === 'widget';
    $gs = $w ? '6'  : '10';
    $ps = $w ? '8px 6px' : '12px 14px';
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:<?php echo $w ? '10' : '14'; ?>px;">
        <span style="font-size:<?php echo $w ? '13' : '15'; ?>px;font-weight:800;color:#1a2332;">🏥 Site Health</span>
        <span style="background:<?php echo $rag_bg[ $health['overall'] ]; ?>;color:<?php echo $overall_color; ?>;
            font-size:11px;font-weight:800;padding:3px 10px;border-radius:12px;text-transform:uppercase;
            box-shadow:0 1px 4px rgba(0,0,0,.08);">
            <?php echo $overall_emoji; ?> <?php echo $overall_label; ?>
        </span>
        <?php if ( $health['data_days'] > 0 ) : ?>
        <span style="font-size:10px;color:#aaa;"><?php echo $health['data_days']; ?> days of tracking data</span>
        <?php endif; ?>
    </div>

    <?php // ── Traffic Growth ── ?>
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;color:#555;letter-spacing:.05em;margin-bottom:6px;">
        📈 Traffic Growth per Time Window
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:<?php echo $gs; ?>px;margin-bottom:<?php echo $w ? '12' : '18'; ?>px;">
    <?php foreach ( $health['growth'] as $label => $g ) :
        $pc = $period_colors[ $label ];
        if ( $g['sufficient'] ) :
            $arrow     = $g['pct_change'] >= 0 ? '▲' : '▼';
            $val_color = $rag_colors[ $g['rag'] ];
    ?>
        <div style="background:<?php echo $pc['light']; ?>;border-radius:8px;padding:<?php echo $ps; ?>;text-align:center;
            border:2px solid <?php echo $pc['border']; ?>;box-shadow:0 2px 8px <?php echo $pc['text']; ?>15;">
            <div style="background:<?php echo $pc['grad']; ?>;color:#fff;font-size:<?php echo $w ? '9' : '10'; ?>px;font-weight:800;
                text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:4px;display:inline-block;margin-bottom:6px;">
                <?php echo esc_html( $label ); ?>
            </div>
            <div style="font-size:<?php echo $w ? '16' : '22'; ?>px;font-weight:900;color:<?php echo $val_color; ?>;
                font-variant-numeric:tabular-nums;line-height:1.1;">
                <?php echo $arrow; ?> <?php echo abs( $g['pct_change'] ); ?>%
            </div>
            <div style="font-size:<?php echo $w ? '9' : '11'; ?>px;color:<?php echo $pc['text']; ?>;margin-top:4px;font-weight:600;">
                <?php echo number_format( $g['daily_avg'] ); ?>/day avg
            </div>
        </div>
    <?php else : ?>
        <div style="background:<?php echo $pc['light']; ?>;border-radius:8px;padding:<?php echo $ps; ?>;text-align:center;
            border:2px solid <?php echo $pc['border']; ?>;box-shadow:0 2px 8px <?php echo $pc['text']; ?>15;">
            <div style="background:<?php echo $pc['grad']; ?>;color:#fff;font-size:<?php echo $w ? '9' : '10'; ?>px;font-weight:800;
                text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:4px;display:inline-block;margin-bottom:6px;">
                <?php echo esc_html( $label ); ?>
            </div>
            <div style="font-size:<?php echo $w ? '11' : '13'; ?>px;font-weight:700;color:<?php echo $pc['text']; ?>;padding:2px 0;">
                Insufficient Data
            </div>
            <div style="font-size:<?php echo $w ? '9' : '10'; ?>px;color:<?php echo $pc['text']; ?>;opacity:.6;margin-top:2px;">
                need <?php echo $g['days'] * 2; ?> days
            </div>
        </div>
    <?php endif; endforeach; ?>
    </div>

    <?php // ── Hot Pages ── ?>
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;color:#555;letter-spacing:.05em;margin-bottom:6px;">
        🔥 Hot Pages Distribution per Time Window
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:<?php echo $gs; ?>px;">
    <?php foreach ( $health['hot_pages'] as $label => $h ) :
        $pc = $period_colors[ $label ];
        if ( $h['sufficient'] ) :
            $arrow     = $h['pct_change'] >= 0 ? '▲' : '▼';
            $val_color = $rag_colors[ $h['rag'] ];
    ?>
        <div style="background:<?php echo $pc['light']; ?>;border-radius:8px;padding:<?php echo $ps; ?>;text-align:center;
            border:2px solid <?php echo $pc['border']; ?>;box-shadow:0 2px 8px <?php echo $pc['text']; ?>15;">
            <div style="background:<?php echo $pc['grad']; ?>;color:#fff;font-size:<?php echo $w ? '9' : '10'; ?>px;font-weight:800;
                text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:4px;display:inline-block;margin-bottom:6px;">
                <?php echo esc_html( $label ); ?>
            </div>
            <div style="font-size:<?php echo $w ? '16' : '22'; ?>px;font-weight:900;color:<?php echo $val_color; ?>;
                font-variant-numeric:tabular-nums;line-height:1.1;">
                <?php echo $arrow; ?> <?php echo abs( $h['pct_change'] ); ?>%
            </div>
            <div style="font-size:<?php echo $w ? '9' : '11'; ?>px;color:<?php echo $pc['text']; ?>;margin-top:4px;font-weight:600;">
                <?php echo $h['current_count']; ?> page<?php echo $h['current_count'] !== 1 ? 's' : ''; ?> = 50% traffic
            </div>
        </div>
    <?php else : ?>
        <div style="background:<?php echo $pc['light']; ?>;border-radius:8px;padding:<?php echo $ps; ?>;text-align:center;
            border:2px solid <?php echo $pc['border']; ?>;box-shadow:0 2px 8px <?php echo $pc['text']; ?>15;">
            <div style="background:<?php echo $pc['grad']; ?>;color:#fff;font-size:<?php echo $w ? '9' : '10'; ?>px;font-weight:800;
                text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:4px;display:inline-block;margin-bottom:6px;">
                <?php echo esc_html( $label ); ?>
            </div>
            <div style="font-size:<?php echo $w ? '11' : '13'; ?>px;font-weight:700;color:<?php echo $pc['text']; ?>;padding:2px 0;">
                Insufficient Data
            </div>
            <div style="font-size:<?php echo $w ? '9' : '10'; ?>px;color:<?php echo $pc['text']; ?>;opacity:.6;margin-top:2px;">
                need <?php echo $h['days'] * 2; ?> days
            </div>
        </div>
    <?php endif; endforeach; ?>
    </div>
    <?php
}
