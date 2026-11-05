#!/usr/bin/env node
/**
 * Blade-written fields - the autocomplete="off" default.
 *
 * jqhtml gives a template-written field with no autocomplete token autocomplete="off".
 * Rsx_Blade_Autocomplete applies the same policy at boot to the fields a Blade page wrote:
 * every <input> and <textarea> with no autocomplete attribute gets "off", and only an
 * autocomplete attribute exempts a field.
 *
 *   a. on a page that is not an SPA page: a token-less <input>, <textarea> and hidden
 *      <input> become "off"; a field declaring a token keeps it; a field that already says
 *      "off" stays "off"; an id and a name exempt nothing; <select> is not touched
 *   b. on an SPA page the class touches nothing
 *   c. no error was printed
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the
 * fields are written into it at run time, and window.rsxapp.is_spa is set for each case.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: blade autocomplete - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Write the probe fields, run the class with is_spa set, and report each field's token. */
async function probe(page, is_spa) {
    return page.evaluate((is_spa) => {
        $('#rsx_blade_autocomplete_temp').remove();
        $('body').append(
            '<div id="rsx_blade_autocomplete_temp">' +
                '<input class="plain" type="text">' +
                '<input class="with_id" type="text" id="rsx_blade_ac_probe_temp" name="probe">' +
                '<input class="hidden" type="hidden" name="code">' +
                '<textarea class="area"></textarea>' +
                '<input class="username" type="email" autocomplete="username">' +
                '<input class="password" type="password" autocomplete="current-password">' +
                '<input class="already_off" type="text" autocomplete="off">' +
                '<select class="choice"><option>a</option></select>' +
            '</div>'
        );

        const saved = window.rsxapp.is_spa;
        window.rsxapp.is_spa = is_spa;
        try {
            Rsx_Blade_Autocomplete._on_framework_core_init();
        } finally {
            window.rsxapp.is_spa = saved;
        }

        const $mount = $('#rsx_blade_autocomplete_temp');
        const out = {};
        for (const name of ['plain', 'with_id', 'hidden', 'area', 'username', 'password', 'already_off', 'choice']) {
            out[name] = $mount.find('.' + name).attr('autocomplete') ?? null;
        }
        $mount.remove();
        return out;
    }, is_spa);
}

async function main() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const blade = await probe(page, false);
        check('a token-less <input> becomes "off"', blade.plain === 'off', blade);
        check('an id and a name exempt nothing', blade.with_id === 'off', blade);
        check('a hidden <input> becomes "off"', blade.hidden === 'off', blade);
        check('a token-less <textarea> becomes "off"', blade.area === 'off', blade);
        check('a field declaring "username" keeps it', blade.username === 'username', blade);
        check('a field declaring "current-password" keeps it', blade.password === 'current-password', blade);
        check('a field already "off" stays "off"', blade.already_off === 'off', blade);
        check('a <select> is not touched', blade.choice === null, blade);

        const spa = await probe(page, true);
        check('on an SPA page a token-less <input> is not touched', spa.plain === null, spa);
        check('on an SPA page a token-less <textarea> is not touched', spa.area === null, spa);
        check('on an SPA page a declared token is kept', spa.username === 'username', spa);

        check('the page reported no error', errors.length === 0, errors);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    fail(err.stack || String(err));
});
