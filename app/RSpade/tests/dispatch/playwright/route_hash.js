#!/usr/bin/env node
/**
 * Rsx.Route() / Rsx_Portal.Route() hash argument - the JS twin of php/Route_Hash_Test.php.
 *
 *   a. PARITY: the JS URL is byte-identical to the PHP test's PARITY_URL literal (query,
 *      then the hash keys in order, then the `at` anchor; null/'' values dropped).
 *   b. ROUND TRIP: that URL, placed in the address bar, reads back through Rsx.url_hash_get()
 *      with every value unchanged - spaces, & = # / + and non-ASCII included.
 *   c. No hash / an all-empty hash adds no '#'; a placeholder route ignores the hash.
 *   d. The portal builder carries the same fragment with the portal base.
 *   e. Invalid input THROWS: a non-plain-object hash, an array/object/boolean/float value,
 *      an integer key, and the reserved anchor key inside the hash.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, and the
 * route tables are PROBE entries written into Rsx._routes / Rsx_Portal._routes in the page.
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so it runs
 * with a bare `node route_hash.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

// Must stay identical to Route_Hash_Test::PARITY_URL / PARITY_VALUE.
const PARITY_URL = "/test-dispatch/page?q=1#k=a%20b%26c%3Dd%23e%2F%C3%A9%2B~!*'()&n=42&at=sec%201";
const PARITY_VALUE = "a b&c=d#e/é+~!*'()";

function check(label, actual, expected) {
    if (actual === expected) {
        console.log('PASS: ' + label);
        return;
    }
    console.log('FAIL: route_hash - ' + label + ' - expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
    process.exitCode = 1;
}

async function run() {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    // Auth headers ONLY on the initial document request (per the SPA test harness contract).
    const auth_headers = { ...dev_auth_headers(ROUTE, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        if (!request.url().startsWith(BASE_URL)) {
            await route.continue();
            return;
        }
        const headers = { ...request.headers(), 'X-Playwright-Test': '1' };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        }
        await route.continue({ headers });
    });

    try {
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'load' });
        await page.waitForFunction(() => typeof Rsx_Portal !== 'undefined' && typeof Rsx_Portal.Route === 'function');

        const r = await page.evaluate(({ parity_value }) => {
            Rsx._routes.Route_Hash_Probe_Controller = { page: ['/test-dispatch/page'] };
            Rsx_Portal._routes.Route_Hash_Probe_Controller = { page: ['/test-dispatch/page'] };
            const target = 'Route_Hash_Probe_Controller::page';

            const parity = Rsx.Route(target, { q: 1, at: 'sec 1' }, { k: parity_value, n: 42, empty: '', gone: null, undef: undefined });

            // Round trip: put the URL in the address bar and read it back through the reader.
            const original = window.location.pathname + window.location.search + window.location.hash;
            history.replaceState(history.state, '', parity);
            const read_back = { k: Rsx.url_hash_get('k'), n: Rsx.url_hash_get('n'), at: Rsx.url_hash_get(Rsx.HASH_ANCHOR_KEY), empty: Rsx.url_hash_get('empty') };
            history.replaceState(history.state, '', original);

            const throws = (fn) => {
                try {
                    fn();
                } catch (e) {
                    return e.message;
                }
                return null;
            };

            return {
                parity: parity,
                read_back: read_back,
                none: Rsx.Route(target),
                all_empty: Rsx.Route(target, null, { a: null, b: '' }),
                anchor_only: Rsx.Route(target, { at: 'top' }, { tab: 'x' }),
                placeholder: Rsx.Route('Anything::#index', null, { tab: 'x' }),
                portal: Rsx_Portal.Route(target, { q: 1, at: 'sec 1' }, { k: parity_value, n: 42 }),
                portal_expected: Rsx_Portal._apply_portal_base(parity),
                e_string: throws(() => Rsx.Route(target, null, 'tab=x')),
                e_array: throws(() => Rsx.Route(target, null, ['x'])),
                e_value_array: throws(() => Rsx.Route(target, null, { tab: ['a'] })),
                e_value_object: throws(() => Rsx.Route(target, null, { tab: { a: 1 } })),
                e_value_bool: throws(() => Rsx.Route(target, null, { tab: true })),
                e_value_float: throws(() => Rsx.Route(target, null, { tab: 1.5 })),
                e_int_key: throws(() => Rsx.Route(target, null, { 5: 'x' })),
                e_anchor_key: throws(() => Rsx.Route(target, null, { at: 'x' })),
                e_portal: throws(() => Rsx_Portal.Route(target, null, { tab: {} })),
            };
        }, { parity_value: PARITY_VALUE });

        check('a. the JS URL is byte-identical to the PHP URL', r.parity, PARITY_URL);
        check('b. a value with spaces, & = # / + and non-ASCII reads back unchanged', r.read_back.k, PARITY_VALUE);
        check('b. an integer reads back as its decimal string', r.read_back.n, '42');
        check('b. the anchor reads back', r.read_back.at, 'sec 1');
        check('b. an empty value was dropped, not written', r.read_back.empty, null);
        check('c. no hash, no fragment', r.none, '/test-dispatch/page');
        check('c. an all-empty hash, no fragment', r.all_empty, '/test-dispatch/page');
        check('c. hash keys first, the anchor last', r.anchor_only, '/test-dispatch/page#tab=x&at=top');
        check('c. a placeholder route ignores the hash', r.placeholder, '#');
        check('d. the portal URL is the staff URL with the portal base', r.portal, r.portal_expected);
        check('e. a string hash throws', r.e_string !== null && r.e_string.includes('plain object'), true);
        check('e. an array hash throws', r.e_array !== null && r.e_array.includes('an array'), true);
        check('e. an array value throws', r.e_value_array !== null && r.e_value_array.includes("'tab' must be a string or an integer"), true);
        check('e. an object value throws', r.e_value_object !== null && r.e_value_object.includes('got object'), true);
        check('e. a boolean value throws', r.e_value_bool !== null && r.e_value_bool.includes('got boolean'), true);
        check('e. a float value throws', r.e_value_float !== null && r.e_value_float.includes('got number'), true);
        check('e. an integer key throws', r.e_int_key !== null && r.e_int_key.includes('non-integer'), true);
        check('e. the anchor key inside the hash throws', r.e_anchor_key !== null && r.e_anchor_key.includes('reserved anchor key'), true);
        check('e. the portal builder validates the same way', r.e_portal !== null && r.e_portal.includes('got object'), true);
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: route_hash - ' + e.message);
    process.exitCode = 1;
});
