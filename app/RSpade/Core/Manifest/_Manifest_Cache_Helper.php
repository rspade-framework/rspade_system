<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Kernels\ManifestKernel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Rsx;

/**
 * _Manifest_Cache_Helper - Persistence, loading, and validation
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Cache_Helper
{
    /**
    * Get or create the kernel instance
    */
    public static function _get_kernel(): ManifestKernel
    {
        if (Manifest::$kernel === null) {
            Manifest::$kernel = app(ManifestKernel::class);
        }

        return Manifest::$kernel;
    }

    /**
    * Get the full cache file path
    */
    public static function _get_cache_file_path(): string
    {
        // Storage-root relative: volatile storage was relocated out of system/ to the
        // project root, so this must never be derived from base_path(). The ROOT itself
        // comes from the build, so a test can put an index somewhere of its own.
        return Manifest::build()->cache_file_path();
    }

    // move to lower soon

    /**
    * Where the bad-manifest flag lives - a sidecar beside the index, never inside it.
    *
    * Its CONTENT is a human sentence; its EXISTENCE is the whole signal.
    */
    public static function _bad_flag_path(): string
    {
        return dirname(Manifest::_get_cache_file_path()) . '/manifest_is_bad';
    }

    /**
    * Raise the bad-manifest flag: the next load refuses the cache and rebuilds in full.
    *
    * Nothing is written to the index. The predecessor called _save() from inside a FAILING
    * build, which overwrote the last good index with the half-built one the process happened
    * to be holding, plus a build key derived from it.
    */
    public static function _write_bad_flag(): void
    {
        $path = static::_bad_flag_path();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents(
            $path,
            "The last manifest build failed its own validation. The next load rebuilds from\n"
            . "scratch and re-reports the failure. Delete nothing: fix the source.\n"
        );
    }

    /**
    * Clear the flag. A build that COMPLETED supersedes the poisoning that preceded it.
    */
    public static function _clear_bad_flag(): void
    {
        $path = static::_bad_flag_path();

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
    * Load the HOT index, and NOTHING else.
    *
    * The index is two files. `manifest_index.php` is what a served request needs - routes,
    * the class maps, the auth surfaces, the attribute index, the models, and the `files`
    * entries for the handful of classes whose METHOD MAP is read at request time (models and
    * their ancestors, task services). `manifest_files.php` is every other `files` entry, method
    * maps intact, and is loaded ONCE per process by the first accessor that genuinely needs a
    * record the hot file does not carry - which boot, dispatch, an Ajax call and a model fetch
    * never do.
    *
    * The staleness sweep still sees every file: the hot index carries `file_index`, a compact
    * path => [size, mtime] map over the WHOLE tree, which is what _validate_cached_data()
    * compares against. A few hundred KB, not the metadata.
    */
    public static function _load_cached_data()
    {
        // The change memo is a statement ABOUT the currently loaded data ("this file matches
        // what the manifest records"), so replacing that data invalidates every entry in it.
        //
        // Without this, init()'s re-check under the build lock answered from a memo computed
        // against the PREVIOUS cache: a process that loaded a cache containing the test trees,
        // was sent to the lock by something else (a concurrent build regenerating the Phase-6
        // stub outputs), and then reloaded the tests-LESS cache another process had just
        // written, was told every test file was unchanged - and ran with a manifest that did
        // not contain them. Observed as an intermittent "No test classes found" from rsx:test
        // racing a manifest build.
        Manifest::$_has_changed_cache = [];

        // A reload replaces the hot half; whatever cold half was merged into it belongs to the
        // superseded data and must be fetched again on demand.
        Manifest::$_cold_loaded = false;

        $cache_file = Manifest::_get_cache_file_path();

        // A raised bad-manifest flag refuses the cache outright - the index on disk may be
        // perfectly well-formed and still describe a tree that failed its own validation.
        if (file_exists(static::_bad_flag_path())) {
            Manifest::$data = static::_empty_data();

            return false;
        }

        if (file_exists($cache_file)) {
            Manifest::$data = include $cache_file;
            // Validate structure
            if (is_array(Manifest::$data) && isset(Manifest::$data['data']['files'])) {
                static::_derive_load_time_indexes();

                return true;
            }
        }

        // Cache doesn't exist or is invalid - return false without logging
        // Logging happens in init() after we determine we actually need to rebuild
        Manifest::$data = static::_empty_data();

        return false;
    }

    /**
    * The empty shape a failed or absent load leaves behind.
    */
    protected static function _empty_data(): array
    {
        return [
            'hash' => '',
            'data' => [
                'files' => [],
                'file_index' => [],
            ],
        ];
    }

    /**
    * Where the COLD half of the index lives.
    */
    public static function _get_cold_file_path(): string
    {
        return Manifest::build()->storage_root() . '/' . Manifest::COLD_FILE;
    }

    /**
    * Merge the cold half into `files`, once per process.
    *
    * The hot entries WIN the merge: they are the same records, and a hot entry is what a
    * rebuilding process has already updated in memory.
    */
    public static function _load_cold_files(): void
    {
        if (Manifest::$_cold_loaded) {
            return;
        }

        // Set FIRST: a throw inside the include must not leave a process retrying the load on
        // every accessor, and a build in progress (which holds the complete files map in
        // memory already) has nothing to merge.
        Manifest::$_cold_loaded = true;
        Manifest::$_cold_load_count++;

        $path = static::_get_cold_file_path();

        if (!file_exists($path)) {
            return;
        }

        $cold = include $path;

        if (!is_array($cold)) {
            return;
        }

        // '+' keeps the left operand's entries for duplicate keys - the hot half wins.
        Manifest::$data['data']['files'] = Manifest::$data['data']['files'] + $cold;
    }

    /**
    * The indexes that are DERIVED at load rather than persisted.
    *
    * `routes_by_target` is a regroup of `routes` and nothing else - persisting it wrote the
    * same 80 KB of route rows a second time. Rebuilding it here costs no memory worth naming:
    * PHP arrays are copy-on-write, so the regrouped rows share the storage of the rows in
    * `routes`.
    */
    public static function _derive_load_time_indexes(): void
    {
        foreach ([['routes', 'routes_by_target'], ['portal_routes', 'portal_routes_by_target']] as [$source, $derived]) {
            $by_target = [];

            foreach (Manifest::$data['data'][$source] ?? [] as $row) {
                $target = $row['target'] ?? null;

                if ($target === null) {
                    continue;
                }

                $by_target[$target][] = $row;
            }

            ksort($by_target);
            Manifest::$data['data'][$derived] = $by_target;
        }
    }

    /**
    * Save the index as TWO files: the hot one a request loads, and the cold one it does not.
    *
    * `manifest_index.php` carries every derived section plus the `files` entries whose METHOD
    * MAP is read at request time - models and their ancestors (Orm_Controller and
    * get_relationships() walk them), task services (Task and Task_Concurrency read #[Task],
    * #[Exclusive], #[Debounce]) and the generated stub outputs (the staleness sweep proves
    * they still exist). `manifest_files.php` carries every other entry, method maps intact.
    *
    * Both are written temp-then-rename and opcache-invalidated, and `build_key` is renamed
    * LAST, so a reader that sees a new key is guaranteed to be able to read both halves.
    */
    public static function _save(): void
    {
        // A build that reached _save() completed. Whatever poisoned the PREVIOUS one is
        // answered by this index, so the flag comes down.
        static::_clear_bad_flag();

        // Validate manifest data before saving
        Manifest::_validate_manifest_data();

        $cache_file = Manifest::_get_cache_file_path();
        $cold_file = static::_get_cold_file_path();

        // Ensure directory exists
        $dir = dirname($cache_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sort files array by key for predictable output
        ksort(Manifest::$data['data']['files']);

        // No timestamp in the file body, in ANY mode. The bytes of a build are a function of
        // the tree and nothing else - that is what makes two builds comparable.
        unset(Manifest::$data['generated']);

        // The by-target route indexes are DERIVED AT LOAD and never persisted (they are a
        // byte-identical regroup of routes). They are dropped from the PAYLOAD, not from the
        // live data: a process that just rebuilt goes on serving the request it was in the
        // middle of, and Rsx::Route() reads routes_by_target.
        //
        // A build that ran BEFORE the modules did (the phase order guarantees it did not, but
        // a partial state must not silently ship an empty index) derives them here.
        static::_derive_load_time_indexes();

        // Strict production strips per-file mtime/size, which are local disk state that
        // dev change-detection reads and a sealed build never consults (init() returns
        // after loading the production cache, before _validate_cached_data()).
        $strict_prod = Rsx::is_production() && !Rsx::is_debug();

        $hot_paths = static::_hot_file_paths();
        $file_index = [];
        $hot_files = [];
        $cold_files = [];

        foreach (Manifest::$data['data']['files'] as $path => $meta) {
            if (!$strict_prod) {
                $file_index[$path] = [$meta['size'] ?? 0, $meta['mtime'] ?? 0];
            } else {
                unset($meta['mtime'], $meta['size']);
            }

            if (isset($hot_paths[$path])) {
                $hot_files[$path] = $meta;
            } else {
                $cold_files[$path] = $meta;
            }
        }

        Manifest::$data['data']['file_index'] = $file_index;

        Manifest::$data['hash'] = self::_compute_hash(Manifest::$data['data']);

        // The hot payload: every derived section, the file index, and the hot files only.
        $hot = Manifest::$data;
        $hot['data']['files'] = $hot_files;
        unset($hot['data']['routes_by_target'], $hot['data']['portal_routes_by_target']);

        $header = "// Generated manifest index - DO NOT EDIT\n"
            . '// Files: ' . count($file_index) . ' (' . count($hot_files) . " hot)\n"
            . '// Hash: ' . Manifest::$data['hash'] . "\n\n";

        self::_write_php_literal($cold_file, $cold_files, "// Generated manifest file metadata - DO NOT EDIT\n\n");
        self::_write_php_literal($cache_file, $hot, $header);

        // Build key LAST: a consumer that sees a new key can read both halves.
        file_put_contents_safe(dirname($cache_file) . '/build_key', Manifest::$data['hash']);
    }

    /**
    * The `files` entries the HOT index carries, as a path => true set.
    *
    * THE COLD HALF IS METHOD MAPS. 81% of the index was `files`, and 76% of THAT was
    * `public_static_methods` and `public_instance_methods` on PHP and JS classes; every other
    * record in the tree - blade views, jqhtml templates, stylesheets, classless PHP - is
    * small, and something on the request path reads most of them (a layout chain walks blade
    * records, the jqhtml compiler reads template records). So a record with no method map is
    * HOT by default, and the split is exactly the thing the split is for.
    *
    * On top of that, three populations keep their method maps hot, each because something on
    * the REQUEST PATH reads one:
    *   - models and every class in a model's lineage (Orm_Controller reads the fetch and
    *     relationship attributes; Rsx_Model_Abstract::get_relationships() climbs the
    *     ancestors, so the abstract intermediates are as load-bearing as the models);
    *   - task services (Task::internal, Task::_find_task_class and
    *     Task_Concurrency::_method_attributes read #[Task], #[Exclusive] and #[Debounce]);
    *   - the generated stub outputs, whose entries the staleness sweep reads on every
    *     development request.
    *
    * @return array<string,bool>
    */
    protected static function _hot_file_paths(): array
    {
        $body = Manifest::$data['data'];
        $paths = [];

        // Model lineage. php_subclass_index holds every DESCENDANT of a parent (the builder
        // walks the whole chain), abstract intermediates included.
        $model_classes = $body['php_subclass_index']['Rsx_Model_Abstract'] ?? [];
        $model_classes[] = 'Rsx_Model_Abstract';

        foreach ($model_classes as $class) {
            $entry = $body['php_classes'][$class] ?? null;

            if (is_array($entry) && isset($entry['file'])) {
                $paths[$entry['file']] = true;
            }
        }

        // Task services, by the attributes that make them one.
        foreach (['Task', 'Schedule', 'Command', 'Exclusive', 'Debounce'] as $attribute) {
            foreach ($body['attribute_index'][$attribute] ?? [] as $row) {
                $paths[$row['file']] = true;
            }
        }

        foreach ($body['files'] as $path => $meta) {
            // Generated stub outputs - the staleness sweep reads these every request.
            if (!empty($meta['is_stub']) || !empty($meta['is_model_stub'])) {
                $paths[$path] = true;

                continue;
            }

            // No method map, nothing to move.
            if (!isset($meta['public_static_methods'])
                && !isset($meta['public_instance_methods'])
                && !isset($meta['methods'])) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    /**
    * Write a PHP literal to disk atomically, STREAMED.
    *
    * var_export() built the whole 8.8 MB file as one string before a byte was written, and
    * spelled arrays `array(...)` across three lines each. This emits short-array syntax with
    * no whitespace, flushing to the temp handle in chunks, so the peak cost of a save is the
    * chunk buffer rather than the file.
    */
    protected static function _write_php_literal(string $path, array $value, string $header): void
    {
        $temp = $path . '.tmp.' . getmypid();
        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Manifest save could not open {$temp} for writing");
        }

        $buffer = "<?php\n\n" . $header . 'return ';
        self::_emit_php_literal($handle, $value, $buffer);
        $buffer .= ";\n";
        fwrite($handle, $buffer);
        fclose($handle);

        @chmod($temp, 0664);

        if (!rename($temp, $path)) {
            @unlink($temp);

            throw new \RuntimeException("Manifest save could not rename {$temp} to {$path}");
        }

        // The file the next request includes is THIS one, and opcache would otherwise serve
        // the superseded bytes until its own revalidation noticed.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    /**
    * Append one value's literal to $buffer, flushing the buffer to $handle as it fills.
    */
    protected static function _emit_php_literal($handle, mixed $value, string &$buffer): void
    {
        if (is_array($value)) {
            $buffer .= '[';

            if (array_is_list($value)) {
                $first = true;

                foreach ($value as $item) {
                    if (!$first) {
                        $buffer .= ',';
                    }
                    $first = false;
                    self::_emit_php_literal($handle, $item, $buffer);
                }
            } else {
                $first = true;

                foreach ($value as $key => $item) {
                    if (!$first) {
                        $buffer .= ',';
                    }
                    $first = false;
                    $buffer .= is_int($key) ? $key . '=>' : var_export((string) $key, true) . '=>';
                    self::_emit_php_literal($handle, $item, $buffer);
                }
            }

            $buffer .= ']';
        } else {
            $buffer .= self::_php_scalar_literal($value);
        }

        if (strlen($buffer) >= 262144) {
            fwrite($handle, $buffer);
            $buffer = '';
        }
    }

    /**
    * One scalar's literal. Strings go through var_export(), which single-quotes and escapes
    * exactly the two characters that need it.
    */
    protected static function _php_scalar_literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return var_export($value, true);
    }

    public static function _validate_cached_data()
    {
        // If cache exists, check if anything changed
        $files = Manifest::_get_rsx_files();

        // Check for changed files
        foreach ($files as $file) {
            if (Manifest::_has_changed($file)) {
                return false;
            }
        }

        // Check for deleted files. file_index is the WHOLE tree - path => [size, mtime] -
        // which is exactly what this sweep and _has_changed() need, and is why the cold half
        // of the index never has to be loaded to answer "is the cache stale".
        $existing_files = array_flip($files);

        foreach (array_keys(Manifest::$data['data']['file_index'] ?? []) as $cached_file) {
            // Skip storage files - they're not part of the manifest
            if (str_starts_with($cached_file, 'storage/')) {
                continue;
            }
            if (!isset($existing_files[$cached_file])) {
                // Only show the message once per page load
                if (!Manifest::$__shown_rescan_message) {
                    console_debug('MANIFEST', '* Deleted file ' . $cached_file . ' is triggering manifest rescan *');
                    Manifest::$__shown_rescan_message = true;
                }

                return false;
            }
        }

        // Phase-6 stub outputs (controller js-stubs + model js-model-stubs) live under
        // storage/rsx-build, are recorded as manifest 'files' entries flagged is_stub /
        // is_model_stub, and get a fresh mtime every build - so they are DELIBERATELY
        // exempt from both the mtime staleness sweep and the deletion sweep above (the
        // storage/ skip). That exemption also means their ABSENCE from disk never
        // invalidates the cache on its own: a fresh-and-valid cache whose stub files (or
        // the whole js-model-stubs dir) went missing after a framework-update recovery or
        // a prune would otherwise stay permanently stub-less, killing the client-side
        // model layer app-wide with no self-heal. Prove the recorded outputs still exist;
        // a missing one makes the cache STALE so init() falls through to a full rebuild
        // that regenerates them. Cheap file_exists() sweep, no hashing.
        //
        // DEV-ONLY by construction: init() returns after loading the production cache
        // (the Rsx::is_production() branch) BEFORE ever calling _validate_cached_data(),
        // so this guard only runs on the development change-detection path. Sealed
        // debug/production builds already guarantee their artifacts and fail loud on
        // missing ones, so no auto-rebuild is added there.
        $missing_stub = self::_first_missing_stub_output(Manifest::$data['data']['files']);
        if ($missing_stub !== null) {
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* Missing generated stub output ' . $missing_stub . ' is triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }

            return false;
        }

        return true;
    }

    /**
     * Return the first recorded Phase-6 stub output missing from disk, or null.
     *
     * Scans a manifest files map for generated-stub entries (flagged is_stub for
     * controller js-stubs, is_model_stub for model js-model-stubs) and returns the
     * project-relative path of the first one whose file does not exist. Resolves each
     * stub independently, so it catches both a single deleted stub file and the whole
     * js-stubs / js-model-stubs directory being absent. Returns null when every stub
     * output is present (or when there are none).
     *
     * Pure over its argument (file_exists is the only side channel) so it is unit
     * testable with a synthetic files map.
     *
     * @param array $manifest_files The value of Manifest::$data['data']['files']
     * @return string|null Missing stub's project-relative path, or null if all present
     */
    public static function _first_missing_stub_output(array $manifest_files): ?string
    {
        foreach ($manifest_files as $path => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if (empty($meta['is_model_stub']) && empty($meta['is_stub'])) {
                continue;
            }
            if (!file_exists(Rsx_Paths::absolute($path))) {
                return $path;
            }
        }

        return null;
    }

    /**
    * Validate manifest data for consistency
    */
    public static function _validate_manifest_data(): void
    {
        if (!isset(Manifest::$data['data']['files'])) {
            throw new \RuntimeException(
                'Fatal: Manifest::validate_manifest_data() called but manifest data structure is not initialized. ' .
"This shouldn't happen - data should be populated before validation."
            );
        }

        // NOTE: PHP and JS class-name uniqueness is NOT checked here. There is ONE detector,
        // _collate_files_by_classes(), which builds the class maps and throws on a duplicate
        // naming both files - and the class-override pass runs before it and turns the
        // legitimate rsx/-over-framework case into an archive. A third copy of the check
        // here could only ever agree or contradict.
        $blade_ids = [];
        $jqhtml_ids = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            $extension = $metadata['extension'] ?? '';

            // Check Blade ID uniqueness
            if ($extension === 'blade.php' && isset($metadata['id'])) {
                $id = $metadata['id'];
                if (isset($blade_ids[$id])) {
                    throw new \RuntimeException(
                        "Duplicate Blade @rsx_id detected: {$id}\n" .
"Found in:\n" .
"  - {$blade_ids[$id]}\n" .
"  - {$file_path}\n\n" .
"Blade @rsx_id values must be unique across all files.\n\n" .
"To resolve: Add specificity to the @rsx_id by prefixing with directory segments.\n" .
"For example, if a view with @rsx_id('Layout') resides in the 'demo' module directory,\n" .
"change it to @rsx_id('Demo_Layout'). Similarly, views in nested directories like\n" .
"'demo/sections/' could use @rsx_id('Demo_Sections_Layout'). After renaming, refactor\n" .
'all references to the old @rsx_id (in @rsx_extends, @rsx_include, etc.) to use the new name.'
                    );
                }
                $blade_ids[$id] = $file_path;
            }

            // Check Jqhtml component ID uniqueness
            if ($extension === 'jqhtml' && isset($metadata['id'])) {
                $id = $metadata['id'];
                if (isset($jqhtml_ids[$id])) {
                    throw new \RuntimeException(
                        "Duplicate jqhtml component name detected: {$id}\n" .
"Found in:\n" .
"  - {$jqhtml_ids[$id]}\n" .
"  - {$file_path}\n\n" .
"Jqhtml component names (<Define:ComponentName>) must be unique across all files.\n\n" .
"To resolve: Add specificity to the component name by prefixing with directory segments.\n" .
"For example, if a component named 'Card' resides in the 'demo' module directory,\n" .
"rename it to 'Demo_Card' in the <Define:> tag. Similarly, components in nested directories\n" .
"like 'demo/widgets/' could be named 'Demo_Widgets_Card'. After renaming, refactor all\n" .
'usages of the component (in Blade templates and JavaScript) to use the new, more specific name.'
                    );
                }
                $jqhtml_ids[$id] = $file_path;
            }

            // Check that controller actions don't have both Route and Ajax_Endpoint
            if ($extension === 'php' && isset($metadata['extends'])) {
                // Check if this is a controller (extends Rsx_Controller_Abstract)
                $is_controller = Manifest::_is_controller_class($metadata);

                if ($is_controller && isset($metadata['public_static_methods'])) {
                    foreach ($metadata['public_static_methods'] as $method_name => $method_info) {
                        if (!isset($method_info['attributes'])) {
                            continue;
                        }

                        $has_route = false;
                        $has_ajax_endpoint = false;
                        $has_task = false;

                        foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                            if ($attr_name === 'Route' || str_ends_with($attr_name, '\\Route')) {
                                $has_route = true;
                            }
                            if ($attr_name === 'Ajax_Endpoint' || str_ends_with($attr_name, '\\Ajax_Endpoint')) {
                                $has_ajax_endpoint = true;
                            }
                            if ($attr_name === 'Task' || str_ends_with($attr_name, '\\Task')) {
                                $has_task = true;
                            }
                        }

                        // Check for conflicting attributes
                        $conflicts = [];
                        if ($has_route) {
                            $conflicts[] = 'Route';
                        }
                        if ($has_ajax_endpoint) {
                            $conflicts[] = 'Ajax_Endpoint';
                        }
                        if ($has_task) {
                            $conflicts[] = 'Task';
                        }

                        if (count($conflicts) > 1) {
                            $class_name = $metadata['class'] ?? 'Unknown';

                            throw new \RuntimeException(
                                'Method cannot have multiple execution type attributes: ' . implode(', ', $conflicts) . "\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n" .
'A method must be either a Route, Ajax_Endpoint, OR Task, not multiple types.'
                            );
                        }

                        // Check Ajax_Endpoint methods don't have return types
                        if ($has_ajax_endpoint && isset($method_info['return_type'])) {
                            $class_name = $metadata['class'] ?? 'Unknown';
                            $return_type_info = $method_info['return_type'];

                            // Format return type for error message
                            if (isset($return_type_info['type']) && $return_type_info['type'] === 'union') {
                                $type_display = implode('|', $return_type_info['types']);
                            } else {
                                $type_display = $return_type_info['type'] ?? 'unknown';
                                if (!empty($return_type_info['nullable'])) {
                                    $type_display = '?' . $type_display;
                                }
                            }

                            throw new \RuntimeException(
                                "Ajax endpoint has forbidden return type declaration: {$type_display}\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"Ajax endpoints must NOT declare return types because they need flexibility to return:\n" .
"- Array data (success case)\n" .
"- Form_Error_Response (validation errors)\n" .
"- Redirect_Response (redirects)\n" .
"- Other response types as needed\n\n" .
"Solution: Remove the return type declaration from this method.\n" .
"Change: public static function {$method_name}(...): {$type_display}\n" .
"To:     public static function {$method_name}(...)\n"
                            );
                        }

                        // Check FPC attribute constraints
                        $has_fpc = false;
                        $has_spa = false;

                        foreach ($method_info['attributes'] as $attr_name => $attr_instances) {
                            if ($attr_name === 'FPC' || str_ends_with($attr_name, '\\FPC')) {
                                $has_fpc = true;
                            }
                            if ($attr_name === 'SPA' || str_ends_with($attr_name, '\\SPA')) {
                                $has_spa = true;
                            }
                        }

                        if ($has_fpc) {
                            $class_name = $metadata['class'] ?? 'Unknown';

                            if ($has_ajax_endpoint) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on Ajax endpoints\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"#[FPC] marks a route for full page caching. Ajax endpoints return JSON\n" .
"data, not HTML pages.\n\n" .
"Solution: Remove #[FPC] from this Ajax endpoint."
                                );
                            }

                            if ($has_spa) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on #[SPA] methods\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"SPA bootstrap methods return an empty shell that JavaScript populates.\n" .
"Caching this shell serves the same empty page for all SPA routes.\n\n" .
"Solution: Remove #[FPC] from this SPA method."
                                );
                            }

                            if ($has_task) {
                                throw new \RuntimeException(
                                    "#[FPC] cannot be used on Task methods\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"Solution: Remove #[FPC] from this Task method."
                                );
                            }

                            if (!$has_route) {
                                throw new \RuntimeException(
                                    "#[FPC] requires #[Route] attribute\n" .
"Class: {$class_name}\n" .
"Method: {$method_name}\n" .
"File: {$file_path}\n\n" .
"#[FPC] can only be used on methods with a #[Route] attribute.\n\n" .
"Solution: Add #[Route('/path')] to this method, or remove #[FPC]."
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Unlink the manifest cache file only (fast rebuild trigger)
     *
     * This removes only the manifest cache file, preserving all parsed AST data
     * and incremental caches. On next load, the manifest will do a full scan and
     * reindex but will reuse existing parsed metadata where files haven't changed.
     *
     * This is much faster than rsx:clean which wipes all caches including parsed
     * AST data, forcing expensive re-parsing of all PHP/JS files.
     *
     * Use this after database migrations or schema changes that affect model
     * metadata without changing the actual source code.
     */
    public static function _unlink_cache(): void
    {
        foreach ([Manifest::_get_cache_file_path(), static::_get_cold_file_path()] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * The build key: SHA-256 over the per-file hashes plus the derived sections.
     *
     * Two byte-identical checkouts at different absolute paths must produce the same key (the
     * cluster contract), so nothing that varies with local disk state enters it:
     *   - a file contributes its PATH and its sha1 and nothing else, which is why mtime, size
     *     and any absolute path embedded in a reflected method record cannot reach the key;
     *   - the per-file lines are SORTED, so readdir order cannot reach it either;
     *   - the derived sections are hashed in their stored order, which the producers make
     *     deterministic (the class maps and the autoloader map are ksorted at build).
     *
     * NO DEEP COPY. The predecessor rebuilt the entire manifest body node by node -
     * ksorting every associative node and running a path rewrite over every string - and
     * measured 29 ms plus a second copy of the index in memory, on every save.
     *
     * @param array $manifest_body The value of Manifest::$data['data']
     * @return string 32-char truncated SHA-256
     */
    public static function _compute_hash(array $manifest_body): string
    {
        $file_lines = [];

        foreach ($manifest_body['files'] ?? [] as $path => $meta) {
            $file_lines[] = $path . ' ' . (is_array($meta) ? ($meta['hash'] ?? '') : '');
        }

        sort($file_lines, SORT_STRING);

        // The derived half. file_index is local disk state by definition; files is already
        // accounted for above; the by-target indexes are a regroup of routes and are not
        // persisted at all.
        unset(
            $manifest_body['files'],
            $manifest_body['file_index'],
            $manifest_body['routes_by_target'],
            $manifest_body['portal_routes_by_target']
        );

        $derived = '';
        self::_emit_hash_material($manifest_body, $derived);

        return substr(
            hash('sha256', hash('sha256', implode("\n", $file_lines)) . "\n" . hash('sha256', $derived)),
            0,
            32
        );
    }

    /**
     * Append a compact, unambiguous serialization of $value to $material.
     *
     * Not the PHP literal emitter: this one needs no valid syntax, only injectivity, and it
     * appends to ONE string rather than streaming (the derived sections are a fraction of the
     * index). Absolute paths are reduced to project-relative form here rather than at every
     * producer, because the producers that embed one do so incidentally.
     */
    protected static function _emit_hash_material(mixed $value, string &$material): void
    {
        if (is_array($value)) {
            $material .= '[';

            foreach ($value as $key => $item) {
                $material .= $key . ':';
                self::_emit_hash_material($item, $material);
                $material .= ',';
            }

            $material .= ']';

            return;
        }

        if (is_string($value)) {
            $material .= '"' . _rsx_relative_build_path($value) . '"';

            return;
        }

        $material .= self::_php_scalar_literal($value);
    }
}
