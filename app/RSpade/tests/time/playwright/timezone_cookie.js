#!/usr/bin/env node
/**
 * The browser's half of the per-request timezone (TIME-COOKIE-01 .. TIME-COOKIE-05).
 *
 *   1. a page whose user is automatic (or signed out) carries the rsx_tz cookie: the browser's
 *      own zone, written at core init - and the interstitial's ?_rsx_tz=1 marker is gone from
 *      the address
 *   2. a user with an explicit zone has the cookie CLEARED (wants_cookie() is false), and is
 *      never watched
 *   3. a browser that cannot report a zone writes the error value
 *   4. a zone change under an open SPA page updates the cookie, disables the SPA (the next
 *      link is a full load) and freezes Rsx_Time's zone against later Ajax envelopes
 *   5. an unchanged zone does nothing
 *
 * Runs on the framework's own /_sys panel. The browser runs in America/Chicago (Playwright's
 * timezoneId), and the user's preference is simulated in the page through
 * rsxapp.user_timezone_preference - the one fact Rsx_Timezone_Cookie reads - so nothing is
 * written to the account. A change of zone is simulated by replacing browser_value().
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        console.log('FAIL: timezone cookie - ' + label + ' - got ' + JSON.stringify(detail));
        process.exitCode = 1;
    }
}

async function main() {
    const { browser, page, errors } = await open_sys_page({ timezone_id: 'America/Chicago' });
    try {
        const loaded = await page.evaluate(() => ({
            cookie: Rsx_Timezone_Cookie.read(),
            browser: Intl.DateTimeFormat().resolvedOptions().timeZone,
            preference: window.rsxapp.user_timezone_preference,
            search: window.location.search,
            rendered: window.rsxapp.user_timezone,
        }));
        if (loaded.preference === null) {
            check('an automatic user\'s page writes the browser zone', loaded.cookie === 'America/Chicago', loaded);
            check('and renders in it', loaded.rendered === 'America/Chicago', loaded);
        } else {
            console.log('NOTE: user 1 has an explicit zone (' + loaded.preference + '); the cookie assertions run on the simulated preference below');
        }
        check('the reload marker is stripped from the address', !loaded.search.includes('_rsx_tz'), loaded);

        const explicit = await page.evaluate(() => {
            const was = window.rsxapp.user_timezone_preference;
            window.rsxapp.user_timezone_preference = 'Europe/Berlin';
            Rsx_Timezone_Cookie.write('America/Chicago');
            Rsx_Timezone_Cookie._on_framework_core_init();
            const watched_was = Rsx_Timezone_Cookie._watched_value;
            Rsx_Timezone_Cookie._watched_value = null;
            Rsx_Timezone_Cookie.watch();
            const out = {
                cookie: Rsx_Timezone_Cookie.read(),
                wants: Rsx_Timezone_Cookie.wants_cookie(window.rsxapp),
                watched: Rsx_Timezone_Cookie._watched_value,
            };
            Rsx_Timezone_Cookie._watched_value = watched_was;
            window.rsxapp.user_timezone_preference = was;
            return out;
        });
        check('an explicit-zone user has the cookie cleared', explicit.cookie === null && explicit.wants === false, explicit);
        check('and is never watched', explicit.watched === null, explicit);

        const failed = await page.evaluate(() => {
            const original = Intl.DateTimeFormat;
            Intl.DateTimeFormat = function () { throw new Error('no Intl'); };
            const value = Rsx_Timezone_Cookie.browser_value();
            Intl.DateTimeFormat = original;
            window.rsxapp.user_timezone_preference = null;
            Rsx_Timezone_Cookie.write(value);
            return { value, cookie: Rsx_Timezone_Cookie.read() };
        });
        check('a browser with no zone writes the error value',
            failed.value === 'error_reading_browser_timezone' && failed.cookie === 'error_reading_browser_timezone', failed);

        const unchanged = await page.evaluate(() => {
            Rsx_Timezone_Cookie._watched_value = 'America/Chicago';
            Rsx_Timezone_Cookie.write('America/Chicago');
            return { changed: Rsx_Timezone_Cookie.check(), spa: Spa._spa_enabled, frozen: Rsx_Time._timezone_frozen };
        });
        check('an unchanged zone does nothing', unchanged.changed === false && unchanged.spa === true && unchanged.frozen === false, unchanged);

        const moved = await page.evaluate(() => {
            const original = Rsx_Timezone_Cookie.browser_value;
            Rsx_Timezone_Cookie.browser_value = () => 'Asia/Tokyo';
            const changed = Rsx_Timezone_Cookie.check();
            Rsx_Timezone_Cookie.browser_value = original;
            const zone_before = Rsx_Time.get_user_timezone();
            Rsx_Time.sync_from_ajax({ user_timezone: 'Asia/Tokyo' });
            return {
                changed,
                cookie: Rsx_Timezone_Cookie.read(),
                spa: Spa._spa_enabled,
                frozen: Rsx_Time._timezone_frozen,
                zone_kept: Rsx_Time.get_user_timezone() === zone_before,
            };
        });
        check('a zone change updates the cookie', moved.changed === true && moved.cookie === 'Asia/Tokyo', moved);
        check('and disables the SPA, so the next link is a full page load', moved.spa === false, moved);
        check('and the open page keeps its zone against a later Ajax envelope', moved.frozen === true && moved.zone_kept === true, moved);

        check('no page errors', errors.length === 0, errors);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: timezone cookie - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
