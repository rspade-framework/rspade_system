<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;

/**
 * eval() and new Function() in bundled JavaScript.
 *
 * Both are blocked by the Content-Security-Policy the framework emits: script-src
 * carries no 'unsafe-eval', deliberately, so either construct is a violation on
 * every page that loads the bundle. The browser reports BOTH as `blocked-uri: eval`
 * - which is why this rule matches both. A rule that caught only eval() would
 * report clean while the page still violated, and that is worse than no rule at
 * all for anyone using it as a CSP-readiness gate.
 *
 * The rule is a deliberately DUMB matcher. Nearly every real instance is one
 * thing - resolving a class from its name as a string - but the shapes that
 * reach it have nothing textually in common beyond the word `eval`, so trying to
 * recognize "is this a class lookup" produces a fragile matcher that misses the
 * fourth shape somebody writes next month. Ban the construct (there is no
 * legitimate use in bundled code) and let the RESOLUTION TEXT carry the
 * class-resolution guidance to the authors who need it.
 */
class EvalUsage_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'JS-EVAL-01';
    }

    public function get_name(): string
    {
        return 'eval() / new Function() Check';
    }

    public function get_description(): string
    {
        return 'Detects eval() and new Function() in bundled JavaScript - both are CSP violations';
    }

    public function get_file_patterns(): array
    {
        return ['*.js'];
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Third-party code and the editor extension are not ours to rewrite.
        if (str_contains($file_path, '/vendor/')
            || str_contains($file_path, '/node_modules/')
            || str_contains($file_path, '/.cdn-cache/')
            || str_contains($file_path, '/resource/vscode_extension/')) {
            return;
        }

        // Bundled JavaScript only. A node script has no browser and therefore no
        // CSP consequence - a build tool or a test driver using eval() is doing
        // something legitimate that this rule has no opinion about. Directories
        // named resource/ are already excluded from the scan globally, which is
        // what keeps standalone drivers under rsx/resource/bin/ out of scope.
        if (!$this->is_bundled_javascript($file_path)) {
            return;
        }

        // Sanitized lines: comments and string literals are already blanked, so a
        // mention of eval() in a docblock or a message string cannot match here.
        $sanitized_data = FileSanitizer::sanitize_javascript($file_path);
        $lines = $sanitized_data['lines'];
        $original_lines = $sanitized_data['original_lines'];

        foreach ($lines as $index => $line) {
            $line_number = $index + 1;

            // eval( as a CALL, not as part of a longer identifier - `medieval(`
            // and `this._eval(` are somebody else's function.
            $is_eval = preg_match('/(?<![A-Za-z0-9_$.])eval\s*\(/', $line) === 1;
            $is_function_ctor = preg_match('/\bnew\s+Function\s*\(/', $line) === 1;

            if (!$is_eval && !$is_function_ctor) {
                continue;
            }

            $construct = $is_eval ? 'eval()' : 'new Function()';
            $snippet = trim($original_lines[$index] ?? $line);

            $this->add_violation(
                $file_path,
                $line_number,
                "{$construct} is prohibited in bundled JavaScript - it is a CSP violation",
                $snippet,
                $this->build_resolution($construct)
            );
        }
    }

    /**
     * Whether this file is compiled into a browser bundle.
     *
     * The manifest's own scan_directories list is the authority - it is the same
     * list that decides what gets bundled in the first place, so this cannot drift
     * away from reality the way a hand-maintained path list would.
     */
    private function is_bundled_javascript(string $file_path): bool
    {
        foreach (config('rsx.manifest.scan_directories', []) as $scan_dir) {
            if (str_contains($file_path, '/' . trim($scan_dir, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function build_resolution(string $construct): string
    {
        $resolution = "{$construct} is prohibited in bundled JavaScript.\n\n";

        $resolution .= "Both eval() and new Function() are blocked by the Content-Security-Policy\n";
        $resolution .= "the framework emits on every page (script-src carries no 'unsafe-eval',\n";
        $resolution .= "deliberately), so this line is a CSP violation wherever it runs. The browser\n";
        $resolution .= "reports both constructs identically, as `blocked-uri: eval`.\n";
        $resolution .= "See: php artisan rsx:man csp\n\n";

        $resolution .= "ARE YOU RESOLVING A CLASS BY ITS NAME?\n\n";
        $resolution .= "  That is what eval() is almost always doing here, and the reasoning that\n";
        $resolution .= "  leads to it is correct as far as it goes: RSpade classes compile to\n";
        $resolution .= "  top-level `class` declarations, which are lexical bindings, so\n";
        $resolution .= "  window['Task_Model'] is undefined and eval('Task_Model') is the first\n";
        $resolution .= "  thing that appears to work.\n\n";
        $resolution .= "  There is a supported API for exactly this:\n\n";
        $resolution .= "      const cls = Manifest.get_class_by_name(name);   // the class, or null\n\n";
        $resolution .= "  It is a registry lookup against Manifest._classes. It does not execute\n";
        $resolution .= "  the string, it cannot throw, and it returns null for an unknown name.\n\n";
        $resolution .= "  BEFORE\n";
        $resolution .= "      let cls = null;\n";
        $resolution .= "      if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(model)) {\n";
        $resolution .= "          try { cls = eval(model); } catch (e) { cls = null; }\n";
        $resolution .= "      }\n\n";
        $resolution .= "  AFTER\n";
        $resolution .= "      const cls = Manifest.get_class_by_name(model);\n\n";
        $resolution .= "  Drop the identifier regex and the try/catch with it - they existed only\n";
        $resolution .= "  to make eval() survivable, and a map lookup needs neither.\n\n";

        $resolution .= "NEEDING A DYNAMIC import()?\n\n";
        $resolution .= "  Write the bare import(url). It is preserved through the build: the babel\n";
        $resolution .= "  transform declares caller.supportsDynamicImport, so import() is NOT\n";
        $resolution .= "  rewritten into a require() shim. new Function('u','return import(u)') was\n";
        $resolution .= "  the workaround for that rewrite and is no longer needed.\n\n";

        $resolution .= "If a standalone node script tripped this rule, it is not bundled JavaScript\n";
        $resolution .= "and the rule should not have seen it - report that rather than suppressing.\n";
        $resolution .= "Otherwise, add '@JS-EVAL-01-EXCEPTION' with a written rationale.";

        return $resolution;
    }
}
