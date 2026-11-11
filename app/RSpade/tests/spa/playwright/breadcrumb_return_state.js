#!/usr/bin/env node
/**
 * A breadcrumb returns to the page AND the state the user came from (SPA-RETURN-01..12,
 * rsx:man breadcrumb_origins, RETURNING TO THE STATE YOU LEFT).
 *
 * The per-tab journal (Spa_Journal) records, per history entry, the URL it was left at (its
 * fragment included), the scroll position, the entry the user came FROM and the trail the
 * page showed. A crumb - every href the resolver emits, and Spa.return_url() - inherits the
 * state of the nearest entry in the `from` chain that is its page, provided every entry
 * passed on the way showed that crumb. The scroll travels as the one-time hash key
 * Rsx.HASH_SCROLL_KEY, applied through the SPA's scroll restoration and removed from the
 * address bar at once.
 *
 * Runs on the framework's own /_sys panel, the breadcrumb structure declared by an init
 * script on every document (so it survives reloads and new tabs):
 *
 *   /_sys/sites            A  root - the "list": its state is a probe fragment + a scroll
 *   /_sys/sites/1          B  parent A - the "record"
 *   /_sys/system-info      C  parent B - the "edit page"
 *   /_sys/tasks            X  parent /_sys - another section
 *   /_sys                  D  root - the "dashboard"
 *
 * The panel's layout renders no breadcrumbs, so each visit streams the trail itself
 * (Rsx_Breadcrumb_Resolver.stream(), what a layout calls) and reads the crumb hrefs it emits.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');
const { poll_until, is_chromium_load_noise, BASE_URL } = require('../../_lib/playwright/sys_page.js');

const USER_ID = 1;
const A = '/_sys/sites';
const B = '/_sys/sites/1';
const C = '/_sys/system-info';
const X = '/_sys/tasks';
const D = '/_sys';

function fail(msg) {
    console.log('FAIL: breadcrumb return state - ' + msg);
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
 * Declared on every /_sys document: tall pages, the breadcrumb structure, and the probe
 * helpers. `block_storage`: session storage throws on access, as in a locked-down browser.
 */
function init_script(block_storage) {
    if (block_storage) {
        Object.defineProperty(window, 'sessionStorage', {
            get() { throw new DOMException('The operation is insecure.', 'SecurityError'); },
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const tag = document.createElement('style');
        tag.textContent = '.Spa_Action { min-height: 5000px; }';
        document.head.appendChild(tag);

        const parent = (cls, fn) => { cls.prototype.breadcrumb_parent = async function () { return fn.call(this); }; };
        parent(_Sys_Sites_Action, () => null);
        parent(_Sys_Site_View_Action, () => '/_sys/sites');
        parent(_Sys_System_Info_Action, () => '/_sys/sites/1');
        parent(_Sys_Tasks_Action, () => '/_sys');
        parent(_Sys_Dashboard_Action, () => null);

        window.__rs = {
            until(fn) {
                return new Promise((resolve) => {
                    const poll = () => {
                        let value = null;
                        try { value = fn(); } catch (e) { value = null; }
                        if (value) resolve(value); else requestAnimationFrame(poll);
                    };
                    poll();
                });
            },

            // Stream the page's trail (recording it on the journal) and answer the crumb hrefs.
            trail() {
                return new Promise((resolve) => {
                    Rsx_Breadcrumb_Resolver.stream(Spa.action(), location.pathname + location.search, {
                        on_chain: () => {},
                        on_label_update: () => {},
                        on_complete: (chain) => resolve(chain.filter((c) => !c.is_active).map((c) => c.href)),
                    });
                });
            },

            // Navigate, wait for the page to be ready, stream its trail.
            async go(url) {
                await Rsx.navigate(url);
                await Spa.action().ready();
                return window.__rs.trail();
            },

            scroll_to(y) {
                Spa._page_scroller().scrollTo({ top: y, behavior: 'instant' });
            },

            where() {
                return { path: location.pathname, hash: location.hash, y: Math.round(Spa._page_scroller().scrollTop) };
            },
        };
    }, { once: true });
}

/** A signed-in /_sys page in a new context (a new tab: empty session storage). */
async function open_tab(browser, route, block_storage = false) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
    await context.addInitScript(init_script, block_storage);
    const page = await context.newPage();

    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !is_chromium_load_noise(msg.text())) {
            errors.push(msg.text());
        }
    });

    const path = route.split('#')[0];
    const auth_headers = { ...dev_auth_headers(path, USER_ID), 'X-Playwright-Test': '1' };
    let initial_doc_sent = false;
    await page.route('**/*', async (r, request) => {
        if (!request.url().startsWith(BASE_URL)) {
            await r.continue();
            return;
        }
        const headers = { ...request.headers() };
        if (!initial_doc_sent && request.resourceType() === 'document') {
            Object.assign(headers, auth_headers);
            initial_doc_sent = true;
        } else {
            headers['X-Playwright-Test'] = '1';
        }
        await r.continue({ headers });
    });

    await page.goto(BASE_URL + route, { waitUntil: 'commit' });
    await ready(page);

    return { page, errors };
}

/** Wait for a ready SPA action with the probe helpers installed (survives a document load). */
async function ready(page) {
    await poll_until(page, 'a ready SPA action with the probe helpers', () =>
        typeof Spa !== 'undefined' && !!window.__rs && !!Spa.action() && !Spa.is_dispatching);
    await page.evaluate(() => Spa.action().ready());
}

async function run() {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });

    try {
        const { page, errors } = await open_tab(browser, A);

        // The "list" left sorted (a fragment) and scrolled.
        const left_a = await page.evaluate(async () => {
            await window.__rs.trail();
            Rsx.url_hash_set({ probe: 'sorted' });
            window.__rs.scroll_to(1200);
            return window.__rs.where();
        });
        const stateful_a = A + '#probe=sorted&_scroll=1200';

        // --- 01. list -> record ---
        const on_b = await page.evaluate((url) => window.__rs.go(url), B);
        check(on_b[0] === stateful_a, 'SPA-RETURN-01 the record\'s crumb links to the list with the fragment and position it was left at', { on_b, left_a });

        // --- 02. list -> record -> edit (record left with its own fragment) ---
        const on_c = await page.evaluate(async (url) => {
            Rsx.url_hash_set({ tab: 'users' });
            return window.__rs.go(url);
        }, C);
        check(on_c[0] === stateful_a && on_c[1] === B + '#tab=users',
            'SPA-RETURN-02 two levels down, each crumb returns to its own page as it was left (the record\'s tab, scroll 0 carries no key)', on_c);

        // --- 03. edit -> save -> record (a forward move): the chain passes through pages showing the list ---
        const after_save = await page.evaluate(async (url) => ({
            crumbs: await window.__rs.go(url),
            api: Spa.return_url('/_sys/sites'),
            unrelated: Spa.return_url('/_sys/tasks'),
        }), B);
        check(after_save.crumbs[0] === stateful_a && after_save.api === stateful_a && after_save.unrelated === '/_sys/tasks',
            'SPA-RETURN-03 after save the crumb still returns to the list; Spa.return_url() answers the same, and a page not on the journey stays bare', after_save);

        // --- 04. a full reload keeps the journal ---
        await page.reload({ waitUntil: 'commit' });
        await ready(page);
        const reloaded = await page.evaluate(() => window.__rs.trail());
        check(reloaded[0] === stateful_a, 'SPA-RETURN-04 the journal survives a reload of the current page', reloaded);

        // --- 05. following the crumb: the state comes back, the scroll key leaves the address ---
        const followed = await page.evaluate(async (href) => {
            const before = history.length;
            await Rsx.navigate(href);
            await Spa.action().ready();
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            return { ...window.__rs.where(), pushed: history.length === before + 1, entry_url_has_key: location.href.includes('_scroll') };
        }, reloaded[0]);
        check(followed.path === A && followed.hash === '#probe=sorted' && followed.y === 1200 && followed.pushed,
            'SPA-RETURN-05 following the crumb lands on the list with its fragment and at its position, as a new entry', followed);
        check(!followed.entry_url_has_key, 'SPA-RETURN-06 the scroll key is gone from the address bar', followed);

        await page.reload({ waitUntil: 'commit' });
        await ready(page);
        const reload_after = await page.evaluate(() => window.__rs.where());
        check(reload_after.hash === '#probe=sorted', 'SPA-RETURN-06 a reload after it carries no scroll key', reload_after);

        // --- 07. Back/Forward unchanged: Back from the list returns to the record entry ---
        const back = await page.evaluate(async () => {
            const leaving = Spa.action();
            history.back();
            await window.__rs.until(() => Spa.action() && Spa.action() !== leaving && !Spa.is_dispatching);
            await Spa.action().ready();
            const where = window.__rs.where();
            history.forward();
            const again = Spa.action();
            await window.__rs.until(() => Spa.action() && Spa.action() !== again && !Spa.is_dispatching);
            await Spa.action().ready();
            return { back: where, forward: window.__rs.where() };
        });
        check(back.back.path === B && back.forward.path === A && back.forward.hash === '#probe=sorted',
            'SPA-RETURN-07 Back and Forward still move between the entries as recorded', back);

        // --- 08. the nearest match: the list re-sorted, then a record ---
        const nearest = await page.evaluate(async (url) => {
            await window.__rs.trail();
            Rsx.url_hash_set({ probe: 'resorted' });
            window.__rs.scroll_to(300);
            return window.__rs.go(url);
        }, B);
        check(nearest[0] === A + '#probe=resorted&_scroll=300', 'SPA-RETURN-08 the NEWER visit to the list is the one returned to', nearest);

        // --- 09. a detour through a page that does not show the list: bare ---
        const detour = await page.evaluate(async (urls) => {
            await window.__rs.go(urls[0]);
            return window.__rs.go(urls[1]);
        }, [X, B]);
        check(detour[0] === A, 'SPA-RETURN-09 after a detour through another section the crumb is the bare route', detour);

        // --- 10. reached from a page that never showed the list: bare ---
        const from_dashboard = await page.evaluate(async (urls) => {
            await window.__rs.go(urls[0]);
            return window.__rs.go(urls[1]);
        }, [D, B]);
        check(from_dashboard[0] === A, 'SPA-RETURN-10 a record reached from the dashboard crumbs to the bare list', from_dashboard);

        // --- 11. the user's own scroll during the restoration wins ---
        const user_scroll = await page.evaluate(async (url) => {
            await Rsx.navigate(url);
            const restoring = Spa._scroll_restoration !== null;
            window.dispatchEvent(new WheelEvent('wheel', { deltaY: -100 }));
            window.__rs.scroll_to(500);
            await Spa.action().ready();
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            return { restoring: restoring, ...window.__rs.where() };
        }, A + '#_scroll=2000');
        check(user_scroll.restoring && user_scroll.y === 500 && user_scroll.hash === '',
            'SPA-RETURN-11 a user who scrolls while the position is being restored keeps their own position', user_scroll);

        check(errors.length === 0, 'no console error in the journey', errors);

        // --- 12. a new tab opened on a stateful crumb: lands in that state, its own journal empty ---
        const tab = await open_tab(browser, A + '#probe=sorted&_scroll=1200');
        const tab_state = await tab.page.evaluate(async () => {
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const landed = window.__rs.where();
            const journal = Rsx_Storage.session_get(Spa_Journal.STORAGE_KEY);
            const crumbs = await window.__rs.go('/_sys/sites/1');
            return { landed, records: journal ? Object.keys(journal.records).length : 0, first: journal ? Object.values(journal.records)[0] : null, crumbs };
        });
        check(tab_state.landed.hash === '#probe=sorted' && tab_state.landed.y === 1200,
            'SPA-RETURN-12 a new tab opened on a stateful crumb lands in that state, the scroll key gone from its address', tab_state);
        check(tab_state.records === 1 && tab_state.first.from === null && tab_state.first.url === A + '#probe=sorted',
            'SPA-RETURN-12 a new tab starts its own journal with one entry and no history behind it, the scroll key never recorded', tab_state);
        check(tab_state.crumbs[0] === A + '#probe=sorted&_scroll=1200',
            'SPA-RETURN-12 within that tab the journey continues from the state it opened in', tab_state);

        // --- 13. session storage unavailable: bare crumbs, no console error ---
        const locked = await open_tab(browser, A, true);
        const locked_state = await locked.page.evaluate(async (url) => {
            await window.__rs.trail();
            Rsx.url_hash_set({ probe: 'sorted' });
            window.__rs.scroll_to(1200);
            return { crumbs: await window.__rs.go(url), api: Spa.return_url('/_sys/sites') };
        }, B);
        check(locked_state.crumbs[0] === A && locked_state.api === A && locked.errors.length === 0,
            'SPA-RETURN-13 with session storage unavailable the crumbs are the bare routes, with no console error', { locked_state, errors: locked.errors });
    } finally {
        await browser.close();
    }
}

run().catch((err) => {
    fail(err.stack || String(err));
});
