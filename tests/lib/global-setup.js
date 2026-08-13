/**
 * CloudScale shared Playwright global setup.
 *
 * Mints ONE Test Account Manager session and persists it as auth.json, which
 * playwright.config.js hands to every test via `use.storageState`. Specs
 * therefore start already logged in — no login form, no 2FA, no hidden-login
 * slug, and no per-spec session boilerplate.
 *
 * This setup deliberately does NOT touch WordPress state. An earlier version
 * SSHed to the production Pi and deleted user meta via wp-cli; test setup must
 * never mutate a live site (and must never alter 2FA/login-security options).
 */
const path = require('path');
const { writeStorageState, SITE, ROLE } = require('./test-session');

module.exports = async () => {
    const out = path.resolve(process.cwd(), 'auth.json');
    console.log(`[setup] Test Account Manager session → ${SITE} (role: ${ROLE})`);
    await writeStorageState(out, { ttl: 3600 });
    console.log(`[setup] auth.json written. Tests start authenticated.`);
};
