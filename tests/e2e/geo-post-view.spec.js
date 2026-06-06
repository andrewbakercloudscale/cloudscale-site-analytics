const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

test('Geo Post View section loads and renders map on post click', async ({ page }) => {
    const jsErrors = [];
    page.on('pageerror', err => {
        if (err.stack && err.stack.includes('cloudscale-devtools')) return;
        jsErrors.push(err.message);
    });

    // Capture AJAX response for geo map to diagnose failures
    let geoAjaxResponse = null;
    page.on('response', async resp => {
        if (resp.url().includes('admin-ajax.php') && resp.request().postData()?.includes('cspv_post_geo_map')) {
            try { geoAjaxResponse = await resp.json(); } catch (e) { geoAjaxResponse = { parseError: e.message }; }
        }
    });

    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Check version in header
    const banner = page.locator('#cspv-banner-title');
    await expect(banner).toBeVisible({ timeout: 10000 });
    const bannerText = await banner.textContent();
    console.log('Plugin version:', bannerText);

    // Open Insights tab
    await page.locator('[data-tab="insights"]').click();
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    // Scroll to Geo Post View section
    const geoList = page.locator('#cspv-geo-post-list');
    await expect(geoList).toBeVisible({ timeout: 10000 });
    await geoList.scrollIntoViewIfNeeded();

    await page.screenshot({ path: 'test-results/geo-section-before-click.png', fullPage: false });

    // Count post items
    const items = geoList.locator('.cspv-geo-post-item');
    const count = await items.count();
    console.log('Geo post items:', count);
    expect(count, 'Should have at least one post in geo list').toBeGreaterThan(0);

    // Click the first post
    const firstItem = items.first();
    const postTitle = await firstItem.locator('div').first().textContent();
    console.log('Clicking post:', postTitle?.trim());
    await firstItem.click();

    // Map wrap should become visible
    const mapWrap = page.locator('#cspv-geo-map-wrap');
    await expect(mapWrap).toBeVisible({ timeout: 15000 });

    // Map title should show the post name
    const mapTitle = page.locator('#cspv-geo-map-post-title');
    await expect(mapTitle).toBeVisible();
    const titleText = await mapTitle.textContent();
    console.log('Map title:', titleText);
    expect(titleText).toContain('🗺');

    // Wait for AJAX to complete — map el should no longer show Loading
    const mapEl = page.locator('#cspv-geo-map-el');
    await expect(mapEl).toBeVisible();
    await expect(mapEl).not.toContainText('Loading', { timeout: 20000 });

    console.log('AJAX response:', JSON.stringify(geoAjaxResponse));

    // Map either rendered Leaflet (has geo data) or shows no-data message (still a pass)
    const mapHtml = await mapEl.innerHTML();
    const hasLeaflet = mapHtml.includes('leaflet-container') || mapHtml.includes('leaflet-pane');
    const hasNoData = mapHtml.includes('No geo data');
    console.log('Map state: leaflet=' + hasLeaflet + ' noData=' + hasNoData);
    expect(hasLeaflet || hasNoData, 'Map must show Leaflet or no-data message, not stuck on Loading').toBe(true);

    if (hasLeaflet) {
        // Leaflet panes are absolutely positioned inside overflow:hidden — check DOM presence, not CSS visibility.
        const leafletPaneCount = await mapEl.locator('.leaflet-pane').count();
        expect(leafletPaneCount, 'Leaflet should have rendered at least one pane').toBeGreaterThan(0);
    }

    await page.screenshot({ path: 'test-results/geo-section-map-loaded.png', fullPage: false });

    // No new JS errors
    expect(jsErrors, 'JS errors: ' + jsErrors.join('; ')).toHaveLength(0);
});

test('Geo Post View switches map when different post clicked', async ({ page }) => {
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-tab="insights"]').click();
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    const items = page.locator('#cspv-geo-post-list .cspv-geo-post-item');
    await expect(items.first()).toBeVisible({ timeout: 10000 });

    // Click first post
    await items.first().click();
    await expect(page.locator('#cspv-geo-map-wrap')).toBeVisible({ timeout: 15000 });
    const title1 = await page.locator('#cspv-geo-map-post-title').textContent();

    // Click second post if available
    const count = await items.count();
    if (count >= 2) {
        await items.nth(1).click();
        // Map wrap stays visible, title updates
        await expect(page.locator('#cspv-geo-map-wrap')).toBeVisible();
        await page.waitForTimeout(1000);
        const title2 = await page.locator('#cspv-geo-map-post-title').textContent();
        console.log('Title 1:', title1, '| Title 2:', title2);
        expect(title2).not.toEqual(title1);
    }

    await page.screenshot({ path: 'test-results/geo-section-switched.png', fullPage: false });
});
