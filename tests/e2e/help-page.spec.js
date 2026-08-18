/**
 * Help page rendering check
 *
 * Published help docs always live on help.cloudscale.consulting (see CLAUDE.md —
 * CloudScaleWpPluginHelpDocs is the single source of truth, publishing there for
 * all 5 plugins), never on the plugin's own site. So this uses an absolute URL
 * rather than one resolved against whichever site's admin the rest of the suite
 * is targeting via WP_BASE_URL, and needs no auth — the page is public.
 *
 * Previously pointed at /wordpress-plugin-help/cloudscale-wordpress-marketing-analytics/
 * and asserted .cs-hero / .cs-panel-heading / .cs-tip-box / #statistics anchors,
 * a page structure that stopped existing when docs moved off the plugin's own
 * site onto help.cloudscale.consulting's multi-page template (one page per
 * section, e.g. /plugin-help/analytics/statistics/, not anchors on one page) —
 * so it had been failing unconditionally. Rewritten 2026-08-18 against the
 * current template.
 */

const { test, expect } = require('@playwright/test');

const HELP_URL = 'https://help.cloudscale.consulting/plugin-help/analytics/';

test('help page renders HTML correctly (no raw JSON/CSS text)', async ({ page }) => {
    const response = await page.goto(HELP_URL, { waitUntil: 'domcontentloaded' });
    expect(response.status(), 'help page must return HTTP 200').toBe(200);

    await page.screenshot({ path: 'test-results/help-page.png', fullPage: true });

    // Title should render as a real heading, not raw text
    const h1 = page.locator('h1');
    await expect(h1).toBeVisible({ timeout: 5000 });
    await expect(h1).toContainText('CloudScale Site Analytics');

    // The section index should link out to each doc sub-page
    for (const section of ['statistics', 'insights', 'geography', '404-log', 'display', 'throttle']) {
        await expect(
            page.locator(`a[href*="/plugin-help/analytics/${section}/"]`).first(),
            `expected a link to the "${section}" doc section`
        ).toBeVisible();
    }

    // Page must NOT contain raw JSON or CSS as visible text — the original bug
    // this test guards against: an out-of-scope esc()/serialization leaking the
    // schema.org JSON-LD or a <style> block's source as plain page content.
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('@context');
    expect(bodyText).not.toMatch(/[a-z-]+\{font-family/);

    console.log('H1 text:', await h1.innerText());
});
