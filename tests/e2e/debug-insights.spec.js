const { test, expect } = require('@playwright/test');
const ADMIN_PAGE = '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics';

test('debug insights layout', async ({ page }) => {
    await page.goto(ADMIN_PAGE, { waitUntil: 'domcontentloaded' });
    
    // Click the Insights tab
    const insightsTab = page.locator('[data-tab="insights"]');
    if (await insightsTab.count() > 0) {
        await insightsTab.click();
    }
    
    // Wait for content to load
    await page.waitForTimeout(3000);
    
    // Check if content is visible
    const content = page.locator('#cspv-ins-content');
    const contentDisplay = await content.evaluate(el => window.getComputedStyle(el).display);
    console.log('ins-content display:', contentDisplay);
    
    // Check KPI grid computed style
    const grid = page.locator('.cspv-ins-kpi-grid');
    if (await grid.count() > 0) {
        const gridStyles = await grid.evaluate(el => {
            const s = window.getComputedStyle(el);
            return { display: s.display, gridTemplateColumns: s.gridTemplateColumns, width: s.width };
        });
        console.log('KPI grid styles:', JSON.stringify(gridStyles));
        
        const gridRect = await grid.boundingBox();
        console.log('KPI grid bounding box:', JSON.stringify(gridRect));
    } else {
        console.log('KPI grid NOT FOUND');
    }
    
    // Check legend
    const legend = page.locator('#cspv-ins-traffic-legend');
    if (await legend.count() > 0) {
        const legendHTML = await legend.innerHTML();
        console.log('Legend HTML (first 300):', legendHTML.slice(0, 300));
        const legendDisplay = await legend.evaluate(el => window.getComputedStyle(el).display);
        console.log('Legend display:', legendDisplay);
    }
    
    // Check if insights tab is active
    const tabPane = page.locator('#cspv-tab-insights');
    const paneClass = await tabPane.getAttribute('class');
    console.log('Insights tab pane class:', paneClass);
    
    await page.screenshot({ path: 'test-results/insights-debug.png', fullPage: false });
});
