#!/usr/bin/env node
/**
 * A validation failure that names no field still shows its message at the top of the form.
 *
 * THE REGRESSION THIS EXISTS FOR. An endpoint that answers
 * response_error(Ajax::ERROR_VALIDATION, 'That code is not valid.') reaches the browser as
 * metadata {_message: '...'}. The renderer skips every key beginning with an underscore, so
 * it found no field errors, and it drew the top alert only when it had found at least one -
 * the submit resolved false, logged to the console, and the user saw nothing at all (a
 * downstream field report, 2026-10-08). The form contract says a failed submit is never
 * silent; this is the path where it was.
 *
 * Three cases, each a real submit() through a scripted Ajax.call:
 *
 *   a. a message with no field errors is rendered in <Form_Errors />;
 *   b. a message WITH a field error still renders the message once (the path that always
 *      worked must not gain a second copy);
 *   c. a before_submit hook that throws an Error takes the same route and is rendered too.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the
 * components are Rsx_Form, Form_Errors and the panel's own text input, built at runtime.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: A message-only validation error is rendered - ' + msg);
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
            $('body').append('<form id="rsx_msg_only_mount_temp"></form>');
            $('#rsx_msg_only_mount_temp').component('Rsx_Form', {
                controller: 'Rsx_Form_Message_Only_Probe_Controller_Temp',
                method: 'never_reached',
            });
            await sleep(300);

            const form = $('#rsx_msg_only_mount_temp').component();
            $('#rsx_msg_only_mount_temp').append('<div id="rsx_msg_only_errors_temp"></div>');
            $('#rsx_msg_only_errors_temp').component('Form_Errors');
            $('#rsx_msg_only_mount_temp').append('<div id="rsx_msg_only_input_temp"></div>');
            $('#rsx_msg_only_input_temp').component('_Sys_Text_Input', { name: 'code', max_length: -1 });
            await sleep(300);

            const original = Ajax.call;
            const reject_with = (metadata) => {
                Ajax.call = function () {
                    const error = new Error(metadata._message);
                    error.code = Ajax.ERROR_VALIDATION;
                    error.metadata = metadata;
                    return Promise.reject(error);
                };
            };
            const count = (text) => $('#rsx_msg_only_errors_temp').text().split(text).length - 1;
            const out = {};

            // a. the message is all there is
            reject_with({ _message: 'That code is not valid.' });
            out.a_result = await form.submit();
            out.a_count = count('That code is not valid.');

            // b. a message and a field error
            reject_with({ _message: 'Check the code.', code: 'Six digits, please.' });
            out.b_result = await form.submit();
            out.b_count = count('Check the code.');
            out.b_stale = count('That code is not valid.');

            // c. a before_submit hook that throws an Error
            Ajax.call = original;
            form.before_submit = function () { throw new Error('The hook refused this.'); };
            out.c_result = await form.submit();
            out.c_count = count('The hook refused this.');

            $('#rsx_msg_only_mount_temp').remove();
            return out;
        });

        if (result.a_result !== false || result.a_count !== 1) {
            fail('(a) a message-only failure rendered ' + result.a_count + ' copies of its message (expected 1), submit() gave ' + result.a_result);
        } else {
            console.log('PASS: a validation failure with no field errors shows its message');
        }

        if (result.b_result !== false || result.b_count !== 1 || result.b_stale !== 0) {
            fail('(b) message + field error rendered ' + result.b_count + ' copies (expected 1), ' + result.b_stale + ' stale');
        } else {
            console.log('PASS: a message beside a field error is still shown exactly once');
        }

        if (result.c_result !== false || result.c_count !== 1) {
            fail('(c) an Error thrown by before_submit rendered ' + result.c_count + ' copies of its message (expected 1)');
        } else {
            console.log('PASS: an Error thrown by before_submit shows its message');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: A message-only validation error is rendered');
    } else {
        console.log('PASS: A message-only validation error is rendered');
    }
}

run();
