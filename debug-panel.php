<?php
/**
 * CloudScale Page Views - View Diagnostics Panel  v2.0.0
 *
 * Admin only overlay on singular posts showing:
 *   - Post meta count (_cspv_view_count) = the displayed number
 *   - Log table count (wp_cspv_views rows for this post)
 *   - Jetpack imported count (the difference)
 *   - Daily view chart from the log table
 *
 * Only visible to users with manage_options capability.
 * Button renders INLINE next to the view counter (pink, 🐛 icon).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Inject the debug button right after the auto-display counter
add_filter( 'the_content', 'cspv_inject_debug_button', 100 );

function cspv_inject_debug_button( $content ) {
    if ( ! is_singular() ) { return $content; }
    if ( ! current_user_can( 'manage_options' ) ) { return $content; }

    // Always prepend a hidden button; JS will relocate it into the counter container
    $btn = '<button id="cspv-debug-toggle" style="display:none" title="View Diagnostics">🐛 Debug</button>';
    $content = $btn . $content;

    return $content;
}

// Render the panel in wp_footer
add_action( 'wp_footer', 'cspv_render_debug_panel' );

function cspv_render_debug_panel() {
    if ( ! is_singular() ) { return; }
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    global $wpdb;
    $post_id = get_the_ID();
    $table   = $wpdb->prefix . 'cspv_views';

    $meta_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    $log_count    = 0;
    $first_log    = null;
    $last_log     = null;
    $daily_data   = array();

    if ( $table_exists ) {
        $log_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id ) );

        $first_log = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) );

        $last_log = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(viewed_at) FROM `{$table}` WHERE post_id = %d", $post_id ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(viewed_at) AS day, COUNT(*) AS views
             FROM `{$table}`
             WHERE post_id = %d AND viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY day ORDER BY day ASC", $post_id ) );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $daily_data[] = array( 'day' => $r->day, 'views' => (int) $r->views );
            }
        }
    }

    $jetpack_imported = max( 0, $meta_count - $log_count );
    $mismatch         = ( $meta_count !== $log_count && $jetpack_imported === 0 );
    $jp_meta          = get_post_meta( $post_id, 'jetpack_post_views', true );
    $jp_views         = $jp_meta ? (int) $jp_meta : null;

    ?>
<style id="cspv-debug-style">
#cspv-debug-toggle {
    display: inline-flex; align-items: center; gap: 5px;
    background: linear-gradient(135deg, #db2777, #f472b6);
    color: #fff; border: none; cursor: pointer;
    font-size: 13px; font-weight: 700; line-height: 1;
    padding: 7px 14px; border-radius: 20px;
    box-shadow: 0 2px 8px rgba(219,39,119,.3);
    transition: transform .15s, box-shadow .15s;
    vertical-align: middle; margin-left: 8px;
    letter-spacing: .02em;
}
#cspv-debug-toggle:hover { transform: scale(1.05); box-shadow: 0 3px 12px rgba(219,39,119,.4); }
#cspv-debug-panel {
    display: none;
    position: fixed; bottom: 16px; right: 16px; z-index: 99999;
    width: 400px; max-width: calc(100vw - 32px); max-height: calc(100vh - 32px); overflow-y: auto;
    background: #fff; border-radius: 10px;
    box-shadow: 0 4px 24px rgba(0,0,0,.2);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 13px; color: #1a2332;
}
#cspv-debug-panel.open { display: block; }
.cspv-dbg-header {
    background: linear-gradient(135deg, #db2777 0%, #f472b6 100%);
    color: #fff; padding: 12px 16px;
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    display: flex; justify-content: space-between; align-items: center;
}
.cspv-dbg-header small {
    display: block; font-weight: 400; opacity: .75; text-transform: none;
    letter-spacing: 0; margin-top: 2px; font-size: 11px;
}
.cspv-dbg-close {
    background: rgba(255,255,255,.2); border: none; color: #fff; cursor: pointer;
    width: 24px; height: 24px; border-radius: 50%; font-size: 14px; line-height: 24px; text-align: center;
}
.cspv-dbg-close:hover { background: rgba(255,255,255,.35); }
.cspv-dbg-body { padding: 14px 16px; }
.cspv-dbg-row {
    display: flex; justify-content: space-between; align-items: baseline;
    padding: 5px 0; border-bottom: 1px solid #f0f0f0;
}
.cspv-dbg-row:last-child { border-bottom: none; }
.cspv-dbg-label { color: #666; font-size: 12px; }
.cspv-dbg-value { font-weight: 700; font-variant-numeric: tabular-nums; }
.cspv-dbg-value.green { color: #059669; }
.cspv-dbg-value.blue  { color: #1e6fd9; }
.cspv-dbg-value.orange { color: #f47c20; }
.cspv-dbg-value.red   { color: #e53e3e; }
.cspv-dbg-section {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    color: #aaa; letter-spacing: .04em; margin: 10px 0 4px; padding-top: 6px;
    border-top: 1px solid #eee;
}
.cspv-dbg-section:first-child { border-top: none; margin-top: 0; padding-top: 0; }
.cspv-dbg-chart { height: 80px; display: flex; align-items: flex-end; gap: 2px; margin-top: 8px; }
.cspv-dbg-bar {
    flex: 1; background: linear-gradient(180deg, #db2777, #f9a8d4);
    border-radius: 2px 2px 0 0; min-height: 2px; position: relative; cursor: default;
}
.cspv-dbg-bar:hover { opacity: .8; }
.cspv-dbg-bar-tip {
    display: none; position: absolute; bottom: 100%; left: 50%;
    transform: translateX(-50%); background: #1a2332; color: #fff;
    font-size: 10px; padding: 2px 6px; border-radius: 3px; white-space: nowrap; pointer-events: none;
}
.cspv-dbg-bar:hover .cspv-dbg-bar-tip { display: block; }
.cspv-dbg-chart-labels { display: flex; justify-content: space-between; font-size: 10px; color: #aaa; margin-top: 2px; }
.cspv-dbg-warn {
    background: #fef3cd; border: 1px solid #f0d060; border-radius: 4px;
    padding: 8px 10px; font-size: 12px; margin-top: 10px; color: #856404;
}
.cspv-dbg-fix-btn {
    display: inline-block; margin-top: 6px; padding: 4px 12px;
    background: #e53e3e; color: #fff; border: none; border-radius: 4px;
    font-size: 11px; font-weight: 700; cursor: pointer;
}
.cspv-dbg-fix-btn:hover { background: #c53030; }
</style>

<div id="cspv-debug-panel">
    <div class="cspv-dbg-header">
        <div>🐛 View Diagnostics — Post #<?php echo (int) $post_id; ?>
        <small><?php echo esc_html( get_the_title( $post_id ) ); ?></small></div>
        <button class="cspv-dbg-close" id="cspv-dbg-close" title="Close">✕</button>
    </div>
    <div class="cspv-dbg-body">

        <div class="cspv-dbg-section">Counts</div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Displayed count (post meta)</span>
            <span class="cspv-dbg-value <?php echo $mismatch ? 'red' : 'green'; ?>"><?php echo number_format( $meta_count ); ?></span>
        </div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Log table rows (wp_cspv_views)</span>
            <span class="cspv-dbg-value blue"><?php echo number_format( $log_count ); ?></span>
        </div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Jetpack imported (meta minus log)</span>
            <span class="cspv-dbg-value orange"><?php echo number_format( $jetpack_imported ); ?></span>
        </div>
        <?php if ( $jp_views !== null ) : ?>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Jetpack meta (jetpack_post_views)</span>
            <span class="cspv-dbg-value"><?php echo number_format( $jp_views ); ?></span>
        </div>
        <?php endif; ?>

        <div class="cspv-dbg-section">Timeline</div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">First logged view</span>
            <span class="cspv-dbg-value"><?php echo $first_log ? esc_html( $first_log ) : 'none'; ?></span>
        </div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Last logged view</span>
            <span class="cspv-dbg-value"><?php echo $last_log ? esc_html( $last_log ) : 'none'; ?></span>
        </div>

        <?php if ( ! empty( $daily_data ) ) : ?>
        <div class="cspv-dbg-section">Last 30 days (log table only)</div>
        <?php
            $max_v = max( array_column( $daily_data, 'views' ) );
            $max_v = max( 1, $max_v );
        ?>
        <div class="cspv-dbg-chart">
            <?php foreach ( $daily_data as $d ) :
                $pct = round( ( $d['views'] / $max_v ) * 100 );
            ?>
            <div class="cspv-dbg-bar" style="height:<?php echo max( 2, $pct ); ?>%">
                <span class="cspv-dbg-bar-tip"><?php echo esc_html( date( 'j M', strtotime( $d['day'] ) ) . ': ' . number_format( $d['views'] ) ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cspv-dbg-chart-labels">
            <span><?php echo esc_html( date( 'j M', strtotime( $daily_data[0]['day'] ) ) ); ?></span>
            <span><?php echo esc_html( date( 'j M', strtotime( end( $daily_data )['day'] ) ) ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $meta_count > 0 && $log_count === 0 && $jetpack_imported > 0 ) : ?>
        <div class="cspv-dbg-warn">
            All <?php echo number_format( $meta_count ); ?> views came from Jetpack import. No log table rows exist for this post.
        </div>
        <?php elseif ( $mismatch ) : ?>
        <div class="cspv-dbg-warn">
            ⚠ Meta count (<?php echo number_format( $meta_count ); ?>) does not match log count (<?php echo number_format( $log_count ); ?>) and no Jetpack import accounts for the difference. The meta may have been corrupted.
            <br>
            <button class="cspv-dbg-fix-btn" id="cspv-dbg-resync">Resync meta from log table</button>
        </div>
        <?php endif; ?>

        <div class="cspv-dbg-section">System</div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Plugin version</span>
            <span class="cspv-dbg-value"><?php echo esc_html( CSPV_VERSION ); ?></span>
        </div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Stored version (cspv_version)</span>
            <span class="cspv-dbg-value"><?php echo esc_html( get_option( 'cspv_version', 'none' ) ); ?></span>
        </div>
        <div class="cspv-dbg-row">
            <span class="cspv-dbg-label">Dedup</span>
            <span class="cspv-dbg-value"><?php
                $dd = get_option( 'cspv_dedup_enabled', 'yes' );
                echo ( $dd !== 'no' ) ? 'On' : 'Off';
            ?></span>
        </div>
    </div>
</div>

<script>
(function() {
    var toggle = document.getElementById('cspv-debug-toggle');
    var panel  = document.getElementById('cspv-debug-panel');
    var close  = document.getElementById('cspv-dbg-close');
    if (!toggle || !panel) return;

    // Relocate button into the auto-display container so they sit side by side
    var container = document.querySelector('.cspv-auto-views');
    if (container && toggle.parentElement !== container) {
        container.appendChild(toggle);
    }
    toggle.style.display = '';

    toggle.addEventListener('click', function() { panel.classList.toggle('open'); });
    if (close) close.addEventListener('click', function() { panel.classList.remove('open'); });

    var resync = document.getElementById('cspv-dbg-resync');
    if (resync) {
        resync.addEventListener('click', function() {
            resync.disabled = true;
            resync.textContent = 'Resyncing…';
            fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cspv_resync_meta&nonce=' + encodeURIComponent(<?php echo wp_json_encode( wp_create_nonce( 'cspv_resync' ) ); ?>) + '&post_id=' + <?php echo (int) $post_id; ?>
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    resync.textContent = '✓ Resynced to ' + resp.data.new_count.toLocaleString();
                    resync.style.background = '#059669';
                } else {
                    resync.textContent = '✗ Failed';
                }
            })
            .catch(function() { resync.textContent = '✗ Error'; });
        });
    }
})();
</script>
<?php
}

// AJAX handler for resync (from front end debug panel)
add_action( 'wp_ajax_cspv_resync_meta', 'cspv_ajax_resync_meta' );

function cspv_ajax_resync_meta() {
    if ( ! check_ajax_referer( 'cspv_resync', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        return;
    }

    global $wpdb;
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! $post_id ) {
        wp_send_json_error( array( 'message' => 'Invalid post ID.' ) );
        return;
    }

    $table = $wpdb->prefix . 'cspv_views';
    $log_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id ) );
    $jp_views = (int) get_post_meta( $post_id, 'jetpack_post_views', true );
    $new_count = $log_count + max( 0, $jp_views );
    $old_count = (int) get_post_meta( $post_id, CSPV_META_KEY, true );
    update_post_meta( $post_id, CSPV_META_KEY, $new_count );

    wp_send_json_success( array(
        'post_id'   => $post_id,
        'old_count' => $old_count,
        'new_count' => $new_count,
        'log_rows'  => $log_count,
        'jp_views'  => $jp_views,
    ) );
}
