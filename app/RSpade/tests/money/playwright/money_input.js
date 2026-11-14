#!/usr/bin/env node
/**
 * Money_Input in a browser:
 *
 * - val() of a blank box (and a whitespace-only one) is null;
 * - typed amounts in the configured display - `(500.00)`, `-5`, `$1,234.56`, `1234.5` - give an
 *   Rsx_Money of the right amount, negatives included;
 * - text that does not parse - `12.345` (three places), `12,34` (bad grouping), `abc` - gives
 *   the typed STRING, unchanged;
 * - typing raises the form's 'input' notification;
 * - blur tidies a parseable entry to to_input_text() and leaves an unparseable one exactly as
 *   typed;
 * - val(Rsx_Money) shows the amount in the box, val(null) empties it, and val('') / val('5')
 *   are refused (ACCEPTS = 'Rsx_Money').
 *
 * Runs on the framework's own /_sys panel; the input is mounted there at run time and typed
 * into through Playwright's keyboard.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

/** A refused val() throws through shouldnt_happen(), which reports to the console by design. */
function is_expected_guard_report(text) {
    return text.includes('edits Rsx_Money values')
        || text.includes('SANITY CHECK FAILURE')
        || text.startsWith('=====')
        || text.startsWith('Stack trace:');
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    const checks = [];
    const check = (label, ok, detail) => checks.push([label, ok, detail]);

    try {
        await page.evaluate(async () => {
            const $host = $('<div id="money_input_probe">').appendTo('body');
            $host.component('Money_Input', { name: 'amount', placeholder: '0.00' });
            const input = $host.component();
            await input.ready();
            window.__money_input = input;
            window.__money_input_events = 0;
            input.on('input', () => { window.__money_input_events++; });
        });

        const field = page.locator('#money_input_probe input');

        /** Type `text` into the emptied box and read val() (described, not the object). */
        const typed = async (text) => {
            await field.fill('');
            await field.pressSequentially(text);
            return page.evaluate(() => {
                const v = window.__money_input.val();
                if (v instanceof Rsx_Money) {
                    return { kind: 'money', decimal: v.to_decimal() };
                }
                return { kind: v === null ? 'null' : typeof v, value: v };
            });
        };
        const box = () => field.inputValue();

        check('the placeholder is shown', (await field.getAttribute('placeholder')) === '0.00', await field.getAttribute('placeholder'));
        check('inputmode is decimal', (await field.getAttribute('inputmode')) === 'decimal', await field.getAttribute('inputmode'));

        let v = await page.evaluate(() => window.__money_input.val());
        check('an untouched box is null', v === null, v);

        await field.fill('   ');
        v = await page.evaluate(() => window.__money_input.val());
        check('a whitespace-only box is null', v === null, v);

        const amounts = [
            ['(500.00)', '-500.00'],
            ['-5', '-5.00'],
            ['$1,234.56', '1234.56'],
            ['1234.5', '1234.50'],
            ['0', '0.00'],
        ];
        for (const [text, decimal] of amounts) {
            v = await typed(text);
            check(`'${text}' is an Rsx_Money of ${decimal}`, v.kind === 'money' && v.decimal === decimal, v);
            check(`'${text}' is left as typed while typing`, (await box()) === text, await box());
        }

        for (const text of ['12.345', '12,34', 'abc']) {
            v = await typed(text);
            check(`'${text}' gives the typed string unchanged`, v.kind === 'string' && v.value === text, v);
        }

        const before = await page.evaluate(() => window.__money_input_events);
        await typed('7');
        const after = await page.evaluate(() => window.__money_input_events);
        check("typing raises the 'input' notification", after > before, { before, after });

        await typed('$1,234.5');
        await field.blur();
        check('blur tidies a parseable entry to to_input_text()', (await box()) === '1234.50', await box());
        v = await page.evaluate(() => window.__money_input.val().to_decimal());
        check('the tidied box holds the same amount', v === '1234.50', v);

        await typed('(5)');
        await field.blur();
        check('blur tidies a negative to its signed form', (await box()) === '-5.00', await box());

        await typed('12.345');
        await field.blur();
        check('blur leaves an unparseable entry exactly as typed', (await box()) === '12.345', await box());

        const set = await page.evaluate(() => {
            const input = window.__money_input;
            const outcome = (fn) => {
                try {
                    fn();
                    return 'accepted';
                } catch (e) {
                    return 'refused: ' + e.message;
                }
            };
            const result = {};
            result.money = outcome(() => input.val(Rsx_Money.from_decimal('-99.9')));
            result.money_box = input.$sid('field').val();
            result.money_val = input.val().to_decimal();
            result.changed_after_set = input.is_changed();
            result.null = outcome(() => input.val(null));
            result.null_box = input.$sid('field').val();
            result.null_val = input.val();
            result.blank_string = outcome(() => input.val(''));
            result.numeric_string = outcome(() => input.val('5'));
            return result;
        });
        check('val(Rsx_Money) is accepted', set.money === 'accepted', set.money);
        check('val(Rsx_Money) shows the amount in the box', set.money_box === '-99.90', set.money_box);
        check('val(Rsx_Money) reads back the same amount', set.money_val === '-99.90', set.money_val);
        check('a programmatic val() is not a user change', set.changed_after_set === false, set.changed_after_set);
        check('val(null) is accepted and empties the box', set.null === 'accepted' && set.null_box === '' && set.null_val === null, set);
        check("val('') is refused", set.blank_string.startsWith('refused') && set.blank_string.includes('edits Rsx_Money values'), set.blank_string);
        check("val('5') is refused", set.numeric_string.startsWith('refused') && set.numeric_string.includes('edits Rsx_Money values'), set.numeric_string);

        for (const [label, ok, detail] of checks) {
            if (ok) {
                console.log('PASS: money input - ' + label);
            } else {
                console.log('FAIL: money input - ' + label + ': ' + JSON.stringify(detail));
                process.exitCode = 1;
            }
        }

        const unexpected = errors.filter((text) => !is_expected_guard_report(text));
        if (unexpected.length) {
            console.log('FAIL: money input - page errors ' + JSON.stringify(unexpected));
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: money input - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
