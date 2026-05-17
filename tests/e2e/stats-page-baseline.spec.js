/**
 * stats-page.php baseline — run BEFORE and AFTER every refactor phase.
 * All tests must pass on the current code; any regression means the refactor broke something.
 */

const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

// ─── helpers ────────────────────────────────────────────────────────────────

function captureErrors(page) {
    const jsErrors = [];
    page.on('pageerror', err => {
        if (err.stack && err.stack.includes('cloudscale-devtools')) return;
        jsErrors.push('PAGEERROR: ' + err.message);
    });
    page.on('console', msg => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        // Skip pre-existing 400s from other admin page scripts (beacon, CSP, etc.)
        if (text.includes('400') || text.includes('Failed to load resource')) return;
        jsErrors.push('CONSOLE ERROR: ' + text);
    });
    return jsErrors;
}

async function gotoStats(page) {
    const jsErrors = captureErrors(page);
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    return jsErrors;
}

async function getCspvStats(page) {
    return page.evaluate(() => window.cspvStats || null);
}

// ─── Group A: Page structure ─────────────────────────────────────────────────

test('A1: all four tab buttons are present', async ({ page }) => {
    await gotoStats(page);
    for (const tab of ['stats', 'insights', 'display', 'throttle']) {
        await expect(page.locator(`[data-tab="${tab}"]`)).toBeVisible();
    }
});

test('A2: page loads with zero JS errors (excluding known 400s)', async ({ page }) => {
    const jsErrors = await gotoStats(page);
    await page.waitForTimeout(1500);
    expect(jsErrors, 'unexpected JS errors on load: ' + jsErrors.join('; ')).toHaveLength(0);
});

// ─── Group B: Tab switching ──────────────────────────────────────────────────

test('B1: clicking each tab activates it', async ({ page }) => {
    await gotoStats(page);
    for (const tab of ['insights', 'display', 'throttle', 'stats']) {
        await page.locator(`[data-tab="${tab}"]`).click();
        await expect(page.locator(`[data-tab="${tab}"]`)).toHaveClass(/active/, { timeout: 3000 });
    }
});

test('B2: stats tab is active on initial page load', async ({ page }) => {
    await gotoStats(page);
    const statsBtn = page.locator('[data-tab="stats"]');
    await expect(statsBtn).toHaveClass(/active/);
    // Stats tab period buttons (data-range, not data-period) should be visible
    await expect(page.locator('#cspv-quick-btns').first()).toBeVisible({ timeout: 5000 });
});

// ─── Group C: Statistics tab content ─────────────────────────────────────────

test('C1: stats tab period buttons are present', async ({ page }) => {
    await gotoStats(page);
    // Stats tab uses data-range (not data-period which is for insights)
    const rangeBtns = page.locator('.cspv-quick[data-range]');
    const count = await rangeBtns.count();
    console.log('Stats range buttons found:', count);
    expect(count).toBeGreaterThanOrEqual(4);
});

test('C2: 1-week button loads top-posts panel', async ({ page }) => {
    await gotoStats(page);
    await page.locator('.cspv-quick[data-range="7"]').click();
    await expect(page.locator('#cspv-top-posts')).not.toContainText('Loading', { timeout: 20000 });
    const html = await page.locator('#cspv-top-posts').innerHTML();
    console.log('top-posts (7d):', html.slice(0, 200));
});

test('C3: referrers panel resolves after 1-week load', async ({ page }) => {
    await gotoStats(page);
    await page.locator('.cspv-quick[data-range="7"]').click();
    await expect(page.locator('#cspv-referrers')).not.toContainText('Loading', { timeout: 20000 });
    const html = await page.locator('#cspv-referrers').innerHTML();
    console.log('referrers (7d):', html.slice(0, 200));
});

// ─── Group D: Display tab form ───────────────────────────────────────────────

test('D1: display tab has nonce field in DOM', async ({ page }) => {
    await gotoStats(page);
    await page.locator('[data-tab="display"]').click();
    // wp_nonce_field generates type="hidden" — use toBeAttached not toBeVisible
    await expect(page.locator('[name="cspv_display_nonce"]')).toBeAttached({ timeout: 5000 });
});

test('D2: display tab save button is visible', async ({ page }) => {
    await gotoStats(page);
    await page.locator('[data-tab="display"]').click();
    // The display save button is type="button" (AJAX save), not type="submit"
    await expect(page.locator('#cspv-save-display')).toBeVisible({ timeout: 5000 });
});

// ─── Group E: Throttle tab ───────────────────────────────────────────────────

test('E1: throttle tab shows its content', async ({ page }) => {
    await gotoStats(page);
    await page.locator('[data-tab="throttle"]').click();
    // The throttle pane should become visible
    await expect(page.locator('#cspv-tab-throttle')).toBeVisible({ timeout: 5000 });
});

// ─── Group F: AJAX endpoint smoke tests ──────────────────────────────────────
// Uses page.request which shares the browser session cookies automatically.

test('F1: cspv_chart_data returns valid JSON', async ({ page }) => {
    await gotoStats(page);
    const stats = await getCspvStats(page);
    expect(stats, 'cspvStats must be defined on the page').not.toBeNull();

    // chart_data requires date_from and date_to in YYYY-MM-DD format
    const today = new Date().toISOString().slice(0, 10);
    const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
    const resp = await page.request.post('/wp-admin/admin-ajax.php', {
        form: { action: 'cspv_chart_data', date_from: weekAgo, date_to: today, nonce: stats.nonce },
    });
    console.log('F1 status:', resp.status());
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    console.log('cspv_chart_data success:', body.success);
    expect(body).toHaveProperty('success');
});

test('F2: cspv_post_search returns results array', async ({ page }) => {
    await gotoStats(page);
    const stats = await getCspvStats(page);
    expect(stats).not.toBeNull();

    const resp = await page.request.post('/wp-admin/admin-ajax.php', {
        form: { action: 'cspv_post_search', q: 'test', nonce: stats.nonce },
    });
    console.log('F2 status:', resp.status());
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    console.log('cspv_post_search response:', JSON.stringify(body).slice(0, 200));
    // Response is wrapped: {success: true, data: [...]}
    expect(body).toHaveProperty('success', true);
    expect(Array.isArray(body.data)).toBe(true);
});

test('F3: cspv_insights returns success response', async ({ page }) => {
    await gotoStats(page);
    const stats = await getCspvStats(page);
    expect(stats).not.toBeNull();

    // insights handler requires from/to in YYYY-MM-DD format
    const today = new Date().toISOString().slice(0, 10);
    const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
    const resp = await page.request.post('/wp-admin/admin-ajax.php', {
        form: { action: 'cspv_insights', from: weekAgo, to: today, nonce: stats.insightsNonce },
    });
    console.log('F3 status:', resp.status());
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    console.log('cspv_insights success:', body.success);
    expect(body).toHaveProperty('success');
});

test('F4: cspv_insights_dashboard returns success response', async ({ page }) => {
    await gotoStats(page);
    const stats = await getCspvStats(page);
    expect(stats).not.toBeNull();

    const resp = await page.request.post('/wp-admin/admin-ajax.php', {
        form: { action: 'cspv_insights_dashboard', period: '7', nonce: stats.dashboardNonce },
    });
    console.log('F4 status:', resp.status());
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    console.log('cspv_insights_dashboard success:', body.success);
    expect(body).toHaveProperty('success');
});
