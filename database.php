<?php
/**
 * CloudScale Analytics - Database
 *
 * Creates the wp_cs_analytics_views_v2 and wp_cs_analytics_referrers_v2 tables.
 * Plugin uses V2 hourly bucket schema exclusively.
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Run on plugin activation: create all database tables and seed the version option.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_activate() {
    cspv_create_table_v2();
    cspv_create_table_referrers_v2();
    cspv_create_table_geo_v2();
    cspv_create_table_visitors_v2();
    cspv_create_table_404_v2();
    cspv_create_table_sessions_v2();
    add_option( 'cspv_version', CSPV_VERSION );
}

/**
 * Create the V2 hourly bucketed views table.
 *
 * One row per post per hour, with a view_count column.
 * The beacon increments the bucket for the current hour on each view.
 *
 * Schema:
 *   post_id     — the post
 *   viewed_at   — hour bucket, always truncated to :00:00
 *   view_count  — number of views in this bucket
 */
function cspv_create_table_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cs_analytics_views_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id     BIGINT(20) UNSIGNED NOT NULL,
        viewed_at   DATETIME            NOT NULL COMMENT 'Hour bucket, always :00:00',
        view_count  INT UNSIGNED        NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY post_hour (post_id, viewed_at),
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

    $table           = $wpdb->prefix . 'cs_analytics_referrers_v2';
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

/**
 * Create the geo tracking table.
 *
 * Stores country code per post per hour bucket. Country is resolved
 * from the CloudFlare CF-IPCountry header (no raw IP stored).
 * Later can be extended with city/lat/lng from DB-IP Lite.
 */
function cspv_create_table_geo_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cs_analytics_geo_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id       BIGINT(20) UNSIGNED NOT NULL,
        viewed_at     DATETIME            NOT NULL COMMENT 'Hour bucket',
        country_code  CHAR(2)             NOT NULL DEFAULT '',
        view_count    INT UNSIGNED        NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY post_hour_country (post_id, viewed_at, country_code),
        KEY country_code (country_code),
        KEY viewed_at (viewed_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Create the unique visitors tracking table.
 *
 * Stores a SHA256 hash of the visitor IP per post per calendar day.
 * The unique key ensures the same visitor viewing the same post
 * on the same day only creates one row (INSERT IGNORE).
 * No raw IP is ever stored.
 */
function cspv_create_table_visitors_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cs_analytics_visitors_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        visitor_hash  CHAR(64)            NOT NULL,
        post_id       BIGINT(20) UNSIGNED NOT NULL,
        viewed_at     DATE                NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY hash_post_day (visitor_hash, post_id, viewed_at),
        KEY viewed_at (viewed_at),
        KEY post_id (post_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Create the session depth tracking table.
 *
 * One row per session+post pair (INSERT IGNORE deduplication).
 * session_id is a browser-generated random token stored in sessionStorage —
 * it contains no PII and is scoped to a single browser tab session.
 * Enables computing pages-per-session percentiles (P50 / P95 / P99).
 */
function cspv_create_table_sessions_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cs_analytics_sessions_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id  VARCHAR(64)         NOT NULL,
        post_id     BIGINT(20) UNSIGNED NOT NULL,
        viewed_at   DATE                NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY session_post (session_id, post_id),
        KEY viewed_at (viewed_at),
        KEY session_id (session_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
