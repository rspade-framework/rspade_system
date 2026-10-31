/**
 * sys_page - open the framework-served /_sys page in headless Chromium, signed in.
 *
 * A browser test cannot see the test tree: it drives the ordinary web server, so it runs on a
 * page the framework itself serves and builds whatever else it needs in the browser at run time.
 * This is the opening every such script shares - launch, the dev-auth headers on the first
 * document request only (the SPA harness contract), the X-Playwright-Test marker on every
 * same-origin request, and a wait for the SPA to boot.
 *
 *   const { open_sys_page } = require('../../_lib/playwright/sys_page.js');
 *   const { browser, page, errors, on_request } = await open_sys_page();
 *   await open_sys_page({ timezone_id: 'America/Chicago' });   // a browser in that zone
 *
 * errors      every pageerror and console.error seen so far (jqhtml reports a failed lifecycle
 *             hook through console.error, not as an uncaught exception). A script that provokes
 *             failures on purpose filters the messages it expects.
 * on_request  (fn) registers ONE interceptor consulted for every same-origin request before it
 *             continues: fn(route, request) returns true when it has answered the route itself
 *             (abort, fulfill, or a continue it performs later), false to let it through.
 *
 * Nothing here has a deadline: the SPA wait ends when the SPA is up.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

async function open_sys_page(options = {}) {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        ...(options.timezone_id ? { timezoneId: options.timezone_id } : {}),
    });
    const page = await context.newPage();

    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });

    let interceptor = null;
    const on_request = (fn) => { interceptor = fn; };

    const auth_headers = {
        ...dev_auth_headers(ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        if (!request.url().startsWith(BASE_URL)) {
            await route.continue();
            return;
        }
        if (interceptor && await interceptor(route, request)) {
            return;
        }
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
    // Spa.action() throws until the SPA has booted, so the probe swallows that as "not yet".
    await page.waitForFunction(() => {
        try {
            return typeof Spa !== 'undefined' && !!Spa.action();
        } catch (e) {
            return false;
        }
    }, null, { timeout: 0 });

    return { browser, page, errors, on_request };
}

module.exports = { open_sys_page, BASE_URL };
