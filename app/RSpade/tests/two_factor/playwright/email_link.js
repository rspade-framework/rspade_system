#!/usr/bin/env node
/**
 * <Two_Factor_Challenge> and the emailed sign-in link (TFA-LINK-B-01..07).
 *
 * The screen is driven against a scripted controller on the framework's own /_sys panel -
 * what is under test is the screen, not a real challenge (Two_Factor_Email_Link_Test pins the
 * engine and the route's three answers):
 *
 *   1. a page opened with #rsx_email_link=<token> removes it from the address bar and posts
 *      {email_link: token} to the host's endpoint, once, without sending a code
 *   2. TWO PAGES, ONE BROWSER: the page whose answer passed announces it, and a page showing
 *      the same challenge swaps its controls for "You have signed in using the email link."
 *      and Continue, which goes to the redirect the other page was given
 *   3. a page showing a DIFFERENT challenge ignores the announcement
 *   4. on becoming visible again, a screen asks challenge_state() and swaps when it answers
 *      {completed} - or when the call is refused for CSRF, which is what a session rotated by
 *      a sign-in in another tab answers
 *   5. while mounted the screen holds the realtime session-refresh claim: a sign-in frame
 *      swaps a waiting screen ("This sign-in continued in another tab.") without reloading or
 *      asking the server, the broadcast that follows upgrades the wording, removing the
 *      screen releases the claim, and a screen with nothing pending hands the frame back
 *   6. a link that is no link (a bad signature) is answered by the framework's notice page on
 *      the staff host and under the portal's prefix, in ANOTHER browser context, with no
 *      sign-in involved
 *
 * Self-contained, so a bare `node email_link.js` runs it. Exit 0 on pass, 1 on any failure.
 * Prints PASS:/FAIL: lines.
 */
const { dev_auth_headers } = require('/var/www/html/system/bin/dev-auth.js');
const { chromium } = require('/var/www/html/system/node_modules/playwright');

const BASE_URL = 'http://localhost';
const START_ROUTE = '/_sys';
const USER_ID = 1;
const TOKEN = 'a'.repeat(64);

// A wait on the browser - a process this script does not control. It bounds the WAIT, never
// any work, and expiry fails the test naming the condition (tests/CLAUDE.md, the contention
// principle: 120 seconds is the house bound).
const WAIT_MS = 120000;

function fail(msg) {
    console.log('FAIL: two factor email link - ' + msg);
    process.exitCode = 1;
}

function check(ok, name, detail) {
    if (ok) {
        console.log('PASS: ' + name);
    } else {
        fail(name + ' - ' + JSON.stringify(detail));
    }
}

/** Wait for the SPA action's full lifecycle on the page (the shape code_entry.js uses). */
async function await_spa_ready(page) {
    // Rsx is a top-level class binding - global, but not a window property - so the
    // existence question can only be asked with typeof.
    await page.waitForFunction(() => typeof Rsx !== 'undefined', null, { timeout: WAIT_MS });

    await page.evaluate(() => new Promise((resolve) => {
        Rsx.on('_debug_ready', () => resolve());
        setTimeout(resolve, 15000);
    }));
}

/** Open an authenticated /_sys page, at an optional fragment. Auth headers ride the first document only. */
async function open_panel(context, fragment) {
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

    await page.goto(BASE_URL + START_ROUTE + (fragment || ''), { waitUntil: 'commit' });
    await await_spa_ready(page);
    return page;
}

/**
 * Install the scripted controllers on a page and mount a challenge screen.
 *
 * window.__tfa holds what the test reads and steers: posts (what the screen sent the host's
 * endpoint), sends (codes requested), state (what challenge_state answers), redirect (what a
 * passing answer is told), navigations (where the screen tried to go).
 */
async function mount_screen(page, challenge_id) {
    await page.evaluate(async (challenge_id) => {
        const tfa = {
            posts: [],
            sends: [],
            state: {
                challenge_id: challenge_id,
                email_masked: 'c***e@example.com',
                email: null,
                has_totp: false,
                has_passkey: false,
                has_recovery_codes: false,
                has_issued_code: true,
                codes_issued: 1,
            },
            redirect: '/_sys/tasks',
        };
        window.__tfa = tfa;

        const stub = Manifest.get_class_by_name('Spa_Session_Controller');
        stub.__link_probe = async (payload) => {
            tfa.posts.push(payload);
            return { redirect: tfa.redirect };
        };
        stub.__send_probe = async () => {
            tfa.sends.push(1);
            return null;
        };

        Rsx_Two_Factor.controller = () => ({
            challenge_state: async () => tfa.state,
            challenge_abandon: async () => null,
        });

        const component = $('<div class="tfa-under-test">').appendTo('body').component('Two_Factor_Challenge', {
            controller: 'Spa_Session_Controller',
            method: '__link_probe',
            send_controller: 'Spa_Session_Controller',
            send_method: '__send_probe',
        }).component();
        await component.ready();
        tfa.component = component;
    }, challenge_id);
}

/** The screen's signed-in message, or '' while it is not showing one. */
async function completed_text(page) {
    return page.evaluate(() => $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim());
}

async function run() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
    const other_context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });

    try {
        // ---- 2/3 setup: the code-entry tab, and a tab showing another challenge
        const code_tab = await open_panel(context);
        await mount_screen(code_tab, 'challenge-one');
        const stranger_tab = await open_panel(context);
        await mount_screen(stranger_tab, 'challenge-two');

        // ---- 1: the link tab, opened at the fragment the landing route redirects with.
        // The screen must not navigate away inside the test, so window.location assignment is
        // observed instead: the Spa_Session_Controller stub answers a redirect, and the test
        // swallows the navigation it causes.
        const link_tab = await open_panel(context, '#rsx_email_link=' + TOKEN);
        const navigations = [];
        link_tab.on('framenavigated', (frame) => {
            if (frame === link_tab.mainFrame()) {
                navigations.push(frame.url());
            }
        });
        const before_mount = await link_tab.evaluate(() => window.location.hash);
        await mount_screen(link_tab, 'challenge-one');

        // The answer passed and the page navigates to the redirect.
        await link_tab.waitForURL('**/_sys/tasks', { timeout: WAIT_MS });
        check(before_mount.includes('rsx_email_link=' + TOKEN), 'the landing fragment reached the page', before_mount);
        check(navigations.some((url) => url.endsWith('/_sys/tasks')), 'the link tab followed the redirect', navigations);

        // ---- 2: the code tab heard it and swapped
        await code_tab.waitForFunction(
            () => $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim() !== '',
            null,
            { timeout: WAIT_MS }
        );
        const code_tab_state = await code_tab.evaluate(() => ({
            message: $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim(),
            has_continue: $('.tfa-under-test .Two_Factor_Challenge__continue').length,
            has_boxes: $('.tfa-under-test .Code_Input__box').length,
        }));
        check(code_tab_state.message === 'You have signed in using the email link.' && code_tab_state.has_continue === 1 && code_tab_state.has_boxes === 0,
            'the code tab replaced its controls with the signed-in message and Continue', code_tab_state);

        // ---- 3: the other challenge's tab ignored it
        check(await completed_text(stranger_tab) === '', 'a tab showing another challenge ignores the announcement', null);

        // Continue goes where the link tab was sent.
        await Promise.all([
            code_tab.waitForURL('**/_sys/tasks', { timeout: WAIT_MS }),
            code_tab.click('.tfa-under-test .Two_Factor_Challenge__continue'),
        ]);
        check(code_tab.url().endsWith('/_sys/tasks'), 'Continue goes to the redirect the link tab was given', code_tab.url());

        // ---- 1 (what was posted): asserted on a page whose endpoint REFUSES the answer, so
        // the page stays to be read.
        const refuse_tab = await open_panel(context, '#rsx_email_link=' + TOKEN);
        const refused = await refuse_tab.evaluate(async (token) => {
            const posts = [];
            const sends = [];
            const stub = Manifest.get_class_by_name('Spa_Session_Controller');
            stub.__refuse_probe = async (payload) => {
                posts.push(payload);
                throw new Error('That code is not valid.');
            };
            stub.__refuse_send = async () => {
                sends.push(1);
                return null;
            };
            Rsx_Two_Factor.controller = () => ({
                challenge_state: async () => ({
                    challenge_id: 'challenge-four', email_masked: 'c***e@example.com', email: null,
                    has_totp: false, has_passkey: false, has_recovery_codes: false, has_issued_code: true, codes_issued: 0,
                }),
                challenge_abandon: async () => null,
            });
            const hash_before = window.location.hash;
            const c = $('<div class="tfa-refuse">').appendTo('body').component('Two_Factor_Challenge', {
                controller: 'Spa_Session_Controller', method: '__refuse_probe',
                send_controller: 'Spa_Session_Controller', send_method: '__refuse_send',
            }).component();
            await c.ready();
            return {
                hash_before: hash_before,
                hash_after: window.location.hash,
                posts: posts,
                sends: sends.length,
                error: c.$.find('.Two_Factor_Challenge__error').text().trim(),
                boxes: c.$.find('.Code_Input__box').length,
                token: token,
            };
        }, TOKEN);
        check(refused.hash_before.includes('rsx_email_link') && !refused.hash_after.includes('rsx_email_link'),
            'the token is taken out of the address bar', refused);
        check(refused.posts.length === 1 && refused.posts[0].email_link === TOKEN && Object.keys(refused.posts[0]).length === 1,
            'the screen posts {email_link: token} once', refused.posts);
        check(refused.sends === 0, 'no code is sent for a page opened from the link, even with none issued', refused.sends);
        check(refused.error === 'That code is not valid.' && refused.boxes === 6,
            'a refused link leaves the ordinary code entry with the message', refused);

        // ---- 4: visibility backstop - {completed} from the server, and the CSRF refusal a
        // rotated session answers with
        await stranger_tab.evaluate(() => {
            window.__tfa.state = { completed: true, challenge_id: 'challenge-two', by_email_link: true };
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await stranger_tab.waitForFunction(
            () => $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim() !== '',
            null,
            { timeout: WAIT_MS }
        );
        check(await completed_text(stranger_tab) === 'You have signed in using the email link.',
            'on becoming visible the screen asks challenge_state() and swaps on {completed}', null);

        const rotated_tab = await open_panel(context);
        await mount_screen(rotated_tab, 'challenge-six');
        await rotated_tab.evaluate(() => {
            Rsx_Two_Factor.controller = () => ({
                challenge_state: async () => {
                    const e = new Error('CSRF token mismatch');
                    e.code = Ajax.ERROR_UNAUTHORIZED;
                    throw e;
                },
                challenge_abandon: async () => null,
            });
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await rotated_tab.waitForFunction(
            () => $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim() !== '',
            null,
            { timeout: WAIT_MS }
        );
        check(await completed_text(rotated_tab) === 'This sign-in continued in another tab.',
            'a CSRF refusal on return (the session rotated) says the sign-in moved on', null);

        // ---- 5: the realtime session-refresh claim
        const claim_tab = await open_panel(context);
        await mount_screen(claim_tab, 'challenge-five');
        const claim = await claim_tab.evaluate(async () => {
            const out = {};
            out.claims_while_mounted = Rsx_Realtime._session_refresh_claims.size;

            let proceeded = 0;
            const proceed = () => { proceeded++; };
            const frame = () => {
                for (const handler of Rsx_Realtime._session_refresh_claims) {
                    handler(proceed);
                }
            };
            const message = () => $('.tfa-under-test .Two_Factor_Challenge__completed-message').text().trim();

            // A frame while this screen waits: the session moved on - swap, no reload, no
            // server call (the page's tokens are stale).
            window.__tfa.state = null;
            frame();
            out.proceeded_when_waiting = proceeded;
            out.moved_message = message();

            // The broadcast that follows replaces the vague message with the exact one.
            const sender = new BroadcastChannel('rsx_two_factor');
            sender.postMessage({ type: 'signed_in', realm: 'staff', challenge_id: 'challenge-five', redirect: '/_sys/tasks', by_email_link: true });
            sender.close();
            const started = Date.now();
            while (message() === out.moved_message && Date.now() - started < 120000) {
                await new Promise((r) => requestAnimationFrame(r));
            }
            out.upgraded_message = message();

            window.__tfa.component.stop();
            out.claims_after_stop = Rsx_Realtime._session_refresh_claims.size;

            // A screen with nothing pending does not answer the frame: it is handed back.
            window.__tfa.state = null;
            const idle = $('<div>').appendTo('body').component('Two_Factor_Challenge', {
                controller: 'Spa_Session_Controller', method: '__link_probe',
            }).component();
            await idle.ready();
            proceeded = 0;
            frame();
            out.proceeded_when_idle = proceeded;
            idle.stop();
            return out;
        });
        check(claim.claims_while_mounted === 1, 'the mounted screen holds the session-refresh claim', claim);
        check(claim.proceeded_when_waiting === 0 && claim.moved_message === 'This sign-in continued in another tab.',
            'a sign-in frame swaps a waiting screen instead of reloading it', claim);
        check(claim.upgraded_message === 'You have signed in using the email link.',
            'the broadcast that follows gives the exact message', claim);
        check(claim.claims_after_stop === 0, 'stopping the screen releases the claim', claim);
        check(claim.proceeded_when_idle === 1, 'a screen with nothing pending hands the frame back to the reload', claim);

        // ---- 6: the landing route, in another browser, on both hosts
        const notice_page = await other_context.newPage();
        for (const path of ['/_email_link/' + TOKEN + '?s=' + '0'.repeat(64), '/_portal/_email_link/' + TOKEN + '?s=' + '0'.repeat(64)]) {
            const response = await notice_page.goto(BASE_URL + path, { waitUntil: 'load' });
            const body = await notice_page.evaluate(() => document.body.innerText);
            check(response.status() === 410 && body.includes('This link has expired or was already used. Sign in again.'),
                'a dead link answers the notice page at ' + path.split('?')[0].replace(TOKEN, '<token>'), { status: response.status(), body: body.slice(0, 200) });
        }
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: two factor email link');
    } else {
        console.log('PASS: two factor email link');
    }
}

run();
