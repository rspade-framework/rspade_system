#!/usr/bin/env node
/**
 * A flash message is PLAIN TEXT: markup in one is shown literally, never rendered.
 *
 * Flash_Alert writes the message into its alert with .text(). A message carrying a <b> and an
 * <img onerror> payload is queued; once its alert is on the page, the alert's text holds the
 * message verbatim, no <b> or <img> element exists inside it, and the payload never ran.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const NAME = 'Flash messages render as text';
const PAYLOAD = 'Saved <b>bold</b><img src="data:," onerror="window.__flash_fired = 1">';

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        await page.evaluate((message) => Flash_Alert.success(message), PAYLOAD);

        // The queue shows the alert on its own schedule; wait for it to be on the page.
        await page.waitForFunction(
            (message) => $('.alert-wrapper .alert').toArray().some((el) => $(el).text().includes(message)),
            PAYLOAD,
            { timeout: 0 }
        );

        const result = await page.evaluate((message) => {
            const $alert = $('.alert-wrapper .alert').filter((index, el) => $(el).text().includes(message)).first();
            return {
                text: $alert.text().trim(),
                elements: $alert.find('b, img').length,
                fired: window.__flash_fired === 1,
            };
        }, PAYLOAD);

        const checks = [
            ['the alert text is the message verbatim', result.text === PAYLOAD],
            ['the alert holds no element from the message', result.elements === 0],
            ['no onerror payload ran', result.fired === false],
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
