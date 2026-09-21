#!/usr/bin/env node
/**
 * $(input).rsx_numeric() - the numeric field filter.
 *
 * The filter is a browser behaviour end to end: it reads the caret, rewrites the box
 * between keystrokes and answers .val() through a $.valHooks entry. None of that exists
 * outside a real document with a real focused input, and a synthetic .trigger('input')
 * carries no caret at all - so every case here types with the keyboard, presses a real
 * Backspace, and reads the box back exactly as a user would see it.
 *
 * What each case proves:
 *
 *   a. Characters the options do not allow never reach the box - with decimals 0 the
 *      decimal point is rejected like any other non-digit.
 *   b. A fraction longer than the option is truncated, not rounded, and .val() answers
 *      the truncated number.
 *   c. Separators and the prefix are written INLINE while the field is focused, the
 *      blur does not change what is displayed, .val() answers the raw number, and
 *      .val(number) displays it formatted. This is the whole raw-value contract.
 *   d. Backspace at the end is about what the user typed, not about what the filter
 *      wrote: over a formatting character it deletes the number's last character, and
 *      the group separator left dangling by a deletion goes with it.
 *   e. An empty box, and one cleared through .val(''), both answer ''.
 *   f. rsx_numeric(false) leaves an ordinary text input behind.
 *   g. Applying it twice reconfigures rather than double-binding.
 *   h. Text arriving in one piece - a paste - is filtered like typing, because the
 *      input event is what the filter listens to.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys and
 * the subject is a jQuery extension in Core/Js; the input is created at runtime in the
 * browser, so no application screen, component or bundle is involved and it runs in any
 * install. A test-tree fixture page could not serve this: the test trees enter the
 * manifest only while rsx:test runs, and this script drives the ordinary web server.
 *
 * Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so it
 * runs with a bare `node rsx_numeric.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const PROBE = '#rsx_numeric_probe_temp';

function fail(msg) {
    console.log('FAIL: rsx_numeric - ' + msg);
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
        $('#rsx_numeric_probe_temp').remove();
        $('body').append('<input type="text" id="rsx_numeric_probe_temp" />');
        $('#rsx_numeric_probe_temp').rsx_numeric(opts);
    }, options);
}

/** What the user sees, and what a caller reading the element gets. */
async function read(page) {
    return await page.evaluate(() => ({
        display: document.querySelector('#rsx_numeric_probe_temp').value,
        val: $('#rsx_numeric_probe_temp').val(),
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

        const ready = await page.evaluate(() => typeof $ !== 'undefined' && typeof $.fn.rsx_numeric === 'function');
        if (!ready) {
            fail('$.fn.rsx_numeric is not defined on the page - the helpers did not load');
            await browser.close();
            return;
        }

        // --- a. integers only -------------------------------------------------
        await mount(page, { decimals: 0 });
        await page.focus(PROBE);
        await page.keyboard.type('12a3.4');
        let state = await read(page);
        // Only the offending characters are dropped - the digits around them all stay,
        // so "12a3.4" is the number 1234 and not a value truncated at the first
        // rejection.
        check('decimals 0 rejects letters and the decimal point', state.display, '1234');
        check('decimals 0 val() is the typed digits', state.val, '1234');

        // --- b. the fraction is capped, and truncated ---------------------------
        await mount(page, { decimals: 2 });
        await page.focus(PROBE);
        await page.keyboard.type('1234.567');
        state = await read(page);
        check('decimals 2 truncates the third decimal', state.display, '1234.56');
        check('decimals 2 val() is the truncated number', state.val, '1234.56');

        // --- c. inline formatting, blur, and the raw-value contract -------------
        await mount(page, { decimals: 2, commas: true, prefix: '$' });
        await page.focus(PROBE);
        await page.keyboard.type('1234567.8');
        state = await read(page);
        check('separators and prefix are written while typing', state.display, '$1,234,567.8');
        check('val() is the raw number, no separators or prefix', state.val, '1234567.8');

        await page.evaluate(() => document.querySelector('#rsx_numeric_probe_temp').blur());
        state = await read(page);
        check('blur leaves the display formatted', state.display, '$1,234,567.8');
        check('blur leaves val() raw', state.val, '1234567.8');

        await page.evaluate(() => $('#rsx_numeric_probe_temp').val('9876543.21'));
        state = await read(page);
        check('val(number) displays it formatted', state.display, '$9,876,543.21');
        check('val() round-trips the number it was given', state.val, '9876543.21');

        // --- d. backspace at the end -------------------------------------------
        await mount(page, { decimals: 2, commas: true, prefix: '$' });
        await page.focus(PROBE);
        await page.keyboard.type('1234.');
        check('a trailing decimal point survives so the fraction can be typed',
            (await read(page)).display, '$1,234.');
        await page.keyboard.press('Backspace');
        state = await read(page);
        check('backspace over the decimal point takes the point', state.display, '$1,234');
        check('and val() follows', state.val, '1234');

        await page.keyboard.press('Backspace');
        state = await read(page);
        check('backspace over the last digit takes the separator with it', state.display, '$123');
        check('and val() follows', state.val, '123');

        // --- e. empty is '' ------------------------------------------------------
        await page.evaluate(() => $('#rsx_numeric_probe_temp').val(''));
        state = await read(page);
        check('val(\'\') clears the display', state.display, '');
        check('val(\'\') reads back as the empty string', state.val, '');

        await mount(page, { decimals: 2, commas: true, prefix: '$' });
        check('an untouched box reads as the empty string', (await read(page)).val, '');

        // --- f. removal ----------------------------------------------------------
        await mount(page, { decimals: 0, commas: true, prefix: '$' });
        await page.focus(PROBE);
        await page.keyboard.type('1234');
        check('the filter is active before removal', (await read(page)).display, '$1,234');

        await page.evaluate(() => $('#rsx_numeric_probe_temp').rsx_numeric(false));
        state = await read(page);
        check('removal leaves the raw number in the box', state.display, '1234');

        await page.focus(PROBE);
        await page.keyboard.press('End');
        await page.keyboard.type('abc');
        state = await read(page);
        check('with the filter off the box accepts anything', state.display, '1234abc');
        check('and .val() is the box, untouched by any hook', state.val, '1234abc');

        // --- g. applying it twice reconfigures ------------------------------------
        await mount(page, { decimals: 0 });
        const handlers = await page.evaluate(() => {
            const element = document.querySelector('#rsx_numeric_probe_temp');
            $(element).rsx_numeric({ decimals: 0, commas: true, prefix: '$' });

            const events = $._data(element, 'events') || {};
            const counts = {};
            Object.keys(events).forEach((name) => {
                counts[name] = events[name].filter((h) => h.namespace === 'rsx_numeric').length;
            });
            return counts;
        });
        const doubled = Object.keys(handlers).filter((name) => handlers[name] !== 1);
        check('two calls leave exactly one handler per event',
            doubled.length ? doubled.map((n) => n + '=' + handlers[n]).join(',') : 'one each',
            'one each');

        await page.focus(PROBE);
        await page.keyboard.type('1234');
        state = await read(page);
        check('the second call\'s options are the ones in force', state.display, '$1,234');
        check('and the value is written once, not twice', state.val, '1234');

        // --- h. a paste is filtered like typing ------------------------------------
        await mount(page, { decimals: 2, commas: true, prefix: '$' });
        await page.focus(PROBE);
        await page.keyboard.insertText('12x34567.891');
        state = await read(page);
        check('text arriving in one piece is filtered', state.display, '$1,234,567.89');
        check('and val() is the filtered number', state.val, '1234567.89');

        await page.evaluate(() => { $('#rsx_numeric_probe_temp').remove(); });
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: rsx_numeric');
    } else {
        console.log('PASS: rsx_numeric');
    }
}

run();
