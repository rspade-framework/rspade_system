<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Support\Rsx_Fingerprint;

/**
 * Manifest_Indexer - phases 3 to 6 of the build.
 *
 * The derived indexes (autoloader class map, blade views, attributes, models-by-table,
 * event handlers, classless files), the duplicate-class detectors, the class-override
 * archive pass, the composer classmap validation, the manifest-time code-quality pass
 * entry point and the VS Code attribute stub.
 *
 * Everything here reads the parsed file map that Manifest_Scanner produced and writes a
 * derived section of the index. Nothing here parses a source file: the code-quality pass
 * hands that to App\RSpade\CodeQuality\Manifest_Rule_Driver, which owns the source cache.
 *
 * @internal Reached through the Manifest facade.
 */
class Manifest_Indexer
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

            if (!is_dir($absolute) || in_array(Rsx_Paths::FRAMEWORK_PREFIX . $entry, $scanned, true)) {
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
    /**
    * Run the manifest-time code-quality pass.
    *
    * THE DRIVER OWNS THE PASS. Discovery, pattern compilation, source reading, tokenizing,
    * parsing, the per-file incremental decision and the cross-file dependency gate all live
    * in App\RSpade\CodeQuality\Manifest_Rule_Driver; this function decides only WHICH
    * files the pass is about and releases the driver when it is done.
    *
    * What used to be here: two rule discoveries, a metadata-extraction phase that ran a
    * separate `on_manifest_file_update` hook over every changed file and stored its findings
    * in the index, a loop that walked the WHOLE file map once PER RULE re-reading each file,
    * and every cross-file rule running unconditionally on every rebuild.
    *
    * @param array $changed_files Files that changed in this manifest scan (relative paths)
    */
    public static function _run_manifest_time_code_quality_checks(array $changed_files = []): void
    {
        $collector = new \App\RSpade\CodeQuality\Support\ViolationCollector();

        $driver = new \App\RSpade\CodeQuality\Manifest_Rule_Driver(
            collector: $collector,
            config: [],
            only_manifest_scan: true,
            throw_on_violation: true,
            source: Manifest::build()->source_cache(),
        );

        try {
            // An empty changed set still runs the cross-file rules: a REMOVED file changes
            // the tree without appearing in the changed list.
            $driver->run($changed_files);
        } finally {
            $driver->finish();
        }
    }

    /**
    * Check for duplicate base class names within the same file type
    *
    * This method handles two scenarios:
    * 1. RESTORE: If a .upstream file exists but no active override exists, restore it
    * 2. OVERRIDE: When a class exists in both rsx/ and app/RSpade/, rename framework to .upstream
    *
    * Throws a fatal error if duplicates exist within the same area (both rsx/ or both app/RSpade/)
    */
    public static function _check_unique_base_class_names(array $dirty_files = []): void
    {
        // ==================================================================================
        // STEP 1: Restore orphaned .upstream files
        // ==================================================================================
        // Check all php.upstream files - if their class no longer has an override in rsx/,
        // restore the framework file by renaming .upstream back to .php
        // ==================================================================================
        Manifest::_restore_orphaned_upstream_files();

        // ==================================================================================
        // STEP 2: Group classes by extension, then by class name
        // ==================================================================================
        // WHICH CLASS NAMES CAN HAVE MOVED. A duplicate appears only when a file that
        // declares the name arrived or changed, so the pass ACTS only on names a dirty file
        // declares. The grouping below is still one scalar pass over the index, because
        // answering "who else declares this name" needs the whole picture - what it no
        // longer does is re-adjudicate 640 settled class names on every rebuild.
        $dirty_classes = null;

        if (!empty($dirty_files)) {
            $dirty_classes = [];

            foreach ($dirty_files as $dirty_file) {
                $class = Manifest::$data['data']['files'][$dirty_file]['class'] ?? null;

                if ($class !== null && $class !== '') {
                    $dirty_classes[$class] = true;
                }
            }
        }
        // THE ONE GROUPING - the same one _collate_files_by_classes() reads. This pass used
        // to build its own, which is how the framework ended up with two answers to "which
        // files declare this class name" that could disagree about their own filters.
        $classes_by_extension = self::_group_files_by_class_name();

        // ==================================================================================
        // STEP 3: Check for duplicates and create overrides
        // ==================================================================================
        // A build that has already poisoned its own manifest (manifest_is_bad) has declared
        // its index untrustworthy, and archiving is the one thing in this pass that cannot
        // be taken back on the next build. So it does not run: duplicates are still reported,
        // nothing is renamed, and the rebuild that follows the poisoning decides.
        $index_is_trustworthy = !Manifest::$_manifest_is_bad;

        foreach ($classes_by_extension as $extension => $base_class_files) {
            foreach ($base_class_files as $class_name => $files) {
                if ($dirty_classes !== null && !isset($dirty_classes[$class_name])) {
                    continue;
                }

                if (count($files) > 1) {
                    // Check if this is a valid override (rsx/ vs app/RSpade/)
                    $rsx_files = array_filter($files, fn ($f) => Rsx_Paths::is_application($f));
                    $framework_files = array_filter($files, fn ($f) => Rsx_Paths::is_framework($f));

                    // Valid override: exactly one file in rsx/, rest in app/RSpade/
                    if (count($rsx_files) === 1 && count($framework_files) >= 1) {
                        $rsx_file = array_values($rsx_files)[0];

                        // ==========================================================================
                        // RE-VERIFY THE TWIN AGAINST DISK BEFORE ARCHIVING ANYTHING
                        // ==========================================================================
                        // Archiving is destructive and irreversible within a build: the framework
                        // file is renamed away and the class stops existing. The only justification
                        // for it is that an rsx/ file is standing in for that class RIGHT NOW - so
                        // that claim is checked against the filesystem, never taken from the index.
                        //
                        // A field report on 2026-08-25 is why: a scan rule failed on a brand-new
                        // framework class while the index still carried just-deleted rsx/ copies of
                        // the same class names, the failure poisoned the manifest, and the next
                        // build ran this pass against that file list and archived the new framework
                        // files as twins of app classes that no longer existed. They vanished with
                        // no error naming them.
                        //
                        // A stale entry is dropped and the build restarts, so the next pass sees a
                        // file list that matches the disk.
                        // ==========================================================================
                        if (!$index_is_trustworthy) {
                            error_log(
                                '[Manifest] Class override pass: NOT archiving a framework twin of '
                                . $class_name . ' - this build already marked its manifest bad, so '
                                . 'its file list is not evidence of anything. The rebuild decides.'
                            );

                            continue;
                        }

                        if (!file_exists(base_path($rsx_file))) {
                            error_log(
                                '[Manifest] Class override pass: ignoring stale index entry for '
                                . $class_name . ' - ' . $rsx_file . ' is in the file list but not on '
                                . 'disk. No framework file was archived; dropping the entry and '
                                . 'restarting the build.'
                            );

                            unset(Manifest::$data['data']['files'][$rsx_file]);
                            Manifest::flag_needs_restart(
                                'the class-override pass dropped a stale index entry for ' . $class_name
                                . ' (' . $rsx_file . ' is indexed but not on disk)'
                            );

                            continue;
                        }

                        $rsx_metadata = Manifest::$data['data']['files'][$rsx_file] ?? [];
                        $rsx_extends = $rsx_metadata['extends'] ?? null;

                        $first_framework_file = array_values($framework_files)[0];
                        $framework_metadata = Manifest::$data['data']['files'][$first_framework_file] ?? [];
                        $framework_extends = $framework_metadata['extends'] ?? null;

                        // A SPLIT class: the framework ships an abstract base carrying every member
                        // and a concrete shell named after it. `X extends X_Abstract` is the whole
                        // signature - the base is the framework's, the shell is what an override
                        // replaces, and the shell is the only thing an application may take over.
                        $split_base = ($framework_extends === $class_name . '_Abstract')
                            ? $framework_extends
                            : null;

                        // Check if rsx/ class extends the framework class (wrong pattern)
                        // This happens when someone tries to use OOP inheritance instead of
                        // the correct RSX override pattern.
                        if ($rsx_extends === $class_name) {
                            $framework_file = $first_framework_file;

                            $correct = $split_base !== null
                                ? "CORRECT OVERRIDE PATTERN FOR A SPLIT FRAMEWORK CLASS:\n"
                                . "  The framework carries every member of {$class_name} on the abstract\n"
                                . "  base {$split_base}, and ships {$class_name} as an empty shell so an\n"
                                . "  application can replace it. Extend the BASE, not the shell:\n\n"
                                . "     class {$class_name} extends {$split_base}\n"
                                . "     {\n"
                                . "         // only the members this application changes\n"
                                . "     }\n\n"
                                . "  The framework archives {$framework_file} as .upstream and your class\n"
                                . "  becomes {$class_name} for the whole tree, still inheriting everything\n"
                                . "  the framework adds to the base from now on."
                                : "CORRECT OVERRIDE PATTERN:\n"
                                . "  1. Copy the framework file to your rsx/ directory:\n"
                                . "     cp system/{$framework_file} {$rsx_file}\n\n"
                                . "  2. Customize the copy as needed (change namespace, add methods, etc.)\n\n"
                                . "  3. The framework will automatically detect this and rename the\n"
                                . "     original to .upstream, allowing your version to take over.\n\n"
                                . "This pattern replaces the framework class entirely, allowing full\n"
                                . "customization while maintaining the same class name for compatibility.";

                            throw new \RuntimeException(
                                "Fatal: Invalid class override pattern for '{$class_name}'.\n\n" .
                                "The file {$rsx_file} extends the framework class {$class_name},\n" .
                                "but RSX requires unique class names - you cannot have two classes\n" .
                                "with the same name, even if one extends the other.\n\n" .
                                $correct . "\n\n" .
                                'See: php artisan rsx:man class_override'
                            );
                        }

                        // THE OVERRIDE OF A SPLIT CLASS MUST EXTEND ITS BASE. A copy of the
                        // framework file is a SECOND IMPLEMENTATION of a class the framework
                        // keeps developing: every member the framework adds to the base from
                        // then on is absent from the application's copy, and core calls it
                        // anyway. That is the exact failure the split exists to end, so it is
                        // refused rather than reported.
                        if ($split_base !== null && $rsx_extends !== $split_base) {
                            $declared = $rsx_extends === null || $rsx_extends === ''
                                ? 'nothing'
                                : $rsx_extends;

                            throw new \RuntimeException(
                                "Fatal: Invalid override of the split framework class '{$class_name}'.\n\n" .
                                "  Override: {$rsx_file} (extends {$declared})\n" .
                                "  Required: extends {$split_base}\n\n" .
                                "{$class_name} is a SPLIT framework class: {$first_framework_file} declares\n" .
                                "nothing but `class {$class_name} extends {$split_base}`, and every member\n" .
                                "lives on the base. The shell exists so an application can replace it\n" .
                                "WITHOUT holding a copy of the implementation.\n\n" .
                                "REWRITE THE OVERRIDE AS:\n\n" .
                                "     class {$class_name} extends {$split_base}\n" .
                                "     {\n" .
                                "         // only what this application changes\n" .
                                "     }\n\n" .
                                "A copy of the framework file is a second implementation of a class the\n" .
                                "framework keeps developing: every member added to {$split_base} after the\n" .
                                "copy was taken is missing from it, and framework code calls those members\n" .
                                "on this class regardless.\n\n" .
                                'See: php artisan rsx:man class_override'
                            );
                        }

                        $did_change = false;
                        // Rename framework files to .upstream and remove from manifest
                        foreach ($framework_files as $framework_file) {
                            $full_framework_path = base_path($framework_file);
                            $upstream_path = $full_framework_path . '.upstream';

                            if (file_exists($full_framework_path) && !file_exists($upstream_path)) {
                                // Normal case: rename to .upstream
                                rename($full_framework_path, $upstream_path);
                                Manifest::$_override_pass_renamed = true;

                                // Loud, not debug-channel: a class leaving the build must always
                                // be attributable to the override that took it out.
                                error_log(
                                    '[Manifest] Class override: ' . $class_name . ' - archived '
                                    . $framework_file . ' to ' . $framework_file . '.upstream, '
                                    . 'overridden by ' . $rsx_file
                                );
                                $did_change = true;
                            } elseif (file_exists($full_framework_path) && file_exists($upstream_path)) {
                                // Self-healing: both .php and .php.upstream exist (e.g., after framework update)
                                // Remove the .php file since .upstream is the correct archived version
                                unlink($full_framework_path);
                                Manifest::$_override_pass_renamed = true;
                                error_log(
                                    '[Manifest] Class override: ' . $class_name . ' - removed duplicate '
                                    . $framework_file . ' (its .upstream archive already exists), '
                                    . 'overridden by ' . $rsx_file
                                );
                                $did_change = true;
                            }

                            // Remove from manifest data so it won't be indexed
                            unset(Manifest::$data['data']['files'][$framework_file]);
                        }

                        if ($did_change) {
                            Manifest::flag_needs_restart(
                                'a framework file was archived as .upstream because ' . $class_name
                                . ' is overridden in rsx/'
                            );
                        }
                        continue;
                    }

                    // Not a valid override - throw error
                    $file_type = $extension === 'php' ? 'PHP' : ($extension === 'js' ? 'JavaScript' : $extension);

                    throw new \RuntimeException(
                        "Fatal: Duplicate {$file_type} class name '{$class_name}' found in multiple files:\n" .
'  - ' . implode("\n  - ", $files) . "\n" .
'Each class name must be unique within the same file type.'
                    );
                }
            }
        }
    }

    /**
    * Testable seam for the composer autoloader dump. Null = the real runner
    * (_run_composer_dump). Tests swap in a spy to assert the dump was / was not
    * invoked without actually shelling out to composer.
    *
    * @var callable|null
    */
    public static $_composer_dump_runner = null;

    /**
    * Validate composer's committed classmap against the filesystem and regenerate it
    * (blocking `composer dump-autoload`) when it has gone stale.
    *
    * WHY HERE: this runs in the manifest rebuild immediately after the class-override
    * rename/restore pass (_check_unique_base_class_names) has reached its settled state
    * for this rebuild - the exact moment classmap staleness is created (an override was
    * added: framework `.php` -> `.php.upstream`) or cured (an override was removed).
    * Composer's committed classmap still maps the framework FQCN to the renamed `.php`
    * path; left alone, composer's ClassLoader would `include` a missing file on every
    * request (tolerated at runtime by Autoloader's scoped warning carve-out, but the
    * on-disk data is still wrong). Regenerating the classmap here heals the data so
    * future requests/processes never hit the miss at all.
    *
    * The two mechanisms are complementary: the error-handler tolerance is the RUNTIME
    * guarantee (keeps THIS process working, whose in-memory classmap is already loaded
    * and cannot be un-staled mid-request); this dump is the DATA HYGIENE (fixes the
    * file for next time).
    *
    * Cost: ~10-20k warm file_exists() stats (tens of ms). It runs ONLY on code-change
    * rebuilds (this whole pipeline is rebuild-only; a no-change dev request loads cache
    * and never reaches here) and MUST NOT be cache-skipped beyond that gating.
    *
    * DEV-MODE ONLY concern: prod/sealed builds never auto-rebuild, and rsx:prod:build
    * already regenerates the composer autoloader (Prod_Build_Command step 3). The
    * validator simply runs wherever the override pass runs (dev rebuild +
    * framework:pull-triggered rebuild); no special prod handling is needed. It writes
    * only into vendor/composer (via the composer subprocess) and a rsx-tmp temp file
    * (via exec_safe) - never under rsx-build - so the sealed-build write-choke guards
    * are not engaged.
    *
    * @param string|null $classmap_path Absolute path to autoload_classmap.php.
    *                                   Null = the framework's committed classmap.
    */
    public static function _validate_composer_classmap(?string $classmap_path = null): void
    {
        $classmap_path = $classmap_path ?? base_path('vendor/composer/autoload_classmap.php');

        // No classmap present (e.g. a minimal install) - nothing to validate.
        if (!file_exists($classmap_path)) {
            return;
        }

        $stale = static::_find_stale_classmap_entries($classmap_path);
        if (empty($stale)) {
            return;
        }

        console_debug('AUTOLOAD', 'Composer classmap has ' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' (points at renamed/removed files) - regenerating with composer dump-autoload');

        $runner = static::$_composer_dump_runner ?? [static::class, '_run_composer_dump'];
        $runner($stale);
    }

    /**
    * Return classmap entries whose mapped file no longer exists on disk (the staleness
    * created by the override pass renaming a framework `.php` to `.php.upstream`).
    * Pure and testable: includes the classmap file and file_exists()-checks every path.
    *
    * @return array<string,string> FQCN => missing absolute path
    */
    public static function _find_stale_classmap_entries(string $classmap_path): array
    {
        // The generated classmap file returns FQCN => absolute path.
        $class_map = include $classmap_path;
        if (!is_array($class_map)) {
            return [];
        }

        $stale = [];
        foreach ($class_map as $fqcn => $path) {
            if (!file_exists($path)) {
                $stale[$fqcn] = $path;
            }
        }

        return $stale;
    }

    /**
    * Default composer-dump runner: blocking `composer dump-autoload` in base_path()
    * (= system/, where the framework composer.json lives). Fails LOUD on a non-zero
    * exit - a broken dump must never be silent. If composer is not on PATH we cannot
    * heal the on-disk classmap here; that is logged (the runtime warning tolerance
    * still keeps things working) and we return rather than fatal every rebuild.
    *
    * @param array<string,string> $stale The stale entries that triggered the dump.
    */
    public static function _run_composer_dump(array $stale): void
    {
        // Mirror Prod_Build_Command::_composer_available(): a missing composer binary
        // is not a hard failure - the Autoloader warning carve-out covers runtime, and
        // failing loud on every rebuild would break dev entirely.
        $composer_path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));
        if ($composer_path === '') {
            console_debug('AUTOLOAD', 'composer not found on PATH - cannot regenerate stale classmap (runtime warning tolerance will cover it)');

            return;
        }

        // --no-scripts is LOAD-BEARING, not an optimization. Composer's stock
        // post-autoload-dump scripts include `@php artisan package:discover`, i.e. a
        // brand-new artisan process spawned mid-pull, on a tree that is being swapped
        // underneath it, with the runtime services stopped. It also cannot inherit the
        // pull's --_framework-update-override (an argv token), so it depends entirely on
        // the maintenance gate's classification to run at all. Any failure there exits
        // the dump non-zero and fires the throw below mid-pull. The heal only needs
        // autoload_classmap.php regenerated;
        // package:discover warms Laravel's package manifest, which a class-override
        // rename cannot invalidate and the rebuild re-runs regardless. Skipping the
        // scripts removes the nested-artisan failure class entirely.
        $output = [];
        $return_var = 0;
        \exec_safe('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload --optimize --no-scripts --no-interaction 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            throw new \RuntimeException(
                "Fatal: composer dump-autoload failed (exit {$return_var}) while regenerating a stale classmap.\n\n" .
                "The manifest override pass renamed framework files to .upstream, leaving " . count($stale) . " stale classmap entr" . (count($stale) === 1 ? 'y' : 'ies') . ".\n" .
                "Composer's regeneration must succeed to heal the on-disk classmap.\n\n" .
                "composer output:\n" . implode("\n", $output)
            );
        }

        console_debug('AUTOLOAD', 'composer classmap regenerated (' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' cleared)');
    }

    /**
    * Generate VS Code IDE helper stubs for attributes and class aliases
    */
    public static function _generate_vscode_stubs(): void
    {
        // Generate to project root (parent of system/)
        $project_root = dirname(base_path());
        $stub_file = $project_root . '/._rsx_helper.php';

        $output = "<?php\n";
        $output .= "/* @noinspection ALL */\n";
        $output .= "// @formatter:off\n";
        $output .= "// phpcs:ignoreFile\n\n";
        $output .= "/**\n";
        $output .= " * RSX Framework Attribute Stubs for IDE Support\n";
        $output .= " *\n";
        $output .= " * AUTO-GENERATED FILE - DO NOT EDIT MANUALLY\n";
        $output .= " *\n";
        $output .= " * This file is automatically regenerated during manifest builds when:\n";
        $output .= " * - Running `php artisan rsx:manifest:build`\n";
        $output .= " * - File changes detected in development mode\n";
        $output .= " * - Any PHP file with attributes is modified\n";
        $output .= " *\n";
        $output .= " * These are stub definitions to provide IDE autocomplete and eliminate warnings\n";
        $output .= " * for RSX framework attributes. These classes are never actually loaded or used\n";
        $output .= " * at runtime - they exist purely for IDE IntelliSense.\n";
        $output .= " *\n";
        $output .= " * This file should not be included in your code, only analyzed by your IDE!\n";
        $output .= " */\n\n";

        // Generate attribute stubs. The ATTRIBUTE INDEX is the one answer to "which
        // attributes does this tree declare", and it is ksorted - so the generated file is
        // deterministic, which matters because it is COMMITTED. This used to walk every file
        // and every method map in insertion order.
        $attributes = array_keys(Manifest::$data['data']['attribute_index'] ?? []);
        sort($attributes, SORT_STRING);

        if (!empty($attributes)) {
            $output .= "namespace {\n";
            foreach ($attributes as $attr_name) {
                $output .= "    #[\\Attribute(\\Attribute::TARGET_ALL | \\Attribute::IS_REPEATABLE)]\n";
                $output .= "    class {$attr_name} {\n";
                $output .= "        public function __construct(...\$args) {}\n";
                $output .= "    }\n\n";
            }
            $output .= "}\n";
        }

        // CONTENT-COMPARE BEFORE WRITING. ._rsx_helper.php is COMMITTED, sits in the project
        // root and is watched by the developer's IDE; rewriting it on every build churned an
        // mtime (and, before the attribute index made it deterministic, sometimes the bytes)
        // for a file whose content is a function of the tree's attribute vocabulary alone.
        if (!file_exists($stub_file) || file_get_contents($stub_file) !== $output) {
            file_put_contents_safe($stub_file, $output);
        }
    }
}
