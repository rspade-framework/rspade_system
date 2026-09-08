<?php

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Emit one JavaScript stub per ORM model - enum constants, relationship methods, detail
 * accessors and the field_length() table - so a model has the same surface in both languages.
 *
 * AN ORDINARY SUPPORT MODULE, the same list as every other; it runs after
 * Model_ManifestSupport because the list says so, and it reads that module's column map.
 *
 * WHAT IT WILL NOT DO IS WRITE FOR NOTHING. Three gates, cheapest first: the source mtime,
 * the model metadata hash (recomputed only when the model FILE HASH moved - the reflection
 * behind it is the expensive part), and finally a content compare against the file on disk.
 * A rebuild that changes no model rewrites no stub, so no bundle recompiles on a churned
 * mtime.
 */
class Model_Stub_ManifestSupport extends ManifestSupport_Abstract
{
    public static function get_name(): string
    {
        return 'Model JS Stubs';
    }

    /**
     * Generate JavaScript stub files for ORM models
     *
     * These stubs enable IDE autocomplete and provide relationship methods
     * for models that extend Rsx_Model_Abstract.
     *
     * TODO: This function needs cleanup
     *
     * @param array &$manifest_data The complete manifest data (passed by reference)
     * @return void
     */
    public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        $stub_dir = storage_path('rsx-build/js-model-stubs');

        // Create directory if it doesn't exist
        if (!is_dir($stub_dir)) {
            mkdir($stub_dir, 0755, true);
        }

        // Track generated stub files for cleanup
        $generated_stubs = [];

        // Get all models from the manifest
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');
        console_debug('STUB_GEN', 'Found ' . count($model_entries) . ' models extending Rsx_Model_Abstract');

        foreach ($model_entries as $model_entry) {
            if (!isset($model_entry['fqcn'])) {
                continue;
            }

            $fqcn = $model_entry['fqcn'];
            $class_name = $model_entry['class'] ?? '';

            // Skip if it extends Rsx_System_Model_Abstract. ONE subclass answer: the
            // manifest's own php_subclass_index, which has already walked every chain. The
            // private copy that used to live here re-scanned the whole file map per model
            // and compared only the one-level simple `extends`, so it missed an FQCN parent.
            if (Manifest::php_is_subclass_of($class_name, 'Rsx_System_Model_Abstract')) {
                console_debug('STUB_GEN', "  Skipping {$class_name}: extends Rsx_System_Model_Abstract");
                continue;
            }

            // Load the class and its hierarchy
            Manifest::_load_class_hierarchy($fqcn, $manifest_data);

            // Verify class loaded
            if (!class_exists($fqcn)) {
                shouldnt_happen("Failed to load model class {$fqcn} after _load_class_hierarchy");
            }

            // Note: Abstract classes already filtered by php_get_extending()

            console_debug('STUB_GEN', "  Processing {$class_name} for stub generation...");

            // Get model metadata from manifest
            $file_path = $model_entry['file'] ?? '';
            $metadata = isset($manifest_data['data']['files'][$file_path]) ? $manifest_data['data']['files'][$file_path] : [];

            // Generate stub filename and paths
            // Always use Base_ prefix - concrete classes are handled at bundle compilation time
            $stub_class_name = 'Base_' . $class_name;
            $stub_filename = static::_sanitize_model_stub_filename($stub_class_name) . '.js';

            $stub_relative_path = 'storage/rsx-build/js-model-stubs/' . $stub_filename;
            $stub_full_path = rsx_project_file_path($stub_relative_path);

            // Check if stub needs regeneration
            $stub_content = null;
            $needs_regeneration = true;
            $source_hash = $metadata['hash'] ?? '';
            $columns_hash = md5(json_encode($manifest_data['data']['models'][$class_name]['columns'] ?? []));
            $inputs_hash = $source_hash . ':' . $columns_hash;

            if (file_exists($stub_full_path)) {
                // Get mtime of source PHP file
                $source_mtime = $metadata['mtime'] ?? 0;
                $stub_mtime = filemtime($stub_full_path);

                // Only regenerate if source is newer than stub
                if ($stub_mtime >= $source_mtime) {
                    // THE REFLECTION IS THE EXPENSIVE PART, so it is not run to decide
                    // whether to run it. _get_model_metadata_for_hash() calls
                    // get_relationships(), reads every public constant and re-reads the
                    // column map; its answer can only move when the model FILE or its
                    // COLUMNS moved, so the stored hash records WHICH inputs it was
                    // computed for and the reflection is skipped when they are unchanged.
                    $stored = $metadata['model_metadata_hash'] ?? null;
                    $stored_inputs = $metadata['model_metadata_inputs'] ?? null;

                    if ($stored !== null && $stored_inputs === $inputs_hash) {
                        $needs_regeneration = false;
                        $model_metadata_hash = $stored;
                    } else {
                        $model_metadata = static::_get_model_metadata_for_hash($fqcn, $class_name, $manifest_data);
                        $model_metadata_hash = md5(json_encode($model_metadata));

                        if ($model_metadata_hash === ($stored ?? '')) {
                            $needs_regeneration = false;
                        }
                    }

                    // Store the hash, and the inputs it was computed for.
                    $manifest_data['data']['files'][$file_path]['model_metadata_hash'] = $model_metadata_hash;
                    $manifest_data['data']['files'][$file_path]['model_metadata_inputs'] = $inputs_hash;
                }
            }

            if ($needs_regeneration) {
                // Generate stub content
                $stub_content = static::_generate_model_stub_content($fqcn, $class_name, $stub_class_name, $manifest_data);

                // CONTENT-COMPARE BEFORE WRITING - a write that changes nothing still moves
                // the mtime, and a moved mtime recompiles every bundle carrying the stub.
                if (!file_exists($stub_full_path) || file_get_contents($stub_full_path) !== $stub_content) {
                    file_put_contents_safe($stub_full_path, $stub_content);
                }

                // Store the metadata hash for future comparisons if not already done
                if (!isset($manifest_data['data']['files'][$file_path]['model_metadata_hash'])) {
                    $model_metadata = static::_get_model_metadata_for_hash($fqcn, $class_name, $manifest_data);
                    $manifest_data['data']['files'][$file_path]['model_metadata_hash'] = md5(json_encode($model_metadata));
                    $manifest_data['data']['files'][$file_path]['model_metadata_inputs'] = $inputs_hash;
                }
            }

            $generated_stubs[] = $stub_filename;

            // Add js_stub property to manifest data
            $metadata['js_stub'] = $stub_relative_path;

            // Write the updated metadata back to the manifest
            $manifest_data['data']['files'][$file_path]['js_stub'] = $stub_relative_path;

            // Debug: Verify the value was written
            // console_debug('STUB_GEN', "    Written js_stub for {$file_path}: {$stub_relative_path}");
            // if (!isset($manifest_data['data']['files'][$file_path]['js_stub'])) {
            //     console_debug('STUB_GEN', '    ERROR: js_stub not set after writing!');
            // }

            // Add the stub file itself to the manifest
            // The stub's own record. Phase 2 strips every `storage/` entry, so it has to be
            // re-derived each build - but the CONTENT is in hand whenever this build wrote
            // it, so the file is not read a second time to hash bytes we just produced.
            clearstatcache(true, $stub_full_path);
            $stat = stat($stub_full_path);
            $manifest_data['data']['files'][$stub_relative_path] = [
                'hash' => $stub_content !== null ? sha1($stub_content) : sha1_file($stub_full_path),
                'mtime' => $stat['mtime'],
                'size' => $stat['size'],
                'extension' => 'js',
                'class' => $stub_class_name,
                'is_model_stub' => true,  // Mark this as a generated model stub
                'source_model' => $file_path,  // Reference to the source model
            ];
        }

        // Clean up orphaned stub files: ONE pass over the stub directory against a SET, in
        // place of the per-model stat + sha1_file + glob it used to be.
        //
        // Deliberately NOT gated on the removed set alone. A stub is orphaned by a REMOVED
        // model, but also by a model that merely CHANGED - renamed its class, or started
        // extending Rsx_System_Model_Abstract - and those never appear in the removed set.
        // A build that touched nothing at all is the one case with nothing to sweep.
        if (empty($changed_files) && empty($removed_files)) {
            return;
        }

        $generated_lookup = array_flip($generated_stubs);
        $existing_stubs = glob($stub_dir . '/*.js');
        foreach ($existing_stubs as $existing_stub) {
            $filename = basename($existing_stub);
            if (!isset($generated_lookup[$filename])) {
                // Remove from disk (check exists to avoid Windows errors)
                if (file_exists($existing_stub)) {
                    unlink($existing_stub);
                }

                // Remove from manifest
                $stub_relative_path = 'storage/rsx-build/js-model-stubs/' . $filename;
                if (isset($manifest_data['data']['files'][$stub_relative_path])) {
                    unset($manifest_data['data']['files'][$stub_relative_path]);
                }
            }
        }
    }

    /**
     * Get model metadata for hash comparison (detects when stubs need regeneration)
     *
     * @param string $fqcn Fully qualified class name
     * @param string $class_name Simple class name
     * @param array $manifest_data The manifest data array
     * @return array Metadata array for hashing
     */
    private static function _get_model_metadata_for_hash(string $fqcn, string $class_name, array $manifest_data): array
    {
        $model_metadata = [];

        // Get relationships
        $model_metadata['rel'] = $fqcn::get_relationships();

        // Get enums
        if (property_exists($fqcn, 'enums')) {
            $model_metadata['enums'] = $fqcn::$enums ?? [];
        }

        // Derived properties (PHP $appends). Adding or removing one changes the stub's declared
        // surface, so it has to move the metadata hash or the stub is never regenerated.
        $model_metadata['appends'] = static::_get_model_appends($fqcn);

        // Realtime emission flag — flipping $realtime must regenerate the stub so the
        // baked-in `static __REALTIME` line appears/disappears (see _generate_model_stub_content).
        $model_metadata['realtime'] = property_exists($fqcn, 'realtime') && $fqcn::$realtime === true;

        // Get columns from models metadata if available
        if (isset($manifest_data['data']['models'][$class_name]['columns'])) {
            $model_metadata['columns'] = $manifest_data['data']['models'][$class_name]['columns'];
        }

        // Get public constants defined directly on this class
        $reflection = new \ReflectionClass($fqcn);
        $constants = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $const) {
            if ($const->getDeclaringClass()->getName() === $fqcn) {
                $constants[$const->getName()] = $const->getValue();
            }
        }
        if (!empty($constants)) {
            $model_metadata['constants'] = $constants;
        }

        return $model_metadata;
    }

    /**
     * The model's DERIVED PROPERTY names - Laravel's $appends.
     *
     * A computed value that must reach JavaScript is declared as $appends plus a
     * getXAttribute() accessor delegating to the public method that defines it; Eloquent
     * serializes it inside parent::toArray(), which Rsx_Model_Abstract::toArray() calls first,
     * so it rides the ordinary fetch() payload. Read from the class's DEFAULT property values -
     * $appends is protected, and the declaration is what the stub documents, not whatever an
     * instance may have been told at runtime.
     *
     * @param string $fqcn Fully qualified model class name.
     * @return string[] Declared appended property names, in declaration order.
     */
    private static function _get_model_appends(string $fqcn): array
    {
        $defaults = (new \ReflectionClass($fqcn))->getDefaultProperties();
        $appends = $defaults['appends'] ?? [];

        if (!is_array($appends)) {
            return [];
        }

        return array_values(array_filter($appends, 'is_string'));
    }

    /**
     * Sanitize model name for use as filename
     */
    private static function _sanitize_model_stub_filename(string $model_name): string
    {
        // Replace underscores with hyphens and lowercase
        // e.g., User_Model becomes user-model
        return strtolower(str_replace('_', '-', $model_name));
    }

    /**
     * Generate JavaScript stub content for a model
     */
    private static function _generate_model_stub_content(string $fqcn, string $class_name, string $stub_class_name, array $manifest_data): string
    {
        // Ensure class is loaded before introspection
        // (should already be loaded but double-check)
        if (!class_exists($fqcn)) {
            shouldnt_happen("Class {$fqcn} not loaded for stub generation");
        }

        // Get model instance to introspect
        $model = new $fqcn();

        // Get relationships that are Ajax-fetchable
        // Only include relationships with BOTH #[Relationship] AND #[Ajax_Endpoint_Model_Fetch]
        $all_relationships = $fqcn::get_relationships();
        $model_metadata = \App\RSpade\Core\Manifest\Manifest::php_get_metadata_by_fqcn($fqcn);
        $fetchable_relationships = [];

        foreach ($all_relationships as $rel_name) {
            $method_data = $model_metadata['public_instance_methods'][$rel_name] ?? [];
            if (isset($method_data['attributes']['Ajax_Endpoint_Model_Fetch'])) {
                $fetchable_relationships[] = $rel_name;
            }
        }
        $relationships = $fetchable_relationships;

        // Get enums
        $enums = $fqcn::$enums ?? [];

        // Get columns from models metadata if available
        $columns = [];
        if (isset($manifest_data['data']['models'][$class_name]['columns'])) {
            $columns = $manifest_data['data']['models'][$class_name]['columns'];
        }

        // Determine the base class to extend
        // User can configure a custom base class that sits between stubs and Rsx_Js_Model
        $js_model_base_class = config('rsx.js_model_base_class');
        $extends_class = $js_model_base_class ?: 'Rsx_Js_Model';

        // Collect enum constant names to avoid duplicating them
        $enum_constant_names = [];
        foreach ($enums as $column => $enum_values) {
            foreach ($enum_values as $value => $props) {
                if (!empty($props['constant'])) {
                    $enum_constant_names[] = $props['constant'];
                }
            }
        }

        // Get all public constants defined directly on this model class (not inherited)
        $reflection = new \ReflectionClass($fqcn);
        $non_enum_constants = [];
        foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $const) {
            // Only include constants defined directly on this class
            if ($const->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }
            $const_name = $const->getName();
            // Skip constants already generated from enums
            if (in_array($const_name, $enum_constant_names)) {
                continue;
            }
            $non_enum_constants[$const_name] = $const->getValue();
        }

        // DERIVED PROPERTIES - the model's $appends. These reach the JS record through
        // toArray() with no column behind them, so nothing else in this generator knows about
        // them: they are absent from $columns, so field_length() answers null for each exactly
        // as it does for any non-varchar, and they get no enum treatment.
        //
        // DECLARED AS @property AND NOT AS A REAL MEMBER, deliberately. The ORM assigns a
        // fetched record's fields onto the instance; a class-level getter would shadow that
        // assignment and a getter-only property would throw on it. A JSDoc @property is the
        // declaration an editor reads for autocomplete and a human reads to learn the property
        // exists, without putting anything in the assignment's way.
        $appends = static::_get_model_appends($fqcn);

        // Start building the stub content
        $content = "/**\n";
        $content .= " * Auto-generated JavaScript stub for {$class_name}\n";
        $content .= " * DO NOT EDIT - This file is automatically regenerated\n";
        foreach ($appends as $append) {
            $content .= " * @property {*} {$append} - derived (appended by the PHP model); read-only\n";
        }
        $content .= " * @Instantiatable\n";
        $content .= " */\n";

        $content .= "class {$stub_class_name} extends {$extends_class} {\n";

        // Add static __MODEL property for PHP model name resolution
        $content .= "    static __MODEL = '{$class_name}';\n";

        // Realtime emission opt-in (mirrors PHP `public static $realtime`). Baked ONLY
        // when true so the JS surface (this.subscribe(Model_Class, id, cb) / watch_changes())
        // can fail loud when a model has not opted into Model_Changed_Topic emission. Absent
        // line == not realtime, keeping non-realtime stubs clean.
        if (property_exists($fqcn, 'realtime') && $fqcn::$realtime === true) {
            $content .= "    static __REALTIME = true;\n";
        }

        $content .= "\n";

        // Generate non-enum constants first (static properties)
        if (!empty($non_enum_constants)) {
            $content .= "    // Non-enum constants\n";
            foreach ($non_enum_constants as $const_name => $const_value) {
                $value_json = json_encode($const_value);
                $content .= "    static {$const_name} = {$value_json};\n";
            }
            $content .= "\n";
        }

        // Generate enum constants and methods
        foreach ($enums as $column => $enum_values) {
            // Sort enum values by order property first, then by key
            uksort($enum_values, function ($keyA, $keyB) use ($enum_values) {
                $orderA = isset($enum_values[$keyA]['order']) ? $enum_values[$keyA]['order'] : 0;
                $orderB = isset($enum_values[$keyB]['order']) ? $enum_values[$keyB]['order'] : 0;

                // First compare by order
                if ($orderA !== $orderB) {
                    return $orderA - $orderB;
                }

                // If order is same, compare by key (use spaceship operator for string comparison)
                return $keyA <=> $keyB;
            });

            // Generate constants
            foreach ($enum_values as $value => $props) {
                if (!empty($props['constant'])) {
                    $value_json = json_encode($value);
                    $content .= "    static {$props['constant']} = {$value_json};\n";
                }
            }
            if (!empty($enum_values)) {
                $content .= "\n";
            }

            // Generate enum getter with Proxy for maintaining order (BEM-style: field__enum)
            $content .= "    /**\n";
            $content .= "     * Get enum metadata for {$column}.\n";
            $content .= "     * @param {number} [enum_value] - If provided, returns metadata for that ID (or null + console.error if invalid)\n";
            $content .= "     * @returns {Object} All enum definitions keyed by ID, or single enum's metadata if enum_value provided\n";
            $content .= "     * @example\n";
            $content .= "     * // Get all: Model.{$column}__enum()\n";
            $content .= "     * // Get one: Model.{$column}__enum(Model.CONSTANT_NAME).property\n";
            $content .= "     */\n";
            $content .= "    static __{$column}__enum = null;\n";
            $content .= "    static {$column}__enum(enum_value) {\n";
            $content .= "        if (!this.__{$column}__enum) {\n";
            $content .= "            const data = {};\n";
            $content .= "            const order = [];\n";

            // Generate the sorted entries
            foreach ($enum_values as $value => $props) {
                $value_json = json_encode($value);
                $props_json = json_encode($props, JSON_UNESCAPED_SLASHES);
                $content .= "            data[{$value_json}] = {$props_json};\n";
                $content .= "            order.push({$value_json});\n";
            }

            $content .= "            // Cache Proxy that maintains sort order for enumeration\n";
            $content .= "            this.__{$column}__enum = new Proxy(data, {\n";
            $content .= "                ownKeys() {\n";
            $content .= "                    return order.map(String);\n";
            $content .= "                },\n";
            $content .= "                getOwnPropertyDescriptor(target, prop) {\n";
            $content .= "                    if (prop in target) {\n";
            $content .= "                        return {\n";
            $content .= "                            enumerable: true,\n";
            $content .= "                            configurable: true,\n";
            $content .= "                            value: target[prop]\n";
            $content .= "                        };\n";
            $content .= "                    }\n";
            $content .= "                }\n";
            $content .= "            });\n";
            $content .= "        }\n";
            $content .= "        if (enum_value !== undefined) {\n";
            $content .= "            const result = this.__{$column}__enum[enum_value];\n";
            $content .= "            if (!result) {\n";
            $content .= "                console.error(`Invalid enum value '\${enum_value}' for {$column}`);\n";
            $content .= "                return null;\n";
            $content .= "            }\n";
            $content .= "            return result;\n";
            $content .= "        }\n";
            $content .= "        return this.__{$column}__enum;\n";
            $content .= "    }\n\n";

            // Generate enum_select() - Selectable items for dropdowns (respects selectable: false)
            // Returns [{value, label}] array — order baked into array index, no Proxy needed
            $content .= "    /**\n";
            $content .= "     * Get selectable options for {$column} dropdowns (excludes selectable:false items).\n";
            $content .= "     * @returns {Array<{value: number, label: string}>} Options sorted by 'order' property\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_select() {\n";
            $content .= "        const fullData = this.{$column}__enum();\n";
            $content .= "        const result = [];\n";
            $content .= "        for (const key of Object.keys(fullData)) {\n";
            $content .= "            const item = fullData[key];\n";
            $content .= "            if (item.selectable !== false && item.label) {\n";
            $content .= "                result.push({value: parseInt(key), label: item.label});\n";
            $content .= "            }\n";
            $content .= "        }\n";
            $content .= "        return result;\n";
            $content .= "    }\n\n";

            // Generate enum_labels() - Simple id => label map (all items, ignores selectable)
            $content .= "    /**\n";
            $content .= "     * Get all {$column} labels (includes non-selectable items).\n";
            $content .= "     * @returns {Object} {id: label} pairs for all enum values\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_labels() {\n";
            $content .= "        const values = {};\n";
            foreach ($enum_values as $value => $props) {
                if (isset($props['label'])) {
                    $value_json = json_encode($value);
                    $label = addslashes($props['label']);
                    $content .= "        values[{$value_json}] = '{$label}';\n";
                }
            }
            $content .= "        return values;\n";
            $content .= "    }\n\n";

            // Generate enum_ids() - Array of all valid enum IDs
            $content .= "    /**\n";
            $content .= "     * Get all valid {$column} IDs.\n";
            $content .= "     * @returns {number[]} Array of all enum IDs\n";
            $content .= "     */\n";
            $content .= "    static {$column}__enum_ids() {\n";
            $content .= "        return [";
            $ids = array_keys($enum_values);
            $content .= implode(', ', array_map('json_encode', $ids));
            $content .= "];\n";
            $content .= "    }\n\n";
        }

        // Generate static get_relationships() method
        $relationships_json = json_encode(array_values($relationships));
        $content .= "    /**\n";
        $content .= "     * Get list of relationship names available on this model\n";
        $content .= "     * @returns {Array} Array of relationship method names\n";
        $content .= "     */\n";
        $content .= "    static get_relationships() {\n";
        $content .= "        return {$relationships_json};\n";
        $content .= "    }\n\n";

        // Generate relationship methods
        foreach ($relationships as $relationship) {
            $content .= "    /**\n";
            $content .= "     * Fetch {$relationship} relationship\n";
            $content .= "     * @returns {Promise} Related model instance(s), null, or empty array\n";
            $content .= "     */\n";
            $content .= "    async {$relationship}() {\n";
            $content .= "        if (!this.id) {\n";
            $content .= "            shouldnt_happen('Cannot fetch relationship without id property');\n";
            $content .= "        }\n";
            $content .= "        return await Orm_Controller.fetch_relationship({\n";
            $content .= "            model: '{$class_name}',\n";
            $content .= "            id: this.id,\n";
            $content .= "            relationship: '{$relationship}'\n";
            $content .= "        });\n";
            $content .= "    }\n\n";
        }

        // Generate Class-Table Inheritance detail accessors (embedded-resolving, NOT a network
        // relationship). Bakes the discriminator + value->accessor map so the accessor can
        // throw wrong-type locally and read the embedded detail from __details.
        if ($fqcn::has_detail_tables()) {
            $discriminator = $fqcn::detail_discriminator_column();
            $value_to_accessor = \App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::value_to_accessor($fqcn::$detail_tables);

            $content .= "    // Class-Table Inheritance detail tables (see man detail_tables)\n";
            $content .= "    static __detail_discriminator = " . json_encode($discriminator) . ";\n";
            $content .= "    static __detail_value_to_accessor = " . json_encode($value_to_accessor, JSON_FORCE_OBJECT) . ";\n\n";

            foreach (array_keys($fqcn::detail_accessors()) as $accessor) {
                $content .= "    /**\n";
                $content .= "     * CTI detail accessor (embedded; resolves with no network call; throws on wrong-type).\n";
                $content .= "     * @returns {Promise<Object|null>} The detail model for this record's type, or null\n";
                $content .= "     */\n";
                $content .= "    async {$accessor}() {\n";
                $content .= "        return this.__resolve_detail(" . json_encode($accessor) . ");\n";
                $content .= "    }\n\n";
            }
        }

        // Generate field_length() method for varchar max lengths
        $varchar_lengths = [];
        foreach (array_keys($columns) as $col_name) {
            // A single leading underscore marks a SYSTEM column. toArray() strips those
            // from every payload, so the client never holds one and can never need its
            // length; publishing it would only advertise a column that is not there.
            if (str_starts_with($col_name, '_') && !str_starts_with($col_name, '__')) {
                continue;
            }

            // The LENGTH itself is the model's answer, not ours - Model::field_length() is the
            // one definition, so a stub can never disagree with what the server enforces.
            $length = $fqcn::field_length($col_name);
            if ($length !== null) {
                $varchar_lengths[$col_name] = $length;
            }
        }

        $content .= "    /**\n";
        $content .= "     * Get max length for a varchar/char column.\n";
        $content .= "     * @param {string} column - Column name\n";
        $content .= "     * @returns {number|null} Max length for varchar/char columns, null for other types\n";
        $content .= "     */\n";
        $content .= "    static field_length(column) {\n";
        $content .= "        const lengths = " . json_encode($varchar_lengths, JSON_FORCE_OBJECT) . ";\n";
        $content .= "        return lengths[column] ?? null;\n";
        $content .= "    }\n\n";

        $content .= "}\n";

        return $content;
    }
}
