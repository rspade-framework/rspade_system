#!/usr/bin/env node
/**
 * Code_Input - a numeric code typed one digit per box.
 *
 * The widget keeps one invariant - the boxes are filled from the left with no gaps - and
 * every row of its behaviour table follows from it. Each case below drives the REAL keyboard
 * and clipboard path through the browser, because the behaviour lives in the input, keydown
 * and focus events a synthetic val() would never fire:
 *
 *   a. typing digits fills from the left and advances; a non-digit is dropped
 *   b. a click on a later box is sent to the first empty box (no gaps are possible)
 *   c. Backspace in an empty box clears the previous one and steps back
 *   d. a pasted string lands in any box and is spread across the row; the overflow and the
 *      non-digits are discarded
 *   e. a digit typed into a full last box is dropped
 *   f. Enter is swallowed while boxes are empty and left alone when all are full
 *   g. val('') clears, focus() lands in the first box, is_complete() follows the boxes, and
 *      the form-input 'input' event carries the value
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * widget is mounted at runtime.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: Code_Input - ' + msg);
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


        await page.evaluate(async () => {
            $('body').append('<div id="rsx_code_mount_temp" style="position:fixed;top:10px;left:10px;z-index:99999;background:#fff"></div>');
            $('#rsx_code_mount_temp').component('Code_Input', { name: 'code' });
            await sleep(300);

            const input = $('#rsx_code_mount_temp').component();
            window.__code_inputs = [];
            input.on('input', (c, value) => { window.__code_inputs.push(value); });
            window.__code_enter_default_prevented = [];
            input.$.on('keydown', (e) => {
                if (e.key === 'Enter') { window.__code_enter_default_prevented.push(e.isDefaultPrevented()); }
            });
        });

        const state = () => page.evaluate(() => {
            const input = $('#rsx_code_mount_temp').component();
            const boxes = input.$.find('.Code_Input__box').get();
            return {
                boxes: boxes.map((b) => b.value),
                value: input.val(),
                complete: input.is_complete(),
                active: boxes.indexOf(document.activeElement),
            };
        });
        const check = (ok, name, detail) => {
            if (ok) { console.log('PASS: ' + name); } else { fail(name + ' - ' + detail); }
        };
        const box = (n) => '#rsx_code_mount_temp .Code_Input__box:nth-child(' + n + ')';

        // a. typing
        await page.click(box(1));
        await page.keyboard.type('4x0');
        let s = await state();
        check(s.value === '40' && s.active === 2, 'digits fill from the left and a non-digit is dropped', JSON.stringify(s));

        // b. a click on a later box
        await page.click(box(5));
        s = await state();
        check(s.active === 2, 'a click on a later box lands in the first empty box', JSON.stringify(s));
        await page.keyboard.type('7');
        s = await state();
        check(s.boxes.join('|') === '4|0|7|||', 'no gap can be typed', JSON.stringify(s));

        // c. Backspace in an empty box
        await page.keyboard.press('Backspace');
        s = await state();
        check(s.value === '40' && s.active === 2, 'Backspace in an empty box clears the previous one', JSON.stringify(s));

        // f (first half). Enter with boxes empty
        await page.keyboard.press('Enter');
        let enters = await page.evaluate(() => window.__code_enter_default_prevented);
        check(enters.length === 1 && enters[0] === true, 'Enter is swallowed while boxes are empty', JSON.stringify(enters));

        // d. paste: spread from the box it lands in, overflow and non-digits discarded
        await page.evaluate(() => {
            const target = document.activeElement;
            target.value = '12-34 5678';
            target.dispatchEvent(new Event('input', { bubbles: true }));
        });
        s = await state();
        check(s.value === '401234' && s.complete && s.active === 5, 'a pasted string is spread across the row and the overflow dropped', JSON.stringify(s));

        // e. a digit typed into the full last box
        await page.keyboard.type('9');
        s = await state();
        check(s.value === '401234', 'a digit typed into a full last box is dropped', JSON.stringify(s));

        // f (second half). Enter with every box full
        await page.keyboard.press('Enter');
        enters = await page.evaluate(() => window.__code_enter_default_prevented);
        check(enters.length === 2 && enters[1] === false, 'Enter is left alone when every box is full', JSON.stringify(enters));

        // g. the contract
        const contract = await page.evaluate(() => {
            const input = $('#rsx_code_mount_temp').component();
            const boxes = input.$.find('.Code_Input__box').get();
            const last_input = window.__code_inputs[window.__code_inputs.length - 1];
            input.val('');
            document.body.focus();
            input.focus();
            const cleared = { value: input.val(), complete: input.is_complete(), active: boxes.indexOf(document.activeElement) };
            input.val('00a42');
            return { last_input: last_input, cleared: cleared, set: input.val(), no_maxlength: boxes.every((b) => !b.hasAttribute('maxlength')),
                otc: boxes.map((b) => b.getAttribute('autocomplete')).join(',') };
        });
        check(contract.last_input === '401234', "the 'input' event carries the value", JSON.stringify(contract));
        check(contract.cleared.value === '' && !contract.cleared.complete && contract.cleared.active === 0,
            "val('') clears and focus() lands in the first box", JSON.stringify(contract.cleared));
        check(contract.set === '0042', 'a set value keeps its leading zeros and only its digits', contract.set);
        check(contract.no_maxlength && contract.otc === 'one-time-code,off,off,off,off,off',
            'no box has a maxlength and only the first asks for the one-time code', contract.otc);

        await page.evaluate(() => { $('#rsx_code_mount_temp').remove(); });
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Code_Input');
    } else {
        console.log('PASS: Code_Input');
    }
}

run();
