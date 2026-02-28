=== CloudScale Page Views ===
Contributors: andrewbaker
Tags: page views, analytics, cloudflare, view counter, jetpack migration
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accurate page view tracking for WordPress sites behind Cloudflare and other CDNs. Counts every view even when HTML is served from cache.

== Description ==

CloudScale Page Views solves the fundamental problem with page view tracking on cached WordPress sites. When Cloudflare, Fastly, or any CDN serves a cached HTML page, WordPress never executes. Server side counters like Jetpack Stats miss the view entirely, resulting in severe undercounting — typically 5 to 10 times lower than actual traffic.

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

1. Upload the `cloudscale-page-views` folder to `/wp-content/plugins/`
2. Activate the plugin in Plugins > Installed Plugins
3. The database table is created automatically on activation
4. Add the Cloudflare Cache Rule (see FAQ)
5. Configure display settings in Tools > CloudScale Page Views > Display tab

== Frequently Asked Questions ==

= What Cloudflare Cache Rule do I need? =

In the Cloudflare dashboard go to Caching > Cache Rules > Create Rule:

* Field: URI Path
* Operator: contains
* Value: /wp-json/cloudscale-page-views/
* Action: Cache Status: Bypass

The plugin also sends no cache headers on every REST response, but the Cache Rule is the primary protection.

= How do I migrate from Jetpack Stats? =

Go to Tools > CloudScale Page Views > Migrate Jetpack tab. Click the migration button to import all Jetpack lifetime view counts into CloudScale. The migration preserves your historical totals as a starting point and the plugin blends them with new beacon data during a 28 day transition period.

= Does it work without Cloudflare? =

Yes. The beacon approach works on any WordPress site. The Cloudflare Cache Rule is only needed if you use Cloudflare caching. Without Cloudflare, the REST endpoint is already accessible and the plugin works out of the box.

= Does refreshing the page count as another view? =

No. The beacon uses sessionStorage to deduplicate views. Each browser session records only one view per post. A new tab or new session counts as a new view.

= How is visitor privacy protected? =

IP addresses are hashed using SHA256 combined with your site wp_salt before storage. Raw IP addresses are never written to the database. The IP hash is used only for throttle protection.

== Changelog ==

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

== License ==

This plugin is free software released under the GPLv2 or later license.

Copyright (C) 2026 Andrew Baker (https://andrewbaker.ninja)

You may copy, distribute and modify the software as long as you track changes/dates in source files. Any modifications to or software including (via compiler) GPL licensed code must also be made available under the GPL along with build and install instructions.

Full license text is included in LICENSE.txt and available at https://www.gnu.org/licenses/gpl-2.0.html
