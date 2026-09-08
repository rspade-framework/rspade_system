#!/usr/bin/env node
/**
 * Dynamic .component() creation from inside a render cascade.
 *
 * The claim under test is the one Rsx_Form._sync_loading_overlay() used to defer around:
 * that a component instantiated on a hand-appended node, from inside an ancestor's INITIAL
 * render pass, comes back as an inert stub that never upgrades. It must either WORK or fail
 * loudly - a silently inert component is the unacceptable outcome.
 *
 * Three probes, all in a real browser because the cascade only exists there:
 *
 *   1. SYNTHETIC: a component registered at runtime appends a node in its own on_render()
 *      and mounts a component on it. The instance must be live and its template painted.
 *   2. CANONICAL: the shape Rsx_Form._sync_loading_overlay() used to defer around - a
 *      component mounts an Rsx_Form on a hand-appended node from inside its OWN first
 *      render pass and arms the loading overlay there. The overlay instantiates the
 *      registered spinner on a node it has just created, two cascades deep. The spinner
 *      must be painted, with no deferral anywhere.
 *   3. A component name that is registered NOWHERE still resolves to the base component
 *      class - documented behavior, asserted here so a change to it is a visible failure.
 *
 * A template-only component (no companion .js) legitimately reports _Jqhtml_Component as its
 * constructor: the base class drives the registered template. Constructor identity is
 * therefore NOT the test - painted output is.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys, the
 * mounted components are Rsx_Form and the registered default spinner, and the probe
 * components are registered at runtime in the browser - so no application screen, modal or
 * bundle is involved and the probe runs in any install. A test-tree fixture page could not
 * serve this: the test trees enter the manifest only while rsx:test is running, and this
 * script drives the ordinary web server.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node dynamic_component_in_render_cascade.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: dynamic component in render cascade - ' + msg);
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
 * Probe 1 - a component mounted on a node appended during the mounting component's own
 * first render pass.
 */
async function probe_synthetic(page) {
    return await page.evaluate(async () => {
        const observed = {};

        class Rsx_Cascade_Probe_Temp extends Component {
            on_render() {
                this.$.append('<div class="rsx_cascade_probe_slot_temp"></div>');
                const $slot = this.$.find('.rsx_cascade_probe_slot_temp');
                $slot.component('Rsx_Default_Spinner');
                observed.instance_during_render = !!$slot.component();
            }
        }

        jqhtml.register_component('Rsx_Cascade_Probe_Temp', Rsx_Cascade_Probe_Temp);

        $('body').append('<div id="rsx_cascade_probe_mount_temp"></div>');
        $('#rsx_cascade_probe_mount_temp').component('Rsx_Cascade_Probe_Temp');

        await sleep(500);

        const $slot = $('#rsx_cascade_probe_mount_temp').find('.rsx_cascade_probe_slot_temp');
        observed.instance_after = !!$slot.component();
        observed.painted = $slot.find('.Rsx_Default_Spinner__circle').length;
        observed.classes = $slot.attr('class') || '';

        $('#rsx_cascade_probe_mount_temp').remove();

        return observed;
    });
}

/**
 * Probe 2 - the canonical case: a component mounts an Rsx_Form from inside its own first
 * render pass and arms the form's loading overlay there, so the overlay's spinner is
 * instantiated two cascades deep. Sample the overlay the moment it exists.
 *
 * The spinner is whatever Rsx.get_default_spinner() names - the framework's own unless the
 * application registered one - so the assertion is that the host carries a live component
 * that painted SOMETHING, never that it is a particular class.
 */
async function probe_loading_overlay(page) {
    return await page.evaluate(async () => {
        const observed = { samples: [] };

        class Rsx_Cascade_Form_Probe_Temp extends Component {
            on_render() {
                this.$.append('<div class="rsx_cascade_form_slot_temp"></div>');
                const $slot = this.$.find('.rsx_cascade_form_slot_temp');

                // Mounting a framework component on a node appended during THIS render, then
                // driving it - the overlay it draws mounts a component of its own.
                $slot.component('Rsx_Form', {
                    controller: 'Rsx_Cascade_Probe_Controller_Temp',
                    method: 'never_called',
                });

                const form = $slot.component();
                if (form && form.set_loading) {
                    form.set_loading(true);
                }
            }
        }

        jqhtml.register_component('Rsx_Cascade_Form_Probe_Temp', Rsx_Cascade_Form_Probe_Temp);

        $('body').append('<div id="rsx_cascade_form_mount_temp"></div>');
        $('#rsx_cascade_form_mount_temp').component('Rsx_Cascade_Form_Probe_Temp');

        for (let i = 0; i < 100; i++) {
            const $overlay = $('#rsx_cascade_form_mount_temp').find('.Rsx_Form__loading');
            if ($overlay.length) {
                const $host = $overlay.find('.Rsx_Form__loading-spinner').first();
                observed.samples.push({
                    has_spinner_instance: !!$host.component(),
                    painted_children: $host.children().length,
                    host_classes: $host.attr('class') || '',
                });
                if (observed.samples.length >= 3) {
                    break;
                }
            }
            await sleep(20);
        }

        $('#rsx_cascade_form_mount_temp').remove();

        return observed;
    });
}

/** Probe 3 - an entirely unregistered name. */
async function probe_unregistered(page) {
    return await page.evaluate(async () => {
        $('body').append('<div id="rsx_unregistered_probe_temp"></div>');
        $('#rsx_unregistered_probe_temp').component('Rsx_No_Such_Component_Temp');
        await sleep(200);

        const instance = $('#rsx_unregistered_probe_temp').component();
        const result = { has_instance: !!instance };

        $('#rsx_unregistered_probe_temp').remove();

        return result;
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

    const console_errors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            console_errors.push(msg.text());
        }
    });

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

        // --- 1. synthetic cascade mount ---
        const synthetic = await probe_synthetic(page);

        if (!synthetic.instance_during_render || !synthetic.instance_after) {
            fail('no component instance on a node appended during the mounting component\'s own render pass');
        } else if (synthetic.painted !== 1) {
            fail('component mounted inside the render cascade never painted its template (classes: "' + synthetic.classes + '")');
        } else {
            console.log('PASS: a component mounted from inside a render cascade paints its template');
        }

        // --- 2. the canonical loading overlay ---
        const overlay = await probe_loading_overlay(page);

        if (overlay.samples.length === 0) {
            fail('the probe component never armed the form loading overlay');
        } else if (!overlay.samples[0].has_spinner_instance || overlay.samples[0].painted_children === 0) {
            fail('the loading overlay carried no painted spinner at its first observed moment '
                + '(host classes: "' + overlay.samples[0].host_classes + '") - the spinner is instantiated '
                + 'from the overlay the form draws inside the initial render cascade');
        } else {
            console.log('PASS: the form loading overlay paints its spinner with no deferral');
        }

        // --- 3. an unregistered name ---
        const unregistered = await probe_unregistered(page);

        if (!unregistered.has_instance) {
            fail('an unregistered component name produced no instance at all');
        } else {
            console.log('PASS: an unregistered component name resolves to the base component class');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: dynamic component in render cascade');
    } else {
        console.log('PASS: dynamic component in render cascade');
    }
}

run();
