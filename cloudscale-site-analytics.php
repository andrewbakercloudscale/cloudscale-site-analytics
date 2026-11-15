<?php
/**
 * Plugin Name:  CloudScale Site Analytics
 * Description:  Accurate page view tracking via a JavaScript beacon that bypasses Cloudflare cache. Includes auto display on posts, Top Posts and Recent Posts sidebar widgets, and a live statistics dashboard under Tools.
 * Version:      2.9.524
 * Author:       CloudScale
 * Author URI:   https://cloudscale.consulting
 * Contributors: cloudscale
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  cloudscale-site-analytics
 * Requires PHP: 7.4
 * Requires at least: 6.2
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSPV_VERSION',    '2.9.524' );
define( 'CSPV_META_KEY',   '_cspv_view_count' );
// Denormalised narration play counter, the audio equivalent of CSPV_META_KEY.
// The hourly buckets in cspv_analytics_audio_v2 stay the record of truth; this is
// the one number a public template can print without a GROUP BY per player on a
// page that may carry a dozen of them. Backfilled from that table on first read.
define( 'CSPV_AUDIO_META_KEY', '_cspv_audio_play_count' );
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

// WordPress's emoji script swaps emoji characters for images from the WordPress.org emoji
// CDN when it (sometimes wrongly) decides a browser lacks native emoji support; if that CDN
// is unreachable the images break. This is an opt-in setting (Display tab, "Turn off the
// WordPress emoji script"), off by default, so core's behaviour is unchanged unless the site
// owner asks for it.
add_action( 'init', function () {
    if ( 'yes' !== get_option( 'cspv_disable_emoji_polyfill', 'no' ) ) {
        return;
    }
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'embed_head', 'print_emoji_detection_script' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
    } );
    // Entries arrive in more than one shape: a full URL, a scheme-relative one, or for
    // preconnect an attribute array keyed 'href'. Anything unrecognised is KEPT, the job
    // here is to remove one known hint, so in doubt it must not remove.
    add_filter( 'wp_resource_hints', function ( $urls ) {
        if ( ! is_array( $urls ) ) {
            return $urls;
        }
        // The emoji hint is recognised by its PATH, which is core's and stable, rather than by
        // naming the host it points at. Core adds it as the full emoji_svg_url value, so the
        // path is present when this filter runs. An entry with no path (a bare host some other
        // plugin added) is never matched, which is the safe direction: in doubt, keep.
        return array_values(
            array_filter(
                $urls,
                static function ( $u ) {
                    if ( is_array( $u ) ) {
                        $u = isset( $u['href'] ) ? $u['href'] : '';
                    }
                    if ( ! is_string( $u ) || '' === $u ) {
                        return true;
                    }
                    return false === strpos( (string) wp_parse_url( $u, PHP_URL_PATH ), '/images/core/emoji/' );
                }
            )
        );
    } );
}, 1 );

// wp-admin/includes/admin-filters.php re-registers the admin_print_scripts/
// admin_print_styles emoji hooks, and that file loads after `init` has already
// fired, so the removal above never catches them on admin pages. Remove again
// on admin_init, which runs after admin-filters.php has loaded.
add_action( 'admin_init', function () {
    if ( 'yes' !== get_option( 'cspv_disable_emoji_polyfill', 'no' ) ) {
        return;
    }
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
}, 1 );

// Tables created before 2.9.516 carried no plugin prefix. Moved before anything can write.
add_action( 'init', 'cspv_migrate_table_names', 0 );
add_action( 'admin_notices', 'cspv_sibling_table_name_notice' );

register_activation_hook( __FILE__, 'cspv_activate' );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'cspv_dbip_auto_update' );
    wp_clear_scheduled_hook( 'cspv_flush_view_queue' );
    wp_clear_scheduled_hook( 'cspv_daily_prune' );
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
    if ( ! wp_next_scheduled( 'cspv_daily_prune' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cspv_daily_prune' );
    }
} );

/**
 * Daily: bound the tables that anonymous requests write to.
 *
 * The beacon and the 404 logger take rows from logged-out visitors, and nothing removed
 * any, so a script sending unique referrers, sessions or missing URLs could grow them
 * without limit. Kept: referrers and sessions for 400 days (the longest range a report
 * offers is one year); 404s last seen in the past 90 days, at most 5,000 rows. Not
 * pruned: hourly view totals, geo and audio (the charts' history) and the visitors table,
 * which the All Time Unique Visitors figure counts.
 *
 * Deletes in batches so one run never holds a long lock on a large table.
 *
 * @since 2.9.524
 * @return void
 */
function cspv_daily_prune(): void {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded maintenance deletes on the plugin's own tables
    $day_cut  = gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS );
    $hour_cut = $day_cut . ' 00:00:00';
    $jobs     = array(
        array( $wpdb->prefix . 'cspv_analytics_referrers_v2', 'viewed_at', $hour_cut ),
        array( $wpdb->prefix . 'cspv_analytics_sessions_v2', 'viewed_at', $day_cut ),
        array( $wpdb->prefix . 'cspv_analytics_404_v2', 'last_seen', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ),
    );
    foreach ( $jobs as $job ) {
        list( $table, $col, $cut ) = $job;
        if ( ! function_exists( 'cspv_table_exists' ) || ! cspv_table_exists( $table ) ) {
            continue;
        }
        for ( $i = 0; $i < 50; $i++ ) {
            $n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i < %s LIMIT 5000', $table, $col, $cut ) );
            if ( $n < 5000 ) {
                break;
            }
        }
    }
    // 404s: the newest 5,000 only.
    $t404 = $wpdb->prefix . 'cspv_analytics_404_v2';
    if ( function_exists( 'cspv_table_exists' ) && cspv_table_exists( $t404 ) ) {
        $edge = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM %i ORDER BY last_seen DESC LIMIT 1 OFFSET 4999', $t404 ) );
        if ( null !== $edge ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $t404, $edge ) );
        }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
add_action( 'cspv_daily_prune', 'cspv_daily_prune' );

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
