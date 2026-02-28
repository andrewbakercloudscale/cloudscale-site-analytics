<?php
/**
 * CloudScale Page Views - Top Posts Widget
 *
 * Sidebar widget that displays a paginated list of top posts ranked by
 * view count from the cspv_views log table, with thumbnail, date and
 * formatted view count.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Query helper: get top posts ranked by CloudScale view data
// -------------------------------------------------------------------------
function cspv_get_top_posts( $total, $order_by, $view_window = -1 ) {

    // --- Rank by views ---
    if ( $order_by === 'views' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cspv_views';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        // Determine if we are in the transition period.
        // If the log table has fewer than view_window days of data,
        // we blend lifetime meta with beacon counts and rank by total.
        $in_transition = false;

        if ( $table_exists && $view_window > 0 ) {
            $earliest = $wpdb->get_var( "SELECT MIN(viewed_at) FROM `{$table}`" );
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
            $beacon_rows = $table_exists ? $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, COUNT(*) AS cnt FROM `{$table}` WHERE viewed_at >= %s GROUP BY post_id",
                $since
            ) ) : array();

            $beacon_map = array();
            foreach ( (array) $beacon_rows as $r ) {
                $beacon_map[ absint( $r->post_id ) ] = (int) $r->cnt;
            }

            // Get all posts with lifetime meta > 0
            $meta_rows = $wpdb->get_results(
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

            // Combine: lifetime + beacon for each post
            $combined = array();
            foreach ( (array) $meta_rows as $r ) {
                $pid = absint( $r->ID );
                $lt  = (int) $r->lifetime;
                $bc  = isset( $beacon_map[ $pid ] ) ? $beacon_map[ $pid ] : 0;
                $combined[ $pid ] = $lt + $bc;
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
                $ranked = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, COUNT(*) AS view_count
                     FROM `{$table}`
                     WHERE viewed_at >= %s
                     GROUP BY post_id
                     ORDER BY view_count DESC
                     LIMIT %d",
                    $since,
                    $total * 2
                ) );
            } else {
                $ranked = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, COUNT(*) AS view_count
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
        $meta_fallback = $wpdb->get_results(
            "SELECT p.ID, CAST(pm.meta_value AS UNSIGNED) AS total_views
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'post'
               AND p.post_status = 'publish'
               AND pm.meta_key = '_cspv_view_count'
               AND CAST(pm.meta_value AS UNSIGNED) > 0
             ORDER BY total_views DESC
             LIMIT " . absint( $total )
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
        $result[] = array( 'post' => $p, 'views' => (int) get_post_meta( $p->ID, '_cspv_view_count', true ) );
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

    public function __construct() {
        parent::__construct(
            'cspv_top_posts_widget',
            'CloudScale: Top Posts',
            array(
                'description'            => 'Shows your most viewed posts with thumbnails, dates and view counts. Paginated with configurable post count and sort order.',
                'show_instance_in_rest'  => true,
            )
        );
    }

    public function widget( $args, $instance ) {
        $title        = ! empty( $instance['title'] )        ? $instance['title']               : 'Top Posts';
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
            echo '<p style="font-size:0.85em;color:#888;">No published posts found.</p>';
            echo $args['after_widget'];
            return;
        }

        $total     = count( $posts_arr );
        $pages_cnt = (int) ceil( $total / $posts_per_pg );

        $post_data = array();
        foreach ( $posts_arr as $item ) {
            $p     = $item['post'];
            $views = $item['views'];
            $lifetime = (int) get_post_meta( $p->ID, CSPV_META_KEY, true );

            // Lifetime (total) must never be less than the beacon window count.
            // This can happen if meta was reimported at a lower value or a race
            // condition prevented the meta increment. Fix it on the fly.
            if ( $lifetime < $views ) {
                $lifetime = $views;
                update_post_meta( $p->ID, CSPV_META_KEY, $lifetime );
            }

            $thumb = '';
            if ( $image_width > 0 && has_post_thumbnail( $p->ID ) ) {
                $url = get_the_post_thumbnail_url( $p->ID, 'medium' );
                if ( ! empty( $url ) ) {
                    $thumb = $url;
                }
            }
            $post_data[] = array(
                'title'    => html_entity_decode( get_the_title( $p->ID ), ENT_QUOTES, 'UTF-8' ),
                'url'      => get_permalink( $p->ID ),
                'date'     => get_the_date( 'd M Y', $p->ID ),
                'views'    => $views,
                'lifetime' => $lifetime,
                'thumb'    => $thumb,
            );
        }

        // Output CSS once per page
        static $css_done = false;
        if ( ! $css_done ) {
            echo '<style>' . cspv_top_posts_widget_css() . '</style>';
            $css_done = true;
        }

        // Per-instance colour overrides
        echo '<style>'
            . '#' . $safe_wid . ' .cspv-tp-info-row,'
            . '#' . $safe_wid . ' .cspv-tp-views{color:' . esc_attr( $meta_color ) . ';transition:color .15s;}'
            . '#' . $safe_wid . ' .cspv-tp-info-row:hover,'
            . '#' . $safe_wid . ' .cspv-tp-info-row:hover .cspv-tp-views{color:' . esc_attr( $meta_hover ) . ';}'
            . '</style>';

        echo '<div class="cspv-tp-widget" id="' . esc_attr( $uid ) . '" data-per-page="' . esc_attr( $posts_per_pg ) . '" data-img-width="' . esc_attr( $image_width ) . '">';
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

        echo '<script>
(function(){
    var uid      = ' . json_encode( $uid ) . ';
    var el      = document.getElementById(uid);
    var posts    = ' . $json . ';
    var perPage  = el ? parseInt(el.getAttribute("data-per-page"), 10) : ' . $per_page . ';
    var imgW     = el ? parseInt(el.getAttribute("data-img-width"), 10) : ' . $img_width . ';
    var totalPgs = Math.ceil(posts.length / perPage);
    var cur      = 1;

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
                img = "<a href=\""+p.url+"\"><img class=\"cspv-tp-thumb\" src=\""+p.thumb+"\" width=\""+imgW+"\" loading=\"lazy\" alt=\"\" style=\"width:"+imgW+"px;max-width:100%;height:auto;\"></a>";
            }
            var views = "";
            if(p.lifetime>0 || p.views>0){
                if(p.views>0 && p.lifetime>0 && p.views !== p.lifetime){
                    views = "<span class=\"cspv-tp-views\">&#128065; "+fmt(p.views)+" trending &middot; "+fmt(p.lifetime)+" total</span>";
                } else {
                    var n = p.lifetime>0 ? p.lifetime : p.views;
                    views = "<span class=\"cspv-tp-views\">&#128065; "+fmt(n)+" views</span>";
                }
            }
            html += "<li>"
                  + "<span class=\"cspv-tp-meta\">"
                  + "<a href=\""+p.url+"\">"+p.title+"</a>"
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
})();
</script>';

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title        = isset( $instance['title'] )          ? $instance['title']                  : 'Top Posts';
        $total_posts  = isset( $instance['total_posts'] )    ? (int) $instance['total_posts']       : 20;
        $per_page     = isset( $instance['posts_per_page'] ) ? (int) $instance['posts_per_page']    : 5;
        $image_width  = isset( $instance['image_width'] )    ? (int) $instance['image_width']       : 150;
        $order_by     = isset( $instance['order_by'] )       ? $instance['order_by']                : 'views';
        $view_window  = isset( $instance['view_window'] )    ? (int) $instance['view_window']        : 28;
        $meta_color   = isset( $instance['meta_color'] )     ? $instance['meta_color']               : '#c2410c';
        $meta_hover   = isset( $instance['meta_hover'] )     ? $instance['meta_hover']               : '#ea580c';
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">Widget Title:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                   name="<?php echo $this->get_field_name('title'); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('total_posts'); ?>">Total posts to pool (n):</label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('total_posts'); ?>"
                   name="<?php echo $this->get_field_name('total_posts'); ?>"
                   type="number" min="1" max="200" value="<?php echo esc_attr( $total_posts ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('posts_per_page'); ?>">Posts per page (x):</label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('posts_per_page'); ?>"
                   name="<?php echo $this->get_field_name('posts_per_page'); ?>"
                   type="number" min="1" max="50" value="<?php echo esc_attr( $per_page ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('image_width'); ?>">Thumbnail width px (0 = hide):</label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('image_width'); ?>"
                   name="<?php echo $this->get_field_name('image_width'); ?>"
                   type="number" min="0" max="500" value="<?php echo esc_attr( $image_width ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('order_by'); ?>">Order posts by:</label>
            <select class="widefat" id="<?php echo $this->get_field_id('order_by'); ?>"
                    name="<?php echo $this->get_field_name('order_by'); ?>">
                <option value="views"  <?php selected( $order_by, 'views' ); ?>>Most Viewed</option>
                <option value="date"   <?php selected( $order_by, 'date' ); ?>>Most Recent</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('view_window'); ?>">View window (days, -1 = all time):</label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('view_window'); ?>"
                   name="<?php echo $this->get_field_name('view_window'); ?>"
                   type="number" min="-1" max="3650" value="<?php echo esc_attr( $view_window ); ?>">
            <br><small>Posts are ranked and counted by views within this window. Set to -1 for all time.</small>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('meta_color'); ?>">Date &amp; views colour:</label><br>
            <input id="<?php echo $this->get_field_id('meta_color'); ?>"
                   name="<?php echo $this->get_field_name('meta_color'); ?>"
                   type="color"
                   value="<?php echo esc_attr( $meta_color ); ?>"
                   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
            <code style="font-size:11px;color:#666;"><?php echo esc_html( $meta_color ); ?></code>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('meta_hover'); ?>">Date &amp; views hover colour:</label><br>
            <input id="<?php echo $this->get_field_id('meta_hover'); ?>"
                   name="<?php echo $this->get_field_name('meta_hover'); ?>"
                   type="color"
                   value="<?php echo esc_attr( $meta_hover ); ?>"
                   style="width:50px;height:30px;padding:0;border:1px solid #ccc;border-radius:3px;cursor:pointer;vertical-align:middle;">
            <code style="font-size:11px;color:#666;"><?php echo esc_html( $meta_hover ); ?></code>
        </p>
        <?php
    }

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
.cspv-tp-info-row{display:flex;align-items:center;gap:10px;font-size:14px;flex-wrap:wrap;}
.cspv-tp-views{display:inline-flex;align-items:center;gap:3px;}
.cspv-tp-pager{display:flex;align-items:center;gap:4px;margin-top:10px;flex-wrap:wrap;}
.cspv-tp-btn{background:linear-gradient(135deg,#e8491d,#f27c1a);border:none;border-radius:4px;padding:4px 11px;cursor:pointer;font-size:14px;line-height:1.7;color:#fff;font-weight:600;transition:opacity .15s;}
.cspv-tp-btn:hover:not(:disabled){opacity:.85;}
.cspv-tp-btn:disabled{opacity:0.3;cursor:default;}
.cspv-tp-info{font-size:13px;color:#666;min-width:56px;text-align:center;}
';
}
