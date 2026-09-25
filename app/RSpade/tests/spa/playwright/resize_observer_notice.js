#!/usr/bin/env node
/**
 * The browser's ResizeObserver delivery-overrun notice is not an application error
 * (SPA-RO-01..03).
 *
 * When a ResizeObserver callback changes layout so that notifications are still pending as a
 * batch ends, the browser dispatches an ErrorEvent at window with no error object and no stack,
 * and delivers the rest on the next frame. Nothing threw. The framework's window error listener
 * must not treat it as an unhandled exception, because that path disables SPA navigation on a
 * page that is working correctly.
 *
 *   1. Both spellings of the notice, dispatched as the browser dispatches them, fire no
 *      unhandled_exception and leave SPA navigation enabled.
 *   2. Spa.dispatch() afterwards still navigates CLIENT-SIDE: a marker on window survives,
 *      which a full page load would erase.
 *   3. The match is exact: a different message, and the same text carried by a real Error,
 *      are both still reported as unhandled exceptions.
 *
 * Runs against the framework's own /_sys panel, so nothing here depends on the application
 * tree. Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so a
 * bare `node resize_observer_notice.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const OTHER_ROUTE = '/_sys/tasks';
const USER_ID = 1;

const NOTICES = [
    'ResizeObserver loop completed with undelivered notifications.',
    'ResizeObserver loop limit exceeded',
];

function fail(msg) {
    console.log('FAIL: resize observer notice - ' + msg);
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

        // Every unhandled_exception is counted on window; the marker proves no page load.
        await page.evaluate(() => {
            window.__unhandled = [];
            window.__no_reload_marker = 'original';
            Rsx.on('unhandled_exception', (data) => window.__unhandled.push(String(data.exception && data.exception.message)));
        });

        // --- 1. both spellings are ignored and leave the SPA enabled ---
        const after_notice = await page.evaluate((messages) => {
            for (const message of messages) {
                window.dispatchEvent(new ErrorEvent('error', { message }));
            }
            return { unhandled: window.__unhandled.slice(), enabled: Spa._spa_enabled };
        }, NOTICES);

        if (after_notice.unhandled.length !== 0) {
            fail('the notice was reported as an unhandled exception: ' + JSON.stringify(after_notice.unhandled));
        } else {
            console.log('PASS: neither ResizeObserver notice spelling is reported as an unhandled exception');
        }

        if (after_notice.enabled !== true) {
            fail('the notice disabled SPA navigation');
        } else {
            console.log('PASS: SPA navigation stays enabled after the notice');
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
            fail('Spa.dispatch() after the notice did not navigate client-side - ' + JSON.stringify(navigated));
        } else {
            console.log('PASS: Spa.dispatch() after the notice navigates client-side');
        }

        // --- 3. the match is exact: anything else is still an error ---
        const controls = await page.evaluate((notice) => {
            window.__unhandled = [];
            window.dispatchEvent(new ErrorEvent('error', { message: 'ResizeObserver loop is a real bug' }));
            window.dispatchEvent(new ErrorEvent('error', { message: notice, error: new Error(notice) }));
            return { unhandled: window.__unhandled.slice(), enabled: Spa._spa_enabled };
        }, NOTICES[0]);

        if (controls.unhandled.length !== 2 || controls.enabled !== false) {
            fail('a non-matching message or a real Error was not handled as an exception - ' + JSON.stringify(controls));
        } else {
            console.log('PASS: a different message, or a real Error with the same text, is still an unhandled exception');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: resize observer notice');
    } else {
        console.log('PASS: resize observer notice');
    }
}

run();
