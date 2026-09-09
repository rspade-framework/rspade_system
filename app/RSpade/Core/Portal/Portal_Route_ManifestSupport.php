<?php

namespace App\RSpade\Core\Portal;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;

/**
 * Support module for building portal routes index from #[Portal_Route] attributes
 *
 * Similar to Route_ManifestSupport but for portal-specific routes.
 * Portal routes are stored separately in the manifest and handled by
 * the portal dispatcher.
 *
 * Usage in controllers:
 * ```php
 * #[Portal_Route('/dashboard')]
 * public static function index(Request $request, array $params = []) { ... }
 *
 * #[Portal_Route('/projects/:id', methods: ['GET'])]
 * public static function view(Request $request, array $params = []) { ... }
 * ```
 */
class Portal_Route_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Portal Routes';
    }

    /**
     * Rebuild every `portal` route row from the whole file map.
     *
     * FULL, the same shape as Route_ManifestSupport and for the same reason: deriving these
     * rows is a loop over in-memory records looking for one attribute, so the changed-set
     * machinery bought nothing and cost correctness - a carried-forward section that is
     * lost can only be restored by files CHANGING, and an unchanged tree has none.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function rebuild(array &$manifest_data): void
    {
        $existing = $manifest_data['data']['portal_routes'] ?? [];

        // Keep every row this module does not own, drop all of its own, and re-derive.
        $manifest_data['data']['portal_routes'] = [];
        foreach ($existing as $pattern => $row) {
            if (($row['type'] ?? null) !== 'portal') {
                $manifest_data['data']['portal_routes'][$pattern] = $row;
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
                    if (!str_ends_with($attr_name, '\\Portal_Route') && $attr_name !== 'Portal_Route') {
                        continue;
                    }

                    static::_record_portal_route_rows($manifest_data, $files, $file, $metadata, $method_name, $attr_instances);
                }
            }
        }

        // Sort routes alphabetically by path
        ksort($manifest_data['data']['portal_routes']);
    }

    /**
     * Store every row one #[Portal_Route]-annotated method declares.
     */
    private static function _record_portal_route_rows(
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

            if ($pattern[0] !== '/') {
                $pattern = '/' . $pattern;
            }

            // Extract Auth attributes for this method (portal-specific auth would use Portal_Auth or similar)
            $require_attrs = [];
            $file_metadata = $files[$file] ?? null;
            if ($file_metadata && isset($file_metadata['public_static_methods'][$method_name]['attributes']['Portal_Auth'])) {
                $require_attrs = $file_metadata['public_static_methods'][$method_name]['attributes']['Portal_Auth'];
            }

            // Declarative auth gates, resolved against the PORTAL check
            // registry: class-level #[Auth] then the method's own.
            Auth_ManifestSupport::merge_gate_lists(
                $file_metadata['attributes'] ?? null,
                $file_metadata['public_static_methods'][$method_name]['attributes'] ?? null,
                "{$fqcn}::{$method_name} in {$file}"
            );

            // Check for duplicate portal route definition
            if (isset($manifest_data['data']['portal_routes'][$pattern])) {
                $existing = $manifest_data['data']['portal_routes'][$pattern];
                $existing_location = "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                throw new \RuntimeException(
                    "Duplicate portal route definition: {$pattern}\n" .
                    "  Already defined: {$existing_location}\n" .
                    "  Conflicting: {$fqcn}::{$method_name} in {$file}"
                );
            }

            // Store route with flat structure (for portal dispatcher)
            $manifest_data['data']['portal_routes'][$pattern] = [
                'methods' => array_map('strtoupper', (array) $methods),
                'type' => 'portal',
                'class' => $fqcn ?? $class,
                'method' => $method_name,
                'name' => $name,
                'file' => $file,
                'require' => $require_attrs,
                'pattern' => $pattern,
                // The gate list lives once, in auth.surfaces, keyed by this.
                'surface' => $class . '::' . $method_name,
                'target' => $class . '::' . $method_name,
            ];
        }
    }
}
