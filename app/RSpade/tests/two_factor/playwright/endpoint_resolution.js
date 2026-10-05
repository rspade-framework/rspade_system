#!/usr/bin/env node
/**
 * <Two_Factor_Challenge> and <Passkey_Sign_In> resolve their $controller through the
 * manifest (TFA-RES-01..03).
 *
 * Both components receive the APPLICATION's controller as a string. A bundle's classes are
 * lexical globals, not window properties, so the former window[controller] lookup found
 * nothing in any bundle and both components threw "could not resolve the endpoint" before
 * reaching it - passwordless sign-in and the 2FA challenge were dead on arrival. The registry
 * every generated controller stub is defined into is Manifest.get_class_by_name().
 *
 *   1. <Two_Factor_Challenge> reaches the endpoint: a probe method on a framework stub,
 *      throwing a known message, is what the component reports.
 *   2. <Passkey_Sign_In> gets past resolution (the headless browser has no WebAuthn, so the
 *      ceremony itself then fails on screen - that is not this test's subject).
 *   3. A controller the manifest does not know is still reported by name.
 *
 * Runs on the framework's own /_sys panel (both components ship in every bundle through
 * Core_Bundle); self-contained, so a bare `node endpoint_resolution.js` runs it.
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
    console.log('FAIL: two factor endpoint resolution - ' + msg);
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
            const stub = Manifest.get_class_by_name('Spa_Session_Controller');
            stub.__probe = async () => { throw new Error('probe reached'); };

            const mount = async (name, args) => {
                const component = $('<div>').appendTo('body').component(name, args).component();
                await component.ready();
                return component;
            };

            const challenge = await mount('Two_Factor_Challenge', { controller: 'Spa_Session_Controller', method: '__probe' });
            await challenge._submit({ code: '123456' });
            const challenge_error = challenge.state.error ? String(challenge.state.error.message || challenge.state.error) : null;

            const sign_in = await mount('Passkey_Sign_In', { controller: 'Spa_Session_Controller', method: '__probe' });
            let sign_in_threw = null;
            try { await sign_in._sign_in(); } catch (e) { sign_in_threw = e.message; }

            const unknown = await mount('Two_Factor_Challenge', { controller: 'No_Such_Controller', method: 'x' });
            let unknown_threw = null;
            try { await unknown._submit({}); } catch (e) { unknown_threw = e.message; }

            return { challenge_error, sign_in_threw, unknown_threw };
        });

        if (result.challenge_error !== 'probe reached') {
            fail('<Two_Factor_Challenge> did not reach its endpoint: ' + JSON.stringify(result.challenge_error));
        } else {
            console.log('PASS: <Two_Factor_Challenge> resolves its controller through the manifest');
        }

        if (result.sign_in_threw !== null) {
            fail('<Passkey_Sign_In> did not resolve its endpoint: ' + result.sign_in_threw);
        } else {
            console.log('PASS: <Passkey_Sign_In> resolves its controller through the manifest');
        }

        if (!result.unknown_threw || !result.unknown_threw.includes('No_Such_Controller::x')) {
            fail('an unknown controller was not reported by name: ' + JSON.stringify(result.unknown_threw));
        } else {
            console.log('PASS: an unknown controller is still reported by name');
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: two factor endpoint resolution');
    } else {
        console.log('PASS: two factor endpoint resolution');
    }
}

run();
