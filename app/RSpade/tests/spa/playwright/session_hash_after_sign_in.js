#!/usr/bin/env node
/**
 * The page's session hash names the session the request ENDED with.
 *
 * rsxapp.session_hash and Spa_Session_Controller::get_state() are compared by
 * Rsx.validate_session() after every SPA navigation, and a difference reloads the page. Both
 * are Session::_client_session_hash(): the session the request holds, read from its row.
 * They used to be computed from the incoming COOKIE, so a request that created or rotated the
 * session while rendering - a sign-in during the request, as the dev-auth harness performs,
 * or a first visit whose page opened a session - published null (or the old session) and the
 * first SPA navigation reloaded the page with 'session_changed'.
 *
 * The dev-auth sign-in is exactly that case: the first document request arrives with no
 * cookie and signs in while it renders. Three checks, WITHOUT stubbing validate_session (the
 * other SPA tests stub it; this one exists to prove they need not):
 *
 *   a. the page carries a session hash, although the request arrived with no cookie;
 *   b. it equals what get_state() answers for the same browser;
 *   c. an SPA navigation stays in the document - no 'session_changed' reload.
 *
 * Framework page only: the control panel at /_sys and its /_sys/tasks screen.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: session hash after an in-request sign-in - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));

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
    const page = await context.newPage();

    // Auth headers ONLY on the initial document request (per the SPA test harness contract).
    const auth_headers = {
        ...dev_auth_headers(ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (url.startsWith(BASE_URL)) {
            const headers = { ...request.headers() };
            if (!initial_doc_sent && request.resourceType() === 'document') {
                Object.assign(headers, auth_headers);
                initial_doc_sent = true;
            } else {
                headers['X-Playwright-Test'] = '1';
            }
            await route.continue({ headers });
        } else {
            await route.continue();
        }
    });

    try {
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed');
            await browser.close();
            return;
        }

        const console_lines = [];
        page.on('console', (msg) => console_lines.push(msg.text()));

        const first = await page.evaluate(async () => {
            window.__session_hash_mark = 'alive';
            const state = await Spa_Session_Controller.get_state({ is_portal: false });
            return { page_hash: window.rsxapp.session_hash, server_hash: state.session_hash };
        });

        if (typeof first.page_hash === 'string' && first.page_hash.length > 0) {
            console.log('PASS: the page carries a session hash after signing in during its own request');
        } else {
            fail('(a) rsxapp.session_hash is ' + JSON.stringify(first.page_hash));
        }

        if (first.page_hash === first.server_hash) {
            console.log('PASS: the page hash equals what get_state() answers');
        } else {
            fail('(b) page ' + first.page_hash + ' vs server ' + first.server_hash);
        }

        await page.evaluate(() => Spa.dispatch('/_sys/tasks'));
        await page.waitForTimeout(3000);

        let after;
        try {
            after = await page.evaluate(() => ({
                mark: window.__session_hash_mark || null,
                path: window.location.pathname,
            }));
        } catch (e) {
            after = { mark: null, path: null };
        }
        const reloaded = console_lines.some((line) => line.includes('session_changed'));

        if (after.mark === 'alive' && after.path === '/_sys/tasks' && !reloaded) {
            console.log('PASS: an SPA navigation stays in the document');
        } else {
            fail('(c) mark ' + after.mark + ', path ' + after.path + ', session_changed logged: ' + reloaded);
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: session hash after an in-request sign-in');
    } else {
        console.log('PASS: session hash after an in-request sign-in');
    }
}

run();
