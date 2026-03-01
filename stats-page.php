<?php
/**
 * CloudScale Page Views - Statistics Dashboard  v2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu',              'cspv_add_tools_page' );
add_action( 'admin_enqueue_scripts',   'cspv_enqueue_admin_assets' );
add_action( 'wp_ajax_cspv_chart_data', 'cspv_ajax_chart_data' );

function cspv_add_tools_page() {
    add_management_page(
        'CloudScale Page Views',
        'CloudScale Page Views',
        'manage_options',
        'cloudscale-page-views',
        'cspv_render_stats_page'
    );
}

function cspv_enqueue_admin_assets( $hook ) {
    if ( 'tools_page_cloudscale-page-views' !== $hook ) { return; }
    wp_enqueue_script( 'chartjs',
        'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
        array(), '2.0.0', true );
}

// ---------------------------------------------------------------------------
// AJAX — chart data
// ---------------------------------------------------------------------------
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
        wp_send_json_error( array( 'message' => 'date_from must be on or before date_to.' ), 400 );
        return;
    }

    $diff_days = (int) date_diff( $from, $to )->days;
    if ( $diff_days > 730 ) {
        wp_send_json_error( array( 'message' => 'Date range cannot exceed 2 years.' ), 400 );
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $table_exists ) {
        wp_send_json_success( array(
            'chart' => array(), 'label_fmt' => 'day', 'total_views' => 0,
            'unique_posts' => 0, 'prev_total' => 0, 'diff_days' => $diff_days,
            'top_posts' => array(), 'referrers' => array(),
            'notice' => 'Database table not found. Deactivate and reactivate the plugin.',
        ) );
        return;
    }

    $from_str = $from->format( 'Y-m-d' ) . ' 00:00:00';
    $to_str   = $to->format( 'Y-m-d' )   . ' 23:59:59';

    // Grouping: single day = hourly, <=90d = daily, >90d = weekly
    if ( $diff_days === 0 ) {
        // ── Hourly: build all 24 slots, fill from DB ──────────────────
        $label_fmt = 'hour';
        $raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(viewed_at,'%%H') AS hr, COUNT(*) AS views
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
    } elseif ( $diff_days <= 90 ) {
        // ── Daily: build every date in range, fill from DB ────────────
        $label_fmt = 'day';
        $raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(viewed_at) AS ymd, COUNT(*) AS views
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
                     COUNT(*) AS views
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

    $total_views  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s", $from_str, $to_str ) );
    $unique_posts = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT post_id) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s", $from_str, $to_str ) );

    // Transition blending: if log table has fewer days than the selected
    // period, add lifetime meta totals so the cards are not misleadingly low.
    $earliest_log = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" );
    $log_age_days = $earliest_log ? (int) floor( ( time() - strtotime( $earliest_log ) ) / 86400 ) : 0;
    $in_transition = ( $log_age_days < max( 1, $diff_days ) );

    if ( $in_transition ) {
        $lt_total = (int) $wpdb->get_var(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = '_cspv_view_count' AND meta_value > 0"
        );
        $lt_posts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_cspv_view_count' AND meta_value > 0"
        );
        $total_views  = max( $total_views, $lt_total );
        $unique_posts = max( $unique_posts, $lt_posts );
    }

    $top_posts     = array();
    $top_posts_raw = $wpdb->get_results( $wpdb->prepare(
        "SELECT post_id, COUNT(*) AS view_count FROM `{$table}`
         WHERE viewed_at BETWEEN %s AND %s
         GROUP BY post_id ORDER BY view_count DESC LIMIT 10", $from_str, $to_str ) );
    if ( is_array( $top_posts_raw ) ) {
        foreach ( $top_posts_raw as $row ) {
            $pid  = absint( $row->post_id );
            $post = $pid ? get_post( $pid ) : null;
            $top_posts[] = array(
                'title' => $post ? esc_html( $post->post_title ) : 'Post #' . $pid,
                'url'   => ( $post && 'publish' === $post->post_status ) ? esc_url( get_permalink( $post ) ) : '',
                'views' => (int) $row->view_count,
            );
        }
    }

    $period_days = max( 1, $diff_days );
    $prev_from   = clone $from; $prev_from->modify( '-' . $period_days . ' days' );
    $prev_to     = clone $to;   $prev_to->modify(   '-' . $period_days . ' days' );
    $prev_total  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s",
        $prev_from->format( 'Y-m-d' ) . ' 00:00:00',
        $prev_to->format( 'Y-m-d' )   . ' 23:59:59' ) );

    $referrers      = array();
    $referrer_pages = array();
    $has_referrer   = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'referrer' ) );
    if ( $has_referrer ) {
        $ref_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT referrer, COUNT(*) AS view_count FROM `{$table}`
             WHERE viewed_at BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> ''
             GROUP BY referrer ORDER BY view_count DESC LIMIT 50", $from_str, $to_str ) );
        if ( is_array( $ref_rows ) ) {
            // Aggregate by domain (host) to avoid duplicates from different URL paths
            $host_totals = array();
            $own_host    = wp_parse_url( home_url(), PHP_URL_HOST );
            foreach ( $ref_rows as $r ) {
                $host = wp_parse_url( $r->referrer, PHP_URL_HOST );
                if ( ! $host ) { $host = $r->referrer; }
                // Skip self referrals from own domain
                if ( $own_host && strcasecmp( $host, $own_host ) === 0 ) {
                    continue;
                }
                if ( ! isset( $host_totals[ $host ] ) ) {
                    $host_totals[ $host ] = 0;
                }
                $host_totals[ $host ] += (int) $r->view_count;

                // Also build full page list (skip self referrals)
                $referrer_pages[] = array(
                    'url'   => esc_url( $r->referrer ),
                    'host'  => esc_html( $host ),
                    'views' => (int) $r->view_count,
                );
            }
            arsort( $host_totals );
            $i = 0;
            foreach ( $host_totals as $host => $views ) {
                if ( $i >= 10 ) break;
                $referrers[] = array(
                    'host'  => esc_html( $host ),
                    'views' => $views,
                );
                $i++;
            }
            // Sort pages by views descending (already mostly sorted from SQL, but
            // re-sort after filtering out self referrals)
            usort( $referrer_pages, function( $a, $b ) { return $b['views'] - $a['views']; } );
            $referrer_pages = array_slice( $referrer_pages, 0, 20 );
        }
    }

    // ── Lifetime totals from post meta (includes Jetpack imports) ────
    $lifetime_total = (int) $wpdb->get_var(
        "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = '_cspv_view_count' AND meta_value > 0"
    );
    $lifetime_posts = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_cspv_view_count' AND meta_value > 0"
    );

    // All time top posts from meta (for the "All Time" display)
    $lifetime_top     = array();
    $lifetime_top_raw = $wpdb->get_results(
        "SELECT pm.post_id, CAST(pm.meta_value AS UNSIGNED) AS total_views
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' AND p.post_type = 'post'
         WHERE pm.meta_key = '_cspv_view_count' AND pm.meta_value > 0
         ORDER BY total_views DESC LIMIT 10"
    );
    if ( is_array( $lifetime_top_raw ) ) {
        foreach ( $lifetime_top_raw as $row ) {
            $pid  = absint( $row->post_id );
            $post = get_post( $pid );
            $lifetime_top[] = array(
                'title' => $post ? esc_html( $post->post_title ) : 'Post #' . $pid,
                'url'   => $post ? esc_url( get_permalink( $post ) ) : '',
                'views' => (int) $row->total_views,
            );
        }
    }

    wp_send_json_success( array(
        'chart'          => array_values( $chart_rows ),
        'label_fmt'      => $label_fmt,
        'total_views'    => $total_views,
        'unique_posts'   => $unique_posts,
        'prev_total'     => $prev_total,
        'diff_days'      => $diff_days,
        'top_posts'      => $top_posts,
        'referrers'       => $referrers,
        'referrer_pages'  => $referrer_pages,
        'lifetime_total' => $lifetime_total,
        'lifetime_posts' => $lifetime_posts,
        'lifetime_top'   => $lifetime_top,
    ) );
}

// ---------------------------------------------------------------------------
// Page render
// ---------------------------------------------------------------------------
function cspv_render_stats_page() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    // Handle display settings save
    if ( isset( $_POST['cspv_display_nonce'] ) && wp_verify_nonce( $_POST['cspv_display_nonce'], 'cspv_display_save' ) ) {
        $valid_positions = array( 'before_content', 'after_content', 'both', 'off' );
        $pos = isset( $_POST['cspv_auto_display'] ) ? sanitize_text_field( $_POST['cspv_auto_display'] ) : 'before_content';
        update_option( 'cspv_auto_display', in_array( $pos, $valid_positions, true ) ? $pos : 'before_content' );

        $valid_styles = array( 'badge', 'pill', 'minimal' );
        $sty = isset( $_POST['cspv_display_style'] ) ? sanitize_text_field( $_POST['cspv_display_style'] ) : 'badge';
        update_option( 'cspv_display_style', in_array( $sty, $valid_styles, true ) ? $sty : 'badge' );

        update_option( 'cspv_display_icon', isset( $_POST['cspv_display_icon'] ) ? sanitize_text_field( $_POST['cspv_display_icon'] ) : '👁' );
        update_option( 'cspv_display_suffix', isset( $_POST['cspv_display_suffix'] ) ? sanitize_text_field( $_POST['cspv_display_suffix'] ) : ' views' );

        $pt = isset( $_POST['cspv_display_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_display_post_types'] ) : array( 'post' );
        update_option( 'cspv_display_post_types', $pt );

        $tpt = isset( $_POST['cspv_track_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['cspv_track_post_types'] ) : array( 'post' );
        update_option( 'cspv_track_post_types', $tpt );

        $valid_colors = array( 'blue', 'pink', 'red', 'purple', 'grey' );
        $col = isset( $_POST['cspv_display_color'] ) ? sanitize_text_field( $_POST['cspv_display_color'] ) : 'blue';
        update_option( 'cspv_display_color', in_array( $col, $valid_colors, true ) ? $col : 'blue' );

        echo '<div class="notice notice-success is-dismissible"><p>Display settings saved.</p></div>';
    }

    $ajax_url        = admin_url( 'admin-ajax.php' );
    $ajax_nonce      = wp_create_nonce( 'cspv_chart_data' );
    $throttle_nonce  = wp_create_nonce( 'cspv_throttle' );
    $today           = current_time( 'Y-m-d' );
    $migrate_nonce    = wp_create_nonce( 'cspv_migrate' );
    $migration_log    = (array) get_option( 'cspv_migration_log', array() );
    $migration_locked = cspv_migration_is_locked();
    $migration_lock   = get_option( 'cspv_migration_complete', false );
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

    // Display settings
    $dsp_position    = get_option( 'cspv_auto_display', 'before_content' );
    $dsp_post_types  = get_option( 'cspv_display_post_types', array( 'post' ) );
    $dsp_icon        = get_option( 'cspv_display_icon', '👁' );
    $dsp_suffix      = get_option( 'cspv_display_suffix', ' views' );
    $dsp_style       = get_option( 'cspv_display_style', 'badge' );
    $dsp_track_types = get_option( 'cspv_track_post_types', array( 'post' ) );
    $dsp_all_types   = get_post_types( array( 'public' => true ), 'objects' );
    $dsp_color       = get_option( 'cspv_display_color', 'blue' );
    ?>
<!DOCTYPE html>
<div id="cspv-app">

    <!-- ═══════════════════════ HEADER BANNER ═══════════════════════ -->
    <div id="cspv-banner">
        <div id="cspv-banner-left">
            <div id="cspv-banner-title">☁ CloudScale Page Views</div>
            <div id="cspv-banner-sub">Cloudflare-accurate view tracking · v<?php echo esc_html( CSPV_VERSION ); ?></div>
        </div>
        <div id="cspv-banner-right">
            <span class="cspv-badge cspv-badge-green">● Site Online</span>
            <span class="cspv-badge cspv-badge-orange"><?php echo esc_html( parse_url( home_url(), PHP_URL_HOST ) ); ?></span>
        </div>
    </div>

    <!-- ═══════════════════════ TAB BAR ═════════════════════════════ -->
    <div id="cspv-tab-bar">
        <button class="cspv-tab active" data-tab="stats">📊 Statistics</button>
        <button class="cspv-tab" data-tab="display">👁 Display</button>
        <button class="cspv-tab" data-tab="throttle">🛡 IP Throttle</button>
        <button class="cspv-tab" data-tab="migrate">🔀 Migrate Jetpack</button>
        <span class="cspv-tab-spacer"></span>
        <button class="cspv-tab-help" id="cspv-help-btn" title="Help">❓ Help</button>
    </div>

    <!-- ═══════════════════════ STATS TAB ═══════════════════════════ -->
    <div id="cspv-tab-stats" class="cspv-tab-content active">

        <!-- Date range bar -->
        <div id="cspv-date-bar">
            <div id="cspv-quick-btns">
                <button class="cspv-quick" data-range="today">Today</button>
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
                <div class="cspv-card-icon">👁</div>
                <div class="cspv-card-value" id="stat-views">—</div>
                <div class="cspv-card-label">Views</div>
                <div class="cspv-card-delta" id="stat-delta"></div>
            </div>
            <div class="cspv-card" id="cspv-card-posts">
                <div class="cspv-card-icon">📄</div>
                <div class="cspv-card-value" id="stat-posts">—</div>
                <div class="cspv-card-label">Posts Viewed</div>
            </div>
            <div class="cspv-card" id="cspv-card-avg">
                <div class="cspv-card-icon">📈</div>
                <div class="cspv-card-value" id="stat-avg">—</div>
                <div class="cspv-card-label">Avg / Day</div>
            </div>
        </div>

        <!-- Lifetime stats bar (includes imported Jetpack data) -->
        <div id="cspv-lifetime-bar">
            <div class="cspv-lifetime-stat">
                <span class="cspv-lifetime-label">🏆 All Time Views</span>
                <span class="cspv-lifetime-value" id="stat-lifetime-views">—</span>
            </div>
            <div class="cspv-lifetime-stat">
                <span class="cspv-lifetime-label">📚 Posts With Views</span>
                <span class="cspv-lifetime-value" id="stat-lifetime-posts">—</span>
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

        <!-- All time panel (includes Jetpack imported views) -->
        <div id="cspv-panels-alltime">
            <div class="cspv-panel" style="flex:1;">
                <div class="cspv-section-header" style="color:#fff;background:linear-gradient(135deg,#1a3a8f,#1e6fd9);border-radius:6px 6px 0 0;">
                    <span>🏆 All Time Top Posts <a class="cspv-info-btn" data-info="all-time" title="Info">i</a></span><span>Total Views</span>
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
                <code>/wp-json/cloudscale-page-views/</code> → Cache Status: <strong>Bypass</strong>
            </div>
            <div id="cspv-cf-test-log"></div>
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
                    <label class="cspv-dsp-style-card<?php echo $dsp_style === 'badge' ? ' active' : ''; ?>">
                        <input type="radio" name="cspv_display_style" value="badge" <?php checked( $dsp_style, 'badge' ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#1a3a8f,#1e6fd9);color:#fff;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:700;">👁 1,234 <span style="opacity:.8;font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name">Badge</span>
                    </label>
                    <label class="cspv-dsp-style-card<?php echo $dsp_style === 'pill' ? ' active' : ''; ?>">
                        <input type="radio" name="cspv_display_style" value="pill" <?php checked( $dsp_style, 'pill' ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:#f0f6ff;border:1px solid #d0dfff;color:#1a3a8f;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:600;">👁 1,234 <span style="color:#5a7abf;font-size:11px;">views</span></span>
                        <span class="cspv-dsp-style-name">Pill</span>
                    </label>
                    <label class="cspv-dsp-style-card<?php echo $dsp_style === 'minimal' ? ' active' : ''; ?>">
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
                    <label class="cspv-dsp-style-card<?php echo $dsp_color === $ckey ? ' active' : ''; ?>">
                        <input type="radio" name="cspv_display_color" value="<?php echo esc_attr( $ckey ); ?>" <?php checked( $dsp_color, $ckey ); ?>>
                        <span class="cspv-dsp-preview" style="display:inline-flex;align-items:center;gap:5px;background:<?php echo $cval['grad']; ?>;color:<?php echo $cval['text']; ?>;padding:4px 10px;border-radius:14px;font-size:12px;font-weight:700;">👁 1,234 <span style="opacity:.8;font-size:11px;">views</span></span>
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
                <p style="font-size:12px;color:#666;margin:0 0 10px;">Only record views on these post types. Unselected types will not fire the beacon.</p>
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

            <p><button type="submit" style="background:linear-gradient(135deg,#2d1b69,#7c3aed);color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;">💾 Save Display Settings</button></p>
        </form>

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
                        <span id="cspv-toggle-label" class="cspv-toggle-text"><?php echo $throttle_enabled ? 'Enabled' : 'Disabled'; ?></span>
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
                <span class="cspv-ftb-status-pill <?php echo $dedup_enabled ? 'cspv-ftb-on' : 'cspv-ftb-off'; ?>" id="cspv-dedup-status">
                    <?php echo $dedup_enabled ? 'DEDUP ON' : 'DEDUP OFF'; ?>
                </span>
            </div>
            <div id="cspv-dedup-body" style="background:#fff;padding:16px 24px 20px;border:1.5px solid #dce3ef;border-top:none;border-radius:0 0 8px 8px;">
                <p class="cspv-throttle-desc">Prevents the same visitor from inflating view counts by visiting the same post multiple times. Works at two levels: client side (localStorage, per browser) and server side (IP + post ID lookup in the database). Catches duplicate views from in app browsers like WhatsApp opening a link and then the user opening it again in Chrome.</p>

                <div class="cspv-throttle-row">
                    <span class="cspv-throttle-label">Enable deduplication</span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-dedup-enabled" <?php checked( $dedup_enabled ); ?>>
                        <span class="cspv-toggle"></span>
                        <span id="cspv-dedup-toggle-label" class="cspv-toggle-text"><?php echo $dedup_enabled ? 'Enabled' : 'Disabled'; ?></span>
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
                <span>Blocked IPs <a class="cspv-info-btn" data-info="blocklist" title="Info">i</a> <span class="cspv-badge-count"><?php echo count( $blocklist ); ?></span></span>
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
        <div style="margin-top:24px;background:#fff;border:2px solid <?php echo $tracking_paused ? '#fecaca' : '#dce3ef'; ?>;border-radius:8px;overflow:hidden;" id="cspv-pause-wrapper">
            <div class="cspv-section-header" style="background:linear-gradient(135deg,<?php echo $tracking_paused ? '#991b1b,#dc2626' : '#374151,#6b7280'; ?>);" id="cspv-pause-header">
                <span>⏸ Page Tracking <a class="cspv-info-btn" data-info="tracking-pause" title="Info">i</a></span>
                <span class="cspv-ftb-status-pill <?php echo $tracking_paused ? 'cspv-ftb-on' : 'cspv-ftb-off'; ?>" id="cspv-pause-status" style="<?php echo $tracking_paused ? 'background:rgba(255,255,255,.3);' : ''; ?>">
                    <?php echo $tracking_paused ? '⏸ TRACKING PAUSED' : '● TRACKING ACTIVE'; ?>
                </span>
            </div>
            <div style="padding:20px 24px;">
                <p class="cspv-throttle-desc" style="margin-bottom:16px;">Emergency kill switch. When paused, the beacon JavaScript is not loaded on any page and the recording API silently rejects all requests. Use this to instantly stop all view tracking during an attack. Historical data is preserved.</p>
                <div class="cspv-throttle-row" style="border-bottom:none;">
                    <span class="cspv-throttle-label">Pause all tracking<br><small>Stops beacon + API recording immediately</small></span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-tracking-paused" <?php checked( $tracking_paused ); ?>>
                        <span class="cspv-toggle" style="<?php echo $tracking_paused ? 'background:#dc2626;' : ''; ?>" id="cspv-pause-toggle"></span>
                        <span id="cspv-pause-label" class="cspv-toggle-text" style="<?php echo $tracking_paused ? 'color:#dc2626;' : ''; ?>"><?php echo $tracking_paused ? 'Paused' : 'Active'; ?></span>
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
                <span class="cspv-ftb-status-pill <?php echo $ftb_enabled ? 'cspv-ftb-on' : 'cspv-ftb-off'; ?>" id="cspv-ftb-status-pill">
                    <?php echo $ftb_enabled ? '● FTB ACTIVE' : '○ FTB OFF'; ?>
                </span>
            </div>
            <div id="cspv-ftb-body">
                <p class="cspv-throttle-desc">Second tier protection. When an IP exceeds the configurable page limit within the throttle time window, it is blocked for <strong>2 hours</strong> (auto clears). Unlike tier 1 throttle (1 hour), FTB gives a longer cooling off period for persistent abusers.</p>

                <div class="cspv-throttle-row">
                    <span class="cspv-throttle-label">Enable Fail2Ban</span>
                    <label class="cspv-toggle-wrap">
                        <input type="checkbox" id="cspv-ftb-enabled" <?php checked( $ftb_enabled ); ?>>
                        <span class="cspv-toggle"></span>
                        <span id="cspv-ftb-toggle-label" class="cspv-toggle-text"><?php echo $ftb_enabled ? 'Enabled' : 'Disabled'; ?></span>
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
                    <div class="cspv-ftb-rule-status <?php echo $ftb_rules['enabled'] ? 'cspv-ftb-active' : 'cspv-ftb-inactive'; ?>">
                        <?php echo $ftb_rules['enabled'] ? '● Active' : '○ Inactive'; ?>
                    </div>
                    <div class="cspv-ftb-rule-summary" id="cspv-ftb-rule-summary">
                        <?php echo esc_html( $ftb_rules['summary'] ); ?>
                    </div>
                    <div class="cspv-ftb-rule-details">
                        <span>Page limit: <strong><?php echo number_format( $ftb_rules['page_limit'] ); ?></strong></span>
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

    <!-- ═══════════════════════ MIGRATE TAB ════════════════════════ -->
    <div id="cspv-tab-migrate" class="cspv-tab-content">
        <div id="cspv-migrate-inner">

            <div class="cspv-section-header" style="background:linear-gradient(135deg,#5a1a8f,#8b2be2);">
                <span>🔀 Migrate Jetpack Statistics <a class="cspv-info-btn" data-info="migrate" title="Info">i</a></span>
                <?php if ( $migration_locked ) : ?>
                <span style="background:rgba(255,255,255,.2);border-radius:12px;font-size:11px;padding:3px 10px;font-weight:700;">🔒 Migration complete</span>
                <?php endif; ?>
            </div>

            <div style="padding:20px 24px;">

                <?php if ( $migration_locked && is_array( $migration_lock ) ) : ?>
                <!-- Already migrated banner -->
                <div style="background:#f0faf4;border:1.5px solid #1db954;border-radius:6px;padding:14px 18px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:14px;font-weight:700;color:#1a7a3a;margin-bottom:6px;">✅ Migration already completed</div>
                        <div style="font-size:13px;color:#444;line-height:1.8;">
                            Ran on <strong><?php echo esc_html( $migration_lock['date'] ?? '—' ); ?></strong> ·
                            <strong><?php echo (int) ( $migration_lock['posts_migrated'] ?? 0 ); ?></strong> posts ·
                            <strong><?php echo number_format( (int) ( $migration_lock['views_imported'] ?? 0 ) ); ?></strong> views imported ·
                            Mode: <strong><?php echo esc_html( $migration_lock['mode'] ?? '—' ); ?></strong>
                        </div>
                        <div style="font-size:12px;color:#888;margin-top:6px;">
                            Running migration again would double-count views already imported. Use Reset Lock only if you need to re-import (e.g. after adding content from a Jetpack export).
                        </div>
                    </div>
                    <button id="cspv-btn-reset-lock" class="cspv-btn-danger-sm" style="white-space:nowrap;flex-shrink:0;">
                        🔓 Reset Lock
                    </button>
                </div>
                <?php endif; ?>

                <p style="font-size:13px;color:#444;line-height:1.7;margin:0 0 16px;">
                    This tool reads your historical Jetpack view counts and imports them into CloudScale Page Views.
                    After migration you can safely disable Jetpack's Stats module or uninstall Jetpack entirely.
                    <strong>Migration runs once</strong> — a lock prevents accidental double-counting.
                </p>

                <div id="cspv-migrate-preflight" style="background:#f7f3ff;border:1.5px solid #c9a8f5;border-radius:6px;padding:14px 18px;margin-bottom:18px;">
                    <div style="font-size:13px;color:#5a1a8f;font-weight:700;margin-bottom:4px;">What will be migrated</div>
                    <div id="cspv-preflight-status" style="font-size:13px;color:#666;">
                        <em>Click "Check" to scan your Jetpack data before committing.</em>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                    <button id="cspv-btn-check" class="cspv-btn-primary" style="background:linear-gradient(135deg,#5a1a8f,#8b2be2);"
                            <?php echo $migration_locked ? 'disabled title="Reset the migration lock first to re-check"' : ''; ?>>
                        🔍 Check Jetpack Data
                    </button>
                    <label style="font-size:12px;color:#555;display:flex;align-items:center;gap:6px;">
                        <input type="radio" name="cspv_migrate_mode" value="additive" checked>
                        Additive <span style="color:#999;">(add Jetpack views on top of existing CS views)</span>
                    </label>
                    <label style="font-size:12px;color:#555;display:flex;align-items:center;gap:6px;">
                        <input type="radio" name="cspv_migrate_mode" value="replace">
                        Replace <span style="color:#999;">(overwrite CS view counts with Jetpack totals)</span>
                    </label>
                </div>

                <div id="cspv-migrate-postlist" style="display:none;margin-bottom:20px;">
                    <div class="cspv-section-header cspv-section-header-green">
                        <span>Posts found in Jetpack</span><span>JP Views → Will add</span>
                    </div>
                    <div id="cspv-migrate-rows"></div>
                </div>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button id="cspv-btn-migrate" class="cspv-btn-primary"
                            style="background:linear-gradient(135deg,#1a7a3a,#1db954);display:none;">
                        ✅ Run Migration
                    </button>
                    <span id="cspv-migrate-status" style="font-size:13px;font-weight:700;"></span>
                </div>

                <div id="cspv-migrate-result" style="display:none;background:#f0faf4;border:1.5px solid #1db954;border-radius:6px;padding:14px 18px;margin-top:16px;">
                    <div style="font-size:14px;font-weight:700;color:#1a7a3a;margin-bottom:8px;">✅ Migration complete</div>
                    <div id="cspv-migrate-result-body" style="font-size:13px;color:#444;line-height:1.8;"></div>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #b7e4c7;font-size:12px;color:#666;">
                        <strong>Next steps:</strong><br>
                        1. Disable Jetpack Stats module (Jetpack → Settings → Traffic → Stats toggle off).<br>
                        2. Update theme templates: replace <code>jetpack_get_stat()</code> with <code>cspv_get_view_count()</code>.<br>
                        3. Once confirmed working, you can safely uninstall Jetpack.
                    </div>
                </div>

                <!-- Manual CSV import (shown when Jetpack is cloud-only) -->
                <div id="cspv-manual-import-section" style="display:none;margin-top:20px;border-top:2px dashed #e2e8f0;padding-top:18px;">
                    <div style="font-size:13px;font-weight:700;color:#1a2332;margin-bottom:8px;">📋 Manual Import from WordPress.com CSV</div>
                    <p style="font-size:12px;color:#666;line-height:1.7;margin:0 0 10px;">
                        1. Log in to <strong>WordPress.com</strong> → Your Site → Stats → scroll to bottom → <strong>Export CSV</strong>.<br>
                        2. Open the CSV, copy the post rows in this format (one per line):<br>
                        <code style="display:inline-block;margin:6px 0;background:#f0f4ff;padding:4px 8px;border-radius:3px;">post-slug-or-id, view_count</code><br>
                        Example: <code style="background:#f0f4ff;padding:2px 6px;border-radius:3px;">my-first-post, 4521</code>
                        or &nbsp;<code style="background:#f0f4ff;padding:2px 6px;border-radius:3px;">42, 4521</code>
                    </p>
                    <textarea id="cspv-csv-input"
                        placeholder="my-first-post, 4521&#10;another-post-slug, 1200&#10;42, 890"
                        style="width:100%;height:110px;font-family:monospace;font-size:12px;border:1.5px solid #dde3ee;border-radius:4px;padding:8px;box-sizing:border-box;resize:vertical;color:#333;"></textarea>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
                        <button id="cspv-btn-manual-import" class="cspv-btn-primary" style="background:linear-gradient(135deg,#c45c00,#f47c20);">
                            📥 Import CSV Data
                        </button>
                        <span id="cspv-manual-import-status" style="font-size:13px;font-weight:700;"></span>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $migration_log ) ) : ?>
            <div class="cspv-section-header" style="background:linear-gradient(135deg,#2d3748,#4a5568);margin-top:0;">
                <span>Migration History</span>
            </div>
            <div style="padding:16px 24px;">
                <?php foreach ( $migration_log as $entry ) : ?>
                <div style="display:flex;gap:16px;padding:8px 0;border-bottom:1px solid #f0f4ff;font-size:12px;color:#555;flex-wrap:wrap;">
                    <span style="color:#888;"><?php echo esc_html( $entry['date'] ); ?></span>
                    <span><strong><?php echo (int) $entry['posts_migrated']; ?></strong> posts migrated</span>
                    <span><strong><?php echo number_format( (int) $entry['views_imported'] ); ?></strong> views imported</span>
                    <span><?php echo (int) $entry['posts_skipped']; ?> skipped</span>
                    <span style="background:<?php echo $entry['mode']==='replace' ? '#fff3e8' : '#e8f5ff'; ?>;padding:1px 6px;border-radius:3px;">
                        <?php echo esc_html( $entry['mode'] ); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div><!-- /migrate tab -->

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
            <div style="padding:0 24px 20px;text-align:right;">
                <button id="cspv-help-modal-ok" class="cspv-btn-primary" style="padding:8px 28px;">Got it</button>
            </div>
        </div>
    </div>

</div><!-- /#cspv-app -->

<style>
/* ============================================================
   CloudScale Page Views — Admin UI  v2.0.0
   Colour palette mirrors CloudScale: electric blue, bright green,
   vivid orange, deep navy, white.
   ============================================================ */

/* Reset within wrap */
#cspv-app * { box-sizing: border-box; }
#cspv-app {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 13px;
    color: #1a2332;
    max-width: 1200px;
    margin-top: 10px;
}

/* ── Banner ─────────────────────────────────────── */
#cspv-banner {
    background: linear-gradient(135deg, #1a3a8f 0%, #1e6fd9 60%, #0fb8e0 100%);
    border-radius: 8px 8px 0 0;
    padding: 20px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
#cspv-banner-title {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.3px;
}
#cspv-banner-sub {
    font-size: 12px;
    color: rgba(255,255,255,.75);
    margin-top: 3px;
}
#cspv-banner-right { display: flex; gap: 10px; align-items: center; }
.cspv-badge {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
.cspv-badge-green  { background: #1db954; color: #fff; }
.cspv-badge-orange { background: #f47c20; color: #fff; }

/* ── Tab bar ─────────────────────────────────────── */
#cspv-tab-bar {
    background: #1a2d6b;
    display: flex;
    gap: 0;
    padding: 0 20px;
}
.cspv-tab {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.6);
    font-size: 13px;
    font-weight: 600;
    padding: 12px 20px;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all .15s;
}
.cspv-tab:hover { color: #fff; }
.cspv-tab.active { color: #fff; border-bottom-color: #f47c20; }

/* ── Tab content ─────────────────────────────────── */
.cspv-tab-content { display: none; padding: 20px 0 0; }
.cspv-tab-content.active { display: block; }

/* ── Display tab ─────────────────────────────────── */
.cspv-dsp-radios { display: flex; flex-direction: column; gap: 8px; }
.cspv-dsp-radios label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 10px; border-radius: 6px; transition: background .1s; }
.cspv-dsp-radios label:hover { background: #f5f3ff; }
.cspv-dsp-radios input[type="radio"]:checked + * { font-weight: 600; }
.cspv-dsp-styles { display: flex; gap: 12px; flex-wrap: wrap; }
.cspv-dsp-style-card { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: border-color .15s; }
.cspv-dsp-style-card:hover { border-color: #a78bfa; }
.cspv-dsp-style-card.active { border-color: #7c3aed; background: #faf5ff; }
.cspv-dsp-style-card input { display: none; }
.cspv-dsp-style-name { font-size: 11px; font-weight: 600; color: #666; }
.cspv-dsp-checks { display: flex; gap: 12px; flex-wrap: wrap; }
.cspv-dsp-checks label { font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 4px; }

/* ── Date bar ────────────────────────────────────── */
#cspv-date-bar {
    background: #fff;
    border: 1px solid #dce3ef;
    border-radius: 6px;
    padding: 12px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
}
#cspv-quick-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.cspv-quick {
    background: #f0f4ff;
    border: 1.5px solid #c5d2f0;
    border-radius: 5px;
    padding: 5px 14px;
    font-size: 12px;
    font-weight: 600;
    color: #1a3a8f;
    cursor: pointer;
    transition: all .15s;
}
.cspv-quick:hover { background: #dce6ff; border-color: #1a3a8f; }
.cspv-quick.active {
    background: #1a3a8f;
    border-color: #1a3a8f;
    color: #fff;
}
#cspv-date-inputs { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #555; }
#cspv-date-inputs input[type="date"] {
    border: 1.5px solid #dce3ef;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 12px;
    color: #1a2332;
}

/* ── Buttons ─────────────────────────────────────── */
.cspv-btn-primary {
    background: linear-gradient(135deg, #1a3a8f, #1e6fd9);
    border: none;
    border-radius: 5px;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 6px 16px;
    cursor: pointer;
    transition: opacity .15s;
}
.cspv-btn-primary:hover { opacity: .88; }
.cspv-btn-danger-sm {
    background: #e53e3e;
    border: none;
    border-radius: 4px;
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    cursor: pointer;
}
.cspv-btn-unblock {
    background: transparent;
    border: 1.5px solid #e53e3e;
    border-radius: 4px;
    color: #e53e3e;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 10px;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
}
.cspv-btn-unblock:hover { background: #e53e3e; color: #fff; }

/* ── Summary cards ───────────────────────────────── */
#cspv-cards {
    display: flex;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.cspv-card {
    flex: 1;
    min-width: 150px;
    background: #fff;
    border: 1.5px solid #dce3ef;
    border-radius: 8px;
    padding: 18px 22px;
    position: relative;
    overflow: hidden;
}
.cspv-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
}
#cspv-card-views::before { background: linear-gradient(90deg,#1a3a8f,#1e6fd9); }
#cspv-card-posts::before { background: linear-gradient(90deg,#1db954,#0fb8e0); }
#cspv-card-avg::before   { background: linear-gradient(90deg,#f47c20,#f7b733); }
.cspv-card-icon  { font-size: 18px; margin-bottom: 6px; }
.cspv-card-value { font-size: 34px; font-weight: 800; line-height: 1; color: #1a2332; }
.cspv-card-label { font-size: 12px; color: #7a8aaa; margin-top: 4px; font-weight: 500; }
.cspv-card-delta { font-size: 11px; margin-top: 6px; font-weight: 700; }
.cspv-delta-up   { color: #e53e3e; }
.cspv-delta-down { color: #1db954; }
.cspv-delta-same { color: #aaa; }

/* ── Chart ───────────────────────────────────────── */
#cspv-chart-box {
    background: #fff;
    border: 1.5px solid #dce3ef;
    border-radius: 8px;
    margin-bottom: 18px;
    overflow: hidden;
}
#cspv-chart-wrap {
    position: relative;
    height: 220px;
    padding: 16px 16px 4px;
}
#cspv-chart { width: 100% !important; height: 100% !important; }
#cspv-chart-msg {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #aaa;
    background: #fff;
    border-radius: 0 0 8px 8px;
}
#cspv-chart-msg.hidden { display: none; }

/* ── Section headers ─────────────────────────────── */
.cspv-section-header {
    background: linear-gradient(135deg, #1a3a8f, #1e6fd9);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 10px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.cspv-section-header-green  { background: linear-gradient(135deg, #1a7a3a, #1db954); }
.cspv-section-header-orange { background: linear-gradient(135deg, #c45c00, #f47c20); }
.cspv-ref-toggle-wrap { display: flex; gap: 0; }
.cspv-ref-toggle {
    background: rgba(255,255,255,.2); border: none; color: rgba(255,255,255,.7);
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: 3px 10px; cursor: pointer; transition: background .15s, color .15s;
}
.cspv-ref-toggle:first-child { border-radius: 3px 0 0 3px; }
.cspv-ref-toggle:last-child  { border-radius: 0 3px 3px 0; }
.cspv-ref-toggle:hover { background: rgba(255,255,255,.3); }
.cspv-ref-toggle.active { background: rgba(255,255,255,.95); color: #c45c00; }
.cspv-section-header-red    { background: linear-gradient(135deg, #8b1a1a, #e53e3e); }
.cspv-range-label { font-size: 11px; font-weight: 400; opacity: .85; }

/* ── Panels ──────────────────────────────────────── */
#cspv-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}
@media (max-width: 782px) { #cspv-panels { grid-template-columns: 1fr; } }
#cspv-panels-alltime { margin-bottom: 18px; display: flex; gap: 18px; }
.cspv-panel { background: #fff; border: 1.5px solid #dce3ef; border-radius: 8px; overflow: hidden; }

#cspv-lifetime-bar {
    display: flex;
    gap: 18px;
    margin-bottom: 18px;
    background: linear-gradient(135deg, #0d2147 0%, #1a3a8f 60%, #1e6fd9 100%);
    border-radius: 8px;
    padding: 14px 20px;
}
.cspv-lifetime-stat {
    display: flex;
    align-items: center;
    gap: 10px;
}
.cspv-lifetime-label {
    color: rgba(255,255,255,.75);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.cspv-lifetime-value {
    color: #fff;
    font-size: 22px;
    font-weight: 800;
}
.cspv-lifetime-stat + .cspv-lifetime-stat {
    margin-left: 24px;
    padding-left: 24px;
    border-left: 1px solid rgba(255,255,255,.2);
}

.cspv-row {
    display: flex;
    align-items: center;
    padding: 9px 14px;
    border-bottom: 1px solid #f0f4ff;
    gap: 10px;
    font-size: 13px;
}
.cspv-row:last-child { border-bottom: none; }
.cspv-bar-wrap { flex: 1; position: relative; min-width: 0; }
.cspv-bar-fill {
    position: absolute; left: -6px; top: -5px; bottom: -5px;
    border-radius: 2px; z-index: 0; transition: width .35s ease;
}
.cspv-panel:first-child .cspv-bar-fill { background: #e8f5ff; }
.cspv-panel:last-child  .cspv-bar-fill { background: #fff3e8; }
.cspv-bar-label {
    position: relative; z-index: 1;
    font-size: 13px; color: #1a2332;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: block; max-width: 100%;
}
.cspv-bar-label a { color: #1a3a8f; text-decoration: none; }
.cspv-bar-label a:hover { text-decoration: underline; }
.cspv-row-views { font-size: 13px; font-weight: 700; color: #1a2332; min-width: 36px; text-align: right; flex-shrink: 0; }
.cspv-empty { padding: 18px 14px; color: #aaa; font-size: 13px; font-style: italic; }
.cspv-loading { padding: 18px 14px; color: #bbb; font-size: 13px; }

/* ── CF cache bypass test ────────────────────────── */
#cspv-cf-notice {
    background: #fffbeb;
    border-left: 4px solid #f47c20;
    border-radius: 0 6px 6px 0;
    padding: 12px 16px;
    font-size: 12px;
    color: #6b4c00;
    margin-bottom: 18px;
}
#cspv-cf-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
}
#cspv-cf-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
#cspv-cf-rule {
    font-size: 12px;
    color: #92660a;
    margin-bottom: 4px;
}
#cspv-cf-notice code {
    background: #fef3c7;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 11px;
}
#cspv-cf-status-badge {
    display: none;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}
#cspv-cf-status-badge.pass { display:inline-block; background:#1db954; color:#fff; }
#cspv-cf-status-badge.fail { display:inline-block; background:#e53e3e; color:#fff; }
#cspv-cf-status-badge.testing { display:inline-block; background:#aaa; color:#fff; }
#cspv-cf-test-log {
    margin-top: 8px;
    font-size: 12px;
    line-height: 1.9;
    color: #555;
    display: none;
}
#cspv-cf-test-log.visible { display: block; }
.cspv-cf-step { display: flex; align-items: baseline; gap: 6px; }
.cspv-cf-step-icon { flex-shrink: 0; width: 16px; text-align: center; }
.cspv-cf-step.pending .cspv-cf-step-icon::before { content: '⏳'; }
.cspv-cf-step.ok      .cspv-cf-step-icon::before { content: '✅'; }
.cspv-cf-step.fail    .cspv-cf-step-icon::before { content: '❌'; }

/* ── Throttle tab ────────────────────────────────── */
#cspv-throttle-inner {
    background: #fff;
    border: 1.5px solid #dce3ef;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
}
#cspv-throttle-body { padding: 20px 24px; }
.cspv-throttle-desc { font-size: 13px; color: #555; margin: 0 0 20px; line-height: 1.6; }
.cspv-throttle-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f4ff;
    gap: 20px;
    flex-wrap: wrap;
}
.cspv-throttle-row:last-of-type { border-bottom: none; }
.cspv-throttle-label { font-size: 13px; color: #1a2332; font-weight: 600; line-height: 1.5; }
.cspv-throttle-label small { font-weight: 400; color: #888; font-size: 11px; display: block; }
.cspv-throttle-control { display: flex; align-items: center; gap: 8px; }
.cspv-throttle-control input[type="number"] {
    width: 80px; border: 1.5px solid #dce3ef;
    border-radius: 4px; padding: 5px 8px; font-size: 13px;
}
.cspv-throttle-control select {
    border: 1.5px solid #dce3ef; border-radius: 4px; padding: 5px 8px; font-size: 13px;
}
.cspv-unit { font-size: 12px; color: #888; }
.cspv-throttle-actions { margin-top: 18px; display: flex; align-items: center; gap: 12px; }
#cspv-save-status { font-size: 12px; font-weight: 700; }
#cspv-save-status.ok  { color: #1db954; }
#cspv-save-status.err { color: #e53e3e; }

/* Toggle */
.cspv-toggle-wrap { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.cspv-toggle-wrap input { position: absolute; opacity: 0; width: 0; height: 0; }
.cspv-toggle {
    position: relative; display: inline-block;
    width: 44px; height: 24px;
    background: #ccc; border-radius: 12px; flex-shrink: 0;
    transition: background .2s;
}
.cspv-toggle::after {
    content: ''; position: absolute; top: 3px; left: 3px;
    width: 18px; height: 18px; background: #fff; border-radius: 50%;
    transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.3);
}
.cspv-toggle-wrap input:checked + .cspv-toggle { background: #1db954; }
.cspv-toggle-wrap input:checked + .cspv-toggle::after { transform: translateX(20px); }
.cspv-toggle-text { font-size: 12px; font-weight: 700; color: #888; min-width: 55px; }
.cspv-toggle-wrap input:checked ~ .cspv-toggle-text { color: #1db954; }

/* Blocklist */
#cspv-blocklist-body { padding: 16px 24px; }
.cspv-blocklist-note { font-size: 11px; color: #aaa; margin-bottom: 12px; }
.cspv-block-row {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 0; border-bottom: 1px solid #f0f4ff; font-size: 12px;
}
.cspv-block-row:last-child { border-bottom: none; }
.cspv-hash { font-family: monospace; color: #555; flex: 1; font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cspv-block-at { color: #aaa; font-size: 11px; white-space: nowrap; flex-shrink: 0; }
.cspv-badge-count {
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.25); border-radius: 10px;
    font-size: 11px; font-weight: 700; min-width: 20px; height: 20px;
    padding: 0 6px; margin-left: 6px;
}

/* Fail2Ban */
#cspv-ftb-inner {
    background: #fff;
    border: 1.5px solid #dce3ef;
    border-radius: 8px;
    overflow: hidden;
}
#cspv-ftb-body { padding: 20px 24px; }
#cspv-ftb-blocklist-body { padding: 16px 24px; }
.cspv-ftb-rule-card {
    background: #fefce8; border: 1.5px solid #fde68a; border-radius: 6px;
    padding: 14px 18px;
}
.cspv-ftb-rule-status {
    font-size: 12px; font-weight: 700; margin-bottom: 6px;
}
.cspv-ftb-active { color: #dc2626; }
.cspv-ftb-inactive { color: #9ca3af; }
.cspv-ftb-rule-summary {
    font-size: 14px; font-weight: 600; color: #1a2332; margin-bottom: 8px;
}
.cspv-ftb-rule-details {
    display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #666;
}
.cspv-ftb-rule-details strong { color: #1a2332; }
.cspv-btn-danger {
    background: linear-gradient(135deg, #b91c1c, #dc2626); color: #fff;
    border: none; border-radius: 6px; font-weight: 700; cursor: pointer;
    transition: opacity .15s;
}
.cspv-btn-danger:hover { opacity: .85; }

/* ── Info / Explain buttons and modal ────────────── */
.cspv-info-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    background: rgba(255,255,255,.25); border: 1.5px solid rgba(255,255,255,.5);
    color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;
    margin-left: 8px; line-height: 1; transition: background .15s;
    font-family: Georgia, serif; font-style: italic; vertical-align: middle;
    text-decoration: none;
}
.cspv-info-btn:hover { background: rgba(255,255,255,.45); }
/* Dark variant for white-bg sections */
.cspv-info-btn-dark {
    background: #e5e7eb; border-color: #d1d5db; color: #374151;
}
.cspv-info-btn-dark:hover { background: #d1d5db; }

.cspv-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 100000;
    background: rgba(0,0,0,.5); align-items: center; justify-content: center;
    padding: 20px;
}
.cspv-modal-overlay.active { display: flex; }
.cspv-modal {
    background: #fff; border-radius: 12px; max-width: 560px; width: 100%;
    max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.3);
}
.cspv-modal-header {
    padding: 16px 20px; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; justify-content: space-between;
}
.cspv-modal-header h3 { margin: 0; font-size: 16px; color: #1a2332; }
.cspv-modal-close {
    background: none; border: none; font-size: 22px; color: #999;
    cursor: pointer; padding: 0 4px; line-height: 1;
}
.cspv-modal-close:hover { color: #333; }
.cspv-modal-body {
    padding: 20px; font-size: 13px; line-height: 1.7; color: #374151;
}
.cspv-modal-body p { margin: 0 0 12px; }
.cspv-modal-body p:last-child { margin-bottom: 0; }
.cspv-modal-body strong { color: #1a2332; }
.cspv-modal-body code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }

/* Help button in tab bar */
.cspv-tab-spacer { flex: 1; }
.cspv-tab-help {
    background: #1a2332; color: #fff; border: none; border-radius: 6px;
    font-size: 12px; font-weight: 700; padding: 6px 14px; cursor: pointer;
    transition: background .15s; white-space: nowrap;
}
.cspv-tab-help:hover { background: #2d3f5e; }

/* FTB status pill in section header */
.cspv-ftb-status-pill {
    display: inline-block; padding: 3px 12px; border-radius: 12px;
    font-size: 11px; font-weight: 700; letter-spacing: .3px;
}
.cspv-ftb-on  { background: rgba(255,255,255,.2); color: #fff; }
.cspv-ftb-off { background: rgba(0,0,0,.15); color: rgba(255,255,255,.6); }

/* Help modal cards */
.cspv-help-card {
    border: 1.5px solid #dce3ef; border-radius: 8px; padding: 16px 20px;
    margin-bottom: 14px;
}
.cspv-help-card:last-child { margin-bottom: 0; }
.cspv-help-card-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
}
.cspv-help-card-title {
    font-size: 13px; font-weight: 700; color: #1a2332; text-transform: uppercase;
    letter-spacing: .3px;
}
.cspv-help-card-badge {
    display: inline-block; padding: 2px 10px; border-radius: 4px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
}
.cspv-help-badge-info { background: #dbeafe; color: #1e40af; }
.cspv-help-badge-optional { background: #e5e7eb; color: #6b7280; }
.cspv-help-badge-required { background: #fee2e2; color: #991b1b; }
.cspv-help-badge-tip { background: #d1fae5; color: #065f46; }
.cspv-help-card-body {
    font-size: 13px; color: #555; line-height: 1.7;
}
.cspv-help-card-body strong { color: #1a2332; }
</style>

<script>
(function () {
    'use strict';

    var ajaxUrl      = <?php echo wp_json_encode( $ajax_url ); ?>;
    var nonce        = <?php echo wp_json_encode( $ajax_nonce ); ?>;
    var throttleNonce = <?php echo wp_json_encode( $throttle_nonce ); ?>;
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
            document.getElementById('cspv-from').value = (r === 'today') ? t : daysAgo(parseInt(r) - 1);
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
    function loadData() {
        var from = document.getElementById('cspv-from').value;
        var to   = document.getElementById('cspv-to').value;

        // Reset UI
        document.getElementById('cspv-chart-msg').classList.remove('hidden');
        document.getElementById('cspv-chart-msg').textContent = 'Loading…';
        document.getElementById('stat-views').textContent = '—';
        document.getElementById('stat-posts').textContent = '—';
        document.getElementById('stat-avg').textContent   = '—';
        document.getElementById('stat-delta').textContent = '';
        document.getElementById('cspv-top-posts').innerHTML   = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('cspv-referrers').innerHTML   = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('cspv-lifetime-top').innerHTML = '<div class="cspv-loading">Loading…</div>';
        document.getElementById('stat-lifetime-views').textContent = '—';
        document.getElementById('stat-lifetime-posts').textContent = '—';
        document.getElementById('cspv-chart-range-label').textContent = '';

        var fd = new FormData();
        fd.append('action',    'cspv_chart_data');
        fd.append('nonce',     nonce);
        fd.append('date_from', from);
        fd.append('date_to',   to);

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
        document.getElementById('stat-views').textContent = data.total_views.toLocaleString();
        document.getElementById('stat-posts').textContent = data.unique_posts.toLocaleString();
        var days = Math.max(1, data.diff_days + 1);
        var avg = data.total_views / days;
        var avgStr = Number.isInteger(avg) ? avg.toLocaleString() : avg.toFixed(1);
        if (avgStr.slice(-2) === '.0') { avgStr = avgStr.slice(0, -2); }
        document.getElementById('stat-avg').textContent = avgStr;

        var deltaEl = document.getElementById('stat-delta');
        if (data.prev_total > 0) {
            var pct   = Math.round(((data.total_views - data.prev_total) / data.prev_total) * 100);
            var arrow = pct > 0 ? '↑' : (pct < 0 ? '↓' : '–');
            var cls   = pct > 0 ? 'cspv-delta-up' : (pct < 0 ? 'cspv-delta-down' : 'cspv-delta-same');
            deltaEl.textContent = arrow + ' ' + Math.abs(pct) + '% vs prev period';
            deltaEl.className   = 'cspv-card-delta ' + cls;
        }

        // Render lists BEFORE chart so they always appear even if Chart.js
        // has not loaded yet (fixes blank page on initial Tools menu load)
        renderList('cspv-top-posts', data.top_posts, true);

        // Store referrer data for toggle switching
        lastRefSites = data.referrers || [];
        lastRefPages = data.referrer_pages || [];
        renderReferrers();

        // Lifetime totals (includes Jetpack imports)
        document.getElementById('stat-lifetime-views').textContent =
            (data.lifetime_total || 0).toLocaleString();
        document.getElementById('stat-lifetime-posts').textContent =
            (data.lifetime_posts || 0).toLocaleString();
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
            return '<div class="cspv-row">'
                 + '<div class="cspv-bar-wrap">'
                 +   '<div class="cspv-bar-fill" style="width:' + pct + '%"></div>'
                 +   '<span class="cspv-bar-label">' + label + '</span>'
                 + '</div>'
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
                { title: 'Summary Cards', badge: 'info', body: 'The summary cards show <strong>total views</strong>, <strong>unique posts viewed</strong>, and <strong>average views per day</strong> for the selected date range. Use the quick range buttons (Today, 7 Days, 30 Days, 6 Months) or the custom date picker to change the period.' },
                { title: 'Chart', badge: 'info', body: 'The chart displays views over time. A single day shows hourly breakdown, up to 90 days shows daily, and longer ranges show weekly aggregation. All data comes from the beacon log table.' },
                { title: 'Most Viewed Posts', badge: 'info', body: 'Top 10 posts ranked by view count within the selected period. Only views recorded by the JavaScript beacon are counted here (not imported Jetpack totals). Click any title to visit the post.' },
                { title: 'All Time Statistics', badge: 'info', body: 'The All Time banner shows your lifetime total across all posts, including any imported Jetpack data. The All Time Top Posts list ranks by lifetime total, combining imported data with beacon views.' },
                { title: 'Top Referrers', badge: 'info', body: 'Shows the top referring domains for the selected period. Direct visits and your own domain are excluded. Common sources include Google, social media, and external links.' },
                { title: 'Cloudflare Cache Bypass', badge: 'tip', body: 'The diagnostic test confirms your Cloudflare Cache Rule is correctly bypassing cache for the REST API. If the counter does not increment, add a Cache Rule: URI Path contains <code>/wp-json/cloudscale-page-views/</code> → Bypass Cache.' },
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
                { title: 'Tracking Filter', badge: 'info', body: 'Controls which post types <strong>record views</strong> in the database. Untracked post types silently skip the beacon. Separate from the display setting.' },
                { title: 'Installation', badge: 'required', body: 'No additional setup needed. Styles are applied automatically. If you use a caching plugin, purge your page cache after changing display settings.' }
            ]
        },
        'throttle': {
            title: 'IP Throttle & Fail2Ban — How It Works',
            cards: [
                { title: 'Emergency Tracking Pause', badge: 'tip', body: 'The <strong>Page Tracking</strong> kill switch at the top instantly stops all view recording across your entire site. When paused, the beacon JavaScript is not loaded and the API silently rejects requests. Use this during sustained attacks to protect your database from junk data. Historical data is preserved.' },
                { title: 'Tier 1: Throttle (Soft Block)', badge: 'info', body: 'After an IP exceeds the <strong>request limit</strong> (default: 50) within the <strong>time window</strong> (default: 1 hour), it is silently blocked for 1 hour. The block auto expires — no manual cleanup needed. Attackers receive HTTP 200 so they have no signal to adapt.' },
                { title: 'Tier 2: Fail2Ban (Hard Block)', badge: 'info', body: 'When an IP exceeds the <strong>page limit</strong> (default: 1,000) within the same time window, it is blocked for <strong>2 hours</strong>. FTB blocks auto clear after 2 hours. This catches persistent abusers who keep returning after throttle blocks expire.' },
                { title: 'How to Know if FTB is Running', badge: 'tip', body: 'Look for the <strong>● FTB ACTIVE</strong> or <strong>○ FTB OFF</strong> status pill in the Fail2Ban section header. The FTB Rules card also shows whether the rule is Active or Inactive, along with the current page limit and window. Use the <strong>🧪 Test Fail2Ban</strong> button to run a full diagnostic.' },
                { title: 'Test Fail2Ban', badge: 'info', body: 'The <strong>🧪 Test Fail2Ban</strong> button runs a five point diagnostic: it writes and reads a test transient (the engine behind FTB blocks), checks options table access (blocklist storage), verifies FTB is enabled, and confirms the block duration. If all five tests pass, Fail2Ban is fully operational.' },
                { title: 'FTB Installation', badge: 'required', body: '<strong>No external software is required.</strong> CloudScale Fail2Ban is entirely built in to the plugin. It does <strong>not</strong> use the Linux fail2ban service or any server side packages. It works purely through WordPress transients and the options table, which means it runs on any WordPress host including shared hosting, managed WordPress, and VPS.<br><br><strong>Requirements:</strong><br>• WordPress 6.0+ with a working database<br>• The plugin activated (no additional configuration files)<br>• Transients must work (they do on all standard WordPress installs; if you use an object cache like Redis or Memcached, transients are stored there instead and still work correctly)<br><br><strong>No server configuration, no firewall rules, no cron jobs needed.</strong> Enable the toggle above and FTB starts protecting immediately.' },
                { title: 'Clear IP Addresses', badge: 'tip', body: 'The <strong>Clear All IP Addresses</strong> button at the bottom is a nuclear option that wipes all throttle blocks, FTB blocks, all request counters, and all event logs across both tiers. Use this to start fresh after configuration changes or testing.' }
            ]
        },
        'migrate': {
            title: 'Jetpack Migration — How It Works',
            cards: [
                { title: 'What Migration Does', badge: 'info', body: 'Reads your historical Jetpack view counts (<code>jetpack_post_views</code> meta) and imports them into CloudScale (<code>_cspv_view_count</code> field). After migration you can safely disable Jetpack Stats or uninstall Jetpack entirely.' },
                { title: 'One Time Operation', badge: 'info', body: 'Migration runs <strong>once</strong>. A lock prevents accidental re-runs that would double-count views. The lock records how many posts and views were imported, and when.' },
                { title: 'Transition Period', badge: 'info', body: 'For the first <strong>28 days</strong> after migration, the plugin blends imported totals with new beacon data so your historically popular posts remain visible while CloudScale builds its own history. After 28 days, ranking switches to pure beacon data.' },
                { title: 'Reset Lock', badge: 'optional', body: 'If you need to re-import (for example after adding content from a Jetpack export), use the <strong>Reset Lock</strong> button. This allows the migration to run again. Be aware that re-running without resetting view counts first will double-count.' },
                { title: 'Installation', badge: 'required', body: 'Jetpack (or its Stats module) must have been previously active so that <code>jetpack_post_views</code> meta values exist. No external API access is needed — the migration reads directly from your WordPress database.' }
            ]
        }
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
        document.getElementById('cspv-help-modal').classList.add('active');
    });
    document.getElementById('cspv-help-modal-close').addEventListener('click', function() {
        document.getElementById('cspv-help-modal').classList.remove('active');
    });
    document.getElementById('cspv-help-modal-ok').addEventListener('click', function() {
        document.getElementById('cspv-help-modal').classList.remove('active');
    });
    document.getElementById('cspv-help-modal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
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
        var testUrl  = <?php echo wp_json_encode( rest_url( 'cloudscale-page-views/v1/cache-test' ) ); ?>;
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

    // ── Jetpack Migration ──────────────────────────────────────────
    var migrateNonce  = <?php echo wp_json_encode( $migrate_nonce ); ?>;
    var preflight     = null;

    // Reset migration lock
    var resetLockBtn = document.getElementById('cspv-btn-reset-lock');
    if (resetLockBtn) {
        resetLockBtn.addEventListener('click', function() {
            if (!confirm('Reset the migration lock?\n\nOnly do this if you genuinely need to re-run the migration (e.g. after restoring a database backup or importing new content). Running migration twice on the same data WILL double your view counts.')) { return; }
            var btn = this;
            btn.disabled = true;
            btn.textContent = '⏳ Resetting…';
            var fd = new FormData();
            fd.append('action', 'cspv_migration_reset_lock');
            fd.append('nonce',  migrateNonce);
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp) {
                    if (resp.success) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = '🔓 Reset Lock';
                        alert('Failed to reset lock: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                    }
                });
        });
    }

    document.getElementById('cspv-btn-check').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '⏳ Scanning…';
        document.getElementById('cspv-preflight-status').innerHTML = 'Scanning Jetpack data…';
        document.getElementById('cspv-migrate-postlist').style.display = 'none';
        document.getElementById('cspv-btn-migrate').style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'cspv_jetpack_preflight');
        fd.append('nonce',  migrateNonce);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                btn.disabled = false;
                btn.textContent = '🔍 Check Jetpack Data';
                if (!resp.success) {
                    document.getElementById('cspv-preflight-status').innerHTML =
                        '<span style="color:#e53e3e;">Error: ' + esc(resp.data.message || 'Unknown error') + '</span>';
                    return;
                }
                preflight = resp.data;
                renderPreflight(preflight);
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '🔍 Check Jetpack Data';
                document.getElementById('cspv-preflight-status').innerHTML =
                    '<span style="color:#e53e3e;">Network error: ' + esc(err.message) + '</span>';
            });
    });

    function renderPreflight(data) {
        var html    = '';
        var noteHtml = data.note
            ? '<br><span style="color:#888;font-size:11px;">ℹ ' + esc(data.note) + '</span>'
            : '';
        var methodBadge = '';
        if (data.method && data.method !== 'none') {
            var badges = {
                'stats_get_csv':  { label: '☁ Live WP.com API',   color: '#1a7a3a', bg: '#f0faf4' },
                'WPCOM_Stats':    { label: '☁ WPCOM_Stats API',    color: '#1a7a3a', bg: '#f0faf4' },
                'post_meta_legacy': { label: '💾 Local meta',      color: '#1a3a8f', bg: '#f0f4ff' },
                'post_meta_stats':  { label: '💾 Local meta',      color: '#1a3a8f', bg: '#f0f4ff' },
            };
            var b = badges[data.method] || { label: data.method, color: '#555', bg: '#f5f5f5' };
            methodBadge = ' <span style="font-size:10px;padding:2px 7px;border-radius:10px;background:' + b.bg + ';color:' + b.color + ';font-weight:700;">' + b.label + '</span>';
        }

        if (data.posts_found === 0) {
            if (!data.jetpack_active) {
                html = '<span style="color:#e53e3e;">⚠ Jetpack does not appear to be active on this site.</span>' + noteHtml;
            } else if (data.cloud_only) {
                html = '<strong style="color:#c45c00;">☁ Jetpack stats API returned no data</strong><br>'
                     + '<span style="color:#666;font-size:12px;line-height:1.8;">'
                     + 'Jetpack is active and the API is reachable, but returned 0 posts.<br>'
                     + 'This can happen if: the Stats module is warming up, your site has very few views, '
                     + 'or the WordPress.com API is temporarily slow.<br>'
                     + '<strong>Try clicking Check again in a few seconds.</strong> If it keeps failing, '
                     + 'use the Manual Import below.'
                     + '</span>' + noteHtml;
            } else {
                html = '<span style="color:#e53e3e;">⚠ No Jetpack view data found.</span>' + noteHtml;
            }
            document.getElementById('cspv-preflight-status').innerHTML = html;
            document.getElementById('cspv-migrate-postlist').style.display = 'none';
            document.getElementById('cspv-btn-migrate').style.display = 'none';
            document.getElementById('cspv-manual-import-section').style.display = 'block';
            return;
        }

        html = '<strong>' + data.posts_found + '</strong> posts found' + methodBadge + ' · '
             + '<strong>' + data.total_jp_views.toLocaleString() + '</strong> Jetpack views · '
             + '<strong>' + data.total_cs_views.toLocaleString() + '</strong> current CS views'
             + noteHtml;
        document.getElementById('cspv-preflight-status').innerHTML = html;
        document.getElementById('cspv-manual-import-section').style.display = 'none';

        // Render post list
        var rows = '';
        data.posts.slice(0, 50).forEach(function(p) {
            rows += '<div style="display:flex;gap:10px;align-items:center;padding:8px 14px;'
                  + 'border-bottom:1px solid #f0f4ff;font-size:13px;flex-wrap:wrap;">'
                  + '<span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1a2332;">'
                  + esc(p.title) + '</span>'
                  + '<span style="color:#8b2be2;font-weight:700;min-width:80px;text-align:right;">'
                  + p.jetpack_views.toLocaleString() + ' JP</span>'
                  + '<span style="color:#1db954;font-weight:700;min-width:70px;text-align:right;">+'
                  + p.will_add.toLocaleString() + '</span>'
                  + '</div>';
        });
        if (data.posts.length > 50) {
            rows += '<div style="padding:8px 14px;font-size:12px;color:#999;">…and '
                  + (data.posts.length - 50) + ' more</div>';
        }
        document.getElementById('cspv-migrate-rows').innerHTML = rows;
        document.getElementById('cspv-migrate-postlist').style.display = 'block';
        document.getElementById('cspv-btn-migrate').style.display = 'inline-block';
    }

    // ── Manual CSV import ─────────────────────────────────────────
    document.getElementById('cspv-btn-manual-import').addEventListener('click', function() {
        var csv = document.getElementById('cspv-csv-input').value.trim();
        if (!csv) {
            document.getElementById('cspv-manual-import-status').textContent = 'Please paste CSV data first.';
            document.getElementById('cspv-manual-import-status').style.color = '#e53e3e';
            return;
        }
        if (!confirm('Import this CSV data? Each post view count will be increased by the imported amount.')) { return; }

        var btn    = this;
        var status = document.getElementById('cspv-manual-import-status');
        btn.disabled = true;
        btn.textContent = '⏳ Importing…';
        status.textContent = '';

        var fd = new FormData();
        fd.append('action',   'cspv_manual_import');
        fd.append('nonce',    migrateNonce);
        fd.append('csv_data', csv);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                btn.disabled = false;
                btn.textContent = '📥 Import CSV Data';
                if (!resp.success) {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : 'Import failed';
                    if (resp.data && resp.data.already_locked) { msg = '🔒 Already migrated. Reset the lock first.'; }
                    status.textContent = '✗ ' + msg;
                    status.style.color = '#e53e3e';
                    return;
                }
                var d = resp.data;
                var body = '<strong>' + d.migrated + '</strong> posts imported · '
                         + '<strong>' + d.views_imported.toLocaleString() + '</strong> views added · '
                         + d.skipped + ' lines skipped';
                if (d.errors && d.errors.length > 0) {
                    body += '<br><span style="color:#e53e3e;font-size:11px;">Could not resolve: ' + d.errors.join(', ') + '</span>';
                }
                document.getElementById('cspv-migrate-result-body').innerHTML = body;
                document.getElementById('cspv-migrate-result').style.display = 'block';
                status.textContent = '';
                setTimeout(function(){ window.location.reload(); }, 2500);
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '📥 Import CSV Data';
                status.textContent = '✗ Network error: ' + err.message;
                status.style.color = '#e53e3e';
            });
    });

    document.getElementById('cspv-btn-migrate').addEventListener('click', function() {
        if (!preflight || preflight.posts_found === 0) { return; }
        if (!confirm('Run migration now? This will update view counts in your database.')) { return; }

        var btn    = this;
        var mode   = document.querySelector('input[name="cspv_migrate_mode"]:checked').value;
        btn.disabled = true;
        btn.textContent = '⏳ Migrating…';
        document.getElementById('cspv-migrate-status').textContent = '';

        var fd = new FormData();
        fd.append('action', 'cspv_jetpack_migrate');
        fd.append('nonce',  migrateNonce);
        fd.append('mode',   mode);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                btn.disabled = false;
                btn.textContent = '✅ Run Migration';
                if (!resp.success) {
                    var errMsg = (resp.data && resp.data.message) ? resp.data.message : 'Migration failed';
                    if (resp.data && resp.data.already_locked) {
                        errMsg = '🔒 Migration already completed on ' + (resp.data.locked_at || '—') + '. Reset the lock if you need to re-run.';
                    }
                    document.getElementById('cspv-migrate-status').textContent = '✗ ' + errMsg;
                    document.getElementById('cspv-migrate-status').style.color = '#e53e3e';
                    return;
                }
                var d = resp.data;
                var body = '<strong>' + d.migrated + '</strong> posts updated · '
                         + '<strong>' + d.views_imported.toLocaleString() + '</strong> views imported · '
                         + d.skipped + ' posts skipped · Mode: <strong>' + d.mode + '</strong>';
                document.getElementById('cspv-migrate-result-body').innerHTML = body;
                document.getElementById('cspv-migrate-result').style.display = 'block';
                btn.style.display = 'none';
                // Reload page after 2s to update migration history
                setTimeout(function(){ window.location.reload(); }, 2500);
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = '✅ Run Migration';
                document.getElementById('cspv-migrate-status').textContent = '✗ Network error: ' + err.message;
                document.getElementById('cspv-migrate-status').style.color = '#e53e3e';
            });
    });

    // ── Info modal system ──────────────────────────────────────────
    var infoData = {
        'stats-overview': {
            title: '📊 Statistics Overview',
            body: '<p>The <strong>summary cards</strong> show total views, unique posts viewed, and average views per day for the selected date range. Use the quick buttons or custom date picker to change the period.</p><p>The <strong>chart</strong> shows views over time with tabs for 7 Hours, 7 Days, 1 Month, and 6 Months. All chart data comes from the beacon log table, reflecting actual recorded views.</p><p>If you recently migrated from Jetpack, the cards blend imported totals with new beacon data during a 28 day transition period so the numbers are not misleadingly low.</p>'
        },
        'top-posts': {
            title: '🏆 Most Viewed Posts',
            body: '<p>Shows the top 10 posts ranked by view count within the selected date range. Only views recorded by the beacon are counted here (not imported Jetpack totals).</p><p>Click any post title to visit it on your site. The view count reflects the selected period, not all time totals.</p>'
        },
        'all-time': {
            title: '🏆 All Time Statistics',
            body: '<p>The <strong>All Time Views</strong> banner shows your total lifetime views across all posts, including any imported Jetpack data. This reads from the <code>_cspv_view_count</code> post meta field.</p><p>The <strong>All Time Top Posts</strong> list ranks posts by their lifetime total, combining imported data with beacon recorded views. This is useful for seeing your historically most popular content.</p>'
        },
        'referrers': {
            title: '🔗 Top Referrers',
            body: '<p>Shows the top referring domains sending traffic to your site during the selected period. The referrer is captured from the HTTP Referer header when a visitor arrives at your post.</p><p>Direct visits (no referrer) and visits from your own domain are excluded. Common sources include Google, social media platforms, and other sites linking to your content.</p>'
        },
        'cache-test': {
            title: '☁ Cloudflare Cache Test',
            body: '<p>This diagnostic tests whether your Cloudflare Cache Rule is correctly bypassing the cache for the CloudScale REST API endpoint.</p><p>Click <strong>Run Test</strong> to send a POST followed by a GET to the cache test endpoint. If the counter increments, the endpoint is not cached and your Cache Rule is working. If the counter stays the same on repeated tests, Cloudflare is caching the API response and views will not be recorded.</p><p>To fix, add a Cache Rule in Cloudflare: URI Path contains <code>/wp-json/cloudscale-page-views/</code> → Bypass Cache.</p>'
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
            body: '<p>Controls which post types actually <strong>record views</strong> in the database. When a visitor views an untracked post type, the beacon does not fire and no view is logged.</p><p>This is separate from the display setting. The Tracking Filter controls what gets counted. The Show Counter On setting controls what displays a badge. You can track Pages without displaying a counter on them, keeping your stats comprehensive while keeping your page layouts clean.</p>'
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
            body: '<p>This is an <strong>emergency kill switch</strong> that instantly stops all page view tracking across your entire site.</p><p>When activated:<br>• The beacon JavaScript is not loaded on any page<br>• The recording REST API silently rejects all requests (returns HTTP 200 with logged: false)<br>• No new views are counted or stored<br>• All historical data is preserved</p><p>Use this when your site is under a sustained bot attack and you want to prevent the database from being flooded with junk view data. Re-enable tracking once the attack subsides.</p>'
        },
        'dedup': {
            title: '🔁 View Deduplication',
            body: '<p>Prevents the same visitor from being counted multiple times for the same post within a configurable time window.</p><p><strong>Client side:</strong> The beacon stores a timestamp in localStorage for each post viewed. Repeat visits within the window skip the recording API entirely. This handles same browser tab/window reopens.</p><p><strong>Server side:</strong> Before inserting a view, the API checks whether the same IP hash + post ID combination already exists in the database within the dedup window. This catches cross browser duplicates, such as a WhatsApp in app browser followed by opening the same link in Chrome.</p><p>When disabled, every page load records a view (subject only to IP throttle limits). The default window is 24 hours.</p>'
        },
        'migrate': {
            title: '🔀 Migrate from Jetpack Stats',
            body: '<p>Imports lifetime view totals from Jetpack Stats into CloudScale. The migration reads <code>jetpack_post_views</code> meta values from all posts and writes them into the CloudScale <code>_cspv_view_count</code> field.</p><p>This is a <strong>one time operation</strong>. After migration, a lock prevents accidental re runs. The migration copies lifetime totals only, not per day breakdowns (Jetpack does not store daily granularity in post meta).</p><p>For the first <strong>28 days</strong> after migration, the plugin runs in transition mode. Summary cards and the Top Posts widget blend imported totals with new beacon data so your historically popular posts remain visible while the plugin builds its own history. After 28 days, ranking switches to pure beacon data.</p>'
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
            document.getElementById('cspv-modal').classList.add('active');
        });
    });
    document.getElementById('cspv-modal-close').addEventListener('click', function() {
        document.getElementById('cspv-modal').classList.remove('active');
    });
    document.getElementById('cspv-modal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });

})();
</script>
    <?php
}
