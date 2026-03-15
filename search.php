<?php
/**
 * Lightweight Analytics: Search Widget
 *
 * Replaces Relevanssi on the frontend with a LIKE '%term%' wildcard search.
 * Renders a search form widget (Appearance > Widgets > "Lightweight Analytics: Search").
 * Results are capped at 50 posts, ordered newest-first.
 * Thumbnails are forced to 'thumbnail' size on search-result pages.
 *
 * @package Lightweight_WordPress_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Register widget
// -------------------------------------------------------------------------
add_action( 'widgets_init', function () {
	register_widget( 'CSPV_Search_Widget' );
} );

// Enqueue widget CSS only on pages where the widget is active.
add_action( 'wp_enqueue_scripts', 'cspv_search_widget_enqueue' );

/**
 * Enqueue inline CSS for the Search widget.
 *
 * Only fires when the widget is active to avoid injecting styles on every page.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_search_widget_enqueue() {
	if ( ! is_active_widget( false, false, 'cspv_search_widget' ) ) {
		return;
	}
	wp_register_style( 'cspv-search-widget', false );
	wp_enqueue_style( 'cspv-search-widget' );
	wp_add_inline_style( 'cspv-search-widget', cspv_search_widget_css() );
}

// -------------------------------------------------------------------------
// 2. Widget class
// -------------------------------------------------------------------------
/**
 * Search widget that replaces Relevanssi with a LIKE-based wildcard search.
 *
 * @since 2.9.94 */
class CSPV_Search_Widget extends WP_Widget {

	/**
	 * Register the widget with WordPress.
	 *
	 * @since 2.9.94 */
	public function __construct() {
		parent::__construct(
			'cspv_search_widget',
			__( 'Lightweight Analytics: Search', 'lightweight-wordpress-free-analytics' ),
			array(
				'description'           => __( 'Wildcard search form. Finds posts by LIKE match on title, content and excerpt — short terms like "AI" work correctly. Results capped at 50.', 'lightweight-wordpress-free-analytics' ),
				'show_instance_in_rest' => true,
			)
		);
	}

	/**
	 * Output the widget HTML on the frontend.
	 *
	 * @since 2.9.94
	 * @param array $args     Widget display arguments (before/after widget/title).
	 * @param array $instance Saved widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$title       = ! empty( $instance['title'] )       ? $instance['title']       : __( 'Search', 'lightweight-wordpress-free-analytics' );
		$placeholder = ! empty( $instance['placeholder'] ) ? $instance['placeholder'] : __( 'Search posts…', 'lightweight-wordpress-free-analytics' );
		$btn_color   = ! empty( $instance['btn_color'] )   ? sanitize_hex_color( $instance['btn_color'] )   : '#e8491d';
		$btn_hover   = ! empty( $instance['btn_hover'] )   ? sanitize_hex_color( $instance['btn_hover'] )   : '#f27c1a';

		echo $args['before_widget'];
		echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
		?>
		<form role="search" method="get" class="cspv-search-form"
			  style="--cspv-btn-color:<?php echo esc_attr( $btn_color ); ?>;--cspv-btn-hover:<?php echo esc_attr( $btn_hover ); ?>;"
			  action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="cspv-search-<?php echo esc_attr( $args['widget_id'] ); ?>">
				<?php echo esc_html( $title ); ?>
			</label>
			<input type="search"
				   id="cspv-search-<?php echo esc_attr( $args['widget_id'] ); ?>"
				   class="cspv-search-input"
				   name="s"
				   placeholder="<?php echo esc_attr( $placeholder ); ?>"
				   value="<?php echo esc_attr( get_search_query() ); ?>">
			<button type="submit" class="cspv-search-btn">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
					<path fill-rule="evenodd" d="M9 3a6 6 0 1 0 3.72 10.72l3.28 3.28a1 1 0 0 0 1.42-1.42l-3.28-3.28A6 6 0 0 0 9 3Zm-4 6a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd"/>
				</svg>
				<span class="screen-reader-text"><?php esc_html_e( 'Search', 'lightweight-wordpress-free-analytics' ); ?></span>
			</button>
		</form>
		<?php
		echo $args['after_widget'];
	}

	/**
	 * Render the widget settings form in the Widgets admin screen.
	 *
	 * @since 2.9.94
	 * @param array $instance Current saved widget settings.
	 * @return void
	 */
	public function form( $instance ) {
		$title       = $instance['title']       ?? __( 'Search', 'lightweight-wordpress-free-analytics' );
		$placeholder = $instance['placeholder'] ?? __( 'Search posts…', 'lightweight-wordpress-free-analytics' );
		$btn_color   = $instance['btn_color']   ?? '#e8491d';
		$btn_hover   = $instance['btn_hover']   ?? '#f27c1a';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'lightweight-wordpress-free-analytics' ); ?></label>
			<input class="widefat"
				   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				   type="text"
				   value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>"><?php esc_html_e( 'Input placeholder:', 'lightweight-wordpress-free-analytics' ); ?></label>
			<input class="widefat"
				   id="<?php echo esc_attr( $this->get_field_id( 'placeholder' ) ); ?>"
				   name="<?php echo esc_attr( $this->get_field_name( 'placeholder' ) ); ?>"
				   type="text"
				   value="<?php echo esc_attr( $placeholder ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'btn_color' ) ); ?>"><?php esc_html_e( 'Button colour:', 'lightweight-wordpress-free-analytics' ); ?></label><br>
			<input id="<?php echo esc_attr( $this->get_field_id( 'btn_color' ) ); ?>"
				   name="<?php echo esc_attr( $this->get_field_name( 'btn_color' ) ); ?>"
				   type="color"
				   value="<?php echo esc_attr( $btn_color ); ?>"
				   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
			<code style="font-size:11px;color:#666;"><?php echo esc_html( $btn_color ); ?></code>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'btn_hover' ) ); ?>"><?php esc_html_e( 'Button hover colour:', 'lightweight-wordpress-free-analytics' ); ?></label><br>
			<input id="<?php echo esc_attr( $this->get_field_id( 'btn_hover' ) ); ?>"
				   name="<?php echo esc_attr( $this->get_field_name( 'btn_hover' ) ); ?>"
				   type="color"
				   value="<?php echo esc_attr( $btn_hover ); ?>"
				   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
			<code style="font-size:11px;color:#666;"><?php echo esc_html( $btn_hover ); ?></code>
		</p>
		<?php
	}

	/**
	 * Sanitise and save widget settings submitted from the form.
	 *
	 * @since 2.9.94
	 * @param array $new_instance New settings submitted by the user.
	 * @param array $old_instance Previous saved settings.
	 * @return array Sanitised settings to persist.
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'       => sanitize_text_field( $new_instance['title'] ),
			'placeholder' => sanitize_text_field( $new_instance['placeholder'] ),
			'btn_color'   => sanitize_hex_color( $new_instance['btn_color'] ?? '#e8491d' ) ?: '#e8491d',
			'btn_hover'   => sanitize_hex_color( $new_instance['btn_hover'] ?? '#f27c1a' ) ?: '#f27c1a',
		);
	}
}

// -------------------------------------------------------------------------
// 3. Widget CSS
// -------------------------------------------------------------------------
/**
 * Return the inline CSS for the search widget form.
 *
 * @since 2.9.94
 * @return string CSS string.
 */
function cspv_search_widget_css() {
	return '
.cspv-search-form{display:flex;gap:0;width:100%;}
.cspv-search-input{flex:1;min-width:0;padding:8px 12px;font-size:14px;border:2px solid #ddd;border-right:none;border-radius:4px 0 0 4px;outline:none;transition:border-color .15s;}
.cspv-search-input:focus{border-color:var(--cspv-btn-color,#e8491d);}
.cspv-search-btn{display:inline-flex;align-items:center;justify-content:center;padding:0 14px;background:var(--cspv-btn-color,#e8491d);border:2px solid var(--cspv-btn-color,#e8491d);border-radius:0 4px 4px 0;color:#fff;cursor:pointer;transition:background .15s,border-color .15s;line-height:1;}
.cspv-search-btn:hover{background:var(--cspv-btn-hover,#f27c1a);border-color:var(--cspv-btn-hover,#f27c1a);}
';
}

// -------------------------------------------------------------------------
// 4. Wildcard search — bypasses Relevanssi entirely via posts_pre_query
// -------------------------------------------------------------------------

/**
 * Run our own LIKE SQL and return results directly, before WordPress (and
 * Relevanssi) execute their queries. Priority 999 ensures we run last so
 * we always win regardless of what priority Relevanssi registered at.
 *
 * Ranking: title matches first, then content/excerpt matches, newest-first
 * within each group. Results capped at 50.
 *
 * @since 2.9.94
 * @param array|null $posts Existing pre-query result (null = not yet handled).
 * @param WP_Query   $query
 * @return array|null
 */
add_filter( 'posts_pre_query', 'cspv_wildcard_posts_pre_query', 999, 2 );

function cspv_wildcard_posts_pre_query( $posts, $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return $posts;
	}

	$term = trim( $query->get( 's' ) );
	if ( $term === '' ) {
		return $posts;
	}

	// Remove Relevanssi from the_posts at every priority it is known to use.
	remove_filter( 'the_posts', 'relevanssi_filter_the_posts', 10 );
	remove_filter( 'the_posts', 'relevanssi_filter_the_posts', 99 );

	global $wpdb;

	$esc  = $wpdb->esc_like( $term );
	$like = '%' . $esc . '%';

	// Tier-0 patterns: exact-case term as a standalone word in the title.
	// Covers space-bounded, start/end of title, and common punctuation after term.
	$t0_word  = '% ' . $esc . ' %';
	$t0_start = $esc . ' %';
	$t0_end   = '% ' . $esc;
	$t0_colon = '% ' . $esc . ':%';
	$t0_dot   = '% ' . $esc . '.%';
	$t0_comma = '% ' . $esc . ',%';
	$t0_exact = $esc;

	// Standard WP pattern: get_results( prepare( ... ) ) — prepare() returns safe SQL string, get_results() executes it.
	$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
		$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a trusted core table name
			"SELECT {$wpdb->posts}.*
			 FROM {$wpdb->posts}
			 WHERE {$wpdb->posts}.post_status = 'publish'
			   AND {$wpdb->posts}.post_type NOT IN (
			       'revision','nav_menu_item','attachment','custom_css',
			       'customize_changeset','oembed_cache','user_request',
			       'wp_block','wp_template','wp_template_part',
			       'wp_global_styles','wp_navigation'
			   )
			   AND (
			       {$wpdb->posts}.post_title   LIKE %s
			    OR {$wpdb->posts}.post_content LIKE %s
			    OR {$wpdb->posts}.post_excerpt LIKE %s
			   )
			 ORDER BY
			   CASE
			     -- Tier 0: exact-case term as a standalone word in the title
			     WHEN (    BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title LIKE %s
			            OR BINARY {$wpdb->posts}.post_title  = %s
			          ) THEN 0
			     -- Tier 1: exact-case term anywhere in the title
			     WHEN BINARY {$wpdb->posts}.post_title LIKE %s THEN 1
			     -- Tier 2: case-insensitive match in title (e.g. 'ai' inside 'waiting')
			     WHEN {$wpdb->posts}.post_title LIKE %s THEN 2
			     -- Tier 3: match is only in content/excerpt, not in title
			     ELSE 3
			   END,
			   {$wpdb->posts}.post_date DESC
			 LIMIT 50",
			// WHERE (3)
			$like, $like, $like,
			// Tier 0 (7)
			$t0_word, $t0_start, $t0_end, $t0_colon, $t0_dot, $t0_comma, $t0_exact,
			// Tier 1 (1)
			$like,
			// Tier 2 (1)
			$like
		)
	);

	$results = is_array( $results ) ? $results : array();

	$query->found_posts   = count( $results );
	$query->max_num_pages = 1;

	return $results;
}

/**
 * Force 'thumbnail' image size on search-result pages so full-size
 * infographics are not rendered in the results list.
 *
 * @since 2.9.94
 * @param string|int[] $size
 * @return string|int[]
 */
add_filter( 'post_thumbnail_size', 'cspv_search_thumbnail_size' );

function cspv_search_thumbnail_size( $size ) {
	if ( is_search() ) {
		return 'thumbnail';
	}
	return $size;
}
