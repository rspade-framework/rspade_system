#!/usr/bin/env node
/**
 * <Turnstile_Input> with the feature off (turnstile-pw-01, turnstile-pw-05).
 *
 *   1. in development the inert placeholder is shown, reserving the widget's height
 *   2. in a sealed build the component shows nothing and takes no room
 *   3. the hidden __turnstile field carries the 'inactive' sentinel in both
 *
 * The sealed build is simulated by clearing window.rsxapp.debug, the one fact the component
 * reads. Skips when this environment has Turnstile enabled, where there is no inactive state.
 *
 * Runs on the framework's own /_sys panel; self-contained, so a bare
 * `node inactive_display.js` runs it.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: turnstile inactive display - ' + msg);
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

        const result = await page.evaluate(async () => {
            if (Turnstile.is_active()) {
                return { skipped: true };
            }

            const mount = async () => {
                const component = $('<div>').appendTo('body').component('Turnstile_Input', {}).component();
                await component.ready();
                const state = {
                    placeholder: component.$sid('placeholder').is(':visible'),
                    widget: component.$sid('widget').is(':visible'),
                    height: component.$.height(),
                    value: component.$sid('token').val(),
                    name: component.$sid('token').attr('name'),
                };
                component.$.remove();
                return state;
            };

            const out = {};
            const was_debug = window.rsxapp.debug;

            window.rsxapp.debug = true;
            out.development = await mount();

            window.rsxapp.debug = false;
            out.sealed = await mount();

            window.rsxapp.debug = was_debug;
            return out;
        });

        if (result.skipped) {
            console.log('PASS: turnstile inactive display (skipped - Turnstile is enabled here)');
            return;
        }

        const check = (ok, name, detail) => {
            if (ok) { console.log('PASS: ' + name); } else { fail(name + ' - ' + JSON.stringify(detail)); }
        };

        check(result.development.placeholder && !result.development.widget && result.development.height >= 65,
            'development shows the placeholder at the widget height', result.development);
        check(!result.sealed.placeholder && !result.sealed.widget && result.sealed.height === 0,
            'a sealed build shows nothing and takes no room', result.sealed);
        check(result.development.value === 'inactive' && result.sealed.value === 'inactive'
            && result.development.name === '__turnstile' && result.sealed.name === '__turnstile',
            'the hidden field carries the inactive sentinel in both', result);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: turnstile inactive display');
    } else {
        console.log('PASS: turnstile inactive display');
    }
}

run();
