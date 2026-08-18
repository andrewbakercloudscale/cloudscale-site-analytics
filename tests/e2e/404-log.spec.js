/**
 * 404 Error Log — end-to-end tests
 *
 * Covers: visiting a non-existent URL as an admin records the hit in the 404 log.
 *
 * Before this fix the admin exclusion guard in cspv_track_404() silently
 * dropped every 404 triggered by a logged-in administrator, so the log was
 * always empty during normal testing/browsing.
 *
 * The table only renders the top 50 URLs by hit_count DESC — a real site
 * accumulates thousands of bot-scanned 404s, so a single fresh test hit
 * (hit_count=1) will never be ranked into that view once the 50th row's
 * hit_count is above 1 (andrewbaker.ninja: 47k+ unique URLs, 50th row at
 * 167 hits at time of writing). Asserting the test's own slug is visible in
 * the table therefore fails unconditionally on any site with real traffic,
 * regardless of whether tracking actually works. Instead this asserts the
 * one thing that's actually true regardless of table size: the "unique
 * URLs" count goes up by exactly one for a guaranteed-unique slug — which
 * is precisely what the admin-exclusion regression this test guards
 * against would break (the count would stay flat instead).
 */

const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-site-analytics';

// A unique slug so this run cannot collide with a previous run's hit.
const NOT_FOUND_SLUG = `cspv-test-404-${Date.now()}`;

async function openAndExpand404Panel(page) {
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

    // The 404 panel lives on the Insights tab (not Statistics, which is active
    // by default), and Insights content only loads once that tab is clicked.
    await page.locator('[data-tab="insights"]').click();
    await expect(page.locator('#cspv-ins-content')).toBeVisible({ timeout: 20000 });

    // It is collapsed by default (display:none until #cspv-404-header is clicked),
    // but the open/closed state persists in localStorage across page loads — so on
    // a second visit within the same test it may already be open. Clicking
    // unconditionally would toggle it back closed, so only click if still hidden.
    const panel = page.locator('#cspv-404-inner');
    if (!(await panel.isVisible())) {
        await page.locator('#cspv-404-header').click();
    }
    await expect(panel).toBeVisible();
    return panel;
}

async function getUniqueUrlCount(panel) {
    // No trailing anchor: the source HTML has trailing whitespace before </span>,
    // and Playwright's regex text-matching (unlike its string matching) does not
    // normalize that away, so /unique URLs$/ never matches.
    const text = await panel.getByText(/unique URLs/).textContent();
    return parseInt(text.replace(/[^\d]/g, ''), 10);
}

test.describe('404 Error Log', () => {
    test('visiting a 404 page as admin records the hit in the error log', async ({ page }) => {
        const before = await getUniqueUrlCount(await openAndExpand404Panel(page));

        // ── Trigger a 404 for a guaranteed-new URL ───────────────────────────
        const response = await page.goto(`/${NOT_FOUND_SLUG}`, { waitUntil: 'domcontentloaded' });
        expect(response.status(), 'page should return HTTP 404').toBe(404);

        const after = await getUniqueUrlCount(await openAndExpand404Panel(page));
        console.log('Unique 404 URLs before:', before, ' after:', after);
        expect(after, 'unique URL count should increase by exactly 1 for this admin-triggered 404').toBe(before + 1);
    });
});
