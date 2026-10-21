#!/usr/bin/env node
/**
 * Form error messages are PLAIN TEXT: markup in one is shown literally, never rendered.
 *
 * Form_Utils.apply_form_errors() is the one renderer every form failure reaches (Rsx_Form,
 * Modal.form, a bare-markup page). Its three shapes are each driven with a message carrying
 * markup and an <img onerror> payload:
 *
 *   a. a field error, pinned under its input
 *   b. an unmatched field error, listed in the top alert
 *   c. an array of general messages, listed in the top alert
 *
 * and Rsx.render_error() - the generic Ajax error box - with a fatal error whose file name and
 * message carry markup. In every case the element's TEXT is the message verbatim, no <b> or
 * <img> element exists inside it, and the payload's handler never ran.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys and the
 * markup is built at runtime.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const NAME = 'Form error messages render as text';

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const result = await page.evaluate(async () => {
            const payload = '<b>bold</b><img src="data:," onerror="window.__form_error_fired = 1">';
            const out = {};

            const $host = $('<div><div class="form-group"><input name="field_a"></div><div class="Form_Errors"></div></div>')
                .appendTo('body');

            await Form_Utils.apply_form_errors($host, { field_a: 'A ' + payload, field_missing: 'M ' + payload });

            const $feedback = $host.find('.invalid-feedback');
            out.field_text = $feedback.text();
            out.field_elements = $feedback.find('b, img').length;

            const $alert_li = $host.find('.Form_Errors li');
            out.unmatched_text = $alert_li.text();
            out.unmatched_elements = $host.find('.Form_Errors b, .Form_Errors img').length;

            $host.find('.Form_Errors').empty();
            await Form_Utils.apply_form_errors($host, ['G ' + payload]);
            out.general_text = $host.find('.Form_Errors li').text();
            out.general_elements = $host.find('.Form_Errors b, .Form_Errors img').length;

            const $box = $('<div></div>').appendTo('body');
            Rsx.render_error({ type: 'fatal', details: { file: '<i>f</i>', line: 7, error: 'E ' + payload } }, $box);
            out.render_text = $box.text();
            out.render_elements = $box.find('b, i, img').length;

            // Give an onerror the chance to run had any <img> been created.
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            out.fired = window.__form_error_fired === 1;
            out.payload = payload;

            return out;
        });

        // apply_form_errors() logs every error set it renders to the console; those are expected.
        const unexpected = errors.filter((message) => !message.includes('<b>bold</b>'));

        const checks = [
            ['field error text is the message verbatim', result.field_text === 'A ' + result.payload],
            ['field error holds no elements', result.field_elements === 0],
            ['unmatched error text is the message verbatim', result.unmatched_text === 'M ' + result.payload],
            ['unmatched error holds no elements', result.unmatched_elements === 0],
            ['general error text is the message verbatim', result.general_text === 'G ' + result.payload],
            ['general error holds no elements', result.general_elements === 0],
            ['render_error shows the file and message as text', result.render_text.includes('<i>f</i>:7') && result.render_text.includes('E ' + result.payload)],
            ['render_error holds no markup from the error', result.render_elements === 0],
            ['no onerror payload ran', result.fired === false],
            ['no unexpected page errors', unexpected.length === 0],
        ];

        for (const [label, ok] of checks) {
            if (ok) {
                console.log('PASS: ' + NAME + ' - ' + label);
            } else {
                console.log('FAIL: ' + NAME + ' - ' + label);
                process.exitCode = 1;
            }
        }

        if (unexpected.length) {
            console.log('  page errors: ' + JSON.stringify(unexpected));
        }
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: ' + NAME + ' - ' + e.message);
    process.exit(1);
});
