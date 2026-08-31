<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\CodeQuality\Rules\Common;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Manifest\Manifest;

/**
 * URL-HARDCODE-01 - an internal path in an href must be produced by a Route() helper.
 *
 * DENY BY DEFAULT. Any href value that starts with "/" is a violation unless it is one of
 * the explicitly allowed forms below. The rule does NOT ask whether the literal happens to
 * resolve to a known route: "flag it only if it resolves" can never enforce the mandate,
 * because the paths that matter most - an interpolated record path, a portal path, a route
 * added after the scan - are exactly the ones that do not resolve.
 *
 * INTERPOLATION IS A POSITIVE SIGNAL. `href="/clients/<%= id %>"` is MORE wrong than
 * `href="/clients"`, not less: the value is being CONSTRUCTED, which is the job Route()
 * exists to do. A template token is never read as a file extension.
 *
 * PORTAL AWARENESS IS PATH-BASED. A file under `rsx/portal/` is a portal screen by
 * convention, and gets the `Rsx_Portal.Route()` suggestion resolved against the PORTAL
 * route table; everything else gets `Rsx.Route()` against the staff table. This is a
 * deliberate choice of the cheap signal over class analysis - the suggestion text is the
 * only thing that depends on it, and a portal file that lives somewhere else still gets
 * flagged, just with the staff spelling in the suggestion.
 *
 * WHY IT IS HIGH: a hardcoded portal href shipped to production and broke navigation. In
 * the portal the browser is under the `/_portal/` prefix, `Spa.dispatch` strips that prefix
 * to MATCH a route but writes the URL to history verbatim, and only `Rsx_Portal.Route()`
 * puts the prefix back. A bare `/messages/3` therefore stays bare.
 */
class HardcodedInternalUrl_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * Path prefixes that are NOT page routes and are never written with Route().
     *
     * The first group is what the framework serves for itself under `/_` (see
     * Core/Dispatch/AssetHandler.php for the two build-artifact prefixes, and the
     * File_Attachment_Controller / File_Preview_Controller routes for the rest): compiled
     * bundles, the sealed-build CDN mirror, and the blob-serving endpoints whose URLs come
     * from the attachment API rather than from a page route. The second group is ordinary
     * static asset roots served out of a `public/` directory.
     */
    protected const ALLOWED_PREFIXES = [
        '/_compiled/',          // compiled bundle artifacts (AssetHandler)
        '/_vendor/',            // sealed-build mirror of declared external assets
        '/_download/',          // File_Attachment_Controller::download_file
        '/_download_zip/',      // File_Attachment_Controller::download_multiple_zip
        '/_inline/',            // File_Attachment_Controller::inline
        '/_icon_by_extension/', // File_Attachment_Controller::icon_by_extension
        '/_thumbnail/',         // File_Attachment_Controller thumbnails
        '/_preview/',           // File_Preview_Controller renditions and the pdf.js modules
        '/_upload',             // the upload transport
        '/_ajax/',              // the Ajax transport
        '/assets/',
        '/css/',
        '/js/',
        '/images/',
        '/img/',
        '/fonts/',
        '/storage/',
    ];

    /**
     * Extensions that make a last path segment a FILE rather than a route.
     *
     * A closed list, not "contains a dot": the old rule skipped any last segment holding a
     * dot, so `href="/clients/<%= t.id %>"` was silently read as a static file because the
     * expression `t.id` has one. An extension only counts when the value carries no
     * interpolation at all.
     */
    protected const STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'md', 'csv', 'html', 'htm',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'bmp', 'avif',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'pdf', 'zip', 'gz', 'mp3', 'mp4', 'webm', 'wav', 'ogg',
    ];

    /** Template/expression openers that mean the value is being CONSTRUCTED. */
    protected const INTERPOLATION_TOKENS = ['<%', '{{', '{!!', '${', '<?'];

    public function get_id(): string
    {
        return 'URL-HARDCODE-01';
    }

    public function get_name(): string
    {
        return 'Hardcoded Internal URL Detection';
    }

    public function get_description(): string
    {
        return 'An internal path in an href must be generated with Rsx::Route() / Rsx_Portal::Route(), never written as a literal';
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Every file kind that can carry an href: a template, a Blade view, application
     * JavaScript that builds markup or assigns location.href, and PHP that emits markup.
     */
    public function get_file_patterns(): array
    {
        return ['*.jqhtml', '*.blade.php', '*.js', '*.php'];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        // Third-party code is not ours to rewrite, and the checker's own rule sources carry
        // href patterns as data.
        if (str_contains($file_path, '/vendor/')
            || str_contains($file_path, '/node_modules/')
            || str_contains($file_path, '/.cdn-cache/')
            || str_contains($file_path, '/CodeQuality/')) {
            return;
        }

        $kind = static::_file_kind($file_path);

        // Work from the ORIGINAL bytes: the checker hands JavaScript through a sanitizer
        // that blanks string CONTENTS, which is precisely the text this rule reads. Comments
        // are then removed here, per kind, so an illustrative href in a doc block never
        // fires (item 6 of the request that produced this rule).
        $original = is_readable($file_path) ? file_get_contents($file_path) : $contents;

        // The overwhelming majority of scanned files hold no href at all; comment stripping
        // (a PHP tokenize, for every .php in the tree) is not worth doing to find that out.
        if (!str_contains($original, 'href')) {
            return;
        }

        $scannable = static::_strip_comments($original, $kind);

        $original_lines = explode("\n", $original);
        $lines = explode("\n", $scannable);

        foreach ($lines as $index => $line) {
            if (!str_contains($line, 'href')) {
                continue;
            }

            $line_number = $index + 1;

            foreach ($this->_extract_href_values($line) as $value) {
                if (!$this->_is_violation($value)) {
                    continue;
                }

                // The marker suppresses this line or the line above it. (The checker
                // additionally skips the whole file when the marker appears anywhere in it -
                // that is generic behavior every rule gets.)
                if (static::_line_is_excepted($lines, $index)
                    || static::_line_is_excepted($original_lines, $index)) {
                    continue;
                }

                $this->add_violation(
                    $file_path,
                    $line_number,
                    "Hardcoded internal URL in an href: {$value}",
                    trim($original_lines[$index] ?? $line),
                    $this->_remediation($file_path, $value, $kind),
                    'high'
                );
            }
        }
    }

    // =====================================================================
    // Extraction
    // =====================================================================

    /**
     * Every href-ish value on one line.
     *
     * Covers the markup attribute in all three quotings (a backtick appears inside a JS
     * template literal), plus the two JavaScript spellings that set the same attribute:
     * `.attr('href', ...)` and an `{href: ...}` property. `data-href` and `xlink:href`
     * match too - they are navigation attributes in this codebase and were within the
     * previous rule's reach.
     *
     * @return array<int,string>
     */
    protected function _extract_href_values(string $line): array
    {
        $patterns = [
            // Either quoting, and DELIBERATELY not requiring a matching pair: an href built
            // by concatenation inside a JS string opens with one quote and is closed by the
            // other (`'<a href="/clients/' + id + '">'`), which is the shape that has to be
            // caught. This is the previous rule's expression, kept verbatim.
            '/\bhref\s*=\s*["\']([^"\']*)["\']/',
            '/\bhref\s*=\s*`([^`]*)`/',
            '/[\'"]href[\'"]\s*,\s*[\'"`]([^\'"`]*)[\'"`]/',
            '/\bhref\s*:\s*[\'"`]([^\'"`]*)[\'"`]/',
        ];

        $values = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $line, $matches)) {
                foreach ($matches[1] as $value) {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    // =====================================================================
    // The decision
    // =====================================================================

    protected function _is_violation(string $raw_value): bool
    {
        $value = trim($raw_value);

        // Deny-by-default applies to INTERNAL paths only. Anything that does not start with
        // "/" is an anchor (#...), a scheme (http:, mailto:, tel:, javascript:, data:), a
        // relative path, or an expression that produces the whole URL - including a bare
        // Rsx.Route(...) / Rsx_Portal.Route(...) call.
        if ($value === '' || !str_starts_with($value, '/')) {
            return false;
        }

        // Protocol-relative //host/path, and the site root.
        if ($value === '/' || str_starts_with($value, '//')) {
            return false;
        }

        // A value that is ENTIRELY a Route() call, wrapper included, is the compliant form.
        // (It cannot start with "/" today; the check is here so the allowance is stated
        // where the decision is made rather than inferred from the branch above.)
        if (static::_is_entirely_route_call($value)) {
            return false;
        }

        $path = strtok($value, '?#');
        $path = $path === false ? $value : $path;

        foreach (static::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // A static FILE - but only when nothing in the value is being interpolated.
        if (!static::_has_interpolation($value) && static::_has_static_extension($path)) {
            return false;
        }

        return true;
    }

    /**
     * Is the whole value one Route() call, with or without a template wrapper?
     *
     * Recognized wrappers: `<%= %>` / `<%!= %>` / `<%- %>` (jqhtml), `{{ }}` / `{!! !!}`
     * (blade), `<?= ?>` (php), `${ }` (a JS template literal). Recognized helpers:
     * Rsx::Route / Rsx.Route (staff) and Rsx_Portal::Route / Rsx_Portal.Route (portal).
     */
    protected static function _is_entirely_route_call(string $value): bool
    {
        $expression = trim($value);

        $wrappers = [
            ['<%!=', '%>'],
            ['<%=', '%>'],
            ['<%-', '%>'],
            ['{!!', '!!}'],
            ['{{', '}}'],
            ['<?=', '?>'],
            ['${', '}'],
        ];

        foreach ($wrappers as [$open, $close]) {
            if (str_starts_with($expression, $open) && str_ends_with($expression, $close)) {
                $expression = trim(substr($expression, strlen($open), -strlen($close)));
                break;
            }
        }

        return (bool) preg_match('/^(Rsx|Rsx_Portal)(::|\.)Route\s*\(.*\)$/s', $expression);
    }

    protected static function _has_interpolation(string $value): bool
    {
        foreach (static::INTERPOLATION_TOKENS as $token) {
            if (str_contains($value, $token)) {
                return true;
            }
        }

        return false;
    }

    protected static function _has_static_extension(string $path): bool
    {
        $segments = explode('/', trim($path, '/'));
        $last = end($segments);

        if ($last === false || !str_contains($last, '.')) {
            return false;
        }

        $extension = strtolower(substr($last, strrpos($last, '.') + 1));

        return in_array($extension, static::STATIC_EXTENSIONS, true);
    }

    /**
     * Does the line, or the line above it, carry the marker WITH a rationale?
     *
     * A bare marker suppresses nothing: an exception that does not say why is the thing
     * this rule exists to prevent, written one line higher.
     */
    protected static function _line_is_excepted(array $lines, int $index): bool
    {
        foreach ([$index, $index - 1] as $candidate) {
            if ($candidate < 0 || !isset($lines[$candidate])) {
                continue;
            }

            $position = strpos($lines[$candidate], '@URL-HARDCODE-01-EXCEPTION');

            if ($position === false) {
                continue;
            }

            $rationale = substr($lines[$candidate], $position + strlen('@URL-HARDCODE-01-EXCEPTION'));

            // Whatever closes the comment the marker sits in is not a rationale.
            $rationale = preg_replace('/(--%>|--\}\}|-->|\*\/|\?>|%>).*$/s', '', $rationale);
            $rationale = trim((string) $rationale, " \t-:*");

            if (preg_match('/[A-Za-z]{3}/', $rationale)) {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // Remediation
    // =====================================================================

    protected function _remediation(string $file_path, string $value, string $kind): string
    {
        $is_portal = static::_is_portal_file($file_path);
        $helper = $is_portal ? 'Rsx_Portal' : 'Rsx';
        $call = $this->_suggested_call($value, $kind, $is_portal, $helper);

        $lines = [];
        $lines[] = 'An internal path written as a literal is a link that breaks silently: it survives a route';
        $lines[] = 'rename, a mount-prefix change and a portal prefix without any error to show for it. The';
        $lines[] = 'portal case has already shipped a real navigation bug - the browser is under /_portal/,';
        $lines[] = 'Spa.dispatch strips that prefix to MATCH a route but writes the URL to history verbatim,';
        $lines[] = 'and only Rsx_Portal.Route() puts it back, so a bare /messages/3 stays bare.';
        $lines[] = '';
        $lines[] = 'Write the WHOLE value as a Route() call:';
        $lines[] = '    ' . $call;
        $lines[] = '';

        if ($is_portal) {
            $lines[] = 'This file is a portal screen (it lives under rsx/portal/), so the portal helper is the';
            $lines[] = 'one that prepends the portal prefix. A staff screen uses Rsx::Route() / Rsx.Route().';
            $lines[] = '';
        }

        $lines[] = 'A literal that is deliberately not a route - a path served by something other than the';
        $lines[] = 'router, or a target outside this application - declares itself, on the line or the line';
        $lines[] = 'above, and states why:';
        $lines[] = '    @URL-HARDCODE-01-EXCEPTION - <why this literal is correct>';
        $lines[] = '';
        $lines[] = 'Spellings, parameters and the route table: rsx:man routing';

        return implode("\n", $lines);
    }

    /**
     * Build the suggested call, naming the destination action when the literal resolves.
     */
    protected function _suggested_call(string $value, string $kind, bool $is_portal, string $helper): string
    {
        $separator = in_array($kind, ['jqhtml', 'js'], true) ? '.' : '::';
        $route = $this->_resolve_route($value, $is_portal);

        if ($route !== null) {
            $target = $route['target'];
            $params = $this->_format_params($route['params'], $kind);
        } else {
            $target = $is_portal ? '<Portal_Action_Class>' : '<Action_Class_Or_Controller::method>';
            $params = $this->_format_params($this->_guess_params($value), $kind);
        }

        $arguments = "'{$target}'" . ($params === '' ? '' : ', ' . $params);
        $expression = "{$helper}{$separator}Route({$arguments})";

        switch ($kind) {
            case 'jqhtml':
                return 'href="<%= ' . $expression . ' %>"';
            case 'blade':
                return 'href="{{ ' . $expression . ' }}"';
            case 'php':
                return "'<a href=\"' . " . $expression . " . '\">'";
            default:
                return 'href="${' . $expression . '}"   (or pass ' . $expression . ' straight to .attr(\'href\', ...))';
        }
    }

    /**
     * Match the literal against the route table the file's realm actually uses.
     *
     * An interpolated segment matches a `:param` segment - that is the whole point: the
     * literal `/contacts/view/<%= row.id %>` is `/contacts/view/:id`, and naming
     * Contacts_View_Action in the suggestion is what turns a lint finding into an edit.
     * Resolution only ENRICHES the message; it never decides whether to flag.
     *
     * @return array{target:string,params:array<string,string>}|null
     */
    protected function _resolve_route(string $value, bool $is_portal): ?array
    {
        try {
            Manifest::init();
            $data = Manifest::$data['data'] ?? [];
            $routes = $is_portal ? ($data['portal_routes'] ?? []) : ($data['routes'] ?? []);
        } catch (\Throwable $e) {
            // The suggestion degrades to the generic form; the violation still stands.
            return null;
        }

        $path = strtok(trim($value), '?#');
        $path = $path === false ? trim($value) : $path;
        $value_segments = explode('/', trim($path, '/'));

        foreach ($routes as $pattern => $route) {
            // An API endpoint is not a navigable page - naming one in an href suggestion
            // would prescribe the wrong thing.
            if (($route['type'] ?? '') === 'api') {
                continue;
            }

            $params = static::_match_pattern($pattern, $value_segments);

            if ($params === null) {
                continue;
            }

            $target = $route['js_action_class'] ?? null;

            if ($target === null) {
                $class_parts = explode('\\', $route['class'] ?? '');
                $class_name = end($class_parts);

                if ($class_name === '' || $class_name === false) {
                    continue;
                }

                $target = $class_name . '::' . ($route['method'] ?? 'index');
            }

            return ['target' => $target, 'params' => $params];
        }

        return null;
    }

    /**
     * @param array<int,string> $value_segments
     * @return array<string,string>|null Parameter map, or null when the pattern does not match
     */
    protected static function _match_pattern(string $pattern, array $value_segments): ?array
    {
        $pattern_segments = explode('/', trim($pattern, '/'));

        // Drop a trailing optional segment when the value does not supply one.
        while (count($pattern_segments) > count($value_segments)
            && str_ends_with(end($pattern_segments), '?')) {
            array_pop($pattern_segments);
        }

        if (count($pattern_segments) !== count($value_segments)) {
            return null;
        }

        $params = [];

        foreach ($pattern_segments as $index => $segment) {
            $actual = $value_segments[$index];

            if (str_starts_with($segment, ':')) {
                $params[rtrim(substr($segment, 1), '?')] = static::_expression_of($actual);
                continue;
            }

            if ($segment !== $actual) {
                return null;
            }
        }

        return $params;
    }

    /**
     * The expression a segment carries, unwrapped from its template tokens.
     *
     * `<%= row.id %>` -> `row.id`, `{{ $id }}` -> `$id`, `${id}` -> `id`. A plain literal
     * segment comes back quoted, so the suggestion is copy-pasteable either way.
     */
    protected static function _expression_of(string $segment): string
    {
        $segment = trim($segment);

        $wrappers = [
            ['<%!=', '%>'], ['<%=', '%>'], ['<%-', '%>'],
            ['{!!', '!!}'], ['{{', '}}'],
            ['<?=', '?>'], ['${', '}'],
        ];

        foreach ($wrappers as [$open, $close]) {
            if (str_starts_with($segment, $open) && str_ends_with($segment, $close)) {
                return trim(substr($segment, strlen($open), -strlen($close)));
            }
        }

        return "'" . $segment . "'";
    }

    /**
     * When nothing resolves, still name the interpolated segments so the developer can see
     * which values have to become parameters.
     *
     * @return array<string,string>
     */
    protected function _guess_params(string $value): array
    {
        $path = strtok(trim($value), '?#');
        $path = $path === false ? trim($value) : $path;

        $params = [];
        $count = 0;

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (!static::_has_interpolation($segment)) {
                continue;
            }

            $count++;
            $name = $count === 1 ? 'id' : 'param' . $count;
            $params[$name] = static::_expression_of($segment);
        }

        return $params;
    }

    /**
     * @param array<string,string> $params
     */
    protected function _format_params(array $params, string $kind): string
    {
        if ($params === []) {
            return '';
        }

        $items = [];

        if (in_array($kind, ['jqhtml', 'js'], true)) {
            foreach ($params as $name => $expression) {
                $items[] = "{$name}: {$expression}";
            }

            return '{' . implode(', ', $items) . '}';
        }

        foreach ($params as $name => $expression) {
            $items[] = "'{$name}' => {$expression}";
        }

        return '[' . implode(', ', $items) . ']';
    }

    // =====================================================================
    // File kind
    // =====================================================================

    /**
     * Portal-ness is decided by PATH, not by class analysis: a portal screen lives under
     * rsx/portal/ by convention, and the only thing that depends on the answer is which
     * helper the suggestion names.
     */
    protected static function _is_portal_file(string $file_path): bool
    {
        $normalized = str_replace('\\', '/', $file_path);

        return str_contains($normalized, '/rsx/portal/') || str_starts_with($normalized, 'rsx/portal/');
    }

    protected static function _file_kind(string $file_path): string
    {
        if (str_ends_with($file_path, '.jqhtml')) {
            return 'jqhtml';
        }

        if (str_ends_with($file_path, '.blade.php')) {
            return 'blade';
        }

        if (str_ends_with($file_path, '.php')) {
            return 'php';
        }

        return 'js';
    }

    /**
     * Remove comment bodies for the file's kind, preserving line numbers.
     */
    protected static function _strip_comments(string $content, string $kind): string
    {
        switch ($kind) {
            case 'jqhtml':
                return FileSanitizer::blank_template_comments($content);

            case 'blade':
                return FileSanitizer::blank_template_comments(
                    FileSanitizer::sanitize_php($content)['content']
                );

            case 'php':
                return FileSanitizer::sanitize_php($content)['content'];

            default:
                return FileSanitizer::blank_js_comments($content);
        }
    }
}
