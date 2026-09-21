<?php

namespace App\RSpade\Core\Dispatch;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;

/**
 * Support module for building routes index from #[Route] attributes
 * This runs after the primary manifest is built to create routes index
 *
 * Each route row carries an 'auth' key: the declarative gate list (class-level
 * #[Auth] then method-level, additive) the dispatcher evaluates before the
 * controller runs. See php artisan rsx:man auth_gates.
 *
 * ROUTE-ERROR-01 lives here (and is called by the portal twin, which declares the
 * same pages for its own realm). /error/ is the reserved prefix an application
 * declares its error pages under, and a declaration in it must be one the error
 * funnel can actually reach:
 *
 *   - the pattern is exactly /error/<3-digit status, 400-599> or /error/generic
 *   - GET only, and no :param - the funnel renders one status, with no URL to read
 *   - the method or its class carries #[Auth('public')] - an error page is shown to
 *     a caller who has just been denied, and a gated one would deny them again
 *
 * A malformed declaration is a manifest-build FATAL rather than a page that
 * silently never renders. See php artisan rsx:man error_pages.
 */
class Route_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Routes';
    }

    /**
     * Rebuild every `standard` route row from the whole file map.
     *
     * FULL, not incremental, and deliberately so. Deriving these rows is a loop over the
     * in-memory file records looking for one attribute - it reads nothing off disk and
     * reflects on nothing - so the changed-set machinery bought nothing here and cost
     * correctness: the table was CARRIED FORWARD, so once it was lost or truncated no
     * later build could restore it, because restoration only happened for files that
     * CHANGED and an unchanged tree has none. That state was reached (an empty route table
     * against a fully populated file index: every page 404, every bundle failing to
     * compile, curable only by `--force`, which works by making everything dirty).
     * Recomputed in full it is a pure function of the manifest and cannot drift.
     *
     * THIS MODULE OWNS THE `standard` ROWS AND NOTHING ELSE. `routes` is shared - the SPA
     * and API modules add their own row types to it later in the same ordered list - so
     * the reset below is scoped by type rather than clearing the section.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function rebuild(array &$manifest_data): void
    {
        $existing = $manifest_data['data']['routes'] ?? [];

        // Keep every row this module does not own, drop all of its own, and re-derive.
        $manifest_data['data']['routes'] = [];
        foreach ($existing as $pattern => $row) {
            if (($row['type'] ?? null) !== 'standard') {
                $manifest_data['data']['routes'][$pattern] = $row;
            }
        }

        $files = $manifest_data['data']['files'];

        foreach (array_keys($files) as $file) {
            $metadata = $files[$file] ?? null;

            if ($metadata === null || !isset($metadata['public_static_methods'])) {
                continue;
            }

            foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                foreach (($method_data['attributes'] ?? []) as $attr_name => $attr_instances) {
                    // Check if this is a Route attribute (ends with \Route or is just Route)
                    if (!str_ends_with($attr_name, '\\Route') && $attr_name !== 'Route') {
                        continue;
                    }

                    static::_record_route_rows($manifest_data, $files, $file, $metadata, $method_name, $attr_instances);
                }
            }
        }

        // Sort routes alphabetically by path to ensure deterministic behavior and prevent race condition bugs
        ksort($manifest_data['data']['routes']);
    }

    /**
     * Store every row one #[Route]-annotated method declares.
     */
    private static function _record_route_rows(
        array &$manifest_data,
        array $files,
        string $file,
        array $metadata,
        string $method_name,
        array $attr_instances
    ): void {
        $class = $metadata['class'] ?? null;
        $fqcn = $metadata['fqcn'] ?? null;

        foreach ($attr_instances as $route_args) {
            $pattern = $route_args[0] ?? ($route_args['pattern'] ?? null);
            $methods = $route_args[1] ?? ($route_args['methods'] ?? ['GET']);
            $name = $route_args[2] ?? ($route_args['name'] ?? null);

            if (!$pattern) {
                continue;
            }

            // Ensure pattern starts with /
            if ($pattern[0] !== '/') {
                $pattern = '/' . $pattern;
            }

            // The /api/vN namespace is reserved for #[Api_Endpoint] only.
            if (preg_match('#^/api/v[0-9]+(/|$)#', $pattern)) {
                throw new \RuntimeException(
                    "Reserved route pattern: {$pattern}\n" .
                    "  {$fqcn}::{$method_name} in {$file}\n" .
                    "  /api/vN is reserved for #[Api_Endpoint]; use #[Api_Endpoint] instead of #[Route]."
                );
            }

            // Declarative auth gates are VALIDATED here and STORED once, in
            // auth.surfaces. merge_gate_lists() still runs because it is what
            // raises a contradiction; its result is not copied onto the row.
            $file_metadata = $files[$file] ?? [];
            $gates = Auth_ManifestSupport::merge_gate_lists(
                $file_metadata['attributes'] ?? null,
                $file_metadata['public_static_methods'][$method_name]['attributes'] ?? null,
                "{$fqcn}::{$method_name} in {$file}"
            );

            static::assert_error_route_shape($pattern, (array) $methods, $gates, $fqcn, $method_name, $file);

            // The surface key auth.surfaces is keyed by, and the target
            // routes_by_target is grouped by at load. Both are the SIMPLE class
            // name plus the method - the row's own `class` is the FQCN.
            $surface = $class . '::' . $method_name;

            // Check for duplicate route definition (pattern must be unique across all route types)
            if (isset($manifest_data['data']['routes'][$pattern])) {
                $existing = $manifest_data['data']['routes'][$pattern];
                $existing_type = $existing['type'];
                $existing_location = $existing_type === 'spa'
                    ? "SPA action {$existing['js_action_class']} in {$existing['file']}"
                    : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                throw new \RuntimeException(
                    "Duplicate route definition: {$pattern}\n" .
                    "  Already defined: {$existing_location}\n" .
                    "  Conflicting: {$fqcn}::{$method_name} in {$file}"
                );
            }

            // Store route with flat structure (for dispatcher)
            $route_data = [
                'methods' => array_map('strtoupper', (array) $methods),
                'type' => 'standard',
                'class' => $fqcn ?? $class,
                'method' => $method_name,
                'name' => $name,
                'file' => $file,
                'pattern' => $pattern,
                'surface' => $surface,
                'target' => $surface,
            ];

            // #[FPC] is a ROUTING FACT, so it is baked onto the row - the flag AND the
            // declared TTL. The dispatcher used to answer it by pulling the handler's
            // whole method map on every matched request, and the TTL used to be an
            // environment value the proxy read behind everybody's back.
            $fpc_instances = $file_metadata['public_static_methods'][$method_name]['attributes']['FPC'] ?? null;

            if ($fpc_instances !== null) {
                $route_data['fpc'] = true;
                $route_data['fpc_ttl_mins'] = static::_fpc_ttl_minutes($fpc_instances);
            }

            $manifest_data['data']['routes'][$pattern] = $route_data;
        }
    }

    /**
     * ROUTE-ERROR-01: a declaration under the reserved /error/ prefix must be one
     * the error funnel can reach.
     *
     * Called by this module and by Portal_Route_ManifestSupport - the rule is the
     * same in both realms, and one implementation is what keeps them the same.
     * Patterns outside the prefix return immediately.
     *
     * @param string $pattern The route pattern, leading slash included
     * @param array $methods The declared HTTP methods
     * @param array $gates The merged #[Auth] check names for the surface
     * @param string|null $fqcn Declaring class
     * @param string $method_name Declaring method
     * @param string $file Declaring file
     * @return void
     */
    public static function assert_error_route_shape(
        string $pattern,
        array $methods,
        array $gates,
        ?string $fqcn,
        string $method_name,
        string $file
    ): void {
        if (!str_starts_with($pattern, \App\RSpade\Core\Errors\Error_Pages::PREFIX)) {
            return;
        }

        $suffix = substr($pattern, strlen(\App\RSpade\Core\Errors\Error_Pages::PREFIX));

        if ($suffix !== 'generic' && !preg_match('/^[45][0-9][0-9]$/', $suffix)) {
            static::_throw_error_route_violation(
                $fqcn,
                $method_name,
                $file,
                "'{$pattern}' is not a page the error funnel looks up.",
                'Declare the pattern as /error/<status 400-599> (one segment, no :param) or /error/generic.'
            );
        }

        $declared = array_map('strtoupper', $methods);
        if ($declared !== ['GET']) {
            static::_throw_error_route_violation(
                $fqcn,
                $method_name,
                $file,
                "'{$pattern}' declares " . implode(', ', $declared) . '; an error page is rendered by GET only.',
                'Remove the methods argument, or declare methods: [\'GET\'].'
            );
        }

        if (!in_array('public', $gates, true)) {
            static::_throw_error_route_violation(
                $fqcn,
                $method_name,
                $file,
                "'{$pattern}' is gated; an error page is shown to a caller who has just been denied.",
                "Declare #[Auth('public')] on the method or on its class."
            );
        }
    }

    /**
     * Raise one ROUTE-ERROR-01 violation.
     *
     * @param string|null $fqcn
     * @param string $method_name
     * @param string $file
     * @param string $problem What is wrong
     * @param string $remedy What to do about it
     * @return void
     */
    private static function _throw_error_route_violation(
        ?string $fqcn,
        string $method_name,
        string $file,
        string $problem,
        string $remedy
    ): void {
        throw new \RuntimeException(
            "Invalid error-page route: {$fqcn}::{$method_name} in {$file}\n" .
            "  ROUTE-ERROR-01: {$problem}\n" .
            "  {$remedy}\n" .
            '  See: php artisan rsx:man error_pages'
        );
    }

    /**
     * The TTL a #[FPC] declared, in minutes. 0 (the default, and what a bare #[FPC]
     * means) is "until something clears it".
     *
     * Positional or named: #[FPC(5)] and #[FPC(ttl: 5)] are the same declaration. The
     * VALUE is validated at build time by Manifest_Store, which throws on anything but a
     * non-negative integer literal - so by the time a row is baked the argument is known
     * good and this only has to read it.
     *
     * @param array $instances The attribute instances as the scanner recorded them
     */
    protected static function _fpc_ttl_minutes(array $instances): int
    {
        $args = $instances[0] ?? [];

        if (!is_array($args)) {
            return 0;
        }

        return (int) ($args['ttl'] ?? $args[0] ?? 0);
    }
}
