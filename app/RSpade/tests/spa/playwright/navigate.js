#!/usr/bin/env node
/**
 * Rsx.navigate() / Rsx.redirect() - the one navigation door (SPA-NAVIGATE-01..20).
 *
 * Every way code moves the browser goes through Rsx_Navigation: validate the options,
 * classify the target (elsewhere | same_site | in_app), carry the breadcrumb origin, then
 * ONE transport - a load:'none' address rewrite, a native fragment move, a reload of the
 * exact URL on screen, an in-app dispatch, or a full page load that hands the origin across
 * (session storage, read once by the next SPA boot). Only a browser has real history, real
 * document loads and real session storage, so this is where the behaviour is proved.
 *
 * Runs on the framework's own /_sys panel. The breadcrumb structure the origin rows need is
 * declared by an init script at DOMContentLoaded - before the SPA boots - so it is already
 * in place when a FULL LOAD's first dispatch judges its origin:
 *
 *   /_sys/users/:id      accepts 'site', VERIFIES the id is '1'        <- decider A
 *   /_sys/system-info    parent /_sys/users/1                          <- a page beneath A
 *   /_sys/workers        accepts 'site' (always)                       <- decider B
 *
 * Another host is answered by an interceptor (http://elsewhere.test), so 'elsewhere' is a
 * real cross-origin navigation that never leaves the machine.
 *
 * Every wait after something that can load a document is a poll_until() (one evaluate per
 * probe), never page.waitForFunction() or an evaluate spanning the load: a full load - and a
 * first visit's timezone-cookie bootstrap, which can replace the document once more - destroys
 * the execution context an in-flight evaluate runs in. A full load is detected by a probe
 * value planted on the old document disappearing, so no navigation event has to be caught.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page, poll_until, BASE_URL } = require('../../_lib/playwright/sys_page.js');

const ELSEWHERE = 'http://elsewhere.test';

function fail(msg) {
    console.log('FAIL: navigate - ' + msg);
    process.exitCode = 1;
}

function check(ok, pass_msg, detail) {
    if (ok) {
        console.log('PASS: ' + pass_msg);
    } else {
        fail(pass_msg + ' - got ' + JSON.stringify(detail));
    }
}

/**
 * Declared on every /_sys document before its SPA boots: the breadcrumb structure, a
 * document mark (gone after a real load), and counters for dispatches, popstate and
 * spa_hash_change.
 */
function init_script() {
    document.addEventListener('DOMContentLoaded', () => {
        if (!location.pathname.startsWith('/_sys')) {
            return;
        }

        const parent = (cls, fn) => { cls.prototype.breadcrumb_parent = async function () { return fn.call(this); }; };
        parent(_Sys_Users_Action, () => '/_sys');
        parent(_Sys_User_View_Action, () => '/_sys/users');
        parent(_Sys_System_Info_Action, () => '/_sys/users/1');
        parent(_Sys_Task_Workers_Action, () => '/_sys');
        parent(_Sys_Tasks_Action, () => '/_sys');

        _Sys_User_View_Action.breadcrumb_via = ['site'];
        _Sys_User_View_Action.prototype.breadcrumb_parent_via = async function (via) {
            return via.id === '1' ? '/_sys/sites/' + via.id : null;
        };

        _Sys_Task_Workers_Action.breadcrumb_via = ['site'];
        _Sys_Task_Workers_Action.prototype.breadcrumb_parent_via = async function (via) {
            return '/_sys/sites/' + via.id;
        };

        window.__counts = { dispatch: 0, popstate: 0, hash_change: 0 };
        window.addEventListener('popstate', () => { window.__counts.popstate++; });
        Rsx.on('spa_dispatch_start', () => { window.__counts.dispatch++; });
        Rsx.on('spa_hash_change', () => { window.__counts.hash_change++; });
    }, { once: true });
}

/** Wait for an SPA page, done dispatching, whose breadcrumb origin has been settled. */
async function settled(page) {
    await poll_until(page, 'an SPA action done dispatching, with its breadcrumb origin settled and the probe counters installed',
        () => typeof Spa !== 'undefined' && !!Spa.action() && !Spa.is_dispatching
            && !!Spa.action()._spa_via_settlement && !!window.__counts);
}

/** Everything the origin rows compare, once the page has settled its origin. */
async function origin_state(page) {
    await settled(page);
    // Read-only, so a probe repeated after a document replaced under it is harmless.
    return poll_until(page, 'the settled origin state could be read', async () => {
        const settlement = await Spa.action()._spa_via_settlement;
        // The decision rewrites the entry once the walk is done; read the address after it.
        await new Promise((resolve) => requestAnimationFrame(() => resolve()));
        return {
            loc: location.pathname + location.search,
            mark: window.__mark || null,
            arrival: Spa._via_navigation ? Spa._via_navigation.arrival : null,
            previous: Spa._via_navigation ? (Spa._via_navigation.previous ?? null) : null,
            kept: settlement.kept,
            decider: history.state ? (history.state.via_decider ?? null) : null,
            handoff: Rsx_Storage.session_get(Rsx_Navigation.HANDOFF_KEY),
        };
    });
}

let full_load_serial = 0;

/**
 * Run `trigger` (a function evaluated in the page that STARTS a navigation and returns at
 * once, never awaiting it) and wait until a NEW document has replaced this one and parsed.
 * The old document carries a probe value the new one lacks; the trigger's own evaluate may
 * die with the document it ran in, which is the load this waits for, not a failure.
 */
async function full_load(page, trigger, arg) {
    const probe = 'navigate-probe-' + (++full_load_serial);
    await page.evaluate((value) => { window.__full_load_probe = value; }, probe);
    try {
        await page.evaluate(trigger, arg);
    } catch (e) {
        if (!/Execution context was destroyed|navigat/i.test(e.message)) {
            throw e;
        }
    }
    await poll_until(page, 'a new document replaced the one the navigation started from (' + probe + ')',
        (value) => window.__full_load_probe !== value && document.readyState !== 'loading', probe);
}

/** Navigate in the SPA and wait for the origin decision. */
async function in_app(page, url, options = {}) {
    await page.evaluate(({ url, options }) => Rsx.navigate(url, options), { url, options });
    return origin_state(page);
}

async function mark(page) {
    await page.evaluate(() => { window.__mark = 'original'; });
}

async function run() {
    const { browser, page } = await open_sys_page();

    try {
        await page.addInitScript(init_script);
        await page.route(ELSEWHERE + '/**', (route) => route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>elsewhere</title><p>elsewhere</p>',
        }));
        await page.reload({ waitUntil: 'commit' });
        await settled(page);

        // --- 1. options are validated ---
        const refused = await page.evaluate(() => {
            const attempt = (fn) => { try { fn(); return null; } catch (e) { return e.message; } };
            return {
                unknown_key: attempt(() => Rsx.navigate('/_sys/tasks', { bogus: 1 })),
                bad_history: attempt(() => Rsx.navigate('/_sys/tasks', { history: 'auto' })),
                none_history: attempt(() => Rsx.navigate('/_sys/tasks', { history: 'none' })),
                bad_load: attempt(() => Rsx.navigate('/_sys/tasks', { load: 'spa' })),
                bad_via: attempt(() => Rsx.navigate('/_sys/tasks', { via: 'keep' })),
                bad_hint: attempt(() => Rsx.navigate('/_sys/tasks', { title_hint: 42 })),
                none_continue: attempt(() => Rsx.navigate('/_sys/tasks', { load: 'none', via: 'continue' })),
                redirect_history: attempt(() => Rsx.redirect('/_sys/tasks', { history: 'replace' })),
            };
        });
        check(refused.unknown_key !== null && refused.unknown_key.includes('history') && refused.unknown_key.includes('title_hint'),
            'an unknown option key throws, naming the accepted options', refused.unknown_key);
        check(refused.bad_history !== null && refused.bad_history.includes("'push'") && refused.bad_history.includes("'replace'")
            && refused.none_history !== null,
            "{history} accepts only 'push' and 'replace' ('auto' and 'none' throw)", refused);
        check(refused.bad_load !== null && refused.bad_load.includes("'full'") && refused.bad_load.includes("'none'"),
            'an unknown {load} throws, naming the accepted values', refused.bad_load);
        check(refused.bad_via !== null && refused.bad_via.includes("'continue'"), 'an unknown {via} throws', refused.bad_via);
        check(refused.bad_hint !== null, 'a non-string {title_hint} throws', refused.bad_hint);
        check(refused.none_continue !== null, "{load: 'none'} with {via: 'continue'} throws", refused.none_continue);
        check(refused.redirect_history !== null && refused.redirect_history.includes('Rsx.redirect()'),
            'Rsx.redirect() given a {history} throws', refused.redirect_history);

        // --- 2. classification ---
        const kinds = await page.evaluate(() => ({
            in_app: Rsx_Navigation._classify('/_sys/tasks').kind,
            absolute_in_app: Rsx_Navigation._classify(location.origin + '/_sys/tasks?x=1#y'),
            same_site: Rsx_Navigation._classify('/no-such-route-navigate-probe').kind,
            elsewhere: Rsx_Navigation._classify('http://elsewhere.test/page').kind,
            mailto: Rsx_Navigation._classify('mailto:someone@example.com').kind,
        }));
        check(kinds.in_app === 'in_app' && kinds.absolute_in_app.kind === 'in_app' && kinds.absolute_in_app.url === '/_sys/tasks?x=1#y',
            'a route of this SPA is in_app, and an absolute URL on this host is made relative', kinds);
        check(kinds.same_site === 'same_site', 'a URL on this host that is no route of this SPA is same_site', kinds);
        check(kinds.elsewhere === 'elsewhere' && kinds.mailto === 'elsewhere', 'another host, or another scheme, is elsewhere', kinds);

        // --- 3. in-app: push adds one entry, redirect replaces, no document load ---
        await mark(page);
        const pushed = await page.evaluate(async () => {
            const before = history.length;
            const dispatches = window.__counts.dispatch;
            await Rsx.navigate('/_sys/tasks');
            return { loc: location.pathname, added: history.length - before, dispatched: window.__counts.dispatch - dispatches, mark: window.__mark || null };
        });
        check(pushed.loc === '/_sys/tasks' && pushed.added === 1 && pushed.dispatched === 1 && pushed.mark === 'original',
            'in_app push: dispatched in the SPA with one new history entry', pushed);

        const replaced = await page.evaluate(async () => {
            const before = history.length;
            await Rsx.redirect('/_sys/workers');
            return { loc: location.pathname, added: history.length - before, mark: window.__mark || null };
        });
        check(replaced.loc === '/_sys/workers' && replaced.added === 0 && replaced.mark === 'original',
            'Rsx.redirect(): dispatched in the SPA, the entry replaced (no new entry)', replaced);

        const hinted = await page.evaluate(async () => {
            // The layout may paint the page's own static title in the same turn, so the hint is
            // observed as a write to the title, and as the action's _loader_title_hint argument.
            const titles = [];
            const observer = new MutationObserver((records) => {
                for (const record of records) {
                    for (const node of record.addedNodes) titles.push(node.textContent);
                }
            });
            observer.observe(document.querySelector('title'), { childList: true, characterData: true, subtree: true });
            await Rsx.navigate('/_sys/tasks', { title_hint: 'Navigate probe title' });
            await new Promise((resolve) => requestAnimationFrame(() => resolve()));
            observer.disconnect();
            return { titles: titles, arg: Spa.action().args._loader_title_hint };
        });
        check(hinted.titles[0] === 'Navigate probe title' && hinted.arg === 'Navigate probe title',
            '{title_hint} is written to the document title first and reaches the action as _loader_title_hint', hinted);
        await settled(page);

        // --- 4. load:'none' rewrites the address bar only ---
        const rewrite = await page.evaluate(() => {
            history.replaceState({ ...history.state, navigate_probe: 'kept' }, '');
            const action = Spa.action();
            const before = history.length;
            const dispatches = window.__counts.dispatch;
            const index = history.state.spa_index;

            Rsx.navigate('/_sys/tasks?probe=1', { load: 'none', history: 'replace' });
            const after_replace = {
                loc: location.pathname + location.search,
                same_action: Spa.action() === action,
                added: history.length - before,
                state_kept: history.state.navigate_probe,
                committed: Spa._last_committed_url,
            };

            Rsx.navigate('/_sys/tasks?probe=2', { load: 'none' });
            const after_push = {
                loc: location.pathname + location.search,
                same_action: Spa.action() === action,
                added: history.length - before,
                index_step: history.state.spa_index - index,
                state_kept: history.state.navigate_probe,
            };

            let threw = null;
            try { Rsx.navigate('/_sys/workers', { load: 'none' }); } catch (e) { threw = e.message; }

            return { after_replace, after_push, dispatched: window.__counts.dispatch - dispatches, threw, loc: location.pathname + location.search };
        });
        check(rewrite.after_replace.loc === '/_sys/tasks?probe=1' && rewrite.after_replace.same_action
            && rewrite.after_replace.added === 0 && rewrite.after_replace.state_kept === 'kept'
            && rewrite.after_replace.committed === '/_sys/tasks?probe=1',
            "load:'none' + replace: the address changes, the action and the entry's state stay", rewrite.after_replace);
        check(rewrite.after_push.loc === '/_sys/tasks?probe=2' && rewrite.after_push.same_action && rewrite.after_push.added === 1
            && rewrite.after_push.index_step === 1 && rewrite.after_push.state_kept === 'kept',
            "load:'none' + push: one new entry, one step along the stack, state kept", rewrite.after_push);
        check(rewrite.dispatched === 0, "load:'none' dispatches nothing", rewrite);
        check(rewrite.threw !== null && rewrite.threw.includes('Rsx.navigate') && rewrite.loc === '/_sys/tasks?probe=2',
            "load:'none' to a URL of another action throws, naming Rsx.navigate, and changes nothing", rewrite);

        // --- 5. a fragment-only move: native, no dispatch, spa_hash_change once ---
        const fragment = await page.evaluate(async () => {
            const action = Spa.action();
            const before = { dispatch: window.__counts.dispatch, popstate: window.__counts.popstate, hash: window.__counts.hash_change, length: history.length };
            // A fragment navigation fires popstate and then hashchange; counting after the
            // hashchange has been delivered sees every spa_hash_change the move produced.
            await new Promise((resolve) => {
                window.addEventListener('hashchange', () => resolve(), { once: true });
                Rsx.navigate(location.pathname + location.search + '#navigate_probe=1');
            });
            return {
                hash: location.hash,
                same_action: Spa.action() === action,
                mark: window.__mark || null,
                dispatched: window.__counts.dispatch - before.dispatch,
                popstates: window.__counts.popstate - before.popstate,
                hash_changes: window.__counts.hash_change - before.hash,
                added: history.length - before.length,
            };
        });
        check(fragment.hash === '#navigate_probe=1' && fragment.same_action && fragment.mark === 'original' && fragment.dispatched === 0,
            'a fragment-only navigate keeps the action mounted, with no dispatch and no load', fragment);
        check(fragment.popstates === 1 && fragment.hash_changes === 1,
            'a programmatic fragment move reaches the popstate branch: spa_hash_change fires exactly once', fragment);
        check(fragment.added === 1, 'a fragment-only push adds one entry', fragment);

        // --- 6. exactly the URL on screen: a reload ---
        await full_load(page, () => { Rsx.navigate(location.pathname + location.search + location.hash); });
        await settled(page);
        // The page writes its own #tab= into the fragment once it is up, so the path and query are compared.
        const reloaded = await page.evaluate(() => ({ loc: location.pathname + location.search, mark: window.__mark || null }));
        check(reloaded.loc === '/_sys/tasks?probe=2' && reloaded.mark === null,
            'navigating to exactly the URL on screen reloads the document', reloaded);

        // --- 7. same_site: a full load ---
        await mark(page);
        await full_load(page, () => { Rsx.navigate('/no-such-route-navigate-probe'); });
        const same_site = await page.evaluate(() => ({ loc: location.pathname, mark: window.__mark || null }));
        check(same_site.loc === '/no-such-route-navigate-probe' && same_site.mark === null, 'same_site: a full document load', same_site);

        await page.goto(BASE_URL + '/_sys', { waitUntil: 'commit' });
        await settled(page);

        // --- 8. elsewhere: a full load to the other host ---
        await full_load(page, () => { Rsx.navigate('http://elsewhere.test/landing'); });
        const elsewhere = await page.evaluate(() => ({ host: location.host, path: location.pathname }));
        check(elsewhere.host === 'elsewhere.test' && elsewhere.path === '/landing', 'elsewhere: the browser goes to the other host', elsewhere);

        await page.goto(BASE_URL + '/_sys', { waitUntil: 'commit' });
        await settled(page);

        // --- 9. load:'full' to a page of this SPA ---
        await mark(page);
        await full_load(page, () => { Rsx.navigate('/_sys/tasks', { load: 'full' }); });
        await settled(page);
        const forced = await page.evaluate(() => ({ loc: location.pathname, mark: window.__mark || null }));
        check(forced.loc === '/_sys/tasks' && forced.mark === null, "load:'full' loads a document even for a page of this SPA", forced);

        // --- 10. SPA disabled: a full load that hands the origin across ---
        const explicit = await in_app(page, '/_sys/users/1?_via=site.1');
        check(explicit.kept && explicit.decider === '_Sys_User_View_Action@/_sys/users/1',
            'setup: an explicit origin accepted by decider A', explicit);

        await mark(page);
        await full_load(page, () => { Spa.disable(); Rsx.navigate('/_sys/system-info'); });
        const same_decider = await origin_state(page);
        check(same_decider.mark === null && same_decider.loc === '/_sys/system-info?_via=site.1',
            'SPA disabled: navigate is a full load, and the carried _via is on the target', same_decider);
        check(same_decider.arrival === 'carried' && same_decider.previous === '_Sys_User_View_Action@/_sys/users/1' && same_decider.kept,
            'after the full load the origin arrived CARRIED, judged against the decider left, and is kept (same decider)', same_decider);
        check(same_decider.handoff === null, 'the hand-off is deleted once read', same_decider);

        await in_app(page, '/_sys/users/1?_via=site.1');
        await full_load(page, () => { Spa.disable(); Rsx.navigate('/_sys/workers'); });
        const other_decider = await origin_state(page);
        check(other_decider.arrival === 'carried' && !other_decider.kept && other_decider.loc === '/_sys/workers',
            'after a full load, a carried origin accepted by a DIFFERENT decider is stripped', other_decider);

        // --- 11. {via: 'continue'} across a full load ---
        await in_app(page, '/_sys/users/1?_via=site.1');
        await full_load(page, () => { Spa.disable(); Rsx.navigate('/_sys/workers', { via: 'continue' }); });
        const continued = await origin_state(page);
        check(continued.arrival === 'explicit' && continued.kept && continued.loc === '/_sys/workers?_via=site.1'
            && continued.decider === '_Sys_Task_Workers_Action@/_sys/workers',
            "{via: 'continue'} across a full load arrives explicit and is kept by a different decider that accepts it", continued);

        // --- 12. a link click with the SPA disabled goes through the door too ---
        await in_app(page, '/_sys/users/1?_via=site.1');
        await full_load(page, () => {
            Spa.disable();
            const a = document.createElement('a');
            a.setAttribute('href', '/_sys/system-info');
            a.textContent = 'probe';
            document.body.appendChild(a);
            a.click();
        });
        const clicked = await origin_state(page);
        check(clicked.loc === '/_sys/system-info?_via=site.1' && clicked.arrival === 'carried' && clicked.kept,
            'SPA disabled: a link click is a full load that carries and hands over the origin', clicked);

        // --- 13. the hand-off is single use, and only for the URL it names ---
        await page.reload({ waitUntil: 'commit' });
        const after_reload = await origin_state(page);
        check(after_reload.arrival === 'explicit' && after_reload.loc === '/_sys/system-info?_via=site.1',
            'a reload after a handed-off load is the first page of a document again: explicit', after_reload);

        await page.evaluate(() => Rsx_Storage.session_set(Rsx_Navigation.HANDOFF_KEY, {
            url: '/_sys/somewhere-else', arrival: 'carried', previous_decider: 'Nobody@/x',
        }));
        await page.reload({ waitUntil: 'commit' });
        const mismatched = await origin_state(page);
        check(mismatched.arrival === 'explicit' && mismatched.handoff === null,
            'a hand-off naming another URL is ignored and still deleted', mismatched);
    } finally {
        await browser.close();
    }
}

run().catch((err) => {
    fail(err.stack || String(err));
});
