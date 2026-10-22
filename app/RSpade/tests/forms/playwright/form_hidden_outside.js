#!/usr/bin/env node
/**
 * An Rsx_Form owns the native hidden inputs associated with it by id, wherever they sit.
 *
 * <Rsx_Form> renders a real <form>, so a hidden input carrying form="<the form's id>" belongs
 * to it by the browser's own association - the shape <Turnstile_Input $form="..."> renders when
 * the widget is placed outside its form. vals() must collect:
 *
 *   a. a hidden input inside the form
 *   b. a hidden input outside the form naming its id
 *
 * and must NOT collect:
 *
 *   c. a hidden input outside the form naming no form
 *   d. a hidden input INSIDE the form that names another form
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys, the form is
 * built at runtime, and nothing is submitted.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const NAME = 'Rsx_Form collects hidden inputs associated by id';

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const values = await page.evaluate(async () => {
            const $el = $('<div>').appendTo('body').component('Rsx_Form', {
                controller: 'Rsx_Two_Factor_Controller',
                method: 'challenge_state',
            });
            const form = $el.component();
            await form.ready();

            form.$.attr('id', 'pw-hidden-outside-form');
            form.$.append('<input type="hidden" name="inside" value="a">');
            form.$.append('<input type="hidden" name="names_another" value="d" form="pw-some-other-form">');
            $('body').append('<input type="hidden" name="outside" value="b" form="pw-hidden-outside-form">');
            $('body').append('<input type="hidden" name="unrelated" value="c">');

            return form.vals();
        });

        const checks = [
            ['a hidden input inside the form is collected', values.inside === 'a'],
            ['a hidden input outside the form naming its id is collected', values.outside === 'b'],
            ['a hidden input outside the form naming no form is not', !('unrelated' in values)],
            ['a hidden input inside the form naming another form is not', !('names_another' in values)],
            ['no page errors', errors.length === 0],
        ];

        for (const [label, ok] of checks) {
            if (ok) {
                console.log('PASS: ' + NAME + ' - ' + label);
            } else {
                console.log('FAIL: ' + NAME + ' - ' + label);
                process.exitCode = 1;
            }
        }

        if (errors.length) {
            console.log('  page errors: ' + JSON.stringify(errors));
        }
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: ' + NAME + ' - ' + e.message);
    process.exit(1);
});
