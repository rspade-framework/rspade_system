#!/usr/bin/env node
/**
 * Attachment content-version swap test (ATTACH-VERSION-SWAP-*).
 *
 * An attachment's key is stable and its bytes are not: relink_storage() points it at another
 * blob under the same key. Every file URL therefore carries the attachment's content version
 * (?v=<storage id>-<rendered_at or 0>), and this script proves the browser half of that
 * contract, for an image and for a PDF:
 *
 *   1. File_Attachment_Model.content_version(record) in JavaScript and content_version() in PHP
 *      are the same string for the same record - the URLs the JS builders produce are compared,
 *      character for character, with the ones the server built into get_preview_info().
 *   2. A mounted <Attachment_Thumbnail> and <Document_Preview> request versioned URLs, and those
 *      are answered with the long-lived cache headers.
 *   3. ANOTHER PROCESS relinks each attachment to different bytes. WITHOUT RELOADING, both
 *      components repaint and request DIFFERENT URLs, and each reports 'preview_loaded' again.
 *   4. The URLs the page held before the relink are now answered no-store (with the new bytes),
 *      the new ones are long-lived, and a URL with no version at all is no-store - on /_inline,
 *      /_download, /_thumbnail and /_preview/pdf.
 *
 * Step 3 is the assertion with teeth: the page never navigates, so the only thing that can have
 * moved the DOM is the realtime frame relink_storage()'s save published. If it does not arrive
 * this test FAILS - it must never be "fixed" by reloading the page.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree. The
 * files are drawn or assembled in the browser and uploaded through the ordinary transport; they
 * stay UNATTACHED and are released by the claim-window sweep (rsx:man file_disposal). The relink
 * is one `php artisan tinker --execute` in a child process, because no web surface performs one.
 * Requires realtime enabled.
 *
 * Every wait is a poll of a condition, bounded at the house 120 seconds: each waits on a party
 * this script does not control (the realtime relay, the browser's image decode), truncates no
 * work, and on expiry FAILS naming the condition that was never reached.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { execFileSync } = require('child_process');
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const PROJECT_ROOT = '/var/www/html';
const WAIT_MS = 120000;

function fail(msg) {
    console.log('FAIL: Attachment content version - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Installed in the page once: upload, mount and read helpers shared by every step. */
function install_helpers() {
    const upload = async (blob, name) => {
        const fd = new FormData();
        fd.append('file', blob, name);
        const result = await Ajax.upload(fd);
        return result.attachment.id;
    };

    const png = (color) => new Promise((resolve) => {
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 48;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 64, 48);
        // A random pixel, so the bytes - and therefore the blob - belong to this run alone.
        ctx.fillStyle = 'rgb(' + [0, 0, 0].map(() => Math.floor(Math.random() * 256)).join(',') + ')';
        ctx.fillRect(3, 3, 2, 2);
        canvas.toBlob(resolve, 'image/png');
    });

    const pdf = (text) => {
        const stream = 'BT /F1 18 Tf 20 100 Td (' + text + ' ' + Math.random().toString(36).slice(2) + ') Tj ET';
        const objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        let out = '%PDF-1.4\n';
        const offsets = [];
        objects.forEach((object, index) => {
            offsets.push(out.length);
            out += (index + 1) + ' 0 obj\n' + object + '\nendobj\n';
        });
        const xref = out.length;
        out += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';
        offsets.forEach((offset) => { out += String(offset).padStart(10, '0') + ' 00000 n \n'; });
        out += 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n';
        return new Blob([out], { type: 'application/pdf' });
    };

    window.__probe = {
        mounted: {},
        async create() {
            return {
                image: await upload(await png('#c0392b'), 'content_version_a.png'),
                image_next: await upload(await png('#2471a3'), 'content_version_b.png'),
                pdf: await upload(pdf('first'), 'content_version_a.pdf'),
                pdf_next: await upload(pdf('second'), 'content_version_b.pdf'),
            };
        },
        mount(name, component_name, args) {
            const $el = $('<div style="width:400px;height:300px">').attr('data-probe', name).appendTo('body');
            $el.component(component_name, args);
            const component = $el.component();
            const entry = { component, events: [] };
            component.on('preview_loaded', (c, data) => entry.events.push(data.state));
            this.mounted[name] = entry;
        },
        /** What each mounted component is showing right now, read off the component itself. */
        shown() {
            const viewer_url = (name) => {
                const viewer = this.mounted[name].component.sid('viewer');
                return viewer ? viewer.args.url : null;
            };
            return {
                thumb_image: this.mounted.thumb_image.component.$.children('img').attr('src') || null,
                thumb_pdf: this.mounted.thumb_pdf.component.$.children('img').attr('src') || null,
                preview_image: viewer_url('preview_image'),
                preview_pdf: viewer_url('preview_pdf'),
            };
        },
        events() {
            const out = {};
            Object.keys(this.mounted).forEach((name) => { out[name] = this.mounted[name].events.slice(); });
            return out;
        },
        /** Status, Cache-Control and a digest of the body for a URL, bypassing the HTTP cache. */
        async probe(url) {
            const response = await fetch(url, { cache: 'no-store' });
            const bytes = await response.arrayBuffer();
            const digest = await crypto.subtle.digest('SHA-256', bytes);
            return {
                status: response.status,
                cache_control: response.headers.get('cache-control') || '',
                sha256: Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join(''),
            };
        },
        requested() {
            return performance.getEntriesByType('resource')
                .map((entry) => entry.name.replace(location.origin, ''))
                .filter((name) => /\/_(inline|download|thumbnail|preview\/pdf)\//.test(name));
        },
    };
}

const long_lived = (answer) => answer.status === 200 && /max-age=31536000/.test(answer.cache_control) && !/no-store/.test(answer.cache_control);
const uncacheable = (answer) => answer.status === 200 && /no-store/.test(answer.cache_control) && !/max-age/.test(answer.cache_control);
const version_of = (url) => { const m = (url || '').match(/[?&]v=([0-9]+-[0-9]+)$/); return m ? m[1] : null; };

async function run() {
    const { browser, page, errors } = await open_sys_page();

    /** Poll a page-side condition; false when it was never reached within the house bound. */
    const reached = (fn, arg) => page.waitForFunction(fn, arg, { timeout: WAIT_MS }).then(() => true).catch(() => false);

    try {
        await page.evaluate(install_helpers);
        const ids = await page.evaluate(() => window.__probe.create());

        // --- 1. PHP and JavaScript agree --------------------------------------------------------
        const agreement = await page.evaluate(async (ids) => {
            const out = {};
            for (const name of ['image', 'pdf']) {
                const record = await File_Attachment_Model.fetch(ids[name]);
                const info = await File_Preview_Controller.get_preview_info({ attachment_id: ids[name] });
                out[name] = {
                    version: File_Attachment_Model.content_version(record),
                    storage_id: record.file_storage_id,
                    js_inline: File_Attachment_Model.inline_url(record),
                    php_inline: info.urls.inline,
                    php_rendition: info.urls.rendition,
                    js_download: File_Attachment_Model.download_url(record),
                    js_thumbnail: File_Attachment_Model.thumbnail_url(record, { width: 200 }),
                };
            }
            out.next = {
                image: (await File_Attachment_Model.fetch(ids.image_next)).file_storage_id,
                pdf: (await File_Attachment_Model.fetch(ids.pdf_next)).file_storage_id,
            };
            out.blobless = File_Attachment_Model.content_version({ key: 'k' });
            return out;
        }, ids);

        for (const name of ['image', 'pdf']) {
            const a = agreement[name];
            check(name + ': the version is <storage id>-0 for an unrendered blob', a.version === a.storage_id + '-0', a);
            check(name + ': the JavaScript inline URL is the URL PHP built', a.js_inline === a.php_inline && version_of(a.js_inline) === a.version, a);
            check(name + ': every JavaScript builder carries the version',
                version_of(a.js_download) === a.version && version_of(a.js_thumbnail) === a.version, a);
        }
        check('pdf: the rendition URL PHP built carries the same version', version_of(agreement.pdf.php_rendition) === agreement.pdf.version, agreement.pdf);
        check('a record with no blob has the defined version 0-0', agreement.blobless === '0-0', agreement.blobless);

        // --- 2. Mounted components request versioned, long-lived URLs -----------------------------
        await page.evaluate((ids) => {
            window.__probe.mount('thumb_image', 'Attachment_Thumbnail', { attachment_id: ids.image, width: 200 });
            window.__probe.mount('thumb_pdf', 'Attachment_Thumbnail', { attachment_id: ids.pdf, width: 200 });
            window.__probe.mount('preview_image', 'Document_Preview', { attachment_id: ids.image });
            window.__probe.mount('preview_pdf', 'Document_Preview', { attachment_id: ids.pdf });
        }, ids);

        const all_loaded = () => {
            const events = window.__probe.events();
            return Object.keys(events).every((name) => events[name][events[name].length - 1] === 'loaded');
        };
        if (!await reached(all_loaded)) {
            fail('the four components never all reported preview_loaded "loaded" - saw ' + JSON.stringify(await page.evaluate(() => window.__probe.events())));
            return;
        }

        const before = await page.evaluate(() => window.__probe.shown());
        const events_before = await page.evaluate(() => window.__probe.events());

        check('the image thumbnail is requested at the attachment\'s version', version_of(before.thumb_image) === agreement.image.version, before);
        check('the pdf thumbnail is requested at the attachment\'s version', version_of(before.thumb_pdf) === agreement.pdf.version, before);
        check('Image_Viewer is handed the versioned inline URL', before.preview_image === agreement.image.php_inline, before);
        check('Pdf_Viewer is handed the versioned rendition URL', before.preview_pdf === agreement.pdf.php_rendition, before);

        const held = {
            thumb_image: before.thumb_image,
            thumb_pdf: before.thumb_pdf,
            inline_image: before.preview_image,
            rendition_pdf: before.preview_pdf,
            download_pdf: agreement.pdf.js_download,
        };
        const answers_before = {};
        for (const name of Object.keys(held)) {
            answers_before[name] = await page.evaluate((url) => window.__probe.probe(url), held[name]);
            check('current URL is long-lived: ' + name, long_lived(answers_before[name]), answers_before[name]);
        }
        check('the current rendition URL is immutable', /immutable/.test(answers_before.rendition_pdf.cache_control), answers_before.rendition_pdf);

        for (const name of Object.keys(held)) {
            const bare = await page.evaluate((url) => window.__probe.probe(url), held[name].split('?')[0]);
            check('a URL with no version is no-store: ' + name, uncacheable(bare), bare);
            check('and serves the same bytes: ' + name, bare.sha256 === answers_before[name].sha256, bare);
        }

        // --- 3. Another process relinks; the page repaints with no reload -------------------------
        const relink = '$m = \\App\\RSpade\\Core\\Files\\File_Attachment_Model::class; '
            + '$s = \\App\\RSpade\\Core\\Files\\File_Storage_Model::class; '
            + '$m::find(' + parseInt(ids.image, 10) + ')->relink_storage($s::find(' + parseInt(agreement.next.image, 10) + ')); '
            + '$m::find(' + parseInt(ids.pdf, 10) + ')->relink_storage($s::find(' + parseInt(agreement.next.pdf, 10) + ')); '
            + 'echo "relinked";';
        const relink_output = execFileSync('php', ['artisan', 'tinker', '--execute', relink], { cwd: PROJECT_ROOT, encoding: 'utf8' });
        if (relink_output.indexOf('relinked') === -1) {
            fail('the relink in the second process did not complete - it printed ' + JSON.stringify(relink_output));
            return;
        }

        const expected = {
            image: agreement.next.image + '-0',
            pdf: agreement.next.pdf + '-0',
        };
        const swapped = await reached((expected) => {
            const shown = window.__probe.shown();
            const ends = (url, version) => typeof url === 'string' && url.endsWith('?v=' + version);
            return ends(shown.thumb_image, expected.image) && ends(shown.thumb_pdf, expected.pdf)
                && ends(shown.preview_image, expected.image) && ends(shown.preview_pdf, expected.pdf);
        }, expected);

        const after = await page.evaluate(() => window.__probe.shown());
        if (!swapped) {
            fail('the mounted components never moved to the relinked blobs\' URLs (no realtime frame? this is a real failure, '
                + 'not a reason to reload) - expected versions ' + JSON.stringify(expected) + ', showing ' + JSON.stringify(after));
            return;
        }
        console.log('PASS: after the relink all four components show URLs at the new versions, no reload');

        for (const name of Object.keys(before)) {
            check('the URL changed and only in its version: ' + name,
                before[name] !== after[name] && before[name].split('?')[0] === after[name].split('?')[0], { before: before[name], after: after[name] });
        }

        const reported_again = await reached((counts) => {
            const events = window.__probe.events();
            return Object.keys(events).every((name) => events[name].length > counts[name] && events[name][events[name].length - 1] === 'loaded');
        }, Object.fromEntries(Object.keys(events_before).map((name) => [name, events_before[name].length])));
        check('every component reported preview_loaded again for the new picture', reported_again, await page.evaluate(() => window.__probe.events()));

        const requested = await page.evaluate(() => window.__probe.requested());
        for (const name of Object.keys(after)) {
            check('the browser requested the new URL: ' + name, requested.indexOf(after[name]) !== -1, { url: after[name], requested });
        }

        const navigations = await page.evaluate(() => performance.getEntriesByType('navigation').length);
        check('the page never navigated', navigations === 1, navigations);

        // --- 4. The cache policy follows the version ----------------------------------------------
        const record_after = await page.evaluate(async (id) => {
            const record = await File_Attachment_Model.fetch(id);
            return { download: File_Attachment_Model.download_url(record), version: File_Attachment_Model.content_version(record) };
        }, ids.pdf);
        check('the refetched record derives the relinked version', record_after.version === expected.pdf, record_after);

        const fresh = {
            thumb_image: after.thumb_image,
            thumb_pdf: after.thumb_pdf,
            inline_image: after.preview_image,
            rendition_pdf: after.preview_pdf,
            download_pdf: record_after.download,
        };
        for (const name of Object.keys(held)) {
            const stale = await page.evaluate((url) => window.__probe.probe(url), held[name]);
            const current = await page.evaluate((url) => window.__probe.probe(url), fresh[name]);
            check('the URL held from before the relink is now no-store: ' + name, uncacheable(stale), stale);
            check('the new URL is long-lived: ' + name, long_lived(current), current);
            check('the old URL serves the NEW bytes: ' + name, stale.sha256 === current.sha256 && stale.sha256 !== answers_before[name].sha256,
                { stale: stale.sha256, current: current.sha256, before: answers_before[name].sha256 });
        }

        check('no page error or console error', errors.length === 0, errors);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Attachment content version swap');
    } else {
        console.log('PASS: Attachment content version swap');
    }
}

run();
