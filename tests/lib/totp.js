'use strict';
/**
 * totp.js — generate a TOTP code from a Base32 secret (RFC 6238).
 *
 * WHY THIS EXISTS
 * ---------------
 * The TOTP setup wizard test used to print the secret and wait on stdin for a human to read a
 * code out of their authenticator app. Unattended — which is every CI run and every run against
 * a QA copy — that could only ever end in a 60-second timeout reported as a failure, so the one
 * test covering the site's actual second factor never checked anything.
 *
 * Six digits from an HMAC is not worth a dependency, and pulling one in for a security test is a
 * poor trade. The RFC's own test vector is asserted at load, because a subtly wrong code
 * generator would look exactly like a broken wizard.
 */

const crypto = require('crypto');

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(input) {
    const clean = String(input).toUpperCase().replace(/=+$/, '').replace(/\s+/g, '');
    let bits = 0;
    let value = 0;
    const out = [];
    for (const ch of clean) {
        const idx = ALPHABET.indexOf(ch);
        if (idx < 0) throw new Error(`totp: not a Base32 character: ${ch}`);
        value = (value << 5) | idx;
        bits += 5;
        if (bits >= 8) {
            out.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }
    if (!out.length) throw new Error('totp: empty secret');
    return Buffer.from(out);
}

/**
 * @param {string} secret Base32 secret as shown by the setup wizard.
 * @param {object} [opts] atMs: milliseconds since epoch (default now); step: seconds; digits.
 * @returns {string} zero-padded code
 */
function totp(secret, opts = {}) {
    const step   = opts.step || 30;
    const digits = opts.digits || 6;
    const atMs   = opts.atMs === undefined ? Date.now() : opts.atMs;

    const counter = Math.floor(atMs / 1000 / step);
    const buf     = Buffer.alloc(8);
    buf.writeUInt32BE(Math.floor(counter / 2 ** 32), 0);
    buf.writeUInt32BE(counter >>> 0, 4);

    const hmac   = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code   = ((hmac[offset] & 0x7f) << 24 | hmac[offset + 1] << 16
                    | hmac[offset + 2] << 8 | hmac[offset + 3]) % 10 ** digits;
    return String(code).padStart(digits, '0');
}

/**
 * Seconds left in the current step. The wizard rejects a code that expires mid-submit, so a test
 * that lands on the last second of a window fails for a reason that has nothing to do with the
 * code being right.
 */
function secondsRemaining(step = 30, atMs = Date.now()) {
    return step - Math.floor(atMs / 1000) % step;
}

// RFC 6238 appendix B, SHA-1: secret "12345678901234567890", T = 59 -> 94287082.
// The RFC's own published test key ("12345678901234567890" in Base32), not a credential.
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // pragma:allow-secret
const rfc = totp(RFC_SECRET, { atMs: 59 * 1000, digits: 8 });
if (rfc !== '94287082') {
    throw new Error(`totp: self-test failed (RFC 6238 vector produced ${rfc}, expected 94287082)`);
}

module.exports = { totp, secondsRemaining, base32Decode };
