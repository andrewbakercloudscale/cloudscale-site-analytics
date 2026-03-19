<?php
/**
 * CloudScale Analytics — 404 Tracking
 *
 * Logs every frontend 404 (destination URL + referrer source) to
 * wp_cspv_404_v2. Repeated hits on the same URL+referrer pair increment
 * the hit_count rather than adding new rows.
 *
 * Results displayed in the stats page above Site Health.
 *
 * @package CloudScale_WordPress_Free_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. Table creation — also runs lazily on admin_init if the table is missing
//    (handles upgrades where the version number has not changed).
// -------------------------------------------------------------------------
add_action( 'admin_init', 'cspv_maybe_create_404_table', 5 );

/**
 * Create the 404 log table if it does not yet exist.
 *
 * Runs on every admin_init so upgrades to an existing install are handled
 * automatically without requiring deactivation/reactivation.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_maybe_create_404_table() {
	global $wpdb;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'cspv_404_v2' ) ) ) {
		cspv_create_table_404_v2();
	}
}

/**
 * Create the wp_cspv_404_v2 table.
 *
 * One row per unique URL + referrer combination. Repeated hits increment
 * hit_count and update last_seen via ON DUPLICATE KEY UPDATE.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_create_table_404_v2() {
	global $wpdb;

	$table           = $wpdb->prefix . 'cspv_404_v2';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
		id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		url        VARCHAR(512)        NOT NULL DEFAULT '',
		referrer   VARCHAR(512)        NOT NULL DEFAULT '',
		hit_count  INT UNSIGNED        NOT NULL DEFAULT 1,
		first_seen DATETIME            NOT NULL,
		last_seen  DATETIME            NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY url_referrer (url(191), referrer(191)),
		KEY last_seen (last_seen)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// -------------------------------------------------------------------------
// 2. Frontend tracking hook
// -------------------------------------------------------------------------
add_action( 'template_redirect', 'cspv_track_404' );

/**
 * Log a frontend 404 hit to the tracking table.
 *
 * Skips logged-in admins so their typos do not pollute the log.
 * Uses INSERT … ON DUPLICATE KEY UPDATE so repeated hits on the same
 * URL + referrer pair increment the counter rather than adding rows.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_track_404() {
	if ( ! is_404() ) {
		return;
	}
	// Skip admins — their 404s (typos, draft previews) pollute the log.
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'cspv_404_v2';

	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return;
	}

	$request  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$url      = substr( esc_url_raw( home_url( $request ) ), 0, 512 );
	$referrer = isset( $_SERVER['HTTP_REFERER'] )
		? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 512 ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$now = current_time( 'mysql' );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted internal table name/expression
			"INSERT INTO `{$table}` (url, referrer, hit_count, first_seen, last_seen)
			 VALUES (%s, %s, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = %s",
			$url,
			$referrer,
			$now,
			$now,
			$now
		)
	);
}

// -------------------------------------------------------------------------
// 3. AJAX — purge log
// -------------------------------------------------------------------------
add_action( 'wp_ajax_cspv_purge_404_log', 'cspv_ajax_purge_404_log' );
add_action( 'admin_enqueue_scripts', 'cspv_404_enqueue_purge_script', 20 );

/**
 * Enqueue the 404 purge button script via wp_add_inline_script.
 *
 * Attaches to the cspv-stats-page handle which is already enqueued on
 * the plugin's stats page. The nonce is embedded at enqueue time.
 *
 * @since 2.9.94
 * @param string $hook Current admin page hook.
 * @return void
 */
function cspv_404_enqueue_purge_script( $hook ) {
	if ( 'tools_page_cloudscale-wordpress-free-analytics' !== $hook ) {
		return;
	}
	$nonce = wp_json_encode( wp_create_nonce( 'cspv_404_data' ) );
	$js    = '(function(){var btn=document.getElementById("cspv-purge-404-btn");if(!btn)return;'
		. 'btn.addEventListener("click",function(){'
		. 'if(!confirm("Clear the entire 404 log? This cannot be undone."))return;'
		. 'btn.disabled=true;btn.textContent="Clearing\u2026";'
		. 'var fd=new FormData();fd.append("action","cspv_purge_404_log");fd.append("nonce",' . $nonce . ');'
		. 'fetch(ajaxurl,{method:"POST",body:fd})'
		. '.then(function(r){return r.json();})'
		. '.then(function(d){'
		. 'if(d.success){var inner=document.getElementById("cspv-404-inner");'
		. 'if(inner)inner.innerHTML="<p style=\"color:#059669;font-size:13px;font-weight:600;\">\u2713 404 log cleared.<\/p>";}'
		. 'else{btn.disabled=false;btn.textContent="\uD83D\uDDD1 Clear Log";}'
		. '});});})();';
	wp_add_inline_script( 'cspv-stats-page', $js );
}

/**
 * AJAX handler: truncate the 404 log table.
 *
 * @since 2.9.94
 * @return void
 */
function cspv_ajax_purge_404_log() {
	if ( ! check_ajax_referer( 'cspv_404_data', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page.' ), 403 );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'cspv_404_v2';
	$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	wp_send_json_success( array( 'message' => '404 log cleared.' ) );
}

// -------------------------------------------------------------------------
// 4. Render — called from stats-page.php
// -------------------------------------------------------------------------

/**
 * Render the 404 error log table for the stats page.
 *
 * Outputs the summary counts, the sortable data table (top 50 by hit count),
 * and the Clear Log button. The button's JS is enqueued separately via
 * cspv_404_enqueue_purge_script() to comply with wp_add_inline_script().
 *
 * @since 2.9.94
 * @return void
 */
function cspv_render_404_html() {
	global $wpdb;
	$table = $wpdb->prefix . 'cspv_404_v2';

	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		echo '<p style="color:#888;font-size:13px;">404 log table not found — deactivate and reactivate the plugin to create it.</p>';
		return;
	}

	$total_unique = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	$total_hits   = (int) $wpdb->get_var( "SELECT SUM(hit_count) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT url, referrer, hit_count, first_seen, last_seen
		 FROM `{$table}`
		 ORDER BY hit_count DESC, last_seen DESC
		 LIMIT 50" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	$over_limit = $total_unique > 50;
	?>
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
		<div style="display:flex;gap:10px;flex-wrap:wrap;">
			<span style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:4px 12px;font-size:13px;font-weight:700;color:#dc2626;">
				<?php echo number_format( $total_unique ); ?> unique URLs
			</span>
			<span style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:4px 12px;font-size:13px;font-weight:700;color:#ea580c;">
				<?php echo number_format( $total_hits ); ?> total hits
			</span>
			<?php if ( $over_limit ) : ?>
			<span style="background:#f9fafb;border:1px solid #d1d5db;border-radius:6px;padding:4px 12px;font-size:12px;color:#6b7280;">
				showing top 50
			</span>
			<?php endif; ?>
		</div>
		<button id="cspv-purge-404-btn"
				style="background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">
			🗑 Clear Log
		</button>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p style="color:#888;font-size:13px;">No 404 errors recorded yet.</p>
	<?php else : ?>

	<div style="overflow-x:auto;">
		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
				<tr style="background:#fef2f2;">
					<th style="text-align:left;padding:8px 12px;border-bottom:2px solid #fca5a5;font-weight:700;color:#dc2626;min-width:200px;">Destination (404 URL)</th>
					<th style="text-align:left;padding:8px 12px;border-bottom:2px solid #fca5a5;font-weight:700;color:#dc2626;min-width:180px;">Source (Referrer)</th>
					<th style="text-align:center;padding:8px 12px;border-bottom:2px solid #fca5a5;font-weight:700;color:#dc2626;">Hits</th>
					<th style="text-align:left;padding:8px 12px;border-bottom:2px solid #fca5a5;font-weight:700;color:#dc2626;white-space:nowrap;">Last Seen</th>
					<th style="text-align:left;padding:8px 12px;border-bottom:2px solid #fca5a5;font-weight:700;color:#dc2626;white-space:nowrap;">First Seen</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $i => $row ) :
					$bg = $i % 2 === 0 ? '#fff' : '#fff8f8';
				?>
				<tr style="background:<?php echo esc_attr( $bg ); ?>;border-bottom:1px solid #fee2e2;">
					<td style="padding:8px 12px;word-break:break-all;">
						<a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener"
						   style="color:#dc2626;text-decoration:none;font-family:monospace;font-size:12px;">
							<?php echo esc_html( $row->url ); ?>
						</a>
					</td>
					<td style="padding:8px 12px;word-break:break-all;">
						<?php if ( ! empty( $row->referrer ) ) : ?>
						<a href="<?php echo esc_url( $row->referrer ); ?>" target="_blank" rel="noopener"
						   style="color:#6b7280;text-decoration:none;font-family:monospace;font-size:12px;">
							<?php echo esc_html( $row->referrer ); ?>
						</a>
						<?php else : ?>
						<span style="color:#9ca3af;font-size:12px;font-style:italic;">direct / unknown</span>
						<?php endif; ?>
					</td>
					<td style="padding:8px 12px;text-align:center;font-weight:700;color:<?php echo (int) $row->hit_count > 10 ? '#dc2626' : '#374151'; ?>;">
						<?php echo number_format( (int) $row->hit_count ); ?>
					</td>
					<td style="padding:8px 12px;font-size:12px;color:#6b7280;white-space:nowrap;">
						<?php echo esc_html( wp_date( 'j M Y H:i', ( new DateTime( $row->last_seen, wp_timezone() ) )->getTimestamp() ) ); ?>
					</td>
					<td style="padding:8px 12px;font-size:12px;color:#9ca3af;white-space:nowrap;">
						<?php echo esc_html( wp_date( 'j M Y', ( new DateTime( $row->first_seen, wp_timezone() ) )->getTimestamp() ) ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php endif; ?>
	<?php
}
