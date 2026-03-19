'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale WordPress Free Analytics',
    pluginDesc: 'Accurate page view tracking via a JavaScript beacon that bypasses caching. Includes a live statistics dashboard, Top Posts and Recent Posts sidebar widgets, IP throttling, and a Jetpack stats migrator — completely free, no data sent to third parties.',
    pageTitle:  'Help & Documentation — Analytics',
    pageSlug:   'analytics-help',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics`,

    sections: [
        // tabSelector: CSS selector of the tab button to click before screenshotting
        { id: 'statistics',   label: 'Statistics Dashboard',  file: 'panel-statistics.png',  tabSelector: '[data-tab="stats"]'    },
        { id: 'display',      label: 'Display Settings',      file: 'panel-display.png',     tabSelector: '[data-tab="display"]'  },
        { id: 'throttle',     label: 'IP Throttle',           file: 'panel-throttle.png',    tabSelector: '[data-tab="throttle"]' },
        { id: 'history',      label: 'Post History',          file: 'panel-history.png',     tabSelector: '[data-tab="history"]'  },
    ],

    docs: {
        'statistics': `
<p>The <strong>Statistics Dashboard</strong> is the main analytics view, showing page view data stored directly in your WordPress database. No data is sent to Google, Facebook, or any third party.</p>
<ul>
<li><strong>Period selector</strong> — switch between Today, 7 days, 30 days, 90 days, and All time to see view counts for that period.</li>
<li><strong>Totals bar</strong> — shows Total Views, Unique Posts Viewed, and Lifetime total at a glance.</li>
<li><strong>Views chart</strong> — a bar chart showing daily view volume over the selected period.</li>
<li><strong>Top Posts table</strong> — your most-viewed posts for the selected period, ranked by view count with links to edit each post.</li>
<li><strong>Referrer breakdown</strong> — shows which domains are sending traffic to your site.</li>
</ul>
<p>Page views are recorded by a lightweight JavaScript beacon that fires after the page loads, bypassing Cloudflare and other caching layers that would block server-side tracking.</p>`,

        'display': `
<p>The <strong>Display Settings</strong> tab controls how and where view counts and widgets appear on your site.</p>
<ul>
<li><strong>Show view count on posts</strong> — when enabled, a view counter is displayed on single post pages. Useful for social proof ("1,240 views").</li>
<li><strong>View count position</strong> — place the counter before or after the post content, or in the post meta area.</li>
<li><strong>Top Posts widget</strong> — displays your most-viewed posts in any sidebar. Configure via Appearance → Widgets. Settings: number of posts to show (1–20), minimum view threshold (hides posts with very few views), and whether to show thumbnails.</li>
<li><strong>Recent Posts widget</strong> — shows recently viewed posts. Configure the number of entries and whether to display post thumbnails.</li>
<li><strong>Exclude post types</strong> — prevent certain post types (e.g. WooCommerce products, custom post types) from having their views tracked or displayed.</li>
</ul>`,

        'throttle': `
<p>The <strong>IP Throttle</strong> tab prevents your own page views — and repeated views from the same visitor — from inflating your statistics.</p>
<ul>
<li><strong>Throttle window</strong> — the time period (in minutes) during which a repeat view from the same IP address is not counted. Default: 30 minutes. Set to 0 to count every page load.</li>
<li><strong>Exclude logged-in users</strong> — when ticked, views from any logged-in WordPress user are not recorded. This prevents your own browsing from appearing in the stats.</li>
<li><strong>Exclude administrators</strong> — more specific than the above: only excludes users with the Administrator role, leaving Editor and Author views counted.</li>
<li><strong>Pause tracking</strong> — temporarily suspend all view recording without deactivating the plugin. Useful during maintenance, content imports, or testing.</li>
<li><strong>Bot filtering</strong> — automatically ignores known crawler and bot user agents. Bots do not trigger the JavaScript beacon, so this is a secondary safeguard.</li>
</ul>`,

        'history': `
<p>The <strong>Post History</strong> tab lets you look up the complete view history for any specific post or page.</p>
<ul>
<li><strong>Post search</strong> — type the title of any post or page to find it. Select it from the autocomplete dropdown.</li>
<li><strong>View timeline</strong> — once a post is selected, the tab shows a day-by-day breakdown of its view count over its entire history.</li>
<li><strong>Total views</strong> — the lifetime view count for that specific post.</li>
<li><strong>Trending indicator</strong> — compares recent views to the post's historical average, showing whether it is currently gaining or losing traffic.</li>
</ul>
<p>This is useful for identifying which posts are driving traffic, spotting seasonal patterns, and measuring the impact of updates or promotions on a specific article.</p>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
