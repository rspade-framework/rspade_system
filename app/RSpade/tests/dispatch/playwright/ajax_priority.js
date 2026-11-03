#!/usr/bin/env node
/**
 * The client half of the priority Ajax channel (Ajax.js):
 *
 *   1. A call whose endpoint is marked `.priority = true` (what a generated stub sets for a
 *      #[Priority] endpoint) leaves ALONE, to /_ajax/_priority, in the batch envelope -
 *      even with batching on and other calls made in the same turn, which still share one
 *      /_ajax/_batch request.
 *   2. The same endpoint called through an unmarked reference goes through /_ajax/_batch.
 *   3. The server refuses a call to an endpoint WITHOUT #[Priority] on that channel with the
 *      not_found envelope, and the client rejects the call with that code (never pending).
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S (a browser test cannot see the test tree): the
 * page is /_sys, and the endpoint is Orm_Controller::fetch on User_Model - a read with no
 * #[Priority], so the marked reference built in the browser is exactly what (3) needs. The
 * server half (an endpoint that IS #[Priority]) is php/Ajax_Priority_Transport_Test.php.
 *
 * Self-contained: run with a bare `node ajax_priority.js` against the dev web server on
 * localhost. Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const USER_ID = 1;
const FETCH_URL = '/_ajax/Orm_Controller/fetch';

function fail(msg) {
    console.log('FAIL: Ajax priority - ' + msg);
    process.exitCode = 1;
}

async function run() {
    const { browser, page } = await open_sys_page();

    // Every /_ajax/ request carrying this test's calls: its path and how many it carries.
    // The panel makes calls of its own while it is open; a request with none of the test's
    // ORM fetches is not this test's.
    let requests = [];
    page.on('request', (request) => {
        if (!request.url().includes('/_ajax/')) {
            return;
        }
        const body = JSON.parse(request.postData() || '{}');
        const ours = (body.batch_calls || []).filter((call) => call.controller === 'Orm_Controller' && call.action === 'fetch'
            && call.params && call.params.model === 'User_Model');
        if (ours.length > 0) {
            requests.push({ path: new URL(request.url()).pathname, calls: (body.batch_calls || []).length });
        }
    });

    try {
        const params = { model: 'User_Model', ids: [USER_ID] };

        // --- 1 + 3. one marked call and two plain ones in the same turn ---
        requests = [];
        const outcomes = await page.evaluate(async ([url, params]) => {
            window.rsxapp.ajax_batching = true;
            const marked = { path: url, priority: true };
            const settled = await Promise.allSettled([
                Ajax.call(marked, params),
                Ajax.call(url, params),
                Ajax.call(url, params),
            ]);
            return settled.map((o) => o.status === 'fulfilled' ? 'ok' : o.reason.code);
        }, [FETCH_URL, params]);

        const priority = requests.filter((r) => r.path.endsWith('/_ajax/_priority'));
        const batch = requests.filter((r) => r.path.endsWith('/_ajax/_batch'));

        if (requests.length !== 2 || priority.length !== 1 || batch.length !== 1) {
            fail('expected one /_ajax/_priority and one /_ajax/_batch request, saw ' + JSON.stringify(requests));
        } else if (priority[0].calls !== 1 || batch[0].calls !== 2) {
            fail('the priority request must carry its one call and the batch the other two, saw ' + JSON.stringify(requests));
        } else {
            console.log('PASS: a marked call leaves alone to /_ajax/_priority; the rest of the turn shares one batch');
        }

        if (outcomes.join(',') !== 'not_found,ok,ok') {
            fail('an endpoint without #[Priority] must be refused not_found on the priority channel; got ' + JSON.stringify(outcomes));
        } else {
            console.log('PASS: the server refuses an endpoint without #[Priority] there, and the call rejects not_found');
        }

        // --- 2. an unmarked reference to the same endpoint goes through the batch ---
        requests = [];
        await page.evaluate(async ([url, params]) => {
            await Ajax.call({ path: url }, params);
        }, [FETCH_URL, params]);

        if (requests.length !== 1 || !requests[0].path.endsWith('/_ajax/_batch')) {
            fail('an unmarked call must go through /_ajax/_batch, saw ' + JSON.stringify(requests));
        } else {
            console.log('PASS: an unmarked call goes through /_ajax/_batch');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: Ajax priority');
    } else {
        console.log('PASS: Ajax priority');
    }
}

run();
