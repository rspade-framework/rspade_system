<?php

namespace App\RSpade\CodeQuality\Rules\JavaScript;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\CodeQuality\Support\Js_CodeQuality_Rpc;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * SPA-VIA-01 - the reserved breadcrumb-origin parameter `_via` and its declarations.
 *
 * `_via` is a framework-reserved query parameter (rsx:man breadcrumb_origins): the framework
 * carries it across navigation, the breadcrumb resolver consumes it, and it never reaches an
 * action's `this.args`. A page that consumes an origin declares it with TWO members that are
 * one declaration: `static breadcrumb_via = ['type', ...]` and the instance hook
 * `breadcrumb_parent_via(via)`. FATAL at manifest build, because each of these is broken code
 * that would otherwise fail silently at run time:
 *
 *   - a route declaring a `:_via` parameter (`#[Route]`, `#[Portal_Route]`, `@route`) - the
 *     framework would strip or carry it as an origin;
 *   - JavaScript reading `args._via` / `this.args._via` / `args['_via']`, or destructuring
 *     `_via` out of an args object - it is always undefined there; `Spa.via()` is the reader;
 *   - `breadcrumb_via` without an instance `breadcrumb_parent_via()` in the same class, or
 *     the reverse - the list without the hook never grafts, the hook without the list is
 *     never called; both are inherited, so a subclass that re-declares one re-declares both;
 *   - a `breadcrumb_via` that is not an array literal of strings, or an entry outside the
 *     token type grammar `[a-z][a-z0-9_]*` - such a type could never match a token.
 *
 * JavaScript files whose bytes never mention `_via` are skipped before any analysis, so the
 * AST pass (in the node service, `quality.analyze_via`) runs only for the few that do; a
 * PHP file is judged from the route attributes the manifest already holds.
 */
class SpaVia_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function get_id(): string
    {
        return 'SPA-VIA-01';
    }

    public function get_name(): string
    {
        return 'Breadcrumb Origin (_via) Declarations';
    }

    public function get_description(): string
    {
        return 'The reserved _via parameter is never a route param or read from args; breadcrumb_via and breadcrumb_parent_via() are declared together and validly';
    }

    public function get_file_patterns(): array
    {
        return ['*.js', '*.php'];
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
     * The JavaScript analysis lives in the node service, so its source is part of every verdict.
     */
    public function fingerprint_extra(): string
    {
        $service = base_path('app/RSpade/CodeQuality/Support/resource/quality-service.js');

        return is_file($service) ? md5_file($service) : 'missing';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        foreach (['/vendor/', '/node_modules/', '/.cdn-cache/', '/resource/'] as $excluded) {
            if (str_contains($file_path, $excluded)) {
                return;
            }
        }

        if (str_ends_with($file_path, '.php')) {
            $this->check_php_routes($file_path, $metadata);
            return;
        }

        if (str_ends_with($file_path, '.min.js')) {
            return;
        }

        // The RAW bytes decide whether to analyze: the sanitized $contents blanks string
        // literals, and a route pattern is one.
        if (!str_contains($this->source()->content($file_path, (string) ($metadata['hash'] ?? '')), '_via')) {
            return;
        }

        // A manifest key is project-logical; the node service reads an absolute path.
        $this->check_js($file_path, Js_CodeQuality_Rpc::analyze_via(Rsx_Paths::absolute($file_path)));
    }

    /**
     * `#[Route]` / `#[Portal_Route]` patterns with a `:_via` segment.
     */
    private function check_php_routes(string $file_path, array $metadata): void
    {
        foreach ($metadata['public_static_methods'] ?? [] as $method_name => $method_data) {
            foreach ($method_data['attributes'] ?? [] as $attr_name => $instances) {
                $short = substr((string) $attr_name, (int) strrpos('\\' . $attr_name, '\\'));

                if ($short !== 'Route' && $short !== 'Portal_Route') {
                    continue;
                }

                foreach ($instances as $args) {
                    $pattern = $args[0] ?? null;

                    if (is_string($pattern) && static::pattern_declares_via($pattern)) {
                        $this->fatal(
                            $file_path,
                            null,
                            "#[{$short}('{$pattern}')] on {$method_name}() declares a :_via parameter.",
                            "_via is the framework's reserved breadcrumb-origin query parameter. Rename the\n"
                            . "route parameter - any other name works."
                        );
                    }
                }
            }
        }
    }

    private function check_js(string $file_path, array $analysis): void
    {
        foreach ($analysis['routes'] as $route) {
            $this->fatal(
                $file_path,
                (int) $route['line'],
                "@route('{$route['pattern']}') declares a :_via parameter.",
                "_via is the framework's reserved breadcrumb-origin query parameter. Rename the\n"
                . "route parameter - any other name works."
            );
        }

        foreach ($analysis['args_reads'] as $line) {
            $this->fatal(
                $file_path,
                (int) $line,
                'This code reads _via from an args object.',
                "_via is the framework's reserved breadcrumb-origin parameter and never reaches\n"
                . "this.args - the read is always undefined. A page that consumes an origin declares\n"
                . "static breadcrumb_via and receives its token in breadcrumb_parent_via(via); a\n"
                . "layout or a diagnostic reads the current origins with Spa.via(). A value the page\n"
                . "needs as DATA (a record to pre-fill) is its own argument, never an origin."
            );
        }

        foreach ($analysis['classes'] as $class) {
            $name = $class['name'];
            $via = $class['via'] ?? null;
            $hook = $class['hook'] ?? null;

            if ($via !== null && ($hook === null || !empty($hook['is_static']))) {
                $this->fatal(
                    $file_path,
                    (int) $via['line'],
                    "{$name} declares static breadcrumb_via without an instance breadcrumb_parent_via() method.",
                    "The two are ONE declaration: the list says which origin types the page accepts, the\n"
                    . "hook verifies a token against the page's data and names the alternate parent:\n\n"
                    . "    static breadcrumb_via = ['engagement'];\n"
                    . "    async breadcrumb_parent_via(via) {\n"
                    . "        await this.await_loaded();\n"
                    . "        if (this.data.invoice.engagement_id !== int(via.id)) return null;\n"
                    . "        return Rsx.Route('Engagements_View_Action', via.id);\n"
                    . "    }"
                );
            }

            if ($hook !== null && $via === null) {
                $this->fatal(
                    $file_path,
                    (int) $hook['line'],
                    "{$name} declares breadcrumb_parent_via() without static breadcrumb_via.",
                    "The hook is only called for origin types the class lists in static breadcrumb_via.\n"
                    . "Declare the list beside it (both are inherited, so a subclass that overrides the\n"
                    . "hook re-declares the list too):\n\n"
                    . "    static breadcrumb_via = ['engagement'];"
                );
            }

            if ($via !== null && $via['entries'] === null) {
                $this->fatal(
                    $file_path,
                    (int) $via['line'],
                    "{$name}'s static breadcrumb_via is not an array literal of strings.",
                    "Write the origin types out literally, so the build can check them:\n\n"
                    . "    static breadcrumb_via = ['engagement', 'contact'];"
                );
            }

            foreach ($via['invalid'] ?? [] as $entry) {
                $this->fatal(
                    $file_path,
                    (int) $via['line'],
                    "{$name}'s static breadcrumb_via lists '{$entry}', which is not a valid origin type.",
                    "An origin type is [a-z][a-z0-9_]* - lowercase, starting with a letter. The token in\n"
                    . "the URL (_via=engagement.156) can only ever match such a type."
                );
            }
        }
    }

    /**
     * Does a route pattern have a `:_via` segment (optional or not)?
     */
    public static function pattern_declares_via(string $pattern): bool
    {
        foreach (explode('/', $pattern) as $segment) {
            if (rtrim($segment, '?') === ':_via') {
                return true;
            }
        }

        return false;
    }

    private function fatal(string $file_path, ?int $line, string $problem, string $fix): void
    {
        $message = "==========================================\n";
        $message .= "FATAL: SPA-VIA-01 - breadcrumb origin (_via)\n";
        $message .= "==========================================\n\n";
        $message .= "File: {$file_path}\n";
        if ($line !== null) {
            $message .= "Line: {$line}\n";
        }
        $message .= "\nPROBLEM:\n{$problem}\n\n";
        $message .= "FIX:\n{$fix}\n\n";
        $message .= "See: php artisan rsx:man breadcrumb_origins\n";
        $message .= "==========================================";

        throw new YoureDoingItWrongException($message);
    }
}
