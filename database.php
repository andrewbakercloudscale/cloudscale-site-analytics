<?php
/**
 * CloudScale Analytics - Database
 *
 * Creates the wp_cspv_analytics_views_v2 and wp_cspv_analytics_referrers_v2 tables.
 * Plugin uses V2 hourly bucket schema exclusively.
 *
 * @package CloudScale_Site_Analytics
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
    cspv_migrate_table_names();
    cspv_create_table_v2();
    cspv_create_table_referrers_v2();
    cspv_create_table_geo_v2();
    cspv_create_table_visitors_v2();
    cspv_create_table_404_v2();
    cspv_create_table_sessions_v2();
    cspv_create_table_audio_v2();
    add_option( 'cspv_version', CSPV_VERSION );
}

/**
 * Move the tables an earlier version created under unprefixed names onto cspv_analytics_*.
 *
 * Until 2.9.516 the tables were named wp_cs_analytics_*_v2, and WordPress.org requires
 * everything a plugin creates to carry its prefix. Runs on every request until it has
 * finished once (one autoloaded option read after that), not on admin_init: the beacon
 * writes from the front end, and a site whose admin is not visited would otherwise record
 * into tables that do not exist yet.
 *
 * A plain RENAME is the normal path. Both tables existing is the race this must survive:
 * a beacon request running the new code can create the new table (rest-api.php creates
 * it lazily) before this rename runs. The old rows are then merged in by the table's own
 * unique key, the same way the beacon adds to a bucket, and only then is the old table
 * dropped. A failed merge leaves the old table in place and the option unset, so the next
 * request tries again rather than losing anything.
 *
 * @since 2.9.516
 * @return void
 */
function cspv_migrate_table_names() {
    if ( '1' === get_option( 'cspv_tables_prefixed', '' ) ) {
        return;
    }
    // add_option() is an INSERT on a unique key, so only one request at a time gets here.
    if ( ! add_option( 'cspv_tables_prefix_lock', time(), '', false ) ) {
        $held_since = (int) get_option( 'cspv_tables_prefix_lock', 0 );
        if ( time() - $held_since < 120 ) {
            return;
        }
        update_option( 'cspv_tables_prefix_lock', time(), false );
    }

    global $wpdb;
    // Old suffix => new suffix.
    $tables = array(
        'cs_analytics_views_v2' => 'cspv_analytics_views_v2',
        'cs_analytics_referrers_v2' => 'cspv_analytics_referrers_v2',
        'cs_analytics_geo_v2' => 'cspv_analytics_geo_v2',
        'cs_analytics_visitors_v2' => 'cspv_analytics_visitors_v2',
        'cs_analytics_sessions_v2' => 'cspv_analytics_sessions_v2',
        'cs_analytics_audio_v2' => 'cspv_analytics_audio_v2',
        'cs_analytics_404_v2' => 'cspv_analytics_404_v2',
    );

    $all_done = true;
    foreach ( $tables as $old_suffix => $new_suffix ) {
        $old = $wpdb->prefix . $old_suffix;
        $new = $wpdb->prefix . $new_suffix;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe during a one-time migration
        if ( $old !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) ) {
            continue;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe during a one-time migration
        $new_exists = $new === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) );
        if ( ! $new_exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time rename of this plugin's own table
            if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) ) ) {
                $all_done = false;
            }
            continue;
        }
        if ( false === cspv_merge_legacy_table( $old_suffix, $new, $old ) ) {
            $all_done = false;
            continue;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the old table's rows were merged into the new one on the line above
        $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old ) );
    }

    if ( $all_done ) {
        update_option( 'cspv_tables_prefixed', '1', true );
    }
    delete_option( 'cspv_tables_prefix_lock' );
}

/**
 * Add the rows of a pre-2.9.516 table into its renamed successor, by the table's unique key.
 *
 * Source columns are aliased in a derived table so the UPDATE clause can name the target's
 * columns unqualified without MySQL calling them ambiguous.
 *
 * @since 2.9.516
 * @param string $old_suffix Old table suffix, selects the statement.
 * @param string $new        Full name of the new table.
 * @param string $old        Full name of the old table.
 * @return int|bool Rows affected, or false on failure.
 */
function cspv_merge_legacy_table( $old_suffix, $new, $old ) {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration of this plugin's own tables
    switch ( $old_suffix ) {
        case 'cs_analytics_views_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id, viewed_at, view_count) SELECT * FROM (SELECT post_id AS p, viewed_at AS t, view_count AS n FROM %i) AS src ON DUPLICATE KEY UPDATE view_count = view_count + src.n', $new, $old ) );
        case 'cs_analytics_referrers_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id, viewed_at, referrer, view_count) SELECT * FROM (SELECT post_id AS p, viewed_at AS t, referrer AS r, view_count AS n FROM %i) AS src ON DUPLICATE KEY UPDATE view_count = view_count + src.n', $new, $old ) );
        case 'cs_analytics_geo_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id, viewed_at, country_code, view_count) SELECT * FROM (SELECT post_id AS p, viewed_at AS t, country_code AS c, view_count AS n FROM %i) AS src ON DUPLICATE KEY UPDATE view_count = view_count + src.n', $new, $old ) );
        case 'cs_analytics_visitors_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (visitor_hash, post_id, viewed_at) SELECT visitor_hash, post_id, viewed_at FROM %i', $new, $old ) );
        case 'cs_analytics_sessions_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (session_id, post_id, viewed_at) SELECT session_id, post_id, viewed_at FROM %i', $new, $old ) );
        case 'cs_analytics_audio_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id, bucketed_at, plays, completes) SELECT * FROM (SELECT post_id AS p, bucketed_at AS t, plays AS pl, completes AS co FROM %i) AS src ON DUPLICATE KEY UPDATE plays = plays + src.pl, completes = completes + src.co', $new, $old ) );
        case 'cs_analytics_404_v2':
            return $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (url, referrer, hit_count, first_seen, last_seen) SELECT * FROM (SELECT url AS u, referrer AS r, hit_count AS h, first_seen AS f, last_seen AS l FROM %i) AS src ON DUPLICATE KEY UPDATE hit_count = hit_count + src.h, first_seen = LEAST(first_seen, src.f), last_seen = GREATEST(last_seen, src.l)', $new, $old ) );
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return false;
}

/**
 * Create the V2 hourly bucketed views table.
 *
 * One row per post per hour, with a view_count column.
 * The beacon increments the bucket for the current hour on each view.
 *
 * Schema:
 *   post_id, the post
 *   viewed_at, hour bucket, always truncated to :00:00
 *   view_count, number of views in this bucket
 */
function cspv_create_table_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_analytics_views_v2';
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
 * Create the V2 audio-narration engagement table.
 *
 * One row per post per hour with two counters for the "Listen to this article"
 * player: `plays` (a listener pressed play) and `completes` (a listener reached
 * the end). The beacon increments the current hour's bucket on the <audio>
 * "play" and "ended" events, deduped per listener per 24h, so the ratio gives a
 * true completion rate.
 *
 * @since 1.7.0
 * @return void
 */
function cspv_create_table_audio_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_analytics_audio_v2';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id      BIGINT(20) UNSIGNED NOT NULL,
        bucketed_at  DATETIME            NOT NULL COMMENT 'Hour bucket, always :00:00',
        plays        INT UNSIGNED        NOT NULL DEFAULT 0,
        completes    INT UNSIGNED        NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY post_hour (post_id, bucketed_at),
        KEY bucketed_at (bucketed_at),
        KEY post_id     (post_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // dbDelta is unreliable at ADDing a column to an already-existing table, so
    // ensure `plays` explicitly (it was introduced after `completes`). Idempotent.
    $has_plays = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'plays' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe on this plugin's own table
    if ( ! $has_plays ) {
        $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN plays INT UNSIGNED NOT NULL DEFAULT 0 AFTER bucketed_at', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time additive migration of this plugin's own table
    }
}

/**
 * Create the V2 referrer table: one row per post per hour per referrer.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_create_table_referrers_v2() {
    global $wpdb;

    $table           = $wpdb->prefix . 'cspv_analytics_referrers_v2';
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
 * from the Cloudflare CF-IPCountry header or DB-IP; no raw IP stored.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_create_table_geo_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cspv_analytics_geo_v2';
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
 * Stores a SHA-256 hash of visitor IP per post per calendar day.
 * INSERT IGNORE deduplicates so each visitor counts once per post per day.
 * No raw IP is ever stored.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_create_table_visitors_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cspv_analytics_visitors_v2';
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
 * session_id is a browser-generated random token stored in sessionStorage;
 * it contains no PII and is scoped to a single browser tab session.
 * Enables computing pages-per-session percentiles (P50/P95/P99).
 *
 * @since 2.9.307
 * @return void
 */
function cspv_create_table_sessions_v2() {
    global $wpdb;
    $table           = $wpdb->prefix . 'cspv_analytics_sessions_v2';
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
