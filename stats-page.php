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

require_once plugin_dir_path( __FILE__ ) . 'stats-page-ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'stats-page-render.php';

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
    wp_enqueue_style( 'cs-design-system',
        CSPV_PLUGIN_URL . 'assets/css/cloudscale-admin.css',
        array(), CSPV_VERSION );
    $css_ver = filemtime( CSPV_PLUGIN_DIR . 'assets/css/stats-page.css' ) ?: CSPV_VERSION;
    wp_enqueue_style( 'cspv-stats-page',
        CSPV_PLUGIN_URL . 'assets/css/stats-page.css',
        array( 'cs-design-system' ), $css_ver );
    wp_register_script( 'cspv-stats-page', false,
        array( 'cspv-chartjs', 'cspv-leaflet-js' ), CSPV_VERSION, true );
    wp_enqueue_script( 'cspv-stats-page' );

    // Auto-reload when a new version is deployed, avoids stale CSS on open tabs
    // and iOS Safari bfcache restores without requiring manual cache clearing.
    $ver_js  = '(function(){';
    $ver_js .= 'var v=' . wp_json_encode( CSPV_VERSION ) . ',k="cspv_ver";';
    $ver_js .= 'var stored=localStorage.getItem(k);';
    $ver_js .= 'localStorage.setItem(k,v);';
    $ver_js .= 'if(stored&&stored!==v){window.location.reload();return;}';
    $ver_js .= 'window.addEventListener("pageshow",function(e){if(e.persisted&&localStorage.getItem(k)!==v)window.location.reload();});';
    $ver_js .= 'document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible"&&localStorage.getItem(k)!==v)window.location.reload();});';
    $ver_js .= '})();';
    wp_add_inline_script( 'cspv-stats-page', $ver_js );
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
        $geo_notice = cspv_save_display_settings();
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__( 'Display settings saved.', 'cloudscale-wordpress-free-analytics' ) . $geo_notice
        );
    }

    $ajax_url        = admin_url( 'admin-ajax.php' );
    $ajax_nonce      = wp_create_nonce( 'cspv_chart_data' );
    $throttle_nonce  = wp_create_nonce( 'cspv_throttle' );
    $insights_nonce   = wp_create_nonce( 'cspv_insights' );
    $dashboard_nonce  = wp_create_nonce( 'cspv_insights_dashboard' );
    $display_nonce    = wp_create_nonce( 'cspv_display_save' );
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

    $vars = compact(
        'ajax_url', 'ajax_nonce', 'throttle_nonce', 'insights_nonce', 'dashboard_nonce',
        'display_nonce', 'today', 'throttle_enabled', 'throttle_limit', 'throttle_window',
        'blocklist', 'block_log', 'ftb_enabled', 'ftb_page_limit', 'ftb_window',
        'ftb_block_dur', 'ftb_rules', 'ftb_blocklist', 'ftb_log', 'tracking_paused',
        'dedup_val', 'dedup_enabled', 'dedup_window', 'ph_top_posts',
        'dsp_position', 'dsp_post_types', 'dsp_icon', 'dsp_suffix', 'dsp_style',
        'dsp_track_types', 'dsp_all_types', 'dsp_color'
    );
    ?>
<!DOCTYPE html>
<div id="cspv-app">

    <!-- ═══════════════════════ HEADER BANNER ═══════════════════════ -->
    <div id="cspv-banner">
        <div id="cspv-banner-left">
            <div id="cspv-banner-title"><img src="<?php echo esc_url( plugins_url( 'cloudscale-analytics-icon.jpg', __FILE__ ) ); ?>" style="height:22px;width:auto;vertical-align:middle;margin-right:8px;position:relative;top:-1px;" alt=""> CloudScale Site Analytics v<?php echo esc_html( CSPV_VERSION ); ?></div>
            <div id="cspv-banner-sub">Cloudflare-accurate view tracking · v<?php echo esc_html( CSPV_VERSION ); ?></div>
        </div>
        <div id="cspv-banner-right">
            <span class="cspv-badge cspv-badge-green">● Site Online</span>
            <a href="https://cloudscale.consulting" target="_blank" rel="noopener noreferrer" class="cspv-badge cspv-badge-orange" style="text-decoration:none;">cloudscale.consulting</a>
            <button id="cspv-help-btn" title="Help" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:2px solid rgba(255,255,255,0.5);background:rgba(255,255,255,0.15);color:#fff;font-size:15px;font-weight:800;cursor:pointer;line-height:1;padding:0;transition:background .15s;">?</button>
        </div>
    </div>

    <!-- ═══════════════════════ TAB BAR ═════════════════════════════ -->
    <div id="cspv-tab-bar">
        <button class="cspv-tab active" data-tab="stats">📊 Statistics</button>
        <button class="cspv-tab" data-tab="insights">💡 Insights</button>
        <button class="cspv-tab" data-tab="display">👁 Display</button>
        <button class="cspv-tab" data-tab="throttle">🛡 IP Throttle</button>
        <span class="cspv-tab-spacer"></span>
    </div>

    <?php
    cspv_render_stats_tab( $vars );
    cspv_render_insights_tab( $vars );
    cspv_render_display_tab( $vars );
    cspv_render_throttle_tab( $vars );
    cspv_render_stats_page_js( $vars );
}
