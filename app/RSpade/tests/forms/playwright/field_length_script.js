#!/usr/bin/env node
/**
 * Model.field_length() answers from the page's field-length script.
 *
 * A model's column lengths are not in its generated stub: the page loads them in a script of
 * its own - <script src="/_field_lengths/<Bundle>__<hash>.js?v=<state key>" defer>, emitted
 * after the app bundle - which assigns Rsx.__field_length_data, and the ONE
 * Rsx_Js_Model.field_length() every model inherits reads it.
 *
 * Six cases:
 *
 *   a. the page carries exactly one such tag, after the app bundle's own;
 *   b. field_length() answers the number the served script carries for a varchar column;
 *   c. a column with no length, and a name that is no column at all, answer null;
 *   d. a class extending a model answers through it (the model name is inherited);
 *   e. with the data gone, field_length() throws naming the model and the script;
 *   f. with the data present but holding nothing for the model, it throws too.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * model is the framework's User_Model.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

function check(ok, what, detail) {
    if (ok) {
        console.log('PASS: ' + what);
    } else {
        console.log('FAIL: ' + what + (detail ? ' - ' + detail : ''));
        process.exitCode = 1;
    }
}

(async () => {
    const { browser, page, errors } = await open_sys_page();

    try {
        const result = await page.evaluate(async () => {
            const out = {};

            const sources = Array.from(document.scripts).map((script) => script.getAttribute('src') || '');
            const app_at = sources.findIndex((src) => /\/_compiled\/[A-Za-z0-9_]+__app\.[a-f0-9]+\.js/.test(src));
            const tags = sources.filter((src) => src.includes('/_field_lengths/'));

            out.tag_count = tags.length;
            out.tag = tags[0] || null;
            out.app_src = sources[app_at] || null;
            out.tag_after_app = app_at !== -1 && sources.indexOf(tags[0]) > app_at;
            out.tag_deferred = !!document.querySelector('script[src*="/_field_lengths/"][defer]');
            out.state_key = window.rsxapp.build_key;

            // b. the served script, read back, against what field_length() answers
            const response = await fetch(out.tag);
            out.status = response.status;
            out.cache_control = response.headers.get('Cache-Control');
            const body = await response.text();
            const served = JSON.parse(body.replace(/^Rsx\.__field_length_data = /, '').replace(/;\s*$/, ''));

            out.served_first_name = served.User_Model ? served.User_Model.first_name : undefined;
            out.first_name = User_Model.field_length('first_name');

            // c. no length, and no such column
            out.id = User_Model.field_length('id');
            out.no_such_column = User_Model.field_length('rsx_probe_no_such_column_temp');

            // d. a class extending the model
            class Rsx_Probe_User_Temp extends User_Model {}
            out.subclass = Rsx_Probe_User_Temp.field_length('first_name');

            // e. the data gone
            const held = Rsx.__field_length_data;
            Rsx.__field_length_data = null;
            try {
                User_Model.field_length('first_name');
                out.missing_data_error = null;
            } catch (e) {
                out.missing_data_error = e.message;
            }

            // f. the data there, the model not in it
            Rsx.__field_length_data = {};
            try {
                User_Model.field_length('first_name');
                out.missing_model_error = null;
            } catch (e) {
                out.missing_model_error = e.message;
            }

            Rsx.__field_length_data = held;
            out.restored = User_Model.field_length('first_name');

            return out;
        });

        const app_hash = (result.app_src || '').match(/__app\.([a-f0-9]+)\.js/);

        check(result.tag_count === 1, '(a) the page carries exactly one field-length tag', 'found ' + result.tag_count);
        check(result.tag_after_app && result.tag_deferred, '(a) it is deferred and follows the app bundle', result.tag);
        check(
            !!app_hash && (result.tag || '').includes('__' + app_hash[1] + '.js?v=' + result.state_key),
            '(a) it names the app bundle\'s own hash and carries the state key',
            result.tag + ' vs ' + result.app_src
        );
        check(
            result.status === 200 && /immutable/.test(result.cache_control || ''),
            '(b) the script is served, immutable for the key the page was given',
            result.status + ' ' + result.cache_control
        );
        check(
            typeof result.first_name === 'number' && result.first_name > 0 && result.first_name === result.served_first_name,
            '(b) field_length() answers what the served script carries',
            result.first_name + ' vs ' + result.served_first_name
        );
        check(result.id === null, '(c) a column with no length answers null', String(result.id));
        check(result.no_such_column === null, '(c) a name that is no column answers null', String(result.no_such_column));
        check(result.subclass === result.first_name, '(d) a class extending the model answers through it', String(result.subclass));
        check(
            !!result.missing_data_error && result.missing_data_error.includes('User_Model')
                && result.missing_data_error.includes('field-length script did not load'),
            '(e) with the data gone it throws, naming the model and the script',
            String(result.missing_data_error)
        );
        check(
            !!result.missing_model_error && result.missing_model_error.includes('User_Model')
                && result.missing_model_error.includes('/_field_lengths/'),
            '(f) with nothing for the model it throws, naming the model and the script',
            String(result.missing_model_error)
        );
        check(result.restored === result.first_name, 'the page is left as it was found');
        check(errors.length === 0, 'the page reported no error', errors.join(' | '));
    } catch (e) {
        check(false, 'the script ran to completion', e.message);
    } finally {
        await browser.close();
    }
})();
