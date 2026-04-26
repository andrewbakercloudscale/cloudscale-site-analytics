<?php
/**
 * CloudScale Analytics - Recent Posts Widget
 *
 * Paginated recent posts widget with CloudScale view counts.
 *
 * @package CloudScale_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Register widget
// -------------------------------------------------------------------------
add_action( 'widgets_init', function () {
    register_widget( 'CSPV_Recent_Posts_Widget' );
} );

// Enqueue widget CSS only on pages where the widget is active.
add_action( 'wp_enqueue_scripts', 'cspv_recent_posts_widget_enqueue' );

/**
 * Enqueue inline CSS for the Recent Posts widget.
 *
 * Only fires when the widget is active in at least one sidebar to avoid
 * injecting styles on every frontend page (PCP global-enqueue rule).
 *
 * @since 1.0.0
 * @return void
 */
function cspv_recent_posts_widget_enqueue() {
    if ( ! is_active_widget( false, false, 'cspv_recent_posts_widget' ) ) {
        return;
    }
    wp_register_style( 'cspv-recent-posts-widget', false, array(), CSPV_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- virtual handle
    wp_enqueue_style( 'cspv-recent-posts-widget' );
    wp_add_inline_style( 'cspv-recent-posts-widget', cspv_recent_posts_widget_css() );
}

// -------------------------------------------------------------------------
// 2. Widget class
// -------------------------------------------------------------------------
class CSPV_Recent_Posts_Widget extends WP_Widget {

    /**
     * Register the widget with WordPress.
     *
     * @since 1.0.0 */
    public function __construct() {
        parent::__construct(
            'cspv_recent_posts_widget',
            __( 'CloudScale Analytics: Recent Posts', 'cloudscale-wordpress-free-analytics' ),
            array(
                'description'            => __( 'Shows your latest posts with publication dates and view counts. Paginated with configurable post count.', 'cloudscale-wordpress-free-analytics' ),
                'show_instance_in_rest'  => true,
            )
        );
    }

    /**
     * Format a raw integer view count into a human-readable abbreviated string.
     *
     * @since 1.0.0
     * @param int $views Raw view count.
     * @return string Formatted string (e.g. "1.2k", "3.4M") or empty string for zero.
     */
    private function format_views( $views ) {
        $views = intval( $views );
        if ( $views <= 0 ) {
            return '';
        }
        if ( $views >= 1000000 ) {
            return round( $views / 1000000, 1 ) . 'M';
        }
        if ( $views >= 1000 ) {
            return round( $views / 1000, 1 ) . 'k';
        }
        return number_format( $views );
    }

    /**
     * Output the widget HTML on the frontend.
     *
     * @since 1.0.0
     * @param array $args     Widget display arguments (before/after widget/title).
     * @param array $instance Saved widget settings.
     * @return void
     */
    public function widget( $args, $instance ) {
        $title          = apply_filters( 'widget_title', $instance['title'] ?? __( 'Most Recent Posts', 'cloudscale-wordpress-free-analytics' ) );
        $posts_per_page = intval( $instance['posts_per_page'] ?? 10 );
        $show_date      = ! empty( $instance['show_date'] );
        $show_views     = ! empty( $instance['show_views'] );
        $meta_color     = ! empty( $instance['meta_color'] )     ? sanitize_hex_color( $instance['meta_color'] )     : '#c2410c';
        $meta_hover     = ! empty( $instance['meta_hover'] )     ? sanitize_hex_color( $instance['meta_hover'] )     : '#ea580c';
        $widget_id      = $args['widget_id'];
        $param_key      = 'cspv_rp_' . sanitize_key( $widget_id );
        $safe_id        = esc_attr( $widget_id );

        $page = isset( $_GET[ $param_key ] ) ? max( 1, absint( wp_unslash( $_GET[ $param_key ] ) ) ) : 1;

        $query = new WP_Query( array(
            'posts_per_page' => $posts_per_page,
            'paged'          => $page,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'no_found_rows'  => false,
        ) );

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard WP widget output

        if ( $title ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard WP widget output
        }

        if ( $query->have_posts() ) {
            // CSS is enqueued via cspv_recent_posts_widget_enqueue(); per-instance colours use CSS custom properties.
            echo '<ul class="cspv-rp-list" style="--cspv-meta-color:' . esc_attr( $meta_color ) . ';--cspv-meta-hover:' . esc_attr( $meta_hover ) . ';">';
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();

                echo '<li>';
                echo '<a href="' . esc_url( get_permalink() ) . '" class="cspv-rp-title">' . esc_html( get_the_title() ) . '</a>';

                $meta_parts = array();

                if ( $show_date ) {
                    $meta_parts[] = '<span class="cspv-rp-date">' . esc_html( get_the_date( 'd M Y' ) ) . '</span>';
                }

                if ( $show_views ) {
                    $views     = cspv_get_view_count( $post_id );
                    $formatted = $this->format_views( $views );
                    if ( $formatted !== '' ) {
                        $meta_parts[] = '<span class="cspv-rp-views">👁 ' . esc_html( $formatted ) . ' views</span>';
                    }
                }

                if ( ! empty( $meta_parts ) ) {
                    echo '<span class="cspv-rp-meta">' . wp_kses_post( implode( ' ', $meta_parts ) ) . '</span>';
                }

                echo '</li>';
            }
            echo '</ul>';

            $total_pages = $query->max_num_pages;

            if ( $total_pages > 1 ) {
                $base_url   = strtok( home_url( add_query_arg( array() ) ), '?' );
                $query_vars = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- used only for pagination URL construction, not for data processing

                echo '<div class="cspv-rp-pagination">';

                if ( $page > 1 ) {
                    $query_vars[ $param_key ] = $page - 1;
                    echo '<a class="cspv-rp-page cspv-rp-arrow" href="' . esc_url( $base_url . '?' . http_build_query( $query_vars ) ) . '">&laquo;</a>';
                }

                $start = max( 1, $page - 2 );
                $end   = min( $total_pages, $page + 2 );

                if ( $start > 1 ) {
                    $query_vars[ $param_key ] = 1;
                    echo '<a class="cspv-rp-page" href="' . esc_url( $base_url . '?' . http_build_query( $query_vars ) ) . '">1</a>';
                    if ( $start > 2 ) echo '<span class="cspv-rp-ellipsis">&hellip;</span>';
                }

                for ( $i = $start; $i <= $end; $i++ ) {
                    $query_vars[ $param_key ] = $i;
                    if ( $i === $page ) {
                        echo '<span class="cspv-rp-page cspv-rp-current">' . (int) $i . '</span>';
                    } else {
                        echo '<a class="cspv-rp-page" href="' . esc_url( $base_url . '?' . http_build_query( $query_vars ) ) . '">' . (int) $i . '</a>';
                    }
                }

                if ( $end < $total_pages ) {
                    if ( $end < $total_pages - 1 ) echo '<span class="cspv-rp-ellipsis">&hellip;</span>';
                    $query_vars[ $param_key ] = $total_pages;
                    echo '<a class="cspv-rp-page" href="' . esc_url( $base_url . '?' . http_build_query( $query_vars ) ) . '">' . (int) $total_pages . '</a>';
                }

                if ( $page < $total_pages ) {
                    $query_vars[ $param_key ] = $page + 1;
                    echo '<a class="cspv-rp-page cspv-rp-arrow" href="' . esc_url( $base_url . '?' . http_build_query( $query_vars ) ) . '">&raquo;</a>';
                }

                echo '</div>';
            }

            wp_reset_postdata();
        } else {
            echo '<p style="font-size:0.85em;color:#888;">' . esc_html__( 'No posts found.', 'cloudscale-wordpress-free-analytics' ) . '</p>';
        }

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard WP widget output
    }

    /**
     * Render the widget settings form in the Widgets admin screen.
     *
     * @since 1.0.0
     * @param array $instance Current saved widget settings.
     * @return void
     */
    public function form( $instance ) {
        $title          = $instance['title'] ?? __( 'Most Recent Posts', 'cloudscale-wordpress-free-analytics' );
        $posts_per_page = $instance['posts_per_page'] ?? 10;
        $show_date      = ! empty( $instance['show_date'] );
        $show_views     = isset( $instance['show_views'] ) ? (bool) $instance['show_views'] : true;
        $meta_color     = $instance['meta_color'] ?? '#c2410c';
        $meta_hover     = $instance['meta_hover'] ?? '#ea580c';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('title') ); ?>"><?php esc_html_e( 'Title:', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id('title') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('title') ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('posts_per_page') ); ?>"><?php esc_html_e( 'Posts per page:', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="tiny-text"
                   id="<?php echo esc_attr( $this->get_field_id('posts_per_page') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('posts_per_page') ); ?>"
                   type="number" min="1" max="50"
                   value="<?php echo esc_attr( $posts_per_page ); ?>">
        </p>
        <p>
            <input type="checkbox"
                   id="<?php echo esc_attr( $this->get_field_id('show_date') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('show_date') ); ?>"
                   <?php checked( $show_date ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id('show_date') ); ?>"><?php esc_html_e( 'Display post date', 'cloudscale-wordpress-free-analytics' ); ?></label>
        </p>
        <p>
            <input type="checkbox"
                   id="<?php echo esc_attr( $this->get_field_id('show_views') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('show_views') ); ?>"
                   <?php checked( $show_views ); ?>>
            <label for="<?php echo esc_attr( $this->get_field_id('show_views') ); ?>"><?php esc_html_e( 'Display view count', 'cloudscale-wordpress-free-analytics' ); ?></label>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('meta_color') ); ?>"><?php esc_html_e( 'Date & views colour:', 'cloudscale-wordpress-free-analytics' ); ?></label><br>
            <input id="<?php echo esc_attr( $this->get_field_id('meta_color') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('meta_color') ); ?>"
                   type="color"
                   value="<?php echo esc_attr( $meta_color ); ?>"
                   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
            <code style="font-size:11px;color:#666;"><?php echo esc_html( $meta_color ); ?></code>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('meta_hover') ); ?>"><?php esc_html_e( 'Date & views hover colour:', 'cloudscale-wordpress-free-analytics' ); ?></label><br>
            <input id="<?php echo esc_attr( $this->get_field_id('meta_hover') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('meta_hover') ); ?>"
                   type="color"
                   value="<?php echo esc_attr( $meta_hover ); ?>"
                   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
            <code style="font-size:11px;color:#666;"><?php echo esc_html( $meta_hover ); ?></code>
        </p>
        <?php
    }

    /**
     * Sanitise and save widget settings submitted from the form.
     *
     * @since 1.0.0
     * @param array $new_instance New settings submitted by the user.
     * @param array $old_instance Previous saved settings.
     * @return array Sanitised settings to persist.
     */
    public function update( $new_instance, $old_instance ) {
        return array(
            'title'          => sanitize_text_field( $new_instance['title'] ),
            'posts_per_page' => max( 1, intval( $new_instance['posts_per_page'] ) ),
            'show_date'      => ! empty( $new_instance['show_date'] ),
            'show_views'     => ! empty( $new_instance['show_views'] ),
            'meta_color'     => sanitize_hex_color( $new_instance['meta_color'] ?? '#c2410c' ) ?: '#c2410c',
            'meta_hover'     => sanitize_hex_color( $new_instance['meta_hover'] ?? '#ea580c' ) ?: '#ea580c',
        );
    }
}

// -------------------------------------------------------------------------
// 3. Widget CSS
// -------------------------------------------------------------------------
function cspv_recent_posts_widget_css() {
    return '
.cspv-rp-list{margin:0;padding:0;list-style:none;}
.cspv-rp-list li{display:flex;flex-direction:column;gap:3px;padding:8px 0;border-bottom:1px solid #f0f0f0;}
.cspv-rp-list li:last-child{border-bottom:none;}
.cspv-rp-title{font-size:16px;line-height:1.4;text-decoration:none;font-weight:600;color:#1a2332;word-break:break-word;}
.cspv-rp-title:hover{text-decoration:underline;color:#e8491d;}
.cspv-rp-meta{display:flex;align-items:center;justify-content:space-between;width:100%;font-size:14px;}
.cspv-rp-date{display:inline-flex;align-items:center;color:var(--cspv-meta-color,#c2410c);transition:color .15s;}
.cspv-rp-views{display:inline-flex;align-items:center;gap:3px;margin-left:auto;color:var(--cspv-meta-color,#c2410c);transition:color .15s;}
.cspv-rp-meta:hover .cspv-rp-date,.cspv-rp-meta:hover .cspv-rp-views{color:var(--cspv-meta-hover,#ea580c);}
.cspv-rp-pagination{margin-top:12px;display:flex;flex-wrap:wrap;align-items:center;gap:4px;}
.cspv-rp-page{display:inline-block;padding:5px 10px;border:1px solid #ddd;text-decoration:none;color:#333;font-size:13px;border-radius:4px;line-height:1;transition:all .15s;}
.cspv-rp-current{background:linear-gradient(135deg,#e8491d,#f27c1a);color:#fff!important;border-color:#e8491d;font-weight:bold;}
a.cspv-rp-page:hover{background:#fff3ee;border-color:#e8491d;color:#e8491d;text-decoration:none;}
.cspv-rp-arrow{font-size:15px;padding:4px 8px;}
.cspv-rp-ellipsis{padding:5px 4px;color:#999;font-size:13px;}
';
}
