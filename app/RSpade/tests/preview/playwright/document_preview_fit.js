#!/usr/bin/env node
/**
 * Document Preview $fit + resize test (PREVIEW-DOCUMENT-PREVIEW-FIT, PREVIEW-DOCUMENT-PREVIEW-RESIZE).
 *
 * Drives /dev/document_preview against the sample PDF (sample_report.pdf) and proves the two
 * geometry behaviours the width-fit default cannot express:
 *
 *   FIT      with the Fit toggle on "contain", the WHOLE page fits the demo page's bounded host
 *            (800px x 70vh): the canvas CSS height is <= the frame height, where the same document
 *            under "width" is taller than the frame.
 *   RESIZE   growing the host re-RASTERS rather than stretching: the canvas BACKING size
 *            (canvas.width/height, not its CSS size) changes after the resize settles.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node document_preview_fit.js`. Runs against the dev web server on localhost. Depends on the
 * sample documents having been imported (import_sample_documents migration).
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

// Must exceed Pdf_Viewer.RESIZE_DEBOUNCE_MS (200) by enough to cover the re-raster itself.
const RESIZE_SETTLE_MS = 3000;

function fail(msg) {
    console.log('FAIL: Document Preview fit - ' + msg);
    process.exitCode = 1;
}

/**
 * Wait for pdf.js to paint a canvas whose backing size is real (the element's DEFAULT is 300x150,
 * so "has a canvas" is not the same question as "has rendered").
 */
async function wait_for_render(page) {
    return await page.waitForFunction(() => {
        const a = (typeof Spa !== 'undefined' && Spa.action) ? Spa.action() : null;
        if (!a) return false;
        const canvas = document.querySelector('.Pdf_Viewer__canvas');
        const frame = document.querySelector('.Document_Preview__frame');
        if (!canvas || !frame) return false;
        if (canvas.width === 300 && canvas.height === 150) return false;
        const rect = canvas.getBoundingClientRect();
        return {
            backing_width: canvas.width,
            backing_height: canvas.height,
            css_width: Math.round(rect.width),
            css_height: Math.round(rect.height),
            frame_width: frame.clientWidth,
            frame_height: frame.clientHeight,
        };
    }, { timeout: RENDER_TIMEOUT_MS }).then((h) => h.jsonValue()).catch(() => null);
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

        // --- Baseline: the default width fit overflows the bounded host vertically -------------
        await page.evaluate((v) => {
            const a = Spa.action();
            a.$sid('select').val(v).trigger('change');
        }, pdf_value);

        const width_fit = await wait_for_render(page);
        if (!width_fit) {
            fail('pdf.js did not render a canvas under the default width fit');
            await browser.close();
            return;
        }

        if (!(width_fit.css_height > width_fit.frame_height)) {
            fail('expected the width fit to overflow the bounded host (canvas css height '
                + width_fit.css_height + ' vs frame height ' + width_fit.frame_height + ')');
        } else {
            console.log('PASS: width fit overflows the bounded host ('
                + width_fit.css_height + 'px page in a ' + width_fit.frame_height + 'px frame)');
        }

        // --- PREVIEW-DOCUMENT-PREVIEW-FIT: contain fits the whole page inside the host ---------
        await page.evaluate(() => {
            const a = Spa.action();
            a.$sid('fit').val('contain').trigger('change');
        });

        const contain_fit = await wait_for_render(page);
        if (!contain_fit) {
            fail('pdf.js did not render a canvas under the contain fit');
            await browser.close();
            return;
        }

        if (!(contain_fit.css_height <= contain_fit.frame_height)) {
            fail('contain fit did not fit the page inside the host (canvas css height '
                + contain_fit.css_height + ' vs frame height ' + contain_fit.frame_height + ')');
        } else if (!(contain_fit.css_width > 0 && contain_fit.css_width <= contain_fit.frame_width)) {
            fail('contain fit produced an out-of-range canvas width (' + contain_fit.css_width
                + ' vs frame width ' + contain_fit.frame_width + ')');
        } else {
            console.log('PASS: contain fit shows the whole page ('
                + contain_fit.css_width + 'x' + contain_fit.css_height
                + ' inside ' + contain_fit.frame_width + 'x' + contain_fit.frame_height + ')');
        }

        // --- PREVIEW-DOCUMENT-PREVIEW-RESIZE: growing the host re-RASTERS ----------------------
        // The backing size is the assertion, not the CSS size: a stretched bitmap keeps its
        // backing size and only the CSS box grows.
        const before_backing = [contain_fit.backing_width, contain_fit.backing_height];

        await page.evaluate(() => {
            const host = Spa.action().$sid('preview_host')[0];
            host.style.width = '1200px';
            host.style.height = '900px';
        });

        const grew = await page.waitForFunction((before) => {
            const canvas = document.querySelector('.Pdf_Viewer__canvas');
            if (!canvas) return false;
            if (canvas.width === before[0] && canvas.height === before[1]) return false;
            return { backing_width: canvas.width, backing_height: canvas.height };
        }, before_backing, { timeout: RESIZE_SETTLE_MS + RENDER_TIMEOUT_MS })
            .then((h) => h.jsonValue()).catch(() => null);

        if (!grew) {
            fail('the canvas backing size did not change after the host grew - the bitmap was '
                + 'stretched instead of re-rendered (was ' + before_backing.join('x') + ')');
        } else if (!(grew.backing_width > before_backing[0] && grew.backing_height > before_backing[1])) {
            fail('the canvas was re-rendered but not larger: ' + before_backing.join('x')
                + ' -> ' + grew.backing_width + 'x' + grew.backing_height);
        } else {
            console.log('PASS: host resize re-rastered the page (' + before_backing.join('x')
                + ' -> ' + grew.backing_width + 'x' + grew.backing_height + ')');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Document Preview fit');
    } else {
        console.log('PASS: Document Preview fit');
    }
}

run();
