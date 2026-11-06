/**
 * QUALITY subsystem of the node service (prefix `quality`).
 *
 * JavaScript linting (Babel parser), this-usage analysis (Acorn), raw-HTML-write detection,
 * breadcrumb-origin facts, raw navigation and code-opened tabs (Babel parser) for rsx:check
 * and the manifest-time rules.
 *
 * Methods: quality.lint, quality.analyze_this, quality.analyze_raw_html, quality.analyze_via,
 *          quality.analyze_navigation, quality.analyze_new_tab
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const fs = require('fs');
const path = require('path');

// Resolve to system/node_modules since that's where packages are installed
const systemDir = path.resolve(__dirname, '../../../../..');
const babelParser = require(path.join(systemDir, 'node_modules', '@babel', 'parser'));
const acorn = require(path.join(systemDir, 'node_modules', 'acorn'));
const walk = require(path.join(systemDir, 'node_modules', 'acorn-walk'));

// =============================================================================
// LINTING LOGIC (Babel Parser)
// =============================================================================

/**
 * Lint JavaScript file for syntax errors using Babel parser
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {object|null} Error object or null if no errors
 */
function lintFile(content, filePath) {
    try {
        babelParser.parse(content, {
            sourceType: 'module',
            plugins: [
                'decorators-legacy',
                'classProperties',
                'classPrivateProperties',
                'classPrivateMethods',
                'optionalChaining',
                'nullishCoalescingOperator',
                'asyncGenerators',
                'bigInt',
                'dynamicImport',
                'exportDefaultFrom',
                'exportNamespaceFrom',
                'objectRestSpread',
                'topLevelAwait'
            ]
        });

        // No syntax errors
        return null;

    } catch (error) {
        return {
            message: error.message,
            line: error.loc ? error.loc.line : null,
            column: error.loc ? error.loc.column : null
        };
    }
}

// =============================================================================
// THIS-USAGE ANALYSIS LOGIC (Acorn Parser)
// =============================================================================

// Known jQuery callback methods - used for better remediation messages
const JQUERY_CALLBACKS = new Set([
    'click', 'dblclick', 'mouseenter', 'mouseleave', 'mousedown', 'mouseup',
    'mousemove', 'mouseover', 'mouseout', 'change', 'submit', 'focus', 'blur',
    'keydown', 'keyup', 'keypress', 'resize', 'scroll', 'select', 'load',
    'on', 'off', 'one', 'each', 'map', 'filter',
    'fadeIn', 'fadeOut', 'slideDown', 'slideUp', 'animate',
    'done', 'fail', 'always', 'then', 'ready', 'hover'
]);

/**
 * Analyze JavaScript file for 'this' usage violations
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {object} Result with violations array or error
 */
function analyzeThisUsage(content, filePath) {
    const lines = content.split('\n');

    let ast;
    try {
        ast = acorn.parse(content, {
            ecmaVersion: 2020,
            sourceType: 'module',
            locations: true
        });
    } catch (e) {
        // Parse error - return empty violations
        return { violations: [], error: `Parse error: ${e.message}` };
    }

    const violations = [];
    const classInfo = new Map();

    // First pass: identify all classes and their types
    walk.simple(ast, {
        ClassDeclaration(node) {
            const hasStaticInit = node.body.body.some(member =>
                member.static && member.key?.name === 'init'
            );
            classInfo.set(node.id.name, {
                isStatic: hasStaticInit
            });
        }
    });

    // Helper to check if first line of function has valid pattern
    function checkFirstLinePattern(funcNode) {
        if (!funcNode.body || !funcNode.body.body || funcNode.body.body.length === 0) {
            return null;
        }

        let checkIndex = 0;
        const firstStmt = funcNode.body.body[0];

        // Check if first statement is e.preventDefault() or similar
        if (firstStmt.type === 'ExpressionStatement' &&
            firstStmt.expression?.type === 'CallExpression' &&
            firstStmt.expression?.callee?.type === 'MemberExpression' &&
            firstStmt.expression?.callee?.property?.name === 'preventDefault') {
            checkIndex = 1;
            if (funcNode.body.body.length <= 1) {
                return null;
            }
        }

        const targetStmt = funcNode.body.body[checkIndex];
        if (targetStmt.type !== 'VariableDeclaration') {
            return null;
        }

        const firstDecl = targetStmt.declarations[0];
        if (!firstDecl || !firstDecl.init) {
            return null;
        }

        const varKind = targetStmt.kind;

        // Check for 'that = this' pattern
        if (firstDecl.id.name === 'that' &&
            firstDecl.init.type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'that-pattern-wrong-kind';
            }
            return 'that-pattern';
        }

        // Check for 'CurrentClass = this' pattern
        if (firstDecl.id.name === 'CurrentClass' &&
            firstDecl.init.type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'currentclass-pattern-wrong-kind';
            }
            return 'currentclass-pattern';
        }

        // Check for '$var = $(this)' pattern
        if (firstDecl.id.name.startsWith('$') &&
            firstDecl.init.type === 'CallExpression' &&
            firstDecl.init.callee.name === '$' &&
            firstDecl.init.arguments.length === 1 &&
            firstDecl.init.arguments[0].type === 'ThisExpression') {
            if (varKind !== 'const') {
                return 'jquery-pattern-wrong-kind';
            }
            return 'jquery-pattern';
        }

        return null;
    }

    // Helper to detect if we're in a jQuery callback
    function isLikelyJQueryCallback(ancestors) {
        for (let i = ancestors.length - 1; i >= 0; i--) {
            const node = ancestors[i];
            if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression') {
                const methodName = node.callee.property.name;
                if (JQUERY_CALLBACKS.has(methodName)) {
                    return true;
                }
            }
        }
        return false;
    }

    // Walk the AST looking for 'this' usage
    walk.ancestor(ast, {
        ThisExpression(node, ancestors) {
            // Skip arrow functions - they inherit 'this'
            for (const ancestor of ancestors) {
                if (ancestor.type === 'ArrowFunctionExpression') {
                    return;
                }
            }

            // Find containing function and class
            let containingFunc = null;
            let containingClass = null;
            let isAnonymousFunc = false;
            let isStaticMethod = false;
            let isConstructor = false;
            let isInstanceMethod = false;
            let hasMethodDefinition = false;

            // First pass: check if we're in a MethodDefinition
            for (let i = ancestors.length - 1; i >= 0; i--) {
                const ancestor = ancestors[i];
                if (ancestor.type === 'MethodDefinition') {
                    hasMethodDefinition = true;
                    isStaticMethod = ancestor.static;
                    isConstructor = ancestor.kind === 'constructor';
                    isInstanceMethod = !ancestor.static && ancestor.kind !== 'constructor';
                    break;
                }
            }

            // Second pass: find function and class
            for (let i = ancestors.length - 1; i >= 0; i--) {
                const ancestor = ancestors[i];

                if (!containingFunc && (
                    ancestor.type === 'FunctionExpression' ||
                    ancestor.type === 'FunctionDeclaration'
                )) {
                    containingFunc = ancestor;
                    isAnonymousFunc = ancestor.type === 'FunctionExpression' && !hasMethodDefinition;
                }

                if (!containingClass && (
                    ancestor.type === 'ClassDeclaration' ||
                    ancestor.type === 'ClassExpression'
                )) {
                    containingClass = ancestor;
                }
            }

            if (!containingFunc) {
                return;
            }

            // Skip constructors and instance methods
            if (isConstructor || isInstanceMethod) {
                return;
            }

            // Check if this is part of the allowed first-line pattern
            const parent = ancestors[ancestors.length - 2];
            const firstStmt = containingFunc.body?.body?.[0];
            let checkIndex = 0;

            const hasPreventDefault = firstStmt?.type === 'ExpressionStatement' &&
                firstStmt?.expression?.type === 'CallExpression' &&
                firstStmt?.expression?.callee?.type === 'MemberExpression' &&
                firstStmt?.expression?.callee?.property?.name === 'preventDefault';

            if (hasPreventDefault) {
                checkIndex = 1;
            }

            const targetStmt = containingFunc.body?.body?.[checkIndex];
            const isTargetConst = targetStmt?.type === 'VariableDeclaration' && targetStmt?.kind === 'const';

            // Check if this 'this' is inside $(this) on the first or second line
            if (parent && parent.type === 'CallExpression' && parent.callee?.name === '$') {
                if (isTargetConst &&
                    targetStmt?.declarations?.[0]?.init === parent &&
                    targetStmt?.declarations?.[0]?.id?.name?.startsWith('$')) {
                    return;
                }
            }

            // Check if this 'this' is the 'const that = this' in correct position
            if (parent && parent.type === 'VariableDeclarator' &&
                parent.id?.name === 'that' &&
                isTargetConst &&
                targetStmt?.declarations?.[0]?.id?.name === 'that') {
                return;
            }

            // Check if this 'this' is the 'const CurrentClass = this' in correct position
            if (parent && parent.type === 'VariableDeclarator' &&
                parent.id?.name === 'CurrentClass' &&
                isTargetConst &&
                targetStmt?.declarations?.[0]?.id?.name === 'CurrentClass') {
                return;
            }

            // Check what pattern is used
            const pattern = checkFirstLinePattern(containingFunc);

            // Determine the violation and remediation
            let message = '';
            let remediation = '';
            const lineNum = node.loc.start.line;
            const codeSnippet = lines[lineNum - 1].trim();
            const className = containingClass?.id?.name || 'unknown';
            const isJQueryContext = isLikelyJQueryCallback(ancestors);

            if (isAnonymousFunc) {
                if (!pattern) {
                    const firstStmt = containingFunc.body?.body?.[0];
                    const hasPreventDefault = firstStmt?.type === 'ExpressionStatement' &&
                        firstStmt?.expression?.type === 'CallExpression' &&
                        firstStmt?.expression?.callee?.type === 'MemberExpression' &&
                        firstStmt?.expression?.callee?.property?.name === 'preventDefault';

                    if (isJQueryContext) {
                        message = `'this' in jQuery callback should be aliased for clarity.`;
                        if (hasPreventDefault) {
                            remediation = `Add 'const $element = $(this);' as the second line (after preventDefault), then use $element instead of 'this'.`;
                        } else {
                            remediation = `Add 'const $element = $(this);' as the first line of this function, then use $element instead of 'this'.`;
                        }
                    } else {
                        message = `Ambiguous 'this' usage in anonymous function.`;
                        if (hasPreventDefault) {
                            remediation = `If this is a jQuery callback: Add 'const $element = $(this);' as the second line (after preventDefault).\n` +
                                       `If this is an instance context: Add 'const that = this;' as the second line.\n` +
                                       `Then use the aliased variable instead of 'this'.`;
                        } else {
                            remediation = `If this is a jQuery callback: Add 'const $element = $(this);' as first line.\n` +
                                       `If this is an instance context: Add 'const that = this;' as first line.\n` +
                                       `Then use the aliased variable instead of 'this'.`;
                        }
                    }
                } else if (pattern === 'that-pattern' || pattern === 'jquery-pattern') {
                    message = `'this' used after aliasing. Use the aliased variable instead.`;
                    let varDeclIndex = 0;
                    const firstStmt = containingFunc.body?.body?.[0];
                    if (firstStmt?.type === 'ExpressionStatement' &&
                        firstStmt?.expression?.callee?.property?.name === 'preventDefault') {
                        varDeclIndex = 1;
                    }
                    const varName = containingFunc.body?.body?.[varDeclIndex]?.declarations?.[0]?.id?.name;
                    remediation = pattern === 'jquery-pattern'
                        ? `You already have 'const ${varName} = $(this)'. Use that variable instead of 'this'.`
                        : `You already have 'const that = this'. Use 'that' instead of 'this'.`;
                } else if (pattern === 'that-pattern-wrong-kind') {
                    message = `Instance alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const that = this;' - the instance reference should never be reassigned.`;
                } else if (pattern === 'jquery-pattern-wrong-kind') {
                    message = `jQuery element alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const $element = $(this);' - jQuery element references should never be reassigned.`;
                }
            } else if (isStaticMethod) {
                if (!pattern) {
                    message = `Static method in '${className}' should not use naked 'this'.`;
                    remediation = `Static methods have two options:\n` +
                                 `1. If you need the exact class (no polymorphism): Replace 'this' with '${className}'\n` +
                                 `2. If you need polymorphism (inherited classes): Add 'const CurrentClass = this;' as first line\n` +
                                 `   Then use CurrentClass.name or CurrentClass.property for polymorphic access.\n` +
                                 `Consider: Does this.name need to work for child classes? If yes, use CurrentClass pattern.`;
                } else if (pattern === 'currentclass-pattern') {
                    message = `'this' used after aliasing to CurrentClass. Use 'CurrentClass' instead.`;
                    remediation = `You already have 'const CurrentClass = this;'. Use 'CurrentClass' for all access, not naked 'this'.`;
                } else if (pattern === 'currentclass-pattern-wrong-kind') {
                    message = `CurrentClass pattern must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const CurrentClass = this;' - the CurrentClass reference should never be reassigned.`;
                } else if (pattern === 'jquery-pattern') {
                    return;
                } else if (pattern === 'jquery-pattern-wrong-kind') {
                    message = `jQuery element alias must use 'const', not 'let' or 'var'.`;
                    remediation = `Change to 'const $element = $(this);' - jQuery element references should never be reassigned.`;
                }

                if (isAnonymousFunc && !pattern) {
                    remediation += `\nException: If this is a jQuery callback, add 'const $element = $(this);' as the first line.`;
                }
            }

            if (message) {
                violations.push({
                    line: lineNum,
                    message: message,
                    codeSnippet: codeSnippet,
                    remediation: remediation
                });
            }
        }
    });

    return { violations: violations };
}

// =============================================================================
// RAW-HTML-WRITE ANALYSIS (Babel Parser) - JS-RAW-HTML-01
// =============================================================================

// Calls whose result is HTML-safe by construction: each turns plain text (or untrusted
// rich text) into markup that is safe to insert.
const SAFE_HTML_CALLS = new Set(['escape_html', 'sanitize_rich_text_html', 'nl2brhtml']);

/**
 * The name of a member expression's property, computed string keys included.
 * @returns {string|null}
 */
function memberPropertyName(node) {
    if (!node || (node.type !== 'MemberExpression' && node.type !== 'OptionalMemberExpression')) {
        return null;
    }
    if (!node.computed && node.property.type === 'Identifier') {
        return node.property.name;
    }
    if (node.computed && node.property.type === 'StringLiteral') {
        return node.property.value;
    }
    return null;
}

/**
 * Is this expression markup the author fully controls or produced by a safe call?
 *
 * Allowed: a string literal; a template literal whose every ${} part is itself allowed;
 * escape_html(...), sanitize_rich_text_html(...), nl2brhtml(...), nl2br(escape_html(...));
 * a + concatenation of allowed parts.
 */
function isSafeHtmlExpression(node) {
    if (!node) {
        return false;
    }
    switch (node.type) {
        case 'StringLiteral':
            return true;
        case 'TemplateLiteral':
            return node.expressions.every(isSafeHtmlExpression);
        case 'BinaryExpression':
            return node.operator === '+' && isSafeHtmlExpression(node.left) && isSafeHtmlExpression(node.right);
        case 'CallExpression': {
            if (node.callee.type !== 'Identifier') {
                return false;
            }
            if (SAFE_HTML_CALLS.has(node.callee.name)) {
                return true;
            }
            if (node.callee.name === 'nl2br' && node.arguments.length === 1) {
                const inner = node.arguments[0];
                return inner.type === 'CallExpression'
                    && inner.callee.type === 'Identifier'
                    && inner.callee.name === 'escape_html';
            }
            return false;
        }
        default:
            return false;
    }
}

/** An empty string written as a literal: '', "" or an empty template. */
function isEmptyStringLiteral(node) {
    if (node.type === 'StringLiteral') {
        return node.value === '';
    }
    return node.type === 'TemplateLiteral'
        && node.expressions.length === 0
        && node.quasis.every((quasi) => quasi.value.cooked === '');
}

/**
 * Find every write of a non-literal HTML string into the DOM.
 *
 * @param {string} content - File content
 * @returns {object} {violations: [{line, kind}], error}
 *   kind: 'html' (.html(x)), 'html_empty' (.html('')), 'inner_html', 'outer_html',
 *         'insert_adjacent_html', 'document_write'
 */
function analyzeRawHtml(content) {
    let ast;
    try {
        ast = babelParser.parse(content, {
            sourceType: 'module',
            errorRecovery: true,
            plugins: [
                'decorators-legacy',
                'classProperties',
                'classPrivateProperties',
                'classPrivateMethods',
                'optionalChaining',
                'nullishCoalescingOperator',
                'asyncGenerators',
                'bigInt',
                'dynamicImport',
                'exportDefaultFrom',
                'exportNamespaceFrom',
                'objectRestSpread',
                'topLevelAwait'
            ]
        });
    } catch (e) {
        // A file that does not parse is reported by quality.lint; nothing to analyze here.
        return { violations: [], error: `Parse error: ${e.message}` };
    }

    const violations = [];

    const report = (node, kind) => {
        violations.push({ line: node.loc.start.line, kind });
    };

    const visit = (node) => {
        if (!node || typeof node.type !== 'string') {
            return;
        }

        if (node.type === 'CallExpression' || node.type === 'OptionalCallExpression') {
            const callee = node.callee;
            const name = memberPropertyName(callee);

            if (name === 'html' && node.arguments.length === 1) {
                const arg = node.arguments[0];
                if (isEmptyStringLiteral(arg)) {
                    report(callee.property, 'html_empty');
                } else if (!isSafeHtmlExpression(arg)) {
                    report(callee.property, 'html');
                }
            } else if (name === 'insertAdjacentHTML' && node.arguments.length >= 2) {
                if (!isSafeHtmlExpression(node.arguments[1])) {
                    report(callee.property, 'insert_adjacent_html');
                }
            } else if ((name === 'write' || name === 'writeln')
                && callee.object.type === 'Identifier' && callee.object.name === 'document') {
                if (!node.arguments.every(isSafeHtmlExpression)) {
                    report(callee.property, 'document_write');
                }
            }
        } else if (node.type === 'AssignmentExpression') {
            const name = memberPropertyName(node.left);
            if ((name === 'innerHTML' || name === 'outerHTML') && !isSafeHtmlExpression(node.right)) {
                report(node.left.property, name === 'innerHTML' ? 'inner_html' : 'outer_html');
            }
        }

        for (const key of Object.keys(node)) {
            if (key === 'loc' || key === 'start' || key === 'end' || key === 'extra'
                || key === 'leadingComments' || key === 'trailingComments' || key === 'innerComments') {
                continue;
            }
            const child = node[key];
            if (Array.isArray(child)) {
                for (const item of child) {
                    visit(item);
                }
            } else if (child && typeof child === 'object') {
                visit(child);
            }
        }
    };

    visit(ast.program);

    return { violations };
}

// =============================================================================
// BREADCRUMB ORIGINS (SPA-VIA-01)
// =============================================================================

const VIA_TYPE_PATTERN = /^[a-z][a-z0-9_]*$/;

/** Is this node the expression `args` or `<anything>.args`? */
function isArgsExpression(node) {
    if (!node) {
        return false;
    }
    if (node.type === 'Identifier') {
        return node.name === 'args';
    }
    return memberPropertyName(node) === 'args';
}

/** Does a route pattern declare a `:_via` segment? */
function routeDeclaresVia(pattern) {
    return pattern.split('/').some((segment) => segment.replace(/\?$/, '') === ':_via');
}

/**
 * Everything SPA-VIA-01 judges in one JavaScript file.
 *
 * @param {string} content - File content
 * @returns {object} {classes: [{name, line, via, hook}], args_reads: [line], routes: [{line, pattern}], error}
 *   via:  null, or {line, entries: string[]|null (null = not an array literal of strings)}
 *   hook: null, or {line, is_static}
 */
function analyzeVia(content) {
    let ast;
    try {
        ast = babelParser.parse(content, {
            sourceType: 'module',
            errorRecovery: true,
            plugins: [
                'decorators-legacy',
                'classProperties',
                'classPrivateProperties',
                'classPrivateMethods',
                'optionalChaining',
                'nullishCoalescingOperator',
                'asyncGenerators',
                'bigInt',
                'dynamicImport',
                'exportDefaultFrom',
                'exportNamespaceFrom',
                'objectRestSpread',
                'topLevelAwait'
            ]
        });
    } catch (e) {
        // A file that does not parse is reported by quality.lint; nothing to analyze here.
        return { classes: [], args_reads: [], routes: [], error: `Parse error: ${e.message}` };
    }

    const classes = [];
    const args_reads = [];
    const routes = [];

    const visit = (node) => {
        if (!node || typeof node.type !== 'string') {
            return;
        }

        if (node.type === 'ClassDeclaration' || node.type === 'ClassExpression') {
            const info = {
                name: node.id ? node.id.name : '(anonymous)',
                line: node.loc.start.line,
                via: null,
                hook: null,
            };

            for (const decorator of node.decorators || []) {
                const expr = decorator.expression;
                if (expr && expr.type === 'CallExpression' && expr.callee.type === 'Identifier'
                    && expr.callee.name === 'route' && expr.arguments.length
                    && expr.arguments[0].type === 'StringLiteral' && routeDeclaresVia(expr.arguments[0].value)) {
                    routes.push({ line: decorator.loc.start.line, pattern: expr.arguments[0].value });
                }
            }

            for (const member of node.body.body) {
                const key = member.key && !member.computed && member.key.type === 'Identifier' ? member.key.name : null;

                if (key === 'breadcrumb_via' && (member.type === 'ClassProperty' || member.type === 'ClassAccessorProperty') && member.static) {
                    let entries = null;
                    const value = member.value;
                    if (value && value.type === 'ArrayExpression'
                        && value.elements.every((el) => el && el.type === 'StringLiteral')) {
                        entries = value.elements.map((el) => el.value);
                    }
                    info.via = { line: member.loc.start.line, entries: entries };
                }

                if (key === 'breadcrumb_parent_via' && (member.type === 'ClassMethod' || member.type === 'ClassProperty')) {
                    info.hook = { line: member.loc.start.line, is_static: !!member.static };
                }
            }

            if (info.via || info.hook) {
                classes.push(info);
            }
        }

        if (node.type === 'MemberExpression' || node.type === 'OptionalMemberExpression') {
            if (memberPropertyName(node) === '_via' && isArgsExpression(node.object)) {
                args_reads.push(node.loc.start.line);
            }
        }

        if (node.type === 'VariableDeclarator' && node.id && node.id.type === 'ObjectPattern' && isArgsExpression(node.init)) {
            for (const prop of node.id.properties) {
                if (prop.type === 'ObjectProperty' && !prop.computed
                    && ((prop.key.type === 'Identifier' && prop.key.name === '_via')
                        || (prop.key.type === 'StringLiteral' && prop.key.value === '_via'))) {
                    args_reads.push(prop.loc.start.line);
                }
            }
        }

        for (const key of Object.keys(node)) {
            if (key === 'loc' || key === 'start' || key === 'end' || key === 'extra'
                || key === 'leadingComments' || key === 'trailingComments' || key === 'innerComments') {
                continue;
            }
            const child = node[key];
            if (Array.isArray(child)) {
                for (const item of child) {
                    visit(item);
                }
            } else if (child && typeof child === 'object') {
                visit(child);
            }
        }
    };

    visit(ast.program);

    for (const info of classes) {
        if (info.via && info.via.entries) {
            info.via.invalid = info.via.entries.filter((entry) => !VIA_TYPE_PATTERN.test(entry));
        }
    }

    return { classes, args_reads, routes };
}

// =============================================================================
// NAVIGATION AND NEW-TAB ANALYSIS (Babel Parser) - JS-NAVIGATE-01, JS-NEW-TAB-01
// =============================================================================
//
// Both analyses work on one parse with no scope analysis. A name is treated as the
// browser global only when the FILE declares nothing of that name (a variable, a
// parameter, a function, a class, an import, a catch binding) - file-wide rather than
// per scope, which can only make a finding go away, never invent one.

const NAVIGATION_PARSE_PLUGINS = [
    'decorators-legacy',
    'classProperties',
    'classPrivateProperties',
    'classPrivateMethods',
    'optionalChaining',
    'nullishCoalescingOperator',
    'asyncGenerators',
    'bigInt',
    'dynamicImport',
    'exportDefaultFrom',
    'exportNamespaceFrom',
    'objectRestSpread',
    'topLevelAwait'
];

// Objects whose `location` property IS the page's location.
const GLOBAL_RECEIVERS = new Set(['window', 'document', 'self', 'top', 'parent', 'globalThis']);

// Objects whose `open()` is window.open (document.open() is the document stream - not a tab).
const WINDOW_RECEIVERS = new Set(['window', 'self', 'top', 'parent', 'globalThis']);

// A location property whose assignment navigates.
const LOCATION_PARTS = new Set(['href', 'search', 'pathname', 'host', 'hostname', 'port', 'protocol', 'hash']);

// Function-like nodes: the unit of "the same function" for the target-blank heuristic.
const FUNCTION_TYPES = new Set([
    'FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression',
    'ClassMethod', 'ClassPrivateMethod', 'ObjectMethod'
]);

const AST_META_KEYS = new Set(['loc', 'start', 'end', 'extra', 'leadingComments', 'trailingComments', 'innerComments']);

/**
 * Call fn(node) on $root and every node below it. When $stop_at_functions is set, a
 * function node below the root is visited itself but not entered.
 */
function visitNodes(root, fn, stop_at_functions = false) {
    const visit = (node, is_root) => {
        if (!node || typeof node.type !== 'string') {
            return;
        }
        fn(node);
        if (stop_at_functions && !is_root && FUNCTION_TYPES.has(node.type)) {
            return;
        }
        for (const key of Object.keys(node)) {
            if (AST_META_KEYS.has(key)) {
                continue;
            }
            const child = node[key];
            if (Array.isArray(child)) {
                for (const item of child) {
                    visit(item, false);
                }
            } else if (child && typeof child === 'object') {
                visit(child, false);
            }
        }
    };
    visit(root, true);
}

function parseRecovering(content) {
    return babelParser.parse(content, {
        sourceType: 'module',
        errorRecovery: true,
        plugins: NAVIGATION_PARSE_PLUGINS
    });
}

/**
 * Every name the file binds anywhere (file-wide, not per scope).
 */
function declaredNames(ast) {
    const names = new Set();
    const addPattern = (pattern) => {
        if (!pattern) {
            return;
        }
        switch (pattern.type) {
            case 'Identifier':
                names.add(pattern.name);
                break;
            case 'ObjectPattern':
                for (const prop of pattern.properties) {
                    addPattern(prop.type === 'RestElement' ? prop.argument : prop.value);
                }
                break;
            case 'ArrayPattern':
                for (const element of pattern.elements) {
                    addPattern(element);
                }
                break;
            case 'AssignmentPattern':
                addPattern(pattern.left);
                break;
            case 'RestElement':
                addPattern(pattern.argument);
                break;
        }
    };

    visitNodes(ast.program, (node) => {
        if (node.type === 'VariableDeclarator') {
            addPattern(node.id);
        } else if (FUNCTION_TYPES.has(node.type)) {
            if (node.type === 'FunctionDeclaration' || node.type === 'FunctionExpression') {
                addPattern(node.id);
            }
            for (const param of node.params || []) {
                addPattern(param);
            }
        } else if (node.type === 'ClassDeclaration' || node.type === 'ClassExpression') {
            addPattern(node.id);
        } else if (node.type === 'ImportSpecifier' || node.type === 'ImportDefaultSpecifier'
            || node.type === 'ImportNamespaceSpecifier') {
            addPattern(node.local);
        } else if (node.type === 'CatchClause') {
            addPattern(node.param);
        }
    });

    return names;
}

function isMember(node) {
    return !!node && (node.type === 'MemberExpression' || node.type === 'OptionalMemberExpression');
}

function isCall(node) {
    return !!node && (node.type === 'CallExpression' || node.type === 'OptionalCallExpression');
}

function isGlobalName(node, names, declared) {
    return !!node && node.type === 'Identifier' && names.has(node.name) && !declared.has(node.name);
}

function stringValue(node) {
    if (!node) {
        return null;
    }
    if (node.type === 'StringLiteral') {
        return node.value;
    }
    if (node.type === 'TemplateLiteral' && node.expressions.length === 0 && node.quasis.length === 1) {
        return node.quasis[0].value.cooked;
    }
    return null;
}

/**
 * The source text of a node on one line, shortened - what a finding names.
 */
function nodeText(content, node) {
    const text = content.slice(node.start, node.end).replace(/\s+/g, ' ').trim();
    return text.length > 90 ? text.slice(0, 87) + '...' : text;
}

/**
 * window / document / self / top / parent / globalThis, and chains of them
 * (window.top, window.parent.document).
 */
function isGlobalReceiver(node, declared) {
    if (isGlobalName(node, GLOBAL_RECEIVERS, declared)) {
        return true;
    }
    return isMember(node) && GLOBAL_RECEIVERS.has(memberPropertyName(node)) && isGlobalReceiver(node.object, declared);
}

function isWindowReceiver(node, declared) {
    if (isGlobalName(node, WINDOW_RECEIVERS, declared)) {
        return true;
    }
    return isMember(node) && WINDOW_RECEIVERS.has(memberPropertyName(node)) && isWindowReceiver(node.object, declared);
}

/**
 * The page's location object itself: bare `location` (when the file binds no such name),
 * `<global receiver>.location` / `['location']`, or a variable that was given one.
 */
function isLocationObject(node, declared, aliases) {
    if (!node) {
        return false;
    }
    if (node.type === 'Identifier') {
        return (node.name === 'location' && !declared.has('location')) || aliases.has(node.name);
    }
    return isMember(node) && memberPropertyName(node) === 'location' && isGlobalReceiver(node.object, declared);
}

/**
 * The page's history object: bare `history` or `<global receiver>.history`.
 */
function isHistoryObject(node, declared) {
    if (node && node.type === 'Identifier') {
        return node.name === 'history' && !declared.has('history');
    }
    return isMember(node) && memberPropertyName(node) === 'history' && isGlobalReceiver(node.object, declared);
}

/**
 * Everything JS-NAVIGATE-01 judges in one JavaScript file.
 *
 * @param {string} content - File content
 * @returns {object} {findings: [{line, kind, detail}], error}
 *   kind: location_write | location_alias | location_call | jquery_location | history_write | spa_removed
 */
function analyzeNavigation(content) {
    let ast;
    try {
        ast = parseRecovering(content);
    } catch (e) {
        // A file that does not parse is reported by quality.lint; nothing to analyze here.
        return { findings: [], error: `Parse error: ${e.message}` };
    }

    const declared = declaredNames(ast);

    // Pass 1: names that were given the location object (const l = window.location). Run
    // to a fixed point, so `const m = l` is an alias too.
    const aliases = new Set();
    for (let changed = true; changed;) {
        changed = false;
        visitNodes(ast.program, (node) => {
            let name = null;
            if (node.type === 'VariableDeclarator' && node.id.type === 'Identifier' && isLocationObject(node.init, declared, aliases)) {
                name = node.id.name;
            } else if (node.type === 'AssignmentExpression' && node.operator === '=' && node.left.type === 'Identifier'
                && isLocationObject(node.right, declared, aliases)) {
                name = node.left.name;
            }
            if (name !== null && name !== 'location' && !aliases.has(name)) {
                aliases.add(name);
                changed = true;
            }
        });
    }

    const findings = [];
    const seen = new Set();
    const report = (node, kind) => {
        const key = node.loc.start.line + ':' + kind;
        if (!seen.has(key)) {
            seen.add(key);
            findings.push({ line: node.loc.start.line, kind: kind, detail: nodeText(content, node) });
        }
    };

    // The location object as an ASSIGNMENT TARGET - an alias variable being re-pointed is
    // not a navigation, so aliases count only as the object a property is written on.
    const isLocationTarget = (node) => {
        if (node.type === 'Identifier') {
            return node.name === 'location' && !declared.has('location');
        }
        return isMember(node) && memberPropertyName(node) === 'location' && isGlobalReceiver(node.object, declared);
    };

    visitNodes(ast.program, (node) => {
        if (node.type === 'AssignmentExpression') {
            const left = node.left;
            if (isLocationTarget(left)
                || (isMember(left) && LOCATION_PARTS.has(memberPropertyName(left)) && isLocationObject(left.object, declared, aliases))) {
                report(node, 'location_write');
                return;
            }
            // Destructuring out of location (`({hash} = location)`) is a READ, not an alias.
            if (node.operator === '=' && left.type !== 'ObjectPattern' && left.type !== 'ArrayPattern'
                && isLocationObject(node.right, declared, aliases)) {
                report(node, 'location_alias');
            }
            return;
        }

        if (node.type === 'VariableDeclarator' && node.id.type === 'Identifier' && node.init
            && isLocationObject(node.init, declared, aliases)) {
            report(node, 'location_alias');
            return;
        }

        if (!isCall(node) || !isMember(node.callee)) {
            return;
        }

        const method = memberPropertyName(node.callee);
        const receiver = node.callee.object;

        if ((method === 'assign' || method === 'replace') && isLocationObject(receiver, declared, aliases)) {
            report(node, 'location_call');
            return;
        }

        if ((method === 'attr' || method === 'prop') && node.arguments.length >= 2
            && ['href', 'location'].includes(stringValue(node.arguments[0]))
            && isCall(receiver) && receiver.callee.type === 'Identifier'
            && (receiver.callee.name === '$' || receiver.callee.name === 'jQuery')
            && receiver.arguments.length === 1
            && (isLocationObject(receiver.arguments[0], declared, aliases) || isGlobalReceiver(receiver.arguments[0], declared))) {
            report(node, 'jquery_location');
            return;
        }

        if ((method === 'pushState' || method === 'replaceState') && isHistoryObject(receiver, declared)) {
            report(node, 'history_write');
            return;
        }

        if ((method === 'dispatch' || method === 'redirect') && isGlobalName(receiver, new Set(['Spa']), declared)) {
            report(node, 'spa_removed');
        }
    });

    findings.sort((a, b) => a.line - b.line);

    return { findings };
}

/**
 * Is this node a string that is exactly `_blank`?
 */
function isBlankTarget(node) {
    return stringValue(node) === '_blank';
}

const TARGET_BLANK_MARKUP = /\btarget\s*=\s*["']?_blank/i;

/**
 * Is the call one that gives its receiver target="_blank"? `.attr('target', '_blank')`,
 * `.prop(...)`, `.attr({target: '_blank'})`, `.setAttribute('target', '_blank')`.
 */
function isBlankTargetCall(node) {
    if (!isCall(node) || !isMember(node.callee)) {
        return false;
    }
    const method = memberPropertyName(node.callee);
    const args = node.arguments;
    if (method === 'attr' || method === 'prop' || method === 'setAttribute') {
        if (args.length >= 2 && stringValue(args[0]) === 'target' && isBlankTarget(args[1])) {
            return true;
        }
        if ((method === 'attr' || method === 'prop') && args.length === 1 && args[0].type === 'ObjectExpression') {
            return args[0].properties.some(isBlankTargetProperty);
        }
    }
    return false;
}

function isBlankTargetProperty(prop) {
    if (prop.type !== 'ObjectProperty' || prop.computed) {
        return false;
    }
    const key = prop.key.type === 'Identifier' ? prop.key.name : stringValue(prop.key);
    return key === 'target' && isBlankTarget(prop.value);
}

/**
 * Does this expression (not entering functions) create or configure something with
 * target="_blank"? Markup with the attribute, an attrs object with it, or a call that sets it.
 */
function containsBlankTarget(node) {
    let found = false;
    visitNodes(node, (child) => {
        if (found) {
            return;
        }
        if (child.type === 'StringLiteral' && TARGET_BLANK_MARKUP.test(child.value)) {
            found = true;
        } else if (child.type === 'TemplateElement' && TARGET_BLANK_MARKUP.test(child.value.raw)) {
            found = true;
        } else if (child.type === 'ObjectProperty' && isBlankTargetProperty(child)) {
            found = true;
        } else if (isBlankTargetCall(child)) {
            found = true;
        }
    }, true);
    return found;
}

/**
 * A stable key for the element an expression names: `a`, `this.$link`, with indexing,
 * `.get(n)` / `.first()` / `.eq(n)` / `.last()` and a `$(x)` wrapper stepped through, so
 * `$link[0]`, `$link.get(0)` and `$(link)` all name the element their base names.
 */
function elementKey(node) {
    if (!node) {
        return null;
    }
    if (node.type === 'Identifier') {
        return node.name;
    }
    if (node.type === 'ThisExpression') {
        return 'this';
    }
    if (isMember(node)) {
        if (node.computed && node.property.type === 'NumericLiteral') {
            return elementKey(node.object);
        }
        const prop = memberPropertyName(node);
        const base = elementKey(node.object);
        return prop !== null && base !== null ? base + '.' + prop : null;
    }
    if (isCall(node)) {
        if (isMember(node.callee) && ['get', 'first', 'eq', 'last'].includes(memberPropertyName(node.callee))) {
            return elementKey(node.callee.object);
        }
        if (node.callee.type === 'Identifier' && (node.callee.name === '$' || node.callee.name === 'jQuery')
            && node.arguments.length === 1) {
            return elementKey(node.arguments[0]);
        }
    }
    return null;
}

/**
 * A call that activates an element from code: `.click()`, `.trigger('click')`,
 * `.triggerHandler('click')`, and for a form `.submit()`, `.requestSubmit()`,
 * `.trigger('submit')`. With an argument, `.click(fn)` / `.submit(fn)` BIND a handler and
 * are not activations.
 */
function isActivationCall(node) {
    if (!isCall(node) || !isMember(node.callee)) {
        return false;
    }
    const method = memberPropertyName(node.callee);
    const args = node.arguments;
    if (method === 'click' || method === 'submit' || method === 'requestSubmit') {
        return args.length === 0;
    }
    if (method === 'trigger' || method === 'triggerHandler') {
        return args.length >= 1 && ['click', 'submit'].includes(stringValue(args[0]));
    }
    return false;
}

function isSyntheticClickEvent(node) {
    return !!node && node.type === 'NewExpression' && node.callee.type === 'Identifier'
        && ['MouseEvent', 'Event', 'PointerEvent'].includes(node.callee.name)
        && node.arguments.length >= 1 && stringValue(node.arguments[0]) === 'click';
}

/**
 * Everything JS-NEW-TAB-01 judges in one JavaScript file.
 *
 * @param {string} content - File content
 * @returns {object} {findings: [{line, kind, detail}], error}
 *   kind: window_open | synthetic_click | blank_target_click
 */
function analyzeNewTab(content) {
    let ast;
    try {
        ast = parseRecovering(content);
    } catch (e) {
        return { findings: [], error: `Parse error: ${e.message}` };
    }

    const declared = declaredNames(ast);

    const findings = [];
    const seen = new Set();
    const report = (node, kind) => {
        const key = node.loc.start.line + ':' + kind;
        if (!seen.has(key)) {
            seen.add(key);
            findings.push({ line: node.loc.start.line, kind: kind, detail: nodeText(content, node) });
        }
    };

    // Names bound to a synthetic click event (const e = new MouseEvent('click')).
    const click_events = new Set();
    visitNodes(ast.program, (node) => {
        if (node.type === 'VariableDeclarator' && node.id.type === 'Identifier' && isSyntheticClickEvent(node.init)) {
            click_events.add(node.id.name);
        }
    });

    visitNodes(ast.program, (node) => {
        if (!isCall(node)) {
            return;
        }
        const callee = node.callee;

        if (callee.type === 'Identifier' && callee.name === 'open' && !declared.has('open')) {
            report(node, 'window_open');
            return;
        }
        if (isMember(callee) && memberPropertyName(callee) === 'open' && isWindowReceiver(callee.object, declared)) {
            report(node, 'window_open');
            return;
        }
        if (isMember(callee) && memberPropertyName(callee) === 'dispatchEvent' && node.arguments.length >= 1) {
            const event = node.arguments[0];
            if (isSyntheticClickEvent(event) || (event.type === 'Identifier' && click_events.has(event.name))) {
                report(node, 'synthetic_click');
            }
        }
    });

    // A code-activated anchor or form that, in the SAME function, was given target _blank.
    // Each function is judged with its nested callbacks; top-level code on its own.
    const judgeScope = (root, stop_at_functions) => {
        const blank_keys = new Set();
        const activations = [];

        visitNodes(root, (node) => {
            if (node.type === 'AssignmentExpression') {
                if (isMember(node.left) && memberPropertyName(node.left) === 'target' && isBlankTarget(node.right)) {
                    const key = elementKey(node.left.object);
                    if (key !== null) {
                        blank_keys.add(key);
                    }
                } else if (containsBlankTarget(node.right)) {
                    const key = elementKey(node.left);
                    if (key !== null) {
                        blank_keys.add(key);
                    }
                }
                return;
            }
            if (node.type === 'VariableDeclarator' && node.id.type === 'Identifier' && node.init && containsBlankTarget(node.init)) {
                blank_keys.add(node.id.name);
                return;
            }
            if (isBlankTargetCall(node)) {
                const key = elementKey(node.callee.object);
                if (key !== null) {
                    blank_keys.add(key);
                }
                return;
            }
            if (isActivationCall(node)) {
                activations.push(node);
            }
        }, stop_at_functions);

        for (const call of activations) {
            const receiver = call.callee.object;
            const key = elementKey(receiver);
            if ((key !== null && blank_keys.has(key)) || containsBlankTarget(receiver)) {
                report(call, 'blank_target_click');
            }
        }
    };

    judgeScope(ast.program, true);
    visitNodes(ast.program, (node) => {
        if (FUNCTION_TYPES.has(node.type)) {
            judgeScope(node, false);
        }
    });

    findings.sort((a, b) => a.line - b.line);

    return { findings };
}

// =============================================================================
// HANDLERS
// =============================================================================

/**
 * Run a findings analyzer ({findings, error}) over each requested file.
 */
function analyzeFindings(request, analyzer) {
    const results = {};

    for (const file of request.files) {
        try {
            const content = fs.readFileSync(file, 'utf8');
            const result = analyzer(content);
            results[file] = {
                status: 'success',
                findings: result.findings,
                error: result.error || null
            };
        } catch (error) {
            results[file] = {
                status: 'error',
                error: {
                    type: 'FileReadError',
                    message: error.message
                }
            };
        }
    }

    return { results };
}

module.exports = {
    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, error}}}
     */
    lint(request) {
        const results = {};

        for (const file of request.files) {
            try {
                const content = fs.readFileSync(file, 'utf8');
                const error = lintFile(content, file);
                results[file] = {
                    status: 'success',
                    error: error
                };
            } catch (error) {
                results[file] = {
                    status: 'error',
                    error: {
                        type: 'FileReadError',
                        message: error.message
                    }
                };
            }
        }

        return { results };
    },

    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, violations, error}}}
     */
    analyze_raw_html(request) {
        const results = {};

        for (const file of request.files) {
            try {
                const content = fs.readFileSync(file, 'utf8');
                const result = analyzeRawHtml(content);
                results[file] = {
                    status: 'success',
                    violations: result.violations,
                    error: result.error || null
                };
            } catch (error) {
                results[file] = {
                    status: 'error',
                    error: {
                        type: 'FileReadError',
                        message: error.message
                    }
                };
            }
        }

        return { results };
    },

    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, classes, args_reads, routes, error}}}
     */
    analyze_via(request) {
        const results = {};

        for (const file of request.files) {
            try {
                const content = fs.readFileSync(file, 'utf8');
                const result = analyzeVia(content);
                results[file] = {
                    status: 'success',
                    classes: result.classes,
                    args_reads: result.args_reads,
                    routes: result.routes,
                    error: result.error || null
                };
            } catch (error) {
                results[file] = {
                    status: 'error',
                    error: {
                        type: 'FileReadError',
                        message: error.message
                    }
                };
            }
        }

        return { results };
    },

    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, findings, error}}}
     */
    analyze_navigation(request) {
        return analyzeFindings(request, analyzeNavigation);
    },

    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, findings, error}}}
     */
    analyze_new_tab(request) {
        return analyzeFindings(request, analyzeNewTab);
    },

    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, violations, error}}}
     */
    analyze_this(request) {
        const results = {};

        for (const file of request.files) {
            try {
                const content = fs.readFileSync(file, 'utf8');
                const result = analyzeThisUsage(content, file);
                results[file] = {
                    status: 'success',
                    violations: result.violations,
                    error: result.error || null
                };
            } catch (error) {
                results[file] = {
                    status: 'error',
                    error: {
                        type: 'FileReadError',
                        message: error.message
                    }
                };
            }
        }

        return { results };
    }
};
