=== CloudScale Free Analytics ===
Contributors: andrewbaker
Tags: page views, analytics, statistics, view counter, free analytics
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.9.152
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accurate page view tracking for WordPress sites behind Cloudflare and other CDNs. Counts every view even when HTML is served from cache.

== Description ==

CloudScale Free Analytics solves the fundamental problem with page view tracking on cached WordPress sites. When Cloudflare, Fastly, or any CDN serves a cached HTML page, WordPress never executes. Server side counters like Jetpack Stats miss the view entirely, resulting in severe undercounting — typically 5 to 10 times lower than actual traffic.

CloudScale uses a lightweight JavaScript beacon that fires after the cached page loads, recording every view through a REST API endpoint that bypasses the CDN cache. The result is accurate view counts regardless of cache status.

= Key Features =

* Accurate counting behind Cloudflare and other CDNs
* Session based deduplication (no double counting on refresh)
* IP throttle protection against view inflation attacks
* Jetpack Stats one click migration tool
* Dashboard widget with hourly, daily, monthly, and 6 month charts
* Top Posts and Recent Posts sidebar widgets with pagination
* Configurable view counter badge on posts (badge, pill, or minimal style)
* Five colour options for the view counter (blue, pink, red, purple, grey)
* Admin column showing views on the Posts list
* Full statistics page with period comparison and top posts
* REST API endpoints for custom integrations
* Privacy focused: IP addresses are hashed with wp_salt, never stored raw

= How It Works =

1. Cloudflare serves the cached HTML page at full speed
2. The browser loads the page and executes the beacon script
3. The beacon fires a lightweight POST to a WordPress REST endpoint
4. That endpoint bypasses CDN caching via headers and Cloudflare Cache Rules
5. WordPress records the view in the database and increments the counter
6. The page counter updates live without a reload

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* A Cloudflare account with Cache Rules (free tier is sufficient)

== Installation ==

1. Upload the `cloudscale-wordpress-free-analytics` folder to `/wp-content/plugins/`
2. Activate the plugin in Plugins > Installed Plugins
3. The database table is created automatically on activation
4. Add the Cloudflare Cache Rule (see FAQ)
5. Configure display settings in Tools > CloudScale Free Analytics > Display tab

== Frequently Asked Questions ==

= What Cloudflare Cache Rule do I need? =

In the Cloudflare dashboard go to Caching > Cache Rules > Create Rule:

* Field: URI Path
* Operator: contains
* Value: /wp-json/cloudscale-wordpress-free-analytics/
* Action: Cache Status: Bypass

The plugin also sends no cache headers on every REST response, but the Cache Rule is the primary protection.

= How do I migrate from Jetpack Stats? =

Go to Tools > CloudScale Free Analytics > Migrate Jetpack tab. Click the migration button to import all Jetpack lifetime view counts into CloudScale. The migration preserves your historical totals as a starting point and the plugin blends them with new beacon data during a 28 day transition period.

= Does it work without Cloudflare? =

Yes. The beacon approach works on any WordPress site. The Cloudflare Cache Rule is only needed if you use Cloudflare caching. Without Cloudflare, the REST endpoint is already accessible and the plugin works out of the box.

= Does refreshing the page count as another view? =

No. The beacon uses sessionStorage to deduplicate views. Each browser session records only one view per post. A new tab or new session counts as a new view.

= How is visitor privacy protected? =

IP addresses are hashed using SHA256 combined with your site wp_salt before storage. Raw IP addresses are never written to the database. The IP hash is used only for throttle protection.

== Changelog ==

= 2.9.94 =
* Unique visitors tracking with SHA-256 hashed IPs (never stored raw)
* Date range swap fix on Statistics page
* Geography map robustness improvements
* WordPress.org submission hardening: all assets bundled locally, inline style/script tags replaced with wp_enqueue APIs, uninstall.php added

= 2.9.12 =
* Site Health v2: complete rewrite — four time windows (1 Day, 7 Days, 28 Days, 90 Days) each with RAG indicator and prior-period comparison
* Insufficient Data gating: buckets without enough history show waiting state instead of misleading percentages
* Site Health results cached in wp_options for 1 hour

= 2.9.11 =
* Site Health panel on dashboard widget and Statistics page with red/amber/green indicators
* Traffic Growth and Hot Pages metrics across 7d, 30d, 90d windows

= 2.9.10 =
* Post History tab on Statistics page: search any post and see full diagnostics including log count, Jetpack imported count, and 90-day chart

= 2.9.9 =
* View Diagnostics debug panel on singular posts (admin only) with resync button

= 2.9.0 =
* Flattened plugin structure — all files in plugin root for reliable FTP/cPanel upgrades
* Deactivation hook cleans stale subdirectories from pre-2.9.0 installs

= 2.8.7 =
* Dedup toggle persistence fix: switched to yes/no string storage

= 2.8.5 =
* View Deduplication settings UI with configurable window (1h–48h)

= 2.8.4 =
* Dashboard widget referrers now has Sites/Pages toggle

= 2.8.3 =
* Statistics page referrers Sites/Pages toggle with clickable full URLs

= 2.8.2 =
* Dashboard widget shows top 3 pages and top 3 referrers in side-by-side columns

= 2.8.1 =
* Referrer tracking fixed: REST API now captures document.referrer from beacon body

= 2.8.0 =
* Client-side dedup switched to localStorage with 24h TTL
* Server-side dedup: IP hash + post ID within configurable window

= 2.7.0 =
* Fixed trending count exceeding lifetime total display bug

= 2.6.9 =
* Both widgets now have configurable date/view count colours with colour picker

= 2.6.2 =
* Fail2Ban second-tier protection: permanently blocks IPs exceeding configurable page limit

= 2.4.0 =
* All Time banner on Statistics page with lifetime totals
* All Time Top Posts panel

= 2.3.0 =
* Tracking filter: configurable post type filter for beacon recording
* Recent Posts widget (CloudScale: Recent Posts)

= 2.2.0 =
* Auto-display of view counter on single posts with no theme editing required
* Display settings: position, post type, icon, suffix, style

= 2.1.0 =
* Top Posts sidebar widget (CloudScale: Top Posts) with pagination and thumbnail support

= 1.1.0 =
* Live Statistics dashboard under Tools > Page Views
* SHA-256 + wp_salt IP hashing for privacy
* Cache-bypass headers on REST endpoint

= 1.0.0 =
* Initial release: JavaScript beacon, post meta view counter, REST endpoint, admin column

= 2.5.4 =
* Stats page shows all recorded data regardless of tracking filter
* REST endpoint enforces post type tracking filter
* Badge colour options: blue, pink, red, purple, grey
* Two layer view deduplication: client side (localStorage, 24h TTL) and server side (IP + post, 24h window)
* View counter positioned above post title
* Display settings moved into main Tools page as Display tab
* Database auto upgrade for missing columns
* Admin bypass for IP throttle during testing
* Two column grid layout for Top Posts widget on desktop
* Dashboard widget colours changed from pink to green
* Transition period logic for Jetpack migration blending
* Jetpack Stats one click migration tool

== Third Party Services ==

This plugin optionally connects to the following external services:

= DB-IP Lite (optional, geolocation only) =
When you click "Download DB-IP Lite" in the plugin's statistics page, the plugin fetches the free DB-IP City Lite database directly from DB-IP's servers:
* Service URL: https://download.db-ip.com/
* This request is only made when you explicitly trigger it from the admin panel.
* DB-IP Privacy Policy: https://db-ip.com/db/privacy-policy.html
* DB-IP Terms of Use: https://db-ip.com/db/terms.html
* The database is stored locally on your server after download; no data is sent to DB-IP.

No data is transmitted to any external service during normal page view tracking. The JavaScript beacon only communicates with your own site's REST API endpoint.

== License ==

This plugin is free software released under the GPLv2 or later license.

Copyright (C) 2026 Andrew Baker (https://your-wordpress-site.example.com)

You may copy, distribute and modify the software as long as you track changes/dates in source files. Any modifications to or software including (via compiler) GPL licensed code must also be made available under the GPL along with build and install instructions.

Full license text is included in LICENSE.txt and available at https://www.gnu.org/licenses/gpl-2.0.html
