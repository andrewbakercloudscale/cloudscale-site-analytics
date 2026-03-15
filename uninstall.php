<?php
/**
 * CloudScale Page Views - Uninstall
 *
 * Runs when the plugin is deleted via Plugins > Delete.
 * Removes all plugin data: custom database tables and wp_options entries.
 *
 * @since 2.9.94
 *
 * @package Lightweight_WordPress_Free_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Drop custom tables ────────────────────────────────────────────────────

$tables = array(
    $wpdb->prefix . 'cspv_views_v2',
    $wpdb->prefix . 'cspv_referrers_v2',
    $wpdb->prefix . 'cspv_geo_v2',
    $wpdb->prefix . 'cspv_visitors_v2',
    // Legacy V1 table — remove if present.
    $wpdb->prefix . 'cspv_views',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted internal value
}

// ── Delete all plugin options ─────────────────────────────────────────────

$options = array(
    'cspv_version',
    'cspv_opcache_version',
    'cspv_auto_display',
    'cspv_display_color',
    'cspv_display_icon',
    'cspv_display_suffix',
    'cspv_display_style',
    'cspv_display_post_types',
    'cspv_track_post_types',
    'cspv_dedup_enabled',
    'cspv_dedup_window',
    'cspv_throttle_enabled',
    'cspv_throttle_limit',
    'cspv_throttle_window',
    'cspv_ip_blocklist',
    'cspv_block_log',
    'cspv_ftb_enabled',
    'cspv_ftb_page_limit',
    'cspv_ftb_window',
    'cspv_ftb_block_duration',
    'cspv_ftb_blocklist',
    'cspv_ftb_log',
    'cspv_tracking_paused',
    'cspv_ignore_jetpack',
    'cspv_geo_source',
    'cspv_dbip_last_updated',
    'cspv_migration_complete',
    'cspv_migration_log',
    'cspv_site_health_cache',
    // Legacy option kept for upgrade path — remove on uninstall.
    'cspv_use_v2',
);

foreach ( $options as $option ) {
    delete_option( $option );
}
