#!/usr/bin/env node
/**
 * Attachment Thumbnail swap test (DOCUMENTS-THUMBNAIL-SWAP).
 *
 * The single behavior the whole async-render epic exists to deliver: a document whose thumbnail
 * does not exist yet paints an extension-icon placeholder, and when the background render finishes
 * the real raster REPLACES IT IN PLACE - no reload, no polling, no stale picture pinned by a
 * one-year cache header.
 *
 * Drives /dev/attachment_thumbnail against the seeded sample_memo.docx:
 *   1. select it -> <Attachment_Thumbnail> and <Document_Preview> mount.
 *   2. Reset to Pending -> the blob goes back to PENDING with NO worker spawned. Assert the img's
 *      data-render-status becomes 2 and its src carries ?v=0 (nothing rendered).
 *   3. Render Now -> the render runs inline in that request. WITHOUT RELOADING, assert the img's
 *      data-render-status becomes 3 and its src carries a non-zero ?v=, and that Document_Preview
 *      left its "Preparing preview..." state for a real Pdf_Viewer.
 *
 * Step 3 is the assertion with teeth: the page is never navigated, so the only thing that can have
 * changed the DOM is the realtime frame the render emitted on the attachment. If the frame does not
 * arrive this test FAILS - it must never be "fixed" by reloading the page, which would prove
 * nothing.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare `node attachment_thumbnail_swap.js`. Runs against
 * the dev web server on localhost. Requires: realtime enabled, and the sample documents imported
 * (import_sample_documents migration).
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/dev/attachment_thumbnail';
const USER_ID = 1;
const SAMPLE_DOCX_NAME = 'sample_memo.docx';
const STEP_TIMEOUT_MS = 30000;

function fail(msg) {
    console.log('FAIL: Attachment Thumbnail swap - ' + msg);
    process.exitCode = 1;
}

/** The live state of the mounted thumbnail, read straight off the DOM. */
function read_thumbnail() {
    const img = document.querySelector('.Attachment_Thumbnail__img');
    if (!img) return null;
    const src = img.getAttribute('src') || '';
    const m = src.match(/[?&]v=(\d+)/);
    return {
        status: img.getAttribute('data-render-status'),
        src: src,
        v: m ? parseInt(m[1], 10) : null,
    };
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

        // Wait for the SPA action's full lifecycle. NOTE: the iife bundle exposes Rsx/Spa as BARE
        // globals, NOT as window.Rsx/window.Spa - use typeof guards, not window.* lookups.
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

        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed, or the route is closed. '
                + "The template ships /dev/attachment_thumbnail as #[Auth('closed')]; open that "
                + 'surface in your own tree to run this test.');
            await browser.close();
            return;
        }

        // Select the sample docx.
        const docx_value = await page.evaluate((name) => {
            const a = Spa.action();
            const opts = a.$sid('select').find('option').toArray();
            const match = opts.find((o) => (o.textContent || '').includes(name));
            return match ? match.value : null;
        }, SAMPLE_DOCX_NAME);

        if (!docx_value) {
            fail(SAMPLE_DOCX_NAME + ' not present in the attachment picker (was the sample-documents migration run?)');
            await browser.close();
            return;
        }

        await page.evaluate((v) => {
            const a = Spa.action();
            a.$sid('select').val(v).trigger('change');
        }, docx_value);

        const mounted = await page.waitForFunction(read_thumbnail, { timeout: STEP_TIMEOUT_MS })
            .then((h) => h.jsonValue()).catch(() => null);

        if (!mounted) {
            fail('<Attachment_Thumbnail> never rendered an img for the selected attachment');
            await browser.close();
            return;
        }
        console.log('PASS: thumbnail mounted (status ' + mounted.status + ', v=' + mounted.v + ')');

        // --- Reset to PENDING ---------------------------------------------------------------
        await page.evaluate(() => { Spa.action().$sid('reset').trigger('click'); });

        const pending = await page.waitForFunction(() => {
            const img = document.querySelector('.Attachment_Thumbnail__img');
            if (!img) return false;
            const src = img.getAttribute('src') || '';
            return img.getAttribute('data-render-status') === '2' && /[?&]v=0(&|$)/.test(src);
        }, { timeout: STEP_TIMEOUT_MS }).then(() => true).catch(() => false);

        if (!pending) {
            const now = await page.evaluate(read_thumbnail);
            fail('the thumbnail did not fall back to the PENDING placeholder - saw '
                + JSON.stringify(now));
        } else {
            console.log('PASS: reset -> data-render-status=2 with ?v=0 (placeholder)');
        }

        const preparing = await page.waitForFunction(
            () => !!document.querySelector('.Document_Preview__preparing'),
            { timeout: STEP_TIMEOUT_MS }
        ).then(() => true).catch(() => false);

        if (!preparing) {
            fail('Document_Preview did not show its "Preparing preview..." state for a PENDING document');
        } else {
            console.log('PASS: Document_Preview shows the preparing state');
        }

        // --- Render, and watch the swap arrive over the wire --------------------------------
        await page.evaluate(() => { Spa.action().$sid('render').trigger('click'); });

        const rendered = await page.waitForFunction(() => {
            const img = document.querySelector('.Attachment_Thumbnail__img');
            if (!img) return false;
            const src = img.getAttribute('src') || '';
            const m = src.match(/[?&]v=(\d+)/);
            if (!m || parseInt(m[1], 10) === 0) return false;
            return img.getAttribute('data-render-status') === '3';
        }, { timeout: STEP_TIMEOUT_MS }).then(() => true).catch(() => false);

        if (!rendered) {
            const now = await page.evaluate(read_thumbnail);
            fail('the thumbnail never swapped to the rendered raster - saw ' + JSON.stringify(now)
                + ' (no realtime frame? this is a real failure, not a reason to reload)');
        } else {
            const after = await page.evaluate(read_thumbnail);
            console.log('PASS: render -> data-render-status=3 with v=' + after.v + ', no reload');
        }

        const viewer = await page.waitForFunction(
            () => !document.querySelector('.Document_Preview__preparing') && !!document.querySelector('.Pdf_Viewer'),
            { timeout: STEP_TIMEOUT_MS }
        ).then(() => true).catch(() => false);

        if (!viewer) {
            fail('Document_Preview did not leave the preparing state for a Pdf_Viewer');
        } else {
            console.log('PASS: Document_Preview swapped preparing -> Pdf_Viewer');
        }

        // The page must never have navigated - the whole point is that the DOM changed under a
        // live document.
        const navigated = await page.evaluate(() => performance.getEntriesByType('navigation').length > 1);
        if (navigated) {
            fail('the page navigated during the test - the swap must happen in place');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Attachment Thumbnail swap');
    } else {
        console.log('PASS: Attachment Thumbnail swap');
    }
}

run();
