<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\Jqhtml_Code_Regions;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * JS-NAVIGATE-01 - navigation goes through ONE door: Rsx.navigate() / Rsx.redirect().
 *
 * `Rsx.navigate(url, {history, via, load, title_hint})` (Core/Js/Rsx_Navigation.js) classifies
 * the target, carries the breadcrumb origin (`_via`) - across a full page load too, by a
 * hand-off the next document reads - and moves in-app or by a document load. A raw write to
 * the page's location or history skips all of it: the origin is lost, the SPA's history
 * bookkeeping goes out of step, and there is no longer one place to read the flow. FATAL at
 * manifest build, in APPLICATION code (`rsx/`, minus test trees, `resource/`, vendored and
 * minified files) - the framework's door itself is the one legitimate writer.
 *
 * FLAGGED:
 *   - an assignment to `location`, or to its href / search / pathname / host / hostname /
 *     port / protocol / hash, under bare `location`, `window.` `document.` `self.` `top.`
 *     `parent.` `globalThis.` (bracket spellings too), or through a variable that was given
 *     the location object - and that aliasing itself (`const l = window.location`);
 *   - `location.assign()` / `location.replace()` on the same receivers;
 *   - jQuery `.attr()` / `.prop()` of `href` or `location` on `$(location)`, `$(window)`,
 *     `$(document)`, `$(window.location)`;
 *   - `history.pushState()` / `history.replaceState()` (also `window.history.`);
 *   - `Spa.dispatch()` / `Spa.redirect()` - removed; `Rsx.navigate()` / `Rsx.redirect()` replace them.
 *
 * LEGAL: `location.reload()`, any READ of location (destructuring included), history.back() /
 * forward() / go() and `history.state`, and a property merely NAMED location on another object.
 * A name is the browser global only when the file binds nothing of that name.
 *
 * THE MARKER `@JS-NAVIGATE-01-EXCEPTION <reason>`, on the reported line or the line above,
 * with a real reason, excuses ONLY a pushState / replaceState - kept on a page that is not an
 * SPA page (a Blade page with its own router). On any other finding it excuses nothing, and
 * the report says so.
 *
 * DETECTION: `.js` by AST in the node service (`quality.analyze_navigation`); `.jqhtml` by
 * pattern over the template's code regions (`Jqhtml_Code_Regions`), because a template's code
 * is fragments that only become a program once compiled. Findings are REPORTED
 * (add_violation), never thrown: the manifest driver turns the first into the FATAL, and
 * rsx:check lists every one.
 */
class Navigation_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const MARKER = '@JS-NAVIGATE-01-EXCEPTION';

    /**
     * Raw bytes that cannot hold a finding are skipped before any analysis.
     */
    private const PREFILTER = '/location|pushState|replaceState|Spa\s*\.\s*(?:dispatch|redirect)|(?:attr|prop)\s*\(\s*[\'"]href/';

    private const LOCATION_PARTS = 'href|search|pathname|host|hostname|port|protocol|hash';

    public function get_id(): string
    {
        return 'JS-NAVIGATE-01';
    }

    public function get_name(): string
    {
        return 'Navigation Through Rsx.navigate()';
    }

    public function get_description(): string
    {
        return 'Application code navigates through Rsx.navigate() / Rsx.redirect(): no raw location or history writes, no Spa.dispatch() / Spa.redirect()';
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

    /**
     * The marker is judged per line, and only for history writes.
     */
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
        // blanked, and the marker lives in a comment.
        $raw = $this->source()->content($file_path, (string) ($metadata['hash'] ?? ''));

        if (!preg_match(self::PREFILTER, $raw)) {
            return;
        }

        $findings = str_ends_with($file_path, '.jqhtml')
            ? static::__template_findings($raw)
            : Js_CodeQuality_Rpc::analyze_navigation(Rsx_Paths::absolute($file_path));

        if (empty($findings)) {
            return;
        }

        $lines = explode("\n", $raw);

        foreach ($findings as $finding) {
            $line_number = (int) $finding['line'];
            $index = $line_number - 1;
            $kind = (string) $finding['kind'];
            $marker = static::__marker_state($lines, $index);

            if ($kind === 'history_write' && $marker === 'granted') {
                continue;
            }

            $this->add_violation(
                $file_path,
                $line_number,
                static::__message($kind, (string) ($finding['detail'] ?? '')),
                trim($lines[$index] ?? ''),
                static::__remediation($kind, $marker)
            );
        }
    }

    /**
     * Application code only: framework JavaScript legitimately writes location and history
     * inside the door itself.
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
     * The findings of a .jqhtml template, by pattern over its code regions.
     *
     * @return array<int,array{line:int,kind:string,detail:string}>
     */
    private static function __template_findings(string $template): array
    {
        $mask = Jqhtml_Code_Regions::extract($template)['mask'];

        // A template that binds `location` / `history` itself has no bare global of that name.
        $bare_location = !preg_match('/\b(?:const|let|var|function)\s+location\b|\blocation\s*\)?\s*=>/', $mask);
        $bare_history = !preg_match('/\b(?:const|let|var|function)\s+history\b|\bhistory\s*\)?\s*=>/', $mask);

        $receiver = '(?:window|document|self|top|parent|globalThis)\s*(?:\.\s*|\[\s*[\'"])';
        $location = '(?<![\w$.\'"\[])' . ($bare_location ? "(?:{$receiver})?" : $receiver) . 'location(?:[\'"]\s*\])?';
        $part = '(?:\s*(?:\.\s*(?:' . self::LOCATION_PARTS . ')\b|\[\s*[\'"](?:' . self::LOCATION_PARTS . ')[\'"]\s*\]))?';
        $history = '(?<![\w$.])' . ($bare_history ? '(?:window\s*\.\s*)?' : 'window\s*\.\s*') . 'history';

        $patterns = [
            'location_write' => '/' . $location . $part . '\s*\+?=(?!=)/',
            'location_call' => '/' . $location . '\s*\.\s*(?:assign|replace)\s*\(/',
            'location_alias' => '/(?<![=!<>+\-*\/%&|^])=(?!=)\s*' . $location . '\s*(?=[;,)}\]]|$)/m',
            'jquery_location' => '/(?<![\w$.])(?:\$|jQuery)\s*\(\s*(?:window|document|' . $location . ')\s*\)\s*\.\s*(?:attr|prop)\s*\(\s*[\'"](?:href|location)[\'"]\s*,/',
            'history_write' => '/' . $history . '\s*\.\s*(?:pushState|replaceState)\s*\(/',
            'spa_removed' => '/(?<![\w$.])Spa\s*\.\s*(?:dispatch|redirect)\s*\(/',
        ];

        $findings = [];

        foreach ($patterns as $kind => $pattern) {
            if (!preg_match_all($pattern, $mask, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $line = Jqhtml_Code_Regions::line_of($mask, $offset);
                $findings[$line . ':' . $kind] = ['line' => $line, 'kind' => $kind, 'detail' => trim($text)];
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
            'location_write' => "Raw navigation: a write to the page's location{$code}.",
            'location_alias' => "The page's location object is held in a variable{$code} - a write through it is a raw navigation.",
            'location_call' => "Raw navigation: location.assign() / location.replace(){$code}.",
            'jquery_location' => "Raw navigation: jQuery writes the page's location{$code}.",
            'history_write' => "Raw history write: history.pushState() / replaceState(){$code}.",
            'spa_removed' => "Removed API: Spa.dispatch() / Spa.redirect(){$code}.",
            default => "Raw navigation{$code}.",
        };
    }

    private static function __remediation(string $kind, string $marker): string
    {
        if ($kind === 'spa_removed') {
            $text = "Spa.dispatch() and Spa.redirect() were removed: use Rsx.navigate(url, options) /\n"
                . "Rsx.redirect(url, options) - same options, history 'auto' is gone (push is the default).\n";
        } elseif ($kind === 'history_write') {
            $text = "Navigation in an RSpade application goes through ONE door, and that includes\n"
                . "rewriting the address bar: use Rsx.navigate(url, {load: 'none', history: 'push'|'replace'}).\n"
                . "(Rsx.url_hash_set() for the fragment alone.)\n\n"
                . "ESCAPE HATCH: only on a page that is NOT an SPA page (a Blade page with its own router)\n"
                . "may raw pushState/replaceState be kept, marked\n"
                . "    // " . self::MARKER . " <why this page needs it>\n"
                . "on the line or the line above. Never on an SPA page: the SPA owns history state (its\n"
                . "run/index bookkeeping, scroll restoration, breadcrumb-origin decisions) and a raw write\n"
                . "corrupts it.\n";
        } else {
            $text = "Navigation in an RSpade application goes through ONE door: Rsx.navigate(url, {history,\n"
                . "via, load, title_hint}) - or Rsx.redirect(url) for a replace. It classifies the target,\n"
                . "carries the breadcrumb origin (_via) and moves in-app or by a full page load that hands\n"
                . "the origin across. A raw location write skips all of that.\n\n"
                . "Fix: Rsx.navigate(url) (add {load: 'full'} when the target must be a fresh document;\n"
                . "{load: 'none'} to rewrite the address bar without navigating). location.reload() is\n"
                . "allowed, and so is READING location.\n";
        }

        if ($marker !== 'none' && $kind !== 'history_write') {
            $text .= "\nThe " . self::MARKER . " marker here does not apply: it excuses ONLY\n"
                . "history.pushState() / replaceState() on a page that is not an SPA page.\n";
        } elseif ($marker === 'bare') {
            $text .= "\nThe " . self::MARKER . " marker here has no reason after it, so it excuses\n"
                . "nothing: say why this page needs its own history writes.\n";
        }

        return $text . "\nSee: php artisan rsx:man navigation";
    }
}
