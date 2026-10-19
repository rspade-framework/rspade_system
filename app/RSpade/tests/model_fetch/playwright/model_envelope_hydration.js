#!/usr/bin/env node
/**
 * A {__MODEL} record whose class the bundle does not carry is refused, loudly.
 *
 * Every Ajax response is walked for {__MODEL: 'Name'} records, and each becomes an instance
 * of the JavaScript model class of that name. A bundle is not obliged to include every
 * model, so a response CAN carry a record the page has no class for. Handing that record on
 * as a plain object would read correctly field by field and fail only where something
 * calls a model method on it, far from the cause - so the walk throws instead, naming the
 * model and the fix (include the model in the bundle).
 *
 * Six cases:
 *
 *   a. a record of a model the bundle carries becomes an instance of it;
 *   b. a record nested in a plain object and in an array is found;
 *   c. a record of a model NO bundle carries throws, naming the model and the fix;
 *   d. a name the bundle carries as something other than a model throws too;
 *   e. a record nested inside another record is refused the same way;
 *   f. the refusal is reached through a real Ajax response, not only a direct call, and
 *      rejects THAT call.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the
 * model is the framework's User_Model, and the Ajax response in (f) is supplied by the
 * test for a framework endpoint's URL.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const MISSING_MODEL = 'Rsx_Probe_No_Such_Model_Temp';
const AJAX_PATH = '/_ajax/Rsx_Reference_Data_Controller/countries';


function fail(msg) {
    console.log('FAIL: A record with no class in the bundle is refused - ' + msg);
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

function names_the_cause(message) {
    return typeof message === 'string'
        && message.includes(MISSING_MODEL)
        && message.includes("does not include that model's class")
        && message.includes('Include the model in the bundle');
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

        // (f) the response a framework endpoint's URL answers with, carrying a record of a
        // model this bundle has no class for.
        // Every call rides /_ajax/_batch, so the batch carrying that endpoint's call is
        // answered under the call's own key.
        if (url.includes('/_ajax/_batch')) {
            const body = JSON.parse(request.postData() || '{}');
            const call = (body.batch_calls || []).find((c) => '/_ajax/' + c.controller + '/' + c.action === AJAX_PATH);
            if (call) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        ['C_' + call.call_id]: {
                            _success: true,
                            _ajax_return_value: { rows: [{ __MODEL: MISSING_MODEL, id: 7 }] },
                        },
                    }),
                });
                return;
            }
        }

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

        const result = await page.evaluate((missing_model) => {
            const walk = (data) => Rsx_Js_Model._instantiate_models_recursive(data);
            const thrown = (data) => {
                try {
                    walk(data);
                } catch (error) {
                    return String(error && error.message);
                }

                return null;
            };
            const out = {};

            // a. a model the bundle carries
            const user = walk({ __MODEL: 'User_Model', id: 3, email: 'probe@example.com' });
            out.a_instance = user instanceof User_Model;
            out.a_field = user.email;

            // b. nested in a plain object and in an array
            const nested = walk({ meta: { count: 1 }, rows: [{ __MODEL: 'User_Model', id: 4 }] });
            out.b_instance = nested.rows[0] instanceof User_Model;
            out.b_plain = nested.meta.count;

            // c. a model no bundle carries
            out.c_message = thrown({ __MODEL: missing_model, id: 1 });

            // d. a class the bundle carries that is not a model
            out.d_message = thrown({ __MODEL: 'Ajax', id: 1 });

            // e. nested inside another record
            out.e_message = thrown({ __MODEL: 'User_Model', id: 5, owner: { __MODEL: missing_model, id: 9 } });

            return out;
        }, MISSING_MODEL);

        if (result.a_instance !== true || result.a_field !== 'probe@example.com') {
            fail('(a) a User_Model record did not become a User_Model instance carrying its fields');
        } else {
            console.log('PASS: a record of a model the bundle carries becomes an instance of it');
        }

        if (result.b_instance !== true || result.b_plain !== 1) {
            fail('(b) a record nested in an array inside a plain object was not hydrated');
        } else {
            console.log('PASS: a nested record is found, and plain values pass through');
        }

        if (!names_the_cause(result.c_message)) {
            fail('(c) a record of a model no bundle carries did not throw naming the model and the fix; got: ' + result.c_message);
        } else {
            console.log('PASS: a record with no class in the bundle throws, naming the model and the fix');
        }

        if (typeof result.d_message !== 'string' || !result.d_message.includes("does not include that model's class")) {
            fail('(d) a name the bundle carries as a non-model class did not throw; got: ' + result.d_message);
        } else {
            console.log('PASS: a name that is a class but not a model is refused too');
        }

        if (!names_the_cause(result.e_message)) {
            fail('(e) a classless record nested inside another record did not throw; got: ' + result.e_message);
        } else {
            console.log('PASS: a classless record nested inside another record is refused');
        }

        // f. through the transport: the refusal raised while the response is unwrapped is
        // THAT call's failure - the caller's promise rejects with it.
        const f_message = await page.evaluate(async (ajax_path) => {
            try {
                await Ajax.call(ajax_path, {});
                return 'NO THROW';
            } catch (e) {
                return String(e && e.message);
            }
        }, AJAX_PATH);

        if (!names_the_cause(f_message)) {
            fail('(f) an Ajax response carrying a classless record did not reject the call with the refusal; got: ' + f_message);
        } else {
            console.log('PASS: an Ajax response carrying a classless record rejects the call with the refusal');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: A record with no class in the bundle is refused');
    } else {
        console.log('PASS: A record with no class in the bundle is refused');
    }
}

run();
