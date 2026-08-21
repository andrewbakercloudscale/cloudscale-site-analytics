<?php
/**
 * Plugin Name:  CloudScale Site Analytics
 * Description:  Accurate page view tracking via a JavaScript beacon that bypasses Cloudflare cache. Includes auto display on posts, Top Posts and Recent Posts sidebar widgets, and a live statistics dashboard under Tools.
 * Version:      2.9.489
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

define( 'CSPV_VERSION',    '2.9.489' );
define( 'CSPV_META_KEY',   '_cspv_view_count' );
define( 'CSPV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSPV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Error text in the units the timeouts are set in: WordPress reports a 120-second ceiling as
// "120000 milliseconds". Required before the shared Telegram class, which uses it too.
require_once CSPV_PLUGIN_DIR . 'includes/class-cloudscale-error-text.php';
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

// Our UI uses plain native emoji characters everywhere (Smart Summary icons, flags).
// WP's built-in twemoji polyfill replaces them with <img> tags from s.w.org when it
// (sometimes wrongly) decides a browser lacks native emoji support; if that CDN is
// ever unreachable the images break. Since native rendering is all we need, skip the
// polyfill outright rather than depending on an external CDN we don't control.
add_action( 'init', function () {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'embed_head', 'print_emoji_detection_script' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
    } );
    // Matched on the host EXACTLY: not on a substring, and not on one exact URL.
    //
    // There are two ways to get this wrong and this filter has now had both. array_diff()
    // against the single literal 'https://s.w.org' stopped removing anything the moment core
    // used the other form it has shipped, '//s.w.org' — a registered filter quietly doing
    // nothing. Replacing it with strpos() then over-matched in the other direction:
    // 'ps.w.org' CONTAINS 's.w.org', and ps.w.org is a real WordPress host, so a hint naming
    // it would have been stripped as well.
    //
    // Entries arrive in more than one shape and all of them have to be handled: a full URL,
    // a scheme-relative '//host', and a BARE host with no scheme at all, because the
    // dns-prefetch list is assembled from parsed hostnames rather than URLs. Preconnect
    // entries may instead be an attribute array keyed 'href'. Anything unrecognised is KEPT
    // — the job here is to remove one known hint, so in doubt it must not remove.
    add_filter( 'wp_resource_hints', function ( $urls ) {
        if ( ! is_array( $urls ) ) {
            return $urls;
        }
        // This is the host of the emoji CDN hint being REMOVED from wp_resource_hints: the
        // plugin's effect here is to stop a remote asset load, not to start one.
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- host of the hint being removed, never requested.
        $emoji_host = 's.w.org';
        return array_values(
            array_filter(
                $urls,
                static function ( $u ) use ( $emoji_host ) {
                    if ( is_array( $u ) ) {
                        $u = isset( $u['href'] ) ? $u['href'] : '';
                    }
                    if ( ! is_string( $u ) || '' === $u ) {
                        return true;
                    }
                    $host = (string) wp_parse_url( $u, PHP_URL_HOST );
                    if ( '' === $host ) {
                        $host = ltrim( $u, '/' ); // a bare host, carrying no scheme
                    }
                    return 0 !== strcasecmp( $host, $emoji_host );
                }
            )
        );
    } );
}, 1 );

// wp-admin/includes/admin-filters.php re-registers the admin_print_scripts/
// admin_print_styles emoji hooks, and that file loads after `init` has already
// fired — so the removal above never catches them on admin pages. Remove again
// on admin_init, which runs after admin-filters.php has loaded.
add_action( 'admin_init', function () {
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
}, 1 );

register_activation_hook( __FILE__, 'cspv_activate' );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'cspv_dbip_auto_update' );
    wp_clear_scheduled_hook( 'cspv_flush_view_queue' );
} );

// Register a 1-minute WP-Cron interval for the view-queue flush.
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['cspv_every_minute'] ) ) {
        $schedules['cspv_every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute (CloudScale Analytics queue flush)', 'cloudscale-site-analytics' ),
        );
    }
    return $schedules;
} );

// Ensure crons are always scheduled while the plugin is active.
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'cspv_dbip_auto_update' ) ) {
        wp_schedule_event( time(), 'daily', 'cspv_dbip_auto_update' );
    }
    if ( ! wp_next_scheduled( 'cspv_flush_view_queue' ) ) {
        wp_schedule_event( time(), 'cspv_every_minute', 'cspv_flush_view_queue' );
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
        cspv_create_table_audio_v2();
        update_option( 'cspv_version', CSPV_VERSION );
    }
} );
