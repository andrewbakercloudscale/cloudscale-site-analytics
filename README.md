# CloudScale Page Views

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple) ![License](https://img.shields.io/badge/License-GPLv2-green) ![Version](https://img.shields.io/badge/Version-2.9.0-orange)

WordPress analytics that actually work behind Cloudflare. Every other page view counter counts on the server. When Cloudflare serves a cached page, WordPress never executes and the counter never increments. On a site with 85 to 95 percent cache hit rate, server side counters undercount by 5x to 10x.

CloudScale Page Views fixes this with a lightweight JavaScript beacon that fires after the cached page loads and hits a REST API endpoint that always bypasses the CDN. Every single view is counted regardless of whether the page was served from cache or origin.

Completely free. No subscriptions. No external services. No tracking pixels. Your data stays on your server.

> Full write up with screenshots: [CloudScale Free WordPress Analytics: Analytics that Work Behind Cloudflare](https://andrewbaker.ninja/2026/02/27/cloudscale-free-wordpress-analytics-analytics-that-work-behind-cloudflare/)

## How It Works

1. Cloudflare serves the cached HTML at edge speed
2. The browser renders the page and executes the beacon script
3. The beacon sends a POST to `/wp-json/cloudscale-page-views/v1/record/{post_id}`
4. The endpoint bypasses the CDN cache via headers and Cache Rules
5. WordPress logs the view in a dedicated database table and increments post meta
6. The page view counter on the page updates live via the API response

The beacon is tiny and fires asynchronously after the page has rendered. Zero impact on perceived performance.

## Features

### Core Analytics

- JavaScript beacon counting that works behind any CDN (Cloudflare, Fastly, CloudFront)
- Dedicated database table with per view logging (timestamp, post ID, referrer, IP hash)
- Statistics dashboard with charts for 7 hours, 7 days, 1 month, and 6 months
- Date range picker with quick buttons (Today, Last 7 Days, Last 30 Days, This Month, Last Month, This Year, All Time)
- Most Viewed posts and top Referrers ranked lists
- Real time counter updates on page load

### Protection Against Gaming

- Session deduplication: refresh ten times, counts as one view
- IP throttle: configurable rate limit (default 50 requests per hour per IP)
- Silent blocking: attackers get no signal they have been blocked
- Automatic unblock after the time window expires
- Logged in administrators bypass the throttle
- Block log with chronological history of events

### Display Options

- Four display positions: Before Content, After Content, Both, or Off
- Three counter styles: Badge (gradient), Pill (tinted), Minimal (plain text)
- Five colour schemes: Blue, Pink, Red, Purple, Grey
- Customisable icon and suffix text
- Per post type control for display and tracking independently

### Widgets

- **Dashboard Widget**: today views, 7 day total, time series chart, top posts with bar charts
- **Top Posts Sidebar Widget**: most viewed posts with thumbnails, pagination, configurable time window, responsive two column grid on desktop
- **Recent Posts Sidebar Widget**: latest posts with optional date and view count badges

### Jetpack Migration

- One click import of Jetpack lifetime view totals
- 28 day transition mode blends imported data with new beacon data
- Migration lock prevents accidental re runs
- Imported totals displayed in a banner on the Statistics tab

### Template Functions

- `cspv_the_views()` outputs the formatted view counter with icon and suffix
- `cspv_get_view_count()` returns the raw numeric count for a post ID
- Elements with CSS class `cspv-views-count` and `data-cspv-id` auto update on archive pages

## Requirements

- WordPress 6.0 or higher
- PHP 8.1 or higher

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file, click **Install Now**, then **Activate Plugin**
4. Go to **Tools > CloudScale Page Views**

The plugin creates its database table automatically on activation.

### Required Cloudflare Cache Rule

In the Cloudflare dashboard, go to **Caching > Cache Rules > Create Rule**:

- **Field**: URI Path
- **Operator**: contains
- **Value**: `/wp-json/cloudscale-page-views/`
- **Action**: Cache Status: Bypass

Without this rule, Cloudflare may cache the REST API response and no new views will be recorded.

### Upgrading

Deactivate > Delete > Upload zip > Activate.

## Advantages Over Jetpack Stats

- **Your data stays on your server.** No third party dependencies, no external service connections
- **CDN aware by design.** Beacon architecture was built specifically for cached sites
- **Privacy by default.** IP addresses are hashed with your site salt before storage. Raw IPs never touch the database
- **Lightweight.** A few kilobytes of beacon JS, one database insert per view, no heavyweight analytics library
- **Real time display.** Counter updates immediately on page load, no dashboard delay

## Debugging

If views are not recording, check in order: Cloudflare Cache Rule is active, browser console shows `[CloudScale PV]` log messages, IP Throttle tab shows you have not hit the rate limit, and `wp_cspv_views` database table exists. Test the API directly:
```
fetch('/wp-json/cloudscale-page-views/v1/ping')
  .then(r => r.json())
  .then(d => console.log(d))
```

This should return the plugin version and current server time. If repeated calls return the same timestamp, your Cache Rule is not working.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

[Andrew Baker](https://andrewbaker.ninja/) - CIO at Capitec Bank, South Africa.
