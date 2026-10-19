#!/usr/bin/env node
/**
 * The browser Ajax transport (Ajax.js): one path, every call answered.
 *
 *   1. Calls made in one turn ride ONE /_ajax/_batch request when rsxapp.ajax_batching is on.
 *   2. With it off, every call is its own request - still to /_ajax/_batch.
 *   3. Two IDENTICAL calls are two calls on the wire: the transport never merges (it cannot
 *      know an endpoint is a read).
 *   4. An answer that does not carry a call REJECTS that call (server_error) instead of
 *      leaving its promise pending; the call the answer does carry is settled normally.
 *   5. An HTTP 500 rejects every call in the request with server_error.
 *   6. Every Ajax failure carries the marker the uncaught-error dialog keys on.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the
 * endpoint is Orm_Controller::fetch on the framework's User_Model (a read), and the faulty
 * answers in (4) and (5) are supplied by the test.
 *
 * Self-contained: mints its own dev-auth headers (system/bin/dev-auth.js) and runs with a
 * bare `node ajax_transport.js` against the dev web server on localhost.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;
const FETCH_URL = '/_ajax/Orm_Controller/fetch';

function fail(msg) {
    console.log('FAIL: Ajax transport - ' + msg);
    process.exitCode = 1;
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    // Every /_ajax/ request that leaves the page: [url, calls in its body].
    let requests = [];
    page.on('request', (request) => {
        if (!request.url().includes('/_ajax/')) {
            return;
        }
        const body = JSON.parse(request.postData() || '{}');
        requests.push({ url: request.url(), calls: body.batch_calls || null });
    });

    // A faulty answer the test arms for the next batch: 'drop_first' answers every call but
    // the first; 'http_500' fails the request.
    let sabotage = null;

    const auth_headers = { ...dev_auth_headers(ROUTE, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();

        if (sabotage !== null && url.includes('/_ajax/_batch')) {
            const mode = sabotage;
            sabotage = null;

            if (mode === 'http_500') {
                await route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>500</h1>' });
                return;
            }

            const calls = JSON.parse(request.postData() || '{}').batch_calls || [];
            const answer = {};
            for (const call of calls.slice(1)) {
                answer['C_' + call.call_id] = { _success: true, _ajax_return_value: { records: {} } };
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(answer) });
            return;
        }

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

        await page.evaluate(() => new Promise((resolve) => {
            if (typeof Rsx !== 'undefined' && Rsx.on) {
                Rsx.on('_debug_ready', () => resolve());
                setTimeout(resolve, 15000);
            } else {
                setTimeout(resolve, 3000);
            }
        }));

        const ready = await page.evaluate(() => typeof Ajax !== 'undefined' && typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!ready) {
            fail('the /_sys page did not boot - auth may have failed');
            return;
        }

        const params = { model: 'User_Model', ids: [USER_ID] };

        // --- 1 + 3. batching on: one request, identical calls kept apart ---
        requests = [];
        const batched = await page.evaluate(async ([url, params]) => {
            window.rsxapp.ajax_batching = true;
            const results = await Promise.all([Ajax.call(url, params), Ajax.call(url, params), Ajax.call(url, params)]);
            return results.map((r) => Object.keys(r.records).join(','));
        }, [FETCH_URL, params]);

        if (requests.length !== 1 || !requests[0].url.includes('/_ajax/_batch')) {
            fail('three calls in one turn should be one /_ajax/_batch request, saw ' + JSON.stringify(requests.map((r) => r.url)));
        } else if (requests[0].calls.length !== 3) {
            fail('three identical calls should be three calls on the wire, saw ' + requests[0].calls.length);
        } else if (batched.join('|') !== [USER_ID, USER_ID, USER_ID].join('|')) {
            fail('each identical call should get its own answer, got ' + JSON.stringify(batched));
        } else {
            console.log('PASS: calls in one turn share one request, and identical calls are not merged');
        }

        // --- 2. batching off: one request per call, still /_ajax/_batch ---
        requests = [];
        await page.evaluate(async ([url, params]) => {
            window.rsxapp.ajax_batching = false;
            try {
                await Promise.all([Ajax.call(url, params), Ajax.call(url, params)]);
            } finally {
                window.rsxapp.ajax_batching = true;
            }
        }, [FETCH_URL, params]);

        if (requests.length !== 2 || requests.some((r) => !r.url.includes('/_ajax/_batch') || r.calls.length !== 1)) {
            fail('with batching off each call should be its own /_ajax/_batch request, saw ' + JSON.stringify(requests));
        } else {
            console.log('PASS: with batching off every call is its own request through /_ajax/_batch');
        }

        // --- 4. an answer missing a call rejects that call ---
        sabotage = 'drop_first';
        const dropped = await page.evaluate(async ([url, params]) => {
            const outcomes = await Promise.allSettled([Ajax.call(url, params), Ajax.call(url, params)]);
            return outcomes.map((o) => o.status === 'fulfilled' ? 'ok' : o.reason.code + (o.reason.__rsx_ajax_error ? '+marked' : ''));
        }, [FETCH_URL, params]);

        if (dropped.join(',') !== 'server_error+marked,ok') {
            fail('a call missing from the answer must reject with server_error and the other settle; got ' + JSON.stringify(dropped));
        } else {
            console.log('PASS: a call missing from the answer is rejected, never left pending');
        }

        // --- 5. an HTTP 500 rejects every call ---
        sabotage = 'http_500';
        const crashed = await page.evaluate(async ([url, params]) => {
            const outcomes = await Promise.allSettled([Ajax.call(url, params), Ajax.call(url, params)]);
            return outcomes.map((o) => o.status === 'fulfilled' ? 'ok' : o.reason.code);
        }, [FETCH_URL, params]);

        if (crashed.join(',') !== 'server_error,server_error') {
            fail('an HTTP 500 must reject every call with server_error; got ' + JSON.stringify(crashed));
        } else {
            console.log('PASS: an HTTP 500 rejects every call in the request with server_error');
        }

        // --- 6. ORM failures carry the marker too ---
        const orm_marked = await page.evaluate(async () => {
            try {
                await User_Model.fetch(999999999);
                return 'NO THROW';
            } catch (e) {
                return e.code + (e.__rsx_ajax_error ? '+marked' : '');
            }
        });

        if (orm_marked !== 'not_found+marked') {
            fail('an ORM not-found must be a marked Ajax error; got ' + orm_marked);
        } else {
            console.log('PASS: an ORM failure carries the Ajax error marker');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Ajax transport');
    } else {
        console.log('PASS: Ajax transport');
    }
}

run();
