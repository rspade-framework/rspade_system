#!/usr/bin/env node
/**
 * Rsx.Route() URL generation from a route pattern's :tokens - the JS twin of
 * php/Route_Url_Tokens_Test.php, pinned against the same cases.
 *
 *   a. A token is replaced whole and by name: ':id' never corrupts ':id_two'.
 *   b. An optional ':x?' is filled when given and dropped together with its slash when not.
 *   c. Only REQUIRED tokens are demanded.
 *   d. Selection prefers the satisfiable pattern that fills the most tokens.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, and
 * the subject is Core/Js/Rsx.js, called in the page. Self-contained: mints its own dev-auth
 * headers through system/bin/dev-auth.js, so it runs with a bare `node route_url_tokens.js`.
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
    console.log('FAIL: route_url_tokens - ' + label + ' - expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
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
        await page.waitForFunction(() => typeof Rsx !== 'undefined' && typeof Rsx._generate_url_from_pattern === 'function');

        const r = await page.evaluate(() => {
            const gen = (p, a) => Rsx._generate_url_from_pattern(p, a);
            let missing = null;
            try {
                gen('/x/:id/:page?', {});
            } catch (e) {
                missing = e.message;
            }
            return {
                a1: gen('/x/:id/:id_two', { id: 1, id_two: 2 }),
                a2: gen('/x/:id_two/:id', { id: 1, id_two: 2 }),
                b1: gen('/list/:page?', { page: 3 }),
                b2: gen('/list/:page?', {}),
                b3: gen('/list/:page?', { page: null }),
                b4: gen('/a/:id/b/:tab?', { id: 5 }),
                c: missing,
                d1: Rsx._select_best_route_pattern(['/list', '/list/:page?', '/list/:page/:id'], { page: 2 }),
                d2: Rsx._select_best_route_pattern(['/list', '/list/:page?', '/list/:page/:id'], { page: 2, id: 7 }),
            };
        });

        check('a. :id and :id_two are replaced by name', r.a1, '/x/1/2');
        check('a. order does not matter', r.a2, '/x/2/1');
        check('b. optional token filled', r.b1, '/list/3');
        check('b. optional token dropped with its slash', r.b2, '/list');
        check('b. a null optional value is absent', r.b3, '/list');
        check('b. optional token after a literal segment', r.b4, '/a/5/b');
        check('c. only the required token is demanded', r.c !== null && r.c.includes('[id]'), true);
        check('d. one token fills the optional pattern', r.d1, '/list/:page?');
        check('d. two tokens fill the two-token pattern', r.d2, '/list/:page/:id');
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: route_url_tokens - ' + e.message);
    process.exitCode = 1;
});
