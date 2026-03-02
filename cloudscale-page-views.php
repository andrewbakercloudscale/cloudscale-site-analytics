<?php
/**
 * Plugin Name:  CloudScale Page Views
 * Plugin URI:   https://your-wordpress-site.example.com
 * Description:  Accurate page view tracking via a JavaScript beacon that bypasses Cloudflare cache. Includes auto display on posts, Top Posts and Recent Posts sidebar widgets, and a live statistics dashboard under Tools.
 * Version:      2.9.42
 * Author:       Andrew Baker
 * Author URI:   https://your-wordpress-site.example.com
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  cloudscale-page-views
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSPV_VERSION',    '2.9.42' );
define( 'CSPV_META_KEY',   '_cspv_view_count' );
define( 'CSPV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSPV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── OPcache buster: invalidate all plugin files when version changes ──
$cspv_cached_ver = get_option( 'cspv_opcache_version', '' );
if ( $cspv_cached_ver !== CSPV_VERSION && function_exists( 'opcache_invalidate' ) ) {
    foreach ( glob( CSPV_PLUGIN_DIR . '*.php' ) as $cspv_file ) {
        opcache_invalidate( $cspv_file, true );
    }
    update_option( 'cspv_opcache_version', CSPV_VERSION, true );
}

require_once CSPV_PLUGIN_DIR . 'database.php';
require_once CSPV_PLUGIN_DIR . 'ip-throttle.php';
require_once CSPV_PLUGIN_DIR . 'rest-api.php';
require_once CSPV_PLUGIN_DIR . 'beacon.php';
require_once CSPV_PLUGIN_DIR . 'template-functions.php';
require_once CSPV_PLUGIN_DIR . 'jetpack-migration.php';
require_once CSPV_PLUGIN_DIR . 'top-posts-widget.php';
require_once CSPV_PLUGIN_DIR . 'recent-posts-widget.php';
require_once CSPV_PLUGIN_DIR . 'auto-display.php';
require_once CSPV_PLUGIN_DIR . 'admin-columns.php';
require_once CSPV_PLUGIN_DIR . 'dashboard-widget.php';
require_once CSPV_PLUGIN_DIR . 'stats-page.php';
require_once CSPV_PLUGIN_DIR . 'site-health.php';
require_once CSPV_PLUGIN_DIR . 'debug-panel.php';

/**
 * Shared rolling 24h view counts.
 * Uses WordPress timezone (current_time) so timestamps match viewed_at column.
 * Both the dashboard widget and stats page call these to guarantee identical numbers.
 */
function cspv_rolling_24h_views() {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return array( 'current' => 0, 'prior' => 0 );
    }
    $now   = current_time( 'mysql' );
    $h24   = date( 'Y-m-d H:i:s', strtotime( $now ) - 86400 );
    $h48   = date( 'Y-m-d H:i:s', strtotime( $now ) - 172800 );
    $current = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s", $h24, $now ) );
    $prior   = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE viewed_at BETWEEN %s AND %s", $h48, $h24 ) );
    return array( 'current' => $current, 'prior' => $prior );
}

register_activation_hook( __FILE__, 'cspv_activate' );

register_deactivation_hook( __FILE__, function() {
    $dir = plugin_dir_path( __FILE__ );
    foreach ( glob( $dir . '*.{js,css}', GLOB_BRACE ) as $f ) {
        if ( is_file( $f ) ) { @unlink( $f ); }
    }
    $assets = $dir . 'assets/';
    if ( is_dir( $assets ) ) {
        foreach ( glob( $assets . '*' ) as $f ) {
            if ( is_file( $f ) ) { @unlink( $f ); }
        }
        @rmdir( $assets );
    }
    $admin = $dir . 'admin/';
    if ( is_dir( $admin ) ) {
        foreach ( glob( $admin . '*' ) as $f ) {
            if ( is_file( $f ) ) { @unlink( $f ); }
        }
        @rmdir( $admin );
    }
    $inc = $dir . 'includes/';
    if ( is_dir( $inc ) ) {
        foreach ( glob( $inc . '*' ) as $f ) {
            if ( is_file( $f ) ) { @unlink( $f ); }
        }
        @rmdir( $inc );
    }
} );

add_action( 'admin_init', function() {
    $stored = get_option( 'cspv_version', '0' );
    if ( $stored !== CSPV_VERSION ) {
        if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }

        $dir    = plugin_dir_path( __FILE__ );
        $assets = $dir . 'assets/';
        if ( is_dir( $assets ) ) {
            foreach ( glob( $assets . '*' ) as $f ) {
                if ( is_file( $f ) ) { @unlink( $f ); }
            }
            @rmdir( $assets );
        }
        $admin = $dir . 'admin/';
        if ( is_dir( $admin ) ) {
            foreach ( glob( $admin . '*' ) as $f ) {
                if ( is_file( $f ) ) { @unlink( $f ); }
            }
            @rmdir( $admin );
        }
        $inc = $dir . 'includes/';
        if ( is_dir( $inc ) ) {
            foreach ( glob( $inc . '*' ) as $f ) {
                if ( is_file( $f ) ) { @unlink( $f ); }
            }
            @rmdir( $inc );
        }

        cspv_upgrade_table();
        update_option( 'cspv_version', CSPV_VERSION );
    }
} );
