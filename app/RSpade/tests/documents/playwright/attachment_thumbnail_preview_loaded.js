#!/usr/bin/env node
/**
 * Attachment Thumbnail 'preview_loaded' test (DOCUMENTS-THUMBNAIL-EVENT-*).
 *
 * <Attachment_Thumbnail> reports what it put on screen with a 'preview_loaded' event, and it
 * ALWAYS reports: a decoded picture, a picture that would not load, the placeholder of a record
 * that does not exist. This script proves each of those, plus the two properties a host relies
 * on without thinking about them - a subscriber that arrives late is still told, and after the
 * component shows something else a late subscriber is never told about the picture before.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree. Its
 * fixtures are created at run time through the ordinary upload transport: a PNG drawn on a
 * canvas in the page, and the suite's animated GIF (tests/attachments/resource/cube.gif) read
 * from disk here and handed to the page as bytes. The uploads stay UNATTACHED and are released
 * by the claim-window sweep (rsx:man file_disposal).
 *
 * NOTHING HERE WAITS ON THE EVENT, and nothing has a deadline. Every step waits on a browser
 * fact that is certain to arrive - the component is ready and its <img> has settled (load or
 * error) - and only then asks whether the event was delivered. An event that never fires is
 * therefore a FAIL line, not a hang.
 *
 * States the server would have to be mid-render to produce (the PENDING and FAILED extension
 * icon) are staged by scripting File_Attachment_Model.fetch_or_null for one fetch.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const fs = require('fs');
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const GIF_PATH = '/var/www/html/system/app/RSpade/tests/attachments/resource/cube.gif';
const MISSING_ID = 2000000000;

function fail(msg) {
    console.log('FAIL: Attachment Thumbnail preview_loaded - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Installed in the page once: mount / settle helpers shared by every step. */
function install_helpers() {
    window.__thumb = {
        mounted: {},
        mount(name, args) {
            const $el = $('<div>').attr('data-probe', name).appendTo('body');
            $el.component('Attachment_Thumbnail', args);
            const component = $el.component();
            const entry = { component, events: [] };
            component.on('preview_loaded', (c, data) => entry.events.push(data));
            this.mounted[name] = entry;
            return entry;
        },
        /** Resolves when the component is ready and the <img> it rendered has loaded or errored. */
        async settled(name) {
            const entry = this.mounted[name];
            await entry.component.ready();
            const img = entry.component.$.find('img')[0];
            if (img && !img.complete) {
                await new Promise((resolve) => {
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true });
                });
            }
            // The component's own handlers were bound first; one more turn lets them run.
            await new Promise((resolve) => requestAnimationFrame(resolve));
            return {
                events: entry.events,
                natural_width: img ? img.naturalWidth : null,
                natural_height: img ? img.naturalHeight : null,
                placeholder: entry.component.$.find('.Attachment_Thumbnail__placeholder').length,
            };
        },
        /** A subscriber arriving now: what it is handed at once, if anything. */
        late(name) {
            let handed = 'nothing';
            this.mounted[name].component.once('preview_loaded', (c, data) => { handed = data; });
            return handed;
        },
    };
}

async function run() {
    const { browser, page, errors, on_request } = await open_sys_page();

    // Requests this script fails or holds on purpose, by a marker in the URL.
    let abort_marker = null;
    let hold_marker = null;
    let release_hold = null;
    const held = new Promise((resolve) => { release_hold = resolve; });
    on_request(async (route, request) => {
        const url = request.url();
        if (abort_marker && url.indexOf(abort_marker) !== -1) {
            await route.abort();
            return true;
        }
        if (hold_marker && url.indexOf(hold_marker) !== -1) {
            await held;
        }
        return false;
    });

    try {
        await page.evaluate(install_helpers);

        const ids = await page.evaluate(async (gif_base64) => {
            const canvas = document.createElement('canvas');
            canvas.width = 64;
            canvas.height = 48;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#2060c0';
            ctx.fillRect(0, 0, 64, 48);
            const png = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

            const gif_bytes = Uint8Array.from(atob(gif_base64), (ch) => ch.charCodeAt(0));
            const gif = new Blob([gif_bytes], { type: 'image/gif' });

            const upload = async (blob, name) => {
                const fd = new FormData();
                fd.append('file', blob, name);
                const result = await Ajax.upload(fd);
                return result && result.attachment ? result.attachment.id : null;
            };

            return { png: await upload(png, 'thumbnail_event_probe.png'), gif: await upload(gif, 'thumbnail_event_probe.gif') };
        }, fs.readFileSync(GIF_PATH).toString('base64'));

        if (!ids.png || !ids.gif) {
            fail('Ajax.upload() returned no attachment: ' + JSON.stringify(ids));
            return;
        }

        // -- DOCUMENTS-THUMBNAIL-EVENT-LOADED: a real picture -------------------------------
        const loaded = await page.evaluate(async (id) => {
            window.__thumb.mount('loaded', { attachment_id: id, width: 32 });
            return window.__thumb.settled('loaded');
        }, ids.png);
        const le = loaded.events[0] || {};
        check('a decoded thumbnail reports once, with what was served',
            loaded.events.length === 1 && le.attachment_id === ids.png && le.state === 'loaded'
                && le.variant === 'thumbnail' && le.render_status === 1
                && le.natural_width === loaded.natural_width && le.natural_width > 0
                && le.natural_height === loaded.natural_height,
            loaded);

        // -- DOCUMENTS-THUMBNAIL-EVENT-LATE: sticky delivery --------------------------------
        const late = await page.evaluate(() => window.__thumb.late('loaded'));
        check('a subscriber arriving after the picture loaded is handed the same payload at once',
            late !== 'nothing' && late.attachment_id === ids.png && late.state === 'loaded'
                && late.natural_width === le.natural_width,
            late);

        // -- DOCUMENTS-THUMBNAIL-EVENT-ORIGINAL: $allow_animated serves the original ----------
        const original = await page.evaluate(async (id) => {
            window.__thumb.mount('original', { attachment_id: id, width: 32, allow_animated: true });
            window.__thumb.mount('still', { attachment_id: id, width: 32 });
            return { original: await window.__thumb.settled('original'), still: await window.__thumb.settled('still') };
        }, ids.gif);
        const oe = original.original.events[0] || {};
        const se = original.still.events[0] || {};
        check('an animated image under $allow_animated reports variant "original" at the upload\'s own size',
            original.original.events.length === 1 && oe.variant === 'original' && oe.state === 'loaded'
                && oe.natural_width === original.original.natural_width && oe.natural_width > 32,
            original.original);
        check('the same image without $allow_animated reports variant "thumbnail" at the smaller thumbnail\'s size',
            original.still.events.length === 1 && se.variant === 'thumbnail'
                && se.natural_width === original.still.natural_width && se.natural_width < oe.natural_width,
            original.still);

        // -- DOCUMENTS-THUMBNAIL-EVENT-UNAVAILABLE: no record --------------------------------
        const missing = await page.evaluate(async (id) => {
            window.__thumb.mount('missing', { attachment_id: id, width: 32 });
            return window.__thumb.settled('missing');
        }, MISSING_ID);
        const me = missing.events[0] || {};
        check('a record that does not exist reports "unavailable" for its placeholder',
            missing.events.length === 1 && missing.placeholder === 1 && me.attachment_id === MISSING_ID
                && me.state === 'unavailable' && me.render_status === 'unavailable' && me.variant === null
                && me.natural_width === 0 && me.natural_height === 0,
            missing);

        // -- DOCUMENTS-THUMBNAIL-EVENT-FAILED: the picture does not load -----------------------
        abort_marker = '/fit/37';
        const broken = await page.evaluate(async (id) => {
            window.__thumb.mount('broken', { attachment_id: id, width: 37 });
            return window.__thumb.settled('broken');
        }, ids.png);
        abort_marker = null;
        const be = broken.events[0] || {};
        check('a picture that fails to load reports "failed" with no dimensions',
            broken.events.length === 1 && be.state === 'failed' && be.variant === 'thumbnail'
                && be.attachment_id === ids.png && be.natural_width === 0 && be.natural_height === 0,
            broken);

        // -- DOCUMENTS-THUMBNAIL-EVENT-PREPARING: the render-state icon, then the swap ---------
        const swap = await page.evaluate(async (id) => {
            const real = File_Attachment_Model.fetch_or_null;
            const with_status = (status) => {
                File_Attachment_Model.fetch_or_null = async (...args) => {
                    const record = await real.apply(File_Attachment_Model, args);
                    record.file_storage.render_status_id = status;
                    return record;
                };
            };

            with_status(2);
            const entry = window.__thumb.mount('swap', { attachment_id: id, width: 41 });
            const pending = JSON.parse(JSON.stringify((await window.__thumb.settled('swap')).events));

            with_status(4);
            await entry.component.refresh();
            const failed = JSON.parse(JSON.stringify((await window.__thumb.settled('swap')).events));

            File_Attachment_Model.fetch_or_null = real;
            await entry.component.refresh();
            const final = JSON.parse(JSON.stringify((await window.__thumb.settled('swap')).events));

            return { pending, failed, final };
        }, ids.png);
        check('the icon of a queued render reports "preparing"',
            swap.pending.length === 1 && swap.pending[0].state === 'preparing' && swap.pending[0].render_status === 2,
            swap.pending);
        check('the icon of a failed render reports "failed"',
            swap.failed.length === 2 && swap.failed[1].state === 'failed' && swap.failed[1].render_status === 4,
            swap.failed);
        check('the swap to the real picture reports again, as "loaded"',
            swap.final.length === 3 && swap.final[2].state === 'loaded' && swap.final[2].render_status === 1
                && swap.final[2].natural_width > 0,
            swap.final);

        // -- DOCUMENTS-THUMBNAIL-EVENT-CHANGE: another attachment, and no stale replay ----------
        // The new picture's request is HELD, so there is a real interval in which the old picture
        // is gone and the new one has not arrived. A subscriber arriving in it must be handed
        // nothing; it is told when the new picture lands.
        hold_marker = '/fit/43';
        const during = await page.evaluate(async (ids) => {
            const entry = window.__thumb.mount('change', { attachment_id: ids.png, width: 32 });
            await window.__thumb.settled('change');

            entry.component.args.attachment_id = ids.gif;
            entry.component.args.width = 43;
            await entry.component.reload();

            window.__thumb.change_late = [];
            entry.component.on('preview_loaded', (c, data) => window.__thumb.change_late.push(data));
            return { before: entry.events.length, late_at_once: window.__thumb.change_late.length };
        }, ids);
        release_hold();
        const after = await page.evaluate(async () => {
            const result = await window.__thumb.settled('change');
            return { events: result.events, late: window.__thumb.change_late };
        });
        hold_marker = null;
        check('while the next picture is still loading, a late subscriber is handed nothing',
            during.before === 1 && during.late_at_once === 0, during);
        check('the next picture reports with ITS attachment id, to the late subscriber too',
            after.events.length === 2 && after.events[1].attachment_id === ids.gif && after.events[1].state === 'loaded'
                && after.late.length === 1 && after.late[0].attachment_id === ids.gif,
            after);

        // The aborted request is the one console error this script causes on purpose.
        const unexpected = errors.filter((message) => message.indexOf('Failed to load resource') === -1);
        check('no page or console error', unexpected.length === 0, unexpected);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    console.log((process.exitCode === 1 ? 'FAIL' : 'PASS') + ': Attachment Thumbnail preview_loaded');
}

run();
