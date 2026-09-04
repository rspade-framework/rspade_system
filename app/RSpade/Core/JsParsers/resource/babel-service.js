/**
 * BABEL subsystem of the node service (prefix `babel`).
 *
 * Transforms modern JavaScript (decorators, class properties) into the browser-compatible
 * code RSpade concatenates into a shared-scope, non-module bundle. The transformation
 * logic below - the generated-name prefixing, the fail-closed decorator class-binding
 * assertion, the target presets - is the whole of the framework's transform pipeline.
 *
 * Methods: babel.transform
 *
 * The framework's js_transform tests require this file directly for its exported
 * internals (transformFileContent, createPrefixPlugin, targetPresets,
 * preprocessDecorators), which is why they are exported alongside the handler.
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const babel = require('@babel/core');

// =============================================================================
// SHARED TRANSFORMATION LOGIC
// =============================================================================

// Error helper for JSON output
function outputError(error, filePath, jsonOutput) {
    const errorObj = {
        status: 'error',
        error: {
            type: error.constructor.name,
            message: error.message,
            line: error.loc ? error.loc.line : null,
            column: error.loc ? error.loc.column : null,
            file: filePath,
            context: null,
            suggestion: null
        }
    };

    // Add suggestions for common errors
    if (error.message.includes('Cannot find module')) {
        errorObj.error.suggestion = 'Missing Babel dependencies. Run: npm install';
    } else if (error.message.includes('Unexpected token')) {
        errorObj.error.suggestion = 'Check JavaScript syntax in the source file';
    } else if (error.message.includes('decorator')) {
        errorObj.error.suggestion = 'Ensure decorators are properly formatted (e.g., @decorator before class/method)';
    }

    if (jsonOutput) {
        return errorObj;
    } else {
        return {
            status: 'error',
            message: `Transformation error: ${error.message}`
        };
    }
}

/**
 * Preprocessor to handle @decorator on standalone functions
 * Converts @decorator to decorator comment when no ES6 classes are present
 */
function preprocessDecorators(content, filePath) {
    // Check if file contains ES6 class declarations
    // Using regex to avoid parsing errors from decorators
    const es6ClassRegex = /^\s*class\s+[A-Z]\w*\s*(?:extends\s+\w+\s*)?\{/m;
    const hasES6Class = es6ClassRegex.test(content);

    if (hasES6Class) {
        // File has ES6 classes, leave @decorator syntax unchanged
        return content;
    }

    // No ES6 classes, convert @decorator to /** @decorator */
    // Match @decorator at the start of a line (with optional whitespace)
    // that appears before a function declaration
    const decoratorRegex = /^(\s*)@decorator\s*\n(\s*(?:async\s+)?function\s+\w+)/gm;

    const processed = content.replace(decoratorRegex, (match, indent, funcDecl) => {
        return `${indent}/** @decorator */\n${funcDecl}`;
    });

    return processed;
}

// Target environment presets
const targetPresets = {
    modern: {
        targets: {
            chrome: '90',
            firefox: '88',
            safari: '14',
            edge: '90'
        }
    },
    es6: {
        targets: {
            chrome: '60',
            firefox: '60',
            safari: '10.1',
            edge: '15'
        }
    },
    es5: {
        targets: {
            ie: '11'
        }
    }
};

/**
 * Create custom plugin to prefix generated WeakMap variables and Babel helper functions.
 * Runs AFTER all other transformations to catch Babel-generated helpers, and asserts the
 * decorator-binding contract that non-module bundle concatenation depends on.
 *
 * GENERATED-NAME PREFIXING
 * ========================
 * Babel emits underscore-prefixed top-level helpers/WeakMaps (e.g. `_applyDecs`,
 * `_classPrivateFieldGet`). RSpade concatenates every transformed file into one shared
 * non-module scope, so two files that both received a helper named `_applyDecs` would
 * collide. This plugin renames every top-level `_`-prefixed generated binding to
 * `_<fileHash><name>` so each file's helpers are unique in the shared scope. This behavior
 * is load-bearing and unrelated to decorators.
 *
 * DECORATOR CLASS-BINDING CONTRACT (fail-closed assertion)
 * ========================================================
 * Babel's decorator transform drops the module-scope name binding of a decorated class
 * DECLARATION that has static members: it renames the class to a truncated uid and replaces
 * the whole statement with a `new (...)()` expression, leaving no `Foo` binding. Because
 * RSpade bundles are shared-scope and non-module, other files / jqhtml templates / the SPA
 * registry reference decorated classes by bare name -- so that binding MUST survive.
 *
 * The binding is now emitted AT THE PRODUCER by the vendored fork
 * ./babel-plugin-decorators (see its README.md): the patched decorator transform emits
 * `var <OriginalName> = <uid>;` for exactly this case. This plugin therefore no longer
 * rewrites decorator output at all -- the previous output-shape matcher (which appended the
 * binding by pattern-matching `[_Foo, _initClass] = _applyDecs(...).c`) has been retired,
 * because @babel/compat-data 7.29.3 moved the destructuring baseline to Safari 14.1 and made
 * preset-env rewrite that shape before the matcher could see it (silent miss -> white screen;
 * es5 was always broken this way).
 *
 * What remains here is a CONTRACT check, not a shape check: record every top-level decorated
 * class declaration name before transform (pre), and after transform (post) fail loudly if
 * any such name lost its program-scope binding. This catches a future upstream regression or
 * a stale/unpatched fork at build time instead of shipping a ReferenceError to the browser.
 *
 * Upstream defect history (not fixed through Babel 8.0.1):
 * - https://github.com/babel/babel/issues/12689 (decorators + class fields)
 * - https://github.com/evanw/esbuild/issues/3823 (same IIFE pattern issue)
 */
function createPrefixPlugin(fileHash) {
    // Names of top-level decorated class DECLARATIONS seen in the pre-transform AST.
    // Reset per-file in pre(); read in post(). The transformer processes one file per
    // babel.transformSync call, so a plain closure set is correct here.
    let decoratedClassNames = new Set();

    // Collect the id name of a top-level statement if it is a decorated class declaration,
    // looking through export wrappers.
    function recordIfDecoratedClass(statement, target) {
        let decl = statement;
        if (statement.type === 'ExportNamedDeclaration' || statement.type === 'ExportDefaultDeclaration') {
            decl = statement.declaration;
        }
        if (!decl || decl.type !== 'ClassDeclaration') return;
        if (!decl.decorators || decl.decorators.length === 0) return;
        if (!decl.id || !decl.id.name) return; // anonymous default export -- no bare name to preserve
        target.add(decl.id.name);
    }

    return function() {
        return {
            name: 'prefix-generated-variables',
            pre(file) {
                decoratedClassNames = new Set();
                const body = file.ast.program.body;
                for (const statement of body) {
                    recordIfDecoratedClass(statement, decoratedClassNames);
                }
            },
            post(file) {
                // Run after all transformations are complete
                const program = file.path;

                // Track all top-level variables and functions that start with underscore
                const generatedNames = new Set();

                // First pass: collect all generated variable and function names at top level
                for (const statement of program.node.body) {
                    if (statement.type === 'VariableDeclaration') {
                        for (const declarator of statement.declarations) {
                            const name = declarator.id?.name;
                            if (name && name.startsWith('_')) {
                                generatedNames.add(name);
                            }
                        }
                    } else if (statement.type === 'FunctionDeclaration') {
                        const name = statement.id?.name;
                        if (name && name.startsWith('_')) {
                            generatedNames.add(name);
                        }
                    }
                }

                // Second pass: rename all references
                if (generatedNames.size > 0) {
                    program.traverse({
                        Identifier(idPath) {
                            if (generatedNames.has(idPath.node.name)) {
                                // Don't rename if it's already prefixed
                                if (!idPath.node.name.startsWith(`_${fileHash}`)) {
                                    const newName = `_${fileHash}${idPath.node.name}`;
                                    idPath.scope.rename(idPath.node.name, newName);
                                }
                            }
                        }
                    });
                }

                // FAIL-CLOSED: every decorated class declaration must still bind its bare name
                // in program scope (either the fork's emitted `var Name`, or a surviving class
                // declaration in the non-static / member-decorator / RETURNS-REPLACEMENT case).
                if (decoratedClassNames.size > 0) {
                    program.scope.crawl();
                    for (const className of decoratedClassNames) {
                        if (!program.scope.hasOwnBinding(className)) {
                            throw new Error(
                                `Decorator transform dropped the module-scope binding for class ` +
                                `"${className}" in ${file.opts.filename}. The vendored decorator ` +
                                `fork (resource/babel-plugin-decorators) must emit ` +
                                `"var ${className} = ...;" for a decorated class declaration with ` +
                                `static members; without it the class is unreachable by bare name ` +
                                `in the concatenated bundle and downstream references throw a ` +
                                `ReferenceError. Check that the fork bundle is current.`
                            );
                        }
                    }
                }
            }
        };
    };
}

/**
 * Transform a single JavaScript file
 * @param {string} content - File content
 * @param {string} filePath - File path for source mapping
 * @param {string} target - Target environment (modern, es6, es5)
 * @param {string} hashPath - Path to use for hash generation
 * @param {boolean} jsonOutput - Whether to output JSON format
 * @returns {object} Transform result or error object
 */
function transformFileContent(content, filePath, target, hashPath, jsonOutput) {
    // Preprocess content before transformation
    content = preprocessDecorators(content, filePath);

    // Generate file hash for prefixing (HARDCODED - NOT CONFIGURABLE)
    // This prevents namespace collisions when files are concatenated in bundles
    const fileHash = crypto.createHash('md5')
        .update(hashPath)
        .digest('hex')
        .substring(0, 8);

    try {
        // Configure Babel transformation
        // Use relative path for sourcemap to match SCSS behavior
        const relativeFilePath = path.relative(process.cwd(), filePath);

        const result = babel.transformSync(content, {
            filename: relativeFilePath,
            sourceFileName: relativeFilePath,  // Explicitly set source filename for sourcemap
            sourceMaps: 'inline',

            // RSpade JS is concatenated into a CLASSIC SCRIPT, never a CommonJS
            // module - nothing here uses static import/export, because classes are
            // global lexical bindings the manifest discovers. Left at its default,
            // preset-env's modules:'auto' assumes a CJS target anyway and rewrites
            // a dynamic import(url) into a require() shim, which is undefined in a
            // browser ("require is not defined").
            //
            // Declaring the caller supports dynamic import keeps import() intact as
            // native syntax. Without this, the only way to reach a real dynamic
            // import was new Function('u','return import(u)') - hiding the token
            // from the transform - and that is an eval-family construct the CSP
            // blocks on every page (JS-EVAL-01).
            caller: {
                name: 'rsx-js-transformer',
                supportsDynamicImport: true,
            },
            presets: [
                ['@babel/preset-env', targetPresets[target] || targetPresets.modern]
            ],
            plugins: [
                // Apply custom prefixing plugin first
                createPrefixPlugin(fileHash),

                // Transform decorators (Stage 3 proposal) via the vendored, patched fork.
                // The fork emits the module-scope class-name binding that upstream Babel drops
                // for decorated class declarations with static members. See
                // resource/babel-plugin-decorators/README.md. Options are identical to the
                // upstream plugin's.
                // Note: We're NOT transforming private fields - native support only
                [require('./babel-plugin-decorators'), {
                    version: '2023-11',
                }],

                // Transform class properties
                '@babel/plugin-transform-class-properties',

                // Transform optional chaining and nullish coalescing
                '@babel/plugin-transform-optional-chaining',
                '@babel/plugin-transform-nullish-coalescing-operator'
            ]
        });

        if (!result || !result.code) {
            const error = new Error('Babel transformation produced no output');
            return outputError(error, filePath, jsonOutput);
        }

        // Output result (no banner - the concat service handles that)
        return {
            status: 'success',
            result: result.code,
            file: filePath,
            hash: fileHash
        };

    } catch (error) {
        // Parse Babel error location if available
        if (error.loc) {
            // Babel provides loc.line and loc.column
        } else if (error.codeFrame) {
            // Try to extract line/column from codeFrame
            const lineMatch = error.codeFrame.match(/>\s*(\d+)\s*\|/);
            const colMatch = error.codeFrame.match(/\n\s+\|\s+(\^+)/);
            if (lineMatch) {
                error.loc = {
                    line: parseInt(lineMatch[1]),
                    column: colMatch ? colMatch[1].indexOf('^') + 1 : 0
                };
            }
        } else if (error.message) {
            // Try to extract from message (e.g., "file.js: Unexpected token (10:5)")
            const match = error.message.match(/\((\d+):(\d+)\)/);
            if (match) {
                error.loc = {
                    line: parseInt(match[1]),
                    column: parseInt(match[2])
                };
            }
        }

        return outputError(error, filePath, jsonOutput);
    }
}

// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {files: [{path, target, hash_path}, ...]} -> {results: {<path>: <transform result>}}
     */
    transform(request) {
        const results = {};

        for (const file of request.files) {
            const fileTarget = file.target || 'modern';
            const fileHashPath = file.hash_path || file.path;

            try {
                const content = fs.readFileSync(file.path, 'utf8');
                results[file.path] = transformFileContent(content, file.path, fileTarget, fileHashPath, true);
            } catch (error) {
                results[file.path] = {
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

    // Exported for the framework's js_transform tests - not part of the RPC surface.
    transformFileContent,
    createPrefixPlugin,
    targetPresets,
    preprocessDecorators
};
