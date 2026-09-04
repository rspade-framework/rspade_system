/**
 * CONCAT subsystem of the node service (prefix `concat`).
 *
 * Joins one bundle's files into a single artifact and merges their sourcemaps (Mozilla
 * source-map: consumer/generator/SourceNode).
 *
 * The file list travels in the request payload and never on a command line: one Linux
 * argument is capped at MAX_ARG_STRLEN (131072 bytes), and a bundle's file list crosses that
 * cliff at roughly 1,300 files, failing every build from then on. A socket payload has no
 * such ceiling.
 *
 * A `concat` request is:
 *   {type:'js'|'css', output_file, files:[{path, source}]}
 * where `path` is the file to READ and `source` is the path to ATTRIBUTE it to (they differ
 * for a babel-transformed file, whose banner and sourcemap must name the developer's own
 * source). Warnings are returned rather than printed, because a daemon's stdout reaches
 * nobody - the PHP client re-emits them.
 *
 * Methods: concat.concat
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const fs = require('fs');
const path = require('path');
const { SourceMapConsumer, SourceMapGenerator, SourceNode } = require('source-map');

/**
 * Extracts inline Base64 sourcemap from JavaScript content
 * CRITICAL: Uses exact regex pattern from JQHTML team - DO NOT MODIFY
 * Returns { content: string without sourcemap, map: parsed object or null }
 */
function extractJsSourceMap(content, filename, warnings) {
    // EXACT regex pattern - do not modify
    const regex = /(?:\/\/[@#][ \t]+sourceMappingURL=([^\s'"]+?)[ \t]*$)/m;
    const match = content.match(regex);

    if (!match || !match[1]) {
        return { content, map: null };
    }

    const url = match[1];

    // Handle inline Base64 data URLs
    if (url.startsWith('data:')) {
        const base64Match = url.match(/base64,(.*)$/);
        if (base64Match) {
            try {
                const json = Buffer.from(base64Match[1], 'base64').toString('utf8');
                const map = JSON.parse(json);

                // Remove sourcemap comment from content
                const cleanContent = content.replace(regex, '');

                return { content: cleanContent, map };
            } catch (e) {
                warnings.push(`Failed to parse sourcemap for ${filename}: ${e.message}`);
                return { content, map: null };
            }
        }
    }

    // External sourcemap files not supported in concatenation
    warnings.push(`External sourcemap "${url}" in ${filename} will be ignored`);
    return { content, map: null };
}

/**
 * Extracts inline Base64 sourcemap from CSS content
 * Returns { content: string without sourcemap, map: parsed object or null }
 */
function extractCssSourceMap(content, filename, warnings) {
    // CSS sourcemap comment format
    const regex = /\/\*#\s*sourceMappingURL=([^\s*]+)\s*\*\//m;
    const match = content.match(regex);

    if (!match || !match[1]) {
        return { content, map: null };
    }

    const url = match[1];

    // Handle inline Base64 data URLs
    if (url.startsWith('data:')) {
        const base64Match = url.match(/base64,(.*)$/);
        if (base64Match) {
            try {
                const json = Buffer.from(base64Match[1], 'base64').toString('utf8');
                const map = JSON.parse(json);

                // Remove sourcemap comment from content
                const cleanContent = content.replace(regex, '');

                return { content: cleanContent, map };
            } catch (e) {
                warnings.push(`Failed to parse sourcemap for ${filename}: ${e.message}`);
                return { content, map: null };
            }
        }
    }

    // External sourcemap files not supported in concatenation
    warnings.push(`External sourcemap "${url}" in ${filename} will be ignored`);
    return { content, map: null };
}

/**
 * Assert that a sourcemap does not describe more generated lines than the file has.
 *
 * A map whose mappings run past the end of the code is not a cosmetic defect: Mozilla's
 * SourceNode.fromStringWithSourceMap walks the mappings and materializes every generated
 * line it is told about, so each phantom line becomes a bare `undefined` identifier in the
 * concatenated bundle - top-level JS that throws the moment the bundle executes, taking the
 * whole bundle with it. Fail here, where the offending file is still named.
 */
function assert_sourcemap_line_count(map, content, filename) {
    const segments = map.mappings.split(';');

    let mapped_lines = 0;
    for (let i = 0; i < segments.length; i++) {
        if (segments[i] !== '') {
            mapped_lines = i + 1;
        }
    }

    const content_lines = content.split('\n').length;

    if (mapped_lines > content_lines) {
        throw new Error(
            `Malformed sourcemap in ${filename}: the map describes ${mapped_lines} generated lines ` +
            `but the file has only ${content_lines}. Concatenating it would emit ${mapped_lines - content_lines} ` +
            `bare "undefined" identifiers into the bundle.`
        );
    }
}

/**
 * Resolve one request file entry into the path to read and the path to attribute it to.
 */
function resolveEntry(entry) {
    const readPath = entry && entry.path ? entry.path : null;

    if (!readPath) {
        throw new Error('Concat request contained a file entry with no "path".');
    }

    const displayPath = entry.source || null;

    return {
        readPath,
        displayPath,
        isBabel: displayPath !== null && displayPath !== readPath,
    };
}

/**
 * Concatenate JavaScript, merging sourcemaps through Mozilla source-map.
 */
async function concatenateJs(files, outputFile, warnings) {
    // Create root SourceNode for concatenation
    const rootNode = new SourceNode(null, null, null);

    // Track source contents for embedding in sourcemap
    const sourceContents = {};

    // Process each input file
    for (const entry of files) {
        const { readPath, displayPath, isBabel } = resolveEntry(entry);

        if (!fs.existsSync(readPath)) {
            throw new Error(`Input file not found: ${readPath}`);
        }

        // Read file content
        const content = fs.readFileSync(readPath, 'utf-8');

        // Generate relative path for better source map references
        const relativePath = displayPath
            ? path.relative(process.cwd(), displayPath)
            : path.relative(process.cwd(), readPath);

        // Add file separator comment with (babel) suffix if transformed
        const banner = isBabel
            ? `/* === ${relativePath} (babel) === */\n`
            : `/* === ${relativePath} === */\n`;
        rootNode.add(banner);

        // Extract sourcemap if present
        const { content: cleanContent, map } = extractJsSourceMap(content, relativePath, warnings);

        if (map) {
            assert_sourcemap_line_count(map, cleanContent + '\n', relativePath);
        }

        // Store source content for embedding in sourcemap
        sourceContents[relativePath] = cleanContent;

        // Check if this is a compiled JQHTML file
        const isJqhtml = content.includes('/* Compiled from:') && content.includes('.jqhtml */');

        if (map) {
            // File has a sourcemap - use it
            let consumer = await new SourceMapConsumer(map);

            // Apply 2-line offset for JQHTML files
            if (isJqhtml) {
                // JQHTML templates need a 2-line offset because the template definition
                // starts on line 3 of the source (after <Define:ComponentName>)
                const offsetMap = JSON.parse(JSON.stringify(map));

                // Update mappings to add 2-line offset
                const generator = new SourceMapGenerator({
                    file: offsetMap.file,
                    sourceRoot: offsetMap.sourceRoot
                });

                // Re-apply all mappings with offset
                consumer.eachMapping(mapping => {
                    if (mapping.source && mapping.originalLine) {
                        generator.addMapping({
                            generated: {
                                line: mapping.generatedLine,
                                column: mapping.generatedColumn
                            },
                            original: {
                                line: mapping.originalLine + 2,  // Add 2-line offset
                                column: mapping.originalColumn
                            },
                            source: mapping.source,
                            name: mapping.name
                        });
                    }
                });

                // Clean up old consumer
                consumer.destroy();

                // Use new consumer with offset mappings
                const offsetMapJson = generator.toJSON();
                offsetMapJson.sources = map.sources;
                offsetMapJson.sourcesContent = map.sourcesContent;
                consumer = await new SourceMapConsumer(offsetMapJson);
            }

            // Store any additional sources from the existing sourcemap
            if (map.sourcesContent && map.sources) {
                map.sources.forEach((source, idx) => {
                    if (map.sourcesContent[idx]) {
                        sourceContents[source] = map.sourcesContent[idx];
                    }
                });
            }

            // Create a SourceNode from the file content with its sourcemap
            const node = SourceNode.fromStringWithSourceMap(
                cleanContent + '\n',  // Add newline separator between files
                consumer
            );

            rootNode.add(node);

            // Clean up consumer to prevent memory leaks
            consumer.destroy();
        } else {
            // No sourcemap - generate identity mappings (each line maps to itself)
            const lines = cleanContent.split('\n');
            const fileNode = new SourceNode();

            // Apply 2-line offset for JQHTML files without existing sourcemaps
            const lineOffset = isJqhtml ? 2 : 0;

            for (let i = 0; i < lines.length; i++) {
                // Map each line to its original position (with offset for JQHTML)
                fileNode.add(new SourceNode(
                    i + 1 + lineOffset,  // line (1-indexed) + offset for JQHTML
                    0,                   // column
                    relativePath,        // source filename
                    lines[i] + (i < lines.length - 1 ? '\n' : '')  // preserve newlines except last
                ));
            }

            // Add final newline separator
            fileNode.add('\n');
            rootNode.add(fileNode);
        }

        // Add extra newline between files
        rootNode.add('\n');
    }

    // Generate the concatenated result with merged sourcemap
    const { code, map } = rootNode.toStringWithSourceMap({
        file: path.basename(outputFile)
    });

    // Convert sourcemap to JSON for final processing
    const mapJSON = map.toJSON();

    // Ensure sourceRoot is set properly
    mapJSON.sourceRoot = '';

    // Ensure all sources are relative paths
    if (mapJSON.sources) {
        mapJSON.sources = mapJSON.sources.map(source => {
            if (!source) return source;
            // If it's an absolute path, make it relative
            if (path.isAbsolute(source)) {
                return path.relative(process.cwd(), source);
            }
            return source;
        });
    }

    // Add source contents to the sourcemap for inline viewing
    if (mapJSON.sources) {
        mapJSON.sourcesContent = mapJSON.sources.map(source => {
            // Try to find content for this source
            return sourceContents[source] || null;
        });
    }

    // Convert sourcemap to Base64 and append as inline comment
    // CRITICAL: Include charset=utf-8 as specified by JQHTML team
    const base64Map = Buffer.from(JSON.stringify(mapJSON)).toString('base64');
    const finalCode = code +
        `\n//# sourceMappingURL=data:application/json;charset=utf-8;base64,${base64Map}\n`;

    writeOutput(outputFile, finalCode);

    return {
        sources: mapJSON.sources || [],
        sourcemap_bytes: Buffer.byteLength(base64Map, 'utf-8'),
    };
}

/**
 * Concatenate CSS, merging sourcemaps through Mozilla source-map.
 */
async function concatenateCss(files, outputFile, warnings) {
    // Create combined sourcemap generator
    const generator = new SourceMapGenerator({
        file: path.basename(outputFile)
    });

    // Track source contents for embedding
    const sourceContents = {};

    // The bundle text so far. LINE POSITIONS ARE COUNTED FROM THIS STRING, never
    // hand-tracked beside it: the old parallel `currentLine` counter started one line out
    // of step with its own header (the header emits three newlines, the counter said two)
    // and every mapping in every bundle inherited the skew. Counting '\n' in what was
    // actually emitted cannot disagree with what was actually emitted.
    let output = `/* Concatenated CSS bundle: ${path.basename(outputFile)} */\n`
        + `/* Generated: ${new Date().toISOString()} */\n\n`;

    const line_count = (text) => {
        let n = 0;
        for (let i = text.indexOf('\n'); i !== -1; i = text.indexOf('\n', i + 1)) {
            n++;
        }
        return n;
    };

    // Process each input file
    for (const entry of files) {
        const { readPath, displayPath } = resolveEntry(entry);

        if (!fs.existsSync(readPath)) {
            throw new Error(`Input file not found: ${readPath}`);
        }

        // Read file content
        const content = fs.readFileSync(readPath, 'utf-8');

        // Generate relative path for better source map references
        const relativePath = path.relative(process.cwd(), displayPath || readPath);

        // Add file separator comment
        output += `/* === ${relativePath} === */\n`;

        // Extract sourcemap if present
        const { content: cleanContent, map } = extractCssSourceMap(content, relativePath, warnings);

        // The chunk's line 1 lands on generated line (offset + 1): output always ends in
        // a newline here, so the newline count IS the number of completed lines above.
        const offset = line_count(output);

        if (map) {
            // The chunk carries its own map (the sass compile): copy EVERY mapping,
            // columns included, shifted by the chunk's position in the bundle. The old
            // code instead probed originalPositionFor() at column 0 of each line, which
            // dropped any line whose first mapping sat past column 0 (most closing
            // braces, every compressed line) and flattened real columns to 0 - the
            // devtools then resolved those rules against whatever mapping came before,
            // frequently a different source file.
            if (map.sourcesContent && map.sources) {
                map.sources.forEach((source, idx) => {
                    if (map.sourcesContent[idx]) {
                        sourceContents[source] = map.sourcesContent[idx];
                    }
                });
            }

            const consumer = await new SourceMapConsumer(map);

            consumer.eachMapping((m) => {
                if (m.source === null || m.originalLine === null) {
                    return;
                }

                generator.addMapping({
                    generated: { line: m.generatedLine + offset, column: m.generatedColumn },
                    original: { line: m.originalLine, column: m.originalColumn },
                    source: m.source,
                    name: m.name || undefined
                });
            });

            consumer.destroy();
        } else {
            // No sourcemap - the chunk itself is the source. Identity mappings, and its
            // content is what sourcesContent should carry for it.
            sourceContents[relativePath] = cleanContent;

            const lines = cleanContent.split('\n');
            for (let i = 0; i < lines.length; i++) {
                generator.addMapping({
                    generated: { line: offset + i + 1, column: 0 },
                    original: { line: i + 1, column: 0 },
                    source: relativePath
                });
            }
        }

        // Emit the chunk, normalized to end in exactly one newline, plus a blank
        // separator line - normalization keeps the next chunk's offset well-defined.
        output += cleanContent.replace(/\n*$/, '\n') + '\n';
    }

    // Generate the final sourcemap
    const mapJSON = generator.toJSON();

    // Ensure sourceRoot is set properly
    mapJSON.sourceRoot = '';

    // Ensure all sources are relative paths
    if (mapJSON.sources) {
        mapJSON.sources = mapJSON.sources.map(source => {
            if (!source) return source;
            // If it's an absolute path, make it relative
            if (path.isAbsolute(source)) {
                return path.relative(process.cwd(), source);
            }
            return source;
        });
    }

    // Add source contents to the sourcemap for inline viewing
    if (mapJSON.sources) {
        mapJSON.sourcesContent = mapJSON.sources.map(source => {
            return sourceContents[source] || null;
        });
    }

    // Convert sourcemap to Base64 and append as inline comment
    const base64Map = Buffer.from(JSON.stringify(mapJSON)).toString('base64');
    const finalContent = output +
        `\n/*# sourceMappingURL=data:application/json;charset=utf-8;base64,${base64Map} */\n`;

    writeOutput(outputFile, finalContent);

    return {
        sources: mapJSON.sources || [],
        sourcemap_bytes: Buffer.byteLength(base64Map, 'utf-8'),
    };
}

/**
 * Write the bundle artifact, creating its directory if needed.
 */
function writeOutput(outputFile, content) {
    const outputDir = path.dirname(outputFile);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(outputFile, content, 'utf-8');
}

// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {type, output_file, files:[{path, source}]} -> {result: {...}}
     */
    async concat(request) {
        const type = request.type;
        const outputFile = request.output_file;
        const files = Array.isArray(request.files) ? request.files : [];
        const warnings = [];

        try {
            if (type !== 'js' && type !== 'css') {
                throw new Error(`Unknown concat type: ${type}`);
            }

            if (!outputFile) {
                throw new Error('Concat request carried no output_file.');
            }

            const merged = type === 'js'
                ? await concatenateJs(files, outputFile, warnings)
                : await concatenateCss(files, outputFile, warnings);

            return {
                result: {
                    status: 'success',
                    files: files.length,
                    bytes: fs.statSync(outputFile).size,
                    sourcemap_bytes: merged.sourcemap_bytes,
                    sources: merged.sources,
                    warnings: warnings
                }
            };
        } catch (error) {
            return {
                result: {
                    status: 'error',
                    error: {
                        message: error.message,
                        stack: error.stack || null
                    },
                    warnings: warnings
                }
            };
        }
    }
};
