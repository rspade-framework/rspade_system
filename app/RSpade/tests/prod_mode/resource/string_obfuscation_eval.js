// Evaluates a JavaScript file as a classic script in a fresh vm context and prints
// JSON.stringify(__collect()). Used by String_Obfuscation_Test to compare the runtime values
// of an original concatenation and its obfuscated (and minified) counterparts. The context
// supplies the jqhtml global a compiled template expects: register_template() (called at load)
// plus the render utilities a render function receives as its `jqhtml` argument - the markup
// buffer class and the runtime's own escape_html / print_object - and no-op logging, so an
// original that still carries its console.log / console_debug calls runs.
//
// Usage: node string_obfuscation_eval.js <file>

const fs = require('fs');
const vm = require('vm');
const jqhtml_runtime = require('@jqhtml/core');

// The runtime keeps its markup buffer class private (compiled templates reach it only through
// the render utilities object), so this is its shape: an instruction list, the definer, and
// p() appending to the list.
class Jqhtml_Markup {
    constructor(definer) {
        this.instructions = [];
        this.definer = definer;
        this.p = this.instructions.push.bind(this.instructions);
    }
}

const code = fs.readFileSync(process.argv[2], 'utf8');
const noop = () => {};
const context = vm.createContext({
    jqhtml: {
        register_template() {},
        Markup: Jqhtml_Markup,
        escape_html: jqhtml_runtime.escape_html,
        print_object: jqhtml_runtime.print_object
    },
    console: { log: noop, info: noop, warn: noop, error: noop },
    console_debug: noop
});
vm.runInContext(code, context, { filename: process.argv[2] });
process.stdout.write(vm.runInContext('JSON.stringify(__collect())', context));
