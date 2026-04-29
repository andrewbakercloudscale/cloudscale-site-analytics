/**
 * Analytics page load diagnostic
 * Checks that the main stats panels actually populate with data (not stuck on Loading…)
 */

const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

test('analytics page loads data without JS errors', async ({ page }) => {
    const jsErrors = [];
    const networkErrors = [];
    // Only track errors originating from our plugin, not from other admin page plugins
    page.on('pageerror', err => {
        if (err.stack && err.stack.includes('cloudscale-devtools')) return;
        jsErrors.push('PAGEERROR: ' + err.message);
    });
    page.on('console', msg => {
        if (msg.type() === 'error') jsErrors.push('CONSOLE ERROR: ' + msg.text());
    });
    page.on('response', response => {
        if (response.status() >= 400) {
            networkErrors.push(`HTTP ${response.status()}: ${response.url()}`);
        }
    });

    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Wait up to 20s for the "Most Viewed" panel to stop showing Loading…
    await expect(page.locator('#cspv-top-posts')).not.toContainText('Loading', { timeout: 20000 });

    // Referrers panel should also be populated
    await expect(page.locator('#cspv-referrers')).not.toContainText('Loading', { timeout: 5000 });

    // Take a screenshot for inspection
    await page.screenshot({ path: 'test-results/analytics-load.png', fullPage: false });

    // Log what's in the panels for debugging
    const topPosts   = await page.locator('#cspv-top-posts').innerHTML();
    const referrers  = await page.locator('#cspv-referrers').innerHTML();
    console.log('TOP POSTS panel:', topPosts.slice(0, 200));
    console.log('REFERRERS panel:', referrers.slice(0, 200));

    // Log network errors for debugging
    if (networkErrors.length > 0) {
        console.log('NETWORK ERRORS:', networkErrors.join('\n'));
    }

    // Fail if there were unexpected JS errors
    expect(jsErrors, 'unexpected JS errors: ' + jsErrors.join('; ')).toHaveLength(0);
});

test('referrer Details button opens drill-down panel', async ({ page }) => {
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // Wait for referrers to load
    await expect(page.locator('#cspv-referrers')).not.toContainText('Loading', { timeout: 20000 });

    // Check if there are any referrers to click
    const detailsBtns = page.locator('.cspv-ref-drill-btn');
    const count = await detailsBtns.count();
    console.log('Details buttons found:', count);

    if (count === 0) {
        console.log('No referrers in this period — skipping drill-down test');
        test.skip();
        return;
    }

    // Click the first Details button
    const hostName = await detailsBtns.first().getAttribute('data-host');
    console.log('Clicking Details for:', hostName);
    await detailsBtns.first().click();

    // Modal should open
    await expect(page.locator('#cspv-ref-drill-modal')).toHaveClass(/active/, { timeout: 5000 });
    await expect(page.locator('#cspv-ref-drill-title')).toContainText(hostName);

    // Wait for drill results (not loading)
    await expect(page.locator('#cspv-ref-drill-list')).not.toContainText('Loading', { timeout: 10000 });

    await page.screenshot({ path: 'test-results/analytics-drill.png', fullPage: false });

    // × button closes modal
    await page.locator('#cspv-ref-drill-close').click();
    await expect(page.locator('#cspv-ref-drill-modal')).not.toHaveClass(/active/, { timeout: 3000 });

    // Re-open and test Escape key closes it
    await detailsBtns.first().click();
    await expect(page.locator('#cspv-ref-drill-modal')).toHaveClass(/active/, { timeout: 5000 });
    await page.keyboard.press('Escape');
    await expect(page.locator('#cspv-ref-drill-modal')).not.toHaveClass(/active/, { timeout: 3000 });
});
