<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;

/**
 * _Manifest_PHP_Reflection_Helper - PHP class reflection operations
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * EVERY STRUCTURAL QUESTION IS ANSWERED FROM THE HOT CLASS RECORD.
 * `php_classes[$simple_name]` carries `file`, `fqcn`, `extends` and `abstract`, so
 * inheritance, abstractness and lineage never touch the `files` map - which since the index
 * split lives in the cold half and would drag 7.6 MB of build metadata into a request to
 * answer "does this class extend that one".
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_PHP_Reflection_Helper
{
    /**
    * The hot class record for a simple class name, or null.
    *
    * ['file' => ..., 'fqcn' => ..., 'extends' => ?string, 'abstract' => bool]
    *
    * @return array|null
    */
    public static function php_class_metadata(string $simple_name): ?array
    {
        Manifest::init();

        return Manifest::$data['data']['php_classes'][$simple_name] ?? null;
    }

    /**
    * Find a PHP class by name
    */
    public static function php_find_class(string $class_name): string
    {
        Manifest::init();

        if (!isset(Manifest::$data['data']['php_classes'][$class_name])) {
            throw new \RuntimeException("PHP class not found in manifest: {$class_name}");
        }

        return Manifest::$data['data']['php_classes'][$class_name]['file'];
    }

    /**
    * Find a PHP class by fully qualified name.
    *
    * RSX enforces unique SIMPLE class names, so an FQCN's last segment is its key in the
    * class map and the lookup is O(1). This used to be a linear scan of every indexed file
    * comparing `fqcn` - on the request path, behind twelve call sites, several of them
    * per-model and per-render.
    */
    public static function find_php_fqcn(string $fqcn): string
    {
        Manifest::init();

        $simple = self::_normalize_class_name($fqcn);
        $record = Manifest::$data['data']['php_classes'][$simple] ?? null;

        if ($record !== null && ($record['fqcn'] ?? null) === ltrim($fqcn, '\\')) {
            return $record['file'];
        }

        throw new \RuntimeException("PHP class with FQCN not found in manifest: {$fqcn}");
    }

    /**
    * Get manifest metadata by PHP class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_class(string $class_name): array
    {
        $file = Manifest::php_find_class($class_name);

        return Manifest::get_file($file);
    }

    /**
    * Get manifest metadata by PHP fully qualified class name
    * This is a convenience method that finds the class and returns its metadata
    */
    public static function php_get_metadata_by_fqcn(string $fqcn): array
    {
        $file = Manifest::find_php_fqcn($fqcn);

        return Manifest::get_file($file);
    }

    /**
    * Get all classes extending a parent (filters out abstract classes by default)
    * Returns FULL file metadata indexed by class name - so it loads the cold half of the
    * index. Callers that only need names or the structural fields want
    * php_get_subclasses_of() and php_class_metadata() instead.
    */
    public static function php_get_extending(string $parentclass): array
    {
        // Get concrete subclasses only (abstract filtered out by default)
        $subclasses = self::php_get_subclasses_of($parentclass, true);

        $classpile = [];
        foreach ($subclasses as $classname) {
            $record = Manifest::$data['data']['php_classes'][$classname] ?? null;

            if ($record !== null) {
                $classpile[$classname] = Manifest::get_file($record['file']);
            }
        }

        return $classpile;
    }

    /**
    * The HOT CLASS RECORDS of every class extending a parent, keyed by class name.
    *
    * php_get_extending()'s cheap sibling: same set, but each value is
    * ['file', 'fqcn', 'extends', 'abstract'] rather than the class's whole file record. Use
    * it wherever the answer is "which classes, and where do they live" - the request-path
    * `Main_Abstract` and `Portal_Main_Abstract` lookups are the archetype - and reserve
    * php_get_extending() for callers that genuinely want the method map.
    *
    * @return array<string, array>
    */
    public static function php_class_records_extending(string $parentclass, bool $concrete_only = true): array
    {
        $records = [];

        foreach (self::php_get_subclasses_of($parentclass, $concrete_only) as $class) {
            $record = Manifest::$data['data']['php_classes'][$class] ?? null;

            if ($record !== null) {
                $records[$class] = $record;
            }
        }

        return $records;
    }

    /**
    * Check if a class is a subclass of another by traversing the inheritance chain
    *
    * @param string $subclass The child class name (simple name, not FQCN)
    * @param string $superclass The parent class name to check for (simple name, not FQCN)
    * @return bool True if subclass extends superclass (directly or indirectly), false otherwise
    */
    public static function php_is_subclass_of(string $subclass, string $superclass): bool
    {
        Manifest::init();

        $subclass = self::_normalize_class_name($subclass);
        $superclass = self::_normalize_class_name($superclass);

        $current_class = $subclass;
        $visited = []; // Prevent infinite loops in case of circular inheritance

        while ($current_class) {
            if (isset($visited[$current_class])) {
                return false;
            }

            $visited[$current_class] = true;

            $record = Manifest::$data['data']['php_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                return false;
            }

            if ($record['extends'] === $superclass) {
                return true;
            }

            // Move up the chain to the parent class
            $current_class = $record['extends'];
        }

        return false;
    }

    /**
    * Check if a PHP class is abstract
    *
    * @param string $class_name The class name to check (simple name, not FQCN)
    * @return bool True if the class is abstract, false if concrete or not found
    */
    public static function php_is_abstract(string $class_name): bool
    {
        Manifest::init();

        $record = Manifest::$data['data']['php_classes'][self::_normalize_class_name($class_name)] ?? null;

        return (bool) ($record['abstract'] ?? false);
    }

    /**
    * Get the full inheritance lineage (ancestry) of a PHP class
    *
    * Returns an array of parent class names from immediate parent to top-level ancestor.
    * Example: For class C extends B extends A, returns ['B', 'A']
    *
    * @param string $class_name The class name (FQCN or simple name)
    * @return array Array of parent class simple names in order from immediate parent to root
    */
    public static function php_get_lineage(string $class_name): array
    {
        Manifest::init();

        $lineage = [];
        $current_class = self::_normalize_class_name($class_name);
        $visited = [];

        while ($current_class) {
            if (isset($visited[$current_class])) {
                break;
            }

            $visited[$current_class] = true;

            $record = Manifest::$data['data']['php_classes'][$current_class] ?? null;

            if ($record === null || empty($record['extends'])) {
                break;
            }

            $lineage[] = $record['extends'];
            $current_class = $record['extends'];
        }

        return $lineage;
    }

    /**
    * Get all direct subclasses of a given PHP class using the pre-built index
    *
    * @param string $class_name The parent class name (simple name, not FQCN)
    * @param bool $concrete_only Whether to filter out abstract classes (default: true)
    * @return array Array of subclass names, or empty array if class not found or has no children
    */
    public static function php_get_subclasses_of(string $class_name, bool $concrete_only = true): array
    {
        Manifest::init();

        $class_name = self::_normalize_class_name($class_name);

        $subclasses = Manifest::$data['data']['php_subclass_index'][$class_name] ?? [];

        // If not filtering for concrete classes, return all subclasses
        if (!$concrete_only) {
            return $subclasses;
        }

        // Filter out abstract classes
        $concrete_subclasses = [];
        foreach ($subclasses as $subclass) {
            $record = Manifest::$data['data']['php_classes'][$subclass] ?? null;

            if ($record === null) {
                shouldnt_happen(
                    "Fatal: PHP class '{$subclass}' found in subclass index but not in php_classes.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            if (!isset($record['abstract'])) {
                shouldnt_happen(
                    "Fatal: Abstract property missing for PHP class '{$subclass}' in manifest data.\n" .
"This indicates a major data integrity issue with the manifest.\n" .
'Try running: php artisan rsx:manifest:build --clean'
                );
            }

            if (!$record['abstract']) {
                $concrete_subclasses[] = $subclass;
            }
        }

        return $concrete_subclasses;
    }

    /**
    * Check if a class name corresponds to a PHP model class (exists in models index)
    *
    * This is used by the JS model system to recognize PHP model class names that may
    * appear in JS inheritance chains but don't exist as JS classes in the manifest.
    * PHP models like "Project_Model" generate JS stubs during bundle compilation.
    *
    * @param string $class_name The class name to check
    * @return bool True if this is a PHP model class name
    */
    public static function is_php_model_class(string $class_name): bool
    {
        return isset(Manifest::$data['data']['models'][$class_name]);
    }

    /**
    * Merged column map for a model class, or null when the class is not an indexed model.
    *
    * O(1) class-keyed - the same array the JS stub generator consumes, so Class-Table
    * Inheritance is already spanned (Model_ManifestSupport::__merge_detail_columns() merges a
    * base model's detail-table columns into its map before this is ever read). Column entries
    * carry the FULL metadata (type, max_length, nullable, ...), unlike db_get_table_columns()
    * which flattens each column to its type string.
    *
    * @param string $class_name Class name (FQCN or simple - normalized either way)
    * @return array|null column_name => metadata array, or null if not an indexed model
    */
    public static function php_model_columns(string $class_name): ?array
    {
        Manifest::init();

        $class_name = self::_normalize_class_name($class_name);

        return Manifest::$data['data']['models'][$class_name]['columns'] ?? null;
    }

    /**
    * The model class that owns a table, or null.
    */
    public static function model_for_table(string $table): ?string
    {
        Manifest::init();

        return Manifest::$data['data']['models_by_table'][$table] ?? null;
    }

    /**
     * Normalize class name to simple name (strip namespace qualifiers)
     *
     * Since RSX enforces unique simple class names across the codebase,
     * we normalize all class references to simple names for consistent
     * comparison and storage. FQCNs are only needed at actual class loading time.
     *
     * Examples:
     *   \Rsx\Lib\DataGrid -> DataGrid
     *   Rsx\Lib\DataGrid -> DataGrid
     *   DataGrid -> DataGrid
     *
     * @param string $class_name Class name in any format (with or without namespace)
     * @return string Simple class name without namespace
     */
    public static function _normalize_class_name(string $class_name): string
    {
        // Strip leading backslash
        $class_name = ltrim($class_name, '\\');

        // Extract just the class name (last part after final backslash)
        $parts = explode('\\', $class_name);

        return end($parts);
    }

    /**
    * Load a class and all its parent classes from manifest data
    *
    * This utility method ensures a class and its entire parent hierarchy
    * are loaded before doing reflection or other operations.
    * Classes are loaded in dependency order (parents first).
    * Used by stub generators and reflection extraction.
    *
    * The hierarchy is walked through the CLASS MAP - simple name to record, one lookup per
    * hop. It used to be a nested linear scan of every indexed file per hop, per class.
    *
    * @param string $fqcn Fully qualified class name to load
    * @param array $manifest_data The manifest data array (unused; the class map is the source)
    * @return void
    * @throws \RuntimeException if class or parent cannot be loaded
    */
    public static function _load_class_hierarchy(string $fqcn, array $manifest_data): void
    {
        // Already loaded? Nothing to do
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || trait_exists($fqcn, false)) {
            return;
        }

        $classes = $manifest_data['data']['php_classes'] ?? Manifest::$data['data']['php_classes'] ?? [];

        // Build list of classes to load in hierarchy order (parents first)
        $hierarchy = [];
        $current_fqcn = $fqcn;
        $visited = [];

        while ($current_fqcn) {
            if (isset($visited[$current_fqcn])) {
                break;
            }

            $visited[$current_fqcn] = true;

            $simple = Manifest::_normalize_class_name($current_fqcn);
            $record = $classes[$simple] ?? null;

            if ($record === null || ($record['fqcn'] ?? null) !== ltrim($current_fqcn, '\\')) {
                // Not an indexed class. A built-in or vendor class simply autoloads.
                if (class_exists($current_fqcn, true) ||
                interface_exists($current_fqcn, true) ||
                trait_exists($current_fqcn, true)) {
                    break;
                }

                shouldnt_happen("Parent class {$current_fqcn} not found in manifest or autoloader for {$fqcn}");
            }

            array_unshift($hierarchy, ['fqcn' => $current_fqcn, 'file' => $record['file']]);

            if (empty($record['extends'])) {
                break;
            }

            $parent = $classes[Manifest::_normalize_class_name($record['extends'])] ?? null;
            $current_fqcn = $parent['fqcn'] ?? null;
        }

        // Load classes in order (parents first)
        foreach ($hierarchy as $class_info) {
            if (!class_exists($class_info['fqcn'], false) &&
            !interface_exists($class_info['fqcn'], false) &&
            !trait_exists($class_info['fqcn'], false)) {
                $full_path = base_path($class_info['file']);
                if (!file_exists($full_path)) {
                    shouldnt_happen("Class file not found: {$full_path} for {$class_info['fqcn']}");
                }

                // This includes the file.
                // A side effect of this include is this line also lints the file.  Past this point, we can assume all php
                // files (well, class files) have valid syntax.
                include_once $full_path;

                // Verify the class loaded successfully
                if (!class_exists($class_info['fqcn'], false) &&
                !interface_exists($class_info['fqcn'], false) &&
                !trait_exists($class_info['fqcn'], false)) {
                    shouldnt_happen("Failed to load class {$class_info['fqcn']} from {$full_path}");
                }
            }
        }
    }

}
