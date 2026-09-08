<?php

namespace App\RSpade\Core\Dispatch;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for building routes index from #[Route] attributes
 * This runs after the primary manifest is built to create routes index
 *
 * Each route row carries an 'auth' key: the declarative gate list (class-level
 * #[Auth] then method-level, additive) the dispatcher evaluates before the
 * controller runs. See php artisan rsx:man auth_gates.
 */
class Route_ManifestSupport extends ManifestSupport_Abstract
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
     * Rebuild the `standard` route rows for the CHANGED controller files only.
     *
     * INCREMENTAL. A row is owned by exactly one file (its `file` value), so the diff is
     * exact: drop every standard row whose file changed or was removed, then re-derive from
     * the changed files' own `#[Route]` declarations. Nothing scans the file map.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        if (!isset($manifest_data['data']['routes'])) {
            $manifest_data['data']['routes'] = [];
        }

        $dirty = static::dirty_set($changed_files, $removed_files);

        // Drop what the dirty files used to declare. A pattern that moved to another file
        // is covered because BOTH files are dirty in the build that moved it.
        foreach ($manifest_data['data']['routes'] as $pattern => $row) {
            if (($row['type'] ?? null) === 'standard' && isset($dirty[$row['file'] ?? ''])) {
                unset($manifest_data['data']['routes'][$pattern]);
            }
        }

        $files = $manifest_data['data']['files'];

        foreach (array_keys($dirty) as $file) {
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
            Auth_ManifestSupport::merge_gate_lists(
                $file_metadata['attributes'] ?? null,
                $file_metadata['public_static_methods'][$method_name]['attributes'] ?? null,
                "{$fqcn}::{$method_name} in {$file}"
            );

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

            // #[FPC] is a ROUTING FACT, so it is baked onto the row. The
            // dispatcher used to answer it by pulling the handler's whole method
            // map on every matched request.
            if (isset($file_metadata['public_static_methods'][$method_name]['attributes']['FPC'])) {
                $route_data['fpc'] = true;
            }

            $manifest_data['data']['routes'][$pattern] = $route_data;
        }
    }
}
