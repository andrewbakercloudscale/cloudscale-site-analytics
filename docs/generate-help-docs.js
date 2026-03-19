'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Free Analytics',
    pluginDesc: 'Accurate page view tracking via a JavaScript beacon that bypasses caching. Includes a live statistics dashboard, Top Posts and Recent Posts sidebar widgets, IP throttling, and a Jetpack stats migrator — completely free, no data sent to third parties.',
    pageTitle:  'CloudScale Free Analytics: Online Help',
    pageSlug:   'analytics-help',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics`,

    sections: [
        { id: 'statistics',   label: 'Statistics Dashboard',  file: 'panel-statistics.png',  tabSelector: '[data-tab="stats"]'    },
        { id: 'display',      label: 'Display Settings',      file: 'panel-display.png',     tabSelector: '[data-tab="display"]'  },
        { id: 'throttle',     label: 'IP Throttle',           file: 'panel-throttle.png',    tabSelector: '[data-tab="throttle"]' },
        { id: 'history',      label: 'Post History',          file: 'panel-history.png',     tabSelector: '[data-tab="history"]'  },
    ],

    docs: {
        'statistics': `
<p>The <strong>Statistics Dashboard</strong> shows page view data stored directly in your WordPress database across five custom tables (<code>wp_cspv_views_v2</code>, <code>wp_cspv_referrers_v2</code>, <code>wp_cspv_geo_v2</code>, <code>wp_cspv_visitors_v2</code>, <code>wp_cspv_404_v2</code>). No data is ever sent to Google, Facebook, or any external service.</p>
<p><strong>How tracking works:</strong> A lightweight <code>beacon.js</code> script fires a POST request to the WordPress REST API endpoint <code>/wp-json/cloudscale-wordpress-free-analytics/v1/record/{post_id}</code> after the page has fully loaded. Because this is a fresh HTTP request — not served from cache — it reaches WordPress even when Cloudflare or another CDN is serving the cached HTML page. This is why beacon-based tracking counts every real visit, whereas server-side counters miss 80–95% of views on cached sites.</p>
<p><strong>Cloudflare Cache Rule required for production:</strong> Add a Cache Rule in your Cloudflare dashboard: URI Path <em>contains</em> <code>/wp-json/cloudscale-wordpress-free-analytics/</code> → Cache Status: <em>Bypass</em>. Without this rule, Cloudflare may cache the REST responses, causing beacon POSTs to fail silently.</p>
<ul>
<li><strong>Period selector</strong> — switch between Today, 7 days, 30 days, 90 days, and All time. Each period queries the <code>wp_cspv_views_v2</code> table directly; no aggregation tables are needed.</li>
<li><strong>Totals bar</strong> — Total Views, Unique Posts Viewed, and Lifetime totals pulled in a single indexed query on <code>viewed_at</code>.</li>
<li><strong>Views chart</strong> — daily bar chart. Gaps indicate days with zero traffic (not missing data).</li>
<li><strong>Top Posts table</strong> — ranked by <code>COUNT(*)</code> on <code>post_id</code> for the selected period, with direct edit links.</li>
<li><strong>Referrer breakdown</strong> — parsed from the <code>document.referrer</code> value sent by the beacon; stored in <code>wp_cspv_referrers_v2</code>. Direct traffic (no referrer) appears as "Direct".</li>
</ul>
<p><strong>Privacy:</strong> visitor IP addresses are hashed with SHA-256 combined with your site's <code>wp_salt</code> before storage — the raw IP is never written to the database. The hash is used only for deduplication and throttle checks.</p>`,

        'display': `
<p>The <strong>Display Settings</strong> tab controls how view counts appear on your site and configures the sidebar widgets.</p>
<ul>
<li><strong>Show view count on posts</strong> — appends a view counter to single post pages using the <code>the_content</code> or <code>wp_footer</code> filter. The count is read from the <code>_cspv_view_count</code> post meta key, which is synced from the <code>wp_cspv_views_v2</code> table on each beacon hit.</li>
<li><strong>View count position</strong> — before content, after content, or in post meta (appended to the post date line). The meta position requires theme support for <code>the_post_meta</code> or equivalent.</li>
<li><strong>Display style</strong> — choose between Badge (coloured pill), Pill (outlined), or Minimal (plain text) styles. Five colour options: blue, pink, red, purple, grey.</li>
<li><strong>Top Posts widget</strong> — register via <em>Appearance → Widgets</em> or block widget editor. Queries <code>wp_cspv_views_v2</code> for the configurable view window (default: all time). Settings: total posts to pool (default 10), posts per page, thumbnail width, and sort order (most viewed or most recent).</li>
<li><strong>Recent Posts widget</strong> — shows the most recently published posts with view counts. Configurable post count and optional publication date display.</li>
<li><strong>Exclude post types</strong> — comma-separated list of post types to skip tracking (e.g. <code>product</code> for WooCommerce). Excluded types do not fire the beacon and are not counted in the dashboard.</li>
</ul>`,

        'throttle': `
<p>The <strong>IP Throttle</strong> tab prevents inflated statistics from repeat page loads, bots, and your own browsing.</p>
<ul>
<li><strong>Throttle window</strong> — the minimum gap (in minutes) between two counted views from the same hashed IP to the same post. Default: 30 minutes. The check queries <code>wp_cspv_views_v2</code> for a recent row matching <code>post_id</code> + <code>ip_hash</code> within the window. Set to 0 to count every request (not recommended for production).</li>
<li><strong>Client-side deduplication</strong> — in addition to server-side throttling, the beacon sets a <code>sessionStorage</code> key per post on first fire. If the same browser tab reloads the page, the beacon does not fire again for that session. This eliminates double-counts from accidental refreshes before the throttle window check even runs.</li>
<li><strong>Exclude logged-in users</strong> — prevents any authenticated WordPress session from being counted. Detected via the <code>logged_in_{hash}</code> cookie presence check in the beacon.</li>
<li><strong>Exclude administrators</strong> — more granular: only users with the <code>administrator</code> role are excluded. Editors, Authors, and Contributors are still counted.</li>
<li><strong>Pause tracking</strong> — sets the <code>cspv_tracking_paused</code> WordPress option to <code>1</code>. The REST endpoint returns a 200 OK but writes no rows. Use during content imports, load tests, or bulk migrations to avoid polluting your stats.</li>
<li><strong>Fail2Ban / IP blocklist</strong> — IPs exceeding the configurable per-window page limit are permanently blocked. Blocked IPs are stored in the <code>cspv_ftb_blocklist</code> option and checked at the top of the REST handler before any database write.</li>
</ul>`,

        'history': `
<p>The <strong>Post History</strong> tab provides a full day-by-day breakdown of views for any individual post or page — useful for diagnosing traffic spikes, measuring the impact of promotions, and spotting seasonal patterns.</p>
<ul>
<li><strong>Post search</strong> — autocomplete dropdown queries <code>wp_posts</code> by title. Supports pages, custom post types, and any post type that has tracking enabled.</li>
<li><strong>View timeline</strong> — bar chart showing daily view volume from the post's first recorded view to the current date. Built from a <code>GROUP BY DATE(viewed_at)</code> query on <code>wp_cspv_views_v2</code> filtered to the selected <code>post_id</code>. Days with zero views are filled in programmatically so the chart has no gaps.</li>
<li><strong>Total views</strong> — lifetime count for that post. This is the same value stored in the <code>_cspv_view_count</code> post meta key, which you can query directly: <code>get_post_meta($post_id, '_cspv_view_count', true)</code>.</li>
<li><strong>Trending indicator</strong> — compares the last 7 days of views against the 28-day average daily rate for that post. Green arrow = accelerating; red arrow = decelerating. Posts with fewer than 7 days of data show "Insufficient history".</li>
<li><strong>Jetpack migration</strong> — if you migrated from Jetpack Stats, the lifetime view count imported during migration is stored as a base offset and blended with live beacon data. The Post History chart shows both the imported baseline and new beacon views combined.</li>
</ul>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
