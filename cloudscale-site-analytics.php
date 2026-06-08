<?php
/**
 * Plugin Name:  CloudScale Site Analytics
 * Description:  Accurate page view tracking via a JavaScript beacon that bypasses Cloudflare cache. Includes auto display on posts, Top Posts and Recent Posts sidebar widgets, and a live statistics dashboard under Tools.
 * Version:      2.9.369
 * Author:       CloudScale
 * Author URI:   https://cloudscale.consulting
 * Contributors: cloudscale
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  cloudscale-site-analytics
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSPV_VERSION',    '2.9.369' );
define( 'CSPV_META_KEY',   '_cspv_view_count' );
define( 'CSPV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSPV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CSPV_PLUGIN_DIR . 'includes/class-cloudscale-telegram.php';
require_once CSPV_PLUGIN_DIR . 'stats-library.php';
require_once CSPV_PLUGIN_DIR . 'database.php';
require_once CSPV_PLUGIN_DIR . 'ip-throttle.php';
require_once CSPV_PLUGIN_DIR . 'rest-api.php';
require_once CSPV_PLUGIN_DIR . 'beacon.php';
require_once CSPV_PLUGIN_DIR . 'template-functions.php';
require_once CSPV_PLUGIN_DIR . 'top-posts-widget.php';
require_once CSPV_PLUGIN_DIR . 'recent-posts-widget.php';
require_once CSPV_PLUGIN_DIR . 'search.php';
require_once CSPV_PLUGIN_DIR . 'not-found.php';
require_once CSPV_PLUGIN_DIR . 'auto-display.php';
require_once CSPV_PLUGIN_DIR . 'admin-columns.php';
require_once CSPV_PLUGIN_DIR . 'dashboard-widget.php';
require_once CSPV_PLUGIN_DIR . 'stats-page.php';
require_once CSPV_PLUGIN_DIR . 'site-health.php';
require_once CSPV_PLUGIN_DIR . 'debug-panel.php';

register_activation_hook( __FILE__, 'cspv_activate' );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'cspv_dbip_auto_update' );
} );

// Ensure the daily DB-IP auto-update cron is always scheduled while the plugin is active.
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'cspv_dbip_auto_update' ) ) {
        wp_schedule_event( time(), 'daily', 'cspv_dbip_auto_update' );
    }
} );

add_action( 'admin_init', function () {
    $stored = get_option( 'cspv_version', '0' );
    if ( $stored !== CSPV_VERSION ) {
        if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }

        cspv_create_table_v2();
        cspv_create_table_referrers_v2();
        cspv_create_table_geo_v2();
        cspv_create_table_visitors_v2();
        cspv_create_table_404_v2();
        cspv_create_table_sessions_v2();
        update_option( 'cspv_version', CSPV_VERSION );
    }
} );
