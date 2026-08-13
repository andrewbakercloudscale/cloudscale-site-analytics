#!/usr/bin/env node
/**
 * host-proxy.js — point the whole test run at one host without touching DNS.
 *
 * WHY THIS EXISTS
 * ---------------
 * WP_HOME is pinned to the canonical domain, so a restored or QA copy 301s any other
 * hostname straight to it. Open the QA hostname in a browser and you are looking at
 * PRODUCTION while believing you are testing a copy — which is how a review of a "QA instance"
 * ended up reporting three services broken.
 *
 * Tests therefore have to keep using the production hostname and have it land somewhere else.
 * Chromium can do that alone (--host-resolver-rules), but the specs mint their session from Node
 * first, and Node resolves through real DNS — so half the run would hit production and half the
 * copy, which is worse than either.
 *
 * A CONNECT proxy fixes both at once: every client speaks to the canonical hostname as usual, and
 * this forwards the tunnel to the chosen IP. TLS is end-to-end through the tunnel, so the copy's
 * self-signed certificate is what the client sees (hence ignoreHTTPSErrors in the config), and
 * nothing has to know it is being redirected.
 *
 * Deliberately refuses to start without a target: defaulting to anything would risk pointing a
 * write-capable admin test run at production.
 *
 * Only the hostnames under test are diverted. Sending EVERYTHING to the target was the first
 * version, and it quietly broke every page that loads a third-party asset: the request went to the
 * copy, which has no such vhost, and the browser reported net::ERR_FAILED. Three site-health specs
 * failed on that and read as CSP or JS faults on the site. Other hosts are now dialled normally.
 *
 * Usage:
 *   node tests/lib/host-proxy.js --target 1.2.3.4 [--port 8899] [--hosts a.com,b.com]
 *   HTTPS_PROXY=http://127.0.0.1:8899 npx playwright test …
 *
 * --hosts defaults to the host of WP_SITE, plus its www. form.
 */
'use strict';

const net = require('net');
const http = require('http');

const args = process.argv.slice(2);
function arg(name, fallback) {
    const i = args.indexOf(name);
    return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
}

const TARGET = arg('--target', '');
const PORT = parseInt(arg('--port', '8899'), 10);
const TARGET_PORT = parseInt(arg('--target-port', '443'), 10);

function defaultHosts() {
    const site = process.env.WP_SITE || '';
    let host = '';
    try { host = new URL(site).hostname; } catch { /* not set */ }
    if (!host) return [];
    return host.startsWith('www.') ? [host, host.slice(4)] : [host, 'www.' + host];
}
const HOSTS = (arg('--hosts', '') ? arg('--hosts', '').split(',') : defaultHosts())
    .map(h => h.trim().toLowerCase()).filter(Boolean);

if (!HOSTS.length) {
    console.error('host-proxy: no hostnames to divert. Pass --hosts a.com,b.com or set WP_SITE,');
    console.error('            otherwise nothing would reach the target and the run would silently');
    console.error('            test production instead.');
    process.exit(2);
}

if (!/^\d+\.\d+\.\d+\.\d+$/.test(TARGET)) {
    console.error('host-proxy: --target <ip> is required. Refusing to start without one, because');
    console.error('            a default would risk sending a write-capable test run at production.');
    process.exit(2);
}

const server = http.createServer((req, res) => {
    // Plain HTTP is not proxied: everything here is https, and silently allowing http would
    // let a request bypass the tunnel and reach production. Logged rather than dropped quietly —
    // a page that asks for an http:// URL is worth knowing about, and an unexplained failure in
    // the run should never trace back to a refusal this proxy never mentioned.
    console.error('host-proxy: REFUSED plain HTTP ' + req.method + ' ' + req.url);
    res.writeHead(501, { 'Content-Type': 'text/plain' });
    res.end('host-proxy only handles CONNECT (https). Request was: ' + req.method + ' ' + req.url + '\n');
});

let tunnels = 0;
let passthru = 0;
server.on('connect', (req, clientSocket, head) => {
    // req.url is "host:port".
    const [reqHost, reqPortRaw] = String(req.url).split(':');
    const host = (reqHost || '').toLowerCase();
    const divert = HOSTS.includes(host);
    const dialHost = divert ? TARGET : host;
    const dialPort = divert ? TARGET_PORT : parseInt(reqPortRaw || '443', 10);

    const upstream = net.connect(dialPort, dialHost, () => {
        if (divert) { ++tunnels; } else { ++passthru; }
        // Every CONNECT is forwarded to TARGET:443 whatever port was asked for, so a page that
        // requests a non-443 port silently gets the TLS listener and fails in a way that looks
        // like the server's fault. Say so.
        if (divert && !/:443$/.test(req.url)) {
            console.error(`host-proxy: NOTE ${req.url} forwarded to ${TARGET}:${TARGET_PORT} (port rewritten)`);
        }
        clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head && head.length) upstream.write(head);
        upstream.pipe(clientSocket);
        clientSocket.pipe(upstream);
    });
    const bail = (what) => (err) => {
        // A reset mid-run is normal when a browser context closes; do not spam.
        if (err && !['ECONNRESET', 'EPIPE'].includes(err.code)) {
            console.error(`host-proxy: ${what} error for ${req.url}: ${err.message}`);
        }
        clientSocket.destroy();
        upstream.destroy();
    };
    upstream.on('error', bail('upstream'));
    clientSocket.on('error', bail('client'));
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`host-proxy: 127.0.0.1:${PORT} → ${TARGET}:${TARGET_PORT} (CONNECT only)`);
    console.log(`host-proxy: diverting ${HOSTS.join(', ')}; every other host is dialled normally`);
    console.log('host-proxy: export HTTPS_PROXY=http://127.0.0.1:' + PORT);
});

for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, () => {
        console.log(`\nhost-proxy: ${tunnels} diverted, ${passthru} passed through, shutting down`);
        server.close(() => process.exit(0));
    });
}
