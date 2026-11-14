#!/usr/bin/env node
/**
 * The browser half of the money column kind:
 *
 * - Rsx_Js_Model._instantiate_models_recursive() - what every Ajax response and model fetch
 *   passes through - turns a {__MONEY: "1.00"} envelope into an Rsx_Money at any depth (inside
 *   a record's fields, inside a list), leaves null as null, and leaves an object with any key
 *   beside __MONEY a plain object.
 * - Form_Input_Abstract._assert_accepts(): an input with no ACCEPTS refuses an Rsx_Money on
 *   val() ("That column is a money column - use <Money_Input>."); an input whose ACCEPTS is
 *   'Rsx_Money' takes an Rsx_Money or null and refuses a string.
 *
 * Runs on the framework's own /_sys panel; the probe inputs are registered at run time.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

/** A refused value throws through shouldnt_happen(), which reports to the console by design. */
function is_expected_guard_report(text) {
    return text.includes('edits plain strings, but was given an Rsx_Money')
        || text.includes('edits Rsx_Money values')
        || text.includes('SANITY CHECK FAILURE')
        || text.startsWith('=====')
        || text.startsWith('Stack trace:');
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const observed = await page.evaluate(async () => {
            const data = Rsx_Js_Model._instantiate_models_recursive({
                record: { name: 'x', budget: { __MONEY: '1234.50' }, spent: null },
                totals: [{ __MONEY: '-0.05' }, { __MONEY: '0.00' }],
                not_an_envelope: { __MONEY: '1.00', other: 1 },
            });

            const rehydrated = {
                budget_is_money: data.record.budget instanceof Rsx_Money,
                budget: data.record.budget instanceof Rsx_Money ? data.record.budget.to_decimal() : null,
                null_stays_null: data.record.spent === null,
                list: data.totals.map((t) => (t instanceof Rsx_Money ? t.to_decimal() : 'not money')),
                extra_key_is_plain: !(data.not_an_envelope instanceof Rsx_Money) && data.not_an_envelope.other === 1,
            };

            class Rsx_Money_Plain_Probe_Input_Temp extends Form_Input_Abstract {
                _get_value() { return this._v ?? null; }
                _set_value(v) { this._v = v; }
                on_render() { this._mark_ready(); }
            }
            class Rsx_Money_Typed_Probe_Input_Temp extends Form_Input_Abstract {
                static ACCEPTS = 'Rsx_Money';
                _get_value() { return this._v ?? null; }
                _set_value(v) { this._v = v; }
                on_render() { this._mark_ready(); }
            }
            jqhtml.register_component('Rsx_Money_Plain_Probe_Input_Temp', Rsx_Money_Plain_Probe_Input_Temp);
            jqhtml.register_component('Rsx_Money_Typed_Probe_Input_Temp', Rsx_Money_Typed_Probe_Input_Temp);

            const mount = async (name) => {
                const $host = $('<div>').appendTo('body');
                $host.component(name, { name: 'amount' });
                const component = $host.component();
                await component.ready();
                return [$host, component];
            };
            const outcome = (fn) => {
                try {
                    fn();
                    return 'accepted';
                } catch (e) {
                    return 'refused: ' + e.message;
                }
            };

            const [$plain_host, plain] = await mount('Rsx_Money_Plain_Probe_Input_Temp');
            const plain_outcome = {
                money: outcome(() => plain.val(Rsx_Money.from_decimal('1.00'))),
                string: outcome(() => plain.val('1.00')),
            };
            $plain_host.remove();

            const [$typed_host, typed] = await mount('Rsx_Money_Typed_Probe_Input_Temp');
            const typed_outcome = {
                money: outcome(() => typed.val(Rsx_Money.from_decimal('1.00'))),
                null: outcome(() => typed.val(null)),
                string: outcome(() => typed.val('1.00')),
            };
            $typed_host.remove();

            return { rehydrated, plain_outcome, typed_outcome };
        });

        const checks = [];
        const r = observed.rehydrated;
        checks.push(['a record field envelope becomes Rsx_Money', r.budget_is_money && r.budget === '1234.50', r]);
        checks.push(['null stays null', r.null_stays_null, r]);
        checks.push(['envelopes in a list become Rsx_Money', JSON.stringify(r.list) === '["-0.05","0.00"]', r.list]);
        checks.push(['an object with a key beside __MONEY stays a plain object', r.extra_key_is_plain, r]);

        const p = observed.plain_outcome;
        checks.push(['a plain input refuses an Rsx_Money', p.money.includes('That column is a money column - use <Money_Input>.'), p.money]);
        checks.push(['a plain input takes a string', p.string === 'accepted', p.string]);

        const t = observed.typed_outcome;
        checks.push(["an ACCEPTS = 'Rsx_Money' input takes an Rsx_Money", t.money === 'accepted', t.money]);
        checks.push(["an ACCEPTS = 'Rsx_Money' input takes null", t.null === 'accepted', t.null]);
        checks.push(["an ACCEPTS = 'Rsx_Money' input refuses a string", t.string.startsWith('refused') && t.string.includes('edits Rsx_Money values'), t.string]);

        for (const [label, ok, detail] of checks) {
            if (ok) {
                console.log('PASS: money rehydrate - ' + label);
            } else {
                console.log('FAIL: money rehydrate - ' + label + ': ' + JSON.stringify(detail));
                process.exitCode = 1;
            }
        }

        const unexpected = errors.filter((text) => !is_expected_guard_report(text));
        if (unexpected.length) {
            console.log('FAIL: money rehydrate - page errors ' + JSON.stringify(unexpected));
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: money rehydrate - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
