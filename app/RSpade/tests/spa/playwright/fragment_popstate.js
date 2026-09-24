#!/usr/bin/env node
/**
 * A fragment-only Back/Forward leaves the action mounted (SPA-HASH-01..04).
 *
 * Path and query route an action; the #fragment is page state the application owns. So a
 * popstate that keeps the path and query must not tear the action down - it fires
 * spa_hash_change and the application reacts. Only a browser has a real history stack, so
 * this is where that is proved:
 *
 *   1. pushState a fragment, go Back: spa_hash_change fires with the restored hash, and
 *      the SAME action instance is still mounted (a re-dispatch would construct a new one).
 *   2. The navigation guard is not consulted for that move - the user is not leaving the
 *      page - and stays registered.
 *   3. A Back across a PATH change still re-dispatches: a different action is mounted and
 *      spa_hash_change does not fire.
 *   4. An in-page `#at=` anchor link reaches its target on the mounted action - the
 *      browser's native fragment scroll cannot, since no element's id is "at=...".
 *
 * Runs against the framework's own /_sys panel, so nothing here depends on the application
 * tree. Self-contained: mints its own dev-auth headers through system/bin/dev-auth.js, so a
 * bare `node fragment_popstate.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const OTHER_ROUTE = '/_sys/tasks';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: fragment popstate - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    // Rsx is a top-level class binding - global, but not a window property - so the
    // existence question can only be asked with typeof.
    await page.waitForFunction(() => typeof Rsx !== 'undefined');

    await page.evaluate(() => new Promise((resolve) => {
        Rsx.on('_debug_ready', () => resolve());
        setTimeout(resolve, 15000);
    }));

    // Prevent false-positive session-hash reloads in the dev-auth environment.
    await page.evaluate(() => { Rsx.validate_session = async () => true; });
}

/** Open an authenticated page on START_ROUTE. Auth headers ride the initial document only. */
async function open_panel(context) {
    const page = await context.newPage();
    const auth_headers = {
        ...dev_auth_headers(START_ROUTE, USER_ID),
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

    await page.goto(BASE_URL + START_ROUTE, { waitUntil: 'commit' });
    await await_spa_ready(page);
    return page;
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

    try {
        const page = await open_panel(context);

        const on_page = await page.evaluate(() => !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present on ' + START_ROUTE + ' - auth may have failed');
            await browser.close();
            return;
        }

        // Every spa_hash_change and every guard consult is recorded on window, and the
        // mounted action is tagged, so each step can ask what happened and to whom.
        await page.evaluate(() => {
            window.__hash_events = [];
            window.__guard_consults = 0;
            Rsx.on('spa_hash_change', (data) => window.__hash_events.push(data));
            Spa.action().__fragment_probe = 'original';
        });

        // --- 1 + 2. a fragment-only Back keeps the action and skips the guard ---
        const fragment_move = await page.evaluate(async () => {
            Rsx.set_navigation_guard(async () => {
                window.__guard_consults++;
                return false;
            });

            history.pushState(history.state, '', window.location.pathname + '#panel=open');

            await new Promise((resolve) => {
                window.addEventListener('popstate', () => setTimeout(resolve, 0), { once: true });
                history.back();
            });

            const result = {
                events: window.__hash_events.slice(),
                probe: Spa.action() ? Spa.action().__fragment_probe : null,
                hash: window.location.hash,
                consults: window.__guard_consults,
                still_guarded: Rsx.has_navigation_guard(),
            };
            Rsx.clear_navigation_guard();
            return result;
        });

        if (fragment_move.events.length !== 1 || fragment_move.events[0].hash !== '') {
            fail('Back over a fragment should fire spa_hash_change once with hash "" - got ' + JSON.stringify(fragment_move.events));
        } else {
            console.log('PASS: Back over a fragment fires spa_hash_change with the restored hash');
        }

        if (fragment_move.probe !== 'original') {
            fail('Back over a fragment re-dispatched - the mounted action is not the original instance');
        } else {
            console.log('PASS: Back over a fragment leaves the same action instance mounted');
        }

        if (fragment_move.consults !== 0 || !fragment_move.still_guarded) {
            fail('a fragment-only move consulted the navigation guard (' + fragment_move.consults + ' times) or cleared it');
        } else {
            console.log('PASS: a fragment-only move does not consult the navigation guard');
        }

        // --- 4. an in-page anchor link scrolls without a dispatch ---
        // The browser's own fragment scroll looks for id="at=..." and finds nothing, so
        // the framework acts on the anchor itself; it focuses the target it found.
        const anchor_move = await page.evaluate(async () => {
            window.__hash_events = [];
            const $target = $('<h2 data-anchor="fragment_probe_target">Probe</h2>');
            Spa.action().$.append($('<div>').css('height', '3000px')).append($target);

            await new Promise((resolve) => {
                window.addEventListener('popstate', () => setTimeout(resolve, 0), { once: true });
                window.location.hash = 'at=fragment_probe_target';
            });

            return {
                focused: document.activeElement === $target[0],
                probe: Spa.action() ? Spa.action().__fragment_probe : null,
                events: window.__hash_events.length,
            };
        });

        if (!anchor_move.focused || anchor_move.probe !== 'original' || anchor_move.events !== 1) {
            fail('an in-page #at= link should reach its target on the mounted action - ' + JSON.stringify(anchor_move));
        } else {
            console.log('PASS: an in-page #at= link scrolls to its target without a dispatch');
        }

        // --- 3. a Back across a path change still re-dispatches ---
        // dispatch() does not await the action's ready(), so each step waits for the
        // spa_dispatch_ready that names ITS url; a stray earlier event cannot satisfy it.
        await page.evaluate(() => {
            window.__ready_waiters = [];
            Rsx.on('spa_dispatch_ready', (data) => {
                const path = Spa.parse_url(data.url).path;
                window.__ready_waiters = window.__ready_waiters.filter((waiter) => {
                    if (waiter.path !== path) {
                        return true;
                    }
                    waiter.resolve();
                    return false;
                });
            });
            window.__ready_for = (path) => new Promise((resolve) => window.__ready_waiters.push({ path, resolve }));
        });

        await page.evaluate(async (target) => {
            const ready = window.__ready_for(target);
            Spa.dispatch(target);
            await ready;
            window.__hash_events = [];
        }, OTHER_ROUTE);

        const path_move = await page.evaluate(async (start) => {
            const ready = window.__ready_for(start);
            history.back();
            await ready;
            return {
                path: window.location.pathname,
                events: window.__hash_events.slice(),
                probe: Spa.action() ? (Spa.action().__fragment_probe || null) : null,
            };
        }, START_ROUTE);

        if (path_move.path !== START_ROUTE || path_move.probe !== null) {
            fail('Back across a path change did not mount a fresh action - ' + JSON.stringify(path_move));
        } else {
            console.log('PASS: Back across a path change re-dispatches a fresh action');
        }

        if (path_move.events.length !== 0) {
            fail('Back across a path change fired spa_hash_change');
        } else {
            console.log('PASS: Back across a path change fires no spa_hash_change');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: fragment popstate');
    } else {
        console.log('PASS: fragment popstate');
    }
}

run();
