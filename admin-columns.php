<?php
/**
 * CloudScale Page Views - Admin Columns
 *
 * Adds a sortable Views column to the Posts list table in wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'manage_posts_columns',              'cspv_add_admin_column' );
add_action( 'manage_posts_custom_column',        'cspv_render_admin_column', 10, 2 );
add_filter( 'manage_edit-post_sortable_columns', 'cspv_sortable_column' );
add_action( 'pre_get_posts',                     'cspv_sort_by_views' );

function cspv_add_admin_column( $columns ) {
    $columns['cspv_views'] = '👁 Views';
    return $columns;
}

function cspv_render_admin_column( $column, $post_id ) {
    if ( 'cspv_views' === $column ) {
        echo esc_html( number_format( cspv_get_view_count( $post_id ) ) );
    }
}

function cspv_sortable_column( $columns ) {
    $columns['cspv_views'] = 'cspv_views';
    return $columns;
}

function cspv_sort_by_views( WP_Query $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( 'cspv_views' === $query->get( 'orderby' ) ) {
        $query->set( 'meta_key', CSPV_META_KEY );
        $query->set( 'orderby',  'meta_value_num' );
    }
}
