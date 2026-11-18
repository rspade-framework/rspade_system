#!/usr/bin/env node
/**
 * The input value contract (Form_Input_Abstract) and the framework's own inputs.
 *
 * Every input owns ONE named value behind val(): _set_value() then _get_value() round-trips
 * it, a value set before the input is ready is buffered and applied by _mark_ready(), a
 * programmatic write announces 'val' only, and a USER edit announces 'input' then 'val'
 * (_notify_input) and makes the input changed. Rsx_Form.vals() collects every input by its
 * $name and applies an object back by name. Three parts:
 *
 *   A. THE BASE CLASS, on a fixture input registered in the browser: a subclass of the
 *      panel's text input whose on_render() wires the user event but does NOT call
 *      _mark_ready(), so the "not ready yet" window is real rather than simulated.
 *        - val(v) before ready answers v from the getter - null, 0, false and '' included;
 *        - nothing is announced while the value is pending;
 *        - _mark_ready() writes the pending value and announces 'val' once with it;
 *        - _mark_ready() with nothing pending announces nothing;
 *        - after ready, val(v) announces 'val' and never 'input', and is not a change;
 *        - typing announces 'input' then 'val' with the typed value, and is a change.
 *   B. EACH FRAMEWORK INPUT, mounted on its own: the round trip in that input's vocabulary
 *      (as its docblock states) and a real user edit through the mouse or keyboard.
 *        Password_Input (Core/Forms), and the control panel's _Sys_Text_Input,
 *        _Sys_Textarea_Input, _Sys_Select_Input, _Sys_Checkbox_Input and
 *        _Sys_Checkbox_List_Input (Sys/theme/components).
 *   C. Rsx_Form.vals(): every input inside a runtime-mounted form is collected by name, an
 *      applied object reaches each named input and leaves the others alone, form.input(name)
 *      answers the component, and a user edit makes the form dirty.
 *
 * The template application's inputs (Select_Input, Checkbox_Input, Tag_List_Input,
 * Wysiwyg_Input, Text_Input, ...) live in rsx/theme and are not the framework's: they are
 * not tested here. Code_Input is code_input.js, Money_Input is the money concern's.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and every
 * component is mounted at run time in the browser. The form is never submitted.
 *
 * Self-contained: runs with a bare `node form_input_value_contract.js`.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const MOUNT = '#form_input_contract_temp';

function fail(msg) {
    console.log('FAIL: form input value contract - ' + msg);
    process.exitCode = 1;
}

function check(label, actual, expected) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) {
        console.log('PASS: ' + label);
        return true;
    }
    fail(label + ' - expected ' + e + ', got ' + a);
    return false;
}

/**
 * Mount one input component on its own and record every 'input' and 'val' it announces on
 * window.__events[key], in order. Returns once the component is ready.
 */
async function mount(page, key, component, args) {
    await page.evaluate(async ({ mount_sel, key, component, args }) => {
        window.__inputs = window.__inputs || {};
        window.__events = window.__events || {};
        // Mounting replaces the host element, so the component is found again by the
        // attribute it carries over.
        $('<div>').attr('data-probe', key).appendTo(mount_sel).component(component, args);
        const input = $(mount_sel).find('[data-probe="' + key + '"]').component();
        await input.ready();
        window.__inputs[key] = input;
        window.__events[key] = [];
        input.on('input', (c, value) => window.__events[key].push(['input', value]));
        input.on('val', (c, value) => window.__events[key].push(['val', value]));
    }, { mount_sel: MOUNT, key, component, args });
}

/** Write each value with val(v) and read it back with val(). */
async function round_trip(page, key, values) {
    return await page.evaluate(({ key, values }) => values.map((v) => {
        window.__inputs[key].val(v);
        return window.__inputs[key].val();
    }), { key, values });
}

async function events(page, key) {
    return await page.evaluate((key) => {
        const list = window.__events[key].slice();
        window.__events[key].length = 0;
        return list;
    }, key);
}

async function changed(page, key) {
    return await page.evaluate((key) => window.__inputs[key].is_changed(), key);
}

function sel(key, inner) {
    return MOUNT + ' [data-probe="' + key + '"] ' + inner;
}

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        await page.evaluate(async (mount_sel) => {
            await Spa.action().ready();
            $(mount_sel).remove();
            $('body').prepend('<div id="' + mount_sel.slice(1) + '"></div>');

            // The fixture: the panel text input's template and value methods, with readiness
            // left to the test.
            class Form_Input_Contract_Probe_Input extends _Sys_Text_Input {
                on_render() {
                    const that = this;
                    this.$sid('input').on('input', function () {
                        that._notify_input(that._get_value());
                    });
                }
            }
            jqhtml.register_component('Form_Input_Contract_Probe_Input', Form_Input_Contract_Probe_Input);
        }, MOUNT);

        // =================================================================================
        // A. the base class
        // =================================================================================
        await mount(page, 'pending', 'Form_Input_Contract_Probe_Input', { name: 'pending', max_length: -1 });
        const pending = await page.evaluate(() => {
            const input = window.__inputs.pending;
            const out = { ready: input._is_ready, answers: [] };
            for (const v of ['text', null, 0, false, '']) {
                input.val(v);
                out.answers.push(input.val());
            }
            input.val('buffered');
            out.before_dom = input.$sid('input').val();
            out.before = input.val();
            return out;
        });
        check('A the fixture input is not ready after rendering', pending.ready, false);
        check('A val(v) before ready answers v, for null, 0, false and "" too',
            pending.answers, ['text', null, 0, false, '']);
        check('A the pending value is not written yet', pending.before_dom, '');
        check('A val() answers the pending value', pending.before, 'buffered');
        check('A nothing is announced while pending', await events(page, 'pending'), []);

        const landed = await page.evaluate(() => {
            const input = window.__inputs.pending;
            input._mark_ready();
            return { dom: input.$sid('input').val(), value: input.val(), changed: input.is_changed() };
        });
        check('A _mark_ready() writes the pending value', landed.dom, 'buffered');
        check('A and val() reads it through _get_value()', landed.value, 'buffered');
        check('A _mark_ready() announces val once with the value', await events(page, 'pending'), [['val', 'buffered']]);
        check('A the applied value is the unchanged state', landed.changed, false);

        await mount(page, 'idle', 'Form_Input_Contract_Probe_Input', { name: 'idle', max_length: -1 });
        await page.evaluate(() => window.__inputs.idle._mark_ready());
        check('A _mark_ready() with nothing pending announces nothing', await events(page, 'idle'), []);

        await page.evaluate(() => window.__inputs.pending.val('programmatic'));
        check('A after ready, val(v) announces val only', await events(page, 'pending'), [['val', 'programmatic']]);
        check('A a programmatic write is not a change', await changed(page, 'pending'), false);

        await page.click(sel('pending', 'input'));
        await page.keyboard.press('End');
        await page.keyboard.type('!');
        check('A typing announces input then val with the typed value',
            await events(page, 'pending'), [['input', 'programmatic!'], ['val', 'programmatic!']]);
        check('A a user edit is a change', await changed(page, 'pending'), true);

        // =================================================================================
        // B. each framework input
        // =================================================================================

        // --- Password_Input ---------------------------------------------------------------
        await mount(page, 'password', 'Password_Input', { name: 'password' });
        check('B Password_Input round-trips; null is "", a number is its string',
            await round_trip(page, 'password', ['s3cret', null, 42, '']), ['s3cret', '', '42', '']);
        await events(page, 'password');
        await page.click(sel('password', 'input'));
        await page.keyboard.type('pw');
        check('B Password_Input: typing announces input then val per keystroke',
            await events(page, 'password'), [['input', 'p'], ['val', 'p'], ['input', 'pw'], ['val', 'pw']]);
        check('B Password_Input: a user edit is a change', await changed(page, 'password'), true);

        // --- _Sys_Text_Input ----------------------------------------------------------------
        await mount(page, 'text', '_Sys_Text_Input', { name: 'text', max_length: -1 });
        check('B _Sys_Text_Input round-trips; null and undefined are ""',
            await round_trip(page, 'text', ['hello', null, undefined, '']), ['hello', '', '', '']);
        await events(page, 'text');
        await page.click(sel('text', 'input'));
        await page.keyboard.type('a');
        check('B _Sys_Text_Input: typing announces input then val', await events(page, 'text'), [['input', 'a'], ['val', 'a']]);
        check('B _Sys_Text_Input: a user edit is a change', await changed(page, 'text'), true);

        // --- _Sys_Textarea_Input -------------------------------------------------------------
        await mount(page, 'textarea', '_Sys_Textarea_Input', { name: 'textarea', max_length: -1 });
        check('B _Sys_Textarea_Input round-trips multi-line text; null is ""',
            await round_trip(page, 'textarea', ['line 1\nline 2', null]), ['line 1\nline 2', '']);
        await events(page, 'textarea');
        await page.click(sel('textarea', 'textarea'));
        await page.keyboard.type('x');
        await page.keyboard.press('Enter');
        check('B _Sys_Textarea_Input: Enter is a newline the user typed',
            await events(page, 'textarea'), [['input', 'x'], ['val', 'x'], ['input', 'x\n'], ['val', 'x\n']]);

        // --- _Sys_Select_Input ----------------------------------------------------------------
        await mount(page, 'select', '_Sys_Select_Input', {
            name: 'select',
            placeholder: 'Pick one',
            options: [{ value: 1, label: 'One' }, 'two', { value: 'three', label: 'Three' }],
        });
        check('B _Sys_Select_Input: untouched it answers the placeholder\'s ""',
            await page.evaluate(() => window.__inputs.select.val()), '');
        check('B _Sys_Select_Input round-trips as strings; null and a value matching no option are ""',
            await round_trip(page, 'select', [1, '1', 'two', null, 'no such option', 'three']),
            ['1', '1', 'two', '', '', 'three']);
        await events(page, 'select');
        await page.selectOption(sel('select', 'select'), 'two');
        check('B _Sys_Select_Input: choosing an option announces input then val',
            await events(page, 'select'), [['input', 'two'], ['val', 'two']]);
        check('B _Sys_Select_Input: a user choice is a change', await changed(page, 'select'), true);

        // --- _Sys_Checkbox_Input -----------------------------------------------------------------
        await mount(page, 'checkbox', '_Sys_Checkbox_Input', { name: 'checkbox', label: 'Probe' });
        check('B _Sys_Checkbox_Input: true, 1 and "1" check it; anything else clears it',
            await round_trip(page, 'checkbox', [true, false, 1, 0, '1', '0', null]), ['1', '0', '1', '0', '1', '0', '0']);
        await mount(page, 'checkbox_custom', '_Sys_Checkbox_Input', {
            name: 'checkbox_custom', checked_value: 'yes', unchecked_value: 'no',
        });
        check('B _Sys_Checkbox_Input with its own values answers them, and its checked value checks it',
            await round_trip(page, 'checkbox_custom', ['yes', 'no', true]), ['yes', 'no', 'yes']);
        await page.evaluate(() => window.__inputs.checkbox.val(false));
        await events(page, 'checkbox');
        await page.click(sel('checkbox', 'label'));
        check('B _Sys_Checkbox_Input: clicking its label announces input then val',
            await events(page, 'checkbox'), [['input', '1'], ['val', '1']]);
        check('B _Sys_Checkbox_Input: a user click is a change', await changed(page, 'checkbox'), true);

        // --- _Sys_Checkbox_List_Input --------------------------------------------------------------
        await mount(page, 'list', '_Sys_Checkbox_List_Input', {
            name: 'list',
            options: ['A', { value: 'B', label: 'Bee', hint: 'second' }, 'C'],
        });
        check('B _Sys_Checkbox_List_Input: values come back in option order, as strings; [] when none; unknown ignored',
            await round_trip(page, 'list', [['C', 'A'], [], ['Z'], ['B', 'Z'], null]),
            [['A', 'C'], [], [], ['B'], []]);
        await page.evaluate(() => window.__inputs.list.val(['A']));
        await events(page, 'list');
        await page.click(sel('list', 'input[value="C"]'));
        check('B _Sys_Checkbox_List_Input: checking a box announces input then val with the list',
            await events(page, 'list'), [['input', ['A', 'C']], ['val', ['A', 'C']]]);
        await page.click(sel('list', '[data-action="all"]'));
        check('B _Sys_Checkbox_List_Input: All checks every box, as a user edit',
            await events(page, 'list'), [['input', ['A', 'B', 'C']], ['val', ['A', 'B', 'C']]]);
        await page.click(sel('list', '[data-action="none"]'));
        check('B _Sys_Checkbox_List_Input: None clears every box, as a user edit',
            await events(page, 'list'), [['input', []], ['val', []]]);
        check('B _Sys_Checkbox_List_Input: a user edit is a change', await changed(page, 'list'), true);

        // =================================================================================
        // C. Rsx_Form.vals()
        // =================================================================================
        const form = await page.evaluate(async (mount_sel) => {
            // Named so no real endpoint matches; the form is never submitted.
            $('<div class="form_input_contract_form">').appendTo(mount_sel)
                .component('Rsx_Form', { controller: 'Form_Input_Contract_Probe_Controller', method: 'save' });
            const form = $(mount_sel).find('.form_input_contract_form').component();
            await form.ready();
            const $content = form.$sid('form_content');
            const add = async (component, args) => {
                $('<div>').attr('data-probe-form', args.name).appendTo($content).component(component, args);
                await $content.find('[data-probe-form="' + args.name + '"]').component().ready();
            };
            await add('_Sys_Text_Input', { name: 'title', max_length: -1 });
            await add('_Sys_Select_Input', { name: 'status', options: ['open', 'closed'] });
            await add('_Sys_Checkbox_Input', { name: 'urgent' });
            await add('_Sys_Checkbox_List_Input', { name: 'tags', options: ['x', 'y', 'z'] });
            await add('Password_Input', { name: 'secret' });
            $content.append('<input type="text" class="form_input_contract_plain" value="not an input component">');
            window.__form = form;

            const out = {};
            out.initial = form.vals();
            out.applied_returns = form.vals({ title: 'T', status: 'closed', urgent: true, tags: ['z', 'x'], unknown_key: 'ignored' });
            out.applied = form.vals();
            form.vals({ status: 'open' });
            out.partial = form.vals();
            out.input_name = form.input('tags') === $content.find('[data-name="tags"]').component();
            out.input_missing = form.input('nope');
            out.dirty_before = form.dirty();
            return out;
        }, MOUNT);
        check('C vals() collects every input by its $name, in its own vocabulary', form.initial,
            { title: '', status: 'open', urgent: '0', tags: [], secret: '' });
        check('C vals(object) returns null', form.applied_returns, null);
        check('C vals(object) writes each named input; an unknown key is ignored', form.applied,
            { title: 'T', status: 'closed', urgent: '1', tags: ['x', 'z'], secret: '' });
        check('C vals(object) leaves inputs it does not name alone', form.partial,
            { title: 'T', status: 'open', urgent: '1', tags: ['x', 'z'], secret: '' });
        check('C form.input(name) answers that input component', form.input_name, true);
        check('C form.input(name) with no such input is null', form.input_missing, null);
        check('C applied values leave the form clean', form.dirty_before, false);

        await page.click(MOUNT + ' .form_input_contract_form [data-name="tags"] input[value="y"]');
        const after = await page.evaluate(() => ({ dirty: window.__form.dirty(), tags: window.__form.vals().tags }));
        check('C a user edit inside the form makes it dirty', after.dirty, true);
        check('C and vals() reports the edited value', after.tags, ['x', 'y', 'z']);

        await page.evaluate((mount_sel) => $(mount_sel).remove(), MOUNT);
        check('no page errors', errors, []);
    } catch (e) {
        fail('exception: ' + (e && e.message ? e.message : String(e)));
    } finally {
        await browser.close();
    }

    if (process.exitCode === 1) {
        console.log('FAIL: form input value contract');
    } else {
        console.log('PASS: form input value contract');
    }
}

run();
