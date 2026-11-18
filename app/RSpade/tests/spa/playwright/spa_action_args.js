#!/usr/bin/env node
/**
 * Route and query parameters reaching an SPA action's this.args (SPA-ARGS-01..12).
 *
 * Every SPA action reads its URL through this.args, filled by the client router
 * (Spa.match_url_to_route()) on every in-SPA dispatch. The contract (rsx:man spa, URL
 * Parameters, and NAVIGATION):
 *
 *   a. a :param segment of the @route pattern arrives under its name;
 *   b. every query-string key arrives under its own name, beside the route parameters;
 *   c. values arrive as STRINGS, exactly as the URL spelled them (percent-decoded) - the
 *      router coerces nothing, so ?n=5 is "5" and a bare ?flag is "";
 *   c2. a query value reads as the server reads it: a pair splits at its FIRST '=' (?q=a=b
 *      is "a=b") and '+' is a space (form encoding); in a path segment '+' stays a plus;
 *   d. a route parameter WINS over a query key of the same name (the server dispatcher's
 *      precedence);
 *   e. a route with no :param still receives the query;
 *   f. a navigation that changes only the #fragment does not re-dispatch: the same action
 *      instance stays mounted with the same args; a change of query re-dispatches a new
 *      instance carrying the new args.
 * *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S. The page is the control panel at /_sys; the
 * patterns under test are registered at run time in the browser (Spa.register_route) onto
 * the panel's own System Info action, so the router - not any one action - is the subject,
 * and the dispatches are in-SPA (Rsx.navigate), never a document load the server would have
 * to recognise.
 *
 * Self-contained: runs with a bare `node spa_action_args.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page, poll_until } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: spa action args - ' + msg);
    process.exitCode = 1;
}

function check(label, actual, expected) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log('PASS: ' + label);
        return true;
    }
    fail(label + ' - expected ' + e + ', got ' + a);
    return false;
}

/**
 * Navigate in the SPA and return the args of the action that is then mounted, its argument
 * types, and whether it is the instance that was mounted before the call. A navigation that
 * should re-dispatch is waited for by polling until a NEW instance is mounted and ready.
 */
async function go(page, url, expect_new_instance = true) {
    await page.evaluate(() => {
        window.__args_probe_seq = (window.__args_probe_seq || 0) + 1;
        Spa.action().__args_probe_tag = window.__args_probe_seq;
    });
    await page.evaluate((target) => Rsx.navigate(target), url);
    if (expect_new_instance) {
        await poll_until(page, 'a new action is mounted for ' + url,
            () => !!Spa.action() && Spa.action().__args_probe_tag === undefined);
    }
    return await page.evaluate(async () => {
        const action = Spa.action();
        await action.ready();
        const args = {};
        const types = {};
        for (const key of Object.keys(action.args).sort()) {
            // Only the URL's own keys are compared: jqhtml's reserved keys ($sid, which the
            // layout's content slot stamps on the action) are not the router's output.
            if (key.startsWith('_') || key === 'skip_cache_read' || key === 'sid') {
                continue;
            }
            args[key] = action.args[key];
            types[key] = typeof action.args[key];
        }
        return {
            args,
            types,
            same_instance: action.__args_probe_tag === window.__args_probe_seq,
            action: action.constructor.name,
            location: window.location.pathname + window.location.search + window.location.hash,
        };
    });
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const registered = await page.evaluate(() => {
            Spa.register_route('/_sys/args-probe', _Sys_System_Info_Action);
            Spa.register_route('/_sys/args-probe/:id', _Sys_System_Info_Action);
            Spa.register_route('/_sys/args-probe/:id/item/:item_id', _Sys_System_Info_Action);
            return !!Spa.match_url_to_route('/_sys/args-probe/1');
        });
        if (!check('the probe routes are registered with the router', registered, true)) {
            return;
        }

        // --- a + c. a route parameter, as a string --------------------------------------
        let r = await go(page, '/_sys/args-probe/42');
        check('SPA-ARGS-01 the route parameter arrives under its name', r.args, { id: '42' });
        check('SPA-ARGS-01 a numeric route parameter is a string', r.types, { id: 'string' });
        check('SPA-ARGS-01 the probe route dispatched the probe action', r.action, '_Sys_System_Info_Action');

        // --- two route parameters ----------------------------------------------------
        r = await go(page, '/_sys/args-probe/7/item/9');
        check('SPA-ARGS-02 every :param segment arrives', r.args, { id: '7', item_id: '9' });

        // --- b + c. query keys beside route parameters, as strings -------------------------
        r = await go(page, '/_sys/args-probe/42?q=1&tab=history');
        check('SPA-ARGS-03 query keys arrive beside the route parameter', r.args, { id: '42', q: '1', tab: 'history' });
        check('SPA-ARGS-03 a numeric query value is a string', r.types, { id: 'string', q: 'string', tab: 'string' });

        // --- c. a key with no value is the empty string --------------------------------
        r = await go(page, '/_sys/args-probe/42?flag&empty=');
        check('SPA-ARGS-04 a bare key and an empty value are both ""', r.args, { empty: '', flag: '', id: '42' });

        // --- c. percent-encoding is decoded, in the path and in the query --------------------
        r = await go(page, '/_sys/args-probe/a%20b?q=x%26y%3Dz&name=J%C3%B6rg');
        check('SPA-ARGS-05 percent-encoded values arrive decoded',
            r.args, { id: 'a b', name: 'Jörg', q: 'x&y=z' });

        // --- c2. the query reads as the server reads it -------------------------------------
        r = await go(page, '/_sys/args-probe/42?q=a=b&token=YWJj==');
        check('SPA-ARGS-11 a literal = inside a query value survives', r.args, { id: '42', q: 'a=b', token: 'YWJj==' });

        r = await go(page, '/_sys/args-probe/a+b?q=x+y&name=a%2Bb');
        check('SPA-ARGS-12 + is a space in the query, a plus in the path, %2B a plus everywhere',
            r.args, { id: 'a+b', name: 'a+b', q: 'x y' });

        // --- d. a route parameter wins over a query key of the same name ----------------------
        r = await go(page, '/_sys/args-probe/42?id=9&q=1');
        check('SPA-ARGS-06 the route parameter wins over the query key', r.args, { id: '42', q: '1' });

        // --- e. a route without parameters still receives the query --------------------
        r = await go(page, '/_sys/args-probe?q=1&id=5');
        check('SPA-ARGS-07 an exact route receives the query as args', r.args, { id: '5', q: '1' });

        // --- f. a fragment-only navigation keeps the action and its args ----------------------
        r = await go(page, '/_sys/args-probe/42?q=1');
        const before = r.args;
        r = await go(page, '/_sys/args-probe/42?q=1#panel=open', false);
        check('SPA-ARGS-08 a fragment-only navigation leaves the same action mounted', r.same_instance, true);
        check('SPA-ARGS-08 and its args unchanged', r.args, before);
        check('SPA-ARGS-08 the address carries the fragment', r.location, '/_sys/args-probe/42?q=1#panel=open');

        // --- f. a query change re-dispatches with the new args -----------------------------
        r = await go(page, '/_sys/args-probe/42?q=2');
        check('SPA-ARGS-09 a query change mounts a new action', r.same_instance, false);
        check('SPA-ARGS-09 carrying the new query value', r.args, { id: '42', q: '2' });

        // --- the reserved breadcrumb-origin key never reaches this.args ---------------------
        const via = await page.evaluate(() => Spa.match_url_to_route('/_sys/args-probe/42?_via=x&q=1').args);
        check('SPA-ARGS-10 the reserved _via key is not an arg', via, { q: '1', id: '42' });

        const unexpected = errors.filter((e) => !e.includes('Spa: Duplicate route'));
        check('no page errors', unexpected, []);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: spa action args');
    } else {
        console.log('PASS: spa action args');
    }
}

run();
