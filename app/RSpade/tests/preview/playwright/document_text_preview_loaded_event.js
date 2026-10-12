#!/usr/bin/env node
/**
 * Document Text Preview 'preview_loaded' test (PREVIEW-TEXT-EVENT-*).
 *
 * <Document_Text_Preview> reports what it put on screen with a 'preview_loaded' event, and it
 * ALWAYS reports: the text, the "(Extracting Text...)" notice it will replace by itself, and
 * "(Document Text Unavailable)" for each of the reasons that notice is shown. This script proves
 * each of those, plus sticky delivery to a late subscriber and the absence of a stale replay
 * after the swap out of the waiting notice.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree. One
 * small text file is uploaded at run time through the ordinary transport so the component has a
 * real attachment to subscribe to; it stays UNATTACHED and is released by the claim-window sweep
 * (rsx:man file_disposal). Extraction is asynchronous, so the extraction STATES are staged by
 * scripting File_Preview_Controller.get_extracted_text for the mount in question - the component
 * under test runs unmodified against that answer. The record that does not exist is asked of
 * the real endpoint.
 *
 * NOTHING HERE WAITS ON THE EVENT, and nothing has a deadline: each step waits for the
 * component to be ready and only then asks whether the event was delivered. An event that never
 * fires is therefore a FAIL line, not a hang.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const MISSING_ID = 2000000000;
const TEXT = 'first line\nsecond line';

function fail(msg) {
    console.log('FAIL: Document Text Preview preview_loaded - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Installed in the page once. */
function install_helpers() {
    const real = File_Preview_Controller.get_extracted_text;

    window.__text = {
        mounted: {},
        script(answer) {
            File_Preview_Controller.get_extracted_text = async () => ({ should_show_text_preview: true, ...answer });
        },
        unscript() {
            File_Preview_Controller.get_extracted_text = real;
        },
        mount(name, id) {
            const $el = $('<div style="width:400px;height:200px">').attr('data-probe', name).appendTo('body');
            $el.component('Document_Text_Preview', { attachment_id: id });
            const component = $el.component();
            const entry = { component, events: [] };
            component.on('preview_loaded', (c, data) => entry.events.push(data));
            this.mounted[name] = entry;
            return entry;
        },
        async shown(name) {
            const entry = this.mounted[name];
            await entry.component.ready();
            return {
                events: JSON.parse(JSON.stringify(entry.events)),
                notice: entry.component.$.find('.Document_Text_Preview__notice').text().trim(),
                text: entry.component.$.find('.Document_Text_Preview__text').text(),
            };
        },
    };
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        await page.evaluate(install_helpers);

        const id = await page.evaluate(async () => {
            const fd = new FormData();
            fd.append('file', new Blob(['probe\n'], { type: 'text/plain' }), 'text_event_probe.txt');
            const result = await Ajax.upload(fd);
            return result && result.attachment ? result.attachment.id : null;
        });

        if (!id) {
            fail('Ajax.upload() returned no attachment');
            return;
        }

        const stage = (name, answer) => page.evaluate(async ({ name, id, answer }) => {
            window.__text.script(answer);
            window.__text.mount(name, id);
            const shown = await window.__text.shown(name);
            window.__text.unscript();
            return shown;
        }, { name, id, answer });

        // -- PREVIEW-TEXT-EVENT-LOADED ---------------------------------------------------------
        const loaded = await stage('loaded', { status: 'available', text: TEXT });
        check('extracted text reports once: loaded, with its length in characters',
            loaded.events.length === 1 && loaded.events[0].state === 'loaded' && loaded.events[0].attachment_id === id
                && loaded.events[0].length === TEXT.length && loaded.text === TEXT,
            loaded);

        // -- PREVIEW-TEXT-EVENT-LATE -----------------------------------------------------------
        const late = await page.evaluate(() => {
            let handed = 'nothing';
            window.__text.mounted.loaded.component.once('preview_loaded', (c, data) => { handed = data; });
            return handed;
        });
        check('a subscriber arriving after the text is on screen is handed the same payload at once',
            late !== 'nothing' && late.state === 'loaded' && late.length === TEXT.length, late);

        // -- PREVIEW-TEXT-EVENT-UNAVAILABLE / -FAILED: the three faces of the unavailable notice --
        const failed = await stage('failed', { status: 'error', text: null });
        check('a failed extraction reports "failed"',
            failed.events.length === 1 && failed.events[0].state === 'failed' && failed.events[0].length === 0
                && failed.notice === '(Document Text Unavailable)',
            failed);

        const unsupported = await stage('unsupported', { status: 'unsupported', text: null });
        check('a file type with no extractable text reports "unavailable"',
            unsupported.events.length === 1 && unsupported.events[0].state === 'unavailable'
                && unsupported.notice === '(Document Text Unavailable)',
            unsupported);

        const empty = await stage('empty', { status: 'available', text: '' });
        check('a document that contains no text reports "unavailable"',
            empty.events.length === 1 && empty.events[0].state === 'unavailable' && empty.events[0].length === 0,
            empty);

        const missing = await page.evaluate(async (missing_id) => {
            window.__text.mount('missing', missing_id);
            return window.__text.shown('missing');
        }, MISSING_ID);
        check('a record that does not exist reports "unavailable"',
            missing.events.length === 1 && missing.events[0].state === 'unavailable'
                && missing.events[0].attachment_id === MISSING_ID && missing.notice === '(Document Text Unavailable)',
            missing);

        // -- PREVIEW-TEXT-EVENT-PREPARING: the waiting notice, then the swap ---------------------
        const swap = await page.evaluate(async ({ id, text }) => {
            window.__text.script({ status: 'pending', text: null });
            const entry = window.__text.mount('swap', id);
            const preparing = await window.__text.shown('swap');

            // What the extraction worker's realtime frame does: the server now answers
            // differently and the component is told to look again.
            window.__text.script({ status: 'available', text: text });
            await entry.component.refresh();
            const swapped = await window.__text.shown('swap');
            window.__text.unscript();

            let handed = 'nothing';
            entry.component.once('preview_loaded', (c, data) => { handed = data; });
            return { preparing, swapped, handed };
        }, { id, text: TEXT });
        check('"(Extracting Text...)" reports "preparing"',
            swap.preparing.events.length === 1 && swap.preparing.events[0].state === 'preparing'
                && swap.preparing.notice === '(Extracting Text...)',
            swap.preparing);
        check('the swap to the text reports again, as "loaded"',
            swap.swapped.events.length === 2 && swap.swapped.events[1].state === 'loaded'
                && swap.swapped.events[1].length === TEXT.length && swap.swapped.text === TEXT,
            swap.swapped);
        check('after the swap a late subscriber is handed the text\'s payload, never the waiting one',
            swap.handed !== 'nothing' && swap.handed.state === 'loaded', swap.handed);

        check('no page or console error', errors.length === 0, errors);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    console.log((process.exitCode === 1 ? 'FAIL' : 'PASS') + ': Document Text Preview preview_loaded');
}

run();
