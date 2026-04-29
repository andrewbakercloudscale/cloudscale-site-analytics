/**
 * 404 Error Log — end-to-end tests
 *
 * Covers:
 *  1. Visiting a non-existent URL as an admin records the hit in the 404 log.
 *  2. The recorded URL appears in the 404 Error Log table on the stats page.
 *
 * Before this fix the admin exclusion guard in cspv_track_404() silently
 * dropped every 404 triggered by a logged-in administrator, so the log was
 * always empty during normal testing/browsing.
 */

const { test, expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

// A unique slug so we can identify this exact hit in the log.
const NOT_FOUND_SLUG = `cspv-test-404-${Date.now()}`;

test.describe('404 Error Log', () => {
    test('visiting a 404 page as admin records the hit in the error log', async ({ page }) => {
        // ── Step 1: trigger a 404 ────────────────────────────────────────────
        // Navigate to a URL that does not exist. WordPress will return a 404.
        const response = await page.goto(`/${NOT_FOUND_SLUG}`, { waitUntil: 'domcontentloaded' });
        expect(response.status(), 'page should return HTTP 404').toBe(404);

        // ── Step 2: open the analytics admin page ────────────────────────────
        await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });

        // The stats tab is active by default; the 404 panel is on that tab.
        const panel = page.locator('#cspv-404-inner');
        await expect(panel).toBeVisible();

        // ── Step 3: assert the URL appears in the log ────────────────────────
        // The table renders full URLs (e.g. https://andrewbaker.ninja/cspv-test-404-…)
        // so we match on the slug fragment which is unique to this test run.
        await expect(
            panel.locator(`text=${NOT_FOUND_SLUG}`),
            `expected "${NOT_FOUND_SLUG}" to appear in the 404 log table`
        ).toBeVisible();
    });
});
