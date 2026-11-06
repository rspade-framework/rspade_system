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
 *             failures on purpose filters the messages it expects. Chromium's own report of a
 *             full document load in headless mode ("Transition was aborted because of invalid
 *             state", the view-transition opt-in being off) is not collected: every full load
 *             logs it, a raw location write included, so it says nothing about the page.
 * on_request  (fn) registers ONE interceptor consulted for every same-origin request before it
 *             continues: fn(route, request) returns true when it has answered the route itself
 *             (abort, fulfill, or a continue it performs later), false to let it through.
 *             An interceptor that must see the page's own first load is passed as
 *             open_sys_page({ on_request: fn }) instead, which installs it before navigating.
 *             fn(route, request, headers) then also receives the headers to continue with.
 *
 * Opening the page has no deadline: the SPA wait ends when the SPA is up.
 *
 *   const { poll_until } = require('../../_lib/playwright/sys_page.js');
 *   await poll_until(page, 'the tasks page is dispatched', () => Spa.action() instanceof _Sys_Tasks_Action);
 *
 * poll_until  polls a condition across document loads - each probe is its own evaluate, and a
 *             probe whose document was replaced under it ("Execution context was destroyed")
 *             or whose globals are not up yet counts as "not yet". Use it after anything that
 *             can load a document: page.waitForFunction() dies with the document it runs in.
 *             Bounded at the house 120 s (tests/CLAUDE.md, the contention principle); reaching
 *             the bound throws naming the condition, never the elapsed time.
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
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !is_chromium_load_noise(msg.text())) {
            errors.push(msg.text());
        }
    });

    let interceptor = options.on_request || null;
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
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        if (interceptor && await interceptor(route, request, headers)) {
            return;
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

/** Chromium's console error on every full document load in headless mode; see `errors` above. */
function is_chromium_load_noise(text) {
    return text.includes('Transition was aborted because of invalid state');
}

/**
 * The house bound for a wait on a browser condition (tests/CLAUDE.md, the contention
 * principle). A bounded wait on a party the test does not control is the sanctioned test
 * exception to the no-timeout mandate: only a genuinely stuck condition reaches 120 s.
 */
const POLL_BOUND_MS = 120000;

/** Interval between probes: how often the condition is asked, never how long anything takes. */
const POLL_INTERVAL_MS = 100;

/**
 * Poll `fn` (evaluated in the page with `arg`) until it returns a truthy value, and return
 * that value. See the header for when to use it.
 */
async function poll_until(page, what, fn, arg) {
    const deadline = Date.now() + POLL_BOUND_MS;
    let last_error = null;
    for (;;) {
        try {
            const value = await page.evaluate(fn, arg);
            if (value) {
                return value;
            }
            last_error = null;
        } catch (e) {
            // The document was replaced mid-probe, or the page's globals are not up yet.
            last_error = e;
        }
        if (Date.now() >= deadline) {
            throw new Error('never reached: ' + what + (last_error ? ' (last probe: ' + last_error.message.split('\n')[0] + ')' : ''));
        }
        await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
    }
}

module.exports = { open_sys_page, poll_until, is_chromium_load_noise, BASE_URL };
