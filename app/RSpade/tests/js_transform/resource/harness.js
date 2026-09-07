#!/usr/bin/env node
/**
 * Runtime harness for the js_transform framework test concern.
 *
 * Drives the REAL transform path (the exported internals of the node service's babel
 * module, babel-service.js) and proves, at runtime, that decorated class declarations keep
 * their module-scope binding and execute correctly. Also proves the fail-closed contract
 * assertion trips when the binding is dropped.
 *
 * Modes:
 *   node harness.js runtime <Fixture_Name> <target>   -> transform + eval, print facts JSON
 *   node harness.js emit <Fixture_Name> <target>      -> print the raw transformed source
 *   node harness.js assert_negative                   -> run the assertion against a
 *                                                        binding-dropping (fork-less) config
 *
 * Invoked by php/Js_Decorator_Transform_Test.php. Output is a single JSON line on stdout.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const BABEL_SERVICE = path.join(__dirname, '..', '..', '..', 'Core', 'JsParsers', 'resource', 'babel-service.js');
const FIXTURES = path.join(__dirname, 'fixtures');

const server = require(BABEL_SERVICE);

function fixture_path(name) {
    return path.join(FIXTURES, name + '.js');
}

function transform(name, target) {
    const file = fixture_path(name);
    const content = fs.readFileSync(file, 'utf8');
    const res = server.transformFileContent(content, file, target, file, true);
    if (res.status !== 'success') {
        throw new Error('transform failed for ' + name + ' [' + target + ']: ' + JSON.stringify(res.error));
    }
    return res.result;
}

// Decorator stubs + base class shared by every fixture. Class decorators in the 2023-11
// proposal are called (value, context); the factory decorators are called first with their
// argument. replace_class returns a subclass so we can prove a replacement wins.
function build_sandbox(decorator_ran) {
    return {
        Spa_Action: class Spa_Action {},
        Component: class Component {},
        route: (p) => (v) => { decorator_ran.route = p; return v; },
        layout: (p) => (v) => { decorator_ran.layout = p; return v; },
        spa: (p) => (v) => { decorator_ran.spa = p; return v; },
        title: (p) => (v) => { decorator_ran.title = p; v._spa_title = p; return v; },
        debounce: (ms) => (v) => { decorator_ran.debounce = ms; return v; },
        replace_class: (v) => class Replacement extends v { static IS_REPLACEMENT = true; },
        console,
        Symbol,
        Object,
        TypeError,
        Error,
    };
}

function runtime_facts(name, target) {
    const code = transform(name, target);
    const decorator_ran = {};
    const sandbox = build_sandbox(decorator_ran);
    sandbox.__captured = undefined;
    vm.createContext(sandbox);

    // Capture the bare class name from WITHIN the same script scope. A lexical binding
    // (a surviving `class Foo`/`let Foo` declaration, as upstream emits for the
    // non-static and undecorated cases) is reachable by bare reference in the shared
    // bundle scope but does NOT attach to the global object, so reading sandbox[name]
    // would miss it. Evaluating `Foo` in the same script resolves it the way a sibling
    // concatenated file's bare reference would. The `var Foo` the fork emits for the
    // static case is captured identically.
    const capture = `\n;globalThis.__captured = (typeof ${name} !== 'undefined') ? ${name} : undefined;`;
    vm.runInContext(code + capture, sandbox, { filename: name + '.transformed.js' });

    const cls = sandbox.__captured;
    const facts = {
        name,
        target,
        bare_name_defined: typeof cls === 'function',
        decorator_ran,
    };

    // Per-fixture runtime proofs.
    if (name === 'Fixture_Static_Action') {
        const inst = new cls();
        facts.static_field = cls.TABS;
        facts.method_reads_static = inst.get_tabs();
        facts.instanceof_base = inst instanceof sandbox.Spa_Action;
    } else if (name === 'Fixture_Member_Decs') {
        facts.static_field = cls.TABS;
        facts.has_method = typeof cls.prototype.do_search === 'function';
    } else if (name === 'Fixture_Replacement') {
        facts.bound_is_replacement = cls.IS_REPLACEMENT === true;
        facts.orig_via_chain = cls.ORIG === true;
    } else if (name === 'Fixture_Method_Only') {
        facts.make_returns_instance = cls.make() instanceof cls;
    } else if (name === 'Fixture_Title_Action') {
        facts.spa_title = cls._spa_title;
    } else if (name === '_Fixture_Sys_Sidebar') {
        const inst = new cls();
        facts.static_field = cls.WIDTH;
        facts.method_reads_static = inst.get_width();
        facts.instanceof_base = inst instanceof sandbox.Component;
    } else if (name === '_Fixture_Sys_Action') {
        const inst = new cls();
        facts.static_field = cls.TABS;
        facts.method_reads_static = inst.get_tabs();
        facts.spa_title = cls._spa_title;
    } else if (name === 'Fixture_Plain') {
        const inst = new cls();
        facts.static_field = cls.VALUE;
        facts.method_reads_static = inst.get_value();
    }

    return facts;
}

// Prove the fail-closed assertion trips: run the REAL createPrefixPlugin against a
// fork-LESS config (stock @babel/plugin-proposal-decorators). Without the fork's emitted
// `var Name = ...`, the decorated-static class loses its module-scope binding, so the
// assertion in the plugin's post() must throw naming the class.
function assert_negative() {
    const babel = require('@babel/core');
    const content = fs.readFileSync(fixture_path('Fixture_Static_Action'), 'utf8');
    try {
        babel.transformSync(content, {
            filename: 'Fixture_Static_Action.js',
            presets: [['@babel/preset-env', { targets: { safari: '14' } }]],
            plugins: [
                server.createPrefixPlugin('deadbeef'),
                ['@babel/plugin-proposal-decorators', { version: '2023-11' }],
                '@babel/plugin-transform-class-properties',
            ],
        });
        return { threw: false };
    } catch (e) {
        return { threw: true, message: e.message };
    }
}

const mode = process.argv[2];
let output;

if (mode === 'runtime') {
    output = runtime_facts(process.argv[3], process.argv[4] || 'modern');
} else if (mode === 'emit') {
    // Raw transformed source, printed as-is rather than as JSON facts, so a test can assert
    // on the emitted TEXT (the fork's `var <Name> =` binding). NEVER process.exit() here -
    // a pipe write is asynchronous and exiting truncates it.
    process.stdout.write(transform(process.argv[3], process.argv[4] || 'modern'));
} else if (mode === 'assert_negative') {
    output = assert_negative();
} else {
    console.error('Usage: node harness.js runtime <Fixture_Name> <target> | node harness.js emit <Fixture_Name> <target> | node harness.js assert_negative');
    process.exit(1);
}

if (mode !== 'emit') {
    console.log(JSON.stringify(output));
}
