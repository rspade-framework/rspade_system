#!/usr/bin/env node
/**
 * Rsx_Money in the browser against the shared parity vectors (../resource/money_vectors.json).
 *
 * Money_Vectors_Test runs the SAME file through the PHP class; together the two prove both
 * languages give the identical result or the identical refusal for every case. Every case
 * runs here, `only: js` included. The cases are handed to ONE page.evaluate() that decodes the
 * arguments, calls the class and encodes the result in the browser exactly as the vector
 * format describes; the comparison happens here in node. A case whose arguments cannot be
 * decoded is a broken vector, never an expectation met.
 *
 * The page is the framework's own /_sys panel: Rsx_Money is a Core/Js class, present in
 * every bundle.
 *
 * Exit 0 on pass, 1 on any failure. Prints a FAIL: line per failing case and a summary.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');
const vectors = require('../resource/money_vectors.json');

/** Exact structural equality of two JSON-shaped values. */
function deep_equal(a, b) {
    if (a === b) {
        return true;
    }
    if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') {
        return false;
    }
    if (Array.isArray(a) !== Array.isArray(b)) {
        return false;
    }
    const keys_a = Object.keys(a);
    const keys_b = Object.keys(b);
    if (keys_a.length !== keys_b.length) {
        return false;
    }
    for (let i = 0; i < keys_a.length; i++) {
        if (keys_a[i] !== keys_b[i] || !deep_equal(a[keys_a[i]], b[keys_b[i]])) {
            return false;
        }
    }

    return true;
}

/** The browser half: decode, run and encode every case (SPEC section 12). */
function run_in_page(cases) {
    function decode(arg) {
        if (Array.isArray(arg)) {
            return arg.map(decode);
        }
        if (arg === null || typeof arg !== 'object') {
            return arg;
        }
        const keys = Object.keys(arg);
        if (keys.length === 1) {
            const tag = keys[0];
            const value = arg[tag];
            if (tag === 'money') {
                if (typeof value !== 'string') {
                    throw new Error('money tag holds a non-string');
                }
                return Rsx_Money.from_decimal(value);
            }
            if (tag === 'int') {
                if (typeof value !== 'string' || !/^-?[0-9]+$/.test(value)) {
                    throw new Error('int tag holds ' + JSON.stringify(value));
                }
                return BigInt(value);
            }
            if (tag === 'float') {
                if (value === 'INF') {
                    return Infinity;
                }
                if (value === '-INF') {
                    return -Infinity;
                }
                if (value === 'NaN') {
                    return NaN;
                }
                const n = Number(value);
                if (typeof value === 'string' && value !== '-0' && Number.isNaN(n)) {
                    throw new Error('unknown float special ' + JSON.stringify(value));
                }
                return n;
            }
            if (tag === 'rounding') {
                if (typeof value !== 'string' || !value.startsWith('ROUND_') || typeof Rsx_Money[value] !== 'string') {
                    throw new Error('unknown rounding constant ' + JSON.stringify(value));
                }
                return Rsx_Money[value];
            }
            if (tag === 'missing') {
                return undefined;
            }
        }
        const decoded = {};
        for (const key of keys) {
            decoded[key] = decode(arg[key]);
        }
        return decoded;
    }

    function encode(result) {
        if (result instanceof Rsx_Money) {
            return { money: result.to_decimal() };
        }
        if (Array.isArray(result)) {
            return result.map(encode);
        }
        if (result !== null && typeof result === 'object') {
            const encoded = {};
            for (const key of Object.keys(result)) {
                encoded[key] = encode(result[key]);
            }
            return encoded;
        }
        if (typeof result === 'bigint' || typeof result === 'undefined' || typeof result === 'function') {
            return { unencodable: typeof result };
        }
        return result;
    }

    return cases.map((c) => {
        let on;
        let args;
        try {
            on = c.call.on === null ? null : decode(c.call.on);
            args = c.call.args.map(decode);
        } catch (e) {
            return { broken: e.message };
        }

        Rsx_Money._use_display({});
        try {
            if (c.config) {
                Rsx_Money._use_display(c.config);
            }
            const target = on === null ? Rsx_Money : on;
            if (typeof target[c.call.method] !== 'function') {
                return { error: 'no method ' + c.call.method };
            }
            return { value: encode(target[c.call.method](...args)) };
        } catch (e) {
            if (e instanceof Rsx_Money_Exception) {
                return { throws: e.reason, message: e.message };
            }
            return { error: (e && e.name) + ': ' + (e && e.message) };
        } finally {
            Rsx_Money._use_display(null);
        }
    });
}

function judge(c, actual) {
    if (actual.broken !== undefined) {
        return 'broken vector: ' + actual.broken;
    }
    const expect = c.expect;
    if ('value' in expect) {
        if ('value' in actual && deep_equal(actual.value, expect.value)) {
            return null;
        }
    } else if (actual.throws === expect.throws && (expect.throws === 'argument' || actual.message === expect.message)) {
        return null;
    }

    return 'expected ' + JSON.stringify(expect) + ', got ' + JSON.stringify(actual);
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const cases = vectors.cases;
        const results = await page.evaluate(run_in_page, cases);

        let failed = 0;
        cases.forEach((c, i) => {
            const failure = judge(c, results[i]);
            if (failure !== null) {
                failed++;
                console.log('FAIL: money vectors - ' + c.id + ': ' + failure);
            }
        });

        if (errors.length) {
            console.log('FAIL: money vectors - page errors ' + JSON.stringify(errors));
            process.exitCode = 1;
        }
        if (failed > 0) {
            process.exitCode = 1;
        }
        const only_js = cases.filter((c) => c.only === 'js').length;
        console.log((failed === 0 ? 'PASS' : 'FAIL') + ': money vectors - ' + (cases.length - failed) + ' of ' + cases.length
            + ' cases passed in the browser (' + only_js + ' of them JavaScript-only)');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: money vectors - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
