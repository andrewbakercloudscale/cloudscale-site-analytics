<?php
/**
 * CloudScale Analytics - Beacon Loader  v2.0.0
 *
 * Two modes:
 *
 * 1. SINGULAR (post/page): fires the record beacon to increment the counter,
 *    then updates .cspv-live-count elements with the new total.
 *
 * 2. ARCHIVE / HOME / SEARCH: does NOT increment anything.
 *    Instead it collects all post IDs that have a [data-cspv-id] attribute
 *    in the DOM, fetches their counts from the public /counts endpoint in a
 *    single request, and injects the numbers into the matching elements.
 *    This means view counts on listing pages are always fresh even when
 *    Cloudflare has cached the HTML.
 *
 * @package CloudScale_Site_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', 'cspv_enqueue_beacon' );

/**
 * Enqueue the beacon script and pass localised data to it.
 *
 * On singular tracked post types the beacon fires in record mode: it POSTs
 * to the record endpoint and updates the live count element. On archive,
 * home, front page, and search pages it runs in fetch mode: it collects all
 * [data-cspv-id] post IDs from the DOM and fetches their counts in one
 * request so cached listing pages always show fresh numbers.
 *
 * @since  1.0.0
 * @return void
 */
function cspv_enqueue_beacon() {
    // Emergency kill switch, stop all tracking
    if ( function_exists( 'cspv_tracking_paused' ) && cspv_tracking_paused() ) {
        return;
    }

    $is_singular = is_singular();
    $is_listing  = is_home() || is_front_page() || is_archive() || is_search();

    // A PREVIEW, OR ANY SINGULAR VIEW OF A POST THAT IS NOT PUBLISHED, CANNOT BE
    // RECORDED. cspv_record_view() answers 404 {"error":"Post is not published."}
    // on purpose, so shipping record mode here guarantees a POST that fails --
    // from the author's own browser, on the one page where the author already
    // knows the post is a draft.
    //
    // That failure is not silent. CS Monitor's editorLogSeverity() ends in
    // `return 'critical'` for any same-origin fail with a real status, so the
    // 404 became a Telegram CRITICAL: "Editor request failed, POST
    // /wp-json/cloudscale-site-analytics/v1/record/11057 -> 404". Post 11057 was
    // published 16 seconds later (alert 2026-08-19 09:11:18, post_modified
    // 09:11:34) -- the alert fired because the workflow was working. That is how
    // CRITICAL stops meaning anything, which is the same reasoning behind the
    // by_design/403 branch in cs-perf-monitor.js.
    //
    // Fixed here rather than in the monitor because the monitor is right: a 404
    // on a route that should have answered IS critical, and teaching it to
    // forgive 404s generally would hide real breakage. The request itself is
    // what should not exist.
    //
    // is_preview() also covers a preview of an already-published post, where the
    // record would succeed -- an author re-reading their own draft revision is
    // not a view, and counting it inflates the number the plugin exists to report.
    // 'private' and future-dated posts fall out of the same guard: both are
    // singular, both 404 at the endpoint, both used to alert.
    if ( $is_singular && ( is_preview() || 'publish' !== get_post_status() ) ) {
        $is_singular = false;
    }

    if ( ! $is_singular && ! $is_listing ) {
        return;
    }

    // Check post type filter for recording (not listing/fetch mode)
    if ( $is_singular ) {
        $track_types = get_option( 'cspv_track_post_types', array( 'post', 'page' ) );
        if ( ! empty( $track_types ) && ! in_array( get_post_type(), $track_types, true ) ) {
            // Post type not tracked, still allow fetch mode for listings
            if ( ! $is_listing ) {
                return;
            }
            $is_singular = false; // Downgrade to fetch mode
        }
    }

    wp_enqueue_script(
        'cloudscale-site-analytics-beacon',
        CSPV_PLUGIN_URL . 'beacon.js',
        array(),
        CSPV_VERSION,
        true
    );

    // Some optimisation plugins strip ?ver= from scripts.
    // Re-add the version as a cache buster that survives stripping.
    add_filter( 'script_loader_src', function( $src, $handle ) {
        if ( $handle === 'cloudscale-site-analytics-beacon' && strpos( $src, 'ver=' ) === false ) {
            $src = add_query_arg( 'cspv', CSPV_VERSION, $src );
        }
        return $src;
    }, 99, 2 );

    $data = array(
        'mode'          => $is_singular ? 'record' : 'fetch',
        'countsUrl'     => rest_url( 'cloudscale-site-analytics/v1/counts' ),
        'nonce'         => wp_create_nonce( 'wp_rest' ),
        'dedupOn'       => get_option( 'cspv_dedup_enabled', 'yes' ) !== 'no',
        // Base URL for the narration engagement beacon (play + complete events);
        // the post id is appended per <audio> element (works on single posts and
        // multi-result listings).
        'audioEventBase' => rest_url( 'cloudscale-site-analytics/v1/audio/' ),
    );

    if ( $is_singular ) {
        $post_id         = get_the_ID();
        $data['apiUrl']  = rest_url( 'cloudscale-site-analytics/v1/record/' . $post_id );
        $data['postId']  = $post_id;
    }

    wp_localize_script( 'cloudscale-site-analytics-beacon', 'cspvData', $data );
}
