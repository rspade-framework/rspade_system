#!/usr/bin/env node
/**
 * Document Preview dynamic-viewer test (PREVIEW-DYNAMIC-VIEWER-ARGS).
 *
 * Document_Preview renders four viewers from its own template and instantiates every other
 * name in config('rsx.preview.viewers') - Markdown_Viewer, Spreadsheet_Viewer, any viewer an
 * app registers - in on_ready. Both framework viewers on that path read ONLY $attachment_id and
 * throw in on_create without it, so this proves the dynamic path hands it over: a markdown file
 * uploaded through Ajax.upload() and mounted in <Document_Preview> must reach the rendered
 * document (.Markdown_Viewer__body carrying the heading as an <h1>), with no page error.
 *
 * Runs on /_sys, a framework-served page, so it needs nothing from the application tree; the
 * fixture is created at run time through the ordinary upload transport. The upload stays
 * UNATTACHED and is released by the claim-window sweep (rsx:man file_disposal).
 *
 * The wait has no deadline: it ends on the rendered body OR on the first page or console error.
 * A viewer's missing-argument throw is the second - jqhtml logs a failed lifecycle hook with
 * console.error instead of letting it escape as an uncaught exception.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const HEADING = 'Dynamic Viewer Probe';

function fail(msg) {
    console.log('FAIL: Document Preview dynamic viewer - ' + msg);
    process.exitCode = 1;
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    const page_errors = [];
    let on_page_error = null;
    // jqhtml reports a component whose lifecycle threw through console.error rather than as an
    // uncaught exception, so a console error ends the wait exactly as a page error does.
    const record_error = (message) => {
        page_errors.push(message);
        if (on_page_error) on_page_error();
    };
    page.on('pageerror', (err) => record_error(err.message));
    page.on('console', (msg) => { if (msg.type() === 'error') record_error(msg.text()); });

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
        // Spa.action() throws until the SPA has booted, so the probe swallows that as "not yet".
        await page.waitForFunction(() => {
            try {
                return typeof Spa !== 'undefined' && !!Spa.action();
            } catch (e) {
                return false;
            }
        }, null, { timeout: 0 });

        const uploaded = await page.evaluate(async (heading) => {
            const fd = new FormData();
            fd.append('file', new Blob(['# ' + heading + '\n\nBody text.\n'], { type: 'text/markdown' }), 'dynamic_viewer_probe.md');
            const result = await Ajax.upload(fd);
            return result && result.attachment ? result.attachment.id : null;
        }, HEADING);

        if (!uploaded) {
            fail('Ajax.upload() returned no attachment');
            return;
        }

        await page.evaluate((id) => {
            $('<div class="dynamic-viewer-probe" style="height:400px">').appendTo('body').component('Document_Preview', { attachment_id: id });
        }, uploaded);

        const errored = new Promise((resolve) => { on_page_error = () => resolve(null); });
        if (page_errors.length) on_page_error();
        const rendered = page.waitForFunction(() => {
            const body = document.querySelector('.dynamic-viewer-probe .Markdown_Viewer__body');
            const h1 = body ? body.querySelector('h1') : null;
            return h1 ? h1.textContent.trim() : false;
        }, null, { timeout: 0 }).then((h) => h.jsonValue()).catch(() => null);

        const heading = await Promise.race([rendered, errored]);

        if (page_errors.length) {
            fail('page error: ' + page_errors.join(' | '));
        } else if (heading !== HEADING) {
            fail('expected the rendered <h1> "' + HEADING + '", got ' + JSON.stringify(heading));
        } else {
            console.log('PASS: Markdown_Viewer rendered through the dynamic path ("' + heading + '")');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Document Preview dynamic viewer');
    } else {
        console.log('PASS: Document Preview dynamic viewer');
    }
}

run();
