#!/usr/bin/env node
/**
 * What only the browser can show about Rsx_Money: the coercion guard, the envelope, the
 * jqhtml printer, the cache class and the display shipped in rsxapp.
 *
 * - Every implicit conversion to a primitive THROWS (`+m`, `m + 1`, `m < n`, a template
 *   literal, str(m), Number(m), `m == 5`) and so does an explicit m.toString(); `===` between
 *   two values is identity, which nothing can intercept.
 * - json_encode(m) is the envelope {"__MONEY":"1.00"}, also inside a structure.
 * - `<%= value %>` prints format(): jqhtml.print_object(), the object branch every compiled
 *   interpolation calls, answers '$1,234.50' and escapes it as a string.
 * - A value stored in a component's this.data comes back from jqhtml's cache serializer as an
 *   Rsx_Money (on_load() results always pass through it), holding the same amount.
 * - window.rsxapp.money_display carries the five display keys, and is what format() uses.
 * - The two JavaScript-only vectors (a missing mode; a Number past 2^53 as cents) refuse with
 *   `argument`.
 *
 * Runs on the framework's own /_sys panel; the probe component is registered at run time.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

/** The guard throws through shouldnt_happen(), which reports to the console by design. */
function is_expected_guard_report(text) {
    return text.includes('Rsx_Money cannot be converted to a primitive')
        || text.includes('SANITY CHECK FAILURE')
        || text.startsWith('=====')
        || text.startsWith('Stack trace:');
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const observed = await page.evaluate(async () => {
            const m = Rsx_Money.from_decimal('1234.5');
            const n = Rsx_Money.from_decimal('2.00');

            const throws = (fn) => {
                try {
                    fn();
                    return 'did not throw';
                } catch (e) {
                    return String(e && e.message).includes('Rsx_Money cannot be converted to a primitive') ? true : 'threw ' + e.message;
                }
            };

            const coercion = {
                unary_plus: throws(() => +m),
                plus_number: throws(() => m + 1),
                minus_money: throws(() => m - n),
                less_than: throws(() => m < n),
                template_literal: throws(() => `${m}`),
                str: throws(() => str(m)),
                to_string: throws(() => m.toString()),
                number: throws(() => Number(m)),
                loose_equals_number: throws(() => m == 5),
            };

            const same_amount = Rsx_Money.from_decimal('1234.50');
            const identity = { strict_is_identity: (m === same_amount) === false, equals_is_value: m.equals(same_amount) === true };

            const envelope = {
                alone: json_encode(Rsx_Money.from_decimal('1.00')),
                nested: json_encode({ total: Rsx_Money.from_cents(-5), list: [Rsx_Money.zero()] }),
                own_keys: Object.keys(m).join(','),
            };

            const printed = {
                escape: jqhtml.print_object(m, 'escape'),
                negative: jqhtml.print_object(Rsx_Money.from_decimal('-0.05'), 'escape'),
            };

            // The cache class: on_load() results pass through jqhtml's serializer.
            class Rsx_Money_Cache_Probe_Temp extends Component {
                async on_load() {
                    this.data.amount = Rsx_Money.from_decimal('1234.50');
                    window.__rsx_money_probe_original = this.data.amount;
                    this.data.list = [Rsx_Money.from_cents(-5)];
                }
            }
            jqhtml.register_component('Rsx_Money_Cache_Probe_Temp', Rsx_Money_Cache_Probe_Temp);
            const $host = $('<div>').appendTo('body');
            $host.component('Rsx_Money_Cache_Probe_Temp', { probe: 'money' });
            const component = $host.component();
            await component.ready();
            const cache = {
                is_money: component.data.amount instanceof Rsx_Money,
                is_a_copy: component.data.amount !== window.__rsx_money_probe_original,
                amount: component.data.amount instanceof Rsx_Money ? component.data.amount.to_decimal() : null,
                list_is_money: component.data.list[0] instanceof Rsx_Money,
                list_amount: component.data.list[0] instanceof Rsx_Money ? component.data.list[0].to_decimal() : null,
            };
            $host.remove();

            const only_js = {};
            try {
                Rsx_Money.from_decimal('10.00').multiply('2');
                only_js.missing_mode = 'did not throw';
            } catch (e) {
                only_js.missing_mode = e instanceof Rsx_Money_Exception ? e.reason : 'threw ' + e.message;
            }
            try {
                Rsx_Money.from_cents(9007199254740992);
                only_js.unsafe_cents = 'did not throw';
            } catch (e) {
                only_js.unsafe_cents = e instanceof Rsx_Money_Exception ? e.reason : 'threw ' + e.message;
            }

            const exception = new Rsx_Money_Exception('range', 'x');
            const exception_shape = {
                is_error: exception instanceof Error,
                name: exception.name,
                is_input: exception.is_input_problem(),
            };

            return {
                coercion,
                identity,
                envelope,
                printed,
                cache,
                only_js,
                exception_shape,
                display: window.rsxapp.money_display,
                display_is_active: json_encode(Rsx_Money.display()) === json_encode(window.rsxapp.money_display),
            };
        });

        const checks = [];
        for (const [what, result] of Object.entries(observed.coercion)) {
            checks.push(['coercion ' + what + ' throws', result === true, result]);
        }
        checks.push(['=== is identity, equals() is the amount', observed.identity.strict_is_identity && observed.identity.equals_is_value, observed.identity]);
        checks.push(['json_encode(m) is the envelope', observed.envelope.alone === '{"__MONEY":"1.00"}', observed.envelope.alone]);
        checks.push(['json_encode of a structure nests envelopes', observed.envelope.nested === '{"total":{"__MONEY":"-0.05"},"list":[{"__MONEY":"0.00"}]}', observed.envelope.nested]);
        checks.push(['the one own property is _decimal', observed.envelope.own_keys === '_decimal', observed.envelope.own_keys]);
        checks.push(['the printer prints format()', observed.printed.escape === '$1,234.50', observed.printed.escape]);
        checks.push(['the printer prints a negative', observed.printed.negative === '-$0.05', observed.printed.negative]);
        checks.push(['this.data keeps an Rsx_Money through the cache serializer', observed.cache.is_money && observed.cache.is_a_copy && observed.cache.amount === '1234.50', observed.cache]);
        checks.push(['a list in this.data keeps its Rsx_Money', observed.cache.list_is_money && observed.cache.list_amount === '-0.05', observed.cache]);
        checks.push(['a missing mode is argument (JavaScript-only)', observed.only_js.missing_mode === 'argument', observed.only_js.missing_mode]);
        checks.push(['a Number past 2^53 as cents is argument (JavaScript-only)', observed.only_js.unsafe_cents === 'argument', observed.only_js.unsafe_cents]);
        checks.push(['Rsx_Money_Exception extends Error', observed.exception_shape.is_error && observed.exception_shape.name === 'Rsx_Money_Exception' && observed.exception_shape.is_input, observed.exception_shape]);
        const keys = observed.display ? Object.keys(observed.display).sort().join(',') : null;
        checks.push(['rsxapp.money_display carries the five keys', keys === 'decimal_separator,group_separator,symbol,symbol_position,symbol_spacing', observed.display]);
        checks.push(['format() reads rsxapp.money_display', observed.display_is_active, observed.display]);

        for (const [label, ok, detail] of checks) {
            if (ok) {
                console.log('PASS: money coercion - ' + label);
            } else {
                console.log('FAIL: money coercion - ' + label + ': ' + JSON.stringify(detail));
                process.exitCode = 1;
            }
        }

        const unexpected = errors.filter((text) => !is_expected_guard_report(text));
        if (unexpected.length) {
            console.log('FAIL: money coercion - page errors ' + JSON.stringify(unexpected));
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: money coercion - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
