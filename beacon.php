<?php
/**
 * CloudScale Page Views - Beacon Loader  v2.0.0
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
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', 'cspv_enqueue_beacon' );

function cspv_enqueue_beacon() {
    // Emergency kill switch — stop all tracking
    if ( function_exists( 'cspv_tracking_paused' ) && cspv_tracking_paused() ) {
        return;
    }

    $is_singular = is_singular();
    $is_listing  = is_home() || is_front_page() || is_archive() || is_search();

    if ( ! $is_singular && ! $is_listing ) {
        return;
    }

    // Check post type filter for recording (not listing/fetch mode)
    if ( $is_singular ) {
        $track_types = get_option( 'cspv_track_post_types', array( 'post' ) );
        if ( ! empty( $track_types ) && ! in_array( get_post_type(), $track_types, true ) ) {
            // Post type not tracked — still allow fetch mode for listings
            if ( ! $is_listing ) {
                return;
            }
            $is_singular = false; // Downgrade to fetch mode
        }
    }

    wp_enqueue_script(
        'cloudscale-page-views-beacon',
        CSPV_PLUGIN_URL . 'beacon.js',
        array(),
        CSPV_VERSION,
        true
    );

    // Some optimisation plugins strip ?ver= from scripts.
    // Re-add the version as a cache buster that survives stripping.
    add_filter( 'script_loader_src', function( $src, $handle ) {
        if ( $handle === 'cloudscale-page-views-beacon' && strpos( $src, 'ver=' ) === false ) {
            $src = add_query_arg( 'cspv', CSPV_VERSION, $src );
        }
        return $src;
    }, 99, 2 );

    $data = array(
        'mode'       => $is_singular ? 'record' : 'fetch',
        'countsUrl'  => rest_url( 'cloudscale-page-views/v1/counts' ),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
        'debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'dedupOn'    => get_option( 'cspv_dedup_enabled', 'yes' ) !== 'no',
    );

    if ( $is_singular ) {
        $post_id         = get_the_ID();
        $data['apiUrl']  = rest_url( 'cloudscale-page-views/v1/record/' . $post_id );
        $data['postId']  = $post_id;
    }

    wp_localize_script( 'cloudscale-page-views-beacon', 'cspvData', $data );
}
