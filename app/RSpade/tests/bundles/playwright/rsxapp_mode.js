#!/usr/bin/env node
/**
 * The application mode in the browser (BUNDLE-MODE-01, BUNDLE-MODE-02).
 *
 *   1. a development page carries window.rsxapp.mode = 'development', and Rsx.is_dev() is true
 *      while Rsx.is_debug() and Rsx.is_prod() are false
 *   2. the three helpers answer from rsxapp.mode alone: for each of 'development', 'debug' and
 *      'production' set in the page, is_dev() is true only in development, is_debug() only in
 *      debug, and is_prod() in debug and production
 *
 * Runs on the framework's own /_sys panel, so it needs nothing from the application tree. The
 * sealed modes are simulated by setting rsxapp.mode in the page, the one fact the helpers read.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: rsxapp mode - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const served = await page.evaluate(() => ({
            mode: window.rsxapp.mode,
            is_dev: Rsx.is_dev(),
            is_debug: Rsx.is_debug(),
            is_prod: Rsx.is_prod(),
        }));
        check('a development page carries rsxapp.mode = development', served.mode === 'development', served);
        check('development: is_dev() true, is_debug() false, is_prod() false',
            served.is_dev === true && served.is_debug === false && served.is_prod === false, served);

        const table = await page.evaluate(() => {
            const was_mode = window.rsxapp.mode;
            const out = {};
            for (const mode of ['development', 'debug', 'production']) {
                window.rsxapp.mode = mode;
                out[mode] = { is_dev: Rsx.is_dev(), is_debug: Rsx.is_debug(), is_prod: Rsx.is_prod() };
            }
            window.rsxapp.mode = was_mode;
            return out;
        });
        const expected = {
            development: { is_dev: true, is_debug: false, is_prod: false },
            debug: { is_dev: false, is_debug: true, is_prod: true },
            production: { is_dev: false, is_debug: false, is_prod: true },
        };
        for (const mode of Object.keys(expected)) {
            const got = table[mode];
            const want = expected[mode];
            check('mode ' + mode + ': is_dev()=' + want.is_dev + ', is_debug()=' + want.is_debug + ', is_prod()=' + want.is_prod,
                got.is_dev === want.is_dev && got.is_debug === want.is_debug && got.is_prod === want.is_prod, got);
        }

        check('the page reported no error', errors.length === 0, errors);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    fail(err.stack || String(err));
});
