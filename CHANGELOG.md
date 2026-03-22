# Changelog

All notable changes to CloudScale Analytics are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.9.119] - 2026-03-22

### Fixed
- Replace echoed `<style>` tags in `cspv_admin_menu_styles` and `cspv_frontend_nav_styles` with `wp_add_inline_style()` to comply with PCP `EscapeOutput` / no-inline-styles rule (critical PCP violation).
- Replace `unlink()` with `wp_delete_file()` throughout deactivation hook, admin_init upgrade handler, and GeoIP download handler (PCP `AlternativeFunctions.unlink_unlink`).
- Replace `rmdir()` with WP Filesystem `$wp_filesystem->rmdir()` in deactivation and upgrade cleanup (PCP `AlternativeFunctions` violation).
- Add `wp_unslash()` to nonce read in display settings form handler (`wp_verify_nonce( wp_unslash( $_POST['cspv_display_nonce'] ), ... )`) — PCP `MissingUnslash`.
- Sanitize `$_GET` superglobal via `array_map( 'sanitize_text_field', wp_unslash( ... ) )` in Recent Posts widget pagination — PCP `InputNotSanitized`.

## [2.9.118] - 2026-03-22

### Changed
- All four summary cards (Views, Posts Viewed, Unique Visitors, Hot Pages) now show `N (was X)` detail format instead of `N vs X`.

### Fixed
- Hot Pages card now shows the traffic-concentration count (how many top pages account for 50% of traffic) instead of all pages with ≥ 2 views. Value now matches the metric shown in the Site Health panel.

## [2.9.117] - 2026-03-22

### Fixed
- Hot Pages summary card was showing inflated numbers (e.g. 150) by counting all distinct pages with ≥ 2 views. Rewrote `cspv_hot_pages_for_range()` to use the 50%-traffic-concentration algorithm consistent with the Site Health panel.

## [2.9.116] - 2026-03-22

### Changed
- WordPress admin menu icon changed from storm cloud 🌩️ to bar chart 📊 for clearer analytics identity.
- Post History tab: timeline entries are now clickable links opening the post in a new tab.
- Added timeline slider to Post History tab.
- Updated help documentation.

## [2.9.115] - 2026-03-19

### Added
- Hot Pages summary card on Statistics page showing traffic-concentration percentage vs prior period.

### Fixed
- Mobile layout improvements across the Statistics page.

### Changed
- Deploy scripts hardened with safe atomic swap pattern.

## [2.9.94] - 2026-03-15

### Fixed
- Debug panel "Jetpack imported" counter was a false positive whenever meta count exceeded log count by any amount. The label now only appears when there are zero log rows (a true Jetpack-only import). A separate "Unlogged views" row appears when log rows exist but meta is slightly ahead — reflecting a minor write-ordering gap, not a Jetpack import.

### Changed
- Widget enqueues gated: `cspv_recent_posts_widget_enqueue` and `cspv_top_posts_widget_enqueue` now check `is_active_widget()` before injecting inline CSS/JS. Assets no longer load on every frontend page when neither widget is active (PCP global-enqueue compliance).
- Added `__()` / `esc_html_e()` i18n wrappers to all widget registration strings, form labels, default titles, and admin column headers. Text domain `cloudscale-page-views` is now exercised across all user-visible PHP strings in widget and column code.
- DocBlocks (`@since`, `@param`, `@return`) added to all functions in `admin-columns.php`, `auto-display.php`, `dashboard-widget.php`, `debug-panel.php`, `database.php`, `ip-throttle.php`, `jetpack-migration.php`, `site-health.php`, `recent-posts-widget.php`, and `top-posts-widget.php`.

## [2.9.88] - 2026-03-08

### Added
- Geography tracking with Leaflet map: country-level data stored in `wp_cspv_geo_v2` table, visualised on the stats page with an interactive choropleth map.
- Unique visitor tracking via daily hashed-IP buckets in `wp_cspv_visitors_v2` table.
- Shared stats consolidation: stats-library.php is now the single source of truth for all rolling view, referrer, geo, and visitor queries.

### Fixed
- Debug panel close button alignment.
- Date swap bug in visitor range queries.
- Geo map rendering on sites with no geo data.

## [2.9.72] - 2026-03-04

### Added
- V2 referrer table (`wp_cspv_referrers_v2`): one row per post per hour per referrer, replacing the flat referrer string stored on each view row.
- Shared referrer query functions in `stats-library.php` (`cspv_top_referrer_domains`, `cspv_top_referrer_pages`).
- Configurable Ignore Jetpack toggle: when enabled, disables transition-mode blending and ranks Top Posts widget purely by beacon data.

### Changed
- Dashboard widget and stats page redesigned with card UI.
- Top Posts widget skips transition mode entirely when Ignore Jetpack toggle is on.
- Dashboard widget banner now leads with percentage change vs prior period.

## [2.9.46] - 2026-03-03

### Added
- `stats-library.php` as single source of truth for rolling view counts, referrer sources, and geo lookups. Removed duplicate `cspv_rolling_24h_views()` from main plugin file.
- Post History view on stats page: per-post daily/monthly breakdown with period comparison.
- Configurable FTB (Fail2Ban) block duration: 30 minutes to 24 hours, selectable in admin UI.

### Fixed
- Runaway "Jetpack imported" count inflation in Top Posts widget transition mode.
- Client-side deduplication ignoring disabled setting when value was stored as a string.
- Beacon cache-bust `?ver=` parameter stripped by some optimisation plugins — re-added as `?cspv=` fallback.
- WordPress timezone offset applied correctly to rolling window calculations.

## [2.9.12] - 2026-03-01

### Changed
- Site Health v2: complete rewrite of metrics engine
- Traffic Growth renamed to "Traffic Growth per Time Window", labels now "7 Days", "30 Days", "90 Days"
- Insufficient Data: buckets without enough historical data (need 2x period) show "Insufficient Data" instead of misleading 100% growth
- Hot Pages now mirrors Traffic Growth layout with three time window buckets (7 Days, 30 Days, 90 Days), each showing how many top pages exceed 50% of traffic, compared to prior period with % change
- Hot Pages RAG: more pages needed for 50% = better distribution = green (>5%), fewer = concentrating = red (<-5%)
- All site health queries cached in wp_options (cspv_site_health_cache) for 1 hour to avoid DB load
- Overall RAG considers all buckets with sufficient data across both metrics

## [2.9.11] - 2026-03-01

### Added
- Site Health summary on both the dashboard widget and Statistics page with red/amber/green indicators
- Traffic Growth metric: compares daily view averages across 7d, 30d, and 90d periods against prior equivalent periods, with per period RAG (red if shrinking >5%, green if growing >5%, amber otherwise)
- Hot Pages metric: shows how many pages account for 50% of total 30 day traffic, indicating content concentration
- Overall RAG: green when all three growth periods are positive >5%, red when all three are negative >5%, amber for anything else
- New site-health.php with shared cspv_compute_site_health() and cspv_render_site_health_html() functions

### Changed
- Debug panel button (front end): moved from fixed bottom right circle to inline pink pill (🐛 Debug) positioned right next to the view counter, bigger and more visible
- Debug panel header and chart bars now pink gradient to match button
- Debug panel has close button (✕) in header
- auto-display.php: added HTML comment marker for debug button injection point

## [2.9.10] - 2026-03-01

### Added
- Post History tab on Statistics page: search any post by title and see displayed count (meta), log table rows, Jetpack imported count, Jetpack meta value, first/last view timestamps, and a 90 day daily or 48 hour hourly view chart
- Resync button on Post History tab to recalculate meta from log rows + Jetpack when counts mismatch
- AJAX endpoints: cspv_post_search (typeahead post search), cspv_post_history (full diagnostics), cspv_resync_meta (recalculate meta)
- Info modal entry explaining all Post History fields

## [2.9.9] - 2026-03-01

### Added
- View Diagnostics panel: admin only debug overlay on every post (🔍 button, bottom right) showing the displayed meta count, actual log table rows, Jetpack imported count, first/last view timestamps, 30 day daily view chart, and system info (plugin version, dedup status)
- Resync button: when meta count does not match log table + Jetpack imports, one click recalculates the correct value from log rows + jetpack_post_views meta
- AJAX endpoint cspv_resync_meta for the resync operation with nonce protection

### Fixed
- Dashboard widget referrer Sites/Pages toggle: inactive button font weight reduced from 600 to 500, active state explicitly set to 800, so the selected tab is visually distinct

## [2.9.0] - 2026-02-28

### Changed
- BREAKING: Flattened plugin structure. All files now live in the plugin root directory. The assets/, admin/, and includes/ subdirectories are eliminated. WordPress plugin upload does not reliably overwrite files in subdirectories, causing stale code to persist across upgrades. This was the root cause of settings not saving, old JS running, and other phantom bugs.

### Added
- Deactivation hook that wipes JS/CSS assets and removes old subdirectories (assets/, admin/, includes/) so Deactivate > Delete > Upload > Activate always produces a clean install
- Version change detector on admin_init that auto cleans stale subdirectories from pre 2.9.0 installs when the plugin is upgraded without deactivating (e.g. FTP upload, wp cli)
- OPcache reset on version change to prevent PHP serving stale bytecode after file replacement
- beacon.js now uses CSPV_VERSION as the wp_enqueue_script version parameter for proper browser cache busting

### Removed
- assets/ subdirectory (beacon.js moved to plugin root)
- admin/ subdirectory (admin-columns.php, dashboard-widget.php, stats-page.php moved to plugin root)
- includes/ subdirectory (all PHP includes moved to plugin root)
- Random cache buster on beacon.js (replaced with deterministic version string)

## [2.8.7] - 2026-02-28

### Fixed
- Dedup toggle still not persisting: switched from '1'/'0' to 'yes'/'no' string storage which WordPress handles unambiguously across update_option/get_option cycles
- Removed autoload=false from update_option calls which could prevent option creation
- Save button now shows the stored value confirmation (e.g. "Saved (stored: no)") for verification
- AJAX response now includes debug fields (saved_e, saved_w, stored) to diagnose persistence issues

## [2.8.6] - 2026-02-28

### Fixed
- Dedup toggle not persisting: WordPress stores boolean false as empty string which is ambiguous with option not existing. Now stores explicit '1'/'0' strings and reads them back with proper comparison
- Dedup window dropdown now always remembers the selected value regardless of enabled/disabled state
- Server side dedup check now reads both cspv_dedup_enabled flag and cspv_dedup_window, so disabling via the toggle immediately stops dedup without relying on the window value being 0

## [2.8.5] - 2026-02-28

### Added
- View Deduplication settings UI on the IP Throttle tab with enable/disable toggle and configurable window (1h, 2h, 6h, 12h, 24h, 48h)
- Status pill shows DEDUP ON/OFF at a glance
- Info modal explaining how client side and server side dedup layers work together
- AJAX save handler for dedup settings with immediate feedback

### Changed
- Server side dedup now respects both the cspv_dedup_enabled and cspv_dedup_window options (previously only checked window > 0)
- When dedup is disabled via the toggle, window is set to 0 so the database lookup is skipped entirely

## [2.8.4] - 2026-02-28

### Added
- Dashboard widget referrers section now has a Sites/Pages toggle matching the Statistics page, showing full referring URLs when Pages is selected
- Referrer page links are clickable and open in a new tab
- Both Sites and Pages views are server rendered in the widget HTML for instant toggle with no AJAX

## [2.8.3] - 2026-02-28

### Added
- Referrers panel on the Statistics page now has a Sites/Pages toggle: Sites shows aggregated domains (existing behaviour), Pages shows the full referring URLs so you can see exactly which pages are sending traffic
- Referrer pages are clickable links that open in a new tab
- AJAX response now includes referrer_pages array (top 20 full URLs with view counts)

### Fixed
- Statistics page showing blank data on initial load when navigating from the Tools menu: Chart.js loaded asynchronously could throw before the data lists rendered, killing the entire renderAll function. Lists now render before the chart, and chart rendering is wrapped in a try/catch with a 1 second retry fallback

## [2.8.2] - 2026-02-28

### Added
- Dashboard widget now shows top 3 pages and top 3 referrers for today in a side by side two column layout beneath the chart
- Referrers are aggregated by domain with self referrals excluded, matching the stats page behaviour

### Changed
- Dashboard widget top posts reduced from 5 to 3 to fit the compact two column layout

## [2.8.1] - 2026-02-28

### Fixed
- Referrers page showing zero data: the referrer column was missing from the database schema, the beacon was not sending document.referrer, and the REST API was not capturing or inserting it
- Added referrer column (VARCHAR 2048) to CREATE TABLE and upgrade path
- Beacon now sends document.referrer in the POST body
- REST API captures referrer from beacon body with fallback to HTTP Referer header and writes it to the views table

## [2.8.0] - 2026-02-28

### Changed
- Client side dedup switched from sessionStorage to localStorage with 24 hour TTL, preventing double counts when a link opens in an in app browser (WhatsApp, Facebook, Telegram) and then again in a real browser tab
- Expired localStorage dedup keys are pruned automatically on each page load

### Added
- Server side dedup: before inserting a view, checks whether the same IP hash + post ID combination already exists within a configurable window (default 24 hours), catching cross browser/cross app duplicates that client side storage cannot prevent
- New `cspv_dedup_window` option (seconds, default 86400) to control the server side dedup window
- Composite database index `ip_post_dedup (ip_hash, post_id, viewed_at)` for fast dedup lookups
- Index is added automatically via the existing upgrade path on version bump

## [2.7.0] - 2026-02-28

### Fixed
- Bug where "trending" (beacon window count) could exceed "total" (lifetime meta), causing confusing display like "1.8k trending · 1.7k total"
- When detected, lifetime meta is corrected on the fly to match the beacon count, self healing the data
- Root cause: meta increment race conditions or Jetpack reimport setting meta lower than actual beacon rows

## [2.6.9] - 2026-02-28

### Added
- Both sidebar widgets now have configurable date and view count colours with native colour picker inputs in widget settings
- Hover colour setting for date and view count text on both widgets
- Default: dark orange (#c2410c) with bright orange (#ea580c) hover
- Colours are applied via scoped per instance styles using the widget ID, so multiple instances can have different colours

## [2.6.8] - 2026-02-28

### Changed
- Switched both sidebar widgets from relative em units to fixed pixel sizes to prevent theme parent font size from shrinking text
- Post titles now render at 16px, date and view count text at 14px regardless of sidebar context
- Top Posts pager buttons at 14px, pager info at 13px

## [2.6.7] - 2026-02-28

### Changed
- Top Posts widget: bumped title font from 0.95em to 1.05em, date/views row from 0.72em to 0.82em, pager buttons from 0.85em to 0.88em, pager info from 0.78em to 0.82em
- Recent Posts widget: bumped title font from 0.95em to 1.05em, date/views meta from 0.72em to 0.82em
- Both sidebar widgets now include show_instance_in_rest for better block editor integration and more descriptive widget descriptions

### Fixed
- "Display a legacy widget." text in block editor sidebar is WordPress core behaviour for classic WP_Widget classes; improved descriptions so users see useful text alongside the WordPress label

## [2.6.6] - 2026-02-28

### Fixed
- Referrers empty state now shows "No referrers recorded in this period." instead of the stale placeholder "Referrer tracking coming in next update."

## [2.6.5] - 2026-02-28

### Fixed
- Dashboard widget now remembers selected period tab (7 Hours, 1 Day, 7 Days, etc.) across page loads via localStorage
- Widget restores the saved tab and sets the correct button active on init, matching the stats page behaviour

## [2.6.4] - 2026-02-28

### Added
- Date range persistence: selected quick range or custom dates are remembered across page loads via localStorage
- Emergency Tracking Pause: kill switch on IP Throttle tab that instantly stops all beacon loading and API recording during attacks
- Test Fail2Ban diagnostic: five point self test (transient write, transient read, options table, FTB enabled, block duration) with pass/fail results
- FTB Installation section in Help modal explains that FTB is fully built in with no external software, server packages, or configuration needed
- Tracking pause info modal explains kill switch behaviour and data preservation

### Changed
- beacon.php checks cspv_tracking_paused() and skips loading when paused
- REST API record endpoint returns silent 200 with paused: true when tracking is paused
- Help data for IP Throttle tab expanded with Emergency Tracking Pause, Test Fail2Ban, and detailed FTB Installation cards

## [2.6.3] - 2026-02-28

### Added
- Help button on every tab (Statistics, Display, IP Throttle, Migrate) that opens a card layout modal explaining all features, settings, and installation requirements
- FTB status indicator pill (FTB ACTIVE / FTB OFF) in the Fail2Ban section header for at a glance visibility
- FTB blocked IPs now show expiry countdown (e.g. "expires in 94m") matching throttle block display

### Changed
- FTB blocks changed from permanent to 2 hour auto clear via transients (CSPV_FTB_BLOCK_DURATION = 7200)
- FTB blocklist now auto prunes expired entries on read, matching throttle blocklist behaviour
- Help modal uses card layout with coloured badges (Info, Tip, Optional, Required) matching the screenshot design pattern

## [2.6.2] - 2026-02-28

### Added
- Fail2Ban (FTB) second tier IP protection: permanently blocks IPs exceeding a configurable page limit (default 1,000) within the rolling window
- FTB settings panel in IP Throttle tab with enable toggle and page limit configuration
- FTB Rules display showing current active rule configuration and parameters
- FTB Blocked IPs panel with individual unblock buttons and clear all functionality
- FTB event log tracking block and unblock actions (last 100 events)
- Clear IP Addresses button: nuclear option that removes all throttle blocks, FTB blocks, counters, and logs across both tiers
- Info modals for FTB Protection, FTB Rules, FTB Blocked IPs, and Clear All IP Addresses

### Changed
- ip-throttle.php upgraded to v3.0.0 with two tier architecture
- cspv_is_throttled() now checks FTB blocklist before throttle transients
- Throttle requests also feed FTB page counter so persistent abusers escalate to permanent blocks

## [2.4.0] - 2026-02-27

### Added
- Stats page: All Time banner showing lifetime total views and posts with views (includes Jetpack imports)
- Stats page: All Time Top Posts panel ranked by lifetime meta totals
- Top Posts widget: Phase 2 backfill from lifetime meta when windowed log data is sparse (transition period after Jetpack migration)
- Top Posts widget: dual display showing "X trending · Y total" when windowed and lifetime counts differ

### Changed
- Dashboard widget: purple gradient banner with pink accents replacing blue theme
- Dashboard widget: chart bars, tab indicators, progress bars and view counts all use pink/purple palette
- Top Posts widget: orange accent pagination buttons, grey date/view text
- Recent Posts widget: orange accent pagination, grey date/view text
- Recent Posts widget: current page indicator uses orange gradient

### Fixed
- Top Posts widget Phase 2 backfill: replaced WP_Query meta_query (silently failed on some installs) with direct SQL join for reliable results

## [2.3.0] - 2026-02-27

### Added
- Tracking filter: configurable post type filter controls which content types the beacon records views on (Settings > CloudScale Views, bottom of page)
  - Defaults to Posts only, so pages, home page, and other content types are not tracked
  - Persisted as cspv_track_post_types option
  - Beacon loader checks post type before firing; untracked types silently skip recording
- Recent Posts widget (Appearance > Widgets > CloudScale: Recent Posts)
  - Paginated list of recent posts with date and CloudScale view counts
  - Server side pagination via query string parameters
  - Replaces the standalone CloudScale Paginated Recent Posts plugin
  - Uses cspv_get_view_count() for all time view display (no Jetpack dependency)
  - Pagination styled with CloudScale blue gradient for current page indicator

### Changed
- Beacon loader now respects cspv_track_post_types setting
- Untracked singular pages fall through to fetch mode so archive style count elements still work

### Note
- After updating, deactivate the standalone CloudScale Paginated Recent Posts plugin
- Re add the widget under Appearance > Widgets (it will appear as CloudScale: Recent Posts)

## [2.2.0] - 2026-02-27

### Added
- Auto display of view counts on single posts with no theme editing required
- Settings page under Settings > CloudScale Views with options for:
  - Display position (before content, after content, both, or off)
  - Post type selection (posts, pages, or any public custom post type)
  - Customisable icon and suffix text
  - Live preview of the counter format
- Manual theme integration instructions shown on the settings page

### Changed
- Default auto display position is "before content" (enabled out of the box)

## [2.1.0] - 2026-02-27

### Added
- Top Posts sidebar widget (Appearance > Widgets > CloudScale: Top Posts)
  - Paginated list with thumbnail, date and formatted view count
  - Configurable ranking window (last N days or all time) using the cspv_views log table
  - Falls back to denormalised meta counter when log table is empty (e.g. after migration)
  - Configurable pool size, posts per page, thumbnail width, and sort order
- Merged the standalone CloudScale Top Posts Widget plugin into this plugin

### Changed
- Widget class renamed from AB_Top_Posts_Widget to CSPV_Top_Posts_Widget
- Widget CSS classes renamed from abw- prefix to cspv-tp- prefix
- All Jetpack stats_get_csv() dependencies removed from widget
- Widget ID changed from ab_top_posts_widget to cspv_top_posts_widget

### Note
- After updating, deactivate the standalone CloudScale Top Posts Widget plugin
- Re add the widget under Appearance > Widgets (it will appear as CloudScale: Top Posts)
- Existing widget settings will need to be reconfigured as the widget ID has changed

## [1.1.0] - 2026-02-27

### Added
- Live statistics dashboard under Tools > Page Views
  - Summary cards: total views, posts tracked, views today
  - Top 10 posts table showing both log count and meta count so drift is visible
  - Recent 50 raw view log entries auto-refreshing every 10 seconds
  - Endpoint diagnostic Ping button to confirm REST endpoint is reachable and not cached
  - Cloudflare Cache Rule setup reminder with exact configuration values
- Hashed IP address stored in the log table (SHA-256 + wp_salt) for future deduplication
- User agent stored in log rows for future bot filtering
- Cache-busting query parameter on beacon.js URL to prevent Cloudflare from serving a stale script
- Full suite of cache-bypass headers on the REST endpoint:
  - Cache-Control: no-store
  - Cloudflare-CDN-Cache-Control: no-store
  - CDN-Cache-Control: no-store
  - Surrogate-Control: no-store
  - Vary: Cookie
- Diagnostics ping endpoint at /wp-json/cloudscale-page-views/v1/ping
- Debug console logging in beacon.js when WP_DEBUG is true
- CHANGELOG.md (this file)
- LICENSE.txt (GPL-2.0+)
- Structured into includes/ and admin/ subdirectories

### Changed
- Refactored monolithic plugin file into separate includes for maintainability
- beacon.js now receives postId and debug flag via wp_localize_script

### Fixed
- Cloudflare could cache beacon.js itself and serve a page-specific script to the wrong page

## [1.0.0] - 2026-02-27

### Added
- Initial release
- JavaScript beacon fires POST to /wp-json/cloudscale-page-views/v1/record/{id} after page load
- View count stored in post meta (_cspv_view_count) for fast display in themes
- Raw view log in wp_cspv_views database table
- Sortable Views column in Posts > All Posts admin list
- Template functions: cspv_get_view_count() and cspv_the_view_count()
- Cache-Control headers on REST endpoint to prevent Cloudflare caching
- live count update via .cspv-live-count CSS class
