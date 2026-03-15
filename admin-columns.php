<?php
/**
 * Lightweight Analytics - Admin Columns
 *
 * Adds a sortable Views column to the Posts list table in wp-admin.
 *
 * @package Lightweight_WordPress_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'manage_posts_columns',              'cspv_add_admin_column' );
add_action( 'manage_posts_custom_column',        'cspv_render_admin_column', 10, 2 );
add_filter( 'manage_edit-post_sortable_columns', 'cspv_sortable_column' );
add_action( 'pre_get_posts',                     'cspv_sort_by_views' );

/**
 * Add the Views column to the Posts list table.
 *
 * @since 1.0.0
 * @param array $columns Existing column headers.
 * @return array Modified column headers.
 */
function cspv_add_admin_column( $columns ) {
    $columns['cspv_views'] = __( '👁 Views', 'lightweight-wordpress-free-analytics' );
    return $columns;
}

/**
 * Render the Views column value for a given post.
 *
 * @since 1.0.0
 * @param string $column  Column slug being rendered.
 * @param int    $post_id Current post ID.
 * @return void
 */
function cspv_render_admin_column( $column, $post_id ) {
    if ( 'cspv_views' === $column ) {
        echo esc_html( number_format( cspv_get_view_count( $post_id ) ) );
    }
}

/**
 * Register the Views column as sortable.
 *
 * @since 1.0.0
 * @param array $columns Existing sortable columns.
 * @return array Modified sortable columns.
 */
function cspv_sortable_column( $columns ) {
    $columns['cspv_views'] = 'cspv_views';
    return $columns;
}

/**
 * Apply the Views column sort to the main admin query.
 *
 * @since 1.0.0
 * @param WP_Query $query The current WP_Query instance.
 * @return void
 */
function cspv_sort_by_views( WP_Query $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( 'cspv_views' === $query->get( 'orderby' ) ) {
        $query->set( 'meta_key', CSPV_META_KEY );
        $query->set( 'orderby',  'meta_value_num' );
    }
}
