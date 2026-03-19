<?php
/**
 * CloudScale Analytics - IP Throttling + Fail2Ban  v3.1.0
 *
 * Two tier IP protection:
 *
 * Tier 1 — Throttle (soft block)
 *   After N requests (default 50) within the rolling window the IP is
 *   silently blocked for 1 hour. Counter and block are transient based
 *   and auto expire.
 *
 * Tier 2 — Fail2Ban (hard block)
 *   After M distinct pages (default 1000, configurable) within the
 *   rolling window the IP is added to the Fail2Ban list. FTB blocks
 *   last 2 hours and auto clear via transients. The admin UI also
 *   stores a persistent list for display that prunes expired entries.
 *
 * @package CloudScale_WordPress_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSPV_BLOCK_DURATION',     3600 ); // tier 1 throttle: 1 hour
// Default FTB block duration (overridden by cspv_ftb_block_duration option)
define( 'CSPV_FTB_BLOCK_DURATION_DEFAULT', 7200 );

// =========================================================================
// 1. CONFIG HELPERS
// =========================================================================

// -- Throttle (tier 1) ----------------------------------------------------

/**
 * Check whether tier-1 IP throttling is enabled.
 *
 * @since 1.0.0
 * @return bool True by default.
 */
function cspv_throttle_enabled() {
    $val = get_option( 'cspv_throttle_enabled', null );
    return $val === null ? true : (bool) $val;
}

/**
 * Return the tier-1 throttle request limit per window.
 *
 * @since 1.0.0
 * @return int Request count threshold (minimum 1).
 */
function cspv_throttle_limit() {
    return max( 1, (int) get_option( 'cspv_throttle_limit', 50 ) );
}

/**
 * Return the tier-1 throttle rolling-window length in seconds.
 *
 * @since 1.0.0
 * @return int Window length in seconds.
 */
function cspv_throttle_window_seconds() {
    return (int) get_option( 'cspv_throttle_window', 3600 );
}

// -- Fail2Ban (tier 2) ----------------------------------------------------

/**
 * Check whether tier-2 Fail2Ban blocking is enabled.
 *
 * @since 1.0.0
 * @return bool True by default.
 */
function cspv_ftb_enabled() {
    $val = get_option( 'cspv_ftb_enabled', null );
    return $val === null ? true : (bool) $val;
}

/**
 * Return the tier-2 distinct-pages-per-window limit that triggers a FTB block.
 *
 * @since 1.0.0
 * @return int Page count threshold (minimum 1).
 */
function cspv_ftb_page_limit() {
    return max( 1, (int) get_option( 'cspv_ftb_page_limit', 1000 ) );
}

/**
 * Return the tier-2 Fail2Ban rolling-window length in seconds.
 *
 * @since 1.0.0
 * @return int Window length in seconds.
 */
function cspv_ftb_window_seconds() {
    return (int) get_option( 'cspv_ftb_window', 3600 );
}

/**
 * Return the tier-2 block duration in seconds.
 *
 * @since 2.9.8
 * @return int Block duration in seconds.
 */
function cspv_ftb_block_duration() {
    return (int) get_option( 'cspv_ftb_block_duration', CSPV_FTB_BLOCK_DURATION_DEFAULT );
}

/**
 * Return a human-readable label for a block duration in seconds.
 *
 * @since 2.9.8
 * @param int $seconds Duration in seconds.
 * @return string Human-readable label (e.g. "2 hours").
 */
function cspv_ftb_duration_label( $seconds ) {
    $labels = array(
        1800  => '30 minutes',
        3600  => '1 hour',
        7200  => '2 hours',
        14400 => '4 hours',
        28800 => '8 hours',
        43200 => '12 hours',
        86400 => '24 hours',
    );
    return isset( $labels[ $seconds ] ) ? $labels[ $seconds ] : round( $seconds / 3600, 1 ) . ' hours';
}

/**
 * Return the current FTB rules as a human readable array for admin display.
 */
function cspv_ftb_get_rules() {
    $enabled    = cspv_ftb_enabled();
    $page_limit = cspv_ftb_page_limit();
    $window     = cspv_ftb_window_seconds();

    $window_labels = array(
        600   => '10 minutes',
        1800  => '30 minutes',
        3600  => '1 hour',
        7200  => '2 hours',
        86400 => '24 hours',
    );
    $window_label = isset( $window_labels[ $window ] ) ? $window_labels[ $window ] : $window . 's';

    $block_dur    = cspv_ftb_block_duration();
    $block_label  = cspv_ftb_duration_label( $block_dur );

    return array(
        'enabled'      => $enabled,
        'page_limit'   => $page_limit,
        'window'       => $window,
        'window_label' => $window_label,
        'block_dur'    => $block_dur,
        'block_label'  => $block_label,
        'summary'      => $enabled
            ? 'Block IP for ' . $block_label . ' after ' . number_format( $page_limit ) . ' pages within ' . $window_label
            : 'Fail2Ban is disabled',
    );
}

// =========================================================================
// 2. CORE THROTTLE + FTB CHECK
// =========================================================================

/**
 * Check whether a hashed IP should be blocked from recording a view.
 *
 * Evaluates both tier-1 throttle and tier-2 Fail2Ban rules and
 * increments the appropriate counters as a side-effect.
 *
 * @since 1.0.0
 * @param string $ip_hash SHA256 hash of the visitor IP.
 * @return bool True if the request should be blocked.
 */
function cspv_is_throttled( $ip_hash ) {
    if ( empty( $ip_hash ) ) {
        return false;
    }

    // Never throttle logged-in admins
    if ( current_user_can( 'manage_options' ) ) {
        return false;
    }

    // -- FTB hard block check (2 hr transient) --
    if ( cspv_ftb_enabled() && cspv_ftb_is_blocked( $ip_hash ) ) {
        return true;
    }

    // -- Throttle transient block check --
    if ( ! cspv_throttle_enabled() ) {
        return cspv_ftb_track_page( $ip_hash );
    }

    if ( false !== get_transient( 'cspv_block_' . substr( $ip_hash, 0, 32 ) ) ) {
        cspv_ftb_track_page( $ip_hash );
        return true;
    }

    $limit  = cspv_throttle_limit();
    $window = cspv_throttle_window_seconds();
    $key    = 'cspv_ip_' . substr( $ip_hash, 0, 32 );

    $count = (int) get_transient( $key );
    $count++;
    set_transient( $key, $count, $window );

    if ( $count >= $limit ) {
        cspv_block_ip( $ip_hash );
        cspv_ftb_track_page( $ip_hash );
        return true;
    }

    // Track distinct pages for FTB even when under throttle limit
    if ( cspv_ftb_track_page( $ip_hash ) ) {
        return true;
    }

    return false;
}

// =========================================================================
// 3. TIER 1 — THROTTLE BLOCK / UNBLOCK (1 hr auto-expire)
// =========================================================================

/**
 * Add a hashed IP to the tier-1 throttle blocklist for one hour.
 *
 * @since 1.0.0
 * @param string $ip_hash SHA256 hash of the visitor IP.
 * @return void
 */
function cspv_block_ip( $ip_hash ) {
    $block_key = 'cspv_block_' . substr( $ip_hash, 0, 32 );

    if ( false === get_transient( $block_key ) ) {
        set_transient( $block_key, 1, CSPV_BLOCK_DURATION );

        $list = cspv_get_blocklist();
        $expires = time() + CSPV_BLOCK_DURATION;
        $list[ $ip_hash ] = array(
            'blocked_at' => current_time( 'mysql' ),
            'expires'    => $expires,
        );
        update_option( 'cspv_ip_blocklist', $list, false );
        cspv_log_block_event( $ip_hash );
    }
}

/**
 * Remove a hashed IP from the tier-1 throttle blocklist.
 *
 * @since 1.0.0
 * @param string $ip_hash SHA256 hash of the visitor IP.
 * @return void
 */
function cspv_unblock_ip( $ip_hash ) {
    delete_transient( 'cspv_block_' . substr( $ip_hash, 0, 32 ) );
    delete_transient( 'cspv_ip_'    . substr( $ip_hash, 0, 32 ) );

    $list = cspv_get_blocklist();
    unset( $list[ $ip_hash ] );
    update_option( 'cspv_ip_blocklist', $list, false );
}

/**
 * Clear all tier-1 throttle blocks and reset the event log.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_clear_blocklist() {
    $list = cspv_get_blocklist();
    foreach ( array_keys( $list ) as $ip_hash ) {
        delete_transient( 'cspv_block_' . substr( $ip_hash, 0, 32 ) );
        delete_transient( 'cspv_ip_'    . substr( $ip_hash, 0, 32 ) );
    }
    update_option( 'cspv_ip_blocklist', array(), false );
    update_option( 'cspv_block_log',    array(), false );
}

/**
 * Check whether a hashed IP is currently in the tier-1 throttle block transient.
 *
 * @since 1.0.0
 * @param string $ip_hash SHA256 hash of the visitor IP.
 * @return bool True if actively blocked.
 */
function cspv_ip_is_blocked( $ip_hash ) {
    return false !== get_transient( 'cspv_block_' . substr( $ip_hash, 0, 32 ) );
}

// =========================================================================
// 4. TIER 1 — BLOCKLIST (auto prune expired)
// =========================================================================

/**
 * Return the tier-1 blocklist, pruning expired entries on read.
 *
 * @since 1.0.0
 * @return array Blocklist keyed by IP hash with blocked_at and expires values.
 */
function cspv_get_blocklist() {
    $raw = get_option( 'cspv_ip_blocklist', array() );

    if ( isset( $raw[0] ) && is_string( $raw[0] ) ) {
        $converted = array();
        foreach ( $raw as $hash ) {
            $converted[ $hash ] = array( 'blocked_at' => '—', 'expires' => 0 ); // em dash display placeholder for migrated legacy entries
        }
        $raw = $converted;
    }

    $now    = time();
    $pruned = false;
    foreach ( $raw as $hash => $data ) {
        $expires = isset( $data['expires'] ) ? (int) $data['expires'] : 0;
        if ( $expires > 0 && $expires < $now ) {
            unset( $raw[ $hash ] );
            $pruned = true;
        }
    }
    if ( $pruned ) {
        update_option( 'cspv_ip_blocklist', $raw, false );
    }

    return is_array( $raw ) ? $raw : array();
}

// =========================================================================
// 5. TIER 1 — BLOCK EVENT LOG
// =========================================================================

/**
 * Append a tier-1 block event to the persistent event log (capped at 100 entries).
 *
 * @since 1.0.0
 * @param string $ip_hash SHA256 hash of the blocked IP.
 * @return void
 */
function cspv_log_block_event( $ip_hash ) {
    $log = (array) get_option( 'cspv_block_log', array() );
    array_unshift( $log, array(
        'ip_hash'    => $ip_hash,
        'blocked_at' => current_time( 'mysql' ),
        'expires_at' => wp_date( 'Y-m-d H:i:s', time() + CSPV_BLOCK_DURATION ),
    ) );
    update_option( 'cspv_block_log', array_slice( $log, 0, 100 ), false );
}

/**
 * Return the tier-1 block event log.
 *
 * @since 1.0.0
 * @return array Array of block events with ip_hash, blocked_at, expires_at.
 */
function cspv_get_block_log() {
    return (array) get_option( 'cspv_block_log', array() );
}

// =========================================================================
// 6. TIER 2 — FAIL2BAN (2 hr auto-clear via transients)
// =========================================================================

/**
 * Track page views for an IP. Returns true if the IP should be blocked.
 */
function cspv_ftb_track_page( $ip_hash ) {
    if ( ! cspv_ftb_enabled() || empty( $ip_hash ) ) {
        return false;
    }

    if ( cspv_ftb_is_blocked( $ip_hash ) ) {
        return true;
    }

    $limit  = cspv_ftb_page_limit();
    $window = cspv_ftb_window_seconds();
    $key    = 'cspv_ftb_' . substr( $ip_hash, 0, 32 );

    $count = (int) get_transient( $key );
    $count++;
    set_transient( $key, $count, $window );

    if ( $count >= $limit ) {
        cspv_ftb_block_ip( $ip_hash );
        return true;
    }

    return false;
}

/**
 * Add an IP to the FTB blocklist. Block duration is configurable via admin UI.
 */
function cspv_ftb_block_ip( $ip_hash ) {
    $block_key = 'cspv_ftb_block_' . substr( $ip_hash, 0, 32 );

    if ( false !== get_transient( $block_key ) ) {
        return; // already blocked
    }

    $duration = cspv_ftb_block_duration();

    // Transient is the authoritative block — auto clears after configured duration
    set_transient( $block_key, 1, $duration );

    // Persistent list for admin display (pruned on read)
    $list = cspv_ftb_get_blocklist();
    $list[ $ip_hash ] = array(
        'blocked_at' => current_time( 'mysql' ),
        'expires'    => time() + $duration,
        'reason'     => 'Exceeded ' . number_format( cspv_ftb_page_limit() ) . ' page limit',
    );
    update_option( 'cspv_ftb_blocklist', $list, false );

    cspv_ftb_log_event( $ip_hash, 'blocked' );
}

/**
 * Check if an IP is on the FTB blocklist (transient is authoritative).
 */
function cspv_ftb_is_blocked( $ip_hash ) {
    return false !== get_transient( 'cspv_ftb_block_' . substr( $ip_hash, 0, 32 ) );
}

/**
 * Remove a single IP from the FTB blocklist.
 */
function cspv_ftb_unblock_ip( $ip_hash ) {
    delete_transient( 'cspv_ftb_block_' . substr( $ip_hash, 0, 32 ) );
    delete_transient( 'cspv_ftb_'       . substr( $ip_hash, 0, 32 ) );

    $list = cspv_ftb_get_blocklist();
    unset( $list[ $ip_hash ] );
    update_option( 'cspv_ftb_blocklist', $list, false );

    cspv_ftb_log_event( $ip_hash, 'unblocked' );
}

/**
 * Clear all FTB blocks.
 */
function cspv_ftb_clear_blocklist() {
    $list = cspv_ftb_get_blocklist();
    foreach ( array_keys( $list ) as $ip_hash ) {
        delete_transient( 'cspv_ftb_block_' . substr( $ip_hash, 0, 32 ) );
        delete_transient( 'cspv_ftb_'       . substr( $ip_hash, 0, 32 ) );
    }
    update_option( 'cspv_ftb_blocklist', array(), false );
    update_option( 'cspv_ftb_log',       array(), false );
}

/**
 * Get the FTB blocklist. Prunes expired entries automatically.
 */
function cspv_ftb_get_blocklist() {
    $raw = get_option( 'cspv_ftb_blocklist', array() );
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $now    = time();
    $pruned = false;
    foreach ( $raw as $hash => $data ) {
        $expires = isset( $data['expires'] ) ? (int) $data['expires'] : 0;
        if ( $expires > 0 && $expires < $now ) {
            unset( $raw[ $hash ] );
            $pruned = true;
        }
    }
    if ( $pruned ) {
        update_option( 'cspv_ftb_blocklist', $raw, false );
    }

    return $raw;
}

/**
 * FTB event log.
 */
function cspv_ftb_log_event( $ip_hash, $action ) {
    $log = (array) get_option( 'cspv_ftb_log', array() );
    array_unshift( $log, array(
        'ip_hash' => $ip_hash,
        'action'  => $action,
        'time'    => current_time( 'mysql' ),
    ) );
    update_option( 'cspv_ftb_log', array_slice( $log, 0, 100 ), false );
}

function cspv_ftb_get_log() {
    return (array) get_option( 'cspv_ftb_log', array() );
}

// =========================================================================
// 7. CLEAR ALL IP ADDRESSES (throttle + FTB + counters)
// =========================================================================

/**
 * Clear all tier-1 throttle and tier-2 FTB data in one operation.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_clear_all_ip_data() {
    cspv_clear_blocklist();
    cspv_ftb_clear_blocklist();
}

// =========================================================================
// 8. AJAX HANDLERS
// =========================================================================

add_action( 'wp_ajax_cspv_save_throttle_settings', 'cspv_ajax_save_throttle_settings' );
add_action( 'wp_ajax_cspv_unblock_ip',             'cspv_ajax_unblock_ip' );
add_action( 'wp_ajax_cspv_clear_blocklist',         'cspv_ajax_clear_blocklist' );
add_action( 'wp_ajax_cspv_save_ftb_settings',       'cspv_ajax_save_ftb_settings' );
add_action( 'wp_ajax_cspv_ftb_unblock_ip',          'cspv_ajax_ftb_unblock_ip' );
add_action( 'wp_ajax_cspv_ftb_clear_blocklist',     'cspv_ajax_ftb_clear_blocklist' );
add_action( 'wp_ajax_cspv_clear_all_ip_data',       'cspv_ajax_clear_all_ip_data' );

/**
 * AJAX handler: save tier-1 throttle settings.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_save_throttle_settings() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $enabled = ! empty( $_POST['enabled'] );
    $limit   = isset( $_POST['limit'] ) ? max( 1, min( 10000, (int) wp_unslash( $_POST['limit'] ) ) ) : 50;
    $raw_win = isset( $_POST['window'] ) ? (int) wp_unslash( $_POST['window'] ) : 3600;
    $window  = in_array( $raw_win, array( 600, 1800, 3600, 7200, 86400 ), true ) ? $raw_win : 3600;

    update_option( 'cspv_throttle_enabled', $enabled, false );
    update_option( 'cspv_throttle_limit',   $limit,   false );
    update_option( 'cspv_throttle_window',  $window,  false );

    wp_send_json_success( array( 'enabled' => $enabled, 'limit' => $limit, 'window' => $window ) );
}

/**
 * AJAX handler: unblock a single tier-1 throttled IP.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_unblock_ip() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $ip_hash = isset( $_POST['ip_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['ip_hash'] ) ) : '';
    if ( empty( $ip_hash ) || ! preg_match( '/^[a-f0-9]{64}$/i', $ip_hash ) ) {
        wp_send_json_error( array( 'message' => 'Invalid IP hash.' ), 400 );
        return;
    }

    cspv_unblock_ip( $ip_hash );
    wp_send_json_success();
}

/**
 * AJAX handler: clear the entire tier-1 throttle blocklist.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_clear_blocklist() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    cspv_clear_blocklist();
    wp_send_json_success();
}

/**
 * AJAX handler: save tier-2 Fail2Ban settings.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_save_ftb_settings() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $enabled    = ! empty( $_POST['enabled'] );
    $page_limit = isset( $_POST['page_limit'] ) ? max( 1, min( 100000, (int) wp_unslash( $_POST['page_limit'] ) ) ) : 1000;
    $raw_win    = isset( $_POST['window'] ) ? (int) wp_unslash( $_POST['window'] ) : 3600;
    $window     = in_array( $raw_win, array( 600, 1800, 3600, 7200, 86400 ), true ) ? $raw_win : 3600;
    $raw_dur    = isset( $_POST['block_duration'] ) ? (int) wp_unslash( $_POST['block_duration'] ) : CSPV_FTB_BLOCK_DURATION_DEFAULT;
    $block_dur  = in_array( $raw_dur, array( 1800, 3600, 7200, 14400, 28800, 43200, 86400 ), true ) ? $raw_dur : CSPV_FTB_BLOCK_DURATION_DEFAULT;

    update_option( 'cspv_ftb_enabled',        $enabled,    false );
    update_option( 'cspv_ftb_page_limit',     $page_limit, false );
    update_option( 'cspv_ftb_window',         $window,     false );
    update_option( 'cspv_ftb_block_duration', $block_dur,  false );

    wp_send_json_success( array(
        'enabled'        => $enabled,
        'page_limit'     => $page_limit,
        'window'         => $window,
        'block_duration' => $block_dur,
        'rules'          => cspv_ftb_get_rules(),
    ) );
}

/**
 * AJAX handler: unblock a single tier-2 FTB-blocked IP.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_ftb_unblock_ip() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $ip_hash = isset( $_POST['ip_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['ip_hash'] ) ) : '';
    if ( empty( $ip_hash ) || ! preg_match( '/^[a-f0-9]{64}$/i', $ip_hash ) ) {
        wp_send_json_error( array( 'message' => 'Invalid IP hash.' ), 400 );
        return;
    }

    cspv_ftb_unblock_ip( $ip_hash );
    wp_send_json_success();
}

/**
 * AJAX handler: clear the entire tier-2 FTB blocklist.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_ftb_clear_blocklist() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    cspv_ftb_clear_blocklist();
    wp_send_json_success();
}

/**
 * AJAX handler: clear all tier-1 throttle and tier-2 FTB data.
 *
 * @since 1.0.0
 * @return void Sends JSON response.
 */
function cspv_ajax_clear_all_ip_data() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    cspv_clear_all_ip_data();
    wp_send_json_success();
}

// =========================================================================
// 9. TRACKING PAUSE (emergency kill switch)
// =========================================================================

/**
 * Check whether the emergency tracking kill switch is active.
 *
 * @since 2.9.0
 * @return bool True if all tracking is paused.
 */
function cspv_tracking_paused() {
    return (bool) get_option( 'cspv_tracking_paused', false );
}

add_action( 'wp_ajax_cspv_set_tracking_pause', 'cspv_ajax_set_tracking_pause' );

/**
 * AJAX handler: toggle the tracking pause kill switch.
 *
 * @since 2.9.0
 * @return void Sends JSON response.
 */
function cspv_ajax_set_tracking_pause() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $paused = ! empty( $_POST['paused'] );
    update_option( 'cspv_tracking_paused', $paused, false );

    wp_send_json_success( array( 'paused' => $paused ) );
}

// =========================================================================
// 9b. VIEW DEDUPLICATION SETTINGS
// =========================================================================

add_action( 'wp_ajax_cspv_save_dedup_settings', 'cspv_ajax_save_dedup_settings' );

/**
 * AJAX handler: save session-based view deduplication settings.
 *
 * @since 2.9.4
 * @return void Sends JSON response.
 */
function cspv_ajax_save_dedup_settings() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $enabled = ! empty( $_POST['enabled'] );
    $raw_win = isset( $_POST['window'] ) ? (int) wp_unslash( $_POST['window'] ) : 86400;
    $allowed = array( 3600, 7200, 21600, 43200, 86400, 172800 );
    $window  = in_array( $raw_win, $allowed, true ) ? $raw_win : 86400;

    // Use 'yes'/'no' strings — WordPress handles these unambiguously
    // unlike booleans or '0'/'1' which can be lost by update_option
    $result_e = update_option( 'cspv_dedup_enabled', $enabled ? 'yes' : 'no' );
    $result_w = update_option( 'cspv_dedup_window', $window );

    wp_send_json_success( array(
        'enabled'   => $enabled,
        'window'    => $window,
        'saved_e'   => $result_e,
        'saved_w'   => $result_w,
        'stored'    => get_option( 'cspv_dedup_enabled' ),
    ) );
}

// =========================================================================
// 10. TEST FAIL2BAN (verifies transient storage works)
// =========================================================================

add_action( 'wp_ajax_cspv_test_ftb', 'cspv_ajax_test_ftb' );

/**
 * AJAX handler: run a self-test to verify transient storage works for FTB.
 *
 * @since 1.0.0
 * @return void Sends JSON response with test results.
 */
function cspv_ajax_test_ftb() {
    if ( ! check_ajax_referer( 'cspv_throttle', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $results = array();

    // Test 1: Can we write a transient?
    $test_key = 'cspv_ftb_selftest_' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $write_ok = set_transient( $test_key, 'ftb_test_value', 60 );
    $results[] = array(
        'test'   => 'Write transient',
        'pass'   => (bool) $write_ok,
        'detail' => $write_ok ? 'Successfully wrote test transient' : 'Failed to write transient — check your object cache or database',
    );

    // Test 2: Can we read it back?
    $read_val = get_transient( $test_key );
    $read_ok  = ( $read_val === 'ftb_test_value' );
    $results[] = array(
        'test'   => 'Read transient',
        'pass'   => $read_ok,
        'detail' => $read_ok ? 'Successfully read test transient' : 'Transient read returned unexpected value — object cache may be misconfigured',
    );

    // Cleanup
    delete_transient( $test_key );

    // Test 3: Can we write to options (FTB blocklist storage)?
    $opt_key  = 'cspv_ftb_selftest_opt';
    $opt_ok   = update_option( $opt_key, array( 'test' => true ), false );
    $opt_read = get_option( $opt_key, null );
    $opt_pass = is_array( $opt_read ) && ! empty( $opt_read['test'] );
    delete_option( $opt_key );
    $results[] = array(
        'test'   => 'Write options (blocklist storage)',
        'pass'   => $opt_pass,
        'detail' => $opt_pass ? 'Options table read/write working' : 'Failed to write to options table',
    );

    // Test 4: Is FTB enabled?
    $ftb_on = cspv_ftb_enabled();
    $results[] = array(
        'test'   => 'FTB enabled',
        'pass'   => $ftb_on,
        'detail' => $ftb_on ? 'Fail2Ban is enabled and will block IPs exceeding ' . number_format( cspv_ftb_page_limit() ) . ' pages' : 'Fail2Ban is currently disabled — enable it above to activate protection',
    );

    // Test 5: FTB block duration
    $results[] = array(
        'test'   => 'Block duration',
        'pass'   => true,
        'detail' => 'FTB blocks last ' . cspv_ftb_duration_label( cspv_ftb_block_duration() ) . ' and auto clear via transient expiry',
    );

    $all_pass = true;
    foreach ( $results as $r ) {
        if ( ! $r['pass'] ) { $all_pass = false; break; }
    }

    wp_send_json_success( array(
        'results'  => $results,
        'all_pass' => $all_pass,
        'summary'  => $all_pass ? 'All tests passed — Fail2Ban is fully operational' : 'Some tests failed — review the results above',
    ) );
}
