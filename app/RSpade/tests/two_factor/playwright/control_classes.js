#!/usr/bin/env node
/**
 * The two-factor components' controls carry classes of their own, and $unstyled_controls
 * drops the theme (Bootstrap) classes from them.
 *
 * Mounted at runtime on the framework's /_sys panel as a signed-in developer: Totp_Enrollment,
 * the one of the four whose controls render without a pending challenge or browser passkey
 * support (headless Chromium has none, so Passkey_Register and Passkey_Sign_In draw no
 * button here; their templates use the same two helpers).
 *
 *   a. by default the Confirm button carries btn btn-primary AND Totp_Enrollment__confirm;
 *   b. with $unstyled_controls it carries Totp_Enrollment__confirm and no btn class;
 *   c. either way the digit boxes keep Code_Input__box (Code_Input's own classes).
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const ROUTE = '/_sys';
const USER_ID = 1;

function fail(msg) {
    console.log('FAIL: two-factor control classes - ' + msg);
    process.exitCode = 1;
}

/** Wait for the SPA action's full lifecycle on the page. */
async function await_spa_ready(page) {
    await page.evaluate(() => new Promise((resolve) => {
        if (typeof Rsx !== 'undefined' && Rsx.on) {
            Rsx.on('_debug_ready', () => resolve());
            setTimeout(resolve, 15000);
        } else {
            setTimeout(resolve, 3000);
        }
    }));

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
    const page = await context.newPage();

    // Auth headers ONLY on the initial document request (per the SPA test harness contract).
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

        const on_page = await page.evaluate(() => typeof Spa !== 'undefined' && !!(Spa.action && Spa.action()));
        if (!on_page) {
            fail('SPA action not present - auth may have failed');
            await browser.close();
            return;
        }

        const result = await page.evaluate(async () => {
            const out = {};
            for (const unstyled of [false, true]) {
                const $host = $('<div>').appendTo('body');
                $host.component('Totp_Enrollment', { unstyled_controls: unstyled });
                for (let i = 0; i < 40 && !$host.find('.Totp_Enrollment__confirm').length; i++) {
                    await sleep(250);
                }
                const $confirm = $host.find('.Totp_Enrollment__confirm');
                out[unstyled ? 'unstyled' : 'styled'] = {
                    found: $confirm.length,
                    classes: $confirm.attr('class') || '',
                    boxes: $host.find('.Code_Input__box').length,
                };
                $host.remove();
            }
            return out;
        });

        const styled = result.styled;
        const unstyled = result.unstyled;

        if (styled.found === 1 && /\bbtn\b/.test(styled.classes) && /\bbtn-primary\b/.test(styled.classes)) {
            console.log('PASS: by default Confirm carries btn btn-primary and its own class');
        } else {
            fail('(a) default Confirm: ' + JSON.stringify(styled));
        }

        if (unstyled.found === 1 && !/\bbtn/.test(unstyled.classes)) {
            console.log('PASS: with $unstyled_controls Confirm carries only its own class');
        } else {
            fail('(b) unstyled Confirm: ' + JSON.stringify(unstyled));
        }

        if (styled.boxes === 6 && unstyled.boxes === 6) {
            console.log('PASS: the digit boxes keep Code_Input__box either way');
        } else {
            fail('(c) digit boxes: ' + styled.boxes + ' / ' + unstyled.boxes);
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: two-factor control classes');
    } else {
        console.log('PASS: two-factor control classes');
    }
}

run();
