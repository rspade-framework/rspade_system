/**
 * MINIFY subsystem of the node service (prefix `minify`).
 *
 * JavaScript (Terser) and CSS (cssnano) minification for production builds.
 *
 * Methods: minify.minify
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const { minify: terserMinify } = require('terser');
const postcss = require('postcss');
const cssnano = require('cssnano');


/**
 * Minify JavaScript content using Terser
 * Strips sourcemaps, preserves license comments, produces minimal output.
 * When stripConsoleDebug is true, console_debug() calls are dropped via Terser
 * pure_funcs (strict production only - the PHP caller decides, this file never does).
 */
async function minifyJs(content, filename, stripConsoleDebug = false) {
    try {
        // Extract all license comments BEFORE minification
        // These can be lost when Terser transforms/inlines IIFEs
        const licenseComments = [];
        const licenseRegex = /\/\*![\s\S]*?\*\//g;
        let match;
        while ((match = licenseRegex.exec(content)) !== null) {
            const license = match[0];
            // Only keep unique licenses (avoid duplicates)
            if (!licenseComments.some(l => l === license)) {
                licenseComments.push(license);
            }
        }

        // Remove existing sourcemap comments before minification
        const cleanContent = content.replace(/\/\/[#@]\s*sourceMappingURL=.*/g, '');

        const compressOptions = {
            dead_code: true,
            drop_console: false,  // Keep console.log for now
            drop_debugger: true,
            passes: 2
        };

        // Strip console_debug call sites in strict production. pure_funcs marks the
        // call as side-effect-free so dead_code elimination removes it entirely.
        // Both forms are stripped: the global console_debug() helper (what app code
        // uses) AND the Debugger.console_debug(...) member form the helper delegates
        // to and that framework code (Ajax, Browser) calls directly. The two function
        // DEFINITIONS survive as harmless dead code - only invocations are the goal.
        if (stripConsoleDebug) {
            compressOptions.pure_funcs = ['console_debug', 'Debugger.console_debug'];
        }

        const result = await terserMinify(cleanContent, {
            compress: compressOptions,
            mangle: {
                reserved: ['$', 'jQuery', '_', 'Rsx', 'rsxapp']  // Don't mangle these
            },
            format: {
                // Don't try to preserve comments - we'll prepend them manually
                comments: false,
                semicolons: true
            },
            sourceMap: false  // No sourcemap in production
        });

        if (result.code === undefined) {
            throw new Error('Terser produced no output');
        }

        // Prepend extracted license comments to output
        let finalCode = result.code;
        if (licenseComments.length > 0) {
            finalCode = licenseComments.join('\n') + '\n' + result.code;
        }

        return {
            status: 'success',
            result: finalCode,
            originalSize: content.length,
            minifiedSize: finalCode.length
        };
    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: filename
            }
        };
    }
}

/**
 * Minify CSS content using cssnano
 * Strips sourcemaps, preserves license comments, produces minimal output
 */
async function minifyCss(content, filename) {
    try {
        // Extract all license comments BEFORE minification
        const licenseComments = [];
        const licenseRegex = /\/\*![\s\S]*?\*\//g;
        let match;
        while ((match = licenseRegex.exec(content)) !== null) {
            const license = match[0];
            if (!licenseComments.some(l => l === license)) {
                licenseComments.push(license);
            }
        }

        // Remove existing sourcemap comments before minification
        const cleanContent = content.replace(/\/\*[#@]\s*sourceMappingURL=.*?\*\//g, '');

        const result = await postcss([
            cssnano({
                preset: ['default', {
                    // Strip all comments - we'll prepend licenses manually
                    discardComments: { removeAll: true },
                    normalizeWhitespace: true,
                    minifySelectors: true,
                    minifyParams: true,
                    minifyFontValues: true
                }]
            })
        ]).process(cleanContent, {
            from: filename,
            map: false  // No sourcemap in production
        });

        // Prepend extracted license comments to output
        let finalCss = result.css;
        if (licenseComments.length > 0) {
            finalCss = licenseComments.join('\n') + '\n' + result.css;
        }

        return {
            status: 'success',
            result: finalCss,
            originalSize: content.length,
            minifiedSize: finalCss.length
        };
    } catch (error) {
        return {
            status: 'error',
            error: {
                type: error.constructor.name,
                message: error.message,
                file: filename
            }
        };
    }
}

// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {files: [{type:'js'|'css', content, filename, strip_console_debug}, ...]}
     *   -> {results: {<filename>: <minify result>}}
     */
    async minify(request) {
        const results = {};

        for (const file of request.files) {
            const type = file.type;  // 'js' or 'css'
            const content = file.content;
            const filename = file.filename || 'unknown';
            const stripConsoleDebug = file.strip_console_debug === true;

            if (type === 'js') {
                results[filename] = await minifyJs(content, filename, stripConsoleDebug);
            } else if (type === 'css') {
                results[filename] = await minifyCss(content, filename);
            } else {
                results[filename] = {
                    status: 'error',
                    error: {
                        type: 'InvalidType',
                        message: `Unknown file type: ${type}`
                    }
                };
            }
        }

        return { results };
    }
};
