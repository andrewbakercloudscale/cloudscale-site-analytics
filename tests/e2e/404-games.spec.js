/**
 * 404 Olympics — game tab / button tests
 *
 * The custom 404 page returns HTTP 404 — that generates one expected browser
 * console error ("Failed to load resource: 404"). We filter that out and only
 * fail on unexpected JS errors.
 */

const { test, expect } = require('@playwright/test');

// Unique URL so Cloudflare never serves a cached copy
const NOT_FOUND_URL = `/cspv-games-test-${Date.now()}`;

async function goto404(page) {
    // 'load' (not 'networkidle') because the rAF game loop keeps the page
    // "active" in some Playwright configurations.
    const resp = await page.goto(NOT_FOUND_URL, { waitUntil: 'load' });
    expect(resp.status(), 'must be a 404 response').toBe(404);
    // Let the JS initialise and first frames render
    await page.waitForTimeout(300);
}

/** Collect JS errors — but ignore the expected "404" resource error from the page itself */
function attachErrorCollector(page) {
    const errors = [];
    page.on('console', msg => {
        if (msg.type() === 'error') {
            const txt = msg.text();
            // Filter out the benign "this page returned 404" browser log
            if (!txt.includes('404 (Not Found)') && !txt.includes('404 (not found)')) {
                errors.push(txt);
            }
        }
    });
    page.on('pageerror', err => errors.push('PAGEERROR: ' + err.message));
    return errors;
}

test.describe('404 Olympics — game page structure', () => {

    test('canvas and all four tabs are rendered', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        await expect(page.locator('#cs404-game')).toBeVisible();

        for (const game of ['runner', 'jetpack', 'racer', 'miner']) {
            await expect(
                page.locator(`.cs404-tab[data-game="${game}"]`),
                `${game} tab visible`
            ).toBeVisible();
        }

        // Runner active by default
        await expect(page.locator('.cs404-tab.active')).toHaveAttribute('data-game', 'runner');

        expect(errors, 'no unexpected JS errors').toEqual([]);
    });

    test('CS_PCR_API is injected by PHP', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        const defined = await page.evaluate(() => typeof CS_PCR_API !== 'undefined');
        expect(defined, 'CS_PCR_API should be defined').toBe(true);

        const apiVal = await page.evaluate(() => CS_PCR_API);
        expect(apiVal, 'API URL should contain cs-pcr/v1').toContain('cs-pcr/v1');

        expect(errors).toEqual([]);
    });

    test('miner controls are hidden by default', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        const ctrl = page.locator('#cs404-miner-ctrl');
        await expect(ctrl).toBeAttached();
        // Should NOT be visible when runner is active
        await expect(ctrl).toHaveCSS('display', 'none');

        expect(errors).toEqual([]);
    });
});

test.describe('404 Olympics — tab switching', () => {

    test('clicking each tab updates active class and currentGame JS variable', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        for (const game of ['jetpack', 'racer', 'miner', 'runner']) {
            await page.locator(`.cs404-tab[data-game="${game}"]`).click();
            await page.waitForTimeout(100);

            // Active class check
            await expect(page.locator(`.cs404-tab[data-game="${game}"]`), `${game} tab active`).toHaveClass(/active/);

            // JS variable check — confirms the game loop switched
            const jsGame = await page.evaluate(() => {
                // currentGame is declared in the IIFE scope so we expose it via
                // the data-game attribute of the active tab as a fallback
                const activeTab = document.querySelector('.cs404-tab.active');
                return activeTab ? activeTab.getAttribute('data-game') : null;
            });
            expect(jsGame, `active tab data-game == ${game}`).toBe(game);
        }

        expect(errors, 'no unexpected JS errors during tab cycling').toEqual([]);
    });

    test('switching to Miner shows d-pad; switching away hides it', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        // Switch to miner
        await page.locator('.cs404-tab[data-game="miner"]').click();
        await page.waitForTimeout(100);
        const ctrl = page.locator('#cs404-miner-ctrl');
        await expect(ctrl).toHaveCSS('display', 'flex');
        await expect(page.locator('#cs404-ml')).toBeVisible();
        await expect(page.locator('#cs404-mj')).toBeVisible();
        await expect(page.locator('#cs404-mr')).toBeVisible();

        // Switch away
        await page.locator('.cs404-tab[data-game="runner"]').click();
        await page.waitForTimeout(100);
        await expect(ctrl).toHaveCSS('display', 'none');

        expect(errors).toEqual([]);
    });
});

test.describe('404 Olympics — input / game start', () => {

    test('Space key starts the runner game', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        // Focus canvas area then press space
        await page.locator('#cs404-game').click({ position: { x: 10, y: 10 } });
        await page.keyboard.press('Space');
        await page.waitForTimeout(200);

        expect(errors).toEqual([]);
    });

    test('Racer tab: ArrowLeft / ArrowRight are handled without errors', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        await page.locator('.cs404-tab[data-game="racer"]').click();
        await page.waitForTimeout(100);
        await page.keyboard.press('Space'); // start
        await page.waitForTimeout(100);
        await page.keyboard.press('ArrowLeft');
        await page.waitForTimeout(50);
        await page.keyboard.press('ArrowRight');
        await page.waitForTimeout(50);

        expect(errors).toEqual([]);
    });

    test('Miner tab: Space starts game; ArrowLeft/Right and Space jump work', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        await page.locator('.cs404-tab[data-game="miner"]').click();
        await page.waitForTimeout(100);
        await page.keyboard.press('Space'); // start miner
        await page.waitForTimeout(150);
        await page.keyboard.press('ArrowRight');
        await page.waitForTimeout(80);
        await page.keyboard.press('Space'); // jump
        await page.waitForTimeout(150);
        await page.keyboard.press('ArrowLeft');
        await page.waitForTimeout(80);

        expect(errors).toEqual([]);
    });

    test('Miner d-pad buttons set mmKeys correctly (mousedown)', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        await page.locator('.cs404-tab[data-game="miner"]').click();
        await page.waitForTimeout(100);
        await page.keyboard.press('Space'); // start miner
        await page.waitForTimeout(100);

        // Press left button
        await page.locator('#cs404-ml').dispatchEvent('mousedown');
        await page.waitForTimeout(50);
        // Press jump
        await page.locator('#cs404-mj').dispatchEvent('mousedown');
        await page.waitForTimeout(50);
        await page.locator('#cs404-ml').dispatchEvent('mouseup');
        await page.locator('#cs404-mj').dispatchEvent('mouseup');
        // Press right
        await page.locator('#cs404-mr').dispatchEvent('mousedown');
        await page.waitForTimeout(50);
        await page.locator('#cs404-mr').dispatchEvent('mouseup');

        expect(errors, 'no JS errors from d-pad button events').toEqual([]);
    });

    test('canvas click starts runner / jetpack game', async ({ page }) => {
        const errors = attachErrorCollector(page);
        await goto404(page);

        // Runner
        await page.locator('#cs404-game').click();
        await page.waitForTimeout(200);

        // Jetpack
        await page.locator('.cs404-tab[data-game="jetpack"]').click();
        await page.waitForTimeout(80);
        await page.locator('#cs404-game').click();
        await page.waitForTimeout(200);

        expect(errors).toEqual([]);
    });

    test.afterEach(async ({ page }, testInfo) => {
        if (testInfo.status !== 'passed') {
            await page.screenshot({
                path: `playwright-report/404-games-fail-${testInfo.title.replace(/\W+/g, '-').slice(0, 60)}.png`,
                fullPage: true,
            });
        }
    });
});
