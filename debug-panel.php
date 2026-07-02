<?php
/**
 * CloudScale Analytics - View Diagnostics Panel  v2.0.0
 *
 * Admin only overlay on singular posts showing:
 *   - Summary row: total views, first view, last view
 *   - 14-day traffic sparkline from the log table
 *   - Country breakdown from the geo table (flag + bar + count)
 *   - Manual override to set the displayed count
 *
 * Only visible to users with manage_options capability.
 * Button renders INLINE next to the view counter (pink, 🐛 icon).
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.PHP.DevelopmentFunctions.error_log_error_log -- analytics plugin: all interpolated vars are internal table/column names; direct queries on custom time-series tables are required

// Enqueue styles for the debug panel (admin-only, singular only).
add_action( 'wp_enqueue_scripts', 'cspv_debug_panel_enqueue' );

/**
 * Enqueue inline CSS and JS for the debug panel on singular admin-visible pages.
 *
 * @since 2.0.0
 * @return void
 */
function cspv_debug_panel_enqueue() {
    if ( ! is_singular() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $css = '#cspv-debug-toggle{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#db2777,#f472b6);color:#fff;border:none;cursor:pointer;font-size:13px;font-weight:700;line-height:1;padding:7px 14px;border-radius:20px;box-shadow:0 2px 8px rgba(219,39,119,.3);transition:transform .15s,box-shadow .15s;vertical-align:middle;margin-left:8px;letter-spacing:.02em;}'
         . '#cspv-debug-toggle:hover{transform:scale(1.05);box-shadow:0 3px 12px rgba(219,39,119,.4);}'
         . '#cspv-debug-panel{display:none;position:fixed;bottom:16px;right:16px;z-index:99999;width:400px;max-width:calc(100vw - 32px);max-height:calc(100vh - 32px);overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.2);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;color:#1a2332;}'
         . '#cspv-debug-panel.open{display:block;}'
         . '.cspv-dbg-header{background:linear-gradient(135deg,#db2777 0%,#f472b6 100%);color:#fff;padding:12px 16px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;display:flex;justify-content:space-between;align-items:center;}'
         . '.cspv-dbg-header small{display:block;font-weight:400;opacity:.75;text-transform:none;letter-spacing:0;margin-top:2px;font-size:11px;}'
         . '.cspv-dbg-close{background:rgba(255,255,255,.2);border:none;color:#fff;cursor:pointer;width:28px;height:28px;border-radius:50%;font-size:14px;display:flex;align-items:center;justify-content:center;padding:0;}'
         . '.cspv-dbg-close:hover{background:rgba(255,255,255,.35);}'
         . '.cspv-dbg-body{padding:14px 16px;}'
         . '.cspv-dbg-row{display:flex;justify-content:space-between;align-items:baseline;padding:5px 0;border-bottom:1px solid #f0f0f0;}'
         . '.cspv-dbg-row:last-child{border-bottom:none;}'
         . '.cspv-dbg-label{color:#666;font-size:12px;}'
         . '.cspv-dbg-value{font-weight:700;font-variant-numeric:tabular-nums;}'
         . '.cspv-dbg-value.green{color:#059669;}.cspv-dbg-value.blue{color:#1e6fd9;}.cspv-dbg-value.orange{color:#f47c20;}.cspv-dbg-value.red{color:#e53e3e;}'
         . '.cspv-dbg-section{font-size:11px;font-weight:700;text-transform:uppercase;color:#aaa;letter-spacing:.04em;margin:12px 0 6px;padding-top:8px;border-top:1px solid #eee;}'
         . '.cspv-dbg-section:first-child{border-top:none;margin-top:0;padding-top:0;}'
         /* Summary stat row */
         . '.cspv-dbg-stat-row{display:flex;align-items:stretch;gap:0;background:#f8f9fb;border-radius:8px;overflow:hidden;margin-bottom:4px;}'
         . '.cspv-dbg-stat{flex:1;padding:6px 8px;text-align:center;}'
         . '.cspv-dbg-stat+.cspv-dbg-stat{border-left:1px solid #e8eaed;}'
         . '.cspv-dbg-stat-num{display:block;font-size:18px;font-weight:800;color:#1a2332;line-height:1.1;font-variant-numeric:tabular-nums;}'
         . '.cspv-dbg-stat-num.sm{font-size:12px;font-weight:700;color:#111827;}'
         . '.cspv-dbg-stat-lbl{display:block;font-size:10px;color:#4b5563;text-transform:uppercase;letter-spacing:.04em;margin-top:1px;font-weight:600;}'
         /* Chart */
         . '.cspv-dbg-chart{height:64px;display:flex;align-items:flex-end;gap:2px;margin-top:4px;}'
         . '.cspv-dbg-bar{flex:1;background:linear-gradient(180deg,#db2777,#f9a8d4);border-radius:2px 2px 0 0;min-height:2px;position:relative;cursor:default;}'
         . '.cspv-dbg-bar:hover{opacity:.8;}'
         . '.cspv-dbg-bar-empty{background:#e8eaed;}'
         . '.cspv-dbg-bar-tip{display:none;position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:#1a2332;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;white-space:nowrap;pointer-events:none;z-index:1;}'
         . '.cspv-dbg-bar:hover .cspv-dbg-bar-tip{display:block;}'
         . '.cspv-dbg-chart-labels{display:flex;justify-content:space-between;font-size:10px;color:#aaa;margin-top:2px;}'
         . '.cspv-dbg-body{padding-bottom:20px;}'
         /* Geo bars */
         . '.cspv-dbg-geo-row{display:flex;align-items:center;gap:7px;padding:4px 0;border-bottom:1px solid #f5f5f5;}'
         . '.cspv-dbg-geo-row:last-child{border-bottom:none;}'
         . '.cspv-dbg-geo-flag{font-size:16px;line-height:1;width:20px;text-align:center;flex-shrink:0;}'
         . '.cspv-dbg-geo-name{font-size:11px;color:#374151;width:90px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
         . '.cspv-dbg-geo-bar-wrap{flex:1;background:#f0f0f0;border-radius:2px;height:6px;overflow:hidden;}'
         . '.cspv-dbg-geo-bar-fill{height:100%;background:linear-gradient(90deg,#db2777,#f472b6);border-radius:2px;}'
         . '.cspv-dbg-geo-count{font-size:11px;font-weight:700;color:#6b7280;width:28px;text-align:right;flex-shrink:0;}'
         /* Override + warning */
         . '.cspv-dbg-warn{background:#fef3cd;border:1px solid #f0d060;border-radius:4px;padding:8px 10px;font-size:12px;margin-top:8px;color:#856404;}'
         . '.cspv-dbg-fix-btn{display:inline-block;margin-top:6px;padding:4px 12px;background:#e53e3e;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;}'
         . '.cspv-dbg-fix-btn:hover{background:#c53030;}'
         . '.cspv-dbg-override{display:flex;gap:6px;align-items:center;margin-top:8px;}'
         . '.cspv-dbg-override input{flex:1;padding:4px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;}'
         . '.cspv-dbg-override-btn{padding:4px 12px;background:#7c3aed;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;}'
         . '.cspv-dbg-override-btn:hover{background:#6d28d9;}';

    wp_register_style( 'cspv-debug-panel', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-debug-panel' );
    wp_add_inline_style( 'cspv-debug-panel', $css );

    wp_register_script( 'cspv-debug-panel', false, array(), CSPV_VERSION, true );
    wp_enqueue_script( 'cspv-debug-panel' );
}

// Inject the hidden debug toggle button at the top of post content for admins.
// The auto-display relocate JS moves it into the counter element after load.
add_filter( 'the_content', 'cspv_inject_debug_button', 100 );

/**
 * Prepend a hidden debug toggle button to post content for admins.
 *
 * @since 2.0.0
 * @param string $content Post content.
 * @return string Content with debug button prepended, or unchanged for non-admins.
 */
function cspv_inject_debug_button( $content ) {
    // is_singular() only tells us the REQUESTED page is a single post/page --
    // it says nothing about which post is currently being filtered. A page
    // template that lists other posts (e.g. a custom "topic" page rendering
    // excerpts via get_the_excerpt(), which internally runs the_content
    // through wp_trim_excerpt()) is itself singular, so without this check
    // the button gets prepended to every listed post's excerpt too -- not
    // just the one post actually being viewed. Confirmed happening on
    // andrewbaker.ninja's pillar topic pages, 2026-07-02.
    if ( ! is_singular() || get_the_ID() !== get_queried_object_id() ) {
		return $content; }
    if ( ! current_user_can( 'manage_options' ) ) {
		return $content; }
    $btn = '<button id="cspv-debug-toggle" style="display:none" title="View Diagnostics">🐛 Debug</button>';
    return wp_kses_post( $btn ) . $content;
}

/**
 * Convert a 2-char ISO 3166-1 country code to its flag emoji.
 *
 * @since 2.9.412
 * @param string $cc Country code (e.g. "ZA", "US").
 * @return string Flag emoji or 🌐 for unknown.
 */
function cspv_flag_emoji( string $cc ): string {
    $cc = strtoupper( trim( $cc ) );
    if ( strlen( $cc ) !== 2 || $cc === 'ZZ' ) {
        return '🌐';
    }
    $offset = 0x1F1E6 - ord( 'A' );
    return mb_chr( ord( $cc[0] ) + $offset ) . mb_chr( ord( $cc[1] ) + $offset );
}

/**
 * Return a human-readable country name for common ISO codes.
 *
 * @since 2.9.412
 * @param string $cc Country code.
 * @return string Country name, or the code itself if not in the map.
 */
function cspv_country_name( string $cc ): string {
    $cc  = strtoupper( trim( $cc ) );
    $map = array(
        'ZZ' => 'Unknown',
        'AD' => 'Andorra',
		'AE' => 'UAE',
		'AF' => 'Afghanistan',
        'AG' => 'Antigua',
		'AL' => 'Albania',
		'AM' => 'Armenia',
        'AO' => 'Angola',
		'AR' => 'Argentina',
		'AT' => 'Austria',
        'AU' => 'Australia',
		'AZ' => 'Azerbaijan',
		'BA' => 'Bosnia',
        'BB' => 'Barbados',
		'BD' => 'Bangladesh',
		'BE' => 'Belgium',
        'BF' => 'Burkina Faso',
		'BG' => 'Bulgaria',
		'BH' => 'Bahrain',
        'BJ' => 'Benin',
		'BN' => 'Brunei',
		'BO' => 'Bolivia',
        'BR' => 'Brazil',
		'BT' => 'Bhutan',
		'BW' => 'Botswana',
        'BY' => 'Belarus',
		'BZ' => 'Belize',
		'CA' => 'Canada',
        'CD' => 'DR Congo',
		'CF' => 'C. Africa',
		'CG' => 'Congo',
        'CH' => 'Switzerland',
		'CI' => "Côte d'Ivoire",
		'CL' => 'Chile',
        'CM' => 'Cameroon',
		'CN' => 'China',
		'CO' => 'Colombia',
        'CR' => 'Costa Rica',
		'CU' => 'Cuba',
		'CV' => 'Cape Verde',
        'CY' => 'Cyprus',
		'CZ' => 'Czechia',
		'DE' => 'Germany',
        'DJ' => 'Djibouti',
		'DK' => 'Denmark',
		'DM' => 'Dominica',
        'DO' => 'Dom. Rep.',
		'DZ' => 'Algeria',
		'EC' => 'Ecuador',
        'EE' => 'Estonia',
		'EG' => 'Egypt',
		'ER' => 'Eritrea',
        'ES' => 'Spain',
		'ET' => 'Ethiopia',
		'FI' => 'Finland',
        'FJ' => 'Fiji',
		'FR' => 'France',
		'GA' => 'Gabon',
        'GB' => 'UK',
		'GD' => 'Grenada',
		'GE' => 'Georgia',
        'GH' => 'Ghana',
		'GM' => 'Gambia',
		'GN' => 'Guinea',
        'GQ' => 'Eq. Guinea',
		'GR' => 'Greece',
		'GT' => 'Guatemala',
        'GW' => 'Guinea-Biss.',
		'GY' => 'Guyana',
		'HN' => 'Honduras',
        'HR' => 'Croatia',
		'HT' => 'Haiti',
		'HU' => 'Hungary',
        'ID' => 'Indonesia',
		'IE' => 'Ireland',
		'IL' => 'Israel',
        'IN' => 'India',
		'IQ' => 'Iraq',
		'IR' => 'Iran',
        'IS' => 'Iceland',
		'IT' => 'Italy',
		'JM' => 'Jamaica',
        'JO' => 'Jordan',
		'JP' => 'Japan',
		'KE' => 'Kenya',
        'KG' => 'Kyrgyzstan',
		'KH' => 'Cambodia',
		'KI' => 'Kiribati',
        'KM' => 'Comoros',
		'KN' => 'St Kitts',
		'KP' => 'N. Korea',
        'KR' => 'S. Korea',
		'KW' => 'Kuwait',
		'KZ' => 'Kazakhstan',
        'LA' => 'Laos',
		'LB' => 'Lebanon',
		'LC' => 'St Lucia',
        'LI' => 'Liechtenstein',
		'LK' => 'Sri Lanka',
		'LR' => 'Liberia',
        'LS' => 'Lesotho',
		'LT' => 'Lithuania',
		'LU' => 'Luxembourg',
        'LV' => 'Latvia',
		'LY' => 'Libya',
		'MA' => 'Morocco',
        'MC' => 'Monaco',
		'MD' => 'Moldova',
		'ME' => 'Montenegro',
        'MG' => 'Madagascar',
		'MH' => 'Marshall Is.',
		'MK' => 'N. Macedonia',
        'ML' => 'Mali',
		'MM' => 'Myanmar',
		'MN' => 'Mongolia',
        'MR' => 'Mauritania',
		'MT' => 'Malta',
		'MU' => 'Mauritius',
        'MV' => 'Maldives',
		'MW' => 'Malawi',
		'MX' => 'Mexico',
        'MY' => 'Malaysia',
		'MZ' => 'Mozambique',
		'NA' => 'Namibia',
        'NE' => 'Niger',
		'NG' => 'Nigeria',
		'NI' => 'Nicaragua',
        'NL' => 'Netherlands',
		'NO' => 'Norway',
		'NP' => 'Nepal',
        'NR' => 'Nauru',
		'NZ' => 'New Zealand',
		'OM' => 'Oman',
        'PA' => 'Panama',
		'PE' => 'Peru',
		'PG' => 'Papua NG',
        'PH' => 'Philippines',
		'PK' => 'Pakistan',
		'PL' => 'Poland',
        'PT' => 'Portugal',
		'PW' => 'Palau',
		'PY' => 'Paraguay',
        'QA' => 'Qatar',
		'RO' => 'Romania',
		'RS' => 'Serbia',
        'RU' => 'Russia',
		'RW' => 'Rwanda',
		'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Is.',
		'SC' => 'Seychelles',
		'SD' => 'Sudan',
        'SE' => 'Sweden',
		'SG' => 'Singapore',
		'SI' => 'Slovenia',
        'SK' => 'Slovakia',
		'SL' => 'Sierra Leone',
		'SM' => 'San Marino',
        'SN' => 'Senegal',
		'SO' => 'Somalia',
		'SR' => 'Suriname',
        'SS' => 'S. Sudan',
		'ST' => 'São Tomé',
		'SV' => 'El Salvador',
        'SY' => 'Syria',
		'SZ' => 'Eswatini',
		'TD' => 'Chad',
        'TG' => 'Togo',
		'TH' => 'Thailand',
		'TJ' => 'Tajikistan',
        'TL' => 'Timor-Leste',
		'TM' => 'Turkmenistan',
		'TN' => 'Tunisia',
        'TO' => 'Tonga',
		'TR' => 'Turkey',
		'TT' => 'Trinidad',
        'TV' => 'Tuvalu',
		'TZ' => 'Tanzania',
		'UA' => 'Ukraine',
        'UG' => 'Uganda',
		'US' => 'United States',
		'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
		'VA' => 'Vatican',
		'VC' => 'St Vincent',
        'VE' => 'Venezuela',
		'VN' => 'Vietnam',
		'VU' => 'Vanuatu',
        'WS' => 'Samoa',
		'YE' => 'Yemen',
		'ZA' => 'South Africa',
        'ZM' => 'Zambia',
		'ZW' => 'Zimbabwe',
    );
    return $map[ $cc ] ?? $cc;
}

// Render the panel in wp_footer.
add_action( 'wp_footer', 'cspv_render_debug_panel' );

/**
 * Render the diagnostics panel overlay in wp_footer for admin users.
 *
 * @since 2.0.0
 * @return void
 */
function cspv_render_debug_panel() {
    if ( ! is_singular() ) {
		return; }
    if ( ! current_user_can( 'manage_options' ) ) {
		return; }

    global $wpdb;
    $post_id = get_the_ID();
    $table   = esc_sql( cspv_views_table() );

    $meta_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $log_count    = 0;
    $first_log    = null;
    $last_log     = null;
    $daily_data   = array();
    $geo_data     = array();

    if ( $table_exists ) {
        $log_count = (int) $wpdb->get_var(
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COALESCE(SUM(view_count),0) FROM `{$table}` WHERE post_id = %d",
                $post_id
            )
        );

        $first_log = $wpdb->get_var(
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT MIN(viewed_at) FROM `{$table}` WHERE post_id = %d",
                $post_id
            )
        );

        $last_log = $wpdb->get_var(
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT MAX(viewed_at) FROM `{$table}` WHERE post_id = %d",
                $post_id
            )
        );

        // Last 14 days daily chart.
        $rows = $wpdb->get_results(
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT DATE(viewed_at) AS day, COALESCE(SUM(view_count),0) AS views
             FROM `{$table}`
             WHERE post_id = %d AND viewed_at >= %s
             GROUP BY day ORDER BY day ASC",
                $post_id,
                wp_date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 14 * 86400 ) )
            )
        );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $daily_data[] = array(
					'day'   => $r->day,
					'views' => (int) $r->views,
				);
            }
        }

        // Country totals (all-time) from geo table.
        $geo_table  = esc_sql( $wpdb->prefix . 'cs_analytics_geo_v2' );
        $geo_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'cs_analytics_geo_v2' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $geo_exists ) {
            $geo_rows = $wpdb->get_results(
                $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    "SELECT country_code, COALESCE(SUM(view_count),0) AS views
                 FROM `{$geo_table}`
                 WHERE post_id = %d
                 GROUP BY country_code ORDER BY views DESC LIMIT 8",
                    $post_id
                )
            );
            if ( is_array( $geo_rows ) ) {
                foreach ( $geo_rows as $g ) {
                    $geo_data[] = array(
						'cc'    => $g->country_code,
						'views' => (int) $g->views,
					);
                }
            }
        }
    }

    $mismatch = ( $meta_count !== $log_count && $meta_count < $log_count );

    // Format dates for summary row.
    $fmt_date = static function ( ?string $dt ): string {
        if ( ! $dt ) {
			return 'none'; }
        $ts = strtotime( $dt );
        if ( ! $ts ) {
			return $dt; }
        return wp_date( 'j M Y', $ts );
    };

    ?>
<div id="cspv-debug-panel">
    <div class="cspv-dbg-header">
        <div>🐛 View Diagnostics, Post #<?php echo (int) $post_id; ?></div>
        <button class="cspv-dbg-close" id="cspv-dbg-close" title="Close">✕</button>
    </div>
    <div class="cspv-dbg-body">

        <!-- Summary row: count + first + last -->
        <div class="cspv-dbg-stat-row">
            <div class="cspv-dbg-stat">
                <span class="cspv-dbg-stat-num"><?php echo esc_html( number_format( $meta_count ) ); ?></span>
                <span class="cspv-dbg-stat-lbl">views</span>
            </div>
            <div class="cspv-dbg-stat">
                <span class="cspv-dbg-stat-num sm"><?php echo esc_html( $fmt_date( $first_log ) ); ?></span>
                <span class="cspv-dbg-stat-lbl">first view</span>
            </div>
            <div class="cspv-dbg-stat">
                <span class="cspv-dbg-stat-num sm"><?php echo esc_html( $fmt_date( $last_log ) ); ?></span>
                <span class="cspv-dbg-stat-lbl">last view</span>
            </div>
        </div>

        <?php if ( $mismatch ) : ?>
        <div class="cspv-dbg-warn">
            ⚠ Meta count (<?php echo esc_html( number_format( $meta_count ) ); ?>) is behind log count (<?php echo esc_html( number_format( $log_count ) ); ?>).
            <br>
            <button class="cspv-dbg-fix-btn" id="cspv-dbg-resync">Resync meta from log table</button>
        </div>
        <?php endif; ?>

        <!-- 14-day traffic chart — always 14 slots, zero-filled for missing days -->
        <?php
        $today_ts  = strtotime( current_time( 'Y-m-d' ) );
        $chart_map = array();
        for ( $i = 13; $i >= 0; $i-- ) {
            $chart_map[ wp_date( 'Y-m-d', $today_ts - $i * 86400 ) ] = 0;
        }
        foreach ( $daily_data as $d ) {
            if ( isset( $chart_map[ $d['day'] ] ) ) {
                $chart_map[ $d['day'] ] = $d['views'];
            }
        }
        $chart_keys = array_keys( $chart_map );
        $max_v      = max( 1, max( array_values( $chart_map ) ) );
        ?>
        <div class="cspv-dbg-section">Last 14 days</div>
        <div class="cspv-dbg-chart">
            <?php foreach ( $chart_map as $day => $views ) : ?>
            <div class="cspv-dbg-bar<?php echo 0 === $views ? ' cspv-dbg-bar-empty' : ''; ?>"
                 style="height:<?php echo 0 === $views ? '4' : (int) max( 4, round( ( $views / $max_v ) * 100 ) ); ?>%">
                <span class="cspv-dbg-bar-tip"><?php echo esc_html( wp_date( 'j M', strtotime( $day ) ) . ': ' . number_format( $views ) ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cspv-dbg-chart-labels">
            <?php foreach ( array( 0, 3, 7, 10, 13 ) as $idx ) : ?>
            <span><?php echo esc_html( wp_date( 'j M', strtotime( $chart_keys[ $idx ] ) ) ); ?></span>
            <?php endforeach; ?>
        </div>

        <!-- Country breakdown -->
        <?php
        if ( ! empty( $geo_data ) ) :
            $geo_max = max( 1, max( array_column( $geo_data, 'views' ) ) );
			?>
        <div class="cspv-dbg-section">Locations</div>
			<?php
			foreach ( $geo_data as $g ) :
				$bar_pct = round( ( $g['views'] / $geo_max ) * 100 );
				?>
        <div class="cspv-dbg-geo-row">
            <span class="cspv-dbg-geo-flag"><?php echo esc_html( cspv_flag_emoji( $g['cc'] ) ); ?></span>
            <span class="cspv-dbg-geo-name"><?php echo esc_html( cspv_country_name( $g['cc'] ) ); ?></span>
            <div class="cspv-dbg-geo-bar-wrap">
                <div class="cspv-dbg-geo-bar-fill" style="width:<?php echo (int) $bar_pct; ?>%"></div>
            </div>
            <span class="cspv-dbg-geo-count"><?php echo esc_html( number_format( $g['views'] ) ); ?></span>
        </div>
			<?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

	<?php
    $js_data = 'var cspvDebug=' . wp_json_encode(
        array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cspv_resync' ),
			'postId'  => $post_id,
        )
    ) . ';';

    $js = $js_data . '
(function(){
    var toggle=document.getElementById("cspv-debug-toggle");
    var panel=document.getElementById("cspv-debug-panel");
    var close=document.getElementById("cspv-dbg-close");
    if(!toggle||!panel)return;
    var container=document.querySelector(".cspv-auto-views");
    if(container&&toggle.parentElement!==container){container.appendChild(toggle);}
    toggle.style.display="";
    toggle.addEventListener("click",function(){panel.classList.toggle("open");});
    if(close)close.addEventListener("click",function(){panel.classList.remove("open");});
    var resync=document.getElementById("cspv-dbg-resync");
    if(resync){
        resync.addEventListener("click",function(){
            resync.disabled=true;
            resync.textContent="Resyncing…";
            fetch(cspvDebug.ajaxUrl,{
                method:"POST",credentials:"same-origin",
                headers:{"Content-Type":"application/x-www-form-urlencoded"},
                body:"action=cspv_resync_meta_debug&nonce="+encodeURIComponent(cspvDebug.nonce)+"&post_id="+cspvDebug.postId
            }).then(function(r){return r.json();})
            .then(function(resp){
                if(resp.success){
                    resync.textContent="✓ Resynced to "+resp.data.new_count.toLocaleString();
                    resync.style.background="#059669";
                }else{resync.textContent="✗ Failed";}
            }).catch(function(){resync.textContent="✗ Error";});
        });
    }
    var overrideSave=document.getElementById("cspv-dbg-override-save");
    var overrideVal=document.getElementById("cspv-dbg-override-val");
    if(overrideSave&&overrideVal){
        overrideSave.addEventListener("click",function(){
            var v=parseInt(overrideVal.value,10);
            if(isNaN(v)||v<0){overrideVal.style.borderColor="#e53e3e";return;}
            overrideVal.style.borderColor="";
            if(!confirm("Set view count for this post to "+v.toLocaleString()+"?"))return;
            overrideSave.disabled=true;
            overrideSave.textContent="Saving…";
            fetch(cspvDebug.ajaxUrl,{
                method:"POST",credentials:"same-origin",
                headers:{"Content-Type":"application/x-www-form-urlencoded"},
                body:"action=cspv_set_view_count&nonce="+encodeURIComponent(cspvDebug.nonce)+"&post_id="+cspvDebug.postId+"&count="+v
            }).then(function(r){return r.json();})
            .then(function(resp){
                if(resp.success){
                    overrideSave.textContent="✓ Set to "+resp.data.new_count.toLocaleString();
                    overrideSave.style.background="#059669";
                }else{overrideSave.textContent="✗ Failed";overrideSave.disabled=false;}
            }).catch(function(){overrideSave.textContent="✗ Error";overrideSave.disabled=false;});
        });
    }
})();';

    wp_add_inline_script( 'cspv-debug-panel', $js );
}

// AJAX handler for resync (from front-end debug panel).
add_action( 'wp_ajax_cspv_resync_meta_debug', 'cspv_ajax_resync_meta' );

/**
 * AJAX handler: resync the post meta count from the log table.
 *
 * Sets _cspv_view_count to the sum of log rows (never reduces existing meta).
 * Requires manage_options capability and a valid nonce.
 *
 * @since 2.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_resync_meta() {
    if ( ! check_ajax_referer( 'cspv_resync', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    try {
        global $wpdb;
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
            return;
        }

        $table     = esc_sql( cspv_views_table() );
        $log_count = (int) $wpdb->get_var(
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COALESCE(SUM(view_count),0) FROM `{$table}` WHERE post_id = %d",
                $post_id
            )
        );
        $old_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
        // Never reduce the meta, a partial log restore shouldn't wipe out counts meta already knows about.
        $new_count = max( $old_count, $log_count );
        update_post_meta( $post_id, CSPV_META_KEY, $new_count );

        wp_send_json_success(
            array(
				'post_id'   => $post_id,
				'old_count' => $old_count,
				'new_count' => $new_count,
				'log_rows'  => $log_count,
            )
        );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}

// AJAX handler for manual count override (e.g. after a data restore).
add_action( 'wp_ajax_cspv_set_view_count', 'cspv_ajax_set_view_count' );

/**
 * AJAX handler: manually set the post meta view count to a specific value.
 *
 * Used to correct counts that were lost or corrupted during a data restore.
 * Requires manage_options capability and a valid nonce.
 *
 * @since 2.9.135
 * @return void Sends JSON response.
 */
function cspv_ajax_set_view_count() {
    if ( ! check_ajax_referer( 'cspv_resync', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    try {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
            return;
        }

        $new_count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;
        $old_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
        update_post_meta( $post_id, CSPV_META_KEY, $new_count );

        wp_send_json_success(
            array(
				'post_id'   => $post_id,
				'old_count' => $old_count,
				'new_count' => $new_count,
            )
        );
    } catch ( \Throwable $e ) {
        wp_send_json_error( $e->getMessage(), 500 );
    }
}
