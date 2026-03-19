<?php
/**
 * CloudScale Analytics - Top Posts Widget
 *
 * Sidebar widget that displays a paginated list of top posts ranked by
 * view count from the cspv_views log table, with thumbnail, date and
 * formatted view count.
 *
 * @package CloudScale_WordPress_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue widget CSS/JS only on pages where the widget is active.
add_action( 'wp_enqueue_scripts', 'cspv_top_posts_widget_enqueue' );

/**
 * Enqueue inline CSS and JS for the Top Posts widget.
 *
 * Only fires when the widget is active in at least one sidebar to avoid
 * injecting styles and scripts on every frontend page (PCP global-enqueue rule).
 *
 * @since 1.0.0
 * @return void
 */
function cspv_top_posts_widget_enqueue() {
    if ( ! is_active_widget( false, false, 'cspv_top_posts_widget' ) ) {
        return;
    }
    wp_register_style( 'cspv-top-posts-widget', false );
    wp_enqueue_style( 'cspv-top-posts-widget' );
    wp_add_inline_style( 'cspv-top-posts-widget', cspv_top_posts_widget_css() );

    wp_register_script( 'cspv-top-posts-widget', false, array(), CSPV_VERSION, true );
    wp_enqueue_script( 'cspv-top-posts-widget' );
}

// -------------------------------------------------------------------------
// 1. Query helper: get top posts ranked by CloudScale view data
// -------------------------------------------------------------------------
/**
 * Return top posts ranked by view data.
 *
 * @since 1.0.0
 * @param int    $total       Maximum number of posts to return.
 * @param string $order_by    Sort field: 'views' or 'date'.
 * @param int    $view_window Days to look back; -1 for all time.
 * @return array Array of { post, views } pairs.
 */
function cspv_get_top_posts( $total, $order_by, $view_window = -1 ) {

    // --- Rank by views ---
    if ( $order_by === 'views' ) {
        global $wpdb;
        $table = cspv_views_table();
        $cnt   = cspv_count_expr();

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        // Determine if we are in the transition period.
        // If the log table has fewer than view_window days of data,
        // we blend lifetime meta with beacon counts and rank by total.
        $in_transition = false;

        if ( $table_exists && $view_window > 0 ) {
            $earliest = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name
            if ( $earliest ) {
                $log_days = (int) floor( ( time() - strtotime( $earliest ) ) / 86400 );
            } else {
                $log_days = 0;
            }
            $in_transition = ( $log_days < $view_window );
        }

        // ── Transition period: blend meta + beacon, rank by combined total ──
        if ( $in_transition ) {
            $since       = gmdate( 'Y-m-d H:i:s', strtotime( "-{$view_window} days" ) );
            $beacon_rows = $table_exists ? $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                "SELECT post_id, {$cnt} AS cnt FROM `{$table}` WHERE viewed_at >= %s GROUP BY post_id",
                $since
            ) ) : array();

            $beacon_map = array();
            foreach ( (array) $beacon_rows as $r ) {
                $beacon_map[ absint( $r->post_id ) ] = (int) $r->cnt;
            }

            // Get all posts with lifetime meta > 0
            $meta_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- no user input; fully hardcoded query
                "SELECT p.ID, CAST(pm.meta_value AS UNSIGNED) AS lifetime
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'post'
                   AND p.post_status = 'publish'
                   AND pm.meta_key = '_cspv_view_count'
                   AND CAST(pm.meta_value AS UNSIGNED) > 0
                 ORDER BY lifetime DESC
                 LIMIT 500"
            );

            // Rank by lifetime meta (already includes Jetpack + tracked views).
            // Do NOT add beacon count here — meta already contains tracked views
            // from rest-api.php, so adding beacon would double count them.
            $combined = array();
            foreach ( (array) $meta_rows as $r ) {
                $pid = absint( $r->ID );
                $combined[ $pid ] = (int) $r->lifetime;
            }
            // Add beacon-only posts not in meta
            foreach ( $beacon_map as $pid => $cnt ) {
                if ( ! isset( $combined[ $pid ] ) ) {
                    if ( get_post_type( $pid ) === 'post' && get_post_status( $pid ) === 'publish' ) {
                        $combined[ $pid ] = $cnt;
                    }
                }
            }

            arsort( $combined );
            $top_ids = array_slice( array_keys( $combined ), 0, $total, true );

            if ( ! empty( $top_ids ) ) {
                $q = new WP_Query( array(
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'post__in'       => $top_ids,
                    'orderby'        => 'post__in',
                    'posts_per_page' => count( $top_ids ),
                    'no_found_rows'  => true,
                ) );
                $result = array();
                foreach ( $q->posts as $p ) {
                    $result[] = array(
                        'post'  => $p,
                        'views' => isset( $combined[ $p->ID ] ) ? $combined[ $p->ID ] : 0,
                    );
                }
                wp_reset_postdata();
                return $result;
            }
        }

        // ── Normal mode: full window of data, rank by beacon only ───────
        if ( $table_exists ) {
            if ( $view_window > 0 ) {
                $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$view_window} days" ) );
                $ranked = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    "SELECT post_id, {$cnt} AS view_count
                     FROM `{$table}`
                     WHERE viewed_at >= %s
                     GROUP BY post_id
                     ORDER BY view_count DESC
                     LIMIT %d",
                    $since,
                    $total * 2
                ) );
            } else {
                $ranked = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
                    "SELECT post_id, {$cnt} AS view_count
                     FROM `{$table}`
                     GROUP BY post_id
                     ORDER BY view_count DESC
                     LIMIT %d",
                    $total * 2
                ) );
            }

            if ( ! empty( $ranked ) ) {
                $ids          = array();
                $window_views = array();
                foreach ( $ranked as $row ) {
                    $pid = absint( $row->post_id );
                    if ( $pid > 0 && get_post_type( $pid ) === 'post' && get_post_status( $pid ) === 'publish' ) {
                        $ids[] = $pid;
                        $window_views[ $pid ] = (int) $row->view_count;
                        if ( count( $ids ) >= $total ) break;
                    }
                }

                if ( ! empty( $ids ) ) {
                    $q = new WP_Query( array(
                        'post_type'      => 'post',
                        'post_status'    => 'publish',
                        'post__in'       => $ids,
                        'orderby'        => 'post__in',
                        'posts_per_page' => count( $ids ),
                        'no_found_rows'  => true,
                    ) );
                    $result = array();
                    foreach ( $q->posts as $p ) {
                        $result[] = array(
                            'post'  => $p,
                            'views' => isset( $window_views[ $p->ID ] ) ? $window_views[ $p->ID ] : 0,
                        );
                    }
                    wp_reset_postdata();
                    return $result;
                }
            }
        }

        // Fallback: rank by meta if log table empty or missing
        $meta_fallback = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted core table names
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts/$wpdb->postmeta are trusted core table names
                "SELECT p.ID, CAST(pm.meta_value AS UNSIGNED) AS total_views
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'post'
                   AND p.post_status = 'publish'
                   AND pm.meta_key = '_cspv_view_count'
                   AND CAST(pm.meta_value AS UNSIGNED) > 0
                 ORDER BY total_views DESC
                 LIMIT %d",
                absint( $total )
            )
        );
        if ( ! empty( $meta_fallback ) ) {
            $fb_ids   = array();
            $fb_views = array();
            foreach ( $meta_fallback as $r ) {
                $fb_ids[] = absint( $r->ID );
                $fb_views[ absint( $r->ID ) ] = (int) $r->total_views;
            }
            $q = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'post__in'       => $fb_ids,
                'orderby'        => 'post__in',
                'posts_per_page' => count( $fb_ids ),
                'no_found_rows'  => true,
            ) );
            $result = array();
            foreach ( $q->posts as $p ) {
                $result[] = array(
                    'post'  => $p,
                    'views' => isset( $fb_views[ $p->ID ] ) ? $fb_views[ $p->ID ] : 0,
                );
            }
            wp_reset_postdata();
            if ( ! empty( $result ) ) {
                return $result;
            }
        }
    }

    // --- Fallback: most recent ---
    $q = new WP_Query( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $total,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ) );
    $result = array();
    foreach ( $q->posts as $p ) {
        $result[] = array( 'post' => $p, 'views' => cspv_get_view_count( $p->ID ) );
    }
    wp_reset_postdata();
    return $result;
}

// -------------------------------------------------------------------------
// 2. Register widget
// -------------------------------------------------------------------------
add_action( 'widgets_init', function () {
    register_widget( 'CSPV_Top_Posts_Widget' );
} );

// -------------------------------------------------------------------------
// 3. Widget class
// -------------------------------------------------------------------------
class CSPV_Top_Posts_Widget extends WP_Widget {

    /**
     * Register the widget with WordPress.
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct(
            'cspv_top_posts_widget',
            __( 'CloudScale Analytics: Top Posts', 'cloudscale-wordpress-free-analytics' ),
            array(
                'description'            => __( 'Shows your most viewed posts with thumbnails, dates and view counts. Paginated with configurable post count and sort order.', 'cloudscale-wordpress-free-analytics' ),
                'show_instance_in_rest'  => true,
            )
        );
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
        $title        = ! empty( $instance['title'] )        ? $instance['title']               : __( 'Top Posts', 'cloudscale-wordpress-free-analytics' );
        $total_posts  = isset( $instance['total_posts'] )    ? (int) $instance['total_posts']    : 20;
        $posts_per_pg = isset( $instance['posts_per_page'] ) ? (int) $instance['posts_per_page'] : 5;
        $image_width  = isset( $instance['image_width'] )    ? (int) $instance['image_width']    : 150;
        $order_by     = isset( $instance['order_by'] )       ? $instance['order_by']             : 'views';
        $view_window  = isset( $instance['view_window'] )    ? (int) $instance['view_window']     : 28;
        $meta_color   = ! empty( $instance['meta_color'] )   ? sanitize_hex_color( $instance['meta_color'] ) : '#c2410c';
        $meta_hover   = ! empty( $instance['meta_hover'] )   ? sanitize_hex_color( $instance['meta_hover'] ) : '#ea580c';

        $uid = 'cspv_tp_' . preg_replace( '/[^a-zA-Z0-9]/', '_', $args['widget_id'] );
        $safe_wid = esc_attr( $args['widget_id'] );

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( $title ) . $args['after_title'];

        $posts_arr = cspv_get_top_posts( $total_posts, $order_by, $view_window );

        if ( empty( $posts_arr ) ) {
            echo '<p style="font-size:0.85em;color:#888;">' . esc_html__( 'No published posts found.', 'cloudscale-wordpress-free-analytics' ) . '</p>';
            echo $args['after_widget'];
            return;
        }

        $total     = count( $posts_arr );
        $pages_cnt = (int) ceil( $total / $posts_per_pg );

        $post_data = array();
        foreach ( $posts_arr as $item ) {
            $p     = $item['post'];
            $views = $item['views'];
            $lifetime = cspv_get_view_count( $p->ID );

            $thumb = '';
            if ( $image_width > 0 && has_post_thumbnail( $p->ID ) ) {
                $url = get_the_post_thumbnail_url( $p->ID, 'medium' );
                if ( ! empty( $url ) ) {
                    $thumb = $url;
                }
            }
            $post_data[] = array(
                'title'    => wp_strip_all_tags( get_the_title( $p->ID ) ),
                'url'      => get_permalink( $p->ID ),
                'date'     => get_the_date( 'd M Y', $p->ID ),
                'views'    => $views,
                'lifetime' => $lifetime,
                'thumb'    => $thumb,
            );
        }

        // CSS is enqueued via cspv_top_posts_widget_enqueue(); per-instance colours use CSS custom properties.
        echo '<div class="cspv-tp-widget" id="' . esc_attr( $uid ) . '"'
            . ' style="--cspv-meta-color:' . esc_attr( $meta_color ) . ';--cspv-meta-hover:' . esc_attr( $meta_hover ) . ';"'
            . ' data-per-page="' . esc_attr( $posts_per_pg ) . '" data-img-width="' . esc_attr( $image_width ) . '">';
        echo '<ul class="cspv-tp-list" id="' . esc_attr( $uid ) . '_list"></ul>';
        if ( $pages_cnt > 1 ) {
            echo '<div class="cspv-tp-pager" id="' . esc_attr( $uid ) . '_pager">';
            echo '<button class="cspv-tp-btn cspv-tp-first">&#171;</button>';
            echo '<button class="cspv-tp-btn cspv-tp-prev">&#8249;</button>';
            echo '<span class="cspv-tp-info" id="' . esc_attr( $uid ) . '_info"></span>';
            echo '<button class="cspv-tp-btn cspv-tp-next">&#8250;</button>';
            echo '<button class="cspv-tp-btn cspv-tp-last" data-pages="' . esc_attr( $pages_cnt ) . '">&#187;</button>';
            echo '</div>';
        }
        echo '</div>';

        $json      = wp_json_encode( $post_data );
        $per_page  = (int) $posts_per_pg;
        $img_width = (int) $image_width;

        $js = '(function(){
    var uid      = ' . wp_json_encode( $uid ) . ';
    var el      = document.getElementById(uid);
    var posts    = ' . $json . ';
    var perPage  = el ? parseInt(el.getAttribute("data-per-page"), 10) : ' . $per_page . ';
    var imgW     = el ? parseInt(el.getAttribute("data-img-width"), 10) : ' . $img_width . ';
    var totalPgs = Math.ceil(posts.length / perPage);
    var cur      = 1;

    function esc(s){return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#39;");}

    function fmt(n){
        if(n>=1000000) return (n/1000000).toFixed(1)+"M";
        if(n>=1000)    return (n/1000).toFixed(1)+"k";
        return ""+n;
    }

    function render(page){
        cur = Math.max(1, Math.min(page, totalPgs));
        var list  = document.getElementById(uid+"_list");
        var info  = document.getElementById(uid+"_info");
        var pager = document.getElementById(uid+"_pager");
        if(!list) return;

        var start = (cur-1)*perPage;
        var slice = posts.slice(start, start+perPage);
        var html  = "";
        for(var i=0;i<slice.length;i++){
            var p = slice[i];
            var img = "";
            if(imgW>0 && p.thumb){
                img = "<a href=\""+esc(p.url)+"\"><img class=\"cspv-tp-thumb\" src=\""+esc(p.thumb)+"\" width=\""+imgW+"\" loading=\"lazy\" alt=\"\" style=\"width:"+imgW+"px;max-width:100%;height:auto;\"></a>";
            }
            var views = "";
            if(p.lifetime>0){
                views = "<span class=\"cspv-tp-views\">&#128065; "+fmt(p.lifetime)+" views</span>";
            }
            html += "<li>"
                  + "<span class=\"cspv-tp-meta\">"
                  + "<a href=\""+esc(p.url)+"\">"+esc(p.title)+"</a>"
                  + "<span class=\"cspv-tp-info-row\"><span>"+p.date+"</span>"+views+"</span>"
                  + img
                  + "</span>"
                  + "</li>";
        }
        list.innerHTML = html;

        if(info) info.textContent = cur+" / "+totalPgs;
        if(pager){
            var btns = pager.querySelectorAll(".cspv-tp-btn");
            for(var b=0;b<btns.length;b++){
                var btn = btns[b];
                if(btn.classList.contains("cspv-tp-first")||btn.classList.contains("cspv-tp-prev")){
                    btn.disabled = (cur<=1);
                } else {
                    btn.disabled = (cur>=totalPgs);
                }
            }
        }
    }

    function init(){
        var pager = document.getElementById(uid+"_pager");
        if(pager){
            pager.addEventListener("click", function(e){
                var btn = e.target.closest(".cspv-tp-btn");
                if(!btn||btn.disabled) return;
                if(btn.classList.contains("cspv-tp-first"))      render(1);
                else if(btn.classList.contains("cspv-tp-prev"))  render(cur-1);
                else if(btn.classList.contains("cspv-tp-next"))  render(cur+1);
                else if(btn.classList.contains("cspv-tp-last"))  render(parseInt(btn.dataset.pages,10));
            });
        }
        render(1);
    }

    if(document.readyState==="loading"){
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();';

        wp_add_inline_script( 'cspv-top-posts-widget', $js );
        echo $args['after_widget'];
    }

    /**
     * Render the widget settings form in the Widgets admin screen.
     *
     * @since 1.0.0
     * @param array $instance Current saved widget settings.
     * @return void
     */
    public function form( $instance ) {
        $title        = isset( $instance['title'] )          ? $instance['title']                  : __( 'Top Posts', 'cloudscale-wordpress-free-analytics' );
        $total_posts  = isset( $instance['total_posts'] )    ? (int) $instance['total_posts']       : 20;
        $per_page     = isset( $instance['posts_per_page'] ) ? (int) $instance['posts_per_page']    : 5;
        $image_width  = isset( $instance['image_width'] )    ? (int) $instance['image_width']       : 150;
        $order_by     = isset( $instance['order_by'] )       ? $instance['order_by']                : 'views';
        $view_window  = isset( $instance['view_window'] )    ? (int) $instance['view_window']        : 28;
        $meta_color   = isset( $instance['meta_color'] )     ? $instance['meta_color']               : '#c2410c';
        $meta_hover   = isset( $instance['meta_hover'] )     ? $instance['meta_hover']               : '#ea580c';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('title') ); ?>"><?php esc_html_e( 'Widget Title:', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id('title') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('title') ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('total_posts') ); ?>"><?php esc_html_e( 'Total posts to pool (n):', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id('total_posts') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('total_posts') ); ?>"
                   type="number" min="1" max="200" value="<?php echo esc_attr( $total_posts ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('posts_per_page') ); ?>"><?php esc_html_e( 'Posts per page (x):', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id('posts_per_page') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('posts_per_page') ); ?>"
                   type="number" min="1" max="50" value="<?php echo esc_attr( $per_page ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('image_width') ); ?>"><?php esc_html_e( 'Thumbnail width px (0 = hide):', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id('image_width') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('image_width') ); ?>"
                   type="number" min="0" max="500" value="<?php echo esc_attr( $image_width ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('order_by') ); ?>"><?php esc_html_e( 'Order posts by:', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <select class="widefat" id="<?php echo esc_attr( $this->get_field_id('order_by') ); ?>"
                    name="<?php echo esc_attr( $this->get_field_name('order_by') ); ?>">
                <option value="views"  <?php selected( $order_by, 'views' ); ?>><?php esc_html_e( 'Most Viewed', 'cloudscale-wordpress-free-analytics' ); ?></option>
                <option value="date"   <?php selected( $order_by, 'date' ); ?>><?php esc_html_e( 'Most Recent', 'cloudscale-wordpress-free-analytics' ); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id('view_window') ); ?>"><?php esc_html_e( 'View window (days, -1 = all time):', 'cloudscale-wordpress-free-analytics' ); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id('view_window') ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name('view_window') ); ?>"
                   type="number" min="-1" max="3650" value="<?php echo esc_attr( $view_window ); ?>">
            <br><small><?php esc_html_e( 'Posts are ranked and counted by views within this window. Set to -1 for all time.', 'cloudscale-wordpress-free-analytics' ); ?></small>
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
        $instance                   = array();
        $instance['title']          = sanitize_text_field( $new_instance['title'] );
        $instance['total_posts']    = max( 1, (int) $new_instance['total_posts'] );
        $instance['posts_per_page'] = max( 1, (int) $new_instance['posts_per_page'] );
        $instance['image_width']    = max( 0, (int) $new_instance['image_width'] );
        $instance['order_by']       = in_array( $new_instance['order_by'], array( 'date', 'views' ) )
                                        ? $new_instance['order_by'] : 'views';
        $instance['view_window']    = isset( $new_instance['view_window'] ) ? (int) $new_instance['view_window'] : -1;
        if ( $instance['view_window'] < -1 ) $instance['view_window'] = -1;
        $instance['meta_color']     = sanitize_hex_color( $new_instance['meta_color'] ?? '#c2410c' ) ?: '#c2410c';
        $instance['meta_hover']     = sanitize_hex_color( $new_instance['meta_hover'] ?? '#ea580c' ) ?: '#ea580c';
        return $instance;
    }
}

// -------------------------------------------------------------------------
// 4. Widget CSS
// -------------------------------------------------------------------------
function cspv_top_posts_widget_css() {
    return '
.cspv-tp-list{margin:0;padding:0;list-style:none;display:grid;grid-template-columns:1fr;gap:0;}
@media(min-width:768px){.cspv-tp-list{grid-template-columns:1fr 1fr;gap:0 24px;}}
.cspv-tp-list li{display:flex;flex-direction:column;gap:6px;padding:10px 0;border-bottom:2px solid #f0f0f0;}
.cspv-tp-list li:last-child{border-bottom:none;}
.cspv-tp-thumb{display:block;height:auto;object-fit:cover;border-radius:4px;max-width:100%;}
.cspv-tp-meta{display:flex;flex-direction:column;gap:5px;min-width:0;width:100%;}
.cspv-tp-meta>a{font-size:16px;line-height:1.4;text-decoration:none;font-weight:700;word-break:break-word;color:#1a2332;}
.cspv-tp-meta>a:hover{text-decoration:underline;color:#e8491d;}
.cspv-tp-info-row{display:flex;align-items:center;gap:10px;font-size:14px;flex-wrap:wrap;color:var(--cspv-meta-color,#c2410c);transition:color .15s;}
.cspv-tp-info-row:hover,.cspv-tp-info-row:hover .cspv-tp-views{color:var(--cspv-meta-hover,#ea580c);}
.cspv-tp-views{display:inline-flex;align-items:center;gap:3px;color:var(--cspv-meta-color,#c2410c);}
.cspv-tp-pager{display:flex;align-items:center;gap:4px;margin-top:10px;flex-wrap:wrap;}
.cspv-tp-btn{background:linear-gradient(135deg,#e8491d,#f27c1a);border:none;border-radius:4px;padding:4px 11px;cursor:pointer;font-size:14px;line-height:1.7;color:#fff;font-weight:600;transition:opacity .15s;}
.cspv-tp-btn:hover:not(:disabled){opacity:.85;}
.cspv-tp-btn:disabled{opacity:0.3;cursor:default;}
.cspv-tp-info{font-size:13px;color:#666;min-width:56px;text-align:center;}
';
}
