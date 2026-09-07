#!/usr/bin/env node
/**
 * Document Preview SPA test (PREVIEW-DOCUMENT-PREVIEW-SPA).
 *
 * Drives /dev/document_preview end to end: selects the sample PDF (sample_report.pdf), waits for
 * pdf.js to render onto the canvas, asserts a multi-page document ("Page 1 of N", N > 1), clicks
 * Next and asserts the page indicator advances, and asserts the <Document_Text_Preview>
 * mounted beside it has rendered non-empty text. Proves the Document_Preview component + Pdf_Viewer (lazy pdf.js) + the extraction
 * pipeline are wired together.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare `node document_preview_spa.js`. Runs against the dev
 * web server on localhost (same target rsx:debug uses). Depends on the sample documents having been
 * imported (import_sample_documents migration) and indexed; on the dev box that is the live state.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/dev/document_preview';
const USER_ID = 1;
const SAMPLE_PDF_NAME = 'sample_report.pdf';
const RENDER_TIMEOUT_MS = 20000;

function fail(msg) {
    console.log('FAIL: Document Preview SPA - ' + msg);
    process.exitCode = 1;
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

        // Confirm we authenticated (the action loaded its attachment list).
        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed, or the route is closed. '
                + "The template ships /dev/document_preview as #[Auth('closed')]; open that "
                + 'surface in your own tree to run this test.');
            await browser.close();
            return;
        }

        // Find + select the sample PDF option.
        const pdf_value = await page.evaluate((name) => {
            const a = Spa.action();
            const opts = a.$sid('select').find('option').toArray();
            const match = opts.find((o) => (o.textContent || '').includes(name));
            return match ? match.value : null;
        }, SAMPLE_PDF_NAME);

        if (!pdf_value) {
            fail(SAMPLE_PDF_NAME + ' not present in the attachment picker (was the sample-documents migration run?)');
            await browser.close();
            return;
        }

        await page.evaluate((v) => {
            const a = Spa.action();
            a.$sid('select').val(v).trigger('change');
        }, pdf_value);

        // Poll until pdf.js renders the first page (canvas gains real dimensions AND the indicator
        // reports a known page count).
        const rendered = await page.waitForFunction(() => {
            const canvas = document.querySelector('.Pdf_Viewer__canvas');
            const a = (typeof Spa !== 'undefined' && Spa.action) ? Spa.action() : null;
            const ind = a ? a.$sid('indicator').text() : '';
            const m = ind.match(/Page\s+(\d+)\s+of\s+(\d+)/);
            if (!canvas || canvas.width < 100 || canvas.height < 100) return false;
            if (!m) return false;
            return { width: canvas.width, height: canvas.height, page: parseInt(m[1], 10), pages: parseInt(m[2], 10) };
        }, { timeout: RENDER_TIMEOUT_MS }).then((h) => h.jsonValue()).catch(() => null);

        if (!rendered) {
            fail('pdf.js did not render a canvas with a page count within the timeout');
            await browser.close();
            return;
        }

        if (!(rendered.width > 0 && rendered.height > 0)) {
            fail('canvas has zero dimensions after render');
        } else {
            console.log('PASS: canvas rendered (' + rendered.width + 'x' + rendered.height + ')');
        }

        if (!(rendered.pages > 1)) {
            fail('expected a multi-page PDF (N > 1), got "Page ' + rendered.page + ' of ' + rendered.pages + '"');
        } else {
            console.log('PASS: multi-page indicator "Page ' + rendered.page + ' of ' + rendered.pages + '"');
        }

        // Click Next and assert the indicator advances.
        await page.evaluate(() => { Spa.action().$sid('next').trigger('click'); });
        const advanced = await page.waitForFunction(() => {
            const a = Spa.action();
            const m = a.$sid('indicator').text().match(/Page\s+(\d+)\s+of\s+(\d+)/);
            return m && parseInt(m[1], 10) === 2;
        }, { timeout: RENDER_TIMEOUT_MS }).then(() => true).catch(() => false);

        if (!advanced) {
            fail('Next did not advance the page indicator to page 2');
        } else {
            console.log('PASS: Next advanced to page 2');
        }

        // Extracted text populated - read off <Document_Text_Preview>, which owns that panel.
        const text_len = await page.evaluate(() => {
            const el = document.querySelector('.Document_Text_Preview__text');
            return el ? (el.textContent || '').trim().length : 0;
        });
        if (!(text_len > 0)) {
            fail('.Document_Text_Preview__text is empty or absent (was the sample document indexed?)');
        } else {
            console.log('PASS: extracted text present (' + text_len + ' chars)');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Document Preview SPA');
    } else {
        console.log('PASS: Document Preview SPA');
    }
}

run();
