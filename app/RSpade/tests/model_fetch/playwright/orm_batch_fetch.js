#!/usr/bin/env node
/**
 * ORM batch fetch, client half (MODELFETCH-ORM-BATCH-FETCH).
 *
 * The batcher only exists in a browser, so this is where it is proved:
 *
 *   1. Three distinct User_Model.fetch_or_null() calls plus a DUPLICATE, all issued in one
 *      turn, produce exactly ONE Orm_Controller/fetch request, and each caller resolves
 *      with its OWN answer - the signed-in identity's record for the id that exists, null
 *      for the two that do not.
 *   2. fetch_or_null() on a nonexistent id resolves null.
 *   3. fetch() on a nonexistent id rejects with code 'not_found'.
 *   4. More distinct ids than rsx.model_fetch.batch_max_ids split into TWO requests -
 *      chunking is driven by the ids REQUESTED, so the ids need not exist.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys and the
 * model is User_Model, whose generated stub reaches every bundle - so no application
 * screen, model or bundle is involved and the probe runs in any install. The concern's own
 * fixture models could NOT serve this half: the test trees enter the manifest only while
 * rsx:test is running and this script drives the ordinary web server, whose manifest (and
 * whose database) carries neither the fixture class nor its table.
 *
 * The one existing id is the signed-in identity's own, which every install has by
 * construction. That is why the batch is one real id plus two missing ones rather than
 * three real ones: a fresh install has exactly one user, and per-caller resolution is
 * proved just as well by callers that get DIFFERENT answers.
 *
 * Self-contained: mints its own dev-auth headers through tests/_lib/dev_auth.js (the
 * node twin of Dev_Auth_Token, keyed on the local development grant), so it runs with a bare
 * `node orm_batch_fetch.js`. Runs against the dev web server on localhost (the same
 * target rsx:debug uses).
 *
 * Every Ajax call rides /_ajax/_batch, so the ORM fetch calls are counted out of the batch
 * bodies: one Orm_Controller/fetch CALL per model per chunk of ids, whatever request
 * carried it.
 *
 *   5. Two overlapping fetches of the same record share one lookup but each caller
 *      receives its OWN instance - a record one component changes is never another's.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const MISSING_ID = 99999999;
const MISSING_ID_2 = 99999998;

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

    // Count ORM fetch CALLS as they leave the browser. Every call rides /_ajax/_batch, so
    // they are read out of each batch body.
    let orm_requests = 0;
    page.on('request', (request) => {
        if (!request.url().includes('/_ajax/_batch')) {
            return;
        }
        const body = JSON.parse(request.postData() || '{}');
        for (const call of body.batch_calls || []) {
            if (call.controller === 'Orm_Controller' && call.action === 'fetch') {
                orm_requests++;
            }
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

        const model_present = await page.evaluate(() => typeof User_Model !== 'undefined');
        if (!model_present) {
            fail('User_Model is not in the served bundle - the generated model stub did not reach it');
            await browser.close();
            return;
        }

        const ids = [USER_ID, MISSING_ID, MISSING_ID_2, USER_ID];

        // --- 1. parallel + duplicate -> ONE request ---
        orm_requests = 0;
        const resolved = await page.evaluate(async (ids) => {
            const results = await Promise.all(ids.map((id) => User_Model.fetch_or_null(id)));
            return results.map((r) => (r === null ? 'null' : String(r.id)));
        }, ids);

        if (orm_requests !== 1) {
            fail('expected 1 Orm_Controller/fetch call for 4 parallel fetches, saw ' + orm_requests);
        } else {
            console.log('PASS: 4 parallel fetches (3 distinct + 1 duplicate) = 1 fetch call');
        }

        const expected = [String(USER_ID), 'null', 'null', String(USER_ID)].join(',');
        if (resolved.join(',') !== expected) {
            fail('each caller must get its own answer: expected [' + expected + '], got [' + resolved.join(',') + ']');
        } else {
            console.log('PASS: every caller resolved with its own answer');
        }

        // --- 2. fetch_or_null on a missing id ---
        const or_null = await page.evaluate(
            async (id) => String(await User_Model.fetch_or_null(id)),
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
                await User_Model.fetch(id);
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
            const results = await Promise.all(ids.map((id) => User_Model.fetch_or_null(id)));
            return results.length;
        }, cap);

        if (chunk_count !== cap + 1) {
            fail('expected ' + (cap + 1) + ' resolutions from the chunked fetch, got ' + chunk_count);
        }

        if (orm_requests !== 2) {
            fail('expected ' + (cap + 1) + ' ids to split into 2 fetch calls, saw ' + orm_requests);
        } else {
            console.log('PASS: ' + (cap + 1) + ' ids split into 2 fetch calls (cap ' + cap + ')');
        }

        // --- 5. one lookup, one instance per caller ---
        orm_requests = 0;
        const instances = await page.evaluate(async (id) => {
            const [a, b] = await Promise.all([User_Model.fetch_or_null(id), User_Model.fetch_or_null(id)]);
            a.__probe_mark = 'changed by caller a';
            return { distinct: a !== b, b_untouched: b.__probe_mark === undefined, same_id: a.id === b.id };
        }, USER_ID);

        if (orm_requests !== 1) {
            fail('expected one lookup for two overlapping fetches of one record, saw ' + orm_requests);
        } else if (!instances.distinct || !instances.b_untouched || !instances.same_id) {
            fail('overlapping fetches must give each caller its own instance: ' + JSON.stringify(instances));
        } else {
            console.log('PASS: overlapping fetches share one lookup and get separate instances');
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
