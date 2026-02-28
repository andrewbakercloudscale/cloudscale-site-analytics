<?php
/**
 * CloudScale Page Views - Auto Display
 *
 * Automatically injects the view counter into single post pages without
 * requiring any theme file edits. Controlled via Settings > CloudScale Views.
 *
 * Positions:
 *   before_content  - Above the post body
 *   after_content   - Below the post body
 *   both            - Above and below
 *   off             - Disabled (use template functions manually)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Settings registration
// -------------------------------------------------------------------------
add_action( 'admin_init', 'cspv_register_display_settings' );

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
}

function cspv_sanitize_auto_display( $value ) {
    $valid = array( 'before_content', 'after_content', 'both', 'off' );
    return in_array( $value, $valid, true ) ? $value : 'before_content';
}

function cspv_sanitize_post_types( $value ) {
    if ( ! is_array( $value ) ) { return array( 'post' ); }
    return array_map( 'sanitize_key', $value );
}

function cspv_sanitize_display_style( $value ) {
    $valid = array( 'badge', 'pill', 'minimal' );
    return in_array( $value, $valid, true ) ? $value : 'badge';
}

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
 * Build the counter HTML (shared by both hooks).
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
         . '</span></div>';
}

/**
 * Inject counter ABOVE the post title on singular pages.
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
 * Inject counter below post content (after_content or both).
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

// -------------------------------------------------------------------------
// 4. Front end CSS for all three styles
// -------------------------------------------------------------------------
add_action( 'wp_head', 'cspv_auto_display_style', 100 );

function cspv_auto_display_style() {
    $position = get_option( 'cspv_auto_display', 'before_content' );
    if ( $position === 'off' ) { return; }

    $color = get_option( 'cspv_display_color', 'blue' );
    $colors = array(
        'blue'   => array( 'grad' => '#1a3a8f, #1e6fd9', 'solid' => '#1a3a8f', 'light_bg' => '#f0f6ff', 'light_border' => '#d0dfff', 'light_text' => '#1a3a8f', 'light_suffix' => '#5a7abf' ),
        'pink'   => array( 'grad' => '#db2777, #f472b6', 'solid' => '#db2777', 'light_bg' => '#fdf2f8', 'light_border' => '#fbcfe8', 'light_text' => '#be185d', 'light_suffix' => '#db2777' ),
        'red'    => array( 'grad' => '#b91c1c, #ef4444', 'solid' => '#b91c1c', 'light_bg' => '#fef2f2', 'light_border' => '#fecaca', 'light_text' => '#991b1b', 'light_suffix' => '#b91c1c' ),
        'purple' => array( 'grad' => '#6b21a8, #a855f7', 'solid' => '#6b21a8', 'light_bg' => '#faf5ff', 'light_border' => '#e9d5ff', 'light_text' => '#6b21a8', 'light_suffix' => '#7c3aed' ),
        'grey'   => array( 'grad' => '#4b5563, #9ca3af', 'solid' => '#4b5563', 'light_bg' => '#f9fafb', 'light_border' => '#e5e7eb', 'light_text' => '#374151', 'light_suffix' => '#6b7280' ),
    );
    $c = isset( $colors[ $color ] ) ? $colors[ $color ] : $colors['blue'];
    ?>
<style id="cspv-auto-display-style">
.cspv-auto-views { margin: 0 0 0.25em; line-height: 1; text-align: right; clear: both; }

/* Badge: gradient background, white text */
.cspv-ad-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, <?php echo $c['grad']; ?>);
    color: #fff;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .02em;
}
.cspv-ad-badge .cspv-ad-icon   { font-size: 15px; line-height: 1; }
.cspv-ad-badge .cspv-ad-num    { font-variant-numeric: tabular-nums; }
.cspv-ad-badge .cspv-ad-suffix { opacity: .85; font-weight: 500; font-size: 12px; }

/* Pill: light background, colored text */
.cspv-ad-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: <?php echo $c['light_bg']; ?>;
    border: 1px solid <?php echo $c['light_border']; ?>;
    color: <?php echo $c['light_text']; ?>;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
}
.cspv-ad-pill .cspv-ad-icon   { font-size: 14px; line-height: 1; }
.cspv-ad-pill .cspv-ad-num    { font-variant-numeric: tabular-nums; }
.cspv-ad-pill .cspv-ad-suffix { color: <?php echo $c['light_suffix']; ?>; font-weight: 500; font-size: 12px; }

/* Minimal: plain inline text */
.cspv-ad-minimal {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: <?php echo $c['solid']; ?>;
    font-size: 13px;
}
.cspv-ad-minimal .cspv-ad-icon   { line-height: 1; }
.cspv-ad-minimal .cspv-ad-num    { font-variant-numeric: tabular-nums; }
.cspv-ad-minimal .cspv-ad-suffix { font-size: 12px; }
</style>
    <?php
}
