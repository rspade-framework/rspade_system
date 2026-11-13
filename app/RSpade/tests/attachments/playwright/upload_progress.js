#!/usr/bin/env node
/**
 * Ajax.upload() progress (ATTACH-UPLOAD-PROGRESS-*).
 *
 * Ajax.upload(form_data, {on_progress: (loaded, total) => ...}) reports the request's upload
 * stream. This script proves, in a real browser against the real /_upload endpoint:
 *
 *   1. A large upload reports loaded values that strictly increase and end at loaded === total,
 *      that final call made BEFORE the promise resolves, with total covering the whole file.
 *   2. The answer is the same as without the option: the same bytes uploaded with no options
 *      resolve with an attachment of the same shape, name, size and type.
 *   3. An error thrown by the callback aborts the upload and is what the promise rejects with.
 *   4. A hand-rolled $.ajax() to /_upload throws, naming Ajax.upload().
 *
 * The upload link is throttled through the DevTools protocol: over an unthrottled local
 * connection the body leaves in one burst and the browser reports it once.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree. The
 * files are generated in the browser and stay UNATTACHED; the claim-window sweep releases them
 * (rsx:man file_disposal).
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

/** Large enough that the upload stream reports more than once on a local connection. */
const LARGE_BYTES = 8 * 1024 * 1024;

/** The emulated upload link: the large file takes a few seconds, reported many times. */
const UPLOAD_BYTES_PER_SECOND = 4 * 1024 * 1024;

function fail(msg) {
    console.log('FAIL: Ajax.upload progress - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        // A local connection sends the body in one burst, which the browser reports as a
        // single progress event. A throttled upload link makes the stream observable.
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', {
            offline: false,
            latency: 0,
            downloadThroughput: -1,
            uploadThroughput: UPLOAD_BYTES_PER_SECOND,
        });

        const outcome = await page.evaluate(async (large_bytes) => {
            // Random bytes, so the blob belongs to this run alone.
            const random_blob = (size) => {
                const bytes = new Uint8Array(size);
                for (let at = 0; at < size; at += 65536) {
                    crypto.getRandomValues(bytes.subarray(at, Math.min(at + 65536, size)));
                }
                return new Blob([bytes], { type: 'application/octet-stream' });
            };
            const form_for = (blob, name) => {
                const fd = new FormData();
                fd.append('file', blob, name);
                return fd;
            };

            const out = {};

            // The ceiling must admit the test file, or the client refuses before sending.
            const ceiling = window.rsxapp?.files?.max_file_size || 0;
            out.size = ceiling > 0 ? Math.min(large_bytes, ceiling - 4096) : large_bytes;
            const blob = random_blob(out.size);

            // --- 1. progress ---
            const calls = [];
            let resolved_at_calls = null;
            const with_progress = await Ajax.upload(form_for(blob, 'upload_progress.bin'), {
                on_progress: (loaded, total) => calls.push([loaded, total]),
            });
            resolved_at_calls = calls.length;
            out.calls = calls;
            out.resolved_at_calls = resolved_at_calls;

            // --- 2. the same answer without the option ---
            const without = await Ajax.upload(form_for(blob, 'upload_progress.bin'));
            const shape = (r) => ({
                success: r.success,
                keys: Object.keys(r).sort(),
                attachment_keys: Object.keys(r.attachment).sort(),
                file_name: r.attachment.file_name,
                size: r.attachment.size,
                mime_type: r.attachment.mime_type,
                file_extension: r.attachment.file_extension,
            });
            out.with_shape = shape(with_progress);
            out.without_shape = shape(without);

            // --- 3. a throwing callback aborts and is the rejection ---
            const thrown = new Error('progress callback refused');
            try {
                await Ajax.upload(form_for(random_blob(out.size), 'upload_progress_abort.bin'), {
                    on_progress: () => { throw thrown; },
                });
                out.abort = 'resolved';
            } catch (e) {
                out.abort = e === thrown ? 'same_error' : ('other: ' + (e && e.message));
            }

            return out;
        }, LARGE_BYTES);

        // Every error so far is unexpected; step 4 provokes a sanity-check report on purpose.
        const unexpected = errors.slice();

        // --- 4. $.ajax() to /_upload is refused ---
        outcome.jq = await page.evaluate(() => {
            const fd = new FormData();
            fd.append('file', new Blob(['x']), 'x.bin');
            try {
                $.ajax({ url: '/_upload', method: 'POST', data: fd, processData: false, contentType: false });
                return 'sent';
            } catch (e) {
                return e && e.message ? e.message : String(e);
            }
        });

        const calls = outcome.calls;
        const increasing = calls.every((c, i) => i === 0 || c[0] > calls[i - 1][0]);
        const last = calls[calls.length - 1] || [null, null];
        const steady_total = calls.every((c) => c[1] === last[1]);

        check('progress is reported more than once (' + calls.length + ' calls)', calls.length >= 2, calls.length);
        check('loaded strictly increases', increasing, calls);
        check('the last call is loaded === total', last[0] === last[1] && last[1] !== null, last);
        check('total is one figure for the whole request body and covers the file',
            steady_total && last[1] >= outcome.size, { total: last[1], size: outcome.size });
        check('the final call is made before the promise resolves', outcome.resolved_at_calls === calls.length, outcome.resolved_at_calls);
        check('the answer is the same with and without on_progress',
            JSON.stringify(outcome.with_shape) === JSON.stringify(outcome.without_shape)
            && outcome.with_shape.success === true && outcome.with_shape.size === outcome.size,
            { with: outcome.with_shape, without: outcome.without_shape });
        check('an error thrown by on_progress aborts the upload and is the rejection', outcome.abort === 'same_error', outcome.abort);
        check('$.ajax() to /_upload throws, naming Ajax.upload()',
            outcome.jq !== 'sent' && outcome.jq.includes('Ajax.upload'), outcome.jq);

        check('the page logged no unexpected errors', unexpected.length === 0, unexpected);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Ajax.upload progress');
    } else {
        console.log('PASS: Ajax.upload progress');
    }
}

run();
