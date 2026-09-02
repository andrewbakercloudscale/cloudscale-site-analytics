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
 * Look for the line that outputs the post title or post meta, something like:
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
 * add it there instead, it will be in the same folder as single.php.
 *
 * No other changes needed. The plugin automatically:
 *   1. Shows the stored count immediately (no layout shift).
 *   2. Records the view via one background request after the page loads.
 *   3. Updates the count in place from that same response, no second call.
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
 * @package CloudScale_Site_Analytics
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
 * Get the narration play count for a post as an integer.
 *
 * The audio equivalent of cspv_get_view_count(): "how many people pressed play on
 * this article's narration", deduped per listener per 24h by the beacon exactly as
 * views are, so the two numbers mean comparable things and can sit side by side.
 *
 * READS A META COUNTER, NOT THE BUCKET TABLE. cs_analytics_audio_v2 keeps one row per
 * post per hour and remains the record of truth (the stats pages and the SEO plugin's
 * editor line both aggregate it). A public template cannot afford that: a listing page
 * renders a player per result, and one SUM per player is a query per player on a page
 * a reader is waiting for. So the REST callback increments a denormalised meta counter
 * alongside the bucket write, and this reads it — the same trade views already make.
 *
 * BACKFILL. Posts narrated before the counter existed have no meta, which would print a
 * confident 0 under an article with real listeners. The first read sums the bucket table
 * once and stores the result, so an existing site starts from its true total rather than
 * from zero. Stored even when the sum is 0, so the backfill runs once and not on every
 * render of an unplayed post ('' means never looked, '0' means looked and found none).
 *
 * @since 2.9.496
 * @param  int|null $post_id  Post ID, or null for current post in The Loop.
 * @return int
 */
function cspv_get_audio_play_count( $post_id = null ) {
    if ( $post_id === null ) {
        $post_id = get_the_ID();
    }
    $post_id = absint( $post_id );
    if ( ! $post_id ) { return 0; }

    $raw = get_post_meta( $post_id, CSPV_AUDIO_META_KEY, true );
    if ( '' !== $raw && null !== $raw ) {
        return (int) $raw;
    }

    $total = 0;
    global $wpdb;
    $table = $wpdb->prefix . 'cs_analytics_audio_v2';
    if ( function_exists( 'cspv_table_exists' ) && cspv_table_exists( $table ) ) {
        // The table name comes from $wpdb->prefix and a literal — it cannot be a
        // placeholder — and post_id IS bound. One indexed aggregate that runs at most
        // once per post, after which the meta counter answers, so there is nothing to
        // cache. A disable/enable pair rather than a phpcs:ignore because the offending
        // token is the string on its own line INSIDE the call: an ignore on the line
        // above covers that line only, which is how this first failed the build.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(plays),0) FROM `{$table}` WHERE post_id = %d",
            $post_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
    update_post_meta( $post_id, CSPV_AUDIO_META_KEY, $total );

    return $total;
}

/**
 * Output the narration play count as a compact inline counter.
 *
 * Marked up so the beacon can bump it in place the moment the listener presses play
 * (data-cspv-audio-id), which matters more here than it does for views: the page is
 * very likely served from a CDN cache, so the printed number is as old as the cached
 * HTML and the reader's own play would otherwise not show up at all.
 *
 * @since 2.9.496
 * @param array $args {
 *     @type string   $icon     Icon before the count. Default '▶'. Pass '' to hide.
 *     @type string   $suffix   Text after the count. Default ' plays'. Pluralised.
 *     @type int|null $post_id  Post ID. Defaults to current post.
 * }
 * @return void
 */
function cspv_the_audio_plays( $args = array() ) {
    // NOT run through wp_kses_post(). KSES strips data-* attributes unless the element's
    // allow-list names 'data-*' explicitly, and data-cspv-audio-id is what the beacon uses
    // to find this counter and repaint it — filtered markup would render a number that
    // silently never updates. Every value is escaped where it is built, below.
    echo cspv_audio_plays_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction in cspv_audio_plays_html()
}

/**
 * The markup cspv_the_audio_plays() prints, as a string.
 *
 * Separate from the echoing wrapper because the callers that need this most are
 * building a player's markup as a string (the SEO plugin's figure), not printing
 * inside a template.
 *
 * @since 2.9.496
 * @param array $args See cspv_the_audio_plays().
 * @return string Markup. A count of zero is PRINTED, not hidden: "nobody has played
 *                this yet" is information, and an element that only appears once the
 *                number is non-zero cannot be updated in place when the reader's own
 *                play is the first one.
 */
function cspv_audio_plays_html( $args = array() ) {
    $defaults = array(
        'icon'    => '&#9654;',
        'suffix'  => ' plays',
        'post_id' => null,
    );
    $args    = wp_parse_args( $args, $defaults );
    $post_id = $args['post_id'] === null ? (int) get_the_ID() : absint( $args['post_id'] );
    $count   = cspv_get_audio_play_count( $post_id );

    // Singular when the number IS one, including after the beacon bumps 0 to 1 in the
    // browser — hence data-cspv-audio-suffix carrying both forms rather than one baked
    // string, so "1 plays" never appears.
    $many   = (string) $args['suffix'];
    $one    = ' ' . rtrim( trim( $many ), 's' );
    $suffix = ( 1 === $count ) ? $one : $many;

    // esc_html on a caller-supplied icon would print '&#9654;' as literal text, so the
    // default is decoded to its character first and whatever a caller passes is escaped.
    // A theme is a trusted caller, but "trusted" is not a property this function can check.
    $icon_txt = html_entity_decode( (string) $args['icon'], ENT_QUOTES, 'UTF-8' );
    $icon     = ( '' !== $icon_txt )
        ? '<span class="cspv-plays-icon" aria-hidden="true">' . esc_html( $icon_txt ) . '</span>'
        : '';

    return '<span class="cspv-plays-count" data-cspv-audio-id="' . esc_attr( (string) $post_id ) . '"'
        . ' data-cspv-audio-suffix="' . esc_attr( $many ) . '"'
        . ' data-cspv-audio-suffix-one="' . esc_attr( $one ) . '">'
        . $icon
        . '<span class="cspv-plays-number">' . esc_html( number_format_i18n( $count ) ) . '</span>'
        . '<span class="cspv-plays-suffix">' . esc_html( $suffix ) . '</span>'
        . '</span>';
}

/**
 * Output the view count with an eye icon.
 *
 * Default output:   👁 1,234 views
 *
 * On single post templates this is all you need, the beacon updates
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
         . '.cspv-views-suffix{font-size:.9em;}'
         // The play counter sits INSIDE a player card rather than in a meta row, so it
         // is sized down and the number is tabular: it changes under the reader's eyes
         // when their own play is recorded, and proportional digits make that a jump.
         . '.cspv-plays-count{display:inline-flex;align-items:center;gap:3px;font-size:.8em;font-weight:700;'
         . 'color:#475569;white-space:nowrap;font-variant-numeric:tabular-nums;letter-spacing:0;text-transform:none;}'
         . '.cspv-plays-icon{line-height:1;font-style:normal;font-size:.85em;}'
         . '.cspv-plays-number{font-variant-numeric:tabular-nums;}';
    wp_register_style( 'cspv-views', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-views' );
    wp_add_inline_style( 'cspv-views', $css );
}
