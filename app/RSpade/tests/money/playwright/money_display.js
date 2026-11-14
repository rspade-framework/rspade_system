#!/usr/bin/env node
/**
 * Money_Display in a browser - three states that never collapse:
 *
 * - a value prints format(): `$1,234.50`, `$0.00` (zero is a value, not "nothing"), `-$5.00`,
 *   `($5.00)` under $negative="parentheses", and the negative modifier class only with
 *   $negative_red and a negative amount;
 * - null prints the em-dash, or the $empty text;
 * - $redacted prints "Hidden" and no amount, even when a value is passed;
 * - a bare number or string as $value is refused.
 *
 * Runs on the framework's own /_sys panel; each display is mounted there at run time.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

/** A refused $value throws through shouldnt_happen(), which reports to the console by design. */
function is_expected_guard_report(text) {
    return text.includes('Money_Display $value must be an Rsx_Money or null')
        || text.includes('SANITY CHECK FAILURE')
        || text.startsWith('=====')
        || text.startsWith('Stack trace:');
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const observed = await page.evaluate(async () => {
            const m = (d) => Rsx_Money.from_decimal(d);

            /** Mount one display; describe what it shows. Its tag is a span, so is the host. */
            const mount = async (args) => {
                const $host = $('<span>').appendTo('body');
                $host.component('Money_Display', args);
                await $host.component().ready();
                const shown = {
                    text: $host.text().trim(),
                    figure: $host.find('.Money_Display__figure').length,
                    negative: $host.find('.Money_Display__figure--negative').length,
                    empty: $host.find('.Money_Display__empty').length,
                    redacted: $host.find('.Money_Display__redacted').length,
                    root: $host.hasClass('Money_Display'),
                };
                $host.remove();
                return shown;
            };

            /**
             * Mount with a value that is not an amount. on_create() refuses it; jqhtml reports a
             * failed lifecycle hook through console.error, so the refusal is read from the page's
             * errors, and the display shows nothing.
             */
            const refused = async (value) => {
                const $host = $('<span>').appendTo('body');
                $host.component('Money_Display', { value });
                await sleep(0);
                const text = $host.text().trim();
                $host.remove();
                return text;
            };

            return {
                value: await mount({ value: m('1234.5') }),
                zero: await mount({ value: m('0') }),
                negative_sign: await mount({ value: m('-5') }),
                negative_parentheses: await mount({ value: m('-5'), negative: 'parentheses' }),
                negative_red: await mount({ value: m('-5'), negative_red: true }),
                positive_red: await mount({ value: m('5'), negative_red: true }),
                null_default: await mount({ value: null }),
                null_text: await mount({ value: null, empty: 'Not set' }),
                redacted: await mount({ redacted: true }),
                redacted_with_value: await mount({ redacted: true, value: m('99.99') }),
                bare_number: await refused(5),
                bare_string: await refused('5.00'),
            };
        });

        const o = observed;
        const checks = [
            ['a value prints format()', o.value.text === '$1,234.50' && o.value.figure === 1 && o.value.root, o.value],
            ['zero is a value, not nothing', o.zero.text === '$0.00' && o.zero.figure === 1 && o.zero.empty === 0, o.zero],
            ['a negative prints with the sign by default', o.negative_sign.text === '-$5.00' && o.negative_sign.negative === 0, o.negative_sign],
            ['$negative="parentheses" prints parentheses', o.negative_parentheses.text === '($5.00)', o.negative_parentheses],
            ['$negative_red marks a negative amount', o.negative_red.negative === 1, o.negative_red],
            ['$negative_red leaves a positive amount alone', o.positive_red.negative === 0 && o.positive_red.text === '$5.00', o.positive_red],
            ['null prints the em-dash', o.null_default.text === '—' && o.null_default.empty === 1 && o.null_default.figure === 0, o.null_default],
            ['null prints the $empty text when given', o.null_text.text === 'Not set' && o.null_text.empty === 1, o.null_text],
            ['redacted prints Hidden', o.redacted.text === 'Hidden' && o.redacted.redacted === 1 && o.redacted.figure === 0, o.redacted],
            ['redacted prints no amount even when given one', o.redacted_with_value.text === 'Hidden' && o.redacted_with_value.figure === 0, o.redacted_with_value],
            ['a bare number shows no amount', o.bare_number === '', o.bare_number],
            ['a bare string shows no amount', o.bare_string === '', o.bare_string],
            ['a bare number is refused, naming its type', errors.some((t) => t.includes('Money_Display $value must be an Rsx_Money or null, but was given number')), errors],
            ['a bare string is refused, naming its type', errors.some((t) => t.includes('Money_Display $value must be an Rsx_Money or null, but was given string')), errors],
        ];

        for (const [label, ok, detail] of checks) {
            if (ok) {
                console.log('PASS: money display - ' + label);
            } else {
                console.log('FAIL: money display - ' + label + ': ' + JSON.stringify(detail));
                process.exitCode = 1;
            }
        }

        const unexpected = errors.filter((text) => !is_expected_guard_report(text));
        if (unexpected.length) {
            console.log('FAIL: money display - page errors ' + JSON.stringify(unexpected));
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: money display - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
