#!/usr/bin/env node
/**
 * hash() / deep_equal() and deep_clone() - the JS helpers that compare and copy plain data.
 *
 *   hash(x, false)    a canonical string for a value: object property order never matters,
 *                     array order does, and every leaf keeps its JSON type
 *   deep_equal(a, b)  hash(a) == hash(b)
 *   deep_clone(x)     a copy of every plain object and array at any depth; any other object
 *                     (a value object, a Date, a File) is the same object in the copy
 *
 * The type vectors are the collisions deep_equal() once answered true for: [] and {}, null and
 * "null", undefined (top-level and nested) and "undefined", true and "true", 5 and "5".
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const NAME = 'hash / deep_equal / deep_clone';

async function run() {
    const { browser, page, errors } = await open_sys_page();

    try {
        const result = await page.evaluate(() => {
            const money = Rsx_Money.from_decimal('12.50');
            const original = { a: { b: [1, { c: 'x' }] }, money: money, when: null };
            const copy = deep_clone(original);
            copy.a.b[1].c = 'changed';
            copy.a.b.push(2);
            copy.added = true;

            return {
                array_vs_object: deep_equal([], {}),
                nested_array_vs_object: deep_equal({ k: [] }, { k: {} }),
                null_vs_string: deep_equal(null, 'null'),
                nested_null_vs_string: deep_equal({ k: null }, { k: 'null' }),
                undefined_vs_string: deep_equal(undefined, 'undefined'),
                undefined_vs_marker_string: deep_equal(undefined, '__undefined__'),
                nested_undefined_vs_string: deep_equal({ k: undefined }, { k: 'undefined' }),
                nested_undefined_vs_array: deep_equal({ k: undefined }, { k: ['undefined'] }),
                true_vs_string: deep_equal(true, 'true'),
                number_vs_string: deep_equal(5, '5'),
                null_vs_empty: deep_equal(null, ''),
                key_order: deep_equal({ a: 1, b: [1, 2] }, { b: [1, 2], a: 1 }),
                list_order: deep_equal([1, 2], [2, 1]),
                same_nested: deep_equal({ a: { b: [null, true, 'x', undefined] } }, { a: { b: [null, true, 'x', undefined] } }),
                hash_stable: hash({ a: 1, b: 2 }) === hash({ b: 2, a: 1 }),
                clone_original_untouched: original.a.b[1].c === 'x' && original.a.b.length === 2 && !('added' in original),
                clone_copied: copy.a.b[1].c === 'changed' && copy.a !== original.a && copy.a.b !== original.a.b,
                clone_keeps_value_object: copy.money === money && copy.money instanceof Rsx_Money,
                clone_primitive: deep_clone('x') === 'x' && deep_clone(null) === null && deep_clone(undefined) === undefined,
            };
        });

        const checks = [
            ['[] is not {}', result.array_vs_object === false],
            ['a nested [] is not a nested {}', result.nested_array_vs_object === false],
            ['null is not "null"', result.null_vs_string === false],
            ['a nested null is not "null"', result.nested_null_vs_string === false],
            ['undefined is not "undefined"', result.undefined_vs_string === false],
            ['undefined is not "__undefined__"', result.undefined_vs_marker_string === false],
            ['a nested undefined is not "undefined"', result.nested_undefined_vs_string === false],
            ['a nested undefined is not ["undefined"]', result.nested_undefined_vs_array === false],
            ['true is not "true"', result.true_vs_string === false],
            ['5 is not "5"', result.number_vs_string === false],
            ['null is not ""', result.null_vs_empty === false],
            ['property order does not matter', result.key_order === true],
            ['list order matters', result.list_order === false],
            ['equal nested data is equal', result.same_nested === true],
            ['hash() is stable across key order', result.hash_stable === true],
            ['deep_clone() leaves the original untouched', result.clone_original_untouched === true],
            ['deep_clone() copies nested objects and arrays', result.clone_copied === true],
            ['deep_clone() keeps a value object as the same object', result.clone_keeps_value_object === true],
            ['deep_clone() returns a primitive as is', result.clone_primitive === true],
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
    } catch (error) {
        console.log('FAIL: ' + NAME + ' - ' + error.message);
        process.exitCode = 1;
    }

    await browser.close();
}

run();
