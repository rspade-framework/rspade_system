<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * _Manifest_Reflection_Helper - View, attribute, and route resolution
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Reflection_Helper
{
    /**
    * The path of a Blade view, by its @rsx_id.
    *
    * One lookup in `blade_views`. It used to scan every indexed file for a matching `id`, on
    * every hop of every layout chain of every rendered page, and to raise the DUPLICATE-ID
    * error at render time - which is a build-time fact, and is now a build failure naming
    * both files (_Manifest_Builder_Helper::_build_blade_view_index()).
    */
    public static function find_view(string $id): string
    {
        Manifest::init();

        $path = Manifest::$data['data']['blade_views'][$id] ?? null;

        if ($path === null) {
            throw new \RuntimeException("View not found in manifest: {$id}");
        }

        return $path;
    }

    /**
    * Whether a Blade view id is indexed. The non-throwing half of find_view(), for callers
    * that are ASKING rather than resolving.
    */
    public static function view_exists(string $id): bool
    {
        Manifest::init();

        return isset(Manifest::$data['data']['blade_views'][$id]);
    }

    /**
    * Find a view by RSX ID (path-agnostic identifier)
    */
    public static function find_view_by_rsx_id(string $id): string
    {
        // This method now properly checks for duplicates
        return Manifest::find_view($id);
    }

    /**
    * Get path for a file by its filename only (quick and dirty lookup)
    *
    * This is a convenience method for finding files when you know the filename is unique.
    * Only works for files in the /rsx directory. Fatal errors if:
    * - File not found in manifest
    * - Multiple files with the same name exist
    * - File is outside /rsx directory
    *
    * @param string $filename Just the filename with extension (e.g., "Counter_Widget.jqhtml")
    * @return string The relative path to the file (e.g., "rsx/app/demo/components/Counter_Widget.jqhtml")
    * @throws RuntimeException If file not found, multiple matches, or outside /rsx
    */
    public static function get_path_by_filename(string $filename): string
    {
        $files = Manifest::get_all();

        $matches = [];

        foreach ($files as $path => $metadata) {
            // Only consider files in /rsx directory
            if (!Rsx_Paths::is_application($path)) {
                continue;
            }

            // Extract just the filename from the path
            $file_basename = basename($path);

            if ($file_basename === $filename) {
                $matches[] = $path;
            }
        }

        if (empty($matches)) {
            throw new \RuntimeException(
                "Fatal: File not found in manifest: {$filename}\n" .
'This method only searches files in the /rsx directory.'
            );
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(
                "Fatal: Multiple files with name '{$filename}' found in manifest:\n" .
'  - ' . implode("\n  - ", $matches) . "\n" .
'This method requires unique filenames.'
            );
        }

        return $matches[0];
    }

    /**
    * Every class and member declaration carrying an attribute, as REFERENCES.
    *
    * Rows are ['file' => ..., 'class' => ?string, 'member' => ?string, 'instances' => [...]],
    * straight out of `attribute_index` - no file record is touched, so a caller asking about
    * `#[Emitter]` or `#[Schedule]` does not load the cold half of the index to learn where
    * they are. The name is matched by its SIMPLE spelling, which is how RSX writes attributes
    * everywhere else; a namespaced argument is reduced to it.
    *
    * @return array<int, array{file: string, class: ?string, member: ?string, instances: array}>
    */
    public static function by_attribute(string $attribute_name): array
    {
        Manifest::init();

        $simple = Manifest::_normalize_class_name($attribute_name);

        return Manifest::$data['data']['attribute_index'][$simple] ?? [];
    }

    /**
    * Get all classes with a specific attribute.
    *
    * The shape callers have always seen (file / class / fqcn / type / method / instances),
    * assembled from `by_attribute()` plus the class map. It used to walk every file and every
    * method map in the index.
    */
    public static function get_with_attribute(string $attribute_class): array
    {
        $results = [];

        foreach (self::by_attribute($attribute_class) as $row) {
            $record = $row['class'] !== null
                ? (Manifest::$data['data']['php_classes'][$row['class']] ?? null)
                : null;

            $result = [
                'file' => $row['file'],
                'class' => $row['class'],
                'fqcn' => $record['fqcn'] ?? null,
                'type' => $row['member'] === null ? 'class' : 'method',
                'instances' => $row['instances'],
            ];

            if ($row['member'] !== null) {
                $result['method'] = $row['member'];
            }

            $results[] = $result;
        }

        // Sort alphabetically by class name to ensure deterministic behavior and prevent race condition bugs
        usort($results, function ($a, $b) {
            return strcmp($a['class'] ?? '', $b['class'] ?? '');
        });

        return $results;
    }

    /**
    * Get all routes from the manifest
    *
    * Returns unified route structure: $routes[$pattern] => route_data
    * where route_data contains:
    *   - methods: ['GET', 'POST']
    *   - type: 'spa' | 'standard'
    *   - class: Full class name
    *   - method: Method name
    *   - file: File path
    *   - require: Auth requirements
    *   - js_action_class: (SPA routes only) JavaScript action class
    */
    public static function get_routes(): array
    {
        Manifest::init();

        return Manifest::$data['data']['routes'] ?? [];
    }

    /**
    * Check if metadata represents a controller class
    * @param array $metadata File metadata
    * @return bool True if class extends Rsx_Controller_Abstract
    */
    public static function _is_controller_class(array $metadata): bool
    {
        $extends = $metadata['extends'] ?? '';

        if ($extends === 'Rsx_Controller_Abstract') {
            return true;
        }

        // Check parent hierarchy
        $current_class = $extends;
        $max_depth = 10;

        while ($current_class && $max_depth-- > 0) {
            try {
                $parent_metadata = Manifest::php_get_metadata_by_class($current_class);
                if (($parent_metadata['extends'] ?? '') === 'Rsx_Controller_Abstract') {
                    return true;
                }
                $current_class = $parent_metadata['extends'] ?? '';
            } catch (\RuntimeException $e) {
                // Check FQCN match
                if ($current_class === 'Rsx_Controller_Abstract' ||
                $current_class === 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract') {
                    return true;
                }
                break;
            }
        }

        return false;
    }

}
