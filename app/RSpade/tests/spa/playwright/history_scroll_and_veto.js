#!/usr/bin/env node
/**
 * Back/Forward return a page to where it was scrolled, and a Back/Forward the user
 * declines at the navigation guard's prompt leaves the history stack as it was
 * (SPA-HISTORY-01..13).
 *
 * Each history entry records where ITS OWN page is scrolled and where it sits in the
 * stack. Only a browser can prove either, so:
 *
 *   1. scroll A, navigate to B, Back: A is where it was left
 *   2. Forward: B is where it was left (left by Back, so never written by a push)
 *   3. the entry's own state is kept current while the page is on screen
 *   4. a layout that scrolls inside a container is measured and restored on that
 *      container, not on the window
 *   5. Rsx.url_hash_set() keeps the entry's state
 *
 * and, with the navigation guard on (Rsx.set_navigation_guard()), where a Back/Forward
 * between two SPA pages raises the browser's confirm box before anything is dispatched:
 *
 *   6. the box is a native confirm carrying the browser's unload wording
 *   7. Cancel on a Forward leaves the address, the stack length and the entry index as
 *      they were, dispatches nothing, and leaves the guard on
 *   8. the popstate fired by stepping back is not treated as a navigation, and is not
 *      asked about
 *   9. Cancel on a Back does the same
 *  10. Cancel on a two-step jump (history.go(2)) steps back across both entries
 *  11. OK turns the guard off and the move is an ordinary in-SPA Back/Forward
 *  12. afterwards nothing asks, and both neighbours are still reachable
 *
 * Runs against the framework's own /_sys panel. The pages are made tall by a stylesheet
 * rule on .Spa_Action, so the result does not depend on how much each screen shows.
 * Self-contained: a bare `node history_scroll_and_veto.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE_A = '/_sys';
const ROUTE_B = '/_sys/tasks';
const ROUTE_C = '/_sys/workers';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: spa history - ' + msg);
    process.exitCode = 1;
}

function check(condition, label, detail) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Open ROUTE_A signed in, with every action made tall, and count completed dispatches. */
async function open_panel(context, css) {
    const page = await context.newPage();
    const auth_headers = {
        ...dev_auth_headers(ROUTE_A, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (!url.startsWith(BASE_URL)) {
            await route.continue();
            return;
        }
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + ROUTE_A, { waitUntil: 'commit' });
    await page.waitForFunction(() => typeof Rsx !== 'undefined');
    await page.evaluate(() => new Promise((resolve) => {
        Rsx.on('_debug_ready', () => resolve());
        setTimeout(resolve, 15000);
    }));
    await page.addStyleTag({ content: css });
    await page.evaluate(() => {
        // Prevent false-positive session-hash reloads in the dev-auth environment.
        Rsx.validate_session = async () => true;
        window.__dispatches = 0;
        Rsx.on('spa_dispatch_ready', () => { window.__dispatches++; });
    });
    return page;
}

/** Run a navigation and wait until the action it lands on is fully ready. */
async function navigate(page, action) {
    const before = await page.evaluate(() => window.__dispatches);
    await action();
    await page.waitForFunction((n) => window.__dispatches > n, before);
}

const dispatch = (page, url) => navigate(page, () => page.evaluate((target) => { Spa.dispatch(target); }, url));
const back = (page) => navigate(page, () => page.evaluate(() => history.back()));
const forward = (page) => navigate(page, () => page.evaluate(() => history.forward()));
const scroll_to = (page, y) => page.evaluate((top) => Spa._page_scroller().scrollTo({ top: top, behavior: 'instant' }), y);
const where = (page) => page.evaluate(() => ({
    path: location.pathname,
    y: Math.round(Spa._page_scroller().scrollTop),
    window_y: Math.round(window.scrollY),
    scroller: Spa._page_scroller() === (document.scrollingElement || document.documentElement) ? 'document' : 'container',
    length: history.length,
    index: history.state && history.state.spa_index,
}));

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 800 },
    });

    try {
        // ---- The window scrolls ---------------------------------------------------------
        const page = await open_panel(context, '.Spa_Action { min-height: 5000px; }');

        await scroll_to(page, 700);
        await dispatch(page, ROUTE_B);
        const on_b = await where(page);
        check(on_b.path === ROUTE_B && on_b.y === 0 && on_b.index === 1, 'a new page opens at the top, one entry further on', on_b);

        await scroll_to(page, 300);
        await back(page);
        const back_on_a = await where(page);
        check(back_on_a.path === ROUTE_A && back_on_a.y === 700 && back_on_a.scroller === 'document',
            'Back returns A to where it was scrolled', back_on_a);

        await forward(page);
        const forward_on_b = await where(page);
        check(forward_on_b.path === ROUTE_B && forward_on_b.y === 300, 'Forward returns B to where it was scrolled', forward_on_b);

        await scroll_to(page, 450);
        await page.waitForFunction(() => history.state && history.state.scroll && history.state.scroll.y === 450);
        check(true, "the entry's own state follows the page while it is on screen");

        const kept = await page.evaluate(() => {
            Rsx.url_hash_set({ probe: 'x' });
            const state = history.state;
            Rsx.url_hash_set({ probe: null });
            return state;
        });
        check(kept && kept.spa_run && kept.spa_index === 1 && kept.scroll && kept.scroll.y === 450,
            'Rsx.url_hash_set() keeps the entry state', kept);
        await page.close();

        // ---- A container scrolls --------------------------------------------------------
        const boxed = await open_panel(context,
            'html, body { height: 100%; overflow: hidden; }'
            + ' .Spa_Action { height: 400px; overflow-y: auto; }'
            + ' .Spa_Action::after { content: ""; display: block; height: 5000px; }');

        await scroll_to(boxed, 600);
        const boxed_a = await where(boxed);
        check(boxed_a.scroller === 'container' && boxed_a.y === 600 && boxed_a.window_y === 0,
            'the page scrolls inside a container, not the window', boxed_a);

        await dispatch(boxed, ROUTE_B);
        await back(boxed);
        const boxed_back = await where(boxed);
        check(boxed_back.path === ROUTE_A && boxed_back.scroller === 'container' && boxed_back.y === 600,
            'Back restores the container, not the window', boxed_back);
        await boxed.close();

        // ---- Back/Forward with the navigation guard on ----------------------------------
        const guarded = await open_panel(context, '.Spa_Action { min-height: 5000px; }');

        // Every dialog is recorded and answered as `answer` says.
        const dialogs = [];
        let answer = 'dismiss';
        guarded.on('dialog', async (dialog) => {
            dialogs.push({ type: dialog.type(), message: dialog.message() });
            if (answer === 'accept') {
                await dialog.accept();
            } else {
                await dialog.dismiss();
            }
        });

        await dispatch(guarded, ROUTE_B);
        await dispatch(guarded, ROUTE_C);
        await back(guarded);
        check(dialogs.length === 0, 'guard off: Back asks nothing', dialogs);

        // Stack: [A, B*, C]
        const start = await where(guarded);
        await guarded.evaluate(() => {
            window.__document_mark = 'original';
            Spa.action().__history_probe = 'kept';
            Rsx.set_navigation_guard();
        });

        /**
         * Make a history move that the user declines, and wait until the browser is back
         * on the entry it left: the address restored and the restoring step swallowed.
         */
        const declined = async (move, home) => {
            const seen = dialogs.length;
            const before = await guarded.evaluate(() => window.__dispatches);
            const asked = guarded.waitForEvent('dialog');
            await guarded.evaluate(move);
            await asked;
            await guarded.waitForFunction(
                ([path, index]) => location.pathname === path
                    && history.state && history.state.spa_index === index
                    && Spa._undoing_vetoed_move === false,
                [home.path, home.index]
            );
            // The restoring step has arrived. Had it been read as a navigation it would
            // have asked again; give that a full task to show itself before reading.
            const after = await guarded.evaluate(() => new Promise((resolve) => setTimeout(() => resolve({
                path: location.pathname,
                length: history.length,
                index: history.state && history.state.spa_index,
                dispatched: window.__dispatches,
                guarded: Rsx.has_navigation_guard(),
                probe: Spa.action() ? (Spa.action().__history_probe || null) : null,
                run_index: Spa._history_index,
            }), 0)));
            after.dispatched -= before;
            after.dialogs = dialogs.slice(seen);
            return after;
        };

        const intact = (after, home) => after.path === home.path && after.length === home.length
            && after.index === home.index && after.run_index === home.index;
        const untouched = (after) => after.dialogs.length === 1 && after.dispatched === 0
            && after.guarded === true && after.probe === 'kept';

        answer = 'dismiss';
        const declined_forward = await declined(() => history.forward(), start);

        check(declined_forward.dialogs.length === 1 && declined_forward.dialogs[0].type === 'confirm'
            && declined_forward.dialogs[0].message === 'Leave site?\n\nChanges you made may not be saved.',
            'guard on: Forward raises the browser confirm box with the unload wording', declined_forward.dialogs);
        check(intact(declined_forward, start),
            'a declined Forward leaves the address and the stack as they were', { start: start, after: declined_forward });
        check(untouched(declined_forward),
            'a declined Forward dispatches nothing, asks once, and leaves the page and the guard as they were', declined_forward);

        const declined_back = await declined(() => history.back(), start);
        check(intact(declined_back, start) && untouched(declined_back),
            'a declined Back does the same', { start: start, after: declined_back });

        // A two-step jump. Stack [A*, B, C]: reached with the guard off, then armed on A.
        await guarded.evaluate(() => Rsx.clear_navigation_guard());
        await back(guarded);
        const on_a = await where(guarded);
        await guarded.evaluate(() => {
            Spa.action().__history_probe = 'kept';
            Rsx.set_navigation_guard();
        });
        const declined_jump = await declined(() => history.go(2), on_a);
        check(on_a.path === ROUTE_A && intact(declined_jump, on_a) && untouched(declined_jump),
            'a declined two-step jump steps back across both entries', { start: on_a, after: declined_jump });

        // OK: the guard is turned off and the move goes ahead in the SPA.
        answer = 'accept';
        const seen_before_accept = dialogs.length;
        await navigate(guarded, () => guarded.evaluate(() => history.forward()));
        const accepted = await where(guarded);
        const accepted_state = await guarded.evaluate(() => ({
            mark: window.__document_mark || null,
            guarded: Rsx.has_navigation_guard(),
        }));
        check(dialogs.length === seen_before_accept + 1 && dialogs[seen_before_accept].type === 'confirm'
            && accepted.path === ROUTE_B && accepted_state.mark === 'original',
            'an accepted Forward is dispatched in the SPA', { accepted, accepted_state, dialogs: dialogs.slice(seen_before_accept) });
        check(accepted_state.guarded === false, 'an accepted Back/Forward turns the guard off', accepted_state);

        // Guard off again: nothing asks, and the stack is whole.
        const seen_after_accept = dialogs.length;
        await forward(guarded);
        const reaches_c = await where(guarded);
        check(reaches_c.path === ROUTE_C, 'Forward still reaches C', reaches_c);

        await back(guarded);
        await back(guarded);
        const reaches_a = await where(guarded);
        check(reaches_a.path === ROUTE_A && reaches_a.length === start.length && dialogs.length === seen_after_accept,
            'Back still reaches A, with nothing added to the stack and nothing asked', { reaches_a, dialogs: dialogs.slice(seen_after_accept) });
        await guarded.close();
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: spa history');
    } else {
        console.log('PASS: spa history');
    }
}

run();
