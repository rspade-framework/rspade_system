#!/usr/bin/env node
/**
 * Rsx_Portal.Route() builds its URL with Rsx.Route()'s own routines - the JS twin of
 * php/Portal_Route_Parity_Test.php.
 *
 *   a. A portal URL is the staff URL for the same pattern and params with the portal base
 *      applied: whole-token replacement, the query string and the #at= anchor.
 *   b. The reserved `at` key rides the hash on the portal too (rsx:man anchors).
 *   c. A non-string action is refused, as Rsx.Route() refuses it.
 *   d. A portal SPA action's patterns are found by name and selected by Rsx's selector.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys (every
 * bundle carries Core/Js, so Rsx_Portal is present), and the route tables the test reads
 * are PROBE entries it writes into Rsx._routes / Rsx_Portal._routes in the page, plus a
 * probe portal SPA class it registers with Manifest. Self-contained: mints its own dev-auth
 * headers through system/bin/dev-auth.js, so it runs with a bare `node portal_route_parity.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function check(label, actual, expected) {
    if (actual === expected) {
        console.log('PASS: ' + label);
        return;
    }
    console.log('FAIL: portal_route_parity - ' + label + ' - expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
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

        const r = await page.evaluate(() => {
            const pattern = '/test-route-parity/:id/:id_type';
            Rsx._routes.Route_Parity_Probe_Controller = { item: [pattern] };
            Rsx_Portal._routes.Route_Parity_Probe_Controller = { item: [pattern] };

            const params = { id: 5, id_type: 7, at: 'a b', x: 1 };
            const staff = Rsx.Route('Route_Parity_Probe_Controller::item', params);
            const portal = Rsx_Portal.Route('Route_Parity_Probe_Controller::item', params);

            let refused = null;
            try {
                Rsx_Portal.Route(42);
            } catch (e) {
                refused = e.message;
            }

            class Route_Parity_Probe_Action extends Spa_Action {}
            Route_Parity_Probe_Action._spa_routes = ['/test-route-parity-spa', '/test-route-parity-spa/:id'];
            Route_Parity_Probe_Action._is_portal_spa = true;
            Manifest._classes.Route_Parity_Probe_Action = { class: Route_Parity_Probe_Action };

            return {
                staff: staff,
                portal: portal,
                expected_portal: Rsx_Portal._apply_portal_base(staff),
                refused: refused,
                spa: Rsx_Portal.Route('Route_Parity_Probe_Action', { id: 3, at: 'top' }),
                spa_expected: Rsx_Portal._apply_portal_base('/test-route-parity-spa/3#at=top'),
            };
        });

        check('a. the staff URL replaces tokens whole and anchors last', r.staff, '/test-route-parity/5/7?x=1#at=a%20b');
        check('a. the portal URL is the staff URL with the portal base', r.portal, r.expected_portal);
        check('b. the portal URL carries #at=, not ?at=', r.portal.endsWith('?x=1#at=a%20b'), true);
        check('c. a non-string action is refused', r.refused !== null && r.refused.includes('must be a string'), true);
        check('d. a portal SPA action resolves by name through Rsx\'s selector', r.spa, r.spa_expected);
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: portal_route_parity - ' + e.message);
    process.exitCode = 1;
});
