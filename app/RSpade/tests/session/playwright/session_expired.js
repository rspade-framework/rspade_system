#!/usr/bin/env node
/**
 * The browser's side of the session lifetime (SESSION-EXPIRED-01..05, SESSION-PAGEVIEW-01..07).
 *
 *   1. a session_expired envelope calls the registered handler with the server's message
 *   2. further refusals on the same page - session_expired or auth_required - do not
 *      call it again
 *   3. an auth_required envelope alone is enough: a sign-in ended elsewhere is the same
 *      event
 *   4. a page rendered for nobody announces nothing
 *   5. with no handler registered the page reloads
 *   6. a completed SPA dispatch reports a page view, at most once a minute
 *   7. nothing is reported once the sign-in is known to be gone
 *   8. Rsx_Session.report_page_view() is public: one report a minute however often it is
 *      called, nothing when signed out; the underscore name is gone
 *   9. a URL-hash write reports a page view when it changes the hash in answer to a
 *      person's input (a real click), not when it rewrites the same state, and not when
 *      code changes the hash on its own (the browser holding no user activation)
 *
 * The once-a-minute throttle is driven through Rsx_Session._last_page_view_ms (set a
 * minute into the past), never by waiting; the report itself is a stub counting calls.
 *
 * The envelopes are fed straight to Ajax's own envelope reader, so what is under test is
 * the client's reaction and not a real expiry (tests/session/http/session_lifetime.sh
 * covers the server). Runs on the framework's own /_sys panel; self-contained, so a bare
 * `node session_expired.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');
const { poll_until } = require('../../_lib/playwright/sys_page.js');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: session expired - ' + msg);
    process.exitCode = 1;
}

function check(condition, label, detail) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Open START_ROUTE signed in and wait for the SPA to finish booting. */
async function open_panel(context) {
    const page = await context.newPage();
    const auth_headers = {
        ...dev_auth_headers(START_ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (!url.startsWith(BASE_URL)) {
            await route.continue();
            return;
        }
        const headers = { ...request.headers() };
        if (request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + START_ROUTE, { waitUntil: 'commit' });
    await poll_until(page, 'the panel finished booting', () => '_debug_ready' in Rsx._triggered_events);
    return page;
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 800 },
    });

    try {
        // ---- The handler ---------------------------------------------------------------
        const page = await open_panel(context);

        const handled = await page.evaluate(() => {
            const calls = [];
            Rsx.set_session_expired_handler((info) => calls.push(info.message));

            const out = { is_auth: window.rsxapp.is_auth };

            Ajax._envelope_error({ error_code: 'session_expired', reason: 'Your session has expired. Please sign in again.' }, 'A::b');
            out.after_first = calls.slice();

            Ajax._envelope_error({ error_code: 'session_expired', reason: 'again' }, 'A::c');
            Ajax._envelope_error({ error_code: 'auth_required', reason: 'Please log in to continue' }, 'A::d');
            out.after_more = calls.slice();

            return out;
        });
        check(handled.is_auth === true, 'the page was rendered signed in', handled);
        check(handled.after_first.length === 1 && handled.after_first[0] === 'Your session has expired. Please sign in again.',
            'a session_expired envelope calls the handler with the server message', handled.after_first);
        check(handled.after_more.length === 1, 'later refusals on the same page do not call it again', handled.after_more);

        // ---- Page views ----------------------------------------------------------------
        const views_after_expiry = await page.evaluate(() => {
            let reported = 0;
            Rsx_Session_Controller.page_view = () => { reported++; return Promise.resolve(true); };
            Rsx_Session._last_page_view_ms = 0;
            Rsx.trigger('spa_dispatch_ready', { url: '/x' });
            return reported;
        });
        check(views_after_expiry === 0, 'no page view is reported once the sign-in is known to be gone', views_after_expiry);
        await page.close();

        const second = await open_panel(context);

        const auth_required_alone = await second.evaluate(() => {
            const calls = [];
            Rsx.set_session_expired_handler((info) => calls.push(info.message));
            Ajax._envelope_error({ error_code: 'auth_required', reason: 'Please log in to continue' }, 'A::b');
            return calls;
        });
        check(auth_required_alone.length === 1, 'auth_required on a signed-in page is the same event', auth_required_alone);
        await second.close();

        const third = await open_panel(context);

        const views = await third.evaluate(() => {
            let reported = 0;
            Rsx_Session_Controller.page_view = () => { reported++; return Promise.resolve(true); };

            const out = {};

            Rsx.trigger('spa_dispatch_ready', { url: '/a' });
            out.right_after_boot = reported;

            Rsx_Session._last_page_view_ms = Date.now() - 61000;
            Rsx.trigger('spa_dispatch_ready', { url: '/b' });
            out.after_a_minute = reported;

            Rsx.trigger('spa_dispatch_ready', { url: '/c' });
            out.immediately_again = reported;

            return out;
        });
        check(views.right_after_boot === 0, 'the page load that booted the tab already counted', views);
        check(views.after_a_minute === 1, 'a completed dispatch a minute later reports a page view', views);
        check(views.immediately_again === 1, 'a second dispatch inside the minute reports nothing', views);

        // ---- The public report ---------------------------------------------------------
        const report = await third.evaluate(() => {
            let reported = 0;
            Rsx_Session_Controller.page_view = () => { reported++; return Promise.resolve(true); };

            const out = { old_name: typeof Rsx_Session._report_page_view };

            Rsx_Session._last_page_view_ms = Date.now() - 61000;
            Rsx_Session.report_page_view();
            Rsx_Session.report_page_view();
            Rsx_Session.report_page_view();
            out.three_calls = reported;

            window.rsxapp.is_auth = false;
            Rsx_Session._last_page_view_ms = Date.now() - 61000;
            Rsx_Session.report_page_view();
            out.signed_out = reported;
            window.rsxapp.is_auth = true;

            return out;
        });
        check(report.three_calls === 1, 'report_page_view() sends one report a minute however often it is called', report);
        check(report.signed_out === 1, 'report_page_view() sends nothing on a page rendered for nobody', report);
        check(report.old_name === 'undefined', 'the underscore name is gone', report);

        // ---- Hash writes ---------------------------------------------------------------
        // A real click (Playwright input, so the browser grants a user activation) runs the
        // staged write inside its handler. Code running with nobody at the screen is the
        // browser reporting no activation; Playwright evaluates every script AS a user
        // gesture, so that state is stated through navigator.userActivation instead.
        await third.evaluate(() => {
            window.__hash_writes = {
                code: () => Rsx.url_hash_set({ pv_probe: 'code' }),
                set_a: () => Rsx.url_hash_set({ pv_probe: 'a' }),
                single_b: () => Rsx.url_hash_set_single('pv_probe', 'b'),
                remove: () => Rsx.url_hash_set_single('pv_probe', null),
            };
            window.__hash_reports = 0;
            Rsx_Session_Controller.page_view = () => { window.__hash_reports++; return Promise.resolve(true); };
            const probe = document.createElement('div');
            probe.id = 'hash_probe';
            probe.style.cssText = 'position:fixed;top:0;left:0;width:40px;height:40px;z-index:2147483647;background:#fff';
            probe.addEventListener('click', () => window.__hash_probe());
            document.body.appendChild(probe);
        });

        // Stage one write, open the throttle, perform it by a click or by code; answer the
        // number of reports it caused.
        async function hash_write(how, write) {
            await third.evaluate((name) => {
                Rsx_Session._last_page_view_ms = Date.now() - 61000;
                window.__hash_reports = 0;
                window.__hash_probe = window.__hash_writes[name];
            }, write);
            if (how === 'click') {
                await third.click('#hash_probe');
            } else {
                await third.evaluate(() => {
                    Object.defineProperty(navigator, 'userActivation', { configurable: true, value: { isActive: false } });
                    try {
                        window.__hash_probe();
                    } finally {
                        delete navigator.userActivation;
                    }
                });
            }
            return third.evaluate(() => window.__hash_reports);
        }

        const by_code = await hash_write('code', 'code');
        check(by_code === 0, 'a hash changed by code with no user input reports nothing', by_code);

        const set_changed = await hash_write('click', 'set_a');
        check(set_changed === 1, 'url_hash_set() changing the hash on a click reports a page view', set_changed);

        const set_same = await hash_write('click', 'set_a');
        check(set_same === 0, 'url_hash_set() writing the same state again reports nothing', set_same);

        const single_changed = await hash_write('click', 'single_b');
        check(single_changed === 1, 'url_hash_set_single() changing the hash on a click reports a page view', single_changed);

        const single_same = await hash_write('click', 'single_b');
        check(single_same === 0, 'url_hash_set_single() writing the same value again reports nothing', single_same);

        const removed = await hash_write('click', 'remove');
        check(removed === 1, 'removing a key on a click is a change and reports a page view', removed);

        const hash_after = await third.evaluate(() => window.location.hash);
        check(hash_after.indexOf('pv_probe') === -1, 'the probe key left the address', hash_after);

        // ---- A page rendered for nobody ------------------------------------------------
        const anonymous = await third.evaluate(() => {
            const calls = [];
            Rsx.set_session_expired_handler((info) => calls.push(info.message));
            window.rsxapp.is_auth = false;
            Ajax._envelope_error({ error_code: 'auth_required', reason: 'Please log in to continue' }, 'A::b');
            return calls;
        });
        check(anonymous.length === 0, 'a page rendered for nobody announces nothing', anonymous);
        await third.close();

        // ---- No handler: the page reloads ----------------------------------------------
        const fourth = await open_panel(context);
        const reloaded = fourth.waitForEvent('load');
        await fourth.evaluate(() => {
            Ajax._envelope_error({ error_code: 'session_expired', reason: 'expired' }, 'A::b');
        });
        await reloaded;
        check(true, 'with no handler registered the page reloads');
        await fourth.close();
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: session expired');
    } else {
        console.log('PASS: session expired');
    }
}

run();
