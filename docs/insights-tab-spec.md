# Insights Tab — Full Specification

**First built:** v2.9.244  
**Rebuilt:** v2.9.250  
**Source:** Screenshots from plugin help page + user-provided images (sessions 2026-04-29)

---

## Tab Layout (top to bottom)

### 1. Header bar
- Dark navy gradient (`#1a2332 → #0f4c81`)
- Left: "📊 INSIGHTS" label
- Right: period buttons + Self toggle + ? Explain button

**Period buttons:** 7 days | 30 days | 90 days | 180 days | 360 days  
- Active = amber/gold (`#f59e0b`, black text)  
- Inactive = transparent with white border  
- Clicking a period re-fetches all dashboard data via `cspv_insights_dashboard` AJAX  
- Default: 30 days

**Self toggle:** `Self: ON` / `Self: OFF`  
- ON = amber, OFF = dim white  
- Filters own-domain traffic from all charts and KPI cards simultaneously (client-side re-render, no AJAX)  
- "Self" = HTTP referrer from site's own domain  
- When OFF: Total Views, Top Referrer exclude own-domain views

**? Explain button:** Opens info modal with key `insights-dashboard`

---

### 2. KPI Cards (4-column grid)

| Card | Value | Sub-line |
|------|-------|----------|
| TOTAL VIEWS | Sum of all views for period | — |
| UNIQUE VISITORS | Distinct visitor hashes | — |
| TOP COUNTRY | 2-letter country code (e.g. "US") | "4.6K views" |
| TOP REFERRER | Label (e.g. "Self", "Google") | "6.9K views" |

- When Self is OFF: TOP REFERRER shows highest non-Self referrer

---

### 3. Traffic Sources (doughnut chart)

- Chart.js doughnut, `cutout: 60%`
- One slice per traffic source: **Direct**, **Self**, known engines (Google, Bing, DuckDuckGo, Yahoo, Ecosia, Yandex, Baidu), social (LinkedIn, Facebook, Instagram, Twitter/X, Reddit, Pinterest, YouTube), other hostnames
- **Direct** = total views − referrer-tracked views
- **Self** = views with own domain as HTTP referrer
- Colors from 20-hue vivid palette, distinct per slice
- Custom legend below chart (dot + label)
- Tooltip: "Label: 1,234 (18%)"
- When Self is OFF: Self slice removed, Direct slice shows remaining direct

---

### 4. Referrer Growth (multi-line chart)

- Chart.js line chart, `tension: 0.3`
- Top 8 referrer sources by total volume for the period
- X-axis: dates — daily buckets for ≤30 days, ISO week-start buckets for >30 days
- Y-axis: view count (starts at 0)
- Each line: distinct color from palette + distinct dash pattern (solid, dashed, dotted, etc.)
- Legend at bottom (Chart.js built-in, font-size 11)
- When Self is OFF: "Self" series hidden

---

### 5. Top Posts by Views (horizontal bar chart)

- Chart.js horizontal bar (`indexAxis: 'y'`)
- Top 15 posts for the period (from main views table)
- Each bar: distinct color from vivid palette
- Y-axis labels truncated at 40 chars with "…"
- Height auto-scales: `max(120, n * 28)px`
- No legend

---

### 6. Top Posts by Referrer (table)

- Rows = top 15 posts (by referrer-tracked views)
- Columns = top 8 referrer labels for the period
- Values = view count per post × referrer (— for zero)
- Alternating row background: white / light blue (`#f0f6ff`)
- Post title truncated at 35 chars, links to post
- When Self is OFF: "Self" column hidden

---

### 7. Your Content (purple panel, uses Stats tab date range)

**Screenshot:** Image #7 in session 2026-04-29

- Header: purple gradient (`#7e22ce → #c026d3`) + date range label (e.g. "1 Nov 2025 – 29 Apr 2026")
- Sub-tabs: **Top** | **Trending Up** | **Trending Down** + "VIEWS" column header
- Each row: 60px thumbnail (featured image or grey placeholder) + title (purple link) + URL path + bold view count right-aligned
- Trend badge: `↑ 45%` (green) or `↓ 12%` (red) showing % change vs previous equal period
- Data from `cspv_ajax_insights` (existing endpoint, uses from/to dates from Statistics tab)
- Loads when Insights tab is opened if a date range is set
- Invalidated (reloads) whenever Statistics tab date range changes

---

### 8. Post Analytics (teal panel)

**Screenshot:** Image #8 in session 2026-04-29

- Header: teal gradient (`#0e7490 → #06b6d4`) — "🔍 POST ANALYTICS"
- Search bar (placeholder "Search posts by title…") + "Search Posts" button
- Sortable post list: POST ▼ | TOTAL VIEWS columns
  - Clicking a column header sorts that column
  - Alternating row background (white / #f8f9fa)
- **Expandable per-post row** — click any row to expand:
  - Header: "LAST 30 DAYS WITH VIEWS" + "Total: N" (meta_count from post meta)
  - One row per day with views: date | count | split bar (green=self, teal=external) | labels
  - Green bar = self hits proportion, teal bar = external referrer proportion
  - Labels: "self: N" (green text) and "referrer.com (N)" (teal text)
  - Click again to collapse
- Data from `cspv_ajax_post_history` AJAX (existing endpoint)
- Search uses `cspv_ajax_post_search` AJAX

---

## AJAX Endpoints

| Action | Nonce | Purpose |
|--------|-------|---------|
| `cspv_insights_dashboard` | `cspv_insights_dashboard` | All rich dashboard data |
| `cspv_insights` | `cspv_insights` | Your Content trending posts |
| `cspv_post_history` | `cspv_chart_data` | Per-post 30-day timeline |
| `cspv_post_search` | `cspv_chart_data` | Post title search |

---

## Color Palette

20-hue vivid palette (cycling):
```
#ef4444 #f97316 #eab308 #22c55e #14b8a6
#3b82f6 #8b5cf6 #ec4899 #f43f5e #84cc16
#06b6d4 #a855f7 #10b981 #f59e0b #6366f1
#e11d48 #0ea5e9 #d946ef #65a30d #0891b2
```

Line chart dash patterns (cycling):
```
[] [6,3] [3,3] [8,3,2,3] [4,4] [10,3] [2,2] [6,2,2,2]
```

---

## Known Referrer Labels

| Pattern | Display label |
|---------|---------------|
| google  | Google |
| bing    | Bing |
| yahoo   | Yahoo |
| duckduckgo | DuckDuckGo |
| ecosia  | Ecosia |
| yandex  | Yandex |
| baidu   | Baidu |
| linkedin | LinkedIn |
| facebook | Facebook |
| instagram | Instagram |
| twitter / x.com / t.co | Twitter/X |
| reddit  | Reddit |
| pinterest | Pinterest |
| youtube | YouTube |
| own domain | Self |
| no referrer | Direct |
| other | hostname as-is |

---

## Database Tables Used

| Table | Purpose |
|-------|---------|
| `wp_cs_analytics_views_v2` | Views (`post_id`, `viewed_at`, `view_count`) |
| `wp_cs_analytics_referrers_v2` | Referrers (`post_id`, `viewed_at`, `referrer`, `view_count`) |
| `wp_cs_analytics_geo_v2` | Geography (`post_id`, `viewed_at`, `country_code`, `view_count`) |
| `wp_cs_analytics_visitors_v2` | Unique visitors (`visitor_hash`, `post_id`, `viewed_at`) |
