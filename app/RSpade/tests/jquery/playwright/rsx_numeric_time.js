#!/usr/bin/env node
/**
 * $(input).rsx_numeric({time: true}) - the time-entry mode of the numeric field filter.
 *
 * The mode adds a colon to what may be typed and converts it to decimal hours at the
 * three moments the value leaves the user's hands: the getter, the setter and blur. That
 * split is the whole subject here, and it is only observable in a real document - a value
 * read while the box is focused still carries the colon the user typed, while .val()
 * answers the number at the same instant.
 *
 * What each case proves:
 *
 *   a. A colon value blurs to decimal hours, and .val() is that number.
 *   b. The hour may be omitted, and minutes at or past 60 roll into hours.
 *   c. A minute that is not a clean fraction of an hour is rounded to two decimals.
 *   d. .val('2:15') sanitises through the same conversion the keyboard goes through.
 *   e. '.' and ':' are mutually exclusive: whichever is typed first wins.
 *   f. At most two digits follow the colon.
 *   g. While the field is FOCUSED the colon form stays as typed, so it can be edited -
 *      and .val() answers the converted number at that same moment.
 *   h. decimals is not the caller's to set in time mode - two places either way.
 *   i. An empty box is still the empty string.
 *
 * Everything it touches is the framework's: the page is the control panel at /_sys and
 * the subject is a jQuery extension in Core/Js, with the probe input created at runtime
 * in the browser. No application screen, component or bundle is involved.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so it
 * runs with a bare `node rsx_numeric_time.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const PROBE = '#rsx_numeric_time_probe_temp';

function fail(msg) {
    console.log('FAIL: rsx_numeric_time - ' + msg);
    process.exitCode = 1;
}

function check(label, actual, expected) {
    if (actual === expected) {
        console.log('PASS: ' + label);
        return true;
    }
    fail(label + ' - expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
    return false;
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

/** A fresh empty text input carrying the filter, replacing any previous probe. */
async function mount(page, options) {
    await page.evaluate((opts) => {
        $('#rsx_numeric_time_probe_temp').remove();
        $('body').append('<input type="text" id="rsx_numeric_time_probe_temp" />');
        $('#rsx_numeric_time_probe_temp').rsx_numeric(opts);
    }, options);
}

/** What the user sees, and what a caller reading the element gets. */
async function read(page) {
    return await page.evaluate(() => ({
        display: document.querySelector('#rsx_numeric_time_probe_temp').value,
        val: $('#rsx_numeric_time_probe_temp').val(),
    }));
}

/** Type a value into a fresh probe and leave the field. */
async function type_and_blur(page, options, text) {
    await mount(page, options);
    await page.focus(PROBE);
    await page.keyboard.type(text);
    await page.evaluate(() => document.querySelector('#rsx_numeric_time_probe_temp').blur());
    return await read(page);
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

    const TIME = { time: true };

    try {
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const ready = await page.evaluate(() => typeof $ !== 'undefined' && typeof $.fn.rsx_numeric === 'function');
        if (!ready) {
            fail('$.fn.rsx_numeric is not defined on the page - the helpers did not load');
            await browser.close();
            return;
        }

        // --- a. hours:minutes becomes decimal hours ------------------------------
        let state = await type_and_blur(page, TIME, '1:30');
        check('1:30 blurs to the decimal form', state.display, '1.5');
        check('and val() is decimal hours', state.val, '1.5');

        state = await type_and_blur(page, TIME, '1:');
        check('a colon with no minutes is a whole hour', state.display, '1');
        check('and val() has no fraction to show', state.val, '1');

        // --- b. the hour is optional, and minutes roll over ----------------------
        state = await type_and_blur(page, TIME, ':30');
        check(':30 is half an hour', state.display, '0.5');
        check('and val() agrees', state.val, '0.5');

        state = await type_and_blur(page, TIME, '0:30');
        check('0:30 is the same half hour', state.display, '0.5');

        state = await type_and_blur(page, TIME, ':90');
        check(':90 rolls ninety minutes into an hour and a half', state.display, '1.5');

        state = await type_and_blur(page, TIME, '1:63');
        check('1:63 rolls the sixty-third minute into the hour', state.display, '2.05');
        check('and val() follows', state.val, '2.05');

        // --- c. rounding to two decimals ------------------------------------------
        state = await type_and_blur(page, TIME, '1:20');
        check('a third of an hour rounds to two decimals', state.display, '1.33');
        check('and val() is the rounded number', state.val, '1.33');

        state = await type_and_blur(page, TIME, '2:15');
        check('2:15 is a quarter past', state.display, '2.25');

        // --- d. the setter runs the same conversion -------------------------------
        await mount(page, TIME);
        await page.evaluate(() => $('#rsx_numeric_time_probe_temp').val('2:15'));
        state = await read(page);
        check('val(\'2:15\') displays the decimal form', state.display, '2.25');
        check('and reads back as decimal hours', state.val, '2.25');

        // --- e. '.' and ':' are mutually exclusive ---------------------------------
        await mount(page, TIME);
        await page.focus(PROBE);
        await page.keyboard.type('1.5:2');
        check('a colon after a decimal point is dropped', (await read(page)).display, '1.52');

        await mount(page, TIME);
        await page.focus(PROBE);
        await page.keyboard.type('1:3.');
        check('a decimal point after a colon is dropped', (await read(page)).display, '1:3');

        state = await type_and_blur(page, TIME, '1.5');
        check('a decimal typed in time mode is already decimal hours', state.display, '1.5');
        check('and is left alone', state.val, '1.5');

        // --- f. at most two digits after the colon ---------------------------------
        await mount(page, TIME);
        await page.focus(PROBE);
        await page.keyboard.type('1:456');
        check('minutes are capped at two digits', (await read(page)).display, '1:45');

        // --- g. focused, the colon form stays as typed -----------------------------
        await mount(page, TIME);
        await page.focus(PROBE);
        await page.keyboard.type('1:30');
        state = await read(page);
        check('the colon survives while the field is focused', state.display, '1:30');
        check('and val() answers the number at that same moment', state.val, '1.5');

        // --- h. decimals is not the caller's to set in time mode -------------------
        state = await type_and_blur(page, { time: true, decimals: 0 }, '1:20');
        check('decimals 0 does not flatten a time value', state.display, '1.33');
        check('and val() still carries both places', state.val, '1.33');

        // --- i. empty is '' ---------------------------------------------------------
        await mount(page, TIME);
        state = await read(page);
        check('an untouched time box displays nothing', state.display, '');
        check('and reads as the empty string', state.val, '');

        await page.evaluate(() => { $('#rsx_numeric_time_probe_temp').remove(); });
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: rsx_numeric_time');
    } else {
        console.log('PASS: rsx_numeric_time');
    }
}

run();
