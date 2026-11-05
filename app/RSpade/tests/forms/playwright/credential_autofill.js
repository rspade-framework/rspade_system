#!/usr/bin/env node
/**
 * Credential fields and autofill - the rendered-field contract.
 *
 * A password manager that remembers a field by its id needs an id that is the same on every
 * render and that works unescaped in '#' + id. jqhtml's $autofill_id gives a $sid field exactly
 * that id (and the same name); the framework's credential inputs take it from the caller and
 * forward it to their inner field. The framework also turns on jqhtml's autocomplete_off, so a
 * template-written field that declares no token renders autocomplete="off".
 *
 *   a. the integration configured autocomplete_off; a field with no token renders "off"
 *   b. <Password_Input $autofill_id="password">: id and name are the value, the token is
 *      present, '#password' finds exactly that field, $sid('field') resolves it, val() writes
 *      and reads it, typing reaches the 'input' event, and a re-render keeps all of it
 *   c. <Code_Input $autofill_id="code">: the first box carries id/name "code" and
 *      one-time-code, $sid('first_box') resolves it, val() and typing still work
 *   d. without $autofill_id the field keeps its scoped <sid>:<cid> id
 *   e. each of the above holds again with jqhtml configured as a production page configures it
 *   f. no jqhtml autofill warning and no error was printed
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * widgets are mounted at runtime.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function fail(msg) {
    console.log('FAIL: credential autofill - ' + msg);
    process.exitCode = 1;
}

function check(label, ok, detail) {
    if (ok) {
        console.log('PASS: ' + label);
    } else {
        fail(label + ' - got ' + JSON.stringify(detail));
    }
}

/** Mount the three probes in a fresh container and describe what rendered. */
async function probe(page) {
    return page.evaluate(async () => {
        $('#rsx_autofill_mount_temp').remove();
        $('body').append('<div id="rsx_autofill_mount_temp"><div class="pw"></div><div class="code"></div><div class="plain"></div><div class="bare"></div></div>');
        const $mount = $('#rsx_autofill_mount_temp');

        const pw = $mount.find('.pw').component('Password_Input', { name: 'password', autofill_id: 'password' }).component();
        const code = $mount.find('.code').component('Code_Input', { name: 'code', autofill_id: 'code' }).component();
        const plain = $mount.find('.plain').component('Password_Input', { name: 'other' }).component();
        const bare = $mount.find('.bare').component('_Sys_Text_Input', { name: 'note', max_length: -1 }).component();
        await Promise.all([pw.ready(), code.ready(), plain.ready(), bare.ready()]);

        const describe = (el) => ({
            id: el.id,
            name: el.getAttribute('name'),
            autocomplete: el.getAttribute('autocomplete'),
        });

        const out = {};

        // b. Password_Input with an explicit id
        const pw_el = pw.$.find('input')[0];
        out.pw = describe(pw_el);
        out.pw_matches = document.querySelectorAll('#password').length;
        out.pw_selector_is_field = document.querySelector('#password') === pw_el;
        out.pw_sid_resolves = pw.$sid('field')[0] === pw_el;
        pw.val('s3cret');
        out.pw_val_written = pw_el.value;
        pw_el.value = 'typed';
        out.pw_val_read = pw.val();
        out.pw_events = [];
        pw.on('input', (c, value) => out.pw_events.push(value));
        pw_el.value = 'typed2';
        pw_el.dispatchEvent(new Event('input', { bubbles: true }));
        pw.render();
        await pw.ready();
        const pw_el_after = pw.$.find('input')[0];
        out.pw_after_render = describe(pw_el_after);
        out.pw_sid_after_render = pw.$sid('field')[0] === pw_el_after;

        // c. Code_Input with an explicit id on its first box
        const boxes = code.$.find('.Code_Input__box');
        out.code_first = describe(boxes[0]);
        out.code_second = describe(boxes[1]);
        out.code_matches = document.querySelectorAll('#code').length;
        out.code_sid_resolves = code.$sid('first_box')[0] === boxes[0];
        code.val('123456');
        out.code_val = code.val();
        out.code_complete = code.is_complete();

        // d. no explicit id: the scoped id
        out.plain = describe(plain.$.find('input')[0]);

        // a. a template-written field that declares no token
        out.bare = describe(bare.$.find('input')[0]);

        return out;
    });
}

function assert_probe(label, got) {
    check(label + ': Password_Input id and name are the $autofill_id', got.pw.id === 'password' && got.pw.name === 'password', got.pw);
    check(label + ': Password_Input keeps its autocomplete token', got.pw.autocomplete === 'current-password', got.pw);
    check(label + ': \'#password\' finds exactly the field', got.pw_matches === 1 && got.pw_selector_is_field, [got.pw_matches, got.pw_selector_is_field]);
    check(label + ': $sid(\'field\') resolves the explicit-id field', got.pw_sid_resolves, got.pw_sid_resolves);
    check(label + ': val() writes and reads the field', got.pw_val_written === 's3cret' && got.pw_val_read === 'typed', [got.pw_val_written, got.pw_val_read]);
    check(label + ': typing reaches the input event', got.pw_events.length === 1 && got.pw_events[0] === 'typed2', got.pw_events);
    check(label + ': a re-render keeps the id and $sid() still resolves it',
        got.pw_after_render.id === 'password' && got.pw_sid_after_render, [got.pw_after_render, got.pw_sid_after_render]);
    check(label + ': Code_Input\'s first box is "code", one-time-code',
        got.code_first.id === 'code' && got.code_first.name === 'code' && got.code_first.autocomplete === 'one-time-code', got.code_first);
    check(label + ': Code_Input\'s other boxes are off and carry no id',
        got.code_second.autocomplete === 'off' && got.code_second.id === '' && got.code_matches === 1, [got.code_second, got.code_matches]);
    check(label + ': $sid(\'first_box\') resolves it and val() spans the boxes',
        got.code_sid_resolves && got.code_val === '123456' && got.code_complete, [got.code_sid_resolves, got.code_val, got.code_complete]);
    check(label + ': without $autofill_id the id is the scoped <sid>:<cid>', /^field:[a-z0-9]+$/.test(got.plain.id), got.plain);
    check(label + ': a field declaring no token renders autocomplete="off"', got.bare.autocomplete === 'off', got.bare);
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    const warnings = [];
    page.on('console', (msg) => {
        if (msg.type() === 'warning' && /autofill|autocomplete/i.test(msg.text())) {
            warnings.push(msg.text());
        }
    });

    try {
        const config = await page.evaluate(() => {
            const c = jqhtml.get_config();
            return { mode: c.mode, autocomplete_off: c.autocomplete_off };
        });
        check('the integration turned autocomplete_off on', config.autocomplete_off === true, config);

        assert_probe('development', await probe(page));

        // The integration's production call, made in the page: mode resets every flag to its
        // defaults, and the explicit autocomplete_off in the same call overrides that.
        await page.evaluate(() => jqhtml.configure({ mode: 'production', autocomplete_off: true }));
        assert_probe('production', await probe(page));

        check('no jqhtml autofill warning was printed', warnings.length === 0, warnings);
        check('the page reported no error', errors.length === 0, errors);
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    fail(err.stack || String(err));
});
