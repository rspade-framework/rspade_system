/**
 * JQHTML subsystem of the node service (prefix `jqhtml`).
 *
 * Compiles .jqhtml templates to JavaScript with sourcemap support, via @jqhtml/parser.
 *
 * This module is ES MODULE source, because @jqhtml/parser is an ES module. The service
 * loader reaches every subsystem through dynamic import(), so a module may be written in
 * either flavour; the one rule is that its handler table is the DEFAULT export here and
 * `module.exports` in a CommonJS module.
 *
 * Methods: jqhtml.compile
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import { compileTemplate } from '@jqhtml/parser';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// The INSTALLED @jqhtml/parser version, resolved through node's own module walk from this
// file (which lives under system/, so the walk lands on system/node_modules). This value is
// handed to compileTemplate(), so a wrong answer changes what the compiler is told - which
// is exactly what happened for months when a hand-built relative path here resolved to a
// directory that does not exist and a catch block quietly substituted '2.2.0' while 2.3.x
// was installed. A failure to resolve is therefore FATAL: no parser package means no
// working compiler, and a guessed version is worse than a loud stop.
const require = createRequire(import.meta.url);
const VERSION = JSON.parse(
    fs.readFileSync(require.resolve('@jqhtml/parser/package.json'), 'utf-8')
).version;

// =============================================================================
// SHARED COMPILATION LOGIC
// =============================================================================

/**
 * Compile a single JQHTML template
 * @param {string} filePath - Path to .jqhtml file
 * @param {object} options - Compilation options
 * @returns {object} Compilation result or error object
 */
function compileFile(filePath, options = {}) {
    try {
        // Read input file
        const inputPath = path.resolve(filePath);
        if (!fs.existsSync(inputPath)) {
            return {
                status: 'error',
                error: {
                    type: 'FileNotFoundError',
                    message: `Input file not found: ${inputPath}`,
                    file: filePath
                }
            };
        }

        const source = fs.readFileSync(inputPath, 'utf-8');

        // Use relative path for sourcemap sources (not just basename)
        // This allows browser devtools to show proper file paths
        const relativePath = path.relative(process.cwd(), inputPath);

        // Compile using @jqhtml/parser API
        const compiled = compileTemplate(source, relativePath, {
            format: options.format || 'iife',
            sourcemap: options.sourcemap !== false,
            version: VERSION
        });

        return {
            status: 'success',
            result: compiled.code,
            file: filePath
        };

    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: error.filename || filePath,
                line: error.line || null,
                column: error.column || null,
                context: error.context || null,
                suggestion: error.suggestion || null
            }
        };
    }
}

// =============================================================================
// HANDLERS
// =============================================================================

export default {
    /**
     * {files: [{path, format, sourcemap}, ...]} -> {results: {<path>: <compile result>}}
     */
    compile(request) {
        const results = {};

        for (const file of request.files) {
            const fileFormat = file.format || 'iife';
            const fileSourcemap = file.sourcemap !== false;

            results[file.path] = compileFile(file.path, {
                format: fileFormat,
                sourcemap: fileSourcemap
            });
        }

        return { results };
    }
};
