<?php
/**
 * Plugin Name:  CloudScale Page Views
 * Plugin URI:   https://andrewbaker.ninja
 * Description:  Accurate page view tracking via a JavaScript beacon that bypasses Cloudflare cache. Includes auto display on posts, Top Posts and Recent Posts sidebar widgets, and a live statistics dashboard under Tools.
 * Version:      2.9.8
 * Author:       Andrew Baker
 * Author URI:   https://andrewbaker.ninja
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  cloudscale-page-views
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSPV_VERSION',    '2.9.8' );
define( 'CSPV_META_KEY',   '_cspv_view_count' );
define( 'CSPV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSPV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
