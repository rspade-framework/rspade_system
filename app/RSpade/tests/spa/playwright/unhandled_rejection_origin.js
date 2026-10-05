#!/usr/bin/env node
/**
 * An unhandled promise rejection is judged by whether it implicates this page's code
 * (SPA-REJ-01..05).
 *
 * Third-party code in the page - browser-extension content scripts above all - rejects its own
 * promises with plain objects. The framework's unhandledrejection listener used to stringify any
 * non-Error reason into "Error: [object Object]", show it to the user as a fatal error and
 * disable SPA navigation for the rest of the page's life, on a page that was working.
 *
 *   1. A plain-object rejection is still REPORTED (unhandled_exception fires, so the server log
 *      gets it) but its message carries the value as JSON - never "[object Object]" - it is
 *      marked implicated:false, and SPA navigation stays enabled.
 *   2. Spa.dispatch() afterwards still navigates CLIENT-SIDE.
 *   3. A rejection whose stack names an extension script, and Chrome's extension-messaging
 *      failure, are ignored outright: no unhandled_exception at all.
 *   4. A rejection whose Error stack names this page's compiled bundle IS implicated: reported,
 *      and SPA navigation is disabled exactly as before.
 *
 * Runs against the framework's own /_sys panel; self-contained (mints its own dev-auth
 * headers), so a bare `node unhandled_rejection_origin.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const OTHER_ROUTE = '/_sys/tasks';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: unhandled rejection origin - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    // Rsx is a top-level class binding - global, but not a window property - so the
    // existence question can only be asked with typeof.
    await page.waitForFunction(() => typeof Rsx !== 'undefined');

    await page.evaluate(() => new Promise((resolve) => {
        Rsx.on('_debug_ready', () => resolve());
        setTimeout(resolve, 15000);
    }));

    // Prevent false-positive session-hash reloads in the dev-auth environment.
    await page.evaluate(() => { Rsx.validate_session = async () => true; });
}

/** Open an authenticated page on START_ROUTE. Auth headers ride the initial document only. */
async function open_panel(context) {
    const page = await context.newPage();
    const auth_headers = {
        ...dev_auth_headers(START_ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (!url.startsWith(BASE_URL)) {
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
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + START_ROUTE, { waitUntil: 'commit' });
    await await_spa_ready(page);
    return page;
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1920, height: 1080 },
    });

    try {
        const page = await open_panel(context);

        const on_page = await page.evaluate(() => !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present on ' + START_ROUTE + ' - auth may have failed');
            return;
        }

        // Dispatch a rejection exactly as the browser does; the promise itself is handled so
        // the test produces no real unhandled rejection of its own.
        await page.evaluate(() => {
            window.__reported = [];
            window.__no_reload_marker = 'original';
            Rsx.on('unhandled_exception', (data) => window.__reported.push({
                message: String(data.exception && data.exception.message),
                implicated: data.meta ? data.meta.implicated : undefined,
            }));
            window.__reject = (reason) => {
                const promise = Promise.reject(reason);
                promise.catch(() => {});
                window.dispatchEvent(new PromiseRejectionEvent('unhandledrejection', { promise, reason, cancelable: true }));
            };
        });

        // --- 1. a plain-object rejection: reported, readable, not fatal ---
        const plain = await page.evaluate(() => {
            window.__reject({ code: 'E_WIDGET', detail: 'bootstrap failed' });
            return { reported: window.__reported.slice(), enabled: Spa._spa_enabled };
        });

        if (plain.reported.length !== 1) {
            fail('a plain-object rejection was not reported exactly once: ' + JSON.stringify(plain.reported));
        } else if (plain.reported[0].message.includes('[object Object]') || !plain.reported[0].message.includes('E_WIDGET')) {
            fail('the reported message does not carry the rejected value: ' + plain.reported[0].message);
        } else if (plain.reported[0].implicated !== false) {
            fail('a plain-object rejection was not marked implicated:false');
        } else {
            console.log('PASS: a plain-object rejection is reported with its value as JSON and marked not implicated');
        }

        if (plain.enabled !== true) {
            fail('a plain-object rejection disabled SPA navigation');
        } else {
            console.log('PASS: SPA navigation stays enabled after a rejection that implicates no page code');
        }

        // --- 2. navigation is still client-side ---
        const navigated = await page.evaluate(async (target) => {
            await new Promise((resolve) => {
                Rsx.on('spa_dispatch_ready', (data) => {
                    if (Spa.parse_url(data.url).path === target) {
                        resolve();
                    }
                });
                Spa.dispatch(target);
            });
            return { path: window.location.pathname, marker: window.__no_reload_marker || null };
        }, OTHER_ROUTE);

        if (navigated.path !== OTHER_ROUTE || navigated.marker !== 'original') {
            fail('Spa.dispatch() afterwards did not navigate client-side - ' + JSON.stringify(navigated));
        } else {
            console.log('PASS: Spa.dispatch() afterwards navigates client-side');
        }

        // --- 3. extension-origin rejections are ignored outright ---
        const extension = await page.evaluate(() => {
            window.__reported = [];
            const from_extension = new Error('wallet provider failed');
            from_extension.stack = 'Error: wallet provider failed\n    at inject (chrome-extension://abcdefghijklmnop/content-script.js:464:9)';
            window.__reject(from_extension);
            window.__reject({ message: 'Could not establish connection. Receiving end does not exist.' });
            return { reported: window.__reported.slice(), enabled: Spa._spa_enabled };
        });

        if (extension.reported.length !== 0 || extension.enabled !== true) {
            fail('an extension-origin rejection was handled as the page failing - ' + JSON.stringify(extension));
        } else {
            console.log('PASS: extension-script and extension-messaging rejections are ignored');
        }

        // --- 4. a rejection from this page's own bundle is still fatal to the SPA ---
        const own = await page.evaluate(() => {
            window.__reported = [];
            const from_page = new Error('a real application bug');
            from_page.stack = 'Error: a real application bug\n    at Some_Action.on_load (' + window.location.origin + '/_compiled/Some_Bundle__app.js:1:100)';
            window.__reject(from_page);
            return { reported: window.__reported.slice(), enabled: Spa._spa_enabled };
        });

        if (own.reported.length !== 1 || own.reported[0].implicated !== true || own.enabled !== false) {
            fail('a rejection from this page\'s bundle was not handled as before - ' + JSON.stringify(own));
        } else {
            console.log('PASS: a rejection from this page\'s own code is reported and disables SPA navigation');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: unhandled rejection origin');
    } else {
        console.log('PASS: unhandled rejection origin');
    }
}

run();
