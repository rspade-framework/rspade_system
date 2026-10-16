/**
 * STRING_OBFUSCATION subsystem of the node service (prefix `string_obfuscation`).
 *
 * Production-only string obfuscation of the files that make up a bundle's APP JavaScript,
 * run on their ORIGINAL SOURCE - before babel - (BundleCompiler, behind
 * config('rsx.production.obfuscate_strings')). The caller babel-transforms each rewritten
 * copy where the file is babel-transformed at all, concatenates, and minifies. Working on the
 * source is what keeps a class field a field (babel lowers an instance field into the
 * constructor) and keeps babel's own injected helpers out of reach. Files are parsed with
 * @babel/parser in its ESTree shape, decorators included. Its purpose is
 * to keep the application's prose - messages, labels, the literal data inside object and
 * array literals - from reading as plain text in the shipped file. It deters casual reading
 * and nothing more: rendered text still lands in the DOM and the dictionary is readable by
 * anyone who looks, so rules and secrets stay server-side.
 *
 * The CALLER chooses the files (BundleCompiler::_string_obfuscation_eligible(): the files
 * the manifest indexes as ES6 class files, the generated model stubs and concrete model
 * classes, and the compiled jqhtml templates). Everything the pass does with them lives in
 * this file:
 *
 *   SCOPE - within an eligible file, only strings lexically inside a FUNCTION BODY: methods,
 *     getters, setters, constructors, plain functions, arrows, nested to any depth. The
 *     nearest enclosing boundary decides: a function body says yes; a class field
 *     initializer (instance or static), a static block, a decorator, a member key, an
 *     `extends` expression, a parameter list (defaults included) or the top level of the file
 *     says no.
 *
 *   QUALIFICATION - within scope, a string is converted when
 *     (a) it has an INNER space: a U+0020 with a non-space character somewhere before it and
 *         somewhere after it (so at least two non-space characters; leading and trailing
 *         spaces ride along once an inner one exists), or
 *     (b) it is a VALUE in an object literal or an ELEMENT of an array literal (nested
 *         literals included), whatever its length - a single word and '' included.
 *     A non-computed object KEY is converted only under (a), and then becomes a computed key.
 *     A template literal's static parts (quasis) convert under (a) one by one; a template
 *     that is itself an object value or array element converts every non-empty quasi, so the
 *     whole value is reproduced from the dictionary. Directive prologues ('use strict'),
 *     tagged templates and import sources are never touched.
 *
 *   WORDS - a converted string is split on exactly one space (split(' ')). A word is the run
 *     between single spaces with every other character (punctuation, tabs, newlines)
 *     included; leading, trailing and doubled spaces produce empty words, so joining with ' '
 *     reproduces the original byte for byte.
 *
 *   DICTIONARY - two phases. Phase 1 collects every unique word from EVERY eligible file of
 *     the bundle; the list is SORTED, then shuffled by a PRNG seeded with the sha256 of the
 *     sorted list: identical input gives byte-identical output (the sealed-build determinism
 *     contract) and any change to the word set gives a fresh order. Phase 2 rewrites each
 *     eligible file against that one dictionary.
 *
 *   RUNTIME - a generated HELPER FILE declares three true globals at the top level of the
 *     classic app script:
 *       var _$$SC = [ ...words ];               the dictionary
 *       function _$$(...indexes) { ... }         the indexed words joined by ' '
 *       function _$$R(index, count) { ... }      `count` copies of one index
 *     and each converted string becomes `_$$(3,55,27)`; a run of three or more identical
 *     consecutive indexes is spread from one repeat call, `_$$(3,..._$$R(8,12),27)`. The caller concatenates the helper
 *     file DIRECTLY BEFORE the first eligible file, so every converted string runs after the
 *     dictionary exists. Top-level declarations in a non-module script are properties of the
 *     global object, Terser never renames a top-level name (toplevel mangling is off), and
 *     the minify subsystem reserves all three names from local mangling, so no minified local can
 *     shadow them. When nothing converts, no helper is written and no file is rewritten.
 *
 *   OUTPUT - rewritten copies are written to the caller's scratch directory (cleared first);
 *     a file with nothing converted gets no copy. Rewriting is by source position only:
 *     every byte outside a converted string is untouched, and a converted string that spanned
 *     lines keeps its line breaks after the call, so the file's line count - and the line
 *     numbers of its inline sourcemap - do not move.
 *
 *   COLLISIONS - an identifier `_$$SC`, `_$$` or `_$$R` already present in an eligible file (any
 *     Identifier node), in any other app file of the bundle or in its vendor output (a token
 *     scan - those files are never parsed) fails the build, naming the bundle, the identifier
 *     and the file.
 *
 * Methods: string_obfuscation.obfuscate
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const babel_parser = require('@babel/parser');

const DICTIONARY_NAME = '_$$SC';
const PRINTER_NAME = '_$$';
const REPEAT_NAME = '_$$R';
const RESERVED_NAMES = [DICTIONARY_NAME, PRINTER_NAME, REPEAT_NAME];

// A run of this many identical consecutive indexes or more is emitted as one repeat call.
const REPEAT_MIN_RUN = 3;

const FUNCTION_TYPES = new Set(['FunctionExpression', 'FunctionDeclaration', 'ArrowFunctionExpression']);
const CLASS_TYPES = new Set(['ClassDeclaration', 'ClassExpression']);


// =============================================================================
// AST WALK
// =============================================================================

/**
 * Visit every node depth-first, recording each node's parent. acorn nodes carry their
 * children as plain properties (single nodes or arrays), so a generic walk covers the
 * whole grammar without a per-type table.
 */
function walk(root, visit) {
    const parents = new Map();
    const stack = [root];
    parents.set(root, null);

    while (stack.length > 0) {
        const node = stack.pop();
        visit(node);

        for (const key of Object.keys(node)) {
            if (key === 'type' || key === 'start' || key === 'end' || key === 'loc' || key === 'range') {
                continue;
            }
            const value = node[key];
            if (Array.isArray(value)) {
                for (let i = value.length - 1; i >= 0; i--) {
                    const child = value[i];
                    if (child && typeof child.type === 'string') {
                        parents.set(child, node);
                        stack.push(child);
                    }
                }
            } else if (value && typeof value.type === 'string') {
                parents.set(value, node);
                stack.push(value);
            }
        }
    }

    return parents;
}

/**
 * Whether a node sits lexically inside a FUNCTION BODY. The nearest enclosing boundary
 * decides: a function's body says yes; its parameter list or name, a class field
 * initializer, a static block, a decorator, a member key, an `extends` expression or the
 * program says no.
 */
function in_function_body(node, parents) {
    let current = node;
    let parent = parents.get(current);

    while (parent) {
        if (FUNCTION_TYPES.has(parent.type)) {
            return parent.body === current;
        } else if (parent.type === 'PropertyDefinition' || parent.type === 'StaticBlock' || parent.type === 'Decorator') {
            return false;
        } else if (parent.type === 'MethodDefinition' && parent.key === current) {
            return false;
        } else if (CLASS_TYPES.has(parent.type) && parent.superClass === current) {
            return false;
        } else if (parent.type === 'Program') {
            return false;
        }

        current = parent;
        parent = parents.get(current);
    }

    return false;
}


// =============================================================================
// QUALIFICATION
// =============================================================================

/** Rule (a): a space with a non-space character before it and after it. */
function has_inner_space(value) {
    return value.replace(/^ +| +$/g, '').includes(' ');
}

/** Rule (b): the node is a value in an object literal or an element of an array literal. */
function is_literal_data(node, parents) {
    const parent = parents.get(node);
    if (!parent) {
        return false;
    }
    if (parent.type === 'ArrayExpression') {
        return true;
    }
    if (parent.type === 'Property' && parent.value === node && parent.kind === 'init' && !parent.method) {
        const container = parents.get(parent);
        return !!container && container.type === 'ObjectExpression';
    }
    return false;
}

/** A non-computed key of an object literal or object pattern property. */
function is_object_key(node, parents) {
    const parent = parents.get(node);
    return !!parent && parent.type === 'Property' && parent.key === node && !parent.computed;
}

/** Strings the pass never touches whatever their content or position. */
function is_untouchable_literal(node, parents) {
    const parent = parents.get(node);
    if (!parent) {
        return true;
    }
    // Directive prologue ('use strict' and friends)
    if (parent.type === 'ExpressionStatement' && parent.directive !== undefined) {
        return true;
    }
    // import('x') / import ... from 'x' / export ... from 'x'
    if (parent.type === 'ImportExpression' || parent.type === 'ImportDeclaration'
        || parent.type === 'ExportAllDeclaration' || parent.type === 'ExportNamedDeclaration') {
        return true;
    }
    return false;
}


// =============================================================================
// DICTIONARY
// =============================================================================

/**
 * sfc32 - a small, fast, well-distributed 32-bit PRNG over a 128-bit state, seeded here
 * from a sha256 so the shuffle is a pure function of the word set.
 */
function seeded_random(seed_buffer) {
    let a = seed_buffer.readUInt32BE(0);
    let b = seed_buffer.readUInt32BE(4);
    let c = seed_buffer.readUInt32BE(8);
    let d = seed_buffer.readUInt32BE(12);

    return function () {
        a >>>= 0; b >>>= 0; c >>>= 0; d >>>= 0;
        let t = (a + b) | 0;
        a = b ^ (b >>> 9);
        b = (c + (c << 3)) | 0;
        c = (c << 21) | (c >>> 11);
        d = (d + 1) | 0;
        t = (t + d) | 0;
        c = (c + t) | 0;
        return (t >>> 0) / 4294967296;
    };
}

/** Sorted unique words, Fisher-Yates shuffled under a seed derived from that sorted list. */
function build_dictionary(word_set) {
    const sorted = Array.from(word_set).sort();
    const seed = crypto.createHash('sha256').update(JSON.stringify(sorted), 'utf8').digest();
    const random = seeded_random(seed);

    const words = sorted.slice();
    for (let i = words.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        const swap = words[i];
        words[i] = words[j];
        words[j] = swap;
    }

    const index = new Map();
    words.forEach((word, i) => index.set(word, i));
    return { words, index };
}


// =============================================================================
// COLLISIONS
// =============================================================================

function collision_error(bundle, name, where, label) {
    return new Error(
        `String obfuscation: bundle ${bundle} already uses the identifier '${name}' in its ${where} ` +
        `JavaScript (${label}). It is reserved for the production string dictionary ` +
        `(rsx.production.obfuscate_strings); rename it or turn the switch off.`
    );
}

/** Token scan of a file that is never parsed (non-eligible app files, vendor output). */
function assert_no_token_collision(content, bundle, where, label) {
    // Each name is matched as a whole token, so `_$$SC` and `_$$R` never report as `_$$`.
    for (const name of RESERVED_NAMES) {
        const escaped = name.replace(/\$/g, '\\$');
        const pattern = new RegExp(`(?<![A-Za-z0-9_$])${escaped}(?![A-Za-z0-9_$])`);
        if (pattern.test(content)) {
            throw collision_error(bundle, name, where, label);
        }
    }
}


// =============================================================================
// TRANSFORM
// =============================================================================

/**
 * Phase 1 for one eligible file: parse, check collisions, and list the edits.
 * Each edit: {start, end, value, kind: 'expr'|'key'|'quasi'}.
 */
function collect_edits(content, bundle, label) {
    // @babel/parser in its ESTree shape: the eligible files are the ORIGINAL sources, which
    // carry decorators (stage 3) that a plain ECMAScript parser rejects.
    let ast;
    try {
        ast = babel_parser.parse(content, {
            sourceType: 'script',
            allowReturnOutsideFunction: false,
            plugins: [['estree', { classFeatures: true }], 'decorators']
        }).program;
    } catch (error) {
        throw new Error(`String obfuscation: bundle ${bundle} could not parse ${label}: ${error.message}`);
    }

    const candidates = [];
    const logging = [];
    const parents = walk(ast, (node) => {
        if (node.type === 'Identifier' && RESERVED_NAMES.includes(node.name)) {
            throw collision_error(bundle, node.name, 'app', label);
        }
        if (node.type === 'ExpressionStatement' && node.expression.type === 'CallExpression'
            && is_logging_callee(node.expression.callee)) {
            logging.push(node);
        }
        if ((node.type === 'Literal' && typeof node.value === 'string') || node.type === 'TemplateLiteral') {
            candidates.push(node);
        }
    });

    const edits = [];

    for (const node of candidates) {
        if (!in_function_body(node, parents)) {
            continue;
        }

        if (node.type === 'Literal') {
            if (is_untouchable_literal(node, parents)) {
                continue;
            }
            if (is_object_key(node, parents)) {
                if (has_inner_space(node.value)) {
                    edits.push({ start: node.start, end: node.end, value: node.value, kind: 'key' });
                }
                continue;
            }
            if (has_inner_space(node.value) || is_literal_data(node, parents)) {
                edits.push({ start: node.start, end: node.end, value: node.value, kind: 'expr' });
            }
            continue;
        }

        // TemplateLiteral
        const parent = parents.get(node);
        if (parent && parent.type === 'TaggedTemplateExpression' && parent.quasi === node) {
            continue;
        }
        const as_data = is_literal_data(node, parents);

        if (node.expressions.length === 0) {
            const value = node.quasis[0].value.cooked;
            if (value !== null && value !== undefined && (as_data || has_inner_space(value))) {
                edits.push({ start: node.start, end: node.end, value, kind: 'expr' });
            }
            continue;
        }

        for (const quasi of node.quasis) {
            const value = quasi.value.cooked;
            if (value === null || value === undefined || value === '') {
                continue;
            }
            if (as_data || has_inner_space(value)) {
                edits.push({ start: quasi.start, end: quasi.end, value, kind: 'quasi' });
            }
        }
    }

    return fold_logging_statements(edits, logging, content);
}

/**
 * LOGGING STATEMENTS - a `console.log(...)` or `console_debug(...)` call that is a whole
 * statement is removed here, BEFORE the words are collected, so the text it would have
 * logged never reaches the dictionary. The minifier drops the same calls from every
 * strict-production app file; doing it first in the eligible files keeps their text out of
 * `_$$SC` and leaves no stray printer call behind. A call whose value is used is not a
 * statement and is left for the minifier, as is any other console method.
 *
 * An argument that may have an effect is kept, so `console.log(counter++)` still counts:
 * the statement becomes `void (counter++);`, and a string inside a kept argument converts as
 * anywhere else. A statement with nothing to keep becomes `;`, which is a valid statement
 * in every position a statement can sit (an unbraced if body included). Line breaks the
 * removed text spanned follow the replacement, so the file keeps its line count.
 */
function is_logging_callee(callee) {
    if (callee.type === 'Identifier') {
        return callee.name === 'console_debug';
    }
    return callee.type === 'MemberExpression' && !callee.computed
        && callee.object.type === 'Identifier' && callee.object.name === 'console'
        && callee.property.type === 'Identifier' && callee.property.name === 'log';
}

/**
 * The parts of a removed call's argument that must still be evaluated: every sub-expression
 * that may have an effect, with the strings and plain values around it dropped. Text being
 * assembled for the log - a concatenation, a template, an array or object of values - is
 * looked through; anything that may run code (a call, an assignment, a property read that a
 * getter could answer) is kept whole.
 */
function effects(node) {
    switch (node.type) {
        case 'Literal':
        case 'Identifier':
        case 'ThisExpression':
        case 'FunctionExpression':
        case 'ArrowFunctionExpression':
            return [];
        case 'TemplateLiteral':
            return node.expressions.flatMap(effects);
        case 'BinaryExpression':
            return [...effects(node.left), ...effects(node.right)];
        case 'UnaryExpression':
            return node.operator === 'delete' ? [node] : effects(node.argument);
        case 'ArrayExpression':
            return node.elements.some((element) => element && element.type === 'SpreadElement')
                ? [node]
                : node.elements.filter(Boolean).flatMap(effects);
        case 'ObjectExpression':
            return node.properties.every((prop) => prop.type === 'Property' && !prop.computed)
                ? node.properties.flatMap((prop) => effects(prop.value))
                : [node];
        default:
            return [node];
    }
}

function count_newlines(text) {
    return (text.match(/\n/g) || []).length;
}

function fold_logging_statements(edits, logging, content) {
    // An outer logging statement swallows a nested one (a console.log inside a callback
    // argument that is itself dropped).
    const statements = logging
        .sort((x, y) => x.start - y.start)
        .filter((node, i, all) => !all.some((other) => other !== node && other.start <= node.start && node.end <= other.end));

    if (statements.length === 0) {
        return edits.sort((x, y) => x.start - y.start);
    }

    const inside = (edit, node) => edit.start >= node.start && edit.end <= node.end;
    const kept_edits = edits.filter((edit) => !statements.some((node) => inside(edit, node)));

    for (const node of statements) {
        const kept = node.expression.arguments.flatMap(effects);
        const inner = kept.map((arg) => ({
            start: arg.start,
            end: arg.end,
            spread: arg.type === 'SpreadElement',
            edits: edits.filter((edit) => inside(edit, arg)).sort((x, y) => x.start - y.start)
        }));

        kept_edits.push({ start: node.start, end: node.end, kind: 'strip', kept: inner });
    }

    return kept_edits.sort((x, y) => x.start - y.start);
}

const IDENTIFIER_PART = /[A-Za-z0-9_$\u0080-￿]/;

/** Every string edit, those inside a kept argument of a removed logging statement included. */
function string_edits(edits) {
    const out = [];
    for (const edit of edits) {
        if (edit.kind === 'strip') {
            for (const arg of edit.kept) {
                out.push(...arg.edits);
            }
        } else {
            out.push(edit);
        }
    }
    return out;
}

/** The replacement for a removed logging statement: its kept arguments, or an empty statement. */
function strip_replacement(content, edit, index) {
    let kept_newlines = 0;
    const parts = edit.kept.map((arg) => {
        const shifted = arg.edits.map((inner) => ({ ...inner, start: inner.start - arg.start, end: inner.end - arg.start }));
        const source = apply_edits(content.slice(arg.start, arg.end), shifted, index);
        kept_newlines += count_newlines(source);
        return arg.spread ? `[${source}]` : source;
    });

    // `void (...)` rather than `(...)`: a statement opening with a parenthesis would join onto
    // a previous line that ends without a semicolon and call it.
    const statement = parts.length === 0 ? ';' : `void (${parts.join(', ')});`;
    const dropped_newlines = count_newlines(content.slice(edit.start, edit.end)) - kept_newlines;

    return statement + '\n'.repeat(Math.max(0, dropped_newlines));
}

/** Phase 2 for one file: splice each edit's printer call in by source position. */
function apply_edits(content, edits, index) {
    const parts = [];
    let cursor = 0;

    for (const edit of edits) {
        if (edit.kind === 'strip') {
            parts.push(content.slice(cursor, edit.start), strip_replacement(content, edit, index));
            cursor = edit.end;
            continue;
        }

        let call = `${PRINTER_NAME}(${printer_arguments(edit.value.split(' ').map((w) => index.get(w)))})`;

        // Line breaks the original source spanned (a multi-line template, a `\`-continued
        // string) follow the call, so the file keeps its line count.
        call += '\n'.repeat((content.slice(edit.start, edit.end).match(/\n/g) || []).length);

        let replacement;
        if (edit.kind === 'key') {
            replacement = `[${call}]`;
        } else if (edit.kind === 'quasi') {
            replacement = `\${${call}}`;
        } else {
            // An expression glued to a preceding word (`return'a b'`, `case'x'`) needs a separator.
            const before = edit.start > 0 ? content[edit.start - 1] : '';
            replacement = (before && IDENTIFIER_PART.test(before) ? ' ' : '') + call;
        }

        parts.push(content.slice(cursor, edit.start), replacement);
        cursor = edit.end;
    }
    parts.push(content.slice(cursor));

    return parts.join('');
}

/**
 * The printer's argument list for a string's word indexes: a run of REPEAT_MIN_RUN or more
 * identical consecutive indexes (indentation inside a template splits into a long run of
 * empty words) is spread from one repeat call, `...${REPEAT_NAME}(n,count)`.
 */
function printer_arguments(indexes) {
    const parts = [];

    for (let i = 0; i < indexes.length;) {
        let run = 1;
        while (i + run < indexes.length && indexes[i + run] === indexes[i]) {
            run++;
        }

        if (run >= REPEAT_MIN_RUN) {
            parts.push(`...${REPEAT_NAME}(${indexes[i]},${run})`);
        } else {
            for (let k = 0; k < run; k++) {
                parts.push(String(indexes[i]));
            }
        }

        i += run;
    }

    return parts.join(',');
}

function helper_source(words) {
    return `var ${DICTIONARY_NAME} = [${words.map((w) => JSON.stringify(w)).join(',')}];\n` +
        `function ${PRINTER_NAME}(...i) { return i.map(function (n) { return ${DICTIONARY_NAME}[n]; }).join(' '); }\n` +
        `function ${REPEAT_NAME}(n, c) { return Array(c).fill(n); }\n`;
}

/**
 * The whole two-phase transform over file CONTENTS.
 *
 * @param {Array<{label: string, content: string}>} eligible - in output order
 * @param {Array<{label: string, content: string}>} others - the bundle's other app files (scanned only)
 * @param {string} vendor_content - the bundle's vendor output (scanned only)
 * @param {string} bundle - named in failures
 * @returns {{helper: string|null, outputs: Array<string|null>, converted: number, words: number}}
 *   outputs[i] is the rewritten content of eligible[i], or null when nothing in it converted.
 */
function transform(eligible, others, vendor_content, bundle) {
    for (const other of others) {
        assert_no_token_collision(other.content, bundle, 'app', other.label);
    }
    if (vendor_content) {
        assert_no_token_collision(vendor_content, bundle, 'vendor', 'vendor output');
    }

    // Phase 1: every eligible file's edits, and the bundle's one word set.
    const per_file = eligible.map((file) => collect_edits(file.content, bundle, file.label));
    const word_set = new Set();
    let converted = 0;
    for (const edits of per_file) {
        for (const edit of string_edits(edits)) {
            converted++;
            for (const word of edit.value.split(' ')) {
                word_set.add(word);
            }
        }
    }

    // Phase 2: one dictionary, every file rewritten against it. A file whose only edits are
    // removed logging statements is still rewritten, with no helper needed for it.
    const { words, index } = build_dictionary(word_set);
    const outputs = eligible.map((file, i) => (per_file[i].length === 0 ? null : apply_edits(file.content, per_file[i], index)));

    return { helper: converted === 0 ? null : helper_source(words), outputs, converted, words: converted === 0 ? 0 : words.length };
}

/**
 * The file-level request: read the files, transform, write the helper and the rewritten
 * copies into output_dir (cleared first).
 */
function obfuscate_files(request) {
    const bundle = request.bundle || 'unknown';
    const read = (file) => ({ label: file, content: fs.readFileSync(file, 'utf8') });

    const eligible = (request.eligible || []).map(read);
    const others = (request.others || []).map(read);
    const vendor_content = request.vendor_path && fs.existsSync(request.vendor_path)
        ? fs.readFileSync(request.vendor_path, 'utf8')
        : '';

    const result = transform(eligible, others, vendor_content, bundle);

    const output_dir = request.output_dir;
    fs.rmSync(output_dir, { recursive: true, force: true });

    const files = {};
    let helper_path = null;

    if (result.helper !== null) {
        fs.mkdirSync(output_dir, { recursive: true });

        helper_path = path.join(output_dir, 'string_dictionary.js');
        fs.writeFileSync(helper_path, result.helper);

        result.outputs.forEach((content, i) => {
            if (content === null) {
                return;
            }
            const copy = path.join(output_dir, `${String(i).padStart(5, '0')}_${path.basename(eligible[i].label)}`);
            fs.writeFileSync(copy, content);
            files[eligible[i].label] = copy;
        });
    }

    return {
        helper_path,
        files,
        eligible: eligible.length,
        rewritten: Object.keys(files).length,
        converted: result.converted,
        words: result.words
    };
}


// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {bundle, eligible: [path...], others: [path...], vendor_path, output_dir}
     *   -> {status:'success', helper_path, files: {path: copy}, eligible, rewritten, converted, words}
     *    | {status:'error', error:{type, message}}
     */
    async obfuscate(request) {
        try {
            return {
                status: 'success',
                ...obfuscate_files(request)
            };
        } catch (error) {
            return {
                status: 'error',
                error: {
                    type: error.constructor.name,
                    message: error.message
                }
            };
        }
    },

    // The pure transform over contents, for in-process callers (scratch measurement).
    _transform: transform
};
