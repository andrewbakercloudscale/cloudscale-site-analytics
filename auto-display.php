<?php
/**
 * CloudScale Analytics - Auto Display
 *
 * Automatically injects the view counter into single post pages without
 * requiring any theme file edits. Controlled via Settings > CloudScale Views.
 *
 * Positions:
 *   before_content  - Above the post body
 *   after_content   - Below the post body
 *   both            - Above and below
 *   off             - Disabled (use template functions manually)
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Settings registration
// -------------------------------------------------------------------------
add_action( 'admin_init', 'cspv_register_display_settings' );

/**
 * Register all display-related plugin settings with the WordPress Settings API.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_register_display_settings() {
    register_setting( 'cspv_display_options', 'cspv_auto_display', array(
        'type'              => 'string',
        'default'           => 'before_content',
        'sanitize_callback' => 'cspv_sanitize_auto_display',
    ) );
    register_setting( 'cspv_display_options', 'cspv_display_post_types', array(
        'type'              => 'array',
        'default'           => array( 'post' ),
        'sanitize_callback' => 'cspv_sanitize_post_types',
    ) );
    register_setting( 'cspv_display_options', 'cspv_display_icon', array(
        'type'              => 'string',
        'default'           => '👁',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    register_setting( 'cspv_display_options', 'cspv_display_suffix', array(
        'type'              => 'string',
        'default'           => ' views',
        'sanitize_callback' => 'sanitize_text_field',
    ) );
    register_setting( 'cspv_display_options', 'cspv_display_style', array(
        'type'              => 'string',
        'default'           => 'badge',
        'sanitize_callback' => 'cspv_sanitize_display_style',
    ) );
    register_setting( 'cspv_display_options', 'cspv_track_post_types', array(
        'type'              => 'array',
        'default'           => array( 'post' ),
        'sanitize_callback' => 'cspv_sanitize_post_types',
    ) );
    register_setting( 'cspv_display_options', 'cspv_display_color', array(
        'type'              => 'string',
        'default'           => 'red',
        'sanitize_callback' => 'cspv_sanitize_display_color',
    ) );
}

/**
 * Sanitise the cspv_display_color setting.
 *
 * @since 1.0.0
 * @param string $value Raw value from settings form.
 * @return string One of blue|pink|red|purple|grey.
 */
function cspv_sanitize_display_color( $value ) {
    $valid = array( 'blue', 'pink', 'red', 'purple', 'grey' );
    return in_array( $value, $valid, true ) ? $value : 'blue';
}

/**
 * Sanitise the cspv_auto_display setting.
 *
 * @since 1.0.0
 * @param string $value Raw value from settings form.
 * @return string One of before_content|after_content|both|off.
 */
function cspv_sanitize_auto_display( $value ) {
    $valid = array( 'before_content', 'after_content', 'both', 'off' );
    return in_array( $value, $valid, true ) ? $value : 'before_content';
}

/**
 * Sanitise the post-types array setting.
 *
 * @since 1.0.0
 * @param mixed $value Raw value from settings form.
 * @return array Array of sanitised post-type slugs.
 */
function cspv_sanitize_post_types( $value ) {
    if ( ! is_array( $value ) ) { return array( 'post' ); }
    return array_map( 'sanitize_key', $value );
}

/**
 * Sanitise the display style setting.
 *
 * @since 1.0.0
 * @param string $value Raw value from settings form.
 * @return string One of badge|pill|minimal.
 */
function cspv_sanitize_display_style( $value ) {
    $valid = array( 'badge', 'pill', 'minimal' );
    return in_array( $value, $valid, true ) ? $value : 'badge';
}

// One-time migration: if color is still the old 'blue' default, switch to 'red'.
add_action( 'init', function () {
    if ( get_option( 'cspv_display_color' ) === 'blue' ) {
        update_option( 'cspv_display_color', 'red' );
    }
}, 1 );

// -------------------------------------------------------------------------
// 2. Settings page under Settings menu
// -------------------------------------------------------------------------
// Display settings are rendered inside the main Tools > CloudScale Page Views tab.
// See stats-page.php for the "Display" tab.


// Display settings UI is now rendered inside the Tools > CloudScale Page Views > Display tab.
// See stats-page.php for the form and save handler.


// -------------------------------------------------------------------------
// 3. Front end auto injection
//    "before_content" = above the post title (via the_title)
//    "after_content"  = below the post body  (via the_content)
//    "both"           = both positions
// -------------------------------------------------------------------------
add_filter( 'the_title',   'cspv_auto_display_above_title', 99, 2 );
add_filter( 'the_content', 'cspv_auto_display_views', 99 );

/**
 * Build the view counter HTML element using current display settings.
 *
 * Used by both the title and content hooks so the output is consistent
 * regardless of which injection position is configured.
 *
 * @since 1.0.0
 * @return string Counter HTML string.
 */
function cspv_build_counter_html() {
    $icon   = get_option( 'cspv_display_icon', '👁' );
    $suffix = get_option( 'cspv_display_suffix', ' views' );
    $style  = get_option( 'cspv_display_style', 'badge' );
    $count  = cspv_get_view_count();

    $icon_html   = ! empty( $icon )   ? '<span class="cspv-ad-icon">' . esc_html( $icon ) . '</span>' : '';
    $num_html    = '<span class="cspv-ad-num">' . esc_html( number_format( $count ) ) . '</span>';
    $suffix_html = ! empty( $suffix ) ? '<span class="cspv-ad-suffix">' . esc_html( ltrim( $suffix ) ) . '</span>' : '';

    return '<div class="cspv-auto-views">'
         . '<span class="cspv-ad-' . esc_attr( $style ) . '">'
         . $icon_html . $num_html . $suffix_html
         . '</span></div><!-- /cspv-auto -->';
}

/**
 * Inject the view counter above the post title on singular pages.
 *
 * Hooked to `the_title` at priority 99. Guards ensure the counter only
 * appears once for the main queried post, never in widgets or nav menus.
 *
 * @since 1.0.0
 * @param string $title   Post title.
 * @param int    $post_id Post ID.
 * @return string Modified title with counter prepended, or unchanged title.
 */
function cspv_auto_display_above_title( $title, $post_id = 0 ) {
    // Only on singular front end, only for the main queried post
    if ( ! is_singular() || is_feed() || is_admin() ) {
        return $title;
    }
    // Only for the main post in the loop (not widget titles, nav menus, etc)
    if ( ! in_the_loop() || (int) $post_id !== (int) get_queried_object_id() ) {
        return $title;
    }

    $position = get_option( 'cspv_auto_display', 'before_content' );
    if ( $position !== 'before_content' && $position !== 'both' ) {
        return $title;
    }

    $post_types = get_option( 'cspv_display_post_types', array( 'post' ) );
    if ( ! in_array( get_post_type(), $post_types, true ) ) {
        return $title;
    }

    // Prepend the counter above the title text
    return cspv_build_counter_html() . $title;
}

/**
 * Inject the view counter below post content on singular pages.
 *
 * Hooked to `the_content` at priority 99. Fires for after_content and both modes.
 *
 * @since 1.0.0
 * @param string $content Post content.
 * @return string Modified content with counter appended, or unchanged content.
 */
function cspv_auto_display_views( $content ) {
    if ( ! is_singular() || is_feed() || is_admin() ) {
        return $content;
    }

    $position = get_option( 'cspv_auto_display', 'before_content' );
    if ( $position === 'off' || $position === 'before_content' ) {
        return $content;
    }

    $post_types = get_option( 'cspv_display_post_types', array( 'post' ) );
    if ( ! in_array( get_post_type(), $post_types, true ) ) {
        return $content;
    }

    $html = cspv_build_counter_html();

    if ( $position === 'after_content' || $position === 'both' ) {
        $content = $content . $html;
    }

    return $content;
}

add_filter( 'the_excerpt', 'cspv_search_results_counter', 99 );

/**
 * Append the view counter to each post excerpt on search result pages.
 *
 * @since 2.9.94
 * @param string $excerpt Post excerpt.
 * @return string Excerpt with counter appended, or unchanged.
 */
function cspv_search_results_counter( $excerpt ) {
	if ( ! is_search() || is_admin() || ! in_the_loop() ) {
		return $excerpt;
	}

	$post_types = get_option( 'cspv_display_post_types', array( 'post' ) );
	if ( ! in_array( get_post_type(), $post_types, true ) ) {
		return $excerpt;
	}

	return $excerpt . cspv_build_counter_html();
}

// -------------------------------------------------------------------------
// 4. Front end CSS for all three styles
// -------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'cspv_auto_display_style', 100 );

/**
 * Enqueue inline CSS for the auto-display counter on applicable frontend pages.
 *
 * Skipped when auto-display is disabled or the current page type doesn't
 * need a counter, so styles are never injected globally.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_auto_display_style() {
    if ( ! is_singular() && ! is_home() && ! is_front_page() && ! is_archive() && ! is_search() ) {
        return;
    }
    $position = get_option( 'cspv_auto_display', 'before_content' );
    if ( $position === 'off' ) { return; }

    $color = get_option( 'cspv_display_color', 'red' );
    $colors = array(
        'blue'   => array( 'grad' => '#1a3a8f, #1e6fd9', 'solid' => '#1a3a8f', 'light_bg' => '#f0f6ff', 'light_border' => '#d0dfff', 'light_text' => '#1a3a8f', 'light_suffix' => '#5a7abf' ),
        'pink'   => array( 'grad' => '#db2777, #f472b6', 'solid' => '#db2777', 'light_bg' => '#fdf2f8', 'light_border' => '#fbcfe8', 'light_text' => '#be185d', 'light_suffix' => '#db2777' ),
        'red'    => array( 'grad' => '#b91c1c, #ef4444', 'solid' => '#b91c1c', 'light_bg' => '#fef2f2', 'light_border' => '#fecaca', 'light_text' => '#991b1b', 'light_suffix' => '#b91c1c' ),
        'purple' => array( 'grad' => '#6b21a8, #a855f7', 'solid' => '#6b21a8', 'light_bg' => '#faf5ff', 'light_border' => '#e9d5ff', 'light_text' => '#6b21a8', 'light_suffix' => '#7c3aed' ),
        'grey'   => array( 'grad' => '#4b5563, #9ca3af', 'solid' => '#4b5563', 'light_bg' => '#f9fafb', 'light_border' => '#e5e7eb', 'light_text' => '#374151', 'light_suffix' => '#6b7280' ),
    );
    $c = isset( $colors[ $color ] ) ? $colors[ $color ] : $colors['blue'];

    $css  = '.cspv-auto-views{margin:0 0 .25em;line-height:1;display:flex;align-items:center;justify-content:flex-end;gap:8px;clear:both;}' . "\n";
    $css .= '.cspv-ad-badge{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,' . $c['grad'] . ');color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;letter-spacing:.02em;}' . "\n";
    $css .= '.cspv-ad-badge .cspv-ad-icon{font-size:15px;line-height:1;}.cspv-ad-badge .cspv-ad-num{font-variant-numeric:tabular-nums;}.cspv-ad-badge .cspv-ad-suffix{opacity:.85;font-weight:500;font-size:12px;}' . "\n";
    $css .= '.cspv-ad-pill{display:inline-flex;align-items:center;gap:6px;background:' . $c['light_bg'] . ';border:1px solid ' . $c['light_border'] . ';color:' . $c['light_text'] . ';padding:5px 12px;border-radius:16px;font-size:13px;font-weight:600;}' . "\n";
    $css .= '.cspv-ad-pill .cspv-ad-icon{font-size:14px;line-height:1;}.cspv-ad-pill .cspv-ad-num{font-variant-numeric:tabular-nums;}.cspv-ad-pill .cspv-ad-suffix{color:' . $c['light_suffix'] . ';font-weight:500;font-size:12px;}' . "\n";
    $css .= '.cspv-ad-minimal{display:inline-flex;align-items:center;gap:5px;color:' . $c['solid'] . ';font-size:13px;}' . "\n";
    $css .= '.cspv-ad-minimal .cspv-ad-icon{line-height:1;}.cspv-ad-minimal .cspv-ad-num{font-variant-numeric:tabular-nums;}.cspv-ad-minimal .cspv-ad-suffix{font-size:12px;}';

    if ( is_search() ) {
        $css .= "\n" . '.search-results .hentry,.search .hentry{padding-bottom:1.5em;margin-bottom:1.5em;border-bottom:2px solid #e5e7eb;}'
              . '.search-results .hentry:last-child,.search .hentry:last-child{border-bottom:none;}';
    }

    wp_register_style( 'cspv-auto-display', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-auto-display' );
    wp_add_inline_style( 'cspv-auto-display', $css );
}
