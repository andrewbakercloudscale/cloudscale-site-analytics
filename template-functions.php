<?php
/**
 * CloudScale Analytics - Template Functions  v2.0.0
 *
 * USAGE GUIDE
 * ===========
 *
 * ── WHERE TO ADD IT IN YOUR THEME ───────────────────────────────────────
 *
 * Open your theme's single.php (or single-post.php if it exists).
 * Look for the line that outputs the post title or post meta — something like:
 *
 *     <h1 class="entry-title"><?php the_title(); ?></h1>
 *
 * Add the view counter directly below the title, or near your post meta
 * (date, author, categories etc):
 *
 *     <div class="entry-meta">
 *         <?php the_date(); ?> · <?php the_author(); ?>
 *         <?php cspv_the_views(); ?>    ← add this line
 *     </div>
 *
 * That outputs:  👁 1,234 views
 *
 * If your theme uses a parts file (e.g. template-parts/content-single.php)
 * add it there instead — it will be in the same folder as single.php.
 *
 * No other changes needed. The plugin automatically:
 *   1. Shows the stored count immediately (no layout shift).
 *   2. Records the view via one background request after the page loads.
 *   3. Updates the count in place from that same response — no second call.
 *
 * ── CUSTOMISE THE OUTPUT ────────────────────────────────────────────────
 *
 * Change the icon or surrounding HTML:
 *
 *     <?php cspv_the_views( array(
 *         'icon'    => '📖',          // any emoji or '' to hide
 *         'suffix'  => ' reads',      // change " views" to anything
 *         'post_id' => get_the_ID(),  // defaults to current post
 *     ) ); ?>
 *
 * Wrap in your own HTML (disables the built-in wrapper):
 *
 *     <?php cspv_the_views( array(
 *         'before' => '<span class="my-meta-item">',
 *         'after'  => '</span>',
 *     ) ); ?>
 *
 * ── ARCHIVE / LISTING TEMPLATES ─────────────────────────────────────────
 *
 * Inside The Loop on home.php, archive.php, category.php etc:
 *
 *     <span class="cspv-views-count" data-cspv-id="<?php the_ID(); ?>">
 *         <?php echo cspv_get_view_count(); ?>
 *     </span>
 *
 * One background request fetches all counts on the page at once.
 *
 * ── GET THE RAW NUMBER IN PHP ────────────────────────────────────────────
 *
 *     $views = cspv_get_view_count();            // current post in loop
 *     $views = cspv_get_view_count( $post_id );  // specific post
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the view count for a post as an integer.
 *
 * @since 1.0.0
 * @param  int|null $post_id  Post ID, or null for current post in The Loop.
 * @return int
 */
function cspv_get_view_count( $post_id = null ) {
    if ( $post_id === null ) {
        $post_id = get_the_ID();
    }
    $post_id = absint( $post_id );
    if ( ! $post_id ) { return 0; }

    return (int) get_post_meta( $post_id, CSPV_META_KEY, true );
}

/**
 * Output the view count with an eye icon.
 *
 * Default output:   👁 1,234 views
 *
 * On single post templates this is all you need — the beacon updates
 * the count automatically after recording the view, with no second API call.
 *
 * @since 1.0.0
 * @param array $args {
 *     @type string   $icon     Icon to show before the count. Default '👁'.
 *                              Pass '' to hide.
 *     @type string   $suffix   Text after the count. Default ' views'.
 *     @type string   $before   HTML wrapper opening tag.
 *     @type string   $after    HTML wrapper closing tag.
 *     @type int|null $post_id  Post ID. Defaults to current post.
 * }
 * @return void
 */
function cspv_the_views( $args = array() ) {
    $defaults = array(
        'icon'    => '👁',
        'suffix'  => ' views',
        'before'  => '<span class="cspv-views-count">',
        'after'   => '</span>',
        'post_id' => null,
    );
    $args  = wp_parse_args( $args, $defaults );
    $count = cspv_get_view_count( $args['post_id'] );

    $icon   = ! empty( $args['icon'] )   ? '<span class="cspv-views-icon" aria-hidden="true">' . esc_html( $args['icon'] ) . '</span>' : '';
    $suffix = ! empty( $args['suffix'] ) ? '<span class="cspv-views-suffix">' . esc_html( $args['suffix'] ) . '</span>' : '';

    echo wp_kses_post( $args['before'] );
    echo wp_kses_post( $icon );
    echo '<span class="cspv-views-number">' . esc_html( number_format( $count ) ) . '</span>';
    echo wp_kses_post( $suffix );
    echo wp_kses_post( $args['after'] );
}

/**
 * Return the view count HTML as a string.
 *
 * @since 1.0.0
 * @param  array $args  Same as cspv_the_views().
 * @return string
 */
function cspv_get_views_html( $args = array() ) {
    ob_start();
    cspv_the_views( $args );
    return ob_get_clean();
}

// Output a small stylesheet once per page so the icon and number
// sit neatly together without the theme needing any CSS changes.
add_action( 'wp_enqueue_scripts', 'cspv_views_inline_style', 99 );

/**
 * Enqueue the inline CSS for the view counter display.
 *
 * @since 1.0.0
 * @return void
 */
function cspv_views_inline_style() {
    // Only enqueue where the counter is likely displayed.
    if ( ! is_singular() && ! is_home() && ! is_front_page() && ! is_archive() && ! is_search() ) {
        return;
    }
    $css = '.cspv-views-count{display:inline-flex;align-items:center;gap:4px;font-size:.875em;color:#6b7280;white-space:nowrap;}'
         . '.cspv-views-icon{line-height:1;font-style:normal;}'
         . '.cspv-views-number{font-variant-numeric:tabular-nums;}'
         . '.cspv-views-suffix{font-size:.9em;}';
    wp_register_style( 'cspv-views', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-views' );
    wp_add_inline_style( 'cspv-views', $css );
}
