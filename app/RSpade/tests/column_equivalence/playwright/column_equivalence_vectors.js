#!/usr/bin/env node
/**
 * Column_Equivalence in the browser against the shared parity vectors
 * (../resource/column_equivalence_vectors.json).
 *
 * Column_Equivalence_Vectors_Test runs the SAME file through the PHP class; together the two
 * prove both languages give the same answer for every kind and every never-loosened case. Every
 * case runs here, `only: js` included, in BOTH argument orders, inside ONE page.evaluate();
 * the comparison happens here in node.
 *
 * The text_type rows name a fixture type the browser cannot see (the test tree is not served),
 * so the page declares the same class at run time: its sanitizer collapses whitespace runs and
 * trims, exactly as the PHP fixture's does.
 *
 * Also checked here, beside the vectors: an undefined side throws, and a generated model stub
 * answers same_value() / column_descriptor() from the page's field-length script (User_Model,
 * which every /_sys page carries), throwing for a name that is no exported column.
 *
 * The page is the framework's own /_sys panel: Column_Equivalence is a Core/Js class, present
 * in every bundle.
 *
 * Exit 0 on pass, 1 on any failure. Prints a FAIL: line per failing case and a summary.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');
const vectors = require('../resource/column_equivalence_vectors.json');

/** The browser half: decode and run every case, both orders. */
function run_in_page(input) {
    class Fixture_Text extends Rsx_Text_Abstract {
        static sanitize_encoded(raw) {
            return raw.replace(/\s+/g, ' ').trim();
        }
    }

    function descriptor(d) {
        const out = Object.assign({}, d);
        if (out.text_type === input.fixture) {
            out.text_type = Fixture_Text;
        }
        return out;
    }

    function decode(arg) {
        if (arg === null || typeof arg !== 'object' || Array.isArray(arg)) {
            return arg;
        }
        const tag = Object.keys(arg)[0];
        const value = arg[tag];
        switch (tag) {
            case 'money': return Rsx_Money.from_decimal(value);
            case 'money_envelope': return { __MONEY: value };
            case 'float': return Number(value);
            case 'text': return Fixture_Text.from_storage(value);
            case 'obj': return value;
            case 'list': return value;
        }
        throw new Error('unknown vector tag ' + JSON.stringify(tag));
    }

    function attempt(d, x, y) {
        try {
            return { value: Column_Equivalence.same(d, x, y) };
        } catch (e) {
            return { throws: (e && e.message) || String(e) };
        }
    }

    const results = input.cases.map((c) => {
        let d;
        let a;
        let b;
        try {
            d = descriptor(c.descriptor);
            a = decode(c.a);
            b = decode(c.b);
        } catch (e) {
            return { broken: e.message };
        }
        return { ab: attempt(d, a, b), ba: attempt(d, b, a) };
    });

    const undefined_side = attempt({ kind: 'string' }, undefined, null);

    const model = {};
    model.id_kind = User_Model.column_descriptor('id').kind;
    model.id_same = User_Model.same_value('id', '23', 23);
    model.id_differs = User_Model.same_value('id', 23, 24);
    try {
        User_Model.same_value('rsx_probe_no_such_column_temp', 1, 1);
        model.missing_error = null;
    } catch (e) {
        model.missing_error = e.message;
    }

    return { results, undefined_side, model };
}

function judge(c, actual) {
    if (actual.broken !== undefined) {
        return 'broken vector: ' + actual.broken;
    }
    const failures = [];
    for (const order of ['ab', 'ba']) {
        const got = actual[order];
        if (c.expect !== null && typeof c.expect === 'object' && c.expect.throws) {
            if (got.throws === undefined) {
                failures.push(order + ': expected a throw, got ' + JSON.stringify(got));
            }
        } else if (got.value !== c.expect) {
            failures.push(order + ': expected ' + JSON.stringify(c.expect) + ', got ' + JSON.stringify(got));
        }
    }

    return failures.length ? failures.join('; ') + ' for ' + JSON.stringify(c.a) + ' / ' + JSON.stringify(c.b) : null;
}

async function main() {
    const { browser, page, errors } = await open_sys_page();
    try {
        const cases = vectors.cases;
        const out = await page.evaluate(run_in_page, { cases, fixture: vectors.text_type_fixture });

        let failed = 0;
        cases.forEach((c, i) => {
            const failure = judge(c, out.results[i]);
            if (failure !== null) {
                failed++;
                console.log('FAIL: column equivalence vectors - ' + c.id + ': ' + failure);
            }
        });

        if (!out.undefined_side.throws || !out.undefined_side.throws.includes('undefined')) {
            failed++;
            console.log('FAIL: column equivalence - an undefined side must throw, got ' + JSON.stringify(out.undefined_side));
        }

        const m = out.model;
        if (m.id_kind !== 'integer' || m.id_same !== true || m.id_differs !== false
            || !m.missing_error || !m.missing_error.includes('no descriptor')) {
            failed++;
            console.log('FAIL: column equivalence - User_Model.same_value() / column_descriptor() ' + JSON.stringify(m));
        }

        if (errors.length) {
            console.log('FAIL: column equivalence vectors - page errors ' + JSON.stringify(errors));
            process.exitCode = 1;
        }
        if (failed > 0) {
            process.exitCode = 1;
        }
        const only_js = cases.filter((c) => c.only === 'js').length;
        console.log((failed === 0 ? 'PASS' : 'FAIL') + ': column equivalence vectors - ' + (cases.length - failed) + ' of '
            + cases.length + ' cases passed in the browser, both orders (' + only_js + ' of them JavaScript-only)');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: column equivalence vectors - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
