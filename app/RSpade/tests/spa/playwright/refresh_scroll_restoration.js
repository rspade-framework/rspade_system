#!/usr/bin/env node
/**
 * A refreshed SPA page keeps its scroll position, and yields to the user
 * (SPA-REFRESH-01..07).
 *
 * The position comes from the history entry, which survives a reload, and the
 * restoration is decided at the start of the dispatch, so nothing sends the page to the
 * top once the action is ready. Only a browser can prove it, so:
 *
 *   1. the SPA owns restoration: history.scrollRestoration is 'manual'
 *   2. a refresh returns the page to where it was, and it is still there once the
 *      action is ready (the defect: it restored, then jumped to the top at ready)
 *   3. a refresh during which the user scrolls leaves the page where the user put it
 *   4. a recorded position the page can no longer hold is clamped and settled - and
 *      the next navigation still opens at the top
 *   5. a layout that scrolls inside a container is restored on the container on refresh
 *
 * Back/Forward is unchanged and covered by history_scroll_and_veto.js.
 *
 * Runs against the framework's own /_sys panel. The pages are made tall by a stylesheet
 * an init script adds to EVERY load (a reload drops a style added after the fact).
 * Self-contained: a bare `node refresh_scroll_restoration.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE_A = '/_sys';
const ROUTE_B = '/_sys/tasks';
const USER_ID = 1;
const TALL = '.Spa_Action { min-height: 5000px; }';

function fail(msg) {
    console.log('FAIL: spa refresh scroll - ' + msg);
    process.exitCode = 1;
}

function check(condition, label, detail) {
    if (condition) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/**
 * A signed-in page on ROUTE_A whose every load carries `css` and counts completed
 * dispatches in window.__dispatches. `slow_ajax` holds every Ajax response that long, so
 * a load is still in progress after the action has rendered.
 */
async function open_panel(browser, css, slow_ajax = 0) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });

    await context.addInitScript((style) => {
        window.__dispatches = 0;
        document.addEventListener('DOMContentLoaded', () => {
            const tag = document.createElement('style');
            tag.textContent = style;
            document.head.appendChild(tag);
        });
        const hook = setInterval(() => {
            if (typeof Rsx !== 'undefined' && Rsx.on) {
                clearInterval(hook);
                Rsx.on('spa_dispatch_ready', () => { window.__dispatches++; });
            }
        }, 5);
    }, css);

    const page = await context.newPage();
    const auth_headers = { ...dev_auth_headers(ROUTE_A, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    page.__slow_ajax = slow_ajax;

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
        if (page.__slow_ajax && url.includes('/_ajax/')) {
            await new Promise((resolve) => setTimeout(resolve, page.__slow_ajax));
        }
        await route.continue({ headers });
    });

    await page.goto(BASE_URL + ROUTE_A, { waitUntil: 'commit' });
    await page.waitForFunction(() => window.__dispatches > 0);
    return page;
}

const scroll_to = (page, y) => page.evaluate((top) => Spa._page_scroller().scrollTo({ top: top, behavior: 'instant' }), y);
const where = (page) => page.evaluate(() => {
    const scroller = Spa._page_scroller();
    return {
        path: location.pathname,
        y: Math.round(scroller.scrollTop),
        max: Math.round(scroller.scrollHeight - scroller.clientHeight),
        window_y: Math.round(window.scrollY),
        scroller: scroller === (document.scrollingElement || document.documentElement) ? 'document' : 'container',
        restoring: Spa._scroll_restoration !== null,
    };
});

/** Reload and wait until the reloaded action is fully ready. */
async function reload(page) {
    await page.reload({ waitUntil: 'commit' });
    await page.waitForFunction(() => window.__dispatches > 0);
}

/** Scroll, and wait until the history entry has recorded it. */
async function scroll_and_record(page, y) {
    await scroll_to(page, y);
    await page.waitForFunction((top) => history.state && history.state.scroll && Math.round(history.state.scroll.y) === top, y);
}

async function run() {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });

    try {
        // ---- 1 + 2. A refresh restores, and stays restored at ready -----------------------
        const page = await open_panel(browser, TALL);
        check(await page.evaluate(() => history.scrollRestoration) === 'manual', 'the SPA owns restoration (scrollRestoration manual)');

        await scroll_and_record(page, 1500);
        await reload(page);
        const reloaded = await where(page);
        check(reloaded.path === ROUTE_A && reloaded.y === 1500, 'a refresh returns the page to where it was, and it is there at ready', reloaded);

        // Two frames on: the ready-time scroll decision has run, and the page is still there.
        const after_ready = await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() =>
            resolve(Math.round(Spa._page_scroller().scrollTop))))));
        check(after_ready === 1500, 'it does not jump back to the top after ready', after_ready);

        // ---- 4. A position the page cannot hold: clamped, settled, not carried on ---------
        await page.evaluate(() => history.replaceState({ ...history.state, scroll: { x: 0, y: 999999 } }, ''));
        // pagehide would write the live position over the probe; a reload made while the
        // entry says 999999 is what a page that came back shorter looks like.
        await page.evaluate(() => { window.addEventListener('pagehide', (e) => e.stopImmediatePropagation(), { capture: true }); });
        await reload(page);
        const clamped = await where(page);
        check(clamped.y === clamped.max && clamped.max > 0, 'a position the page cannot hold is scrolled as far as it goes', clamped);

        const before = await page.evaluate(() => window.__dispatches);
        await page.evaluate((target) => { Spa.dispatch(target); }, ROUTE_B);
        await page.waitForFunction((n) => window.__dispatches > n, before);
        const next = await where(page);
        check(next.path === ROUTE_B && next.y === 0 && !next.restoring, 'and the next navigation opens at the top, nothing carried over', next);
        await page.context().close();

        // ---- 3. The user scrolls during the load ------------------------------------------
        const slow = await open_panel(browser, TALL);
        await scroll_and_record(slow, 1500);
        slow.__slow_ajax = 1500;
        await slow.reload({ waitUntil: 'commit' });
        // The action has rendered tall and the restoration has been applied; its data
        // is still loading.
        await slow.waitForFunction(() => typeof Spa !== 'undefined' && Spa._scroll_restoration !== null
            && Math.round(Spa._page_scroller().scrollTop) === 1500);
        await slow.mouse.move(640, 400);
        await slow.mouse.wheel(0, -600);
        await slow.waitForFunction(() => Spa._scroll_restoration === null);
        const placed = await slow.evaluate(() => Math.round(Spa._page_scroller().scrollTop));
        await slow.waitForFunction(() => window.__dispatches > 0);
        const user_scrolled = await where(slow);
        check(placed < 1500 && user_scrolled.y === placed && !user_scrolled.restoring,
            'a refresh during which the user scrolls leaves the page where the user put it', { placed: placed, after_ready: user_scrolled });
        await slow.context().close();

        // ---- 5. A container scrolls -------------------------------------------------------
        const boxed = await open_panel(browser,
            'html, body { height: 100%; overflow: hidden; }'
            + ' .Spa_Action { height: 400px; overflow-y: auto; }'
            + ' .Spa_Action::after { content: ""; display: block; height: 5000px; }');
        await scroll_and_record(boxed, 600);
        await reload(boxed);
        const boxed_reloaded = await where(boxed);
        check(boxed_reloaded.scroller === 'container' && boxed_reloaded.y === 600 && boxed_reloaded.window_y === 0,
            'a refresh restores the container, not the window', boxed_reloaded);
        await boxed.context().close();
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    fail(e.stack || String(e));
});
