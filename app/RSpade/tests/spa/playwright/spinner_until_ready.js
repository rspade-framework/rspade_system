#!/usr/bin/env node
/**
 * @spinner_until_ready and Spa_Action.show_spinner() / hide_spinner() (SPA-SPINNER-01..11).
 *
 * @spinner_until_ready(mode) hides an action behind the application's spinner until its
 * whole tree is ready. 'uncached' holds only when jqhtml has no record (was_ready_before())
 * of this action with these args reaching ready in the cache scope; 'always' holds on every
 * visit, and boots the action with jqhtml's skip_cache_read so nothing in its hidden tree
 * paints from cache. Both are built on the two public action methods, which survive
 * re-renders.
 *
 * Runs on the framework's own /_sys panel (a browser test cannot see the test tree) and
 * declares the decorator at run time, in the browser, by setting the static the decorator
 * sets on panel actions that do not carry it. A fresh browser context has an empty jqhtml
 * cache, and was_ready_before() records a marker only for a component that asks, so each
 * 'uncached' action's first visit after being marked is cold by construction.
 *
 * Every hold is OBSERVED rather than sampled: a MutationObserver records each spinner the
 * moment it is inserted, with the visibility of the element it hides, so the assertions do
 * not depend on how quickly an action loads.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const UNCACHED_A = '/_sys/system-info';   // _Sys_System_Info_Action, marked 'uncached'
const UNCACHED_B = '/_sys/workers';       // _Sys_Task_Workers_Action, marked 'uncached'
const ALWAYS = '/_sys/tasks';             // _Sys_Tasks_Action, marked 'always'
const PLAIN = '/_sys';                    // _Sys_Dashboard_Action, unmarked

function fail(msg) {
    console.log('FAIL: @spinner_until_ready - ' + msg);
    process.exitCode = 1;
}

function check(ok, pass_msg, fail_msg) {
    if (ok) {
        console.log('PASS: ' + pass_msg);
    } else {
        fail(fail_msg);
    }
}

/**
 * Install the observer, the visit helper and the run-time decorations. `holds` collects,
 * per visit, every spinner seen being inserted and whether the element after it was hidden.
 */
async function install(page) {
    await page.evaluate(() => {
        window.__spin = { holds: [] };
        new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const node of m.addedNodes) {
                    if (node.nodeType === 1 && node.classList.contains('Spinner_Overlay')) {
                        window.__spin.holds.push({
                            hidden: node.nextElementSibling
                                ? getComputedStyle(node.nextElementSibling).visibility === 'hidden'
                                : null,
                        });
                    }
                }
            }
        }).observe(document.body, { childList: true, subtree: true });

        window.__spin.count = () => document.querySelectorAll('.Spinner_Overlay').length;
        window.__spin.visibility = () => getComputedStyle(Spa.action().$[0]).visibility;

        window.__spin.visit = async (url) => {
            window.__spin.holds = [];
            await Spa.dispatch(url);
            await Spa.action().ready();
            const action = Spa.action();
            const $child = action.$.find('.Component').first();
            return {
                holds: window.__spin.holds.slice(),
                left: window.__spin.count(),
                visibility: window.__spin.visibility(),
                // jqhtml's resolved skip_cache_read, on the action and on a component
                // created inside it, and whether the reserved arg leaked into this.args
                skip_cache_read: action._skip_cache_read === true,
                child_skip_cache_read: $child.length ? $child.component()._skip_cache_read === true : null,
                arg_leaked: 'skip_cache_read' in action.args,
            };
        };

        _Sys_System_Info_Action._spa_spinner_until_ready = 'uncached';
        _Sys_Task_Workers_Action._spa_spinner_until_ready = 'uncached';
        _Sys_Tasks_Action._spa_spinner_until_ready = 'always';
    });
}

const visit = (page, url) => page.evaluate((u) => window.__spin.visit(u), url);

async function run() {
    const { browser, page } = await open_sys_page();

    try {
        await install(page);

        // --- 01 + 02: 'uncached', cold: held, then revealed at ready ---
        const cold = await visit(page, UNCACHED_A);
        check(cold.holds.length === 1 && cold.holds[0].hidden === true,
            "'uncached': a cold visit hides the action behind one spinner",
            'cold visit not held as expected: ' + JSON.stringify(cold));
        check(cold.left === 0 && cold.visibility === 'visible',
            'the spinner is removed when the action is ready',
            'not revealed at ready: ' + JSON.stringify(cold));

        // --- 03: an undecorated action is never held ---
        const plain = await visit(page, PLAIN);
        check(plain.holds.length === 0 && plain.visibility === 'visible',
            'an action without the decorator is never held',
            'undecorated action held: ' + JSON.stringify(plain));

        // --- 04: 'uncached', warm: not held ---
        const warm = await visit(page, UNCACHED_A);
        check(warm.holds.length === 0 && warm.visibility === 'visible',
            "'uncached': a warm revisit (same action, same args) is not held",
            'warm revisit held: ' + JSON.stringify(warm));

        // --- 05: 'always': held on the first visit AND on the revisit ---
        const always_1 = await visit(page, ALWAYS);
        await visit(page, PLAIN);
        const always_2 = await visit(page, ALWAYS);
        check(always_1.holds.length === 1 && always_2.holds.length === 1
            && always_2.holds[0].hidden === true && always_2.left === 0,
            "'always': held on every visit, revisit included, and revealed at ready",
            "'always' not held on both visits: " + JSON.stringify({ always_1, always_2 }));

        // --- 11: 'always' skips the cache READ for the action and its tree; 'uncached' does not ---
        check(always_2.skip_cache_read && always_2.child_skip_cache_read === true && !always_2.arg_leaked
            && !warm.skip_cache_read && !warm.arg_leaked,
            "'always' boots the action and the components inside it with skip_cache_read, which never reaches this.args; 'uncached' reads the cache",
            'skip_cache_read wrong: ' + JSON.stringify({ always_2, warm }));

        // --- 06 + 07: show_spinner() survives a re-render; hide_spinner() reveals ---
        const manual = await page.evaluate(async () => {
            const action = Spa.action();
            action.show_spinner();
            const shown = { count: window.__spin.count(), visibility: window.__spin.visibility() };
            action.show_spinner();                     // a second show adds nothing
            const twice = window.__spin.count();
            await action.render();
            const after_render = { count: window.__spin.count(), visibility: window.__spin.visibility() };
            action.hide_spinner();
            const hidden = { count: window.__spin.count(), visibility: window.__spin.visibility() };
            action.hide_spinner();                     // hiding with none shown is a no-op
            return { shown, twice, after_render, hidden };
        });
        check(manual.shown.count === 1 && manual.shown.visibility === 'hidden' && manual.twice === 1,
            'show_spinner() hides the action behind one spinner, and a second call adds none',
            'show_spinner() wrong: ' + JSON.stringify(manual));
        check(manual.after_render.count === 1 && manual.after_render.visibility === 'hidden',
            'the spinner survives the action re-rendering',
            'spinner lost on re-render: ' + JSON.stringify(manual.after_render));
        check(manual.hidden.count === 0 && manual.hidden.visibility === 'visible',
            'hide_spinner() reveals the action',
            'hide_spinner() wrong: ' + JSON.stringify(manual.hidden));

        // --- 08: placement - the stage covers the on-screen part of the action ---
        const placement = await page.evaluate(() => {
            const action = Spa.action();
            action.show_spinner();
            const stage = document.querySelector('.Spinner_Overlay__stage').getBoundingClientRect();
            const target = action.$[0].getBoundingClientRect();
            action.hide_spinner();
            const expected_top = Math.max(target.top, 0);
            const expected_bottom = Math.min(target.bottom, window.innerHeight);
            return {
                top_ok: Math.abs(stage.top - expected_top) < 1,
                bottom_ok: Math.abs(stage.bottom - expected_bottom) < 1,
                stage: { top: stage.top, bottom: stage.bottom },
                expected: { top: expected_top, bottom: expected_bottom },
            };
        });
        check(placement.top_ok && placement.bottom_ok,
            'the spinner stage spans the action from its top edge to the lower of its bottom edge and the window',
            'placement wrong: ' + JSON.stringify(placement));

        // --- 09: a dispatch made during a hold removes the old spinner ---
        const abandoned = await page.evaluate(async (url) => {
            Spa.action().show_spinner();
            const held = window.__spin.count();
            await Spa.dispatch(url);
            await Spa.action().ready();
            return { held, left: window.__spin.count() };
        }, PLAIN);
        check(abandoned.held === 1 && abandoned.left === 0,
            'a dispatch made while a page is held removes the old spinner',
            'spinner left behind: ' + JSON.stringify(abandoned));

        // --- 10: an unhandled exception during a hold reveals the page ---
        const failed = await page.evaluate(async (url) => {
            const result = await window.__spin.visit(url);
            Spa.action().show_spinner();
            const held = window.__spin.count();
            Exception_Handler.display_unhandled_exception(new Error('spinner_until_ready probe'));
            return {
                first_visit_held: result.holds.length === 1,
                held,
                left: window.__spin.count(),
                visibility: window.__spin.visibility(),
            };
        }, UNCACHED_B);
        check(failed.first_visit_held && failed.held === 1 && failed.left === 0 && failed.visibility === 'visible',
            'an unhandled exception during a hold reveals the page',
            'exception left the page held: ' + JSON.stringify(failed));
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: @spinner_until_ready');
    } else {
        console.log('PASS: @spinner_until_ready');
    }
}

run();
