#!/usr/bin/env node
/**
 * Document Preview 'preview_loaded' test (PREVIEW-EVENT-*).
 *
 * <Document_Preview> reports what ended up in its frame with a 'preview_loaded' event, and it
 * ALWAYS reports: a viewer's document, a viewer that could not show its document, the waiting
 * and failed notices, the unavailable card, and a viewer that never reports anything itself.
 * This script proves each of those, plus sticky delivery to a late subscriber and the absence
 * of a stale replay once the frame shows something else.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree. Real
 * content is uploaded at run time through the ordinary transport (a PNG drawn on a canvas, a
 * markdown file); the uploads stay UNATTACHED and are released by the claim-window sweep
 * (rsx:man file_disposal). States that need the server mid-render, a broken source or a viewer
 * the framework does not ship are staged by scripting File_Preview_Controller.get_preview_info
 * for the mount in question - the component under test runs unmodified against that answer.
 *
 * NOTHING HERE WAITS ON THE EVENT, and nothing has a deadline. Every step waits on facts that
 * are certain to arrive - the component and its viewer are ready, an <img> or <iframe> has
 * settled - and only then asks whether the event was delivered. An event that never fires is
 * therefore a FAIL line, not a hang.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const MISSING_ID = 2000000000;

function fail(msg) {
    console.log('FAIL: Document Preview preview_loaded - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Installed in the page once: mount / settle / scripting helpers shared by every step. */
function install_helpers() {
    const real = File_Preview_Controller.get_preview_info;

    // load and error do not bubble, but they are CAPTURED here before the element's own
    // listeners run. An element stamped by this has finished; one that is not stamped has an
    // event still to come - which "complete" cannot say for an image served from memory.
    const stamp = (event) => { event.target.__probe_settled = true; };
    document.addEventListener('load', stamp, true);
    document.addEventListener('error', stamp, true);

    window.__preview = {
        mounted: {},
        /** Lay `overlay` (or the result of calling it with the real answer) over get_preview_info. */
        script(overlay) {
            File_Preview_Controller.get_preview_info = async (params) => {
                const info = await real.call(File_Preview_Controller, params);
                const patch = typeof overlay === 'function' ? overlay(info) : overlay;
                return { ...info, ...patch, urls: { ...info.urls, ...(patch.urls || {}) } };
            };
        },
        unscript() {
            File_Preview_Controller.get_preview_info = real;
        },
        mount(name, args) {
            const $el = $('<div style="width:400px;height:300px">').attr('data-probe', name).appendTo('body');
            $el.component('Document_Preview', args);
            const component = $el.component();
            const entry = { component, events: [] };
            component.on('preview_loaded', (c, data) => entry.events.push(data));
            this.mounted[name] = entry;
            return entry;
        },
        /**
         * Resolves when the component is ready, its viewer (if any) is ready, and every <img> and
         * <iframe> in the frame has settled.
         */
        async settled(name) {
            const entry = this.mounted[name];
            await entry.component.ready();
            const viewer = entry.component.sid('viewer');
            if (viewer) {
                await viewer.ready();
            }
            const pending = [];
            entry.component.$.find('img, iframe').each(function () {
                const element = this;
                if (!element.__probe_settled) {
                    pending.push(new Promise((resolve) => {
                        element.addEventListener('load', resolve, { once: true });
                        element.addEventListener('error', resolve, { once: true });
                    }));
                }
            });
            await Promise.all(pending);
            // The component's own handlers were bound first; one more turn lets them run.
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return JSON.parse(JSON.stringify(entry.events));
        },
        text(name, selector) {
            return this.mounted[name].component.$.find(selector).text().trim();
        },
    };
}

async function run() {
    const { browser, page, errors, on_request } = await open_sys_page();

    // One request this script holds on purpose, by a marker in the URL.
    let hold_marker = null;
    let release_hold = null;
    const held = new Promise((resolve) => { release_hold = resolve; });
    on_request(async (route, request) => {
        if (hold_marker && request.url().indexOf(hold_marker) !== -1) {
            await held;
        }
        return false;
    });

    try {
        await page.evaluate(install_helpers);

        const ids = await page.evaluate(async () => {
            const draw = async (color) => {
                const canvas = document.createElement('canvas');
                canvas.width = 64;
                canvas.height = 48;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = color;
                ctx.fillRect(0, 0, 64, 48);
                return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            };
            const upload = async (blob, name) => {
                const fd = new FormData();
                fd.append('file', blob, name);
                const result = await Ajax.upload(fd);
                return result && result.attachment ? { id: result.attachment.id, key: result.attachment.key } : null;
            };

            return {
                png: await upload(await draw('#2060c0'), 'preview_event_probe.png'),
                png2: await upload(await draw('#c06020'), 'preview_event_probe_2.png'),
                md: await upload(new Blob(['# Probe\n\nBody.\n'], { type: 'text/markdown' }), 'preview_event_probe.md'),
            };
        });

        if (!ids.png || !ids.png2 || !ids.md) {
            fail('Ajax.upload() returned no attachment: ' + JSON.stringify(ids));
            return;
        }

        const stage = (name, id, overlay) => page.evaluate(async ({ name, id, overlay }) => {
            if (overlay) window.__preview.script(overlay);
            window.__preview.mount(name, { attachment_id: id });
            const events = await window.__preview.settled(name);
            window.__preview.unscript();
            return events;
        }, { name, id, overlay });

        // -- PREVIEW-EVENT-LOADED: a template-rendered viewer (Image_Viewer) -------------------
        const image = await stage('image', ids.png.id);
        const ie = image[0] || {};
        check('an image reports once: loaded, one page, its natural geometry, the common fields',
            image.length === 1 && ie.state === 'loaded' && ie.attachment_id === ids.png.id && ie.render_status === 1
                && ie.pages === 1 && ie.width === 64 && ie.height === 48 && ie.ratio === 0.75,
            image);

        // -- PREVIEW-EVENT-LATE: sticky delivery ---------------------------------------------
        const late = await page.evaluate(() => {
            let handed = 'nothing';
            window.__preview.mounted.image.component.once('preview_loaded', (c, data) => { handed = data; });
            return handed;
        });
        check('a subscriber arriving after the preview loaded is handed the same payload at once',
            late !== 'nothing' && late.state === 'loaded' && late.attachment_id === ids.png.id && late.width === 64,
            late);

        // -- PREVIEW-ATTEND: a mount attends the view, a realtime frame's refetch does not ------
        // The frame is delivered the way the relay's would be, through the component's own watch
        // entry; with realtime off there is no subscription and only the mount half is asserted.
        const attend = await page.evaluate(async (id) => {
            const real = File_Preview_Controller.get_preview_info;
            const sent = [];
            File_Preview_Controller.get_preview_info = (params) => {
                sent.push(params.attend);
                return real.call(File_Preview_Controller, params);
            };
            try {
                window.__preview.mount('attend', { attachment_id: id });
                await window.__preview.settled('attend');
                const component = window.__preview.mounted.attend.component;

                let framed = null;
                if (Rsx_Realtime.is_enabled()) {
                    const entry = Array.from(Rsx_Realtime._watches.values())
                        .find((w) => w.filter && w.filter.model === 'File_Attachment_Model' && int(w.filter.id) === id);
                    framed = !!entry;
                    if (entry) {
                        const before = sent.length;
                        Rsx_Realtime._fire_callbacks(entry, { model: 'File_Attachment_Model', id: id }, false);
                        while (sent.length === before) {
                            await new Promise((resolve) => requestAnimationFrame(resolve));
                        }
                        await component.ready();
                    }
                }
                return { sent, framed, state: component.data.error_data ? 'error' : 'ok' };
            } finally {
                File_Preview_Controller.get_preview_info = real;
            }
        }, ids.png.id);
        check('a mounted preview asks the server to attend the view, and loads without error',
            attend.sent[0] === true && attend.state === 'ok', attend);
        if (attend.framed !== null) {
            // The subscription's resync (its ack fires the callback once) is a refetch too.
            check('a realtime frame\'s refetch does not ask the server to attend',
                attend.framed === true && attend.sent.length >= 2 && attend.sent.slice(1).every((a) => a === false), attend);
        }

        // -- PREVIEW-EVENT-DYNAMIC: a dynamically instantiated viewer (Markdown_Viewer) ---------
        const markdown = await stage('markdown', ids.md.id);
        check('a dynamically instantiated viewer that reports for itself is forwarded, once',
            markdown.length === 1 && markdown[0].state === 'loaded' && markdown[0].pages === 1
                && markdown[0].attachment_id === ids.md.id,
            markdown);

        // -- PREVIEW-EVENT-UNAVAILABLE: no record ----------------------------------------------
        const missing = await stage('missing', MISSING_ID);
        const missing_title = await page.evaluate(() => window.__preview.text('missing', '.Document_Preview__unavailable-title'));
        check('a record that does not exist shows the unavailable card and reports "unavailable"',
            missing.length === 1 && missing[0].state === 'unavailable' && missing[0].attachment_id === MISSING_ID
                && missing[0].render_status === 'unavailable' && missing[0].pages === 0
                && missing_title === 'Preview unavailable',
            { missing, missing_title });

        // -- PREVIEW-EVENT-DEGRADED: the degraded-upload card -----------------------------------
        const degraded = await stage('degraded', ids.png.id, { preview_unavailable: true });
        check('a degraded upload\'s "Preview unavailable" card reports "unavailable"',
            degraded.length === 1 && degraded[0].state === 'unavailable' && degraded[0].render_status === 1,
            degraded);

        // -- PREVIEW-EVENT-RENDER-FAILED: the failed-render notice ------------------------------
        const render_failed = await stage('render_failed', ids.png.id, { render_status_id: 4 });
        check('a FAILED render\'s notice reports "failed"',
            render_failed.length === 1 && render_failed[0].state === 'failed' && render_failed[0].render_status === 4,
            render_failed);

        // -- PREVIEW-EVENT-RENDER-RETRYING: a FAILED render this view set a retry in motion for --
        const retrying = await stage('render_retrying', ids.png.id, { render_status_id: 4, render_retrying: true });
        const retrying_notice = await page.evaluate(() => window.__preview.text('render_retrying', '.Document_Preview__state-title'));
        check('a FAILED render with a retry under way shows "Preparing preview..." and reports "preparing"',
            retrying.length === 1 && retrying[0].state === 'preparing' && retrying[0].render_status === 4
                && retrying_notice === 'Preparing preview...',
            { retrying, retrying_notice });

        // -- PREVIEW-EVENT-PREPARING: the waiting notice, then the swap --------------------------
        const swap = await page.evaluate(async (id) => {
            window.__preview.script({ render_status_id: 2 });
            const entry = window.__preview.mount('swap', { attachment_id: id });
            const preparing = await window.__preview.settled('swap');
            const notice = window.__preview.text('swap', '.Document_Preview__state-title');

            // What the render worker's realtime frame does: the server now answers differently
            // and the component is told to look again.
            window.__preview.unscript();
            await entry.component.refresh();
            const swapped = await window.__preview.settled('swap');

            let handed = 'nothing';
            entry.component.once('preview_loaded', (c, data) => { handed = data; });
            return { preparing, notice, swapped, handed };
        }, ids.png.id);
        check('"Preparing preview..." reports "preparing"',
            swap.preparing.length === 1 && swap.preparing[0].state === 'preparing' && swap.preparing[0].render_status === 2
                && swap.notice === 'Preparing preview...',
            swap);
        check('the swap to the document reports again, as "loaded", and that is what a late subscriber is handed',
            swap.swapped.length === 2 && swap.swapped[1].state === 'loaded' && swap.swapped[1].width === 64
                && swap.handed !== 'nothing' && swap.handed.state === 'loaded',
            swap);

        // -- PREVIEW-EVENT-VIEWER-FAILED: viewers whose source is broken -------------------------
        const broken_image = await stage('broken_image', ids.png.id, { urls: { inline: '/_inline/preview-event-probe-no-such-key' } });
        check('an image that fails to load reports "failed" with no geometry',
            broken_image.length === 1 && broken_image[0].state === 'failed' && broken_image[0].width === 0
                && broken_image[0].height === 0 && broken_image[0].attachment_id === ids.png.id,
            broken_image);

        const broken_pdf = await stage('broken_pdf', ids.png.id, {
            viewer: 'Pdf_Viewer',
            urls: { rendition: '/_preview/pdf/preview-event-probe-no-such-key' },
        });
        const pdf_error_shown = await page.evaluate(() =>
            window.__preview.mounted.broken_pdf.component.$.find('.Pdf_Viewer__error').is(':visible'));
        check('a document pdf.js cannot load shows its error and reports "failed"',
            broken_pdf.length === 1 && broken_pdf[0].state === 'failed' && broken_pdf[0].pages === 0 && pdf_error_shown === true,
            { broken_pdf, pdf_error_shown });

        // -- PREVIEW-EVENT-SPREADSHEET: the grid's iframe ---------------------------------------
        // Any same-origin document stands in for the rendition: what is under test is that the
        // viewer says "preparing" while the iframe is on its way and "loaded" when it lands.
        const sheet = await page.evaluate(async (id) => {
            window.__preview.script((info) => ({
                viewer: 'Spreadsheet_Viewer',
                render_status_id: 3,
                urls: { rendition: info.urls.icon },
            }));
            window.__preview.mount('sheet', { attachment_id: id });
            const events = await window.__preview.settled('sheet');
            window.__preview.unscript();
            return events;
        }, ids.png.id);
        check('a spreadsheet reports "preparing" while its grid loads, then "loaded"',
            sheet.length === 2 && sheet[0].state === 'preparing' && sheet[1].state === 'loaded'
                && sheet[1].attachment_id === ids.png.id && sheet[1].render_status === 3,
            sheet);

        // -- PREVIEW-EVENT-SILENT-VIEWER: a viewer that never reports ---------------------------
        // A component name with no definition mounts as a bare component: it reaches 'ready' and
        // says nothing, which is exactly a custom viewer that does not keep the viewer contract.
        const silent = await stage('silent', ids.png.id, { viewer: 'Preview_Event_Probe_Silent_Viewer' });
        check('a viewer that never reports is reported for by Document_Preview once it is ready',
            silent.length === 1 && silent[0].state === 'loaded' && silent[0].pages === 1
                && silent[0].attachment_id === ids.png.id && silent[0].render_status === 1,
            silent);

        // -- PREVIEW-EVENT-CHANGE: another attachment, and no stale replay ----------------------
        // The second image's bytes are HELD, so there is a real interval in which the first
        // document is gone and the second has not arrived. A subscriber arriving in it must be
        // handed nothing; it is told when the second document lands.
        hold_marker = '/_inline/' + ids.png2.key;
        const during = await page.evaluate(async (ids) => {
            const entry = window.__preview.mount('change', { attachment_id: ids.png.id });
            await window.__preview.settled('change');

            entry.component.args.attachment_id = ids.png2.id;
            await entry.component.reload();
            await entry.component.sid('viewer').ready();

            window.__preview.change_late = [];
            entry.component.on('preview_loaded', (c, data) => window.__preview.change_late.push(data));
            return { before: entry.events.length, late_at_once: window.__preview.change_late.length };
        }, ids);
        release_hold();
        const after = await page.evaluate(async () => ({
            events: await window.__preview.settled('change'),
            late: window.__preview.change_late,
        }));
        hold_marker = null;
        check('while the next document is still loading, a late subscriber is handed nothing',
            during.before === 1 && during.late_at_once === 0, during);
        check('the next document reports with ITS attachment id, to the late subscriber too',
            after.events.length === 2 && after.events[1].attachment_id === ids.png2.id && after.events[1].state === 'loaded'
                && after.late.length === 1 && after.late[0].attachment_id === ids.png2.id,
            after);

        // The broken sources are the console errors this script causes on purpose.
        const unexpected = errors.filter((message) => message.indexOf('Failed to load resource') === -1);
        check('no page or console error', unexpected.length === 0, unexpected);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    console.log((process.exitCode === 1 ? 'FAIL' : 'PASS') + ': Document Preview preview_loaded');
}

run();
