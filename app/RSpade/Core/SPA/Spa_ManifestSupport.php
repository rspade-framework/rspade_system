<?php

namespace App\RSpade\Core\SPA;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module for extracting Spa route metadata from Spa_Action classes
 * This runs after the primary manifest is built to add Spa routes to the unified routes index
 *
 * An SPA route row carries TWO gate lists, because two different surfaces answer for
 * one URL:
 *   'auth'        - the PHP bootstrap method's gates (class-level #[Auth] on the SPA
 *                   controller then the #[SPA] method's own). The server dispatcher
 *                   evaluates these before rendering the bootstrap.
 *   'auth_action' - the JS action's @auth(...) check names. These are the CLIENT
 *                   gate (Spa.dispatch resolves them against the render-time auth
 *                   snapshot); the server never renders a denial for them.
 * See php artisan rsx:man auth_gates.
 */
class Spa_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Spa Routes';
    }

    /**
     * Rebuild the `spa` route rows for the CHANGED action files only.
     *
     * INCREMENTAL. An SPA row is owned by TWO files - the JS action that declares the route
     * and the PHP bootstrap controller whose gates the row names - so the dirty set is the
     * union: actions whose own file changed, plus actions whose existing row points at a
     * changed controller file. Everything else keeps the row it already had.
     *
     * The controller is resolved through `php_classes` (one lookup), never by scanning the
     * file map, and the action set comes from `js_subclass_index`.
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
        $js_classes = $manifest_data['data']['js_classes'] ?? [];
        $action_classes = $manifest_data['data']['js_subclass_index']['Spa_Action'] ?? [];

        $dirty_actions = [];
        foreach ($action_classes as $class_name) {
            $action_file = $js_classes[$class_name]['file'] ?? null;

            if ($action_file !== null && isset($dirty[$action_file])) {
                $dirty_actions[$class_name] = true;
            }
        }

        // Drop the rows that have to be re-derived: an action whose file changed, an action
        // whose BOOTSTRAP CONTROLLER changed (the row carries the controller's file, class
        // and surface), and an action that is no longer a class at all.
        foreach ($manifest_data['data']['routes'] as $pattern => $row) {
            if (($row['type'] ?? null) !== 'spa') {
                continue;
            }

            $action = $row['js_action_class'] ?? null;

            if ($action === null || !isset($js_classes[$action])) {
                unset($manifest_data['data']['routes'][$pattern]);

                continue;
            }

            if (isset($dirty_actions[$action]) || isset($dirty[$row['file'] ?? ''])) {
                $dirty_actions[$action] = true;
                unset($manifest_data['data']['routes'][$pattern]);
            }
        }

        foreach (array_keys($dirty_actions) as $class_name) {
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
     * Derive and store every route row one Spa_Action declares.
     */
    private static function _record_action_routes(array &$manifest_data, string $class_name, array $action_metadata): void
    {
        // Extract decorator metadata
        $decorators = $action_metadata['decorators'] ?? [];

        // Parse decorators into route configuration
        $route_info = static::_parse_decorators($decorators);

        // Skip if this is a portal SPA action (handled by Portal_Spa_ManifestSupport)
        if (!empty($route_info['is_portal_spa'])) {
            return;
        }

        // Skip if no route decorator found
        if (empty($route_info['routes'])) {
            return;
        }

        // Validate that @spa decorator is present
        if (empty($route_info['spa_controller']) || empty($route_info['spa_method'])) {
            throw new RuntimeException(
                "Spa action '{$class_name}' is missing required @spa decorator.\n" .
                "Add @spa('Controller_Class::method') to specify the PHP controller method that serves the Spa bootstrap.\n" .
                "File: {$action_metadata['file']}"
            );
        }

        $php_controller_class = $route_info['spa_controller'];
        $php_controller_method = $route_info['spa_method'];

        $controller = static::_resolve_controller($manifest_data, $php_controller_class);

        if ($controller === null) {
            throw new RuntimeException(
                "Spa action '{$class_name}' references unknown controller '{$php_controller_class}'.\n" .
                "The @spa decorator must reference a valid PHP controller class.\n" .
                "File: {$action_metadata['file']}"
            );
        }

        [$php_controller_file, $php_controller_fqcn] = $controller;

        // Server-side gates come from the PHP bootstrap declaration; the action's
        // @auth list rides alongside as the client gate.
        $php_controller_metadata = $manifest_data['data']['files'][$php_controller_file] ?? [];
        Auth_ManifestSupport::merge_gate_lists(
            $php_controller_metadata['attributes'] ?? null,
            $php_controller_metadata['public_static_methods'][$php_controller_method]['attributes'] ?? null,
            "{$php_controller_class}::{$php_controller_method} in {$php_controller_file}"
        );

        // Build complete route metadata for each route pattern
        foreach ($route_info['routes'] as $route_pattern) {
            // Ensure pattern starts with /
            if ($route_pattern[0] !== '/') {
                $route_pattern = '/' . $route_pattern;
            }

            // The /api/vN namespace is reserved for #[Api_Endpoint] only.
            if (preg_match('#^/api/v[0-9]+(/|$)#', $route_pattern)) {
                throw new RuntimeException(
                    "Reserved route pattern: {$route_pattern}\n" .
                    "  Spa action {$class_name} in {$action_metadata['file']}\n" .
                    "  /api/vN is reserved for #[Api_Endpoint]; SPA routes may not use it."
                );
            }

            // Check for duplicate route definition (pattern must be unique across all route types)
            if (isset($manifest_data['data']['routes'][$route_pattern])) {
                $existing = $manifest_data['data']['routes'][$route_pattern];
                $existing_type = $existing['type'];
                $existing_location = $existing_type === 'spa'
                    ? "Spa action {$existing['js_action_class']} in {$existing['file']}"
                    : "{$existing['class']}::{$existing['method']} in {$existing['file']}";

                throw new RuntimeException(
                    "Duplicate route definition: {$route_pattern}\n" .
                    "  Already defined: {$existing_location}\n" .
                    "  Conflicting: Spa action {$class_name} in {$action_metadata['file']}"
                );
            }

            // Store route with unified structure (for dispatcher)
            $manifest_data['data']['routes'][$route_pattern] = [
                'methods' => ['GET'],  // Spa routes are always GET
                'type' => 'spa',
                'class' => $php_controller_fqcn,
                'method' => $php_controller_method,
                'name' => null,
                'file' => $php_controller_file,
                'js_action_class' => $class_name,
                'pattern' => $route_pattern,
                // The gates enforced at dispatch are the BOOTSTRAP CONTROLLER's, so the
                // surface is the controller's, not the action's. The action's own gates
                // are auth.surfaces[$class_name] and stay named here as auth_action.
                'surface' => Manifest::_normalize_class_name($php_controller_fqcn) . '::' . $php_controller_method,
                // For SPA, the URL-generation target is the JS action class name.
                'target' => $class_name,
                'auth_action' => $route_info['auth'],
            ];
        }
    }

    /**
     * Resolve a @spa controller name to [file, fqcn] through `php_classes`.
     *
     * The decorator may name the simple class or the FQCN; both are one lookup, because the
     * class map is keyed by simple name and carries the FQCN. This used to be a linear scan
     * of the whole file map, once per action.
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

        // An FQCN in the decorator must be the FQCN the manifest records for that name.
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
            'spa_controller' => null,
            'spa_method' => null,
            'is_portal_spa' => false,
            'auth' => [],
        ];

        foreach ($decorators as $decorator) {
            [$name, $args] = $decorator;

            switch ($name) {
                case 'auth':
                    // @auth('a', 'b') - variadic check names, AND semantics. Every
                    // argument is collected; a repeated decorator merges (tolerant).
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
                    // @portal_spa decorator - handled by Portal_Spa_ManifestSupport, skip here
                    $config['is_portal_spa'] = true;
                    break;

                case 'spa':
                    // @spa('Controller::method') - args is array with single string
                    if (!empty($args[0])) {
                        $parts = explode('::', $args[0]);
                        if (count($parts) === 2) {
                            $config['spa_controller'] = $parts[0];
                            $config['spa_method'] = $parts[1];
                        }
                    }
                    break;
            }
        }

        return $config;
    }
}
