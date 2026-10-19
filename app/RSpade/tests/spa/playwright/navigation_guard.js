#!/usr/bin/env node
/**
 * The navigation guard, end to end (SPA-GUARD-01..12).
 *
 * The guard is a toggle - Rsx.set_navigation_guard() / Rsx.clear_navigation_guard() -
 * and the BROWSER does the asking. Only a browser can prove any of it:
 *
 *   1. set_navigation_guard() takes no argument: passing one throws, naming the contract,
 *      and turns nothing on.
 *   2. One slot, idempotent: three sets are one guard, one clear turns it off, a second
 *      clear changes nothing.
 *   3. Guard OFF: a link click is intercepted and dispatched in the SPA, with no dialog.
 *   4. Guard ON: the same link is NOT intercepted. A real navigation starts and the
 *      browser offers its beforeunload dialog. Dismissed: the page, its action and the
 *      guard are exactly as they were.
 *   5. Clearing the guard restores interception on the very next click.
 *   6. Guard ON, dialog accepted: the target arrives as a full document.
 *   7. Navigation from code is not guarded: Spa.dispatch() and Spa.redirect() go ahead
 *      in the SPA with no dialog and leave the guard off.
 *   8. A dispatch to the page already showing (a fragment) is not a departure: no dialog,
 *      the action stays mounted, the guard stays on.
 *   9. Closing the page raises the beforeunload dialog while the guard is on, and nothing
 *      once it has been cleared.
 *
 * Back/Forward under the guard is history_scroll_and_veto.js; a fragment-only Back is
 * fragment_popstate.js.
 *
 * Runs against the framework's own /_sys panel, so nothing here depends on the
 * application tree. A bare `node navigation_guard.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const START_ROUTE = '/_sys';
const TARGET_ROUTE = '/_sys/tasks';
const OTHER_ROUTE = '/_sys/workers';

function fail(msg) {
    console.log('FAIL: navigation guard - ' + msg);
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
 * Open /_sys with every dialog recorded and answered as `session.answer` says
 * ('accept' or 'dismiss'), and prepare the document just loaded.
 */
async function open_session() {
    const opened = await open_sys_page();
    const session = { browser: opened.browser, page: opened.page, dialogs: [], answer: 'dismiss' };

    session.page.on('dialog', async (dialog) => {
        session.dialogs.push({ type: dialog.type(), message: dialog.message() });
        if (session.answer === 'accept') {
            await dialog.accept();
        } else {
            await dialog.dismiss();
        }
    });

    await prepare_document(session.page);
    return session;
}

/**
 * Mark the document, count completed dispatches and plant two links in the layout (which
 * outlives every action). The mark is how a full page load is told from an in-SPA
 * navigation: a new document has none.
 */
async function prepare_document(page) {
    await page.evaluate(([target, other]) => {
        window.__document_mark = 'original';
        window.__dispatches = 0;
        Rsx.on('spa_dispatch_ready', () => { window.__dispatches++; });

        const $links = $('<div style="position:fixed;top:0;left:0;z-index:99999;background:#fff"></div>');
        $links.append($('<a id="guard_probe_target">target</a>').attr('href', target));
        $links.append(' ');
        $links.append($('<a id="guard_probe_other">other</a>').attr('href', other));
        $('body').append($links);
    }, [TARGET_ROUTE, OTHER_ROUTE]);
}

const state = (page) => page.evaluate(() => ({
    path: location.pathname,
    hash: location.hash,
    mark: window.__document_mark || null,
    guarded: Rsx.has_navigation_guard(),
    probe: Spa.action() ? (Spa.action().__guard_probe || null) : null,
}));

/** Run something that navigates in the SPA and wait for the action it lands on. */
async function in_spa(page, action) {
    const before = await page.evaluate(() => window.__dispatches);
    await action();
    await page.waitForFunction((n) => window.__dispatches > n, before);
}

/** Click a link and wait for the dialog the browser raises over it to be answered. */
async function click_and_await_dialog(session, selector) {
    const seen = session.dialogs.length;
    const answered = session.page.waitForEvent('dialog');
    await session.page.click(selector, { noWaitAfter: true });
    await answered;
    return session.dialogs[seen];
}

async function run() {
    let session = null;
    let closing = null;

    try {
        session = await open_session();
        const page = session.page;

        // --- 1. an argument throws ---
        const refused = await page.evaluate(() => {
            const messages = [];
            for (const argument of [async () => true, undefined, true]) {
                try {
                    Rsx.set_navigation_guard(argument);
                    messages.push(null);
                } catch (e) {
                    messages.push(e.message);
                }
            }
            return { messages, guarded: Rsx.has_navigation_guard() };
        });
        check(
            refused.messages.every((m) => m !== null
                && m.indexOf('Rsx.set_navigation_guard() takes no argument') === 0
                && m.indexOf('Rsx.clear_navigation_guard()') !== -1
                && m.indexOf('rsx:man spa') !== -1),
            'set_navigation_guard() with any argument throws, stating the contract', refused);
        check(refused.guarded === false, 'a refused call turns nothing on', refused);

        // --- 2. one slot, idempotent ---
        const slot = await page.evaluate(() => {
            const before = Rsx.has_navigation_guard();
            Rsx.set_navigation_guard();
            Rsx.set_navigation_guard();
            Rsx.set_navigation_guard();
            const on = Rsx.has_navigation_guard();
            Rsx.clear_navigation_guard();
            const off = Rsx.has_navigation_guard();
            Rsx.clear_navigation_guard();
            return { before, on, off, off_again: Rsx.has_navigation_guard() };
        });
        check(slot.before === false && slot.on === true && slot.off === false && slot.off_again === false,
            'the guard is one toggle: three sets are one guard, one clear turns it off', slot);

        // --- 3. guard off: a link click is an in-SPA navigation ---
        await in_spa(page, () => page.click('#guard_probe_target'));
        const unguarded = await state(page);
        check(unguarded.path === TARGET_ROUTE && unguarded.mark === 'original' && session.dialogs.length === 0,
            'guard off: a link click navigates in the SPA with no dialog', { unguarded, dialogs: session.dialogs });

        // --- 4. guard on: the link is the browser's, and the browser asks ---
        await page.evaluate(() => {
            Spa.action().__guard_probe = 'kept';
            Rsx.set_navigation_guard();
        });
        session.answer = 'dismiss';
        const dispatches_before = await page.evaluate(() => window.__dispatches);
        const stay_dialog = await click_and_await_dialog(session, '#guard_probe_other');
        const stayed = await state(page);
        const dispatches_after = await page.evaluate(() => window.__dispatches);

        check(stay_dialog && stay_dialog.type === 'beforeunload',
            'guard on: a link click is not intercepted - the browser offers its beforeunload dialog', stay_dialog);
        check(stayed.path === TARGET_ROUTE && stayed.mark === 'original' && stayed.probe === 'kept'
            && dispatches_after === dispatches_before,
            'dismissed: the page and its action are exactly as they were, nothing dispatched',
            { stayed, dispatches_before, dispatches_after });
        check(stayed.guarded === true, 'dismissed: the guard is still on', stayed);

        // --- 5. clearing the guard restores interception on the very next click ---
        const dialogs_before_clear = session.dialogs.length;
        await page.evaluate(() => Rsx.clear_navigation_guard());
        await in_spa(page, () => page.click('#guard_probe_other'));
        const restored = await state(page);
        check(restored.path === OTHER_ROUTE && restored.mark === 'original' && session.dialogs.length === dialogs_before_clear,
            'guard cleared: the next click is intercepted again, with no dialog', { restored, dialogs: session.dialogs });

        // --- 7. navigation from code is not guarded, and turns the guard off ---
        const dialogs_before_code = session.dialogs.length;
        await page.evaluate(() => Rsx.set_navigation_guard());
        await in_spa(page, () => page.evaluate((target) => { Spa.dispatch(target); }, TARGET_ROUTE));
        const dispatched = await state(page);
        check(dispatched.path === TARGET_ROUTE && dispatched.mark === 'original' && session.dialogs.length === dialogs_before_code,
            'guard on: Spa.dispatch() navigates in the SPA with no dialog', { dispatched, dialogs: session.dialogs });
        check(dispatched.guarded === false, 'Spa.dispatch() leaves the guard off', dispatched);

        await page.evaluate(() => Rsx.set_navigation_guard());
        await in_spa(page, () => page.evaluate((target) => { Spa.redirect(target); }, START_ROUTE));
        const redirected = await state(page);
        check(redirected.path === START_ROUTE && redirected.mark === 'original' && redirected.guarded === false
            && session.dialogs.length === dialogs_before_code,
            'guard on: Spa.redirect() navigates in the SPA with no dialog and leaves the guard off',
            { redirected, dialogs: session.dialogs });

        // --- 8. a dispatch to the page already showing is not a departure ---
        const same_page = await page.evaluate(async () => {
            Spa.action().__guard_probe = 'same_page';
            Rsx.set_navigation_guard();
            const before = window.__dispatches;

            await new Promise((resolve) => {
                window.addEventListener('popstate', () => setTimeout(resolve, 0), { once: true });
                Spa.dispatch(window.location.pathname + '#guard_probe=1');
            });

            return {
                hash: window.location.hash,
                probe: Spa.action() ? Spa.action().__guard_probe : null,
                mark: window.__document_mark || null,
                guarded: Rsx.has_navigation_guard(),
                dispatched: window.__dispatches - before,
            };
        });
        check(same_page.hash === '#guard_probe=1' && same_page.probe === 'same_page' && same_page.mark === 'original'
            && same_page.dispatched === 0 && session.dialogs.length === dialogs_before_code,
            'a dispatch that changes only the fragment keeps the action mounted and asks nothing',
            { same_page, dialogs: session.dialogs });
        check(same_page.guarded === true, 'a dispatch to the page already showing leaves the guard on', same_page);

        // --- 6. guard on, dialog accepted: a full page load ---
        session.answer = 'accept';
        const leave_dialog = await click_and_await_dialog(session, '#guard_probe_target');
        await page.waitForURL('**' + TARGET_ROUTE);
        await page.waitForFunction(() => {
            try {
                return typeof Spa !== 'undefined' && !!Spa.action();
            } catch (e) {
                return false;
            }
        }, null, { timeout: 0 });
        const left = await state(page);
        check(leave_dialog && leave_dialog.type === 'beforeunload' && left.path === TARGET_ROUTE && left.mark === null,
            'accepted: the target arrives as a full document', { leave_dialog, left });
        check(left.guarded === false, 'the new document starts with the guard off', left);

        // --- 9. a real page exit ---
        // A browser raises beforeunload only once the page has been interacted with, so
        // the click is what makes the dialog reachable in this fresh document.
        await prepare_document(page);
        await page.mouse.click(600, 400);
        await page.evaluate(() => Rsx.set_navigation_guard());

        const close_dialog = page.waitForEvent('dialog');
        const seen_before_close = session.dialogs.length;
        await page.close({ runBeforeUnload: true });
        await close_dialog;
        check(session.dialogs[seen_before_close] && session.dialogs[seen_before_close].type === 'beforeunload',
            'guard on: closing the page raises the browser\'s native leave dialog', session.dialogs[seen_before_close]);
        await session.browser.close();
        session = null;

        // The guard was on and has been cleared, so the listener is installed and must
        // stay silent. Waiting on the close event is what proves no dialog intervened - a
        // dialog would hold the page open until it was answered.
        closing = await open_session();
        closing.answer = 'accept';
        await closing.page.mouse.click(600, 400);
        await closing.page.evaluate(() => {
            Rsx.set_navigation_guard();
            Rsx.clear_navigation_guard();
        });
        const closed = closing.page.waitForEvent('close');
        await closing.page.close({ runBeforeUnload: true });
        await closed;
        check(closing.dialogs.length === 0, 'guard cleared: leaving the page asks nothing', closing.dialogs);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        if (session) {
            await session.browser.close();
        }
        if (closing) {
            await closing.browser.close();
        }
    }

    if (process.exitCode === 1) {
        console.log('FAIL: navigation guard');
    } else {
        console.log('PASS: navigation guard');
    }
}

run();
