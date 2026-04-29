'use strict';
const helpLib = require('REPO_BASE/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Site Analytics — Free Privacy-First WordPress Analytics That Works Behind Cloudflare &amp; Any CDN',
    pluginDesc: 'Most WordPress analytics plugins miss 40–95% of your traffic when Cloudflare, WP Rocket, or any CDN is caching your pages — they only fire when a PHP request reaches your server. CloudScale Site Analytics uses a lightweight client-side pixel that fires on every page load regardless of server-side caching, stores all data in your own WordPress database, and shows real view counts in your posts list. No Google Analytics, no Google Tag Manager, no Jetpack, no Automattic, no third-party tracking — zero GDPR risk, no cookie consent banner needed. Works with Cloudflare, WP Rocket, LiteSpeed Cache, and all major caching plugins. No cookies required. Completely free, open source, no subscription.',
    seoTitle:   'CloudScale Site Analytics | Free WordPress Analytics — Works Behind Cloudflare & CDN, No Google',
    seoDesc:    'Free WordPress analytics that counts every visit even behind Cloudflare & CDN. Privacy-first: no Google, no tracking pixels, data on your own server. Works with WP Rocket & LiteSpeed. No subscription.',
    schema: {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: 'CloudScale Site Analytics',
        operatingSystem: 'WordPress',
        applicationCategory: 'WebApplication',
        offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
        softwareVersion: '2.9.186',
        downloadUrl: 'https://your-s3-bucket.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip',
        url: 'https://github.com/andrewbakercloudscale/wordpress-free-analytics',
    },
    pageTitle:  'CloudScale Site Analytics — Free Privacy-First WordPress Analytics That Works Behind Cloudflare & CDN',
    pageSlug:   'cloudscale-wordpress-marketing-analytics',
    downloadUrl: 'https://your-s3-bucket.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip',
    repoUrl:     'https://github.com/andrewbakercloudscale/wordpress-free-analytics',

    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics`,

    pluginFile: `${__dirname}/../stats-page.php`,
    logoFile:   `${__dirname}/../cloudscale-analytics-icon.jpg`,

    sections: [
        { id: 'statistics',   label: 'Statistics Dashboard',  file: 'panel-statistics.png',  tab: 'stats',    tabSelector: '[data-tab="stats"]'    },
        { id: 'geography',    label: 'Geography',             file: 'panel-geography.png',   tab: 'stats',    tabSelector: '[data-tab="stats"]',   elementSelector: '#cspv-geo-panel' },
        { id: 'display',      label: 'Display Settings',      file: 'panel-display.png',     tab: 'display',  tabSelector: '[data-tab="display"]'  },
        { id: 'throttle',     label: 'IP Throttle',           file: 'panel-throttle.png',    tab: 'throttle', tabSelector: '[data-tab="throttle"]' },
        { id: 'history',      label: 'Post History',          file: 'panel-history.png',     tab: 'history',  tabSelector: '[data-tab="history"]'  },
        { id: 'migrate',      label: 'Migrate Jetpack Stats', file: 'panel-migrate.png',     tab: 'migrate',  tabSelector: '[data-tab="migrate"]'  },
    ],

    docs: {
        'statistics': `
<div style="background:#f0f9ff!important;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:28px;">
<h2 style="margin:0 0 10px;font-size:1.3em;color:#0f172a!important;">Why CloudScale Site Analytics?</h2>
<p style="margin:0 0 10px;">If your site runs behind Cloudflare, WP Rocket, or any other caching layer, server-side analytics see only the 5–20% of requests that reach PHP. Your stats are lying to you — by a factor of 5 to 10.</p>
<p style="margin:0 0 10px;">CloudScale solves this with a JavaScript beacon that fires after the browser loads the page, regardless of where the HTML came from. The beacon POSTs to a REST endpoint that bypasses the CDN cache, so every real visit is counted. <strong>You finally see your actual traffic.</strong></p>
<p style="margin:0 0 10px;">Unlike Google Analytics or Jetpack, your visitor data never leaves your server. No third-party scripts, no tracking pixels, no GDPR headaches from external data processors. IP addresses are hashed before storage — the raw IP is never written to the database.</p>
<p style="margin:0;"><strong>It is completely free.</strong> No premium tier. No per-site fees. Install it, add one Cloudflare Cache Rule, and your stats are accurate from the first pageview.</p>
</div>
<p>The <strong>Statistics Dashboard</strong> shows page view data stored directly in your WordPress database across five custom tables (<code>wp_cspv_views_v2</code>, <code>wp_cspv_referrers_v2</code>, <code>wp_cspv_geo_v2</code>, <code>wp_cspv_visitors_v2</code>, <code>wp_cspv_404_v2</code>). No data is ever sent to Google, Facebook, or any external service.</p>
<p><strong>How tracking works:</strong> A lightweight <code>beacon.js</code> script fires a POST request to the WordPress REST API endpoint <code>/wp-json/cloudscale-wordpress-free-analytics/v1/record/{post_id}</code> after the page has fully loaded. Because this is a fresh HTTP request — not served from cache — it reaches WordPress even when Cloudflare or another CDN is serving the cached HTML page. This is why beacon-based tracking counts every real visit, whereas server-side counters miss 80–95% of views on cached sites.</p>
<h3>Views, Top Posts &amp; Referrers</h3>
<ul>
<li><strong>Period selector</strong> — switch between Today, 7 days, 30 days, 90 days, and All time. Each period queries the <code>wp_cspv_views_v2</code> table directly; no aggregation tables are needed.</li>
<li><strong>Totals bar</strong> — Total Views, Unique Posts Viewed, and Lifetime totals pulled in a single indexed query on <code>viewed_at</code>.</li>
<li><strong>Views chart</strong> — daily bar chart. Gaps indicate days with zero traffic (not missing data).</li>
<li><strong>Most Viewed (Period)</strong> — top posts ranked by view count for the selected period, with direct edit links.</li>
<li><strong>Referrers</strong> — parsed from the <code>document.referrer</code> value sent by the beacon; stored in <code>wp_cspv_referrers_v2</code>. Toggle between <strong>Sites</strong> (referrer domain) and <strong>Pages</strong> (full referring URL). Direct traffic (no referrer) appears as "Direct".</li>
</ul>
<h3>All Time Top Posts</h3>
<p>A separate ranked table showing lifetime view counts across all time — independent of the period selector. Includes imported Jetpack view counts blended with live beacon data if you migrated from Jetpack Stats. Useful for identifying your most valuable evergreen content.</p>
<h3>Cloudflare Cache Bypass</h3>
<p>An interactive test panel that verifies your Cloudflare Cache Rule is correctly configured to bypass caching for the beacon REST endpoint. Without this rule, Cloudflare caches the REST responses and beacon POSTs fail silently — your view counts appear to record but nothing is actually written.</p>
<p><strong>Required Cache Rule:</strong> In your Cloudflare dashboard → Caching → Cache Rules → create a rule: URI Path <em>contains</em> <code>/wp-json/cloudscale-wordpress-free-analytics/</code> → Cache Status: <em>Bypass</em>.</p>
<p>Click <strong>Test Cache Bypass</strong> to send a probe request through the beacon endpoint and check whether the response has the expected headers. A green status badge confirms Cloudflare is bypassing the cache correctly; a red badge means the rule is missing or misconfigured.</p>
<h3>404 Error Log</h3>
<p>Tracks every 404 (page not found) response served by your site and logs the requested URL, referrer, and timestamp. Stored in <code>wp_cspv_404_v2</code>. Useful for finding broken links from external sites, detecting content that has moved without a redirect, and identifying crawler probing paths.</p>
<p>The log shows the most recent 404 events with the requested path and the source (referrer or direct). Use this to set up <code>301</code> redirects for your most-hit missing pages before they cost you rankings or visitor trust.</p>
<p><strong>Privacy:</strong> visitor IP addresses are hashed with SHA-256 combined with your site's <code>wp_salt</code> before storage — the raw IP is never written to the database. The hash is used only for deduplication and throttle checks.</p>`,

        'geography': `
<p>An interactive world map showing where your visitors come from, powered by data stored in <code>wp_cspv_geo_v2</code>. Country-level tracking is built into the beacon — no third-party geolocation service is used.</p>
<ul>
<li><strong>World map</strong> — bubble markers sized by relative visit volume. Click any country bubble to drill down to a city-level breakdown for that country.</li>
<li><strong>Country list</strong> — ranked list of countries with visit counts for the selected period, shown below the map.</li>
<li><strong>Reset Map</strong> — clears any active country drill-down and returns to the full world view.</li>
</ul>
<p>The geography date range matches your currently selected period (Today, 7 days, 30 days, etc.). Country data is captured via the Cloudflare <code>CF-IPCountry</code> header when available; otherwise the plugin uses the DB-IP Lite database or falls back to the site's configured geo source. Configure this in the <strong>Display Settings</strong> tab under Geography Source.</p>`,

        'display': `
<p>The <strong>Display Settings</strong> tab controls how view counts appear on your site, configures the sidebar widgets, and provides data management tools.</p>
<h3>View Counter Display</h3>
<ul>
<li><strong>Display position</strong> — before post content, after post content, both, or off. The count is read from the <code>_cspv_view_count</code> post meta key, which is synced from <code>wp_cspv_views_v2</code> on each beacon hit.</li>
<li><strong>Counter style</strong> — choose between Badge (coloured pill with gradient), Pill (outlined), or Minimal (plain text). Five colour options: blue, pink, red, purple, grey.</li>
<li><strong>Exclude post types</strong> — comma-separated list of post types to skip tracking (e.g. <code>product</code> for WooCommerce). Excluded types do not fire the beacon and are not counted in the dashboard.</li>
</ul>
<h3>Widgets</h3>
<ul>
<li><strong>Top Posts widget</strong> — register via <em>Appearance → Widgets</em> or the block widget editor. Queries <code>wp_cspv_views_v2</code> for a configurable view window (default: all time). Settings: total posts to pool (default 10), posts per page, thumbnail width, and sort order (most viewed or most recent).</li>
<li><strong>Recent Posts widget</strong> — shows the most recently published posts with view counts. Configurable post count and optional publication date display.</li>
</ul>
<h3>Data Management</h3>
<p>The <strong>Purge Visitor Hashes</strong> tool removes historical unique visitor tracking data from the <code>wp_cspv_visitors_v2</code> table older than a selected threshold (30, 60, 90, 180 days, 1 year, or all data). The table records hashed IP addresses for deduplication and throttle checks — it grows indefinitely unless periodically purged.</p>
<p>Purging old visitor hashes frees database storage but removes the ability to deduplicate views from that period retroactively. View counts already recorded in <code>wp_cspv_views_v2</code> are not affected — only the visitor hash records used for future deduplication are removed. The panel shows the current row count and date range before you purge.</p>`,

        'throttle': `
<p>The <strong>IP Throttle</strong> tab has four panels that work together to prevent inflated statistics from bots, repeat loads, and your own browsing.</p>
<h3>IP Throttle Protection</h3>
<p>Automatically blocks IP addresses that send an excessive number of beacon requests within a rolling time window — typically aggressive scrapers, bots, or misconfigured load tests. Blocked IPs receive an HTTP 200 response (silent drop) so attackers have no signal to change behaviour. Blocks auto-expire after 1 hour.</p>
<ul>
<li><strong>Enable protection</strong> — toggle to activate automatic IP blocking.</li>
<li><strong>Block after</strong> — maximum number of requests per IP within the time window before the IP is blocked. Default: configurable.</li>
<li><strong>Time window</strong> — the rolling window for counting requests: 10 minutes, 30 minutes, 1 hour, 2 hours, or 24 hours.</li>
<li><strong>Exclude logged-in users</strong> — prevents any authenticated WordPress session from being counted. Detected via the <code>logged_in_{hash}</code> cookie in the beacon.</li>
<li><strong>Exclude administrators</strong> — more granular: only users with the <code>administrator</code> role are excluded. Editors, Authors, and Contributors are still counted.</li>
</ul>
<h3>View Deduplication</h3>
<p>Prevents the same visitor from inflating view counts by visiting the same post multiple times within a configurable window. Works at two levels simultaneously:</p>
<ul>
<li><strong>Client-side (localStorage)</strong> — the beacon records a key in <code>localStorage</code> per post on first fire. Subsequent visits to the same post from the same browser do not fire the beacon again within the window. Catches duplicate views from in-app browsers (e.g. WhatsApp opening a link, then the user opening it again in Chrome).</li>
<li><strong>Server-side (IP + post ID lookup)</strong> — the REST endpoint checks <code>wp_cspv_views_v2</code> for a recent row matching the same hashed IP and <code>post_id</code> within the dedup window. This catches duplicates from clients that clear localStorage or use private browsing.</li>
</ul>
<p>Configure the <strong>dedup window</strong> (1 hour to 48 hours). Setting it shorter counts repeat visits within a day; longer prevents the same reader from contributing more than once per session period.</p>
<h3>Blocked IPs</h3>
<p>Shows all IPs currently blocked by the throttle system. IPs are stored as one-way SHA-256 hashes — they cannot be reversed to a real IP address. Each entry shows the block timestamp and time remaining until auto-expiry.</p>
<ul>
<li><strong>Unblock</strong> — removes a specific IP hash from the blocklist immediately, before the auto-expiry timer.</li>
<li><strong>Clear All</strong> — removes all blocked IPs at once. Use after a false-positive event (e.g. a load test triggered the throttle).</li>
</ul>
<h3>Page Tracking</h3>
<p>An emergency kill switch that instantly stops all view tracking across the entire site. When paused, the tracking beacon script is not loaded on any page and the REST recording endpoint silently rejects all requests. Historical data is fully preserved.</p>
<p>Use this during content imports, database migrations, load tests, or any period when you do not want views recorded. The status badge on the panel header shows <strong>TRACKING ACTIVE</strong> (green) or <strong>TRACKING PAUSED</strong> (red) at a glance. Toggle it off to resume normal tracking immediately.</p>`,

        'migrate': `
<p>The <strong>Migrate Jetpack Stats</strong> tab imports your historical Jetpack view counts into CloudScale Analytics so you don't lose years of traffic data when switching away from Jetpack. After migration you can safely disable the Jetpack Stats module or uninstall Jetpack entirely.</p>
<p><strong>Migration runs once</strong> — a lock prevents accidental double-counting. If you have already migrated and need to re-run (e.g. after importing additional Jetpack export data), click <strong>Reset Lock</strong> first.</p>
<h3>Workflow</h3>
<ol>
<li><strong>Check Jetpack Data</strong> — scans your database for Jetpack view counts and shows a pre-flight summary: how many posts have Jetpack stats and how many views will be imported. No data is written at this stage.</li>
<li><strong>Choose import mode</strong>:
  <ul>
  <li><strong>Additive</strong> (default) — Jetpack view counts are added on top of any existing CloudScale counts for each post. Use this if CloudScale has been running alongside Jetpack and you want to combine the two histories.</li>
  <li><strong>Replace</strong> — CloudScale view counts are overwritten with the Jetpack totals. Use this if CloudScale was just installed and has no meaningful data yet.</li>
  </ul>
</li>
<li><strong>Run Migration</strong> — imports all found view counts. The result shows total posts migrated, views imported, and posts skipped. A migration history log is saved and displayed below the panel.</li>
</ol>
<h3>Manual CSV Import</h3>
<p>If Jetpack stored your stats on WordPress.com (cloud-only mode) rather than locally, the automatic check will find no data. In this case use the CSV import: log in to WordPress.com, go to your site's Stats, scroll to the bottom, and export a CSV. Paste post slug or ID and view count pairs (one per line, comma-separated) into the text area and click <strong>Import CSV Data</strong>.</p>
<h3>Jetpack Data Management</h3>
<p><strong>Delete Jetpack Data</strong> — permanently removes the Jetpack stats tables and option rows from your database once you have confirmed the migration was successful and you no longer need the original data. This is irreversible.</p>`,

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
