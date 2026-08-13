/**
 * CloudScale shared Playwright auth helper — Test Account Manager sessions.
 *
 * SINGLE SOURCE OF TRUTH. Do not copy this logic into a spec: every spec that
 * needs an authenticated browser or API context requires this module instead.
 * (Before this existed, ~60 spec files each carried their own inline
 * `getAdminSession()`; they drifted and broke independently.)
 *
 * The Test Account Manager (Cyber DevTools → Login tab) issues short-lived
 * server-side WP session cookies for a persistent test user, so Playwright never
 * touches the login form and never needs 2FA or the hidden-login slug.
 *
 * IMPORTANT — role policy (CSDT_Test_Accounts::ALLOWED_ROLES):
 *   Permitted WP roles are 'author', 'contributor', 'subscriber' and the scoped
 *   'csdt_test_settings'. Administrator and editor are refused by design, because
 *   this endpoint bypasses login+2FA and the blast radius of a leaked secret must
 *   stay small.
 *
 *   Plugin SETTINGS pages gate on `manage_options`, so author/contributor/
 *   subscriber sessions get HTTP 403 there (licence-card and settings specs
 *   cannot pass with those roles). For those, create the test user with the
 *   "Settings-only" role in the Test Account Manager: it grants exactly
 *   `read` + `manage_options` — no user management, no plugin/theme install or
 *   edit, no file editing — and is hard-blocked from writing any
 *   csdt_devtools_2fa_* / _login_* option, so it can never weaken 2FA or the
 *   hidden login page.
 *
 *   NOTE: a test account created before role tracking existed has an empty
 *   wp_role and mints as SUBSCRIBER (the UI used to mislabel that as
 *   "Administrator"). If settings specs 403, that is why — create a new
 *   Settings-only account and point CSDT_TEST_ROLE at it.
 *
 * CREDENTIAL — a passkey-minted CI token, not a shared secret.
 *   The endpoint used to accept a long random string from a .env file: no expiry, and a copy
 *   on every machine that had ever run the suite. It now takes a CSDT_CI_TOKEN, minted by an
 *   administrator completing a WebAuthn ceremony in the Test Account Manager ("Authorise a
 *   device"). It lasts a year, is revocable from that panel, and dies on its own if the
 *   passkey that minted it is removed or the issuing administrator is demoted.
 *
 *   Sent as the X-CSDT-CI-Token header rather than in the body, so it stays out of request
 *   logs. CSDT_TEST_SECRET is still read, and still works on a site where no device has been
 *   authorised yet — once one has, that site refuses it and says so.
 *
 * Required env (see each plugin's .env.test / .env.cloudscale):
 *   WP_SITE                or WP_BASE_URL   — e.g. https://andrewbaker.ninja
 *   CSDT_CI_TOKEN          — passkey-minted token (csdtci_…); preferred
 *   CSDT_TEST_SECRET       — legacy shared secret; only for a site with no authorised device
 *   CSDT_TEST_ROLE         — test role name (the "Name" column, not the WP role)
 *   CSDT_TEST_SESSION_URL  — full REST URL incl. path token
 *   CSDT_TEST_LOGOUT_URL   — optional, used by killSession()
 *
 * CSDT_CI_TOKEN may also be left unset and stored in the macOS login keychain instead — see
 * scripts/ci-token-keychain.sh. If the environment carries no token, this file looks there on
 * its own, keyed by WP_SITE's hostname. A no-op on Linux CI (no Keychain, and a runner that
 * sets the env var explicitly never reaches the lookup).
 */
const { request: pwRequest } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

/**
 * Load .env.test / tests/.env into process.env if the vars aren't already set.
 * Without this, globalSetup (which runs before any spec's own dotenv call) has
 * no credentials and auth.json silently fails unless the caller exported them
 * in the shell. Existing env always wins, so CI overrides still work.
 * Minimal parser — avoids adding a dotenv dependency to three plugins.
 */
function loadEnvFiles() {
    const here  = __dirname;                       // <plugin>/tests/lib
    const tests = path.resolve(here, '..');        // <plugin>/tests
    const plug  = path.resolve(tests, '..');       // <plugin>
    const candidates = [
        path.join(plug,  '.env.test'),
        path.join(tests, '.env'),
        path.join(plug,  '.env'),
    ];
    for (const file of candidates) {
        let raw;
        try { raw = fs.readFileSync(file, 'utf8'); } catch { continue; }
        for (const line of raw.split(/\r?\n/)) {
            const m = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/.exec(line);
            if (!m) continue;
            let v = m[2].trim().replace(/\s+#.*$/, '');
            if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
                v = v.slice(1, -1);
            }
            if (process.env[m[1]] === undefined || process.env[m[1]] === '') {
                process.env[m[1]] = v;
            }
        }
    }
    // Accept either historical naming scheme.
    process.env.CSDT_TEST_SECRET      = process.env.CSDT_TEST_SECRET      || process.env.CSDT_CS_SECRET      || '';
    process.env.CSDT_TEST_ROLE        = process.env.CSDT_TEST_ROLE        || process.env.CSDT_CS_ROLE        || '';
    process.env.CSDT_TEST_SESSION_URL = process.env.CSDT_TEST_SESSION_URL || process.env.CSDT_CS_SESSION_URL || '';
    process.env.CSDT_TEST_LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || process.env.CSDT_CS_LOGOUT_URL  || '';
    process.env.CSDT_CI_TOKEN         = process.env.CSDT_CI_TOKEN         || process.env.CSDT_TEST_CI_TOKEN   || '';
    process.env.WP_SITE               = process.env.WP_SITE || process.env.WP_BASE_URL || process.env.CS_WP_SITE || '';

    if (!process.env.CSDT_CI_TOKEN) {
        process.env.CSDT_CI_TOKEN = readCiTokenFromKeychain(process.env.WP_SITE);
    }
}

/**
 * The CI token for `site`, from the macOS login keychain, or '' if there is none (including on
 * any non-macOS platform, where 'security' does not exist). Best-effort by design: a CI runner
 * that has no Keychain and no CSDT_CI_TOKEN should fall through to CSDT_TEST_SECRET exactly as
 * it did before this existed, not fail on a missing binary.
 *
 * execFileSync rather than exec/execSync with a template string — the account name below comes
 * from a URL hostname (site config, not request input), but there is no reason to build a
 * shell command out of it when passing it as an argv element is just as easy and closes off
 * shell-injection as a category rather than trusting the input to stay clean.
 */
function readCiTokenFromKeychain(site) {
    if (process.platform !== 'darwin') { return ''; }
    let account = 'default';
    try {
        if (site) { account = new URL(site).hostname; }
    } catch { /* keep 'default' — matches scripts/ci-token-keychain.sh's own fallback */ }
    try {
        const { execFileSync } = require('child_process');
        return execFileSync(
            'security',
            [ 'find-generic-password', '-s', 'csdt-ci-token', '-a', account, '-w' ],
            { stdio: [ 'ignore', 'pipe', 'ignore' ] }
        ).toString().trim();
    } catch {
        return ''; // No item for this account — not an error, just nothing stored yet.
    }
}

loadEnvFiles();

const SITE        = process.env.WP_SITE || process.env.WP_BASE_URL || '';
const SECRET      = process.env.CSDT_TEST_SECRET || '';
const CI_TOKEN    = process.env.CSDT_CI_TOKEN || '';
const ROLE        = process.env.CSDT_TEST_ROLE || '';

/**
 * Optional network override so a run can be aimed at a specific host.
 *
 * WP_HOME pins the canonical domain, so a restored or QA copy redirects any other hostname to
 * production — tests must keep using the production URL and have it land elsewhere. Chromium can
 * be redirected on its own, but these session mints happen in NODE, which resolves through real
 * DNS; without this the browser would talk to the copy while the login talked to production.
 *
 * Set CSDT_TEST_PROXY to a CONNECT proxy (see tests/lib/host-proxy.js). ignoreHTTPSErrors rides
 * along with it because a copy presents a self-signed certificate.
 */
const TEST_PROXY = process.env.CSDT_TEST_PROXY || '';
function netOpts() {
    return TEST_PROXY
        ? { proxy: { server: TEST_PROXY }, ignoreHTTPSErrors: true }
        : {};
}

const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL || '';

function assertEnv() {
    const missing = [];
    if (!SITE)        missing.push('WP_SITE (or WP_BASE_URL)');
    if (!ROLE)        missing.push('CSDT_TEST_ROLE');
    if (!SESSION_URL) missing.push('CSDT_TEST_SESSION_URL');
    // Either credential satisfies this. Naming both in the message matters: a run that fails
    // for want of a credential should not send someone hunting for the one the site has
    // already retired.
    if (!CI_TOKEN && !SECRET) missing.push('CSDT_CI_TOKEN (or the legacy CSDT_TEST_SECRET)');
    if (missing.length) {
        throw new Error(
            'Test Account Manager env missing: ' + missing.join(', ') +
            '\nMint a token in Cyber DevTools -> Protect -> Test Account Manager -> "Authorise a device",' +
            '\nor populate .env.test.'
        );
    }
}

/**
 * How a request carries its credential.
 *
 * The token goes in a header and the legacy secret in the body, because that is where each
 * belongs — and sending both when both are present is deliberate: it lets one .env work
 * against a site that has authorised a device and one that has not, without the caller
 * having to know which is which. The server prefers the token and refuses the secret once a
 * device exists.
 */
function credentialOpts(data) {
    const opts = { data: { ...data } };
    if (SECRET)   opts.data.secret = SECRET;
    if (CI_TOKEN) opts.headers = { 'X-CSDT-CI-Token': CI_TOKEN };
    return opts;
}

/**
 * Mint a session from the Test Account Manager.
 *
 * @param {number} ttl  Session lifetime in seconds (default 900).
 * @param {string} role Test-account name to mint, overriding CSDT_TEST_ROLE.
 *                      Needed because CSDT_TEST_ROLE names the default
 *                      (subscriber) account, and any spec that opens a plugin
 *                      settings/tools page needs the Settings-only account
 *                      instead — see the role policy above. Specs used to
 *                      hand-roll their own mint to get at this, which is how
 *                      ~60 copies of this function came to exist.
 * @returns {Promise<object>} raw session payload (cookie names/values/domain/expiry)
 */
async function getSession(ttl = 900, role = ROLE) {
    assertEnv();
    const ctx  = await pwRequest.newContext(netOpts());
    let resp, body;
    try {
        // POST-only endpoint: a GET returns 404, which reads like a bad token.
        resp = await ctx.post(SESSION_URL, credentialOpts({ role, ttl }));
        body = await resp.json().catch(() => ({}));
    } finally {
        await ctx.dispose();
    }

    if (!resp.ok()) {
        const err = (body && body.error) ? body.error : JSON.stringify(body);
        // Translate the two failures that actually happen in practice into
        // actionable messages instead of a bare status code.
        if (resp.status() === 403 && /privileged WordPress role/i.test(err)) {
            throw new Error(
                'Test Account Manager refused the role "' + role + '": it was created as ' +
                'Administrator/Editor by an older plugin version and is no longer honoured.\n' +
                'FIX: delete that test user in Cyber DevTools -> Login -> Test Account Manager ' +
                'and re-create it as Author (or Contributor/Subscriber).'
            );
        }
        // The endpoint says these in plain words; surfacing them beats a bare 401.
        if (resp.status() === 401 && /passkey-minted CI token/i.test(err)) {
            throw new Error(
                'This site has retired the shared secret. Mint a token in Cyber DevTools -> Protect ->\n' +
                'Test Account Manager -> "Authorise a device", and put it in .env.test as CSDT_CI_TOKEN.'
            );
        }
        if (resp.status() === 401 && /(expired|no longer registered|no longer has access)/i.test(err)) {
            throw new Error(
                'The CI token is no longer valid: ' + err + '\n' +
                'Authorise the device again in the Test Account Manager.'
            );
        }
        if (resp.status() === 404) {
            throw new Error(
                'Test-session endpoint returned 404. Either CSDT_TEST_SESSION_URL has a stale ' +
                'path token (regenerate it in the Test Account Manager) or the request was not a POST.'
            );
        }
        throw new Error('test-session API ' + resp.status() + ': ' + err);
    }
    if (!body || !body.logged_in_cookie) {
        throw new Error('test-session API returned no cookies: ' + JSON.stringify(body));
    }
    return body;
}

/** Cookie array for context.addCookies() / storageState. */
function cookiesFrom(sess) {
    const secure = /^https:/i.test(SITE);
    const expires = sess.expires_at ? Number(sess.expires_at) : undefined;
    const base = { domain: sess.cookie_domain, path: '/', secure, sameSite: 'Lax' };
    const list = [
        { ...base, name: sess.logged_in_cookie_name, value: sess.logged_in_cookie, httpOnly: false },
    ];
    if (sess.secure_auth_cookie_name && sess.secure_auth_cookie) {
        list.push({ ...base, name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie, httpOnly: true });
    }
    if (expires) list.forEach(c => { c.expires = expires; });
    return list;
}

/**
 * Browser context that is already logged in as the test user.
 * @param {import('@playwright/test').Browser} browser
 */
async function newAuthedContext(browser, { ttl = 900, role = ROLE, ...contextOptions } = {}) {
    const sess = await getSession(ttl, role);
    const ctx  = await browser.newContext({ ...netOpts(), ...contextOptions });
    await ctx.addCookies(cookiesFrom(sess));
    return ctx;
}

/** Authenticated page (convenience wrapper around newAuthedContext). */
async function newAuthedPage(browser, opts = {}) {
    const ctx = await newAuthedContext(browser, opts);
    const page = await ctx.newPage();
    page.on('close', () => ctx.close().catch(() => {}));
    return page;
}

/** Authenticated APIRequestContext, for REST / admin-ajax calls. */
async function newAuthedRequest({ ttl = 900, role = ROLE } = {}) {
    const sess = await getSession(ttl, role);
    const ctx  = await pwRequest.newContext({ baseURL: SITE, ...netOpts() });
    await ctx.addCookies(cookiesFrom(sess));
    return ctx;
}

/** Write storageState for playwright.config.js `use.storageState`. */
async function writeStorageState(path = 'auth.json', { ttl = 3600, role = ROLE } = {}) {
    const { chromium } = require('@playwright/test');
    const sess    = await getSession(ttl, role);
    const browser = await chromium.launch();
    const ctx     = await browser.newContext(netOpts());
    await ctx.addCookies(cookiesFrom(sess));
    await ctx.storageState({ path });
    await browser.close();
    return path;
}

/** Best-effort session teardown (leaves the persistent user in place). */
async function killSession() {
    if (!LOGOUT_URL || (!SECRET && !CI_TOKEN)) return;
    const ctx = await pwRequest.newContext(netOpts());
    try { await ctx.post(LOGOUT_URL, credentialOpts({ role: ROLE })); }
    catch { /* teardown is best-effort */ }
    finally { await ctx.dispose(); }
}

module.exports = {
    SITE, ROLE,
    getSession, cookiesFrom,
    newAuthedContext, newAuthedPage, newAuthedRequest,
    writeStorageState, killSession,
};
