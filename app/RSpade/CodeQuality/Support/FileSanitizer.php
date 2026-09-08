<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;

/**
 * COMMENT AND STRING BLANKING - ONE IMPLEMENTATION PER LANGUAGE FAMILY.
 *
 * PHP goes through the tokenizer (`sanitize_php`); JavaScript through the `sanitize`
 * subsystem of the node service when strings must go too (`sanitize_javascript`), and
 * through `blank_js_comments` when they must stay; templates through
 * `blank_template_comments` (jqhtml, blade and html comment forms); stylesheets through
 * `blank_scss_comments`; a PHP FRAGMENT (one line, where the tokenizer cannot be used)
 * through `blank_php_comments`.
 *
 * EVERY ONE OF THEM IS LINE-PRESERVING. A comment body is replaced with spaces rather than
 * removed, so a line and column computed against the sanitized text still addresses the
 * original file - which is what a violation's reported line number is. That is also why
 * this class exists rather than eight `preg_replace` calls scattered across rules and
 * manifest modules: several of those DELETED the comment, so every line after the first
 * multi-line docblock in the file was reported one or more lines off.
 */
class FileSanitizer
{
    /**
     * Derived-cache namespace for sanitized JavaScript. See
     * App\RSpade\Core\Cache\File_Content_Cache - the ONE per-source-file cache helper.
     */
    protected const CACHE_NAMESPACE = 'js-sanitized';

    /**
     * Get PHP content with comments removed
     * This ensures we don't match patterns inside comments
     * Uses PHP tokenizer to properly strip comments (from line 711 of monolith)
     */
    public static function sanitize_php(string $content): array
    {
        // Use PHP tokenizer to properly strip comments
        $tokens = token_get_all($content);
        $lines = [];
        $current_line = '';
        
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $token_type = $token[0];
                $token_content = $token[1];
                
                // Skip comment tokens
                if ($token_type === T_COMMENT || $token_type === T_DOC_COMMENT) {
                    // Add empty lines to preserve line numbers
                    $comment_lines = explode("\n", $token_content);
                    foreach ($comment_lines as $idx => $comment_line) {
                        if ($idx === 0 && $current_line !== '') {
                            // First line of comment - complete current line
                            $lines[] = $current_line;
                            $current_line = '';
                        } elseif ($idx > 0) {
                            // Additional comment lines
                            $lines[] = '';
                        }
                    }
                } else {
                    // Add non-comment content
                    $content_parts = explode("\n", $token_content);
                    foreach ($content_parts as $idx => $part) {
                        if ($idx > 0) {
                            $lines[] = $current_line;
                            $current_line = $part;
                        } else {
                            $current_line .= $part;
                        }
                    }
                }
            } else {
                // Single character tokens
                $current_line .= $token;
            }
        }
        
        // Add the last line if any
        if ($current_line !== '' || count($lines) === 0) {
            $lines[] = $current_line;
        }
        
        $sanitized_content = implode("\n", $lines);
        
        return [
            'content' => $sanitized_content,
            'lines' => $lines,
            'original_lines' => explode("\n", $content),
        ];
    }
    
    /**
     * Get sanitized JavaScript content for checking
     * Removes comments and string contents to avoid false positives
     * Uses RPC server for performance
     */
    public static function sanitize_javascript(string $file_path): array
    {
        // The cache key is the file's build hash, so a changed file misses by construction.
        // The mtime guard is kept on top of it - this cache had one before the move, and a
        // cache never gets weaker in a consolidation.
        $sanitized = File_Content_Cache::get(self::CACHE_NAMESPACE, $file_path, '', 'js', true);

        if ($sanitized === null) {
            // Sanitize via RPC server
            $sanitized = static::_sanitize_via_rpc($file_path);

            File_Content_Cache::put(self::CACHE_NAMESPACE, $file_path, '', 'js', $sanitized);
        }

        return [
            'content' => $sanitized,
            'lines' => explode("\n", $sanitized),
            'original_lines' => explode("\n", file_get_contents($file_path)),
        ];
    }

    /**
     * Sanitize via the node service.
     *
     * Lazy by construction: a fully cached check never reaches request(), and request() is
     * what starts the service - so it never spawns node at all.
     */
    protected static function _sanitize_via_rpc($file_path): string
    {
        try {
            $data = Rsx_Node_Service::request('sanitize.sanitize', [
                'files' => [$file_path],
            ]);

            if (isset($data['error'])) {
                throw new \RuntimeException("RPC error: " . $data['error']);
            }

            if (!isset($data['results'][$file_path])) {
                throw new \RuntimeException("No result for file in RPC response");
            }

            $result = $data['results'][$file_path];

            // Handle sanitize result
            if ($result['status'] === 'success') {
                return $result['sanitized'];
            }

            // Handle error response
            if ($result['status'] === 'error' && isset($result['error'])) {
                $error = $result['error'];
                $message = $error['message'] ?? 'Unknown error';
                throw new \RuntimeException("Sanitization error: " . $message);
            }

            // Unknown response format
            throw new \RuntimeException(
                "JavaScript sanitizer RPC returned unexpected response for {$file_path}:\n" .
                json_encode($result, JSON_PRETTY_PRINT)
            );

        } catch (\Exception $e) {
            // Wrap exceptions
            throw new \RuntimeException(
                "JavaScript sanitizer RPC error for {$file_path}: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Blank out TEMPLATE comment blocks, preserving every byte position and line break.
     *
     * The extension-driven sanitize() below leaves .jqhtml untouched and runs the PHP
     * tokenizer over .blade.php - neither of which knows what a `<%-- --%>` or a
     * `{{-- --}}` is. A rule that matches on markup therefore reads illustrative examples
     * inside documentation comments as real code (a component doc block showing
     * `<tr data-href="/url">` is the house example). Comment bodies are replaced with
     * spaces rather than removed, so line and column numbers still address the original.
     */
    public static function blank_template_comments(string $content): string
    {
        $patterns = [
            '/<%--.*?--%>/s',       // jqhtml
            '/\{\{--.*?--\}\}/s',   // blade
            '/<!--.*?-->/s',        // html
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace_callback(
                $pattern,
                static function (array $match): string {
                    // Keep newlines so the line count never moves; blank everything else.
                    return preg_replace('/[^\n]/', ' ', $match[0]);
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Blank out JavaScript comments, preserving every byte position and line break.
     *
     * sanitize_javascript() blanks string CONTENTS as well, which is exactly wrong for a
     * rule whose subject IS the string content (a URL literal). This keeps the strings and
     * removes only the comments, with a scanner that tracks quoting so a `//` inside
     * "https://example.com" is never mistaken for a comment. A `/` that opens a regex
     * literal cannot be confused with a comment opener: `//` is a comment in every
     * position where it is legal, and `/*` is not a valid regex start.
     */
    public static function blank_js_comments(string $content): string
    {
        return static::__blank_slash_comments($content, false);
    }

    /**
     * Blank out SCSS comments, preserving every byte position and line break.
     *
     * SAME COMMENT SYNTAX AS JAVASCRIPT, one difference that matters: CSS accepts an
     * UNQUOTED url(), and `url(https://fonts.googleapis.com/...)` contains `//`. The four
     * hand-rolled `preg_replace('#//.*$#m', '', ...)` strippers this replaced all truncated
     * such a line at the scheme separator - silently, since what they deleted was the rest of
     * an at-rule the caller then failed to match. So an unquoted url(...) is skipped whole.
     */
    public static function blank_scss_comments(string $content): string
    {
        return static::__blank_slash_comments($content, true);
    }

    /**
     * Blank out PHP comments in a FRAGMENT - one line, or any string that is not a whole
     * file - preserving every byte position and line break.
     *
     * `sanitize_php()` above is the whole-file tool and is the better one: it runs the PHP
     * TOKENIZER, so it is exact. It cannot be pointed at a single line, because a line is
     * not parseable PHP. Two rules walk a file line by line and need the comment gone from
     * the line in hand; this is what they call, and it recognizes PHP's `#` line comment as
     * well as the two C-style forms.
     */
    public static function blank_php_comments(string $content): string
    {
        return static::__blank_slash_comments($content, false, true);
    }

    /**
     * The scanner both of the above are: `//` to end of line and `/* *\/` blanked to spaces,
     * quoting tracked so a separator inside a string is never a comment, newlines kept so
     * every line and column still addresses the original.
     *
     * $skip_unquoted_urls additionally steps over a CSS `url(...)` token; $hash_comments
     * additionally treats `#` as a line comment, which it is in PHP and is not in the others.
     */
    private static function __blank_slash_comments(string $content, bool $skip_unquoted_urls, bool $hash_comments = false): string
    {
        $out = '';
        $length = strlen($content);
        $i = 0;
        $quote = null;

        while ($i < $length) {
            $char = $content[$i];
            $next = $i + 1 < $length ? $content[$i + 1] : '';

            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;
                $i++;
                continue;
            }

            if ($skip_unquoted_urls && ($char === 'u' || $char === 'U') && stripos(substr($content, $i, 4), 'url(') === 0) {
                $close = strpos($content, ')', $i);
                $close = $close === false ? $length : $close + 1;
                $out .= substr($content, $i, $close - $i);
                $i = $close;
                continue;
            }

            if (($char === '/' && $next === '/') || ($hash_comments && $char === '#')) {
                while ($i < $length && $content[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($content, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                for (; $i < $end; $i++) {
                    $out .= $content[$i] === "\n" ? "\n" : ' ';
                }
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * Sanitize file based on extension
     * Note: PHP takes content, JavaScript takes file_path (matching monolith behavior)
     */
    public static function sanitize(string $file_path, ?string $content = null): array
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        if ($extension === 'php') {
            if ($content === null) {
                $content = file_get_contents($file_path);
            }
            return self::sanitize_php($content);
        } elseif (in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
            // JavaScript sanitization needs file path, not content
            return self::sanitize_javascript($file_path);
        } elseif (in_array($extension, ['scss', 'css'])) {
            if ($content === null) {
                $content = file_get_contents($file_path);
            }

            $blanked = self::blank_scss_comments($content);

            return [
                'content' => $blanked,
                'lines' => explode("\n", $blanked),
                'original_lines' => explode("\n", $content),
            ];
        } else {
            // For other files, return as-is
            if ($content === null) {
                $content = file_get_contents($file_path);
            }
            return [
                'content' => $content,
                'lines' => explode("\n", $content),
                'original_lines' => explode("\n", $content),
            ];
        }
    }
}
