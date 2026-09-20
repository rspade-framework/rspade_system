#!/usr/bin/env node
/**
 * The browser half of a server-driven question: the submission loop in Rsx_Form.
 *
 * An endpoint may answer a submission with a QUESTION rather than a result. submit()
 * clears the spinner, runs the application's question handler, merges the answer into
 * _answers and calls the endpoint AGAIN - all inside one submit() invocation. Five
 * properties carry the feature, and each is one case below:
 *
 *   a. CANCEL IS NOT AN ANSWER. Rsx_Form.CANCELLED stops the submit like before_submit
 *      returning false: one call made, nothing rendered, the form still holding what the
 *      user typed. This is the distinction the whole feature rests on - a dialog's "No"
 *      is an answer the endpoint acts on, and a dismissal is not.
 *   b. FALSE IS AN ANSWER. It reaches the endpoint as _answers.k === false, riding
 *      alongside the original values rather than replacing them.
 *   c. ANSWERS ACCUMULATE. A second question does not discard the first answer.
 *   d. MAX_QUESTION_ROUNDS IS A DEFECT DETECTOR. An endpoint that keeps asking is
 *      ignoring _answers; the loop ends in a rendered error instead of running forever.
 *   e. NO HANDLER IS A CONFIGURATION FAULT. It escapes submit() rather than being
 *      rendered, so the omission cannot hide inside a form's error area.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys and
 * the mounted components are Rsx_Form and Form_Errors; the probe form is built at runtime
 * in the browser, so no application screen, modal or bundle is involved and it runs in any
 * install. Ajax.call is replaced by a scripted fake for the duration and restored
 * afterwards: what is under test is the LOOP, not any endpoint.
 *
 * The probe carries its own per-form question_handler, which takes precedence over
 * whatever global handler the install has registered (the reference application registers
 * one; a framework page's bundle does not carry it). Case (e) clears both and restores
 * them.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so it
 * runs with a bare `node form_questions_round_trip.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Rsx_Form question round trip - ' + msg);
    process.exitCode = 1;
}

function pass(msg) {
    console.log('PASS: ' + msg);
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

    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
}

/**
 * Build the probe: a real <form> carrying an Rsx_Form, one <Form_Errors /> (the engine
 * refuses to render a failure without one) and a native hidden input, which is what
 * vals() serializes without any application input component being present.
 */
async function build_probe(page) {
    return await page.evaluate(async () => {
        $('body').append('<form id="rsx_question_mount_temp"></form>');
        $('#rsx_question_mount_temp').component('Rsx_Form', {
            controller: 'Rsx_Form_Question_Probe_Controller_Temp',
            method: 'never_reached',
        });

        await sleep(300);

        const form = $('#rsx_question_mount_temp').component();
        if (!form) {
            return { mounted: false };
        }

        $('#rsx_question_mount_temp').append('<div id="rsx_question_errors_temp"></div>');
        $('#rsx_question_errors_temp').component('Form_Errors');
        $('#rsx_question_mount_temp').append(
            '<input type="hidden" name="title" value="the typed value" />'
        );
        $('#rsx_question_mount_temp').append(
            '<input type="text" id="rsx_question_typed_temp" value="" />'
        );

        await sleep(200);

        // The scripted fake. window.__q_script is a list of outcomes, one per call:
        // {question: {key, question}} rejects as a pending question, anything else resolves.
        window.__q_calls = [];
        window.__q_original_ajax_call = Ajax.call;
        Ajax.call = function (url, body) {
            window.__q_calls.push({ url: url, body: JSON.parse(JSON.stringify(body || {})) });

            const index = window.__q_calls.length - 1;
            const step = window.__q_always_ask
                ? { question: { key: 'k' + index, question: { kind: 'confirm' } } }
                : (window.__q_script[index] || { resolve: { ok: true } });

            if (step.question) {
                const error = new Error('A question is pending');
                error.code = Ajax.ERROR_QUESTION;
                error.metadata = step.question;
                return Promise.reject(error);
            }

            return Promise.resolve(step.resolve);
        };

        // The per-form handler delegates to a swappable page-level function, so each case
        // scripts its own answers without rebuilding the form.
        form.question_handler = async function (question, context) {
            return await window.__q_answer(question, context);
        };

        return { mounted: true };
    });
}

/** Reset the recorder and script one case. Returns nothing. */
async function arm(page, script, always_ask) {
    await page.evaluate(({ script, always_ask }) => {
        window.__q_calls = [];
        window.__q_script = script;
        window.__q_always_ask = !!always_ask;
        window.__q_handler_calls = [];
        $('#rsx_question_typed_temp').val('still here');
        $('#rsx_question_errors_temp').empty();
    }, { script, always_ask });
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

        const probe = await build_probe(page);
        if (!probe.mounted) {
            fail('the probe form never produced an Rsx_Form instance');
            await browser.close();
            return;
        }

        // --- a. CANCELLED stops the submit and leaves the form alone ---
        await arm(page, [{ question: { key: 'k', question: { kind: 'confirm' } } }]);
        const cancelled = await page.evaluate(async () => {
            window.__q_answer = async () => Rsx_Form.CANCELLED;
            const result = await $('#rsx_question_mount_temp').component().submit();
            return {
                result: result,
                calls: window.__q_calls.length,
                error_html: $('#rsx_question_errors_temp').text().trim(),
                hidden: $('#rsx_question_mount_temp input[name="title"]').val(),
                typed: $('#rsx_question_typed_temp').val(),
            };
        });

        if (cancelled.result !== false) {
            fail('(a) a cancelled question must resolve false, got ' + JSON.stringify(cancelled.result));
        } else if (cancelled.calls !== 1) {
            fail('(a) a cancelled question must make exactly one call, made ' + cancelled.calls);
        } else if (cancelled.error_html !== '') {
            fail('(a) a cancel rendered an error: "' + cancelled.error_html + '"');
        } else if (cancelled.hidden !== 'the typed value' || cancelled.typed !== 'still here') {
            fail('(a) the form lost the user\'s values on cancel');
        } else {
            pass('CANCELLED stops the submit with no error and the form intact');
        }

        // --- b. false is an answer, and it rides with the original values ---
        await arm(page, [
            { question: { key: 'k', question: { kind: 'confirm' } } },
            { resolve: { saved: true } },
        ]);
        const answered = await page.evaluate(async () => {
            window.__q_answer = async () => false;
            const result = await $('#rsx_question_mount_temp').component().submit();
            return { result: result, calls: window.__q_calls };
        });

        if (!answered.result || answered.result.saved !== true) {
            fail('(b) the answered submit must resolve with the server result');
        } else if (answered.calls.length !== 2) {
            fail('(b) expected two calls, made ' + answered.calls.length);
        } else if (answered.calls[0].body._answers !== undefined) {
            fail('(b) the first call must carry no _answers');
        } else if (answered.calls[1].body._answers.k !== false) {
            fail('(b) the answer false did not reach the endpoint: '
                + JSON.stringify(answered.calls[1].body._answers));
        } else if (answered.calls[1].body.title !== 'the typed value') {
            fail('(b) the resubmission lost the original values');
        } else {
            pass('an answer of false resubmits with _answers and the original values');
        }

        // --- c. answers accumulate across questions ---
        await arm(page, [
            { question: { key: 'first', question: { kind: 'confirm' } } },
            { question: { key: 'second', question: { kind: 'prompt' } } },
            { resolve: { saved: true } },
        ]);
        const accumulated = await page.evaluate(async () => {
            window.__q_answer = async (question, context) => (context.key === 'first' ? true : 'a string');
            await $('#rsx_question_mount_temp').component().submit();
            return { calls: window.__q_calls };
        });

        if (accumulated.calls.length !== 3) {
            fail('(c) expected three calls, made ' + accumulated.calls.length);
        } else {
            const answers = accumulated.calls[2].body._answers || {};
            if (answers.first !== true || answers.second !== 'a string') {
                fail('(c) the third call did not carry both answers: ' + JSON.stringify(answers));
            } else {
                pass('a second question carries the first answer forward');
            }
        }

        // --- d. an endpoint that never stops asking ends in a rendered error ---
        await arm(page, [], true);
        const runaway = await page.evaluate(async () => {
            window.__q_answer = async () => true;
            const result = await $('#rsx_question_mount_temp').component().submit();
            return {
                result: result,
                calls: window.__q_calls.length,
                error_text: $('#rsx_question_errors_temp').text(),
                max: Rsx_Form.MAX_QUESTION_ROUNDS,
            };
        });

        if (runaway.result !== false) {
            fail('(d) a runaway question loop must resolve false');
        } else if (runaway.calls !== runaway.max + 1) {
            fail('(d) expected ' + (runaway.max + 1) + ' calls before the cap, made ' + runaway.calls);
        } else if (!runaway.error_text.includes('Rsx_Form_Question_Probe_Controller_Temp')) {
            fail('(d) the rendered error does not name the endpoint: "' + runaway.error_text.trim() + '"');
        } else {
            pass('MAX_QUESTION_ROUNDS ends a runaway loop with an error naming the endpoint');
        }

        // --- e. no handler anywhere is a configuration fault that escapes ---
        await arm(page, [{ question: { key: 'k', question: { kind: 'confirm' } } }]);
        const unhandled = await page.evaluate(async () => {
            const form = $('#rsx_question_mount_temp').component();
            const form_handler = form.question_handler;
            const global_handler = Rsx_Form._question_handler;

            form.question_handler = null;
            Rsx_Form.set_question_handler(null);

            let message = null;
            let resolved;
            try {
                resolved = await form.submit();
            } catch (e) {
                message = e && e.message ? e.message : String(e);
            }

            form.question_handler = form_handler;
            Rsx_Form.set_question_handler(global_handler);

            return { message: message, resolved: resolved, error_text: $('#rsx_question_errors_temp').text().trim() };
        });

        if (unhandled.message === null) {
            fail('(e) a question with no handler must throw, but the submit resolved '
                + JSON.stringify(unhandled.resolved));
        } else if (!unhandled.message.includes('set_question_handler')) {
            fail('(e) the thrown message does not name the registration call: ' + unhandled.message);
        } else if (unhandled.error_text !== '') {
            fail('(e) the configuration fault was rendered in the form instead of escaping');
        } else {
            pass('a question with no handler throws, naming set_question_handler()');
        }

        await page.evaluate(() => {
            Ajax.call = window.__q_original_ajax_call;
            $('#rsx_question_mount_temp').remove();
        });
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Rsx_Form question round trip');
    } else {
        console.log('PASS: Rsx_Form question round trip');
    }
}

run();
