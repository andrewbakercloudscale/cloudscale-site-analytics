/**
 * Help page rendering check
 * Verifies the cs-hero, cs-panel-heading, and cs-tip-box render as HTML (not raw text)
 */

const { test, expect } = require('@playwright/test');

const HELP_URL = '/wordpress-plugin-help/cloudscale-wordpress-marketing-analytics/';

test('help page renders HTML correctly (no raw JSON/CSS text)', async ({ page }) => {
    await page.goto(HELP_URL, { waitUntil: 'domcontentloaded' });

    await page.screenshot({ path: 'test-results/help-page.png', fullPage: true });

    // Hero gradient div should exist and be visible
    const hero = page.locator('.cs-hero');
    await expect(hero).toBeVisible({ timeout: 5000 });

    // Title inside hero should be the short one
    await expect(hero.locator('h1')).toContainText('CloudScale Site Analytics');

    // cs-tip-box should exist (new CSS class)
    await expect(page.locator('.cs-tip-box').first()).toBeVisible();

    // Panel headings should exist
    await expect(page.locator('#statistics')).toBeVisible();
    await expect(page.locator('#history')).toBeVisible();
    await expect(page.locator('#referrer-drilldown')).toBeVisible();

    // Page must NOT contain raw JSON or CSS as visible text
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('@context');
    expect(bodyText).not.toContain('cs-help-docs{font-family');

    console.log('Hero text:', await hero.innerText().then(t => t.slice(0, 120)));
});
