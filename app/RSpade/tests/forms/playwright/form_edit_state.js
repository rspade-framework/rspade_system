#!/usr/bin/env node
/**
 * What Rsx_Form tells an endpoint about an edit, beside the values: `_changed` and
 * `_loaded_updated_at`, which Form_Edit acts on.
 *
 *   a. an untouched form reports nothing changed, and the version it loaded;
 *   b. a typed value is reported, and typing it back un-reports it;
 *   c. a programmatic val() is reported though it does not make the form dirty: it is a
 *      value the record does not hold, and an endpoint must write it;
 *   d. a value a before_submit hook altered is reported;
 *   e. a successful submit moves the snapshot and the version to what the server answered.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * form is the panel's own site-rename form, mounted at runtime with a made-up site. Each
 * submit answers through a stubbed Ajax.call_detailed, so nothing is renamed.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Rsx_Form edit state - ' + msg);
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
    // The browser's zone is already known, or the framework's first-visit reload lands in the
    // middle of the test.
    await context.addCookies([{ name: 'rsx_tz', value: 'UTC', url: BASE_URL }]);
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
            $('body').append('<div id="rsx_edit_state_mount_temp"></div>');
            $('#rsx_edit_state_mount_temp').component('_Sys_Site_Rename_Form', {
                site: { id: 999999, name: 'Probe Site', slug: 'probe-site', field_lengths: { name: 100, slug: 100 } },
            });
            await sleep(500);

            const form = $('#rsx_edit_state_mount_temp').find('.Rsx_Form').first().component();
            form.state.values.updated_at = '2026-11-02T01:02:03.456Z';

            const sent = [];
            let answer = {};
            const real_call = Ajax.call_detailed;
            Ajax.call_detailed = async (url, payload) => { sent.push(payload); return { value: answer, replayed: false, completed_at: null, duration_ms: null }; };

            const name = form.input('name');
            const slug = form.input('slug');
            const type = (input, value) => input.$.find('input').val(value).trigger('input');
            const last = () => sent[sent.length - 1];
            const out = {};

            // a
            await form.submit();
            out.a_nothing = JSON.stringify(last()._changed) === '[]';
            out.a_version = last()._loaded_updated_at === '2026-11-02T01:02:03.456Z';

            // b
            type(name, 'Probe Site edited');
            await form.submit();
            out.b_typed = JSON.stringify(last()._changed) === '["name"]';
            answer = { updated_at: '2026-11-02T01:02:04.000Z' };
            await form.submit();

            // e
            await form.submit();
            out.e_snapshot = JSON.stringify(last()._changed) === '[]';
            out.e_version = last()._loaded_updated_at === '2026-11-02T01:02:04.000Z';

            // c
            slug.val('derived-slug');
            out.c_not_dirty = form.dirty() === false;
            answer = {};
            form.before_submit = null;
            const before_snapshot = form.input('slug').is_changed_from_record();
            out.c_reported = before_snapshot === true;

            // d
            const fresh = sent.length;
            answer = {};
            form.before_submit = (values) => Object.assign({}, values, { extra_value: 'set by the host' });
            await form.submit();
            out.d_hook = sent.length > fresh && last()._changed.includes('extra_value');

            Ajax.call_detailed = real_call;
            $('#rsx_edit_state_mount_temp').remove();
            return out;
        });

        for (const [check, passed] of Object.entries(result)) {
            if (!passed) {
                fail(check);
            }
        }

        if (!process.exitCode) {
            console.log('PASS: Rsx_Form edit state');
        }
    } catch (error) {
        fail(error.message);
    }

    await browser.close();
}

run();
