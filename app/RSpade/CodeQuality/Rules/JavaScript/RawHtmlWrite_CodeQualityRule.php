<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;

/**
 * JS-RAW-HTML-01 - a string written into the page as markup.
 *
 * jQuery's `.html(x)`, `innerHTML =`, `outerHTML =`, `insertAdjacentHTML()` and
 * `document.write()` all PARSE their argument: whatever the string holds becomes elements,
 * attributes and handlers. When any part of it came from data - a record's name, a server
 * message, a URL parameter - that is a stored or reflected XSS hole, and nothing at the call
 * site says so. Almost every such write in practice only wanted to show text (`.text()`),
 * clear an element (`.empty()`), or put a piece of UI on the page (a jqhtml component).
 *
 * DETECTION is by AST, in the node service (`quality.analyze_raw_html`), so a mention in a
 * comment or a string is never a finding and a multi-line argument is judged whole. ALLOWED
 * with no marker, because the markup is the author's own or safe by construction:
 *   - a string literal, or a template literal whose every ${} part is itself allowed
 *     (`.html('&nbsp;')`, `.html('<div class="x"></div>')`)
 *   - escape_html(...), sanitize_rich_text_html(...), nl2brhtml(...), nl2br(escape_html(...))
 *   - a `+` concatenation whose every part is one of the above
 * `.html('')` is reported on its own, with `.empty()` as the answer.
 *
 * THE EXCEPTION MARKER is read here, per line, not by the driver: `@JS-RAW-HTML-01-EXCEPTION
 * <rationale>` on the reported line or the line before suppresses that one write, and a
 * marker with no rationale suppresses nothing. A granted exception therefore never hides a
 * second, ungranted write elsewhere in the same file.
 *
 * SCOPE is browser JavaScript: `.js` under the manifest's scan directories, minus test trees,
 * `resource/` directories (node services, editor tooling), vendored code and minified files.
 * Not a manifest-time rule - rsx:check only.
 */
class RawHtmlWrite_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const MARKER = '@JS-RAW-HTML-01-EXCEPTION';

    public function get_id(): string
    {
        return 'JS-RAW-HTML-01';
    }

    public function get_name(): string
    {
        return 'Raw HTML Write Check';
    }

    public function get_description(): string
    {
        return 'Detects .html(x), innerHTML/outerHTML assignment, insertAdjacentHTML() and document.write() with a non-literal argument';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function reads_own_exception_markers(): bool
    {
        return true;
    }

    /**
     * The analysis lives in the node service, so its source is part of every verdict.
     */
    public function fingerprint_extra(): string
    {
        $service = base_path('app/RSpade/CodeQuality/Support/resource/quality-service.js');

        return is_file($service) ? md5_file($service) : 'missing';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (!$this->is_in_scope($file_path)) {
            return;
        }

        $findings = Js_CodeQuality_Rpc::analyze_raw_html($file_path);

        if (empty($findings)) {
            return;
        }

        // Raw bytes: the marker lives in a comment, which the sanitized $contents blanks.
        $lines = explode("\n", $this->source()->content($file_path, (string) ($metadata['hash'] ?? '')));

        foreach ($findings as $finding) {
            $line_number = (int) $finding['line'];
            $index = $line_number - 1;

            if (static::__line_is_excepted($lines, $index)) {
                continue;
            }

            $kind = (string) $finding['kind'];

            $this->add_violation(
                $file_path,
                $line_number,
                static::__message($kind),
                trim($lines[$index] ?? ''),
                static::__remediation($kind)
            );
        }
    }

    /**
     * Browser JavaScript under a manifest scan directory, minus tests, resource/ trees,
     * vendored code and minified files.
     */
    private function is_in_scope(string $file_path): bool
    {
        foreach (['/vendor/', '/node_modules/', '/.cdn-cache/', '/resource/', '/tests/'] as $excluded) {
            if (str_contains($file_path, $excluded)) {
                return false;
            }
        }

        if (str_ends_with($file_path, '.min.js')) {
            return false;
        }

        foreach (config('rsx.manifest.scan_directories', []) as $scan_dir) {
            if (str_contains($file_path, '/' . trim($scan_dir, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the line, or the line above it, carry the marker WITH a rationale?
     *
     * A bare marker suppresses nothing: an exception that does not say why is the thing
     * this rule exists to prevent, written one line higher.
     */
    private static function __line_is_excepted(array $lines, int $index): bool
    {
        foreach ([$index, $index - 1] as $candidate) {
            if ($candidate < 0 || !isset($lines[$candidate])) {
                continue;
            }

            $position = strpos($lines[$candidate], self::MARKER);

            if ($position === false) {
                continue;
            }

            $rationale = substr($lines[$candidate], $position + strlen(self::MARKER));

            // Whatever closes the comment the marker sits in is not a rationale.
            $rationale = preg_replace('/\*\/.*$/s', '', $rationale);
            $rationale = trim((string) $rationale, " \t-:*");

            if (preg_match('/[A-Za-z]{3}/', $rationale)) {
                return true;
            }
        }

        return false;
    }

    private static function __message(string $kind): string
    {
        return match ($kind) {
            'html_empty' => ".html('') clears an element by parsing an empty string - use .empty()",
            'html' => '.html() with a non-literal argument writes it into the page as markup',
            'inner_html' => 'innerHTML assigned a non-literal value writes it into the page as markup',
            'outer_html' => 'outerHTML assigned a non-literal value writes it into the page as markup',
            'insert_adjacent_html' => 'insertAdjacentHTML() with a non-literal argument writes it into the page as markup',
            'document_write' => 'document.write() with a non-literal argument writes it into the page as markup',
            default => 'Raw HTML written into the page',
        };
    }

    private static function __remediation(string $kind): string
    {
        if ($kind === 'html_empty') {
            return "To clear an element, use .empty():\n\n"
                . "    \$el.html('');   // WRONG\n"
                . "    \$el.empty();    // RIGHT - also stops any jqhtml component inside it\n";
        }

        $text = "Raw .html() is almost never needed, and with any data in the string it is an XSS hole.\n\n";
        $text .= "- Just displaying text? Use .text(value). Nothing in the value is read as markup.\n";
        $text .= "- Clearing an element? Use .empty().\n";
        $text .= "- Multi-line plain text that must keep its line breaks? .html(nl2brhtml(value)).\n";
        $text .= "- Reading the text out of an HTML string? html_to_text(html) - it parses inertly.\n";
        $text .= "- A few elements around some text? Build the nodes and set the text on them:\n";
        $text .= "      \$('<li></li>').text(message).appendTo(\$list);\n";
        $text .= "- Inserting a piece of UI? Design a jqhtml component and invoke it on the target -\n";
        $text .= "  \$(target).component('Name', args) - rather than inserting raw HTML. The markup\n";
        $text .= "  then lives in a .jqhtml template, where <%= %> escapes every value.\n";
        $text .= "\n";
        $text .= "Allowed without comment: a string literal (including '&nbsp;' and a template with no \${}),\n";
        $text .= "escape_html(...), sanitize_rich_text_html(...), nl2brhtml(...), nl2br(escape_html(...)),\n";
        $text .= "and a + / template composition of only those.\n";
        $text .= "\n";
        $text .= "If the use case genuinely justifies raw HTML - an effects plugin, for instance - add\n";
        $text .= "'@JS-RAW-HTML-01-EXCEPTION <rationale>' on the same line or the line before. The\n";
        $text .= "rationale is required: a bare marker suppresses nothing.";

        return $text;
    }
}
