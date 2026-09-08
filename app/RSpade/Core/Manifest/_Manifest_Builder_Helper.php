<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Support\Rsx_Fingerprint;

/**
 * _Manifest_Builder_Helper - Index generation and autoloader building
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Builder_Helper
{
    /**
    * Build the autoloader class map for simplified class name resolution
    * Maps simple class names to their fully qualified class names
    *
    * TWO HALVES, and only one of them costs anything.
    *
    * The INDEXED half is a pure function of the files map - one pass, no copies, no reads.
    *
    * The UNINDEXED half is the reason this method is interesting. The manifest scans a short
    * list of framework roots; everything else under app/RSpade/ - Commands, Database, Http,
    * Ide, SchemaQuality - is invisible to it BY DESIGN, and those classes still have to be
    * autoloadable by simple name. So they are read from disk. What changed is how often:
    * the walk used to tokenize EVERY framework php file on every rebuild, the scanned ones
    * included (where the answer was already in the files map), and it is now
    *
    *   - restricted to the subtrees this build does NOT index, and
    *   - memoized on a stat fingerprint of exactly those subtrees, in the persistent cache,
    *     so a rebuild where none of them moved tokenizes nothing at all.
    *
    * The fingerprint is names + sizes + mtimes (Rsx_Fingerprint::directories): a class
    * declaration cannot change without changing the file, and a file cannot change without
    * changing its size or its mtime.
    *
    * @return array Map of simple names to arrays of FQCNs
    */
    public static function _build_autoloader_class_map(): array
    {
        $class_map = [];

        // First, collect classes from the manifest files
        foreach (Manifest::$data['data']['files'] as $file_path => $file_data) {
            if (isset($file_data['class']) && isset($file_data['namespace'])) {
                $simple_name = $file_data['class'];
                $fqcn = $file_data['namespace'] . '\\' . $simple_name;

                if (!isset($class_map[$simple_name])) {
                    $class_map[$simple_name] = [];
                }
                $class_map[$simple_name][] = $fqcn;
            }
        }

        // Second, the framework subtrees this build never indexed.
        foreach (static::_unindexed_framework_classes() as $simple_name => $fqcns) {
            foreach ($fqcns as $fqcn) {
                if (!isset($class_map[$simple_name])) {
                    $class_map[$simple_name] = [];
                }
                // Only add if not already present
                if (!in_array($fqcn, $class_map[$simple_name])) {
                    $class_map[$simple_name][] = $fqcn;
                }
            }
        }

        ksort($class_map);

        foreach ($class_map as &$fqcns) {
            sort($fqcns, SORT_STRING);
        }
        unset($fqcns);

        return $class_map;
    }

    /**
    * simple name => FQCNs, for the app/RSpade/ subtrees this build does not index.
    *
    * Memoized in the PERSISTENT cache under a stat fingerprint of those subtrees - the
    * build-scoped family is unusable here, because this runs mid-build (before a build key
    * exists) and is exactly the kind of expensive content-keyed derivation the persistent
    * family is for. A Redis outage degrades to the walk, never to a wrong answer.
    */
    private static function _unindexed_framework_classes(): array
    {
        $framework_root = base_path('app/RSpade');

        if (!is_dir($framework_root)) {
            return [];
        }

        $scanned = Manifest::scan_directories();
        $directories = [];

        foreach (scandir($framework_root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $framework_root . '/' . $entry;

            if (!is_dir($absolute) || in_array('app/RSpade/' . $entry, $scanned, true)) {
                continue;
            }

            $directories[] = $absolute;
        }

        // Loose php files directly under app/RSpade/ belong to nobody's subtree; the walk
        // below covers them via the root itself only when there ARE none of the above, so
        // they are collected explicitly instead.
        $loose = [];
        foreach (glob($framework_root . '/*.php') ?: [] as $file) {
            $loose[] = $file;
        }

        $fingerprint = sha1(
            Rsx_Fingerprint::directories($directories) . '|'
            . implode('|', array_map(fn ($f) => basename($f) . ':' . filesize($f) . ':' . filemtime($f), $loose))
        );

        $cache_key = 'Manifest_unindexed_framework_classes_v1_' . $fingerprint;
        $cached = RsxCache::get_persistent($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $classes = [];

        foreach ($directories as $directory) {
            foreach (Manifest::_scan_directory_for_classes($directory) as $simple_name => $fqcns) {
                foreach ($fqcns as $fqcn) {
                    $classes[$simple_name][] = $fqcn;
                }
            }
        }

        foreach ($loose as $file) {
            foreach (Manifest::_extract_classes_from_php_file($file) as $simple_name => $fqcns) {
                foreach ($fqcns as $fqcn) {
                    $classes[$simple_name][] = $fqcn;
                }
            }
        }

        RsxCache::set_persistent($cache_key, $classes);

        return $classes;
    }

    /**
     * THE ONE GROUPING: extension -> class name -> the files that declare it.
     *
     * "Which files declare this class name" was answered by two independent scans of the
     * file map that could disagree about their own filters - `_check_unique_base_class_names()`
     * built one to adjudicate overrides, and `_collate_files_by_classes()` built the class
     * record and threw a "safety net" duplicate error off another. There is one now, and
     * the second is a READ of it.
     *
     * `.php.upstream` sidecars are excluded (an archived framework file is not a live
     * declaration; the restore pass tracks them separately) and every JavaScript dialect
     * collapses onto `js`.
     *
     * @return array<string,array<string,array<int,string>>>
     */
    public static function _group_files_by_class_name(): array
    {
        $grouped = [];

        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (empty($metadata['class'])) {
                continue;
            }

            $extension = $metadata['extension'] ?? '';

            if ($extension === 'php.upstream') {
                continue;
            }

            if (in_array($extension, ['js', 'jsx', 'ts', 'tsx'], true)) {
                $extension = 'js';
            }

            $grouped[$extension][$metadata['class']][] = $file;
        }

        return $grouped;
    }

    /**
    * Build the CLASS MAPS and the inheritance indices.
    *
    * The class map is the HOT CLASS RECORD - the structural facts about a class that a served
    * request asks for constantly and that live one level above the method map:
    *
    *     php_classes['Client_Model'] = ['file' => ..., 'fqcn' => ..., 'extends' => ...,
    *                                    'abstract' => false]
    *     js_classes['Clients_Index_Action'] = ['file' => ..., 'extends' => ...]
    *
    * It used to be `class => path`, which forced php_is_subclass_of(), php_is_abstract(),
    * php_get_lineage() and php_get_subclasses_of() to reach into `files` for two fields - so
    * every inheritance question on the request path pulled a whole method map into scope.
    * With the split index those records live in the cold half, and pulling them would have
    * meant loading 7.6 MB of build metadata to answer "does this class extend that one".
    *
    * The subclass index maps a parent to every DESCENDANT (the walk climbs the whole chain,
    * not just one level), which is what makes php_get_subclasses_of() a lookup.
    *
    * Both maps are ksorted: they are populated in filesystem readdir order, and the build key
    * hashes the derived sections in their stored order.
    *
    * @return void
    */
    public static function _collate_files_by_classes()
    {
        $grouped = static::_group_files_by_class_name();

        foreach (['js', 'php'] as $ext) {
            Manifest::$data['data'][$ext . '_classes'] = [];

            // Step 1: the hot class record, keyed by simple class name, read off THE ONE
            // grouping. Class override detection (rsx/ vs app/RSpade/) happens earlier, in
            // _check_unique_base_class_names(), off the same grouping - so a duplicate that
            // reaches here is one the override pass declined to adjudicate, and it is an
            // error rather than a second detection.
            foreach ($grouped[$ext] ?? [] as $class_name => $declaring_files) {
                if (count($declaring_files) > 1) {
                    throw new \RuntimeException(
                        "Duplicate {$ext} class detected: {$class_name}\n" .
                        "Found in:\n  - " . implode("\n  - ", $declaring_files) . "\n" .
                        'Class names must be unique across the codebase.'
                    );
                }

                $file = $declaring_files[0];
                $filedata = Manifest::$data['data']['files'][$file];

                $record = ['file' => $file];

                if ($ext === 'php') {
                    $record['fqcn'] = $filedata['fqcn'] ?? null;
                    $record['abstract'] = (bool) ($filedata['abstract'] ?? false);
                }

                if (!empty($filedata['extends'])) {
                    $record['extends'] = $filedata['extends'];
                }

                Manifest::$data['data'][$ext . '_classes'][$class_name] = $record;
            }

            ksort(Manifest::$data['data'][$ext . '_classes']);

            // Step 2: parent -> every descendant, by walking each class's chain to the root.
            $subclass_index = [];

            foreach (Manifest::$data['data'][$ext . '_classes'] as $class => $record) {
                $seen = [];

                while (!empty($record['extends'])) {
                    $parent = $record['extends'];

                    // A cycle cannot be walked out of; the manifest records what the source
                    // says, and the source can say something impossible.
                    if (isset($seen[$parent])) {
                        break;
                    }

                    $seen[$parent] = true;
                    $subclass_index[$parent][] = $class;

                    $record = Manifest::$data['data'][$ext . '_classes'][$parent] ?? null;

                    if ($record === null) {
                        // Parent not in the manifest (a Laravel class, say) - the chain ends.
                        break;
                    }
                }
            }

            ksort($subclass_index);
            Manifest::$data['data'][$ext . '_subclass_index'] = $subclass_index;
        }
    }

    /**
     * Blade view id -> path.
     *
     * find_view() used to scan every indexed file for a matching `id`, on every layout hop of
     * every rendered page, and threw "Duplicate view ID" when it found two. Both halves of
     * that belong at build time: the map answers the lookup, and a duplicate is a build
     * failure naming both files instead of a surprise at render time.
     */
    public static function _build_blade_view_index()
    {
        $views = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            if (($metadata['extension'] ?? '') !== 'blade.php' || empty($metadata['id'])) {
                continue;
            }

            $id = $metadata['id'];

            if (isset($views[$id])) {
                throw new \RuntimeException(
                    "Duplicate view ID detected: {$id}\n" .
                    "Found in:\n  - {$views[$id]}\n  - {$file_path}\n" .
                    "View IDs must be unique across all Blade files.\n\n" .
                    "To resolve: add specificity to the @rsx_id by prefixing with directory\n" .
                    "segments, then update every @rsx_extends / @rsx_include that names it."
                );
            }

            $views[$id] = $file_path;
        }

        ksort($views);
        Manifest::$data['data']['blade_views'] = $views;
    }

    /**
     * attribute simple name -> the class and member declarations carrying it.
     *
     *     attribute_index['Emitter'] = [
     *         ['file' => ..., 'class' => 'Sales_Service', 'member' => 'daily_total',
     *          'instances' => [[0 => 'Sales_Topic']]],
     *     ]
     *
     * Six places in the framework wrote the same "iterate every file, filter PHP, read
     * attribute X off public_static_methods" triple loop - the emitter registry, the
     * scheduler, health checks, heal targets - each one a full pass over the index, and each
     * one matching attribute names by slightly different rules. This is that loop, run once,
     * at build time, with ONE matching rule: the attribute's SIMPLE name, which is how RSX
     * spells attributes everywhere else.
     *
     * The instances are the attribute ARGUMENTS, which is what makes the index answer the
     * question rather than merely narrow it (a `#[Schedule('daily at 3am')]` consumer needs
     * the phrase, not the fact). They are small by construction and would otherwise force a
     * cold load for a class whose method map nothing else wants.
     */
    public static function _build_attribute_index()
    {
        $index = [];

        $record = function (string $attribute, array $row) use (&$index): void {
            $simple = ltrim($attribute, '\\');
            $position = strrpos($simple, '\\');

            if ($position !== false) {
                $simple = substr($simple, $position + 1);
            }

            $index[$simple][] = $row;
        };

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            $class = $metadata['class'] ?? null;

            foreach ($metadata['attributes'] ?? [] as $attribute => $instances) {
                $record($attribute, [
                    'file' => $file_path,
                    'class' => $class,
                    'member' => null,
                    'instances' => $instances,
                ]);
            }

            foreach (['public_static_methods', 'public_instance_methods', 'methods'] as $bucket) {
                foreach ($metadata[$bucket] ?? [] as $member => $member_data) {
                    foreach ($member_data['attributes'] ?? [] as $attribute => $instances) {
                        $record($attribute, [
                            'file' => $file_path,
                            'class' => $class,
                            'member' => $member,
                            'instances' => $instances,
                        ]);
                    }
                }
            }
        }

        ksort($index);
        Manifest::$data['data']['attribute_index'] = $index;
    }

    /**
     * table name -> model class name.
     *
     * db_get_table_columns() and ModelHelper::get_columns_by_table() each walked the whole
     * model registry comparing `table` on every call. One line of index, built where the
     * registry is.
     */
    public static function _build_models_by_table_index()
    {
        $by_table = [];

        foreach (Manifest::$data['data']['models'] ?? [] as $class => $model) {
            if (!empty($model['table'])) {
                $by_table[$model['table']] = $class;
            }
        }

        ksort($by_table);
        Manifest::$data['data']['models_by_table'] = $by_table;
    }

    /**
     * Build event handler index from OnEvent attributes
     *
     * Scans all PHP files for methods with #[OnEvent] attributes and builds
     * an index of event_name => [handlers] for fast event dispatching.
     *
     * Index structure:
     * ['event_handlers'] => [
     *     'event.name' => [
     *         ['class' => 'Class_Name', 'method' => 'method_name', 'priority' => 100],
     *         ['class' => 'Other_Class', 'method' => 'other_method', 'priority' => 200],
     *     ]
     * ]
     *
     * Handlers are sorted by priority (lower numbers execute first).
     *
     * @return void
     */
    public static function _build_event_handler_index()
    {
        Manifest::$data['data']['event_handlers'] = [];

        // Scan all PHP files for OnEvent attributes
        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            // Only process PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files without a class
            if (empty($metadata['class'])) {
                continue;
            }

            $class_name = $metadata['class'];

            // Check public static methods for OnEvent attributes
            if (isset($metadata['public_static_methods'])) {
                foreach ($metadata['public_static_methods'] as $method_name => $method_data) {
                    // Check if method has OnEvent attribute
                    if (isset($method_data['attributes']['OnEvent'])) {
                        $on_event_attrs = $method_data['attributes']['OnEvent'];

                        // Process each OnEvent attribute instance (a method can have multiple)
                        foreach ($on_event_attrs as $attr_args) {
                            // Extract event name - could be positional or named
                            $event_name = $attr_args[0] ?? $attr_args['event'] ?? null;
                            if (!$event_name) {
                                continue; // Skip invalid attribute
                            }

                            // Extract priority - could be positional or named, defaults to 100
                            $priority = $attr_args[1] ?? $attr_args['priority'] ?? 100;

                            // Initialize event array if needed
                            if (!isset(Manifest::$data['data']['event_handlers'][$event_name])) {
                                Manifest::$data['data']['event_handlers'][$event_name] = [];
                            }

                            // Add handler to event
                            Manifest::$data['data']['event_handlers'][$event_name][] = [
                                'class' => $class_name,
                                'method' => $method_name,
                                'priority' => $priority,
                                'file' => $file_path,
                            ];
                        }
                    }
                }
            }
        }

        ksort(Manifest::$data['data']['event_handlers']);

        // Sort each event's handlers by priority (lower = earlier)
        foreach (Manifest::$data['data']['event_handlers'] as $event_name => &$handlers) {
            usort($handlers, function ($a, $b) {
                return $a['priority'] <=> $b['priority'];
            });
        }
    }

    /**
     * Build index of classless PHP files
     *
     * Creates a simple array of file paths for all PHP files in the manifest
     * that do not contain a class. These files typically contain helper functions,
     * constants, or other procedural code that needs to be loaded during post_init().
     *
     * Stored in manifest data at: $data['data']['classless_php_files']
     */
    public static function _build_classless_php_files_index()
    {
        Manifest::$data['data']['classless_php_files'] = [];

        // Scan all PHP files
        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            // Only process PHP files
            if (($metadata['extension'] ?? '') !== 'php') {
                continue;
            }

            // Skip files that have a class
            if (!empty($metadata['class'])) {
                continue;
            }

            // Add file path to classless index
            Manifest::$data['data']['classless_php_files'][] = $file_path;
        }
    }

}
