// Evaluates a JavaScript file as a classic script in a fresh vm context and prints
// JSON.stringify(__collect()). Used by String_Obfuscation_Test to compare the runtime values
// of an original concatenation and its obfuscated (and minified) counterparts. The context
// supplies the one global a compiled jqhtml template expects at load: jqhtml.register_template.
//
// Usage: node string_obfuscation_eval.js <file>

const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync(process.argv[2], 'utf8');
const context = vm.createContext({ jqhtml: { register_template() {} } });
vm.runInContext(code, context, { filename: process.argv[2] });
process.stdout.write(vm.runInContext('JSON.stringify(__collect())', context));
