#!/usr/bin/env node
/**
 * Enter in a text input submits the form - it does NOT navigate the page.
 *
 * THE REGRESSION THIS EXISTS FOR. <Define:Rsx_Form tag="form"> makes the component a real
 * <form> element, so the browser's IMPLICIT SUBMISSION applies: a form whose only field is a
 * single-line text input submits on Enter with no button involved at all. Rsx_Form wired
 * button[type="submit"] clicks and nothing else, so that submission ran its DEFAULT action
 * and the browser navigated - in a modal the dialog vanished with everything typed into it,
 * on a full page the action was re-dispatched and the form came back blank. Reported from a
 * downstream field report on 2026-09-08 as reproducible in every form in the application.
 *
 * The fix binds the form element's own submit event. So this test presses a REAL Enter key
 * through the browser, because the bug lives in the browser's own submission machinery and a
 * synthetic jQuery .trigger('submit') would exercise none of it.
 *
 * Two assertions, and the second is the one that catches the regression:
 *
 *   1. submit() was called - Enter must SUBMIT, not merely be swallowed. preventDefault()
 *      alone would stop the navigation and leave Enter doing nothing, which is its own bug.
 *   2. The page did not navigate - a sentinel planted on window before the keypress is still
 *      there afterwards, and Playwright saw no frame navigation. A navigation destroys the
 *      document, so a surviving sentinel is proof the default action never ran.
 *
 * NO SUBMIT BUTTON is placed in the probe form, deliberately. With one present the browser
 * activates the BUTTON instead, which the pre-existing click wiring already intercepted -
 * that is precisely why the bug hid for so long. One text input and nothing else is the
 * shape that reaches implicit submission, and it is the shape of the one-field dialog where
 * the data loss was worst.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys and the
 * mounted component is Rsx_Form; the probe form and its input are created at runtime in the
 * browser, so no application screen, modal or bundle is involved and it runs in any install.
 * A test-tree fixture page could not serve this: the test trees enter the manifest only while
 * rsx:test runs, and this script drives the ordinary web server.
 *
 * submit() is replaced on the INSTANCE with a recorder - the probe names no real endpoint,
 * and what is under test is whether the pipeline is REACHED, not what it then does.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js (the node
 * twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node form_enter_submits.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Enter submits an Rsx_Form - ' + msg);
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

/**
 * Build the probe: a REAL <form> element carrying an Rsx_Form component and exactly one
 * text input. Mounted on a <form> rather than a <div> on purpose - the browser only performs
 * implicit submission for an actual form element, which is what the component renders as
 * when it is invoked from a template.
 */
async function build_probe(page) {
    return await page.evaluate(async () => {
        $('body').append('<form id="rsx_form_enter_mount_temp"></form>');
        $('#rsx_form_enter_mount_temp').component('Rsx_Form', {
            controller: 'Rsx_Form_Enter_Probe_Controller_Temp',
            method: 'never_called',
        });

        await sleep(300);

        const form = $('#rsx_form_enter_mount_temp').component();
        if (!form) {
            return { mounted: false };
        }

        // The pipeline is replaced by a recorder: the probe names no real endpoint, and the
        // question is whether Enter REACHES submit() at all.
        window.__rsx_form_enter_submit_calls = 0;
        form.submit = function () {
            window.__rsx_form_enter_submit_calls++;
            return Promise.resolve(false);
        };

        // One single-line text input, no submit button - the shape that reaches implicit
        // submission (see the header).
        $('#rsx_form_enter_mount_temp').append(
            '<input type="text" id="rsx_form_enter_input_temp" value="probe" />'
        );

        // Proof-of-life for the document itself. A navigation replaces the document and
        // takes this with it.
        window.__rsx_form_enter_sentinel = 'alive';

        return {
            mounted: true,
            is_form_element: $('#rsx_form_enter_mount_temp').prop('tagName') === 'FORM',
        };
    });
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

        const probe = await build_probe(page);
        if (!probe.mounted) {
            fail('the probe form never produced an Rsx_Form instance');
            await browser.close();
            return;
        }
        if (!probe.is_form_element) {
            fail('the probe mount is not a <form> element - implicit submission cannot be reached');
            await browser.close();
            return;
        }

        // Navigations from THIS point on are the failure being tested for. Watched from the
        // Playwright side as well as through the in-page sentinel, because the two catch it
        // differently: the sentinel proves the document survived, this proves nothing
        // navigated and came back.
        const navigations = [];
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                navigations.push(frame.url());
            }
        });

        // A REAL keypress, delivered by the browser to a focused text input.
        await page.focus('#rsx_form_enter_input_temp');
        await page.keyboard.press('Enter');

        // A NAVIGATION DESTROYS THE EXECUTION CONTEXT, so reading the sentinel back is
        // itself the assertion: when the regression is present this throws rather than
        // returning, and the throw must be reported as the navigation it is - not as an
        // incidental harness error.
        let after;
        try {
            await page.waitForTimeout(500);
            after = await page.evaluate(() => ({
                sentinel: window.__rsx_form_enter_sentinel || null,
                submit_calls: window.__rsx_form_enter_submit_calls,
                still_mounted: $('#rsx_form_enter_mount_temp').length > 0,
            }));
        } catch (e) {
            after = { sentinel: null, submit_calls: 0, still_mounted: false, destroyed: true };
        }

        // --- 1. the page did not navigate ---
        if (after.sentinel !== 'alive' || !after.still_mounted || navigations.length > 0) {
            fail('Enter NAVIGATED the page - the form element\'s submit event is unbound, so the '
                + 'browser ran the default action and the form, with everything typed into it, '
                + 'is gone (' + (after.destroyed
                    ? 'the execution context was destroyed by the navigation'
                    : 'sentinel: ' + after.sentinel + ', form still mounted: ' + after.still_mounted
                      + ', navigations: ' + navigations.length) + ')');
        } else {
            console.log('PASS: Enter in a text input does not navigate the page');
        }

        // --- 2. Enter reached the submission pipeline ---
        if (!after.submit_calls) {
            fail('Enter did not reach submit() - a bare preventDefault() stops the navigation '
                + 'but leaves Enter doing nothing, which is its own bug');
        } else if (after.submit_calls !== 1) {
            fail('Enter reached submit() ' + after.submit_calls + ' times - it must submit exactly once');
        } else {
            console.log('PASS: Enter in a text input submits the form exactly once');
        }

        // Best-effort teardown: on the failing path the document this belonged to is gone.
        try {
            await page.evaluate(() => { $('#rsx_form_enter_mount_temp').remove(); });
        } catch (e) { /* the navigation already took it */ }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Enter submits an Rsx_Form');
    } else {
        console.log('PASS: Enter submits an Rsx_Form');
    }
}

run();
