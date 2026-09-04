/**
 * SCSS subsystem of the node service (prefix `scss`).
 *
 * Compiles one SCSS entry file to CSS, and in production runs the postcss (autoprefixer +
 * cssnano) pass over the result. This is the framework's ONE sass invocation.
 *
 * A `compile` request is:
 *   {input_file, output_file, production:bool, source_maps:bool}
 * and the response reports what happened rather than printing it, because a daemon's stdout
 * reaches nobody.
 *
 * Methods: scss.compile
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const fs = require('fs');
const path = require('path');


// The node service is spawned with the project's base path as its working directory (see
// Rsx_Node_Service), which is also where node_modules lives. Resolving the toolchain
// once, here, is the point of a daemon: sass stays loaded between calls.
const basePath = process.cwd();
const sass = require(basePath + '/node_modules/sass');

/**
 * Compile one SCSS entry file to CSS.
 *
 * Returns the notes worth reporting; throws on failure, with sass's own message.
 */
async function compileScss(inputFile, outputFile, isProduction, enableSourceMaps) {
    const notes = [];

    const result = sass.compile(inputFile, {
        style: isProduction ? 'compressed' : 'expanded',
        sourceMap: enableSourceMaps,
        sourceMapIncludeSources: true,
        verbose: !isProduction,  // Show all deprecation warnings in dev mode
        silenceDeprecations: ['import', 'if-function', 'global-builtin', 'color-functions'],  // Suppress deprecation warnings until Sass 3.0 migration
        loadPaths: [
            path.dirname(inputFile),
            basePath + '/rsx',
            basePath + '/rsx/styles',
            basePath + '/resources/sass',
            basePath + '/node_modules'
        ]
    });

    let cssContent = result.css;

    // Add inline source map if enabled
    // Using embedded source maps for better debugging experience
    if (enableSourceMaps && result.sourceMap) {
        // Add file boundaries in expanded mode for easier debugging
        if (!isProduction) {
            cssContent = cssContent.replace(/\/\* ============ START: (.+?) ============ \*\//g,
                '\n/* ======= FILE: $1 ======= */\n');
            cssContent = cssContent.replace(/\/\* ============ END: .+? ============ \*\//g, '');
        }

        // Fix sourcemap paths to be relative to project root and remove file:// protocol
        const sourceMap = result.sourceMap;
        sourceMap.sourceRoot = '';  // Use relative paths

        // Clean up source paths (but don't filter - keep all sources to preserve mapping indices)
        sourceMap.sources = result.sourceMap.sources.map(source => {
            let cleanedSource = source;

            // Remove file:// protocol if present
            // file:///path means file (protocol) + :// (separator) + /path (absolute path)
            // So file:///var/www/html should become /var/www/html
            if (cleanedSource.startsWith('file:///')) {
                cleanedSource = '/' + cleanedSource.substring(8);  // Remove 'file:///' and add back the leading /
            } else if (cleanedSource.startsWith('file://')) {
                cleanedSource = cleanedSource.substring(7);  // Remove 'file://' (non-standard)
            }

            // Make paths relative to project root
            if (cleanedSource.startsWith(basePath + '/')) {
                cleanedSource = cleanedSource.substring(basePath.length + 1);
            } else if (cleanedSource.startsWith(basePath)) {
                cleanedSource = cleanedSource.substring(basePath.length);
                if (cleanedSource.startsWith('/')) {
                    cleanedSource = cleanedSource.substring(1);
                }
            }

            return cleanedSource;
        });

        const sourceMapBase64 = Buffer.from(JSON.stringify(sourceMap)).toString('base64');
        cssContent += '\n/*# sourceMappingURL=data:application/json;charset=utf-8;base64,' + sourceMapBase64 + ' */';
    }

    // Write output
    fs.writeFileSync(outputFile, cssContent);

    notes.push('SCSS compilation successful');

    // If production, also run postcss for additional optimization
    if (isProduction) {
        await optimizeWithPostCSS(outputFile, enableSourceMaps);
        notes.push('PostCSS optimization complete');
    }

    return notes;
}

/**
 * Autoprefix and minify a compiled stylesheet in place.
 *
 * FAILS LOUD. This used to log and continue, which shipped un-optimized, un-prefixed CSS
 * from a production build with nothing but a line in a captured stdout to say so. A
 * stylesheet that did not survive its own build step is not a stylesheet we ship - so the
 * error propagates to the caller, which deletes the half-built artifact.
 */
async function optimizeWithPostCSS(file, enableSourceMaps) {
    const postcss = require(basePath + '/node_modules/postcss');
    const autoprefixer = require(basePath + '/node_modules/autoprefixer');
    const cssnano = require(basePath + '/node_modules/cssnano');

    const css = fs.readFileSync(file, 'utf8');

    const result = await postcss([
        autoprefixer(),
        cssnano({
            preset: ['default', {
                discardComments: {
                    removeAll: true,
                },
            }]
        })
    ]).process(css, {
        from: file,
        to: file,
        map: enableSourceMaps ? { inline: true, sourcesContent: true } : false
    });

    fs.writeFileSync(file, result.css);
}

// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {input_file, output_file, production, source_maps} -> {result: {...}}
     */
    async compile(request) {
        try {
            if (!request.input_file || !request.output_file) {
                throw new Error('Compile request carried no input_file/output_file.');
            }

            const notes = await compileScss(
                request.input_file,
                request.output_file,
                request.production === true,
                request.source_maps === true
            );

            return {
                result: {
                    status: 'success',
                    bytes: fs.statSync(request.output_file).size,
                    notes: notes
                }
            };
        } catch (error) {
            return {
                result: {
                    status: 'error',
                    error: {
                        message: error.message,
                        stack: error.stack || null
                    }
                }
            };
        }
    }
};
