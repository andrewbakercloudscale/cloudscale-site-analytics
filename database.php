<?php
/**
 * CloudScale Page Views - Database
 *
 * Creates the wp_cspv_views_v2 and wp_cspv_referrers_v2 tables.
 * Plugin uses V2 hourly bucket schema exclusively.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cspv_activate() {
    cspv_create_table_v2();
    cspv_create_table_referrers_v2();
    add_option( 'cspv_version', CSPV_VERSION );
}

/**
 * Create the V2 hourly bucketed views table.
 *
 * One row per post per hour per source, with a view_count column.
 * Imported data lands as a single row with the full count on the import hour.
 * Tracked views increment the bucket for the current hour.
 *
 * Schema:
 *   post_id     — the post
 *   viewed_at   — hour bucket, always truncated to :00:00
 *   view_count  — number of views in this bucket (1 for real time, N for imports)
 *   source      — 'tracked' | 'imported' | 'manual'
 */
function cspv_create_table_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_views_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT(20) UNSIGNED NOT NULL,
        viewed_at   DATETIME            NOT NULL COMMENT 'Hour bucket, always :00:00',
        view_count  INT UNSIGNED        NOT NULL DEFAULT 1,
        source      VARCHAR(32)         NOT NULL DEFAULT 'tracked',
        PRIMARY KEY (id),
        UNIQUE KEY post_hour_source (post_id, viewed_at, source),
        KEY viewed_at (viewed_at),
        KEY post_id   (post_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * V2 referrer table: one row per post + hour + referrer.
 */
function cspv_create_table_referrers_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_referrers_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT(20) UNSIGNED NOT NULL,
        viewed_at   DATETIME            NOT NULL COMMENT 'Hour bucket, always :00:00',
        referrer    VARCHAR(512)        NOT NULL DEFAULT '',
        view_count  INT UNSIGNED        NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY post_hour_ref (post_id, viewed_at, referrer(191)),
        KEY viewed_at (viewed_at),
        KEY post_id   (post_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
