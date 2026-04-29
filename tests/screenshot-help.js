const { chromium } = require('playwright');

(async () => {
    const label = process.argv[2] || 'before';
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.goto('https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-wordpress-marketing-analytics/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: `/tmp/help-${label}.png`, fullPage: true });
    console.log(`Screenshot saved: /tmp/help-${label}.png`);
    await browser.close();
})();
