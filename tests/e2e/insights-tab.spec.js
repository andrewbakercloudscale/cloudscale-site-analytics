/**
 * Insights tab — layout, CSS, and data verification
 * Verifies: KPI cards load real data, all chart canvases render, CSS grid applies,
 * legend dots are colored, period buttons reload data, country flags appear,
 * Your Content uses the Insights period (not Stats tab dates).
 */

const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

async function openInsightsTab(page) {
    const jsErrors = [];
    const networkErrors = [];
    page.on('pageerror', err => {
        if (err.stack && err.stack.includes('cloudscale-devtools')) return;
        jsErrors.push('PAGEERROR: ' + err.message);
    });
    page.on('console', msg => {
        if (msg.type() === 'error') jsErrors.push('CONSOLE ERROR: ' + msg.text());
    });
    page.on('response', response => {
        if (response.status() >= 400) networkErrors.push(`HTTP ${response.status()}: ${response.url()}`);
    });
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-tab="insights"]').click();
    return { jsErrors, networkErrors };
}

test('Insights tab activates without JS or network errors', async ({ page }) => {
    const { jsErrors, networkErrors } = await openInsightsTab(page);

    // Wait for content to appear (loading state → content)
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    await page.screenshot({ path: 'test-results/insights-tab.png', fullPage: false });

    if (networkErrors.length) console.log('NETWORK ERRORS:', networkErrors.join('\n'));
    expect(jsErrors, 'JS errors: ' + jsErrors.join('; ')).toHaveLength(0);
    expect(networkErrors.filter(e => !e.includes('404')  /* allow pre-existing 404s */),
        'Network errors: ' + networkErrors.join('; ')).toHaveLength(0);
});

test('KPI cards show real numeric values', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    // Views and visitors should be numbers
    await expect(page.locator('#cspv-ins-kpi-views')).not.toHaveText('—', { timeout: 10000 });
    await expect(page.locator('#cspv-ins-kpi-visitors')).not.toHaveText('—', { timeout: 10000 });

    const viewsText = await page.locator('#cspv-ins-kpi-views').textContent();
    const visitorsText = await page.locator('#cspv-ins-kpi-visitors').textContent();
    console.log('KPI views:', viewsText, '  visitors:', visitorsText);

    expect(viewsText).toMatch(/[\d,]+/);
    expect(visitorsText).toMatch(/[\d,]+/);
});

test('KPI grid is a CSS grid with multiple columns', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    const gridStyles = await page.locator('.cspv-ins-kpi-grid').evaluate(el => {
        const s = window.getComputedStyle(el);
        return {
            display:             s.display,
            gridTemplateColumns: s.gridTemplateColumns,
            width:               el.offsetWidth,
        };
    });
    console.log('KPI grid styles:', JSON.stringify(gridStyles));

    expect(gridStyles.display).toBe('grid');
    // Should have at least 2 column tracks
    const colCount = (gridStyles.gridTemplateColumns.match(/\d+px/g) || []).length;
    expect(colCount).toBeGreaterThanOrEqual(2);
    expect(gridStyles.width).toBeGreaterThan(400);
});

test('KPI cards have colored top-border accents', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });
    // Wait a tick for JS to apply accent colors
    await page.waitForTimeout(500);

    const accent = await page.locator('#cspv-ins-kpi-card-views').evaluate(el => {
        return window.getComputedStyle(el).borderTopColor;
    });
    console.log('Views card accent color:', accent);
    // Should not be the default grey (#e5e7eb → rgb(229,231,235))
    expect(accent).not.toBe('rgb(229, 231, 235)');
});

test('All chart canvases render with non-zero dimensions', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(1500); // allow charts to paint

    const canvasIds = [
        'cspv-ins-traffic-chart',
        'cspv-ins-growth-chart',
        'cspv-ins-posts-chart',
        'cspv-ins-country-chart',
        'cspv-ins-country-time-chart',
        'cspv-ins-refs-chart',
    ];
    for (const id of canvasIds) {
        const box = await page.locator('#' + id).boundingBox();
        console.log(id, '->', JSON.stringify(box));
        expect(box, id + ' canvas has no bounding box').not.toBeNull();
        expect(box.width, id + ' width should be > 0').toBeGreaterThan(0);
        expect(box.height, id + ' height should be > 0').toBeGreaterThan(0);
    }
});

test('Traffic Sources legend has colored dots', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(1000);

    const items = page.locator('#cspv-ins-traffic-legend .cspv-ins-legend-item');
    const count = await items.count();
    console.log('Traffic legend items:', count);
    expect(count).toBeGreaterThanOrEqual(2);

    // Each dot should have a non-empty background color (not transparent or empty)
    const firstDot = await items.first().locator('.cspv-ins-legend-dot').evaluate(el => {
        return window.getComputedStyle(el).backgroundColor;
    });
    console.log('First legend dot bg:', firstDot);
    expect(firstDot).not.toBe('rgba(0, 0, 0, 0)');
    expect(firstDot).not.toBe('');
    expect(firstDot).not.toBe('transparent');
});

test('Period button switch reloads dashboard', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    const before = await page.locator('#cspv-ins-kpi-views').textContent();

    // Switch to 7 days
    await page.locator('[data-period="7"]').click();
    // Loading state should appear
    await expect(page.locator('#cspv-ins-loading')).toBeVisible({ timeout: 5000 });
    // Then content should return
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 15000 });

    const after = await page.locator('#cspv-ins-kpi-views').textContent();
    console.log('Views before (30d):', before, '  after (7d):', after);
    // Both should be numeric
    expect(after).toMatch(/[\d,]+/);
});

test('Your Content shows "Last N days" not a calendar date range', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    // Wait for Your Content to load
    await expect(page.locator('#cspv-insights-list')).not.toContainText('Loading', { timeout: 15000 });

    const rangeText = await page.locator('#cspv-insights-range').textContent();
    console.log('Your Content range label:', rangeText);
    expect(rangeText).toContain('Last');
    // Should NOT look like a calendar range (e.g. "1 Nov 2025 – 29 Apr 2026")
    expect(rangeText).not.toMatch(/\d{1,2} \w+ \d{4}/);
});

test('Country time chart legend contains flag emojis', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(1500);

    const legendText = await page.locator('#cspv-ins-country-time-legend').textContent();
    console.log('Country time legend text:', legendText);

    if (legendText.trim()) {
        // Flag emoji characters are in the range U+1F1E6–U+1F1FF
        const hasFlag = /[\u{1F1E6}-\u{1F1FF}]/u.test(legendText);
        expect(hasFlag, 'Country legend should contain flag emoji').toBe(true);
    } else {
        console.log('No country time data — skipping flag check');
        test.skip();
    }
});

test('Insights tab takes a full-page screenshot', async ({ page }) => {
    await openInsightsTab(page);
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });
    await page.waitForTimeout(2000);
    await page.screenshot({ path: 'test-results/insights-full.png', fullPage: true });
});
