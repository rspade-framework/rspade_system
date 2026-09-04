/**
 * SANITIZE subsystem of the node service (prefix `sanitize`).
 *
 * Removes comments and replaces string contents with spaces while preserving line numbers
 * and column positions, so a code-quality rule never matches inside a comment or a string
 *
 * Methods: sanitize.sanitize
 *
 * @FILENAME-CONVENTION-EXCEPTION - node service module
 */

const fs = require('fs');
const decomment = require('decomment');
const acorn = require('acorn');

// =============================================================================
// SHARED SANITIZATION LOGIC
// =============================================================================

/**
 * Sanitize JavaScript file content
 * @param {string} content - File content
 * @param {string} filePath - File path for error reporting
 * @returns {string} Sanitized content
 */
function sanitizeContent(content, filePath) {
    // Step 1: Remove comments while preserving spaces
    let sanitized;
    try {
        sanitized = decomment(content, {
            space: true,  // Replace comments with spaces to preserve line numbers
            tolerant: true // Handle potential syntax issues
        });
    } catch (error) {
        // If decomment fails, continue with original code
        sanitized = content;
    }

    // Step 2: Parse the code to find string literals and replace their contents
    try {
        // Parse with location tracking
        const ast = acorn.parse(sanitized, {
            ecmaVersion: 'latest',
            sourceType: 'module',
            locations: true,
            ranges: true,
            allowReturnOutsideFunction: true,
            allowImportExportEverywhere: true,
            allowAwaitOutsideFunction: true,
            allowSuperOutsideMethod: true,
            allowHashBang: true,
            onComment: () => {} // Ignore comments (already removed)
        });

        // Convert code to array of characters for manipulation
        const chars = sanitized.split('');

        // Function to replace string content with spaces
        function replaceStringContent(node) {
            if (node.type === 'Literal' && typeof node.value === 'string') {
                // For string literals, replace the content (but keep the quotes)
                const start = node.range[0];
                const end = node.range[1];

                // Replace everything between quotes with spaces
                for (let i = start + 1; i < end - 1; i++) {
                    if (chars[i] !== '\n') {  // Preserve newlines for line counting
                        chars[i] = ' ';
                    }
                }
            } else if (node.type === 'TemplateLiteral') {
                // For template literals, replace the raw text parts
                for (let quasi of node.quasis) {
                    const start = quasi.range[0];
                    const end = quasi.range[1];

                    // Replace content between backticks or ${...}
                    for (let i = start + 1; i < end - 1; i++) {
                        if (chars[i] !== '\n' && chars[i] !== '$' && chars[i] !== '{' && chars[i] !== '}') {
                            chars[i] = ' ';
                        }
                    }
                }
            }
        }

        // Walk the AST to find all string literals
        function walk(node) {
            if (!node) return;

            // Process current node
            replaceStringContent(node);

            // Recursively process all child nodes
            for (let key in node) {
                if (key === 'range' || key === 'loc' || key === 'start' || key === 'end') {
                    continue; // Skip location properties
                }

                const value = node[key];
                if (Array.isArray(value)) {
                    for (let item of value) {
                        if (typeof item === 'object' && item !== null) {
                            walk(item);
                        }
                    }
                } else if (typeof value === 'object' && value !== null) {
                    walk(value);
                }
            }
        }

        // Walk the AST to process all strings
        walk(ast);

        // Convert back to string
        sanitized = chars.join('');

    } catch (error) {
        // If parsing fails (e.g., syntax error), we still have comments removed
        // Just continue with the decommented version
    }

    return sanitized;
}

// =============================================================================
// HANDLERS
// =============================================================================

module.exports = {
    /**
     * {files: [<absolute path>, ...]} -> {results: {<path>: {status, sanitized, original_lines}}}
     */
    sanitize(request) {
        const results = {};

        for (const file of request.files) {
            try {
                const content = fs.readFileSync(file, 'utf8');
                const sanitized = sanitizeContent(content, file);
                results[file] = {
                    status: 'success',
                    sanitized: sanitized,
                    original_lines: content.split('\n').length
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
