#!/usr/bin/env node
/**
 * Private-member isolation harness for the js_transform framework test concern.
 *
 * Transforms Fixture_Private_Alpha and Fixture_Private_Beta through the REAL `babel.transform`
 * handler of the node service's babel module (the request the PHP transformer sends),
 * concatenates the two outputs into ONE script - the shape of a bundle - evaluates it in a vm
 * sandbox and prints the facts as one JSON line: what each class reads back from its own
 * `static #SCALE` and `#count`, whether a brand check accepts the other class's instance, and
 * the generated holder names found in the emitted text.
 *
 * Usage: node private_fields.js
 *
 * Invoked by php/Js_Private_Field_Transform_Test.php.
 */

const path = require('path');
const vm = require('vm');

const BABEL_SERVICE = path.join(__dirname, '..', '..', '..', 'Core', 'JsParsers', 'resource', 'babel-service.js');
const FIXTURES = path.join(__dirname, 'fixtures');

const server = require(BABEL_SERVICE);

function transform(name) {
    const file = path.join(FIXTURES, name + '.js');
    const response = server.transform({ files: [{ path: file, hash_path: path.relative(process.cwd(), file) }] });
    const result = response.results[file];
    if (result.status !== 'success') {
        throw new Error('transform failed for ' + name + ': ' + JSON.stringify(result.error));
    }
    return result.result;
}

const alpha = transform('Fixture_Private_Alpha');
const beta = transform('Fixture_Private_Beta');

const sandbox = { WeakMap, TypeError, Object };
vm.createContext(sandbox);
vm.runInContext(
    alpha + '\n' + beta + '\n;globalThis.__facts = {' +
        'alpha_scale: Fixture_Private_Alpha.scale(),' +
        'beta_scale: Fixture_Private_Beta.scale(),' +
        'alpha_count: new Fixture_Private_Alpha().count(),' +
        'beta_count: new Fixture_Private_Beta().count(),' +
        'alpha_brands_beta: Fixture_Private_Alpha.reads_count_of(new Fixture_Private_Beta()),' +
        'alpha_brands_alpha: Fixture_Private_Alpha.reads_count_of(new Fixture_Private_Alpha()),' +
    '};',
    sandbox,
    { filename: 'private_fields.bundle.js' }
);

// Top-level `var <name> =` declarations whose name ends in a fixture member name.
const holders = (code) => [...code.matchAll(/^var (_\w*(?:SCALE|count)) =/gm)].map((m) => m[1]);

console.log(JSON.stringify({
    ...sandbox.__facts,
    alpha_holders: holders(alpha),
    beta_holders: holders(beta),
}));
