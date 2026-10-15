#!/usr/bin/env node
/**
 * Button_Utils.set_loader() - the registered busy-state loader.
 *
 * Button_Utils is the busy-state engine behind $.fn.click_async(), Rsx_Form's submit buttons
 * and so Modal.form(). An application with a busy-state look of its own registers it once with
 * Button_Utils.set_loader(renderer); the framework keeps every other part of the busy cycle.
 * That split is what this file proves, in a real browser, because the size lock reads the
 * rendered box:
 *
 *   a. With nothing registered, set_submitting() draws the framework spinner.
 *   b. A registered renderer is called with the element and its markup - a string, or a
 *      jQuery - is what the element holds while busy. The btn--submitting class and the
 *      size lock (inline width/height equal to the box before the swap, border-box) are
 *      applied exactly as with the framework spinner.
 *   c. clear_submitting() restores the original content, removes the class and unlocks the
 *      size, whatever the loader was. Both calls return the element (chaining).
 *   d. The renderer is read at set_submitting() time: .click_async() uses the one
 *      registered after the handler was bound.
 *   e. set_loader(null) restores the framework spinner.
 *   f. Anything but a function or null throws, and so does a renderer that returns neither
 *      a string nor a jQuery - the element is left untouched.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys and the
 * subject is Core/Ui/Button_Utils.js; the button is created at runtime in the browser, so no
 * application screen is involved and it runs in any install. Every case leaves the loader
 * unregistered again, so nothing leaks to later cases.
 *
 * Self-contained: runs with a bare `node button_utils_loader.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: button_utils_loader - ' + msg);
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

/** A fresh button with known content, replacing any previous probe. */
async function mount(page) {
    await page.evaluate(() => {
        $('#button_utils_probe_temp').remove();
        $('body').append('<button type="button" class="btn btn-primary" id="button_utils_probe_temp"><b>Save</b> changes</button>');
    });
}

/** Everything the busy cycle owns on the probe. */
async function read(page) {
    return await page.evaluate(() => {
        const el = document.querySelector('#button_utils_probe_temp');
        return {
            html: el.innerHTML,
            submitting: $(el).hasClass('btn--submitting'),
            width: el.style.width,
            height: el.style.height,
            box_sizing: el.style.boxSizing,
        };
    });
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const ready = await page.evaluate(() => typeof Button_Utils !== 'undefined' && typeof Button_Utils.set_loader === 'function');
        if (!ready) {
            fail('Button_Utils.set_loader is not defined on the page');
            return;
        }

        // --- a. the framework spinner by default ---------------------------------
        await mount(page);
        const original = (await read(page)).html;
        await page.evaluate(() => Button_Utils.set_submitting($('#button_utils_probe_temp')));
        let state = await read(page);
        check('with nothing registered the framework spinner is drawn',
            state.html, '<span class="Button_Utils__spinner" aria-hidden="true"></span>');
        await page.evaluate(() => Button_Utils.clear_submitting($('#button_utils_probe_temp')));

        // --- b. a registered string renderer ---------------------------------------
        await mount(page);
        const box = await page.evaluate(() => {
            const rect = document.querySelector('#button_utils_probe_temp').getBoundingClientRect();
            return { width: rect.width, height: rect.height };
        });
        const returned = await page.evaluate(() => {
            window.__button_utils_seen = null;
            Button_Utils.set_loader(($el) => {
                window.__button_utils_seen = $el.attr('id') + '|' + $el.text();
                return '<span class="probe__loader"><i></i><i></i><i></i></span>';
            });
            const $btn = $('#button_utils_probe_temp');
            return Button_Utils.set_submitting($btn) === $btn;
        });
        state = await read(page);
        check('set_submitting() returns the element', returned, true);
        check('the renderer is handed the element, untouched',
            await page.evaluate(() => window.__button_utils_seen), 'button_utils_probe_temp|Save changes');
        check('the renderer\'s markup is what the element holds',
            state.html, '<span class="probe__loader"><i></i><i></i><i></i></span>');
        check('btn--submitting is applied', state.submitting, true);
        // The inline style round-trips the box at the browser's own precision, so the
        // comparison is to the hundredth of a pixel.
        check('the width is locked to the box before the swap',
            Math.abs(parseFloat(state.width) - box.width) < 0.01 && state.width.endsWith('px'), true);
        check('the height is locked to the box before the swap',
            Math.abs(parseFloat(state.height) - box.height) < 0.01 && state.height.endsWith('px'), true);
        check('the lock is border-box', state.box_sizing, 'border-box');

        // Idempotence: a second call neither re-renders nor re-stashes the loader as content.
        await page.evaluate(() => {
            Button_Utils.set_loader(() => '<span class="probe__second"></span>');
            Button_Utils.set_submitting($('#button_utils_probe_temp'));
        });
        check('a second set_submitting() is a no-op',
            (await read(page)).html, '<span class="probe__loader"><i></i><i></i><i></i></span>');

        // --- c. clear restores, whatever the loader was ------------------------------
        const cleared_returned = await page.evaluate(() => {
            const $btn = $('#button_utils_probe_temp');
            return Button_Utils.clear_submitting($btn) === $btn;
        });
        state = await read(page);
        check('clear_submitting() returns the element', cleared_returned, true);
        check('the original content is restored', state.html, original);
        check('btn--submitting is removed', state.submitting, false);
        check('the width is unlocked', state.width, '');
        check('the height is unlocked', state.height, '');
        check('box-sizing is unlocked', state.box_sizing, '');

        // A jQuery is accepted as well as a string.
        await mount(page);
        await page.evaluate(() => {
            Button_Utils.set_loader(() => $('<span class="probe__jq"></span>').text('busy'));
            Button_Utils.set_submitting($('#button_utils_probe_temp'));
        });
        check('a jQuery returned by the renderer is placed inside the element',
            (await read(page)).html, '<span class="probe__jq">busy</span>');
        await page.evaluate(() => Button_Utils.clear_submitting($('#button_utils_probe_temp')));
        check('and the content comes back after it', (await read(page)).html, original);

        // --- d. read at set_submitting() time: click_async uses the current loader --
        await mount(page);
        await page.evaluate(() => {
            Button_Utils.set_loader(null);
            window.__button_utils_release = null;
            $('#button_utils_probe_temp').click_async(() => new Promise((resolve) => { window.__button_utils_release = resolve; }));
            // Registered AFTER the handler was bound.
            Button_Utils.set_loader(() => '<span class="probe__late"></span>');
            $('#button_utils_probe_temp').trigger('click');
        });
        await page.waitForFunction(() => typeof window.__button_utils_release === 'function', null, { timeout: 0 });
        check('click_async draws the loader registered after it was bound',
            (await read(page)).html, '<span class="probe__late"></span>');
        await page.evaluate(() => window.__button_utils_release());
        await page.waitForFunction(() => !$('#button_utils_probe_temp').hasClass('btn--submitting'), null, { timeout: 0 });
        check('click_async restores the content when the handler settles', (await read(page)).html, original);

        // --- e. set_loader(null) restores the framework spinner ------------------------
        await mount(page);
        await page.evaluate(() => {
            Button_Utils.set_loader(null);
            Button_Utils.set_submitting($('#button_utils_probe_temp'));
        });
        check('set_loader(null) restores the framework spinner',
            (await read(page)).html, '<span class="Button_Utils__spinner" aria-hidden="true"></span>');
        await page.evaluate(() => Button_Utils.clear_submitting($('#button_utils_probe_temp')));

        // --- f. invalid input throws -------------------------------------------------
        const thrown = await page.evaluate(() => {
            const outcomes = {};
            for (const [label, value] of [['string', '<span></span>'], ['object', {}], ['undefined', undefined], ['true', true]]) {
                try {
                    Button_Utils.set_loader(value);
                    outcomes[label] = 'accepted';
                } catch (e) {
                    outcomes[label] = 'threw';
                }
            }
            return outcomes;
        });
        for (const label of Object.keys(thrown)) {
            check('set_loader(' + label + ') throws', thrown[label], 'threw');
        }
        check('a rejected argument leaves the framework spinner in force',
            await page.evaluate(() => Button_Utils._loader), null);

        await mount(page);
        const bad_return = await page.evaluate(() => {
            Button_Utils.set_loader(() => undefined);
            try {
                Button_Utils.set_submitting($('#button_utils_probe_temp'));
                return 'accepted';
            } catch (e) {
                return 'threw';
            } finally {
                Button_Utils.set_loader(null);
            }
        });
        state = await read(page);
        check('a renderer returning neither a string nor a jQuery throws', bad_return, 'threw');
        check('and the element is left untouched', state.html + '|' + state.submitting + '|' + state.width, original + '|false|');

        await page.evaluate(() => { $('#button_utils_probe_temp').remove(); });

        check('no page errors', errors.join(' | '), '');
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: button_utils_loader');
    } else {
        console.log('PASS: button_utils_loader');
    }
}

run();
