#!/usr/bin/env node
/**
 * The panel toolkit in a real browser: the SPA error-screen registry and _Sys_Modal
 * (RP-ERR-01..04, RP-MODAL-01..06).
 *
 * Both only exist in a browser, so this is where they are proved, on the framework's own
 * /_sys page (no application code involved):
 *
 *   ERROR SCREENS
 *     1. The panel bundle registered its own set (_Sys_Unauthorized / _Sys_Not_Found /
 *        _Sys_Error), and Error_Screens.not_found() mounts the registered component in the
 *        layout's content area.
 *     2. With nothing registered, an error screen THROWS naming set_components().
 *     3. A registered name the bundle does not carry THROWS naming the component.
 *     4. set_components() refuses a set missing a key.
 *
 *   _Sys_Modal
 *     1. confirm(): Enter accepts (resolves true).
 *     2. confirm(): the Cancel button resolves false; Escape resolves false.
 *     3. show(): a callback returning literal false keeps the dialog open; one returning
 *        null closes it and resolves null.
 *     4. Enter inside a textarea is a newline, not an accept.
 *     5. alert() with one argument renders it as the body, with no title header.
 *     6. form(): a component with no <Rsx_Form> fails loud instead of submitting nothing.
 *     7. form() round trip against a real panel endpoint - the Tasks screen's Kill dialog
 *        (_Sys_Task_Kill_Form -> _Sys_Tasks_Controller.kill): a blank explanation comes
 *        back as a server field error and the dialog stays open with the field marked;
 *        a real one kills the row and resolves the server result.
 *
 * Step 7 plants its own RUNNING _tasks row with NO worker_pid (so the kill signals no
 * process) through `php artisan db:query`, and deletes it afterwards.
 *
 * Self-contained: mints its own dev-auth headers (system/bin/dev-auth.js), so it runs with a
 * bare `node sys_panel_toolkit.js` against the dev web server on localhost. User 1 is the
 * baseline developer identity.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');
const { execFileSync } = require('child_process');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: panel toolkit - ' + msg);
    process.exitCode = 1;
}

function check(cond, pass_msg, fail_msg) {
    if (cond) {
        console.log('PASS: ' + pass_msg);
    } else {
        fail(fail_msg);
    }
}

async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));

    await page.evaluate(() => { if (typeof Rsx !== 'undefined') Rsx.validate_session = async () => true; });
}

/** Open a dialog in the page, keeping its promise on window, and wait until it has finished showing. */
async function open_dialog(page, expression) {
    await page.evaluate((expr) => {
        window.__dialog_result = undefined;
        window.__dialog_settled = false;
        // eslint-disable-next-line no-eval
        Promise.resolve(eval(expr)).then((r) => { window.__dialog_result = r; window.__dialog_settled = true; });
    }, expression);
    // Shown means Bootstrap's fade-in has finished: it ignores Escape and backdrop
    // dismissals while the show transition is still running.
    await page.waitForFunction(() => !!_Sys_Modal.top() && _Sys_Modal.top()._is_shown === true);
}

/** Wait for the open dialog's promise to settle and return its result. */
async function dialog_result(page) {
    await page.waitForFunction(() => window.__dialog_settled === true);
    return await page.evaluate(() => window.__dialog_result);
}

/** Run SQL against the site's database (no shell involved) and return the parsed rows. */
function db_query(sql) {
    const out = execFileSync('php', ['artisan', 'db:query', sql, '--json'], { cwd: '/var/www/html', encoding: 'utf8' });
    const start = out.indexOf('[');
    return start === -1 ? [] : JSON.parse(out.slice(start));
}

async function run() {
    let probe_task_id = null;

    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1600, height: 1000 },
    });
    const page = await context.newPage();

    const auth_headers = {
        ...dev_auth_headers(ROUTE, USER_ID),
        'X-Playwright-Test': '1',
    };
    let initial_doc_sent = false;
    await page.route('**/*', async (route, request) => {
        const url = request.url();
        if (url.startsWith(BASE_URL)) {
            const headers = { ...request.headers() };
            if (!initial_doc_sent && request.resourceType() === 'document') {
                Object.assign(headers, auth_headers);
                initial_doc_sent = true;
            } else {
                headers['X-Playwright-Test'] = '1';
            }
            await route.continue({ headers });
        } else {
            await route.continue();
        }
    });

    try {
        await page.goto(BASE_URL + ROUTE, { waitUntil: 'commit' });
        await await_spa_ready(page);

        const on_panel = await page.evaluate(() => typeof Spa !== 'undefined' && !!Spa.action() && !!document.querySelector('._Sys_Layout'));
        if (!on_panel) {
            fail('the /_sys panel did not render - auth may have failed');
            await browser.close();
            return;
        }

        // ---------------------------------------------------------------- error screens

        const registry = await page.evaluate(() => {
            const out = { registered: Error_Screens.get_components() };

            Error_Screens.not_found();
            out.mounted = !!Spa.layout.$sid('content').find('._Sys_Not_Found').length;

            const saved = Error_Screens._components;
            const message_of = (fn) => { try { fn(); return null; } catch (e) { return e.message; } };

            Error_Screens._components = null;
            out.unregistered = message_of(() => Error_Screens.not_found());

            Error_Screens._components = { unauthorized: '_Sys_Unauthorized', not_found: 'No_Such_Component_Anywhere', fatal: '_Sys_Error' };
            out.missing_component = message_of(() => Error_Screens.not_found());

            Error_Screens._components = saved;
            out.missing_key = message_of(() => Error_Screens.set_components({ unauthorized: 'a', not_found: 'b' }));
            out.restored = Error_Screens.get_components();

            return out;
        });

        const reg = registry.registered || {};
        check(
            reg.unauthorized === '_Sys_Unauthorized' && reg.not_found === '_Sys_Not_Found' && reg.fatal === '_Sys_Error',
            'the panel bundle registers its own three error screens',
            'unexpected registration: ' + JSON.stringify(registry.registered)
        );
        check(registry.mounted, 'Error_Screens.not_found() mounts the registered _Sys_Not_Found in the layout',
            'not_found() did not mount _Sys_Not_Found in the layout content area');
        check(registry.unregistered && registry.unregistered.includes('set_components'),
            'an unregistered bundle fails loud naming set_components()',
            'unregistered error screen did not throw usefully: ' + registry.unregistered);
        check(registry.missing_component && registry.missing_component.includes('No_Such_Component_Anywhere'),
            'a registered component the bundle lacks fails loud naming it',
            'missing registered component did not throw usefully: ' + registry.missing_component);
        check(registry.missing_key && registry.missing_key.includes('fatal'),
            'set_components() refuses a set missing a key',
            'set_components() accepted an incomplete set: ' + registry.missing_key);

        // ---------------------------------------------------------------- _Sys_Modal

        // 1. Enter accepts a confirm (focus is on the dialog, not a button).
        await open_dialog(page, "_Sys_Modal.confirm('Title', 'Body')");
        await page.evaluate(() => document.activeElement && document.activeElement.blur());
        await page.evaluate(() => document.querySelector('._Sys_Modal').focus());
        await page.keyboard.press('Enter');
        check(await dialog_result(page) === true, 'confirm(): Enter accepts (true)', 'confirm(): Enter did not resolve true');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 2a. Cancel resolves false.
        await open_dialog(page, "_Sys_Modal.confirm('Title', 'Body')");
        await page.click('._Sys_Modal .modal-footer [data-index="0"]');
        check(await dialog_result(page) === false, 'confirm(): Cancel resolves false', 'confirm(): Cancel did not resolve false');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 2b. Escape resolves false.
        await open_dialog(page, "_Sys_Modal.confirm('Title', 'Body')");
        await page.keyboard.press('Escape');
        check(await dialog_result(page) === false, 'confirm(): Escape resolves false', 'confirm(): Escape did not resolve false');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 3. Literal false keeps it open; null closes and resolves null.
        await open_dialog(page, "_Sys_Modal.show({title: 'T', body: 'B', buttons: [" +
            "{label: 'Keep', callback: () => false}, {label: 'Null', callback: () => null}]})");
        await page.click('._Sys_Modal .modal-footer [data-index="0"]');
        await page.waitForTimeout(400);
        const still_open = await page.evaluate(() => !window.__dialog_settled && !!document.querySelector('._Sys_Modal.show'));
        check(still_open, 'show(): a callback returning false keeps the dialog open', 'show(): a false callback closed the dialog');
        await page.click('._Sys_Modal .modal-footer [data-index="1"]');
        check(await dialog_result(page) === null, 'show(): a callback returning null closes it, resolving null',
            'show(): a null callback did not close/resolve null');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 4. Enter in a textarea is a newline.
        await open_dialog(page, "_Sys_Modal.show({title: 'T', body: $('<textarea class=\"probe\"></textarea>'), buttons: [" +
            "{label: 'OK', value: 'ok', class: 'btn-primary', default: true}]})");
        await page.focus('._Sys_Modal textarea.probe');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const textarea_state = await page.evaluate(() => ({
            settled: window.__dialog_settled,
            value: document.querySelector('._Sys_Modal textarea.probe').value,
        }));
        check(!textarea_state.settled && textarea_state.value === '\n',
            'Enter inside a textarea is a newline, not an accept',
            'Enter in a textarea accepted the dialog or was swallowed: ' + JSON.stringify(textarea_state));
        await page.evaluate(() => document.querySelector('._Sys_Modal').focus());
        await page.keyboard.press('Enter');
        check(await dialog_result(page) === 'ok', 'Enter outside the textarea accepts the default button',
            'Enter outside the textarea did not accept');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 5. One-argument alert: body only, no header.
        await open_dialog(page, "_Sys_Modal.alert('Only a body')");
        const alert_shape = await page.evaluate(() => ({
            header: document.querySelectorAll('._Sys_Modal .modal-header').length,
            body: document.querySelector('._Sys_Modal ._Sys_Modal__text').textContent.trim(),
        }));
        check(alert_shape.header === 0 && alert_shape.body === 'Only a body',
            'alert() with one argument renders it as the body',
            'alert() one-argument shape wrong: ' + JSON.stringify(alert_shape));
        await page.click('._Sys_Modal .modal-footer [data-index="0"]');
        check(await dialog_result(page) === true, 'alert(): OK resolves true', 'alert(): OK did not resolve true');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 6. form() around a component with no <Rsx_Form> fails loud on submit.
        const page_errors = [];
        page.on('pageerror', (e) => page_errors.push(e.message));
        await open_dialog(page, "_Sys_Modal.form({title: 'T', component: '_Sys_Empty_State', component_args: {title: 'No form here'}})");
        await page.click('._Sys_Modal .modal-footer [data-sys-modal-default]');
        await page.waitForTimeout(500);
        const form_state = await page.evaluate(() => ({ open: !!document.querySelector('._Sys_Modal.show') }));
        check(form_state.open && page_errors.some((m) => m.includes('found no <Rsx_Form>')),
            'form(): a component with no <Rsx_Form> fails loud and the dialog stays open',
            'form() without a form did not fail loud: ' + JSON.stringify({ form_state, page_errors }));
        await page.keyboard.press('Escape');
        check(await dialog_result(page) === false, 'form(): dismissing resolves false', 'form(): dismissal did not resolve false');
        await page.waitForSelector('._Sys_Modal', { state: 'detached' });

        // 7. form() round trip against the Kill endpoint.
        const marker = 'sys_panel_toolkit_probe_' + Date.now();
        db_query("INSERT INTO _tasks (class, method, queue, status, started_at, created_at) VALUES " +
            "('Sys_Panel_Toolkit_Probe_Service', '" + marker + "', 'default', 'running', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        probe_task_id = db_query("SELECT id FROM _tasks WHERE method = '" + marker + "'")[0].id;

        await open_dialog(page, "_Sys_Modal.form({title: 'Kill', component: '_Sys_Task_Kill_Form', " +
            "component_args: {task_id: " + probe_task_id + ", task_label: 'Probe'}, submit_label: 'Kill'})");
        await page.click('._Sys_Modal .modal-footer [data-sys-modal-default]');
        await page.waitForSelector('._Sys_Modal .is-invalid');
        const invalid_state = await page.evaluate(() => ({
            open: !!document.querySelector('._Sys_Modal.show'),
            settled: window.__dialog_settled,
        }));
        check(invalid_state.open && !invalid_state.settled,
            'form(): a server validation failure keeps the dialog open with the field marked invalid',
            'form() validation round trip wrong: ' + JSON.stringify(invalid_state));

        // The invalid mark lands on the input COMPONENT; its SCSS must carry it to the
        // <textarea> itself, or the field is never outlined (the transition settles first).
        await page.waitForTimeout(400);
        const outline = await page.evaluate(() => {
            const probe = document.createElement('div');
            probe.style.color = 'var(--bs-form-invalid-border-color)';
            document.body.appendChild(probe);
            const expected = getComputedStyle(probe).color;
            probe.remove();
            return { expected, actual: getComputedStyle(document.querySelector('._Sys_Modal textarea')).borderTopColor };
        });
        check(outline.actual === outline.expected,
            'form(): the invalid textarea itself is outlined in the invalid colour',
            'the invalid textarea is not outlined: ' + JSON.stringify(outline));

        await page.fill('._Sys_Modal textarea', 'toolkit round trip');
        await page.click('._Sys_Modal .modal-footer [data-sys-modal-default]');
        const kill_result = await dialog_result(page);
        const killed_row = db_query('SELECT status, status_reason FROM _tasks WHERE id = ' + probe_task_id)[0];
        check(kill_result && kill_result.id === probe_task_id && kill_result.outcome === 'killed_no_process'
                && killed_row.status === 'killed' && killed_row.status_reason === 'toolkit round trip',
            'form(): a valid submit resolves the server result and the endpoint acted',
            'form() success round trip wrong: ' + JSON.stringify({ kill_result, killed_row }));
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        if (probe_task_id !== null) {
            db_query('DELETE FROM _tasks WHERE id = ' + probe_task_id);
        }
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: panel toolkit');
    } else {
        console.log('PASS: panel toolkit');
    }
}

run();
