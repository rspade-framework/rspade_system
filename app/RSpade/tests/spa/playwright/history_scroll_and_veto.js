#!/usr/bin/env node
/**
 * Back/Forward return a page to where it was scrolled, and a vetoed Back/Forward leaves
 * the history stack as it was (SPA-HISTORY-01..09).
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
 *   6. a vetoed Forward leaves the address, the stack length and both neighbours as
 *      they were: Back still reaches A, Forward still reaches C
 *   7. a vetoed Back does the same
 *   8. the popstate fired by undoing a veto is not treated as a navigation
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

        // ---- A vetoed Back/Forward ------------------------------------------------------
        const guarded = await open_panel(context, '.Spa_Action { min-height: 5000px; }');
        await dispatch(guarded, ROUTE_B);
        await dispatch(guarded, ROUTE_C);
        await back(guarded);

        // Stack: [A, B*, C]
        const start = await where(guarded);

        const vetoed_forward = await guarded.evaluate(async () => {
            let asked = 0;
            Rsx.set_navigation_guard(async () => { asked++; return false; });
            const before = window.__dispatches;

            history.forward();
            await new Promise((resolve) => setTimeout(resolve, 1500));

            return {
                asked: asked,
                path: location.pathname,
                length: history.length,
                index: history.state && history.state.spa_index,
                dispatched: window.__dispatches - before,
                undoing: Spa._undoing_vetoed_move,
            };
        });
        check(vetoed_forward.path === ROUTE_B && vetoed_forward.length === start.length && vetoed_forward.index === start.index,
            'a vetoed Forward leaves the address and the stack as they were', { start: start, after: vetoed_forward });
        check(vetoed_forward.asked === 1 && vetoed_forward.dispatched === 0 && vetoed_forward.undoing === false,
            'the popstate fired by undoing the veto is not a navigation', vetoed_forward);

        const vetoed_back = await guarded.evaluate(async () => {
            history.back();
            await new Promise((resolve) => setTimeout(resolve, 1500));

            return { path: location.pathname, length: history.length, index: history.state && history.state.spa_index };
        });
        check(vetoed_back.path === ROUTE_B && vetoed_back.length === start.length && vetoed_back.index === start.index,
            'a vetoed Back leaves the address and the stack as they were', { start: start, after: vetoed_back });

        await guarded.evaluate(() => Rsx.clear_navigation_guard());

        await forward(guarded);
        const reaches_c = await where(guarded);
        check(reaches_c.path === ROUTE_C, 'Forward still reaches C', reaches_c);

        await back(guarded);
        await back(guarded);
        const reaches_a = await where(guarded);
        check(reaches_a.path === ROUTE_A && reaches_a.length === start.length, 'Back still reaches A, with nothing added to the stack', reaches_a);
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
