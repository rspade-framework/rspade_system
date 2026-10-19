#!/usr/bin/env node
/**
 * Rsx_Form.dirty() and the 'dirty' event: unsaved changes are CHANGED values, not touched ones.
 *
 * Eight cases, on a real form:
 *
 *   a. a seeded form is clean, and announces nothing;
 *   b. a user edit makes it dirty and fires 'dirty' (true);
 *   c. typing the value back makes it clean and fires 'dirty' (false);
 *   d. programmatic writes - vals(object), input.val(v) - never make it dirty;
 *   e. vals(object) still skips a field the user typed into, even typed back (keystrokes win);
 *   f. an input given a value before it is ready reads unchanged once it is ready;
 *   g. a user value restored to an input before it is ready (parking) reads as changed;
 *   h. a successful submit re-baselines: 'dirty' (false) fires BEFORE 'submitted'.
 *
 * "Not ready" is produced by clearing the instance's ready flag: the panel's text input marks
 * itself ready during its first render, so there is no earlier moment to reach from outside,
 * and what is under test is the base class's bookkeeping.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * form is the panel's own site-rename form, mounted at runtime with a made-up site. The
 * submit in (h) answers through a stubbed Ajax.call, so nothing is renamed.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Rsx_Form.dirty() - ' + msg);
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

        const result = await page.evaluate(async () => {
            $('body').append('<div id="rsx_dirty_mount_temp"></div>');
            $('#rsx_dirty_mount_temp').component('_Sys_Site_Rename_Form', {
                site: { id: 999999, name: 'Probe Site', slug: 'probe-site', field_lengths: { name: 100, slug: 100 } },
            });
            await sleep(500);

            const form = $('#rsx_dirty_mount_temp').find('.Rsx_Form').first().component();
            const events = [];
            const order = [];
            form.on('dirty', (f, dirty) => { events.push(dirty); order.push('dirty:' + dirty); });
            form.on('submitted', () => order.push('submitted'));

            const name = form.input('name');
            const slug = form.input('slug');
            const type = (input, value) => input.$.find('input').val(value).trigger('input');
            const out = {};

            // a
            out.a_clean = form.dirty() === false && events.length === 0;

            // b
            type(name, 'Probe Site edited');
            out.b_dirty = form.dirty() === true && JSON.stringify(events) === '[true]';

            // c
            type(name, 'Probe Site');
            out.c_clean = form.dirty() === false && JSON.stringify(events) === '[true,false]';

            // d
            form.vals({ slug: 'programmatic-slug' });
            slug.val('another-slug');
            out.d_clean = form.dirty() === false && events.length === 2 && slug.val() === 'another-slug';

            // e - name was typed into (and back): data does not overwrite it
            form.vals({ name: 'From data' });
            out.e_kept = name.val() === 'Probe Site';

            // f
            slug._is_ready = false;
            slug.val('buffered-slug');
            out.f_before = slug.is_changed() === false;
            slug._mark_ready();
            out.f_after = slug.is_changed() === false && slug.val() === 'buffered-slug' && form.dirty() === false;

            // g
            slug._is_ready = false;
            slug._restore_user_value('restored-slug');
            out.g_before = slug.is_changed() === true && slug.val() === 'restored-slug';
            slug._mark_ready();
            out.g_after = slug.is_changed() === true && slug.val() === 'restored-slug' && form.dirty() === true;

            // h
            const real_call = Ajax.call;
            Ajax.call = async () => ({ ok: true });
            try {
                order.length = 0;
                const submitted = await form.submit();
                out.h_result = !!submitted;
            } finally {
                Ajax.call = real_call;
            }
            out.h_order = order.join(',');
            out.h_clean = form.dirty() === false;

            $('#rsx_dirty_mount_temp').remove();
            return out;
        });

        const checks = [
            ['a_clean', 'a seeded form is clean and announces nothing'],
            ['b_dirty', "a user edit makes the form dirty and fires 'dirty' (true)"],
            ['c_clean', "typing the value back makes it clean and fires 'dirty' (false)"],
            ['d_clean', 'programmatic writes never make the form dirty'],
            ['e_kept', 'vals(object) skips a field the user typed into, even typed back'],
            ['f_before', 'a buffered value on an input that is not ready reads unchanged'],
            ['f_after', 'the buffered value is the baseline once the input is ready'],
            ['g_before', 'a restored user value reads as changed before readiness'],
            ['g_after', 'and still reads as changed once the input is ready'],
            ['h_result', 'the stubbed submit succeeds'],
            ['h_clean', 'a successful submit leaves the form clean'],
        ];
        for (const [key, label] of checks) {
            if (result[key]) {
                console.log('PASS: ' + label);
            } else {
                fail(label + ' (' + JSON.stringify(result) + ')');
            }
        }

        if (result.h_order !== 'dirty:false,submitted') {
            fail("a successful submit fires 'dirty' (false) before 'submitted' - got " + JSON.stringify(result.h_order));
        } else {
            console.log("PASS: 'dirty' (false) fires before 'submitted'");
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Rsx_Form.dirty()');
    } else {
        console.log('PASS: Rsx_Form.dirty()');
    }
}

run();
