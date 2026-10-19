#!/usr/bin/env node
/**
 * The typed answer on <Two_Factor_Challenge> and <Totp_Enrollment> is the one-digit-per-box
 * <Code_Input> (TFA-CODE-01..08).
 *
 * Both screens are driven against a scripted controller, because what is under test is the
 * screen - which control it shows, what it posts, where the cursor is - and not a real
 * challenge or enrollment:
 *
 *   1. a challenge that takes only six-digit answers shows the digit boxes, no recovery box
 *      and no switch link, with the cursor in the first box
 *   2. Verify with empty boxes posts nothing
 *   3. Enter in a complete row posts {code} exactly once
 *   4. a refusal shows the message, marks the boxes, empties them and returns the cursor
 *   5. a challenge that also has recovery codes offers "Use a recovery code instead", which
 *      swaps in a plain box (and back), and the plain box posts what was typed
 *   6. a recovery-codes-only challenge shows the plain box alone
 *   7. enrollment shows the boxes with the cursor in them; a blank Confirm posts nothing
 *   8. a refused enrollment code shows the message and returns the cursor to empty boxes
 *
 * Runs on the framework's own /_sys panel; self-contained, so a bare
 * `node code_entry.js` runs it.
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
    console.log('FAIL: two factor code entry - ' + msg);
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
            const out = {};
            const posts = [];
            let refuse = false;

            // The application endpoint the challenge posts to.
            const stub = Manifest.get_class_by_name('Spa_Session_Controller');
            stub.__code_probe = async (payload) => {
                posts.push(payload);
                if (refuse) { throw new Error('That code is not valid.'); }
                // No redirect is followed in this test: every scripted answer is a refusal
                // or is counted before the screen would navigate.
                throw new Error('accepted');
            };

            // The framework controller both screens read their state from.
            let challenge_state = null;
            const confirms = [];
            const real_controller = Rsx_Two_Factor.controller;
            Rsx_Two_Factor.controller = () => ({
                challenge_state: async () => challenge_state,
                totp_begin: async () => ({ qr_svg: '<svg></svg>', secret: 'JBSWY3DPEHPK3PXP' }),
                totp_confirm: async (payload) => { confirms.push(payload); throw new Error('That code is not valid. Please try again.'); },
            });

            const mount = async (name, args) => {
                const component = $('<div>').appendTo('body').component(name, args).component();
                await component.ready();
                await sleep(100);
                return component;
            };
            const boxes = (c) => c.$.find('.Code_Input__box').get();
            const type_code = (c, digits) => {
                const first = boxes(c)[0];
                first.value = digits;
                first.dispatchEvent(new Event('input', { bubbles: true }));
            };
            const press_enter = (el) => el.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
            const base = { email_masked: 'c***e@example.com', email: null, has_passkey: false, codes_issued: 0 };

            // 1-4: six-digit answers only
            challenge_state = { ...base, has_totp: true, has_issued_code: false, has_recovery_codes: false };
            let c = await mount('Two_Factor_Challenge', { controller: 'Spa_Session_Controller', method: '__code_probe' });
            out.digits_only = {
                boxes: boxes(c).length,
                recovery: c.$sid('recovery').length,
                switch_link: c.$sid('switch').length,
                focused: boxes(c).indexOf(document.activeElement),
            };

            c.$sid('verify').trigger('click');
            await sleep(100);
            out.blank_posts = posts.length;

            refuse = true;
            type_code(c, '123456');
            press_enter(document.activeElement);
            await sleep(300);
            out.enter_posts = posts.slice();
            out.refused = {
                error: c.$.find('.Two_Factor_Challenge__error').text().trim(),
                invalid: c.sid('code').$.hasClass('is-invalid'),
                value: c.sid('code').val(),
                focused: boxes(c).indexOf(document.activeElement),
            };

            // An incomplete row: Enter is the widget's, and nothing is posted.
            posts.length = 0;
            type_code(c, '12');
            press_enter(document.activeElement);
            await sleep(100);
            out.incomplete_posts = posts.length;
            c.$.remove();

            // 5: six digits AND recovery codes
            posts.length = 0;
            challenge_state = { ...base, has_totp: true, has_issued_code: false, has_recovery_codes: true };
            c = await mount('Two_Factor_Challenge', { controller: 'Spa_Session_Controller', method: '__code_probe' });
            out.both_initial = { boxes: boxes(c).length, recovery: c.$sid('recovery').length, link: c.$sid('switch').text().trim() };
            c.$sid('switch').trigger('click');
            await sleep(100);
            out.both_switched = {
                boxes: boxes(c).length,
                recovery: c.$sid('recovery').length,
                link: c.$sid('switch').text().trim(),
                focused: document.activeElement === c.$sid('recovery').get(0),
            };
            c.$sid('recovery').val('  abcd-efgh  ');
            c.$sid('verify').trigger('click');
            await sleep(300);
            out.recovery_posts = posts.slice();
            c.$sid('switch').trigger('click');
            await sleep(100);
            out.both_back = { boxes: boxes(c).length, recovery: c.$sid('recovery').length };
            c.$.remove();

            // 6: recovery codes only
            challenge_state = { ...base, has_totp: false, has_issued_code: false, has_recovery_codes: true };
            c = await mount('Two_Factor_Challenge', { controller: 'Spa_Session_Controller', method: '__code_probe' });
            out.recovery_only = { boxes: boxes(c).length, recovery: c.$sid('recovery').length, switch_link: c.$sid('switch').length };
            c.$.remove();

            // 7-8: enrollment
            const e = await mount('Totp_Enrollment', {});
            out.enroll_initial = { boxes: boxes(e).length, focused: boxes(e).indexOf(document.activeElement) };
            e.$sid('confirm').trigger('click');
            await sleep(100);
            out.enroll_blank_confirms = confirms.length;
            type_code(e, '654321');
            press_enter(document.activeElement);
            await sleep(300);
            out.enroll_refused = {
                confirms: confirms.slice(),
                error: e.$.find('.Totp_Enrollment__error').text().trim(),
                value: e.sid('code').val(),
                focused: boxes(e).indexOf(document.activeElement),
            };
            e.$.remove();

            Rsx_Two_Factor.controller = real_controller;
            delete stub.__code_probe;
            return out;
        });

        const check = (ok, name, detail) => {
            if (ok) { console.log('PASS: ' + name); } else { fail(name + ' - ' + JSON.stringify(detail)); }
        };

        check(result.digits_only.boxes === 6 && result.digits_only.recovery === 0 && result.digits_only.switch_link === 0 && result.digits_only.focused === 0,
            'a six-digit challenge shows the boxes alone, cursor in the first', result.digits_only);
        check(result.blank_posts === 0, 'Verify with empty boxes posts nothing', result.blank_posts);
        check(result.enter_posts.length === 1 && result.enter_posts[0].code === '123456',
            'Enter in a complete row posts {code} once', result.enter_posts);
        check(result.refused.error === 'That code is not valid.' && result.refused.invalid && result.refused.value === '' && result.refused.focused === 0,
            'a refusal shows the message, marks and empties the boxes, and returns the cursor', result.refused);
        check(result.incomplete_posts === 0, 'Enter in an incomplete row posts nothing', result.incomplete_posts);
        check(result.both_initial.boxes === 6 && result.both_initial.recovery === 0 && result.both_initial.link === 'Use a recovery code instead',
            'with recovery codes the boxes come with a switch link', result.both_initial);
        check(result.both_switched.boxes === 0 && result.both_switched.recovery === 1 && result.both_switched.focused
            && result.both_switched.link === 'Use a 6-digit code instead',
            'the link swaps in a plain recovery box with the cursor in it', result.both_switched);
        check(result.recovery_posts.length === 1 && result.recovery_posts[0].code === 'abcd-efgh',
            'the recovery box posts what was typed, trimmed', result.recovery_posts);
        check(result.both_back.boxes === 6 && result.both_back.recovery === 0, 'the link swaps back to the boxes', result.both_back);
        check(result.recovery_only.boxes === 0 && result.recovery_only.recovery === 1 && result.recovery_only.switch_link === 0,
            'a recovery-only challenge shows the plain box alone', result.recovery_only);
        check(result.enroll_initial.boxes === 6 && result.enroll_initial.focused === 0, 'enrollment shows the boxes with the cursor in them', result.enroll_initial);
        check(result.enroll_blank_confirms === 0, 'a blank Confirm posts nothing', result.enroll_blank_confirms);
        check(result.enroll_refused.confirms.length === 1 && result.enroll_refused.confirms[0].code === '654321'
            && result.enroll_refused.error.startsWith('That code is not valid') && result.enroll_refused.value === '' && result.enroll_refused.focused === 0,
            'a refused enrollment code shows the message and returns the cursor to empty boxes', result.enroll_refused);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: two factor code entry');
    } else {
        console.log('PASS: two factor code entry');
    }
}

run();
