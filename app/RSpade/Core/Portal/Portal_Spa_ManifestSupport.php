<?php

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Support module for extracting Portal Spa route metadata from Spa_Action classes
 *
 * Similar to Spa_ManifestSupport but for portal-specific SPA routes.
 * Portal SPA actions use the @portal_spa() decorator instead of @spa()
 * and their routes are registered in portal_routes instead of routes.
 *
 * Usage in JS action:
 * ```javascript
 * @route('/dashboard')
 * @layout('Portal_Layout')
 * @portal_spa('Portal_Spa_Controller::index')
 * class Portal_Dashboard_Action extends Spa_Action {
 *     // ...
 * }
 * ```
 */
class Portal_Spa_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Portal Spa Routes';
    }

    /**
     * Rebuild every `portal_spa` route row from the Spa_Action set.
     *
     * FULL, identical in shape to Spa_ManifestSupport and for the same reason: a row is
     * owned by TWO files (the action and its bootstrap controller), and tracking that
     * ownership across a diff was most of this method for no saving - the derivation reads
     * decorators off records already in memory.
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
            if (($row['type'] ?? null) !== 'portal_spa') {
                $manifest_data['data']['portal_routes'][$pattern] = $row;
            }
        }

        $js_classes = $manifest_data['data']['js_classes'] ?? [];
        $action_classes = $manifest_data['data']['js_subclass_index']['Spa_Action'] ?? [];

        foreach ($action_classes as $class_name) {
            $action_file = $js_classes[$class_name]['file'] ?? null;
            $action_metadata = $action_file !== null ? ($manifest_data['data']['files'][$action_file] ?? null) : null;

            if ($action_metadata === null) {
                continue;
            }

            $action_metadata['file'] = $action_file;

            static::_record_action_routes($manifest_data, $class_name, $action_metadata);
        }
    }

    /**
     * Derive and store every portal route row one Spa_Action declares.
     */
    private static function _record_action_routes(array &$manifest_data, string $class_name, array $action_metadata): void
    {
        $decorators = $action_metadata['decorators'] ?? [];
        $route_info = static::_parse_decorators($decorators);

        // Skip if no @portal_spa decorator (this is a regular SPA action)
        if (empty($route_info['portal_spa_controller'])) {
            return;
        }

        // Skip if no route decorator found
        if (empty($route_info['routes'])) {
            return;
        }

        $php_controller_class = $route_info['portal_spa_controller'];
        $php_controller_method = $route_info['portal_spa_method'];

        $controller = static::_resolve_controller($manifest_data, $php_controller_class);

        if ($controller === null) {
            throw new RuntimeException(
                "Portal Spa action '{$class_name}' references unknown controller '{$php_controller_class}'.\n" .
                "The @portal_spa decorator must reference a valid PHP controller class.\n" .
                "File: {$action_metadata['file']}"
            );
        }

        [$php_controller_file, $php_controller_fqcn] = $controller;

        // Server-side gates come from the PHP bootstrap declaration; the action's
        // @auth list rides alongside as the client gate. Both resolve against the
        // PORTAL check registry.
        $php_controller_metadata = $manifest_data['data']['files'][$php_controller_file] ?? [];
        Auth_ManifestSupport::merge_gate_lists(
            $php_controller_metadata['attributes'] ?? null,
            $php_controller_metadata['public_static_methods'][$php_controller_method]['attributes'] ?? null,
            "{$php_controller_class}::{$php_controller_method} in {$php_controller_file}"
        );

        foreach ($route_info['routes'] as $route_pattern) {
            if ($route_pattern[0] !== '/') {
                $route_pattern = '/' . $route_pattern;
            }

            // Check for duplicate portal route definition
            if (isset($manifest_data['data']['portal_routes'][$route_pattern])) {
                $existing = $manifest_data['data']['portal_routes'][$route_pattern];
                $existing_type = $existing['type'] ?? 'portal';
                $existing_location = $existing_type === 'portal_spa'
                    ? "Portal Spa action {$existing['js_action_class']} in {$existing['file']}"
                    : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                throw new RuntimeException(
                    "Duplicate portal route definition: {$route_pattern}\n" .
                    "  Already defined: {$existing_location}\n" .
                    "  Conflicting: Portal Spa action {$class_name} in {$action_metadata['file']}"
                );
            }

            // Store route with unified structure (for portal dispatcher)
            $manifest_data['data']['portal_routes'][$route_pattern] = [
                'methods' => ['GET'],  // Spa routes are always GET
                'type' => 'portal_spa',
                'class' => $php_controller_fqcn,
                'method' => $php_controller_method,
                'name' => null,
                'file' => $php_controller_file,
                'require' => [],
                'js_action_class' => $class_name,
                'pattern' => $route_pattern,
                // The gates enforced at dispatch are the BOOTSTRAP CONTROLLER's. The
                // ACTION's own gates are auth.surfaces[$class_name], reached through
                // 'target' - never copied onto the row.
                'surface' => Manifest::_normalize_class_name($php_controller_fqcn) . '::' . $php_controller_method,
                'target' => $class_name,
            ];
        }
    }

    /**
     * Resolve a @portal_spa controller name to [file, fqcn] through `php_classes`.
     *
     * @return array{0:string,1:string}|null
     */
    private static function _resolve_controller(array $manifest_data, string $name): ?array
    {
        $php_classes = $manifest_data['data']['php_classes'] ?? [];
        $simple = Manifest::_normalize_class_name($name);
        $record = $php_classes[$simple] ?? null;

        if ($record === null) {
            return null;
        }

        if (str_contains($name, '\\') && ($record['fqcn'] ?? null) !== $name) {
            return null;
        }

        return [$record['file'], $record['fqcn'] ?? $simple];
    }


    /**
     * Parse decorator metadata into route configuration
     *
     * @param array $decorators Array of decorator data from manifest
     * @return array Parsed route configuration
     */
    private static function _parse_decorators(array $decorators): array
    {
        $config = [
            'routes' => [],
            'layout' => null,
            'portal_spa_controller' => null,
            'portal_spa_method' => null,
            'auth' => [],
        ];

        foreach ($decorators as $decorator) {
            [$name, $args] = $decorator;

            switch ($name) {
                case 'auth':
                    // @auth('a', 'b') - variadic check names, AND semantics.
                    foreach ($args as $check_name) {
                        if (is_string($check_name) && $check_name !== ''
                            && !in_array($check_name, $config['auth'], true)) {
                            $config['auth'][] = $check_name;
                        }
                    }
                    break;

                case 'route':
                    // @route('/path') - args is array with single string
                    if (!empty($args[0])) {
                        $config['routes'][] = $args[0];
                    }
                    break;

                case 'layout':
                    // @layout('Layout_Name') - args is array with single string
                    if (!empty($args[0])) {
                        $config['layout'] = $args[0];
                    }
                    break;

                case 'portal_spa':
                    // @portal_spa('Controller::method') - args is array with single string
                    if (!empty($args[0])) {
                        $parts = explode('::', $args[0]);
                        if (count($parts) === 2) {
                            $config['portal_spa_controller'] = $parts[0];
                            $config['portal_spa_method'] = $parts[1];
                        }
                    }
                    break;
            }
        }

        return $config;
    }
}
