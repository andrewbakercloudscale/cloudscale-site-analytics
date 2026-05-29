// One-off script: capture computed styles of both stat blocks and screenshot
// Run via: WP_BASE_URL=... WP_COOKIES='...' node debug-widget-styles.js
// OR use the companion shell wrapper that fetches fresh CSDT cookies
const { chromium } = require('@playwright/test');
const path = require('path');

(async () => {
    const baseURL    = process.env.WP_BASE_URL || 'https://andrewbaker.ninja';
    const cookiesRaw = process.env.WP_COOKIES;

    const browser = await chromium.launch();
    let ctx;

    if (cookiesRaw) {
        const c = JSON.parse(cookiesRaw);
        ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await ctx.addCookies([
            { name: c.auth_name,  value: c.auth_value,  domain: c.domain, path: '/wp-admin',   httpOnly: true, secure: true, expires: c.expiration },
            { name: c.auth_name,  value: c.auth_value,  domain: c.domain, path: '/wp-content', httpOnly: true, secure: true, expires: c.expiration },
            { name: c.sec_name,   value: c.sec_value,   domain: c.domain, path: '/wp-admin',   httpOnly: true, secure: true, expires: c.expiration },
            { name: c.sec_name,   value: c.sec_value,   domain: c.domain, path: '/wp-content', httpOnly: true, secure: true, expires: c.expiration },
            { name: c.login_name, value: c.login_value, domain: c.domain, path: '/',           httpOnly: true, secure: true, expires: c.expiration },
        ]);
    } else {
        ctx = await browser.newContext({
            storageState: path.join(__dirname, 'auth.json'),
            viewport: { width: 1280, height: 900 },
        });
    }

    const page = await ctx.newPage();
    await page.goto(`${baseURL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    console.log('URL:', page.url());
    console.log('Title:', await page.title());

    // Click 1 Day tab
    const dayTab = page.locator('.cspv-dw-period-btn').filter({ hasText: '1 Day' });
    if (await dayTab.count()) {
        await dayTab.click();
        await page.waitForTimeout(1500);
    }

    // Screenshot full dashboard
    const outDir = path.join(__dirname, 'test-results');
    await page.screenshot({ path: path.join(outDir, 'widget-debug.png'), fullPage: false });
    console.log('Screenshot: test-results/widget-debug.png');

    // Get computed styles for all spans in .cspv-dw-counts
    const styles = await page.evaluate(() => {
        const container = document.querySelector('.cspv-dw-counts');
        if (!container) return { error: 'no .cspv-dw-counts', html: document.body.innerHTML.slice(0, 500) };
        const spans = Array.from(container.querySelectorAll('span'));
        return {
            containerComputedFontSize: getComputedStyle(container).fontSize,
            spans: spans.map(s => {
                const cs = getComputedStyle(s);
                return {
                    text: s.textContent.trim().slice(0, 25),
                    inlineFontSize: s.style.fontSize,
                    computedFontSize: cs.fontSize,
                    fontWeight: cs.fontWeight,
                    color: cs.color,
                    verticalAlign: cs.verticalAlign,
                    lineHeight: cs.lineHeight,
                };
            }),
        };
    });

    console.log('\n=== Computed styles ===');
    console.log(JSON.stringify(styles, null, 2));

    await browser.close();
})();
