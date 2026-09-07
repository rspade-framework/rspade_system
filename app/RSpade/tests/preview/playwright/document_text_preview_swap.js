#!/usr/bin/env node
/**
 * Document_Text_Preview swap test (PREVIEW-TEXT-PREVIEW-SWAP).
 *
 * The behavior the component exists for: a document whose text has not been extracted yet paints
 * "(Extracting Text...)", and when the extraction pass finishes the real text REPLACES IT IN PLACE
 * - no reload, no polling.
 *
 * Drives /dev/document_preview against the seeded sample_memo.docx:
 *   1. select it -> <Document_Text_Preview> mounts and shows the extracted text.
 *   2. Reset Extraction -> the blob's index row is deleted and is_indexed goes back to 0, with NO
 *      worker spawned. Assert the notice reads "(Extracting Text...)".
 *   3. Extract Now -> the render/extract pass runs inline in that request. WITHOUT RELOADING,
 *      assert the notice is gone and .Document_Text_Preview__text is non-empty.
 *
 * Step 3 is the assertion with teeth: the page is never navigated, so the only thing that can have
 * changed the DOM is the realtime frame the pass emitted on the attachment. If the frame does not
 * arrive this test FAILS - it must never be "fixed" by reloading the page, which would prove
 * nothing.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare `node document_text_preview_swap.js`. Runs against
 * the dev web server on localhost. Requires: realtime enabled, and the sample documents imported
 * (import_sample_documents migration).
 *
 * NOT RUNNABLE AS SHIPPED: /dev/document_preview declares #[Auth('closed')] (B-99), so the harness
 * reports the closed gate rather than passing vacuously. Open that surface in your own tree to run
 * this test.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/dev/document_preview';
const USER_ID = 1;
const SAMPLE_DOCX_NAME = 'sample_memo.docx';
const STEP_TIMEOUT_MS = 30000;

function fail(msg) {
    console.log('FAIL: Document_Text_Preview swap - ' + msg);
    process.exitCode = 1;
}

/** The live state of the mounted text preview, read straight off the DOM. */
function read_text_preview() {
    const notice = document.querySelector('.Document_Text_Preview__notice');
    const text = document.querySelector('.Document_Text_Preview__text');
    if (!notice && !text) return null;
    return {
        notice: notice ? (notice.textContent || '').trim() : null,
        text_length: text ? (text.textContent || '').trim().length : 0,
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
                + "The template ships /dev/document_preview as #[Auth('closed')]; open that "
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

        const mounted = await page.waitForFunction(read_text_preview, { timeout: STEP_TIMEOUT_MS })
            .then((h) => h.jsonValue()).catch(() => null);

        if (!mounted) {
            fail('<Document_Text_Preview> never rendered for the selected attachment');
            await browser.close();
            return;
        }
        console.log('PASS: text preview mounted (notice ' + JSON.stringify(mounted.notice)
            + ', text length ' + mounted.text_length + ')');

        // --- Reset to un-indexed ------------------------------------------------------------
        await page.evaluate(() => { Spa.action().$sid('reset_extraction').trigger('click'); });

        const pending = await page.waitForFunction(() => {
            const notice = document.querySelector('.Document_Text_Preview__notice');
            return !!notice && (notice.textContent || '').trim() === '(Extracting Text...)';
        }, { timeout: STEP_TIMEOUT_MS }).then(() => true).catch(() => false);

        if (!pending) {
            const now = await page.evaluate(read_text_preview);
            fail('the text preview did not fall back to the extracting notice - saw ' + JSON.stringify(now));
        } else {
            console.log('PASS: reset -> "(Extracting Text...)"');
        }

        // --- Extract, and watch the swap arrive over the wire --------------------------------
        await page.evaluate(() => { Spa.action().$sid('run_extraction').trigger('click'); });

        const extracted = await page.waitForFunction(() => {
            const notice = document.querySelector('.Document_Text_Preview__notice');
            const text = document.querySelector('.Document_Text_Preview__text');
            return !notice && !!text && (text.textContent || '').trim().length > 0;
        }, { timeout: STEP_TIMEOUT_MS }).then(() => true).catch(() => false);

        if (!extracted) {
            const now = await page.evaluate(read_text_preview);
            fail('the notice never swapped to the extracted text - saw ' + JSON.stringify(now)
                + ' (no realtime frame? this is a real failure, not a reason to reload)');
        } else {
            const after = await page.evaluate(read_text_preview);
            console.log('PASS: extract -> text length ' + after.text_length + ', no reload');
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
        console.log('FAIL: Document_Text_Preview swap');
    } else {
        console.log('PASS: Document_Text_Preview swap');
    }
}

run();
