#!/usr/bin/env node
/**
 * The page does not boot until its field-length script has run, however late that script arrives.
 *
 * The field-length script (<script src="/_field_lengths/..." defer>) follows the app bundle, and
 * the bundle boots the page. jQuery is deferred too, so it finds the document already
 * "interactive" and runs its ready() handlers from a timer - one that can fire while the browser
 * is still fetching a later deferred script. A bundle that booted from ready() therefore booted
 * before a slow field-length script, and a form then threw from Model.field_length(). A deferred
 * bundle boots on DOMContentLoaded, which the browser fires only after every deferred script.
 *
 * The script HOLDS the field-length response until the app bundle has executed and the page has
 * had a moment's worth of timer ticks to boot in, then checks it has not booted, then releases it:
 *
 *   a. while the field-length script is held, the bundle has run but the page has not booted;
 *   b. once it is released the page boots, with the field-length data in place;
 *   c. field_length() answers, and the page reported no error.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * model is the framework's User_Model.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function check(ok, what, detail) {
    if (ok) {
        console.log('PASS: ' + what);
    } else {
        console.log('FAIL: ' + what + (detail ? ' - ' + detail : ''));
        process.exitCode = 1;
    }
}

(async () => {
    let held = null;
    let page_ref = null;
    const held_done = new Promise((resolve) => { held = resolve; });

    const on_request = async (route, request, headers) => {
        if (!request.url().includes('/_field_lengths/')) {
            return false;
        }
        (async () => {
            const page = page_ref;
            // The app bundle has executed once Rsx exists. The ready() timer the old boot rode
            // on is a zero-delay setTimeout, so 250 ms of the page's own timers is many times
            // what it needed to fire; the wait gives a wrong boot its chance and decides nothing
            // by itself expiring.
            await page.waitForFunction(() => typeof Rsx !== 'undefined', null, { timeout: 0 });
            const while_held = await page.evaluate(async () => {
                await new Promise((resolve) => setTimeout(resolve, 250));
                return {
                    booted: !!Rsx.__booted,
                    ready_state: document.readyState,
                    data: Rsx.__field_length_data === undefined ? 'undefined' : (Rsx.__field_length_data === null ? 'null' : 'set'),
                };
            });
            await route.continue({ headers });
            held(while_held);
        })();
        return true;
    };

    // page_ref must be set before the first request reaches the interceptor; open_sys_page
    // creates the page and navigates in one call, so the interceptor reads it lazily.
    const opening = open_sys_page({ on_request: async (route, request, headers) => {
        if (!page_ref) {
            page_ref = route.request().frame().page();
        }
        return on_request(route, request, headers);
    } });

    let browser = null;
    try {
        const opened = await opening;
        browser = opened.browser;
        const { page, errors } = opened;
        const while_held = await held_done;

        check(
            while_held.booted === false && while_held.ready_state === 'interactive',
            '(a) while the field-length script is held, the bundle has run but the page has not booted',
            JSON.stringify(while_held)
        );

        const after = await page.evaluate(() => ({
            booted: !!Rsx.__booted,
            has_data: !!Rsx.__field_length_data && typeof Rsx.__field_length_data === 'object',
            first_name: User_Model.field_length('first_name'),
        }));

        check(after.booted && after.has_data, '(b) once released the page boots, with the field-length data in place', JSON.stringify(after));
        check(typeof after.first_name === 'number' && after.first_name > 0, '(c) field_length() answers', String(after.first_name));
        check(errors.length === 0, '(c) the page reported no error', errors.join(' | '));
    } catch (e) {
        check(false, 'the script ran to completion', e.message);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
