/**
 * Destructive Actions Audit
 *
 * Visits every CloudScale plugin admin page, walks all tabs, waits for
 * dynamic history rows, and checks that every visible destructive button
 * shows a confirmation before any AJAX POST fires.
 *
 * Pass criteria (per button):
 *   - A native dialog (window.confirm / window.alert) was triggered, OR
 *   - A visible modal/confirmation panel appeared within 600ms of the click
 *
 * Fail criteria:
 *   - A network POST fired within 600ms with no dialog and no modal
 */

const { test, expect } = require('@playwright/test');

// Tab selectors to click through on each page (data-tab values for backup; other pages use their own)
const PAGE_TABS = {
    'Backup': ['local', 'cloud', 'autorecovery'],
};

const PAGES = [
    { name: 'Analytics',  url: '/wp-admin/tools.php?page=cloudscale-wordpress-free-analytics', tabs: [] },
    { name: 'Backup',     url: '/wp-admin/admin.php?page=cloudscale-backup',                   tabs: ['local', 'cloud', 'autorecovery'] },
    { name: 'Cleanup',    url: '/wp-admin/tools.php?page=cloudscale-cleanup',                  tabs: [] },
    { name: 'DevTools',   url: '/wp-admin/tools.php?page=cloudscale-devtools',                 tabs: [] },
    { name: 'SEO',        url: '/wp-admin/admin.php?page=cs-seo-optimizer',                    tabs: [] },
];

// Text patterns that indicate a destructive action
const DESTRUCTIVE_RE = /\b(delete|remove|reset|clear|wipe|purge|trash|revoke|rollback|deactivate|free space)\b/i;

// Selectors that indicate a visible confirmation UI after a click
const MODAL_SELECTORS = [
    '#cs-dialog-modal',          // csConfirm() — used by all backup plugin destructive actions
    '#cs-dialog-overlay',
    '.cs-modal:not([style*="display: none"]):not([style*="display:none"])',
    '[role="dialog"]:not([hidden])',
    '.cs-confirm',
    '.cs-alert-modal',
    '#cs-restore-modal',
    '#cs-confirm-modal',
    '[class*="confirm"]:not([style*="display: none"])',
    '[class*="modal"]:not([style*="display: none"])',
    '[class*="dialog"]:not([style*="display: none"])',
];

// Skip patterns — UI toggles or intentionally confirmation-free actions
const SKIP_PATTERNS = [
    /remove.*filter/i,
    /clear.*search/i,
    /clear.*filter/i,
    /dismiss.*banner/i,
    /dismiss.*notice/i,
    /reset.*view/i,
    /got it/i,
    /deactivate.*2fa/i,   // never touch 2FA
];

function shouldSkip(text) {
    return SKIP_PATTERNS.some(re => re.test(text));
}

async function isModalVisible(page) {
    for (const sel of MODAL_SELECTORS) {
        try {
            const el = page.locator(sel).first();
            if (await el.isVisible({ timeout: 100 })) return true;
        } catch { /* continue */ }
    }
    return false;
}

async function dismissModal(page) {
    // Prefer Cancel button (explicit, reliable) over overlay click
    const dismissors = [
        '#cs-dialog-cancel',
        'button:has-text("Cancel")',
        'button:has-text("No")',
        'button:has-text("Close")',
        '.cs-modal-close',
        '[data-dismiss]',
        '#cs-dialog-overlay',
        '.cs-modal-overlay',
    ];
    for (const sel of dismissors) {
        try {
            const d = page.locator(sel).first();
            if (await d.isVisible({ timeout: 200 })) {
                await d.click({ timeout: 1000 });
                break;
            }
        } catch { /* continue */ }
    }
    // Force-hide cs-dialog overlay and modal via JS — jQuery .hide() and Playwright can race
    await page.evaluate(() => {
        const els = document.querySelectorAll('#cs-dialog-overlay, #cs-dialog-modal, .cs-modal-overlay, [id$="-overlay"]');
        els.forEach(el => { el.style.display = 'none'; el.style.visibility = 'hidden'; });
    }).catch(() => {});
    // Also wait for the overlay to truly be gone from the painted layer
    try { await page.locator('#cs-dialog-overlay').waitFor({ state: 'hidden', timeout: 1000 }); } catch { /* ok */ }
    await page.waitForTimeout(200);
}

async function scanAndTestButtons(page, label, failures, notices) {
    const allButtons = await page.locator('button, input[type="button"], input[type="submit"]').all();
    const destructive = [];
    const seenText = new Set(); // one representative per unique button text

    for (const btn of allButtons) {
        try {
            if (!await btn.isVisible()) continue;
            if (await btn.isDisabled()) continue;
            const text = (await btn.innerText().catch(() => '') || await btn.getAttribute('value') || '').trim();
            if (!DESTRUCTIVE_RE.test(text)) continue;
            if (shouldSkip(text)) continue;
            if (seenText.has(text)) continue; // skip duplicate rows
            seenText.add(text);
            destructive.push({ btn, text });
        } catch { /* stale — skip */ }
    }

    if (destructive.length === 0) return;

    const buttonNames = destructive.map(d => `"${d.text}"`).join(', ');
    console.log(`    [${label}] ${destructive.length} destructive button(s): ${buttonNames}`);

    for (const { btn, text } of destructive) {
        let dialogShown = false;
        let postFired   = false;
        let postUrl     = '';

        const dialogHandler = dialog => { dialogShown = true; dialog.dismiss().catch(() => {}); };
        const reqHandler    = req => { if (req.method() === 'POST') { postFired = true; postUrl = req.url(); } };

        page.once('dialog', dialogHandler);
        page.on('request', reqHandler);

        const modalBefore = await isModalVisible(page);

        console.log(`      clicking: "${text}"...`);
        try {
            await btn.click({ timeout: 3000, force: false });
        } catch (e) {
            console.log(`      skipped: "${text}" (not interactable: ${e.message.split('\n')[0]})`);
            page.off('dialog', dialogHandler);
            page.off('request', reqHandler);
            continue;
        }
        console.log(`      clicked: "${text}" — waiting...`);

        await page.waitForTimeout(400);

        const modalAfter = await isModalVisible(page);
        page.off('dialog', dialogHandler);
        page.off('request', reqHandler);

        const confirmed = dialogShown || (modalAfter && !modalBefore);

        if (postFired && !confirmed) {
            failures.push(`[${label}] "${text}" fired POST (${postUrl.split('?')[0]}) without confirmation`);
        } else if (!postFired && !confirmed) {
            notices.push(`[${label}] "${text}" — no POST, no confirmation (may be UI-only)`);
        } else {
            console.log(`      ✓ "${text}"`);
        }

        if (modalAfter && !modalBefore) await dismissModal(page);
    }
}

for (const { name, url, tabs } of PAGES) {
    test(`${name}: all destructive buttons require confirmation`, async ({ page }) => {
        test.setTimeout(name === 'Backup' ? 180000 : 60000);
        const failures = [];
        const notices  = [];

        await page.goto(url, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Scan the default/initial view
        await scanAndTestButtons(page, `${name}/default`, failures, notices);

        // Walk each tab
        for (const tab of tabs) {
            const tabBtn = page.locator(`.cs-tab[data-tab="${tab}"]`);
            try {
                if (!await tabBtn.isVisible({ timeout: 2000 })) continue;
                await tabBtn.click();
                await page.waitForTimeout(1200); // let tab content + any AJAX render
            } catch { continue; }

            // For the cloud tab: wait for history rows to render
            if (tab === 'cloud') {
                try { await page.waitForSelector('#cs-s3h-table tbody tr, #cs-gd-table tbody tr', { timeout: 5000 }); } catch { /* no rows */ }
                await page.waitForTimeout(300);
            }

            await scanAndTestButtons(page, `${name}/${tab}`, failures, notices);
        }

        if (notices.length) {
            console.log(`\n  ${name} — manual review needed:`);
            for (const n of notices) console.log(`    ⚠ ${n}`);
        }

        if (failures.length) {
            const msg = `${name}: ${failures.length} destructive button(s) fired without confirmation:\n` +
                failures.map(f => `  ✗ ${f}`).join('\n');
            expect.soft(false, msg).toBe(true);
        }
    });
}
