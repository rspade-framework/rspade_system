#!/usr/bin/env node
/**
 * focus() on a form input is timing-indifferent, like val().
 *
 * A host that wants the cursor in a field calls input.focus() and nothing else - it never
 * reaches into the input's markup, and it never waits for the input to be ready. Four cases:
 *
 *   a. on a ready input, focus() puts the cursor in its control;
 *   b. on an input that is not ready, focus() moves nothing and is remembered;
 *   c. _mark_ready() honours the remembered request, AFTER applying a buffered value, so the
 *      control holds the value at the moment it takes focus;
 *   d. readiness with no request pending moves focus nowhere.
 *
 * "Not ready" is produced by clearing the instance's ready flag: the panel's text input
 * marks itself ready during its first render, so there is no earlier moment to reach from
 * outside, and what is under test is the base class's bookkeeping.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * input is the panel's own text input, mounted at runtime.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Form_Input_Abstract.focus() - ' + msg);
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

    // Prevent false-positive session-hash reloads in the dev-auth environment.
    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
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

        const result = await page.evaluate(async () => {
            $('body').append('<div id="rsx_focus_mount_temp"></div><input type="text" id="rsx_focus_elsewhere_temp" />');
            $('#rsx_focus_mount_temp').component('_Sys_Text_Input', { name: 'probe', max_length: -1 });
            await sleep(300);

            const input = $('#rsx_focus_mount_temp').component();
            const control = input.$.find('input').get(0);
            const elsewhere = document.getElementById('rsx_focus_elsewhere_temp');
            const out = {};

            // a. ready
            elsewhere.focus();
            input.focus();
            out.a_focused = document.activeElement === control;

            // b. not ready: nothing moves, the request is kept
            elsewhere.focus();
            input._is_ready = false;
            input.val('buffered');
            input.focus();
            out.b_unmoved = document.activeElement === elsewhere;

            // c. readiness applies the value, then the focus
            let value_at_focus = null;
            $(control).one('focus', function () { value_at_focus = control.value; });
            input._mark_ready();
            out.c_focused = document.activeElement === control;
            out.c_value_at_focus = value_at_focus;

            // d. readiness with nothing pending
            elsewhere.focus();
            input._is_ready = false;
            input._mark_ready();
            out.d_unmoved = document.activeElement === elsewhere;

            $('#rsx_focus_mount_temp, #rsx_focus_elsewhere_temp').remove();
            return out;
        });

        if (!result.a_focused) {
            fail('(a) focus() on a ready input did not put the cursor in its control');
        } else {
            console.log('PASS: focus() on a ready input focuses its control');
        }

        if (!result.b_unmoved) {
            fail('(b) focus() before ready moved the cursor');
        } else {
            console.log('PASS: focus() before ready moves nothing');
        }

        if (!result.c_focused || result.c_value_at_focus !== 'buffered') {
            fail('(c) readiness did not honour the pending focus after the pending value (focused: '
                + result.c_focused + ', value at focus: ' + JSON.stringify(result.c_value_at_focus) + ')');
        } else {
            console.log('PASS: readiness applies the buffered value, then the pending focus');
        }

        if (!result.d_unmoved) {
            fail('(d) readiness with no focus pending moved the cursor');
        } else {
            console.log('PASS: readiness with nothing pending leaves focus alone');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Form_Input_Abstract.focus()');
    } else {
        console.log('PASS: Form_Input_Abstract.focus()');
    }
}

run();
