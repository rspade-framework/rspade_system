#!/usr/bin/env node
/**
 * ORM batch fetch, client half (MODELFETCH-ORM-BATCH-FETCH).
 *
 * The batcher only exists in a browser, so this is where it is proved:
 *
 *   1. Three distinct Task_Model.fetch() calls plus a DUPLICATE, all issued in one turn,
 *      produce exactly ONE Orm_Controller/fetch request, and each caller resolves with
 *      its own record.
 *   2. fetch_or_null() on a nonexistent id resolves null.
 *   3. fetch() on a nonexistent id rejects with code 'not_found'.
 *   4. More distinct ids than rsx.model_fetch.batch_max_ids split into TWO requests -
 *      chunking is driven by the ids REQUESTED, so the ids need not exist.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node orm_batch_fetch.js`. Runs against the dev web server on localhost (the same
 * target rsx:debug uses) and reads real Task ids off the page it lands on.
 *
 * NOTE: the dev site runs in development mode, where Ajax batching (the TRANSPORT
 * batcher, /_ajax/_batch) is off - so each ORM request is its own direct
 * /_ajax/Orm_Controller/fetch POST and the request count is read straight off the wire.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/tasks';
const USER_ID = 1;
const MISSING_ID = 99999999;

function fail(msg) {
    console.log('FAIL: ORM batch fetch - ' + msg);
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

    // Count ORM fetch requests as they leave the browser.
    let orm_requests = 0;
    page.on('request', (request) => {
        if (request.url().includes('/_ajax/Orm_Controller/fetch')) {
            orm_requests++;
        }
    });

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
            fail('SPA action not present - auth may have failed');
            await browser.close();
            return;
        }

        // Real task ids, straight off the rendered datagrid rows (href = /tasks/view/:id).
        const task_ids = await page.evaluate(() => {
            const ids = [];
            $('tr[data-href]').each(function () {
                const m = ($(this).attr('data-href') || '').match(/\/tasks\/view\/(\d+)/);
                if (m) {
                    ids.push(parseInt(m[1], 10));
                }
            });
            return ids;
        });

        if (task_ids.length < 3) {
            fail('need at least 3 task rows on ' + ROUTE + ', found ' + task_ids.length);
            await browser.close();
            return;
        }

        const ids = task_ids.slice(0, 3);

        // --- 1. parallel + duplicate -> ONE request ---
        orm_requests = 0;
        const resolved = await page.evaluate(async (ids) => {
            const results = await Promise.all([
                Task_Model.fetch(ids[0]),
                Task_Model.fetch(ids[1]),
                Task_Model.fetch(ids[2]),
                Task_Model.fetch(ids[0]),
            ]);
            return results.map((r) => r.id);
        }, ids);

        if (orm_requests !== 1) {
            fail('expected 1 Orm_Controller/fetch request for 4 parallel fetches, saw ' + orm_requests);
        } else {
            console.log('PASS: 4 parallel fetches (3 distinct + 1 duplicate) = 1 request');
        }

        const expected = [ids[0], ids[1], ids[2], ids[0]].join(',');
        if (resolved.join(',') !== expected) {
            fail('each caller must get its own record: expected [' + expected + '], got [' + resolved.join(',') + ']');
        } else {
            console.log('PASS: every caller resolved with its own record');
        }

        // --- 2. fetch_or_null on a missing id ---
        const or_null = await page.evaluate(
            async (id) => String(await Task_Model.fetch_or_null(id)),
            MISSING_ID
        );

        if (or_null !== 'null') {
            fail('fetch_or_null on a missing id returned "' + or_null + '", expected null');
        } else {
            console.log('PASS: fetch_or_null on a missing id resolves null');
        }

        // --- 3. fetch on a missing id ---
        const thrown = await page.evaluate(async (id) => {
            try {
                await Task_Model.fetch(id);
                return 'NO THROW';
            } catch (e) {
                return e.code + '|' + e.message;
            }
        }, MISSING_ID);

        if (thrown !== 'not_found|Record not found') {
            fail('fetch on a missing id gave "' + thrown + '", expected "not_found|Record not found"');
        } else {
            console.log('PASS: fetch on a missing id rejects with code not_found');
        }

        // --- 4. chunking past the cap ---
        const cap = await page.evaluate(() => window.rsxapp.model_fetch.batch_max_ids);
        orm_requests = 0;

        const chunk_count = await page.evaluate(async (cap) => {
            const ids = [];
            for (let i = 1; i <= cap + 1; i++) {
                // Deliberately nonexistent: chunking is decided by the ids REQUESTED.
                ids.push(900000000 + i);
            }
            const results = await Promise.all(ids.map((id) => Task_Model.fetch_or_null(id)));
            return results.length;
        }, cap);

        if (chunk_count !== cap + 1) {
            fail('expected ' + (cap + 1) + ' resolutions from the chunked fetch, got ' + chunk_count);
        }

        if (orm_requests !== 2) {
            fail('expected ' + (cap + 1) + ' ids to split into 2 requests, saw ' + orm_requests);
        } else {
            console.log('PASS: ' + (cap + 1) + ' ids split into 2 requests (cap ' + cap + ')');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: ORM batch fetch');
    } else {
        console.log('PASS: ORM batch fetch');
    }
}

run();
