<?php
/**
 * CloudScale Page Views - Jetpack Migration  v2.0.0
 *
 * Migrates historical post view counts from Jetpack into CloudScale.
 *
 * HOW JETPACK STORES STATS
 * ------------------------
 * Modern Jetpack stores all stats on WordPress.com servers. The plugin
 * exposes two ways to retrieve them from your own PHP code:
 *
 *   1. stats_get_csv( 'postviews', 'days=-1&limit=500' )
 *      A Jetpack-provided wrapper that fetches all-time post view totals
 *      from the WP.com Stats API using the existing Jetpack site connection.
 *      No separate OAuth or API key needed — Jetpack handles auth.
 *      Returns: array of [ 'post_id' => N, 'views' => N ] rows.
 *
 *   2. Automattic\Jetpack\Stats\WPCOM_Stats (Jetpack >= 11.5)
 *      Object-oriented wrapper around the same API.
 *
 *   3. Local post meta (_jetpack_post_views, jetpack_post_stats)
 *      Only present on very old Jetpack installs (pre-2018).
 *
 * This migration tries all three methods in order.
 *
 * DOUBLE-MIGRATION PROTECTION
 * After a successful run a lock is stored. Reset it via "Reset Lock" if
 * you need to re-run after a database restore.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_cspv_jetpack_preflight',   'cspv_ajax_jetpack_preflight' );
add_action( 'wp_ajax_cspv_jetpack_migrate',      'cspv_ajax_jetpack_migrate' );
add_action( 'wp_ajax_cspv_migration_reset_lock', 'cspv_ajax_migration_reset_lock' );
add_action( 'wp_ajax_cspv_manual_import',        'cspv_ajax_manual_import' );

// -------------------------------------------------------------------------
// Lock helpers
// -------------------------------------------------------------------------

function cspv_migration_is_locked() {
    return (bool) get_option( 'cspv_migration_complete', false );
}

function cspv_migration_set_lock( $data ) {
    update_option( 'cspv_migration_complete', $data, false );
}

function cspv_migration_clear_lock() {
    delete_option( 'cspv_migration_complete' );
}

// -------------------------------------------------------------------------
// Pre-flight — detect Jetpack and preview what will be imported
// -------------------------------------------------------------------------

function cspv_ajax_jetpack_preflight() {
    if ( ! check_ajax_referer( 'cspv_migrate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $lock   = get_option( 'cspv_migration_complete', false );
    $result = cspv_get_jetpack_data();

    $result['already_migrated'] = (bool) $lock;
    $result['lock_info']        = $lock ?: null;

    wp_send_json_success( $result );
}

// -------------------------------------------------------------------------
// Migration
// -------------------------------------------------------------------------

function cspv_ajax_jetpack_migrate() {
    if ( ! check_ajax_referer( 'cspv_migrate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $lock = get_option( 'cspv_migration_complete', false );
    if ( $lock ) {
        wp_send_json_error( array(
            'message'        => 'Migration has already been run and is locked to prevent double-counting.',
            'locked_at'      => is_array( $lock ) ? ( $lock['date'] ?? '—' ) : '—',
            'already_locked' => true,
        ), 409 );
        return;
    }

    $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'additive';
    if ( ! in_array( $mode, array( 'additive', 'replace' ), true ) ) {
        $mode = 'additive';
    }

    global $wpdb;
    $table = $wpdb->prefix . 'cspv_views';
    if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        if ( function_exists( 'cspv_create_table' ) ) { cspv_create_table(); }
    }

    $data           = cspv_get_jetpack_data();
    $posts          = $data['posts'];
    $migrated       = 0;
    $skipped        = 0;
    $total_imported = 0;
    $import_time    = current_time( 'mysql' );

    foreach ( $posts as $item ) {
        $post_id  = (int) $item['post_id'];
        $jp_views = (int) $item['jetpack_views'];
        if ( $jp_views <= 0 ) { $skipped++; continue; }

        $current_cs = (int) get_post_meta( $post_id, CSPV_META_KEY, true );

        if ( $mode === 'replace' ) {
            update_post_meta( $post_id, CSPV_META_KEY, $jp_views );
            $to_insert = $jp_views;
        } else {
            $diff = max( 0, $jp_views - $current_cs );
            if ( $diff <= 0 ) { $skipped++; continue; }
            update_post_meta( $post_id, CSPV_META_KEY, $current_cs + $diff );
            $to_insert = $diff;
        }

        // Insert up to 100 synthetic rows to represent the imported views
        $batch = min( $to_insert, 100 );
        $ip    = hash( 'sha256', 'jetpack-migration-' . $post_id );
        for ( $i = 0; $i < $batch; $i++ ) {
            $wpdb->insert( $table, array(
                'post_id'    => $post_id,
                'user_agent' => 'Jetpack Migration v2.0.0',
                'ip_hash'    => $ip,
                'viewed_at'  => $import_time,
            ), array( '%d', '%s', '%s', '%s' ) );
        }

        $migrated++;
        $total_imported += $to_insert;
    }

    $lock_data = array(
        'date'           => $import_time,
        'posts_migrated' => $migrated,
        'views_imported' => $total_imported,
        'mode'           => $mode,
        'source'         => $data['method'],
    );
    cspv_migration_set_lock( $lock_data );

    $log = (array) get_option( 'cspv_migration_log', array() );
    array_unshift( $log, array_merge( $lock_data, array( 'posts_skipped' => $skipped ) ) );
    update_option( 'cspv_migration_log', array_slice( $log, 0, 10 ), false );

    wp_send_json_success( array(
        'migrated'       => $migrated,
        'skipped'        => $skipped,
        'views_imported' => $total_imported,
        'mode'           => $mode,
        'source'         => $data['method'],
    ) );
}

// -------------------------------------------------------------------------
// Manual CSV import — fallback for sites where API fetch fails
// One line per post:  post-slug-or-id, view_count
// -------------------------------------------------------------------------

function cspv_ajax_manual_import() {
    if ( ! check_ajax_referer( 'cspv_migrate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    $lock = get_option( 'cspv_migration_complete', false );
    if ( $lock ) {
        wp_send_json_error( array( 'message' => 'Migration already completed. Reset the lock to re-import.', 'already_locked' => true ), 409 );
        return;
    }

    $raw = isset( $_POST['csv_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ) ) : '';
    if ( empty( $raw ) ) {
        wp_send_json_error( array( 'message' => 'No CSV data provided.' ) );
        return;
    }

    global $wpdb;
    $table       = $wpdb->prefix . 'cspv_views';
    $migrated    = 0;
    $skipped     = 0;
    $errors      = array();
    $import_time = current_time( 'mysql' );
    $total_views = 0;

    foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $line ) {
        if ( strpos( $line, ',' ) === false ) { $skipped++; continue; }
        $parts     = array_map( 'trim', explode( ',', $line, 2 ) );
        $id_or_url = $parts[0];
        $views     = (int) $parts[1];
        if ( $views <= 0 ) { $skipped++; continue; }

        $post_id = 0;
        if ( is_numeric( $id_or_url ) ) {
            $post_id = (int) $id_or_url;
        } else {
            $post_id = url_to_postid( $id_or_url );
            if ( ! $post_id ) {
                $p = get_page_by_path( basename( rtrim( $id_or_url, '/' ) ), OBJECT, 'post' );
                if ( $p ) { $post_id = $p->ID; }
            }
        }

        if ( ! $post_id ) {
            $errors[] = substr( $id_or_url, 0, 60 );
            $skipped++;
            continue;
        }

        $current = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
        update_post_meta( $post_id, CSPV_META_KEY, $current + $views );

        $batch = min( $views, 100 );
        $ip    = hash( 'sha256', 'manual-import-' . $post_id );
        for ( $i = 0; $i < $batch; $i++ ) {
            $wpdb->insert( $table, array(
                'post_id'    => $post_id,
                'user_agent' => 'Manual Import v2.0.0',
                'ip_hash'    => $ip,
                'viewed_at'  => $import_time,
            ), array( '%d', '%s', '%s', '%s' ) );
        }

        $migrated++;
        $total_views += $views;
    }

    $lock_data = array(
        'date'           => $import_time,
        'posts_migrated' => $migrated,
        'views_imported' => $total_views,
        'mode'           => 'manual_csv',
        'source'         => 'manual_import',
    );
    cspv_migration_set_lock( $lock_data );

    $log = (array) get_option( 'cspv_migration_log', array() );
    array_unshift( $log, array_merge( $lock_data, array( 'posts_skipped' => $skipped ) ) );
    update_option( 'cspv_migration_log', array_slice( $log, 0, 10 ), false );

    wp_send_json_success( array(
        'migrated'       => $migrated,
        'skipped'        => $skipped,
        'views_imported' => $total_views,
        'errors'         => array_slice( $errors, 0, 10 ),
    ) );
}

// -------------------------------------------------------------------------
// Reset lock
// -------------------------------------------------------------------------

function cspv_ajax_migration_reset_lock() {
    if ( ! check_ajax_referer( 'cspv_migrate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }
    cspv_migration_clear_lock();
    wp_send_json_success( array( 'message' => 'Lock cleared.' ) );
}

// -------------------------------------------------------------------------
// Core: fetch Jetpack data — tries all available methods
// -------------------------------------------------------------------------

function cspv_get_jetpack_data() {
    $posts  = array();
    $method = 'none';
    $note   = '';

    // ── Method A: stats_get_csv() — Jetpack's own API wrapper ────────────
    // This is the recommended way. It uses the existing Jetpack site
    // connection (no separate auth) and fetches ALL-TIME post view totals
    // from WordPress.com Stats. days=-1 means all time, limit=500 = max.
    if ( function_exists( 'stats_get_csv' ) ) {
        // Suppress errors — the function can return false on API failure
        $rows = @stats_get_csv( 'postviews', 'days=-1&limit=500' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( is_array( $rows ) && ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $pid  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
                $jv   = isset( $row['views'] )   ? (int) $row['views']   : 0;
                if ( $pid > 0 && $jv > 0 ) {
                    cspv_add_jp_post( $posts, $pid, $jv );
                }
            }
            if ( ! empty( $posts ) ) {
                $method = 'stats_get_csv';
                $note   = 'Fetched live from WordPress.com Stats API via stats_get_csv()';
            }
        }
        if ( empty( $posts ) && function_exists( 'stats_get_csv' ) ) {
            // Function exists but returned nothing — API likely OK but no data yet
            $note = 'stats_get_csv() returned no data — your site may have very few views or the API is temporarily unavailable';
        }
    }

    // ── Method B: Automattic\Jetpack\Stats\WPCOM_Stats (Jetpack >= 11.5) ─
    if ( empty( $posts ) && class_exists( 'Automattic\\Jetpack\\Stats\\WPCOM_Stats' ) ) {
        try {
            $wpcom = new Automattic\Jetpack\Stats\WPCOM_Stats();
            $data  = $wpcom->get_top_posts( array( 'max' => 500, 'summarize' => 1 ) );
            if ( ! empty( $data->summary->postviews ) ) {
                foreach ( $data->summary->postviews as $row ) {
                    $pid = isset( $row->id )    ? (int) $row->id    : 0;
                    $jv  = isset( $row->views ) ? (int) $row->views : 0;
                    if ( $pid > 0 && $jv > 0 ) {
                        cspv_add_jp_post( $posts, $pid, $jv );
                    }
                }
                if ( ! empty( $posts ) ) {
                    $method = 'WPCOM_Stats';
                    $note   = 'Fetched via Automattic\Jetpack\Stats\WPCOM_Stats class';
                }
            }
        } catch ( Exception $e ) {
            $note = 'WPCOM_Stats error: ' . $e->getMessage();
        }
    }

    // ── Method C: Legacy local post meta (Jetpack < 2018) ─────────────────
    if ( empty( $posts ) ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_jetpack_post_views' AND meta_value > 0
             ORDER BY CAST(meta_value AS UNSIGNED) DESC LIMIT 500"
        );
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                cspv_add_jp_post( $posts, (int) $row->post_id, (int) $row->meta_value );
            }
            if ( ! empty( $posts ) ) {
                $method = 'post_meta_legacy';
                $note   = 'Found via _jetpack_post_views post meta (older Jetpack install)';
            }
        }
    }

    if ( empty( $posts ) ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'jetpack_post_stats' LIMIT 500"
        );
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $stats = maybe_unserialize( $row->meta_value );
                $jv    = isset( $stats['views'] ) ? (int) $stats['views'] : 0;
                if ( $jv > 0 ) { cspv_add_jp_post( $posts, (int) $row->post_id, $jv ); }
            }
            if ( ! empty( $posts ) ) {
                $method = 'post_meta_stats';
                $note   = 'Found via jetpack_post_stats post meta';
            }
        }
    }

    // ── Detect Jetpack presence so we can report useful status ────────────
    $jetpack_active = function_exists( 'stats_get_csv' )
        || class_exists( 'Automattic\\Jetpack\\Stats\\WPCOM_Stats' )
        || defined( 'JETPACK__VERSION' )
        || is_plugin_active( 'jetpack/jetpack.php' );

    if ( ! $jetpack_active ) {
        $note = 'Jetpack plugin does not appear to be active on this site';
    } elseif ( empty( $posts ) && empty( $note ) ) {
        $note = 'Jetpack is active but returned no view data. The stats_get_csv() function may need a few seconds — try again, or check that the Stats module is enabled in Jetpack settings.';
    }

    $total_jp = 0;
    $total_cs = 0;
    foreach ( $posts as $p ) {
        $total_jp += $p['jetpack_views'];
        $total_cs += $p['cs_views'];
    }

    return array(
        'jetpack_active' => $jetpack_active,
        'method'         => $method,
        'note'           => $note,
        'cloud_only'     => ( $jetpack_active && empty( $posts ) && function_exists( 'stats_get_csv' ) ),
        'posts_found'    => count( $posts ),
        'total_jp_views' => $total_jp,
        'total_cs_views' => $total_cs,
        'posts'          => array_values( $posts ),
    );
}

// -------------------------------------------------------------------------
// Helper: resolve a post ID and add it to the working array
// -------------------------------------------------------------------------

function cspv_add_jp_post( &$posts, $post_id, $jp_views ) {
    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
        return;
    }
    $cv = (int) get_post_meta( $post->ID, CSPV_META_KEY, true );
    $posts[ $post->ID ] = array(
        'post_id'       => $post->ID,
        'title'         => $post->post_title,
        'jetpack_views' => $jp_views,
        'cs_views'      => $cv,
        'will_add'      => max( 0, $jp_views - $cv ),
    );
}
