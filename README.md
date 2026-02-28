# CloudScale Page Views

**Version:** 1.1.0
**Author:** Andrew Baker
**Site:** https://andrewbaker.ninja
**License:** GPL-2.0+

Accurate page view tracking for WordPress sites behind Cloudflare, with a live statistics dashboard.

## The Problem This Solves

When Cloudflare serves a cached HTML page, WordPress never runs. Server-side counters
(Jetpack Stats, most view count plugins) therefore miss the view entirely. The result
is severe undercounting — typically 5 to 10 times lower than actual traffic on sites
with a high Cloudflare cache hit rate.

## How It Works

1. Cloudflare serves the cached HTML page at full speed.
2. The browser loads the page and executes beacon.js.
3. The beacon fires a lightweight POST request to a WordPress REST API endpoint.
4. That endpoint is excluded from Cloudflare caching via Cache Rule and response headers.
5. WordPress receives the request, increments the counter in post meta, and logs a row.
6. The .cspv-live-count element on the page updates in real time without a reload.

Because the count happens client-side after the cache has done its job, every view is
captured regardless of cache status.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- A Cloudflare account with Cache Rules (free tier is sufficient)

## Installation

1. Upload the cloudscale-page-views folder to /wp-content/plugins/.
2. Activate the plugin in Plugins > Installed Plugins.
3. The plugin creates the wp_cspv_views table automatically on activation.
4. Add the Cloudflare Cache Rule described below.

## Cloudflare Cache Rule (Required)

Without this rule, Cloudflare may cache the REST API response and the beacon will
appear to succeed but no new rows will appear in the database.

In the Cloudflare dashboard go to Caching > Cache Rules > Create Rule:

Field: URI Path
Operator: contains
Value: /wp-json/cloudscale-page-views/
Action: Cache Status: Bypass

The plugin also sends Cloudflare-CDN-Cache-Control: no-store and related headers
on every REST response, but the Cache Rule is the primary and most reliable protection.

## Displaying View Counts in Your Theme

In the loop (post listings):

```php
<?php if ( function_exists( 'cspv_the_view_count' ) ) : ?>
    <span class="post-views">
        <?php cspv_the_view_count( get_the_ID() ); ?>
    </span>
<?php endif; ?>
```

On single post templates with live update. The .cspv-live-count class causes the
beacon to update the number in real time after it fires, without a page reload:

```php
<?php if ( function_exists( 'cspv_get_view_count' ) ) : ?>
    <span class="post-views">
        <span class="cspv-live-count"><?php echo esc_html( cspv_get_view_count() ); ?></span> views
    </span>
<?php endif; ?>
```

Get the count as a number for custom use:

```php
$views = cspv_get_view_count( $post_id );
```

## Live Statistics Dashboard

Go to Tools > Page Views to see:

Summary cards showing total views all time, posts tracked, and views today.

Top 10 posts by raw log count, with a meta count comparison column. If these two
numbers differ for a post, the counts have drifted and need a re-sync.

Live view log showing the 50 most recent raw entries, auto-refreshing every 10 seconds.
If this list is not growing when you visit posts, the beacon is not reaching the server.

Endpoint diagnostic Ping button. Click it and it shows the server timestamp. Click
again and the timestamp should change. If it shows the same time, Cloudflare is
caching the REST endpoint and you must add the Cache Rule above.

## Debugging

With WP_DEBUG enabled in wp-config.php, the beacon logs to the browser console:

    [CloudScale Page Views] Firing beacon for post 42
    [CloudScale Page Views] Endpoint: https://example.com/wp-json/cloudscale-page-views/v1/record/42
    [CloudScale Page Views] Response status: 200
    [CloudScale Page Views] View recorded. New count: 17

Open Chrome DevTools > Console on any post and refresh to confirm the beacon is firing
and receiving a 200 response. A non-200 response means the endpoint is unreachable,
returning an error, or being intercepted.

## Admin

A sortable Views column appears in Posts > All Posts.

## Database

The plugin creates wp_cspv_views with these columns:

- id — auto-increment primary key
- post_id — WordPress post ID
- user_agent — browser user agent (truncated to 255 chars)
- ip_hash — SHA-256 hash of visitor IP plus wp_salt (never a raw IP)
- viewed_at — server time of the view

## REST API Endpoints

POST /wp-json/cloudscale-page-views/v1/record/{id}
Records a view for the given post ID. Returns JSON with post_id and views count.

GET /wp-json/cloudscale-page-views/v1/ping
Health check. Returns status, version, and current server time.

## Roadmap

Version 1.2.0 will add:

- Cookie or sessionStorage flag to prevent duplicate counting on refresh (opt-in)
- IP hash cooldown window (30 minutes per post per visitor)
- Bot filtering based on user agent patterns
- One-click Jetpack Stats data migration to seed historical counts

## Changelog

See CHANGELOG.md for full version history.

## License

GPL-2.0+. See LICENSE.txt for full terms.
