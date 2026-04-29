'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Site Analytics: Free Privacy-First WordPress Analytics That Works Behind Cloudflare and Any CDN',
    pluginDesc: 'Most WordPress analytics plugins miss 40–95% of your traffic when Cloudflare, WP Rocket, or any CDN is caching your pages. CloudScale Site Analytics uses a lightweight JavaScript beacon that fires on every page load regardless of server-side caching, stores all data in your own WordPress database, and shows real view counts in your posts list. No Google Analytics, no Google Tag Manager, no Jetpack, no Automattic, no third-party tracking. Zero GDPR risk, no cookie consent banner needed. Works with Cloudflare, WP Rocket, LiteSpeed Cache, and all major caching plugins. Completely free, open source, no subscription.',
    seoTitle:   'CloudScale Site Analytics | Free WordPress Analytics: Works Behind Cloudflare & CDN, No Google',
    seoDesc:    'Free WordPress analytics that counts every visit even behind Cloudflare & CDN. Privacy-first: no Google, no tracking pixels, data on your own server. Works with WP Rocket & LiteSpeed. No subscription.',
    schema: {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: 'CloudScale Site Analytics',
        operatingSystem: 'WordPress',
        applicationCategory: 'WebApplication',
        offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
        softwareVersion: '2.9.252',
        downloadUrl: 'https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip',
        url: 'https://github.com/andrewbakercloudscale/wordpress-free-analytics',
    },
    pageTitle:  'CloudScale Site Analytics: Free Privacy-First WordPress Analytics That Works Behind Cloudflare and Any CDN',
    pageSlug:   'cloudscale-wordpress-marketing-analytics',
    downloadUrl: 'https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip',
    repoUrl:     'https://github.com/andrewbakercloudscale/wordpress-free-analytics',

    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics`,
    logoFile:   `${__dirname}/../cloudscale-analytics-icon.jpg`,

    pluginFile: `${__dirname}/../stats-page.php`,

    pluginIntro: `
<div style="background:linear-gradient(135deg,#f0f9ff 0%,#e8f5e9 100%);border:1px solid #bae6fd;border-radius:12px;padding:32px 36px;margin-bottom:36px;">
<p style="margin:0 0 8px;font-size:1.5em;font-weight:800;color:#0f172a;">Why Your WordPress Analytics Are Lying to You</p>
<p style="margin:0 0 24px;font-size:1.05em;color:#475569;">If your site runs behind Cloudflare, WP Rocket, or any caching plugin, server-side analytics see only the 5–20% of requests that reach PHP. The other 80–95% are served directly from cache and never counted. CloudScale fixes this with a JavaScript beacon that fires after the browser loads the page, regardless of where the HTML came from. Every real visit is counted.</p>
<table style="width:100%;border-collapse:collapse;font-size:.95em;margin:0 0 24px;">
<thead><tr style="background:#0f172a;color:#fff;">
<th style="padding:10px 14px;text-align:left;border-radius:6px 0 0 0;">Feature</th>
<th style="padding:10px 14px;text-align:center;">Google Analytics</th>
<th style="padding:10px 14px;text-align:center;">Jetpack Stats</th>
<th style="padding:10px 14px;text-align:center;">Server-side counters</th>
<th style="padding:10px 14px;text-align:center;background:#1a7a34;border-radius:0 6px 0 0;">CloudScale</th>
</tr></thead>
<tbody>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Works behind Cloudflare/CDN</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Data stays on your server</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗ (Google)</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗ (Automattic)</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">No cookie consent banner needed</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">View counts in posts list</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Free, no subscription</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">Paid tiers</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">Paid tiers</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Top pages per referrer drill-down</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#94a3b8;">Custom reports</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Geography / country tracking</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#94a3b8;">Requires consent</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">404 error log</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#94a3b8;">Custom setup</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#94a3b8;">Log files only</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">No external scripts loaded</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗ (Google CDN)</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗ (wp.com)</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;">✓</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
<tr style="background:#fff;">
<td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;">Open source</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#dc2626;">✗</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#94a3b8;">Varies</td>
<td style="padding:8px 14px;border:1px solid #e2e8f0;text-align:center;color:#16a34a;font-weight:700;">✓</td>
</tr>
</tbody>
</table>
<p style="margin:0 0 16px;font-weight:700;color:#0f172a;">Setup checklist (5 minutes):</p>
<ol style="margin:0 0 0 1.3em;padding:0;font-size:1em;color:#334155;line-height:2;">
<li><strong>Download and install</strong> the plugin: grab the zip from <a href="https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip" style="color:#16a34a;font-weight:700;">S3 (direct download)</a> or clone from <a href="https://github.com/andrewbakercloudscale/wordpress-free-analytics" target="_blank" rel="noopener" style="color:#24292f;font-weight:700;">GitHub</a>, then upload via <em>Plugins → Add New → Upload Plugin</em>. The beacon starts counting immediately on activation.</li>
<li><strong>Cloudflare Cache Rule</strong>: in your Cloudflare dashboard, create a Cache Rule: URI Path contains <code>/wp-json/cloudscale-wordpress-free-analytics/</code>, Cache Status: Bypass. This is the critical step for CDN-accurate counting.</li>
<li><strong>Test Cache Bypass</strong>: click the button on the Statistics tab to confirm the Cloudflare rule is working. A green badge means every visit will be counted.</li>
<li><strong>IP Throttle</strong>: enable bot protection and configure your deduplication window so repeat reloads don't inflate counts.</li>
<li><strong>Top Posts widget</strong>: add it to your sidebar via Appearance > Widgets so readers can discover your most popular content.</li>
</ol>
</div>`,

    sections: [
        {
            id: 'statistics', label: 'Statistics Dashboard', file: 'panel-statistics.png', tab: 'stats',
            intro: 'The main analytics dashboard showing page views over time, top posts, referrer sources, a Cloudflare cache bypass tester, and 404 error log. All data is stored in your own WordPress database with no data sent to any third party.',
        },
        {
            id: 'geography', label: 'Geography', file: 'panel-geography.png', tab: 'stats',
            elementSelector: '#cspv-geo-panel',
            intro: 'An interactive world map showing where your visitors come from, built from country data captured at the time of each beacon hit. Supports drill-down to city level for any country.',
        },
        {
            id: 'display', label: 'Display Settings', file: 'panel-display.png', tab: 'display',
            intro: 'Controls where view count badges appear on your posts, the visual style and colour of counters, which post types are tracked, and the geography source configuration.',
        },
        {
            id: 'throttle', label: 'IP Throttle', file: 'panel-throttle.png', tab: 'throttle',
            intro: 'Four panels for keeping your stats accurate: automatic bot blocking by request rate, client and server-side view deduplication, blocked IP management, and an emergency switch to pause all tracking.',
        },
        {
            id: 'insights', label: 'Insights', file: 'panel-insights.png', tab: 'insights',
            intro: 'A rich analytics dashboard showing how your content performs across traffic sources, referrer domains, geography, and time — with KPI cards, pie charts, line charts, a referrer timeline, and a top-posts-by-referrer breakdown table. Includes a Self toggle to filter internal navigation from all charts at once.',
        },
    ],

    docs: {

'statistics': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:28px;">
<h2 style="margin:0 0 10px;font-size:1.3em;color:#0f172a;">Why CloudScale Site Analytics?</h2>
<p style="margin:0 0 10px;">If your site runs behind Cloudflare, WP Rocket, or any other caching layer, server-side analytics see only the 5–20% of requests that reach PHP. Your stats are wrong by a factor of 5 to 10.</p>
<p style="margin:0 0 10px;">CloudScale solves this with a JavaScript beacon that fires after the browser loads the page, regardless of where the HTML came from. The beacon POSTs to a REST endpoint that bypasses the CDN cache, so every real visit is counted. <strong>You finally see your actual traffic.</strong></p>
<p style="margin:0 0 10px;">Unlike Google Analytics or Jetpack, your visitor data never leaves your server. No third-party scripts, no tracking pixels, no GDPR headaches from external data processors. IP addresses are hashed before storage; the raw IP is never written to the database.</p>
<p style="margin:0;"><strong>It is completely free.</strong> No premium tier. No per-site fees. Install it, add one Cloudflare Cache Rule, and your stats are accurate from the first pageview.</p>
</div>
<p>The <strong>Statistics Dashboard</strong> shows page view data stored directly in your WordPress database across five custom tables (<code>wp_cspv_views_v2</code>, <code>wp_cspv_referrers_v2</code>, <code>wp_cspv_geo_v2</code>, <code>wp_cspv_visitors_v2</code>, <code>wp_cspv_404_v2</code>). No data is ever sent to Google, Facebook, or any external service.</p>
<p><strong>How tracking works:</strong> A lightweight <code>beacon.js</code> script fires a POST request to the WordPress REST API endpoint <code>/wp-json/cloudscale-wordpress-free-analytics/v1/record/{post_id}</code> after the page has fully loaded. Because this is a fresh HTTP request rather than a cached response, it reaches WordPress even when Cloudflare or another CDN is serving the cached HTML page. This is why beacon-based tracking counts every real visit, whereas server-side counters miss 80–95% of views on cached sites.</p>
<h3>Views, Top Posts and Referrers</h3>
<ul>
<li><strong>Period selector</strong>: switch between Today, 7 days, 30 days, 90 days, and All time. Each period queries the <code>wp_cspv_views_v2</code> table directly; no aggregation tables are needed.</li>
<li><strong>Totals bar</strong>: Total Views, Unique Posts Viewed, and Lifetime totals pulled in a single indexed query on <code>viewed_at</code>.</li>
<li><strong>Views chart</strong>: daily bar chart. Gaps indicate days with zero traffic, not missing data.</li>
<li><strong>Most Viewed (Period)</strong>: top posts ranked by view count for the selected period, with direct edit links.</li>
<li><strong>Referrers</strong>: parsed from the <code>document.referrer</code> value sent by the beacon and stored in <code>wp_cspv_referrers_v2</code>. Toggle between <strong>Sites</strong> (referrer domain) and <strong>Pages</strong> (full referring URL). Direct traffic with no referrer appears as "Direct". Click any referrer domain to see a <strong>Top Pages</strong> breakdown: which of your posts and pages that source sent the most traffic to, with view counts and a Copy button.</li>
</ul>
<h3>All Time Top Posts</h3>
<p>A separate ranked table showing lifetime view counts across all time, independent of the period selector. Includes imported Jetpack view counts blended with live beacon data if you migrated from Jetpack Stats. Useful for identifying your most valuable evergreen content.</p>
<h3>Cloudflare Cache Bypass</h3>
<p>An interactive test panel that verifies your Cloudflare Cache Rule is correctly configured to bypass caching for the beacon REST endpoint. Without this rule, Cloudflare caches the REST responses and beacon POSTs fail silently; your view counts appear to record but nothing is actually written.</p>
<p><strong>Required Cache Rule:</strong> In your Cloudflare dashboard, go to Caching, then Cache Rules, and create a rule: URI Path <em>contains</em> <code>/wp-json/cloudscale-wordpress-free-analytics/</code>, Cache Status: <em>Bypass</em>.</p>
<p>Click <strong>Test Cache Bypass</strong> to send a probe request through the beacon endpoint and check whether the response has the expected headers. A green status badge confirms Cloudflare is bypassing the cache correctly; a red badge means the rule is missing or misconfigured.</p>
<h3>404 Error Log</h3>
<p>Tracks every 404 (page not found) response served by your site and logs the requested URL, referrer, and timestamp. Stored in <code>wp_cspv_404_v2</code>. Useful for finding broken links from external sites, detecting content that has moved without a redirect, and identifying crawler probing paths.</p>
<p>The log shows the most recent 404 events with the requested path and the source (referrer or direct). Use this to set up 301 redirects for your most-hit missing pages before they cost you rankings or visitor trust.</p>
<p><strong>Privacy:</strong> visitor IP addresses are hashed with SHA-256 combined with your site's <code>wp_salt</code> before storage. The raw IP is never written to the database. The hash is used only for deduplication and throttle checks.</p>
<h3>Download &amp; Source Code</h3>
<p><strong>Direct download (S3):</strong> <a href="https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-wordpress-free-analytics.zip">cloudscale-wordpress-free-analytics.zip</a> — always the latest stable release. Upload via <em>Plugins → Add New → Upload Plugin</em> in wp-admin.</p>
<p><strong>Source code (GitHub):</strong> <a href="https://github.com/andrewbakercloudscale/wordpress-free-analytics" target="_blank" rel="noopener">github.com/andrewbakercloudscale/wordpress-free-analytics</a> — MIT licensed, issues and pull requests welcome.</p>`,

'geography': `
<p>An interactive world map showing where your visitors come from, powered by data stored in <code>wp_cspv_geo_v2</code>. Country-level tracking is built into the beacon; no third-party geolocation service is used.</p>
<ul>
<li><strong>World map</strong>: bubble markers sized by relative visit volume. Click any country bubble to drill down to a city-level breakdown for that country.</li>
<li><strong>Country list</strong>: ranked list of countries with visit counts for the selected period, shown below the map.</li>
<li><strong>Reset Map</strong>: clears any active country drill-down and returns to the full world view.</li>
</ul>
<p>The geography date range matches your currently selected period (Today, 7 days, 30 days, etc.). Country data is captured via the Cloudflare <code>CF-IPCountry</code> header when available; otherwise the plugin uses the DB-IP Lite database or falls back to the site's configured geo source. Configure this in the <strong>Display Settings</strong> tab under Geography Source.</p>`,

'display': `
<p>The <strong>Display Settings</strong> tab controls how view counts appear on your site, configures the sidebar widgets, and provides data management tools.</p>
<h3>View Counter Display</h3>
<ul>
<li><strong>Display position</strong>: before post content, after post content, both, or off. The count is read from the <code>_cspv_view_count</code> post meta key, which is synced from <code>wp_cspv_views_v2</code> on each beacon hit.</li>
<li><strong>Counter style</strong>: choose between Badge (coloured pill with gradient), Pill (outlined), or Minimal (plain text). Five colour options: blue, pink, red, purple, grey.</li>
<li><strong>Icon and suffix</strong>: customise the icon character (default: 👁) and the text that follows the count (default: "views").</li>
<li><strong>Post types to display on</strong>: which post types show the view counter. Unselected types will not display the badge.</li>
<li><strong>Post types to track</strong>: which post types fire the beacon and record views. Useful for excluding WooCommerce products, landing pages, or other post types you don't want counted.</li>
</ul>
<h3>Widgets</h3>
<ul>
<li><strong>Top Posts widget</strong>: register via Appearance > Widgets or the block widget editor. Queries <code>wp_cspv_views_v2</code> for a configurable view window (default: all time). Settings: total posts to pool (default 10), posts per page, thumbnail width, and sort order (most viewed or most recent).</li>
<li><strong>Recent Posts widget</strong>: shows the most recently published posts with view counts. Configurable post count and optional publication date display.</li>
</ul>
<h3>Geography Source</h3>
<p>Controls how visitor country is resolved for the geography map and country breakdown.</p>
<ul>
<li><strong>Auto</strong>: tries Cloudflare first (zero performance cost), then falls back to DB-IP if the CF-IPCountry header is absent. Recommended for most sites.</li>
<li><strong>Cloudflare Only</strong>: uses only the <code>CF-IPCountry</code> header. Fast and accurate but requires your site to be proxied through Cloudflare.</li>
<li><strong>DB-IP Only</strong>: always uses the local database file. Works without Cloudflare but adds a small lookup overhead per request.</li>
<li><strong>Disabled</strong>: skips geography tracking entirely. The map and country stats will show no data.</li>
</ul>
<p>The DB-IP Lite database (~30 MB) is stored in your uploads folder and updates monthly. Click <strong>Download DB-IP</strong> to install or refresh it.</p>
<h3>Data Management</h3>
<p>The <strong>Purge Visitor Hashes</strong> tool removes historical unique visitor tracking data from the <code>wp_cspv_visitors_v2</code> table older than a selected threshold (30, 60, 90, 180 days, 1 year, or all data). This table records hashed IP addresses for deduplication and throttle checks; it grows indefinitely unless periodically purged.</p>
<p>Purging old visitor hashes frees database storage but removes the ability to deduplicate views from that period retroactively. View counts already recorded in <code>wp_cspv_views_v2</code> are not affected. The panel shows the current row count and date range before you purge.</p>`,

'throttle': `
<p>The <strong>IP Throttle</strong> tab has four panels that work together to prevent inflated statistics from bots, repeat loads, and your own browsing.</p>
<h3>IP Throttle Protection</h3>
<p>Automatically blocks IP addresses that send an excessive number of beacon requests within a rolling time window, typically aggressive scrapers, bots, or misconfigured load tests. Blocked IPs receive an HTTP 200 response (silent drop) so attackers have no signal to change behaviour. Blocks auto-expire after 1 hour.</p>
<ul>
<li><strong>Enable protection</strong>: toggle to activate automatic IP blocking.</li>
<li><strong>Block after</strong>: maximum number of requests per IP within the time window before the IP is blocked.</li>
<li><strong>Time window</strong>: the rolling window for counting requests: 10 minutes, 30 minutes, 1 hour, 2 hours, or 24 hours.</li>
<li><strong>Exclude logged-in users</strong>: prevents any authenticated WordPress session from being counted. Detected via the <code>logged_in_{hash}</code> cookie in the beacon.</li>
<li><strong>Exclude administrators</strong>: more granular; only users with the <code>administrator</code> role are excluded. Editors, Authors, and Contributors are still counted.</li>
</ul>
<h3>View Deduplication</h3>
<p>Prevents the same visitor from inflating view counts by visiting the same post multiple times within a configurable window. Works at two levels simultaneously:</p>
<ul>
<li><strong>Client-side (localStorage)</strong>: the beacon records a key in <code>localStorage</code> per post on first fire. Subsequent visits to the same post from the same browser do not fire the beacon again within the window. Catches duplicate views from in-app browsers (e.g. WhatsApp opening a link, then the user opening it again in Chrome).</li>
<li><strong>Server-side (IP and post ID lookup)</strong>: the REST endpoint checks <code>wp_cspv_views_v2</code> for a recent row matching the same hashed IP and <code>post_id</code> within the dedup window. This catches duplicates from clients that clear localStorage or use private browsing.</li>
</ul>
<p>Configure the <strong>dedup window</strong> (1 hour to 48 hours). Setting it shorter counts repeat visits within a day; longer prevents the same reader from contributing more than once per session period.</p>
<h3>Blocked IPs</h3>
<p>Shows all IPs currently blocked by the throttle system. IPs are stored as one-way SHA-256 hashes; they cannot be reversed to a real IP address. Each entry shows the block timestamp and time remaining until auto-expiry.</p>
<ul>
<li><strong>Unblock</strong>: removes a specific IP hash from the blocklist immediately, before the auto-expiry timer.</li>
<li><strong>Clear All</strong>: removes all blocked IPs at once. Use after a false-positive event (e.g. a load test triggered the throttle).</li>
</ul>
<h3>Page Tracking</h3>
<p>An emergency kill switch that instantly stops all view tracking across the entire site. When paused, the tracking beacon script is not loaded on any page and the REST recording endpoint silently rejects all requests. Historical data is fully preserved.</p>
<p>Use this during content imports, database migrations, load tests, or any period when you do not want views recorded. The status badge on the panel header shows <strong>TRACKING ACTIVE</strong> (green) or <strong>TRACKING PAUSED</strong> (red) at a glance. Toggle it off to resume normal tracking immediately.</p>`,

'insights': `
<p>The <strong>Insights</strong> tab provides a rich analytics dashboard loaded on demand when you open the tab. Use the period buttons (7 / 30 / 90 / 180 days) in the header to change the time window — all charts update together. Each chart has an <strong>? Explain</strong> button that opens context-specific documentation inline.</p>
<h3>Self Toggle</h3>
<p>The <strong>Self: ON/OFF</strong> button at the top of the Insights tab filters <em>Self</em> traffic — visits where your own domain was the HTTP referrer — out of every chart, table, and KPI card simultaneously, without re-fetching data from the server. For sites where internal navigation accounts for the majority of referrals (often 80–90%), toggling Self OFF makes it far easier to evaluate external acquisition channels. Toggle it back ON to include all traffic again.</p>
<h3>KPI Cards</h3>
<p>Four summary metrics for the selected period: <strong>Total Views</strong>, <strong>Unique Visitors</strong> (distinct visitor hashes), <strong>Top Country</strong> (most views by geography), and <strong>Top Referrer</strong> (highest-traffic source domain). When Self is OFF, Total Views, Unique Visitors, and Top Referrer all exclude Self traffic so the numbers reflect external acquisition only.</p>
<h3>Traffic Sources</h3>
<p>A doughnut chart breaking all views by how the visitor arrived. Known search engines (Google, Bing, DuckDuckGo, Yandex, Baidu, Ecosia) and social networks (LinkedIn, Facebook, Twitter/X, Reddit, Instagram, Pinterest) get their own labelled slices. <strong>Direct</strong> means no referrer header was sent — typed URL, bookmark, or email client. <strong>Self</strong> is internal navigation from your own domain (a reader clicking from one post to another). Slices that share similar hues automatically receive distinct canvas hatch patterns so they stay distinguishable at a glance.</p>
<h3>Referrer Growth Timeline</h3>
<p>A multi-line chart showing how each traffic source has trended over time. Known engines and social networks appear under their brand name; your own domain appears as <strong>Self</strong>; every other external site appears under its own hostname (e.g. <em>news.ycombinator.com</em>). Each line has a unique colour from a 20-hue vivid palette, a distinct dash pattern, and a distinct point shape so they remain readable even when many sources overlap. Periods of 30 days or fewer use daily buckets; longer periods use weekly buckets (ISO week start). Sources with zero traffic are hidden automatically.</p>
<h3>Top Posts by Views</h3>
<p>A horizontal bar chart ranking your most-viewed content for the selected period. Each bar uses a distinct colour from the vivid 20-hue palette; bars that would share a similar hue automatically receive a hatch pattern fill instead, so every bar is visually distinct. Hover any bar to see the exact view count.</p>
<h3>Top Posts by Referrer</h3>
<p>A table showing up to 20 of your top posts with a view-count breakdown by traffic source. Columns are the top referrer sources for the period — including <strong>Self</strong> (internal navigation), named search engines, social platforms, and external domains. Rows alternate between white and a soft blue background for readability. Dashes indicate zero views from that source. Use this to identify which posts rank in Google, which spread on social, and which are discovered through your own internal links.</p>
<h3>Views by Country</h3>
<p>A doughnut chart showing the top 10 countries by view count for the selected period, built from the geo table. Hover for exact counts and percentages.</p>
<h3>Top Countries Over Time</h3>
<p>A multi-line chart for the top 5 countries, showing how geographic traffic shifts over the selected period. Useful for spotting when a post goes viral in a specific market or when SEO efforts in a new region start paying off.</p>
<h3>Top Referrer Domains</h3>
<p>A horizontal bar chart ranking the top referring domains. <strong>Self</strong> appears here when your own domain is the top source through internal navigation. Each bar uses a distinct vivid colour; similar hues get a hatch pattern automatically. Aggregated from full referrer URLs stored in <code>wp_cspv_referrers_v2</code>. Useful for identifying which sites, directories, or communities send you the most traffic.</p>`,

    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
