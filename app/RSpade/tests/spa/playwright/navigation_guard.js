#!/usr/bin/env node
/**
 * The navigation guard, end to end (SPA-GUARD-01..05).
 *
 * Rsx.set_navigation_guard() is one callback slot consulted at the single navigation
 * choke point, Spa.dispatch(), plus a beforeunload listener for real page exits. Only a
 * browser can prove either half, so this is where both are proved:
 *
 *   1. A guard resolving false BLOCKS the dispatch: the URL does not move, and the
 *      framework logs "prevented by the navigation guard" - the console line an
 *      application developer greps for.
 *   2. A guard resolving true ALLOWS the dispatch, and the guard is cleared by the
 *      navigation it approved, so nothing is left armed for the next page.
 *   3. ONE SLOT, NOT A STACK: three set_navigation_guard() calls install one callback
 *      (the last), and a single clear_navigation_guard() leaves no guard at all.
 *   4. While a guard is set, closing the page raises the browser's native beforeunload
 *      dialog; with no guard set, it does not. The callback is NOT consulted there -
 *      the native dialog is the whole prompt.
 *
 * Runs against the framework's own /_sys panel, so nothing here depends on the
 * application tree. Self-contained: mints its own dev-auth headers through
 * system/bin/dev-auth.js, so a bare `node navigation_guard.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const TARGET_ROUTE = '/_sys/tasks';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: navigation guard - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    // The document is only committed at this point, so the bundle that defines Rsx
    // may still be in flight - wait for it before driving anything through it. Rsx is a
    // top-level class binding, which is global but is not a window property, so the
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

        const console_lines = [];
        page.on('console', (msg) => console_lines.push(msg.text()));

        // --- 1. a guard resolving false blocks the dispatch ---
        const blocked = await page.evaluate(async (target) => {
            Rsx.set_navigation_guard(async () => false);
            await Spa.dispatch(target);
            return {
                path: window.location.pathname,
                still_guarded: Rsx.has_navigation_guard(),
            };
        }, TARGET_ROUTE);

        if (blocked.path !== START_ROUTE) {
            fail('a guard resolving false did not block the dispatch - landed on ' + blocked.path);
        } else {
            console.log('PASS: a guard resolving false blocks Spa.dispatch()');
        }

        if (!blocked.still_guarded) {
            fail('a REJECTED navigation cleared the guard - only an approved one may clear it');
        } else {
            console.log('PASS: a blocked navigation leaves the guard registered');
        }

        const warned = console_lines.some((line) => line.indexOf('prevented by the navigation guard') !== -1);
        if (!warned) {
            fail('no console warning containing "prevented by the navigation guard" was emitted');
        } else {
            console.log('PASS: the block is announced on the console');
        }

        // --- 2. a guard resolving true allows the dispatch and is cleared by it ---
        const allowed = await page.evaluate(async (target) => {
            Rsx.set_navigation_guard(async () => true);
            await Spa.dispatch(target);
            return {
                path: window.location.pathname,
                still_guarded: Rsx.has_navigation_guard(),
            };
        }, TARGET_ROUTE);

        if (allowed.path !== TARGET_ROUTE) {
            fail('a guard resolving true did not allow the dispatch - landed on ' + allowed.path);
        } else {
            console.log('PASS: a guard resolving true allows Spa.dispatch()');
        }

        if (allowed.still_guarded) {
            fail('the guard survived the navigation it approved');
        } else {
            console.log('PASS: an approved navigation clears the guard');
        }

        // --- 3. one slot, not a stack ---
        const slot = await page.evaluate(async () => {
            const calls = [];
            Rsx.set_navigation_guard(async () => { calls.push('first'); return true; });
            Rsx.set_navigation_guard(async () => { calls.push('second'); return true; });
            Rsx.set_navigation_guard(async () => { calls.push('third'); return true; });
            const answer = await Rsx.navigation_guard_allows('/anywhere');

            Rsx.set_navigation_guard(async () => true);
            Rsx.set_navigation_guard(async () => true);
            Rsx.set_navigation_guard(async () => true);
            Rsx.clear_navigation_guard();

            return { calls, answer, after_single_clear: Rsx.has_navigation_guard() };
        });

        if (slot.calls.join(',') !== 'third' || slot.answer !== true) {
            fail('three registrations must leave exactly the last callback, invoked once - invoked [' + slot.calls.join(',') + ']');
        } else {
            console.log('PASS: the last registration wins and is the only callback consulted');
        }

        if (slot.after_single_clear) {
            fail('one clear_navigation_guard() did not clear three set_navigation_guard() calls');
        } else {
            console.log('PASS: one clear discards the slot however many times it was set');
        }

        // --- 4. the native dialog on a real page exit ---
        // A browser suppresses beforeunload until the page has been interacted with,
        // so the click is what makes the dialog reachable at all.
        await page.mouse.click(5, 5);
        await page.evaluate(() => Rsx.set_navigation_guard(async () => false));

        const dialog_promise = page.waitForEvent('dialog');
        await page.close({ runBeforeUnload: true });
        const dialog = await dialog_promise;
        const dialog_type = dialog.type();
        await dialog.accept();

        if (dialog_type !== 'beforeunload') {
            fail('closing the page with a guard set raised a ' + dialog_type + ' dialog, expected beforeunload');
        } else {
            console.log('PASS: a real page exit raises the browser\'s native leave dialog');
        }

        // The negative case: no guard, so the page closes instead of asking. Waiting on
        // the close event is what proves no dialog intervened - a dialog would hold the
        // page open until it was answered.
        const clean_page = await open_panel(context);
        let clean_dialog_type = null;
        clean_page.on('dialog', async (d) => {
            clean_dialog_type = d.type();
            await d.accept();
        });
        await clean_page.mouse.click(5, 5);
        const closed_promise = clean_page.waitForEvent('close');
        await clean_page.close({ runBeforeUnload: true });
        await closed_promise;

        if (clean_dialog_type !== null) {
            fail('closing the page with NO guard set raised a ' + clean_dialog_type + ' dialog');
        } else {
            console.log('PASS: with no guard set, leaving the page asks nothing');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: navigation guard');
    } else {
        console.log('PASS: navigation guard');
    }
}

run();
