<?php
/**
 * CloudScale Page Views - Database
 *
 * Creates and upgrades the wp_cspv_views log table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cspv_activate() {
    cspv_create_table();
    cspv_upgrade_table();
    add_option( 'cspv_version', CSPV_VERSION );
}

function cspv_create_table() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_views';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT(20) UNSIGNED NOT NULL,
        user_agent  VARCHAR(255)        NOT NULL DEFAULT '',
        ip_hash     VARCHAR(64)         NOT NULL DEFAULT '',
        referrer    VARCHAR(2048)       NOT NULL DEFAULT '',
        viewed_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id   (post_id),
        KEY viewed_at (viewed_at),
        KEY ip_post_dedup (ip_hash, post_id, viewed_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Ensure columns added in later versions exist.
 * Safe to call repeatedly — checks before altering.
 */
function cspv_upgrade_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';

    $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
    if ( ! is_array( $cols ) ) { return; }

    if ( ! in_array( 'user_agent', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN user_agent VARCHAR(255) NOT NULL DEFAULT '' AFTER post_id" );
    }
    if ( ! in_array( 'ip_hash', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN ip_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER user_agent" );
    }

    if ( ! in_array( 'referrer', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN referrer VARCHAR(2048) NOT NULL DEFAULT '' AFTER ip_hash" );
    }

    // Add composite index for server side dedup (v2.8.0+)
    $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'ip_post_dedup'" );
    if ( empty( $indexes ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX ip_post_dedup (ip_hash, post_id, viewed_at)" );
    }
}
