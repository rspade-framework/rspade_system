<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Jqhtml_Code_Regions;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * JS-NEW-TAB-01 - code never opens a tab; an `<a target="_blank">` the user clicks does.
 *
 * Browsers block a tab opened outside the user's own click, so `window.open()` after an
 * `await` fails only sometimes; a reserved blank tab hides the failure; and a code-opened tab
 * skips the navigation door. The ONLY sanctioned way to open a new tab is a real link with
 * `target="_blank"` that the user clicks. FATAL at manifest build, in APPLICATION code
 * (`rsx/`, minus test trees, `resource/`, vendored and minified files).
 *
 * FLAGGED:
 *   - `window.open()` / `self.` / `top.` / `parent.` / `globalThis.open()`, and a bare
 *     `open()` when the file binds no `open` of its own;
 *   - `dispatchEvent()` of a synthetic click: `new MouseEvent('click')`, `new Event('click')`,
 *     `new PointerEvent('click')`, directly or through a variable;
 *   - an anchor or form given `target="_blank"` and activated from code in the SAME function
 *     (`.click()`, `.trigger('click')`, `.triggerHandler('click')`, `.submit()`,
 *     `.requestSubmit()`, `.trigger('submit')`) - `el.target = '_blank'`,
 *     `.attr('target', '_blank')`, `.prop(...)`, `.attr({target: '_blank'})`,
 *     `.setAttribute(...)`, or markup holding the attribute (`$('<a target="_blank">')`).
 *
 * LEGAL: a `<a download>` click with no target (a blob download), `.click()` on a file input
 * or a button, `.click(handler)` (binding, not activating), and giving `target="_blank"` to an
 * element the user clicks. The target-blank heuristic is deliberately CONSERVATIVE - a false
 * negative is preferred to a false positive: the element must be named the same way in both
 * places (`$a`, `$a[0]`, `$a.get(0)`, `$(a)` all name `a`), within one function and its
 * nested callbacks.
 *
 * THE MARKER `@JS-NEW-TAB-01-EXCEPTION <reason>`, on the reported line or the line above, with
 * a real reason, excuses that one finding. Rare, and reviewed.
 *
 * DETECTION: `.js` by AST in the node service (`quality.analyze_new_tab`); `.jqhtml` by
 * pattern over the template's code regions (`Jqhtml_Code_Regions`), where "the same function"
 * is one code block. Findings are REPORTED (add_violation), never thrown.
 */
class NewTab_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const MARKER = '@JS-NEW-TAB-01-EXCEPTION';

    /**
     * Raw bytes that cannot hold a finding are skipped before any analysis: every finding
     * needs an `open`, a `dispatchEvent` or a `_blank`.
     */
    private const PREFILTER = '/\bopen\b|dispatchEvent|_blank/';

    public function get_id(): string
    {
        return 'JS-NEW-TAB-01';
    }

    public function get_name(): string
    {
        return 'No Tab Opened From Code';
    }

    public function get_description(): string
    {
        return 'Application code never opens a tab (window.open, a synthetic click, a target=_blank anchor or form activated from code); a user-clicked <a target="_blank"> does';
    }

    public function get_file_patterns(): array
    {
        return ['*.js', '*.jqhtml'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * FATAL at manifest build.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function reads_own_exception_markers(): bool
    {
        return true;
    }

    /**
     * The JavaScript analysis lives in the node service and the template extraction in a
     * support class, so both sources are part of every verdict.
     */
    public function fingerprint_extra(): string
    {
        $hashes = '';

        foreach (['Support/resource/quality-service.js', 'Support/Jqhtml_Code_Regions.php'] as $relative) {
            $file = base_path('app/RSpade/CodeQuality/' . $relative);
            $hashes .= is_file($file) ? md5_file($file) : 'missing';
        }

        return md5($hashes);
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (!static::__in_scope($file_path)) {
            return;
        }

        // RAW bytes: rsx:check hands a sanitized copy with string contents and comments
        // blanked, and both '_blank' and the marker live in exactly those.
        $raw = $this->source()->content($file_path, (string) ($metadata['hash'] ?? ''));

        if (!preg_match(self::PREFILTER, $raw)) {
            return;
        }

        $findings = str_ends_with($file_path, '.jqhtml')
            ? static::__template_findings($raw)
            : Js_CodeQuality_Rpc::analyze_new_tab(Rsx_Paths::absolute($file_path));

        if (empty($findings)) {
            return;
        }

        $lines = explode("\n", $raw);

        foreach ($findings as $finding) {
            $line_number = (int) $finding['line'];
            $index = $line_number - 1;
            $marker = static::__marker_state($lines, $index);

            if ($marker === 'granted') {
                continue;
            }

            $this->add_violation(
                $file_path,
                $line_number,
                static::__message((string) $finding['kind'], (string) ($finding['detail'] ?? '')),
                trim($lines[$index] ?? ''),
                static::__remediation($marker)
            );
        }
    }

    /**
     * Application code only.
     */
    private static function __in_scope(string $file_path): bool
    {
        if (!Rsx_Paths::is_application($file_path) || Rsx_Paths::is_test_tree($file_path)) {
            return false;
        }

        foreach (['/vendor/', '/node_modules/', '/.cdn-cache/', '/resource/'] as $excluded) {
            if (str_contains($file_path, $excluded)) {
                return false;
            }
        }

        return !str_ends_with($file_path, '.min.js');
    }

    /**
     * The findings of a .jqhtml template, by pattern over its code regions. The
     * target-blank heuristic treats one code region as "the same function".
     *
     * @return array<int,array{line:int,kind:string,detail:string}>
     */
    private static function __template_findings(string $template): array
    {
        $extracted = Jqhtml_Code_Regions::extract($template);
        $mask = $extracted['mask'];

        $bare_open = !preg_match('/\b(?:const|let|var|function)\s+open\b|\bopen\s*\)?\s*=>/', $mask);
        $open = '/(?<![\w$.])' . ($bare_open ? '(?:(?:window|self|top|parent|globalThis)\s*\.\s*)?' : '(?:window|self|top|parent|globalThis)\s*\.\s*') . 'open\s*\(/';

        $findings = [];
        $add = function (string $kind, string $text, int $offset) use (&$findings, $mask): void {
            $line = Jqhtml_Code_Regions::line_of($mask, $offset);
            $findings[$line . ':' . $kind] = ['line' => $line, 'kind' => $kind, 'detail' => trim($text)];
        };

        $patterns = [
            'window_open' => $open,
            'synthetic_click' => '/dispatchEvent\s*\(\s*new\s+(?:Mouse|Pointer)?Event\s*\(\s*[\'"`]click[\'"`]/',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match_all($pattern, $mask, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $add($kind, $text, $offset);
                }
            }
        }

        $blank = '/\btarget[\'"]?\s*(?:,|:|=)\s*\\\\?[\'"]?_blank/';
        $activation = '/\.(?:click|submit|requestSubmit)\s*\(\s*\)|\.trigger(?:Handler)?\s*\(\s*[\'"](?:click|submit)[\'"]/';

        foreach ($extracted['regions'] as [$start, $end]) {
            $code = substr($mask, $start, $end - $start);

            if (!preg_match($blank, $code) || !preg_match_all($activation, $code, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $add('blank_target_click', $text, $start + $offset);
            }
        }

        usort($findings, fn ($a, $b) => $a['line'] <=> $b['line']);

        return array_values($findings);
    }

    /**
     * 'granted' (the marker with a reason on the line or the line above), 'bare' (the
     * marker with no reason), or 'none'.
     */
    private static function __marker_state(array $lines, int $index): string
    {
        $state = 'none';

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
            $rationale = preg_replace('/(--%>|-->|\*\/|%>).*$/s', '', $rationale);
            $rationale = trim((string) $rationale, " \t-:*");

            if (preg_match('/[A-Za-z]{3}/', $rationale)) {
                return 'granted';
            }

            $state = 'bare';
        }

        return $state;
    }

    private static function __message(string $kind, string $detail): string
    {
        $code = $detail === '' ? '' : " `{$detail}`";

        return match ($kind) {
            'window_open' => "A tab is opened from code: window.open(){$code}.",
            'synthetic_click' => "A synthetic click is dispatched from code{$code} - the way code fakes the user's click to open a tab.",
            'blank_target_click' => "An anchor or form given target=\"_blank\" is activated from code{$code} - code opening a tab.",
            default => "A tab is opened from code{$code}.",
        };
    }

    private static function __remediation(string $marker): string
    {
        $text = "RSpade policy: the ONLY sanctioned way to open a new tab is an <a target=\"_blank\"> the user\n"
            . "clicks. Code never opens a tab: browsers block a tab opened outside the user's own click (so\n"
            . "window.open after an await fails only sometimes), a reserved blank tab hides failures, and a\n"
            . "code-opened tab skips the navigation door.\n\n"
            . "Fix: render a real link with target=\"_blank\" rel=\"noopener\"; when the tab's URL needs server\n"
            . "work first, point the link at a same-site GET route that does the work and redirects (guard a\n"
            . "route that DOES something with \$request->is_same_origin_navigation()). A download needs no\n"
            . "tab: Rsx.navigate(download_url).\n\n"
            . "ESCAPE HATCH (rare, reviewed): `// " . self::MARKER . " <why no link can work here>` on\n"
            . "the line or the line above.\n";

        if ($marker === 'bare') {
            $text .= "\nThe " . self::MARKER . " marker here has no reason after it, so it excuses nothing.\n";
        }

        return $text . "\nSee: php artisan rsx:man navigation";
    }
}
