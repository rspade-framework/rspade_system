<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE REFERENCE RULE, MADE PERMANENT.
 *
 * An index POINTS AT a record; it does not contain one. The manifest broke that rule in
 * several places at once and the index paid for it: `models` carried a verbatim copy of each
 * model's method map (167 KB), `routes_by_target` was a byte-identical regroup of `routes`
 * (80 KB), every gate list was stored three times, and every file record repeated its own
 * path as a value.
 *
 * These tests are that rule expressed as an assertion, plus the hot/cold contract the split
 * index rests on:
 *
 *   - no top-level index holds an array that also appears VERBATIM inside a `files` record;
 *   - the hot index carries no method map outside the model / task-service / stub subset;
 *   - the derived-at-load indexes are not persisted at all.
 *
 * They read the index the developer's own build wrote, because that is the artifact that
 * ships - the fixture builds in Manifest_Fixture_Build_Test cover the build MECHANICS.
 */
class Index_Reference_Rule_Test extends Rsx_Test_Abstract
{
    // The subject is a file on disk; nothing here touches the database.
    protected static $use_database_transactions = false;

    /**
     * Serialized values shorter than this are not evidence of anything: `['GET']`,
     * `['public']` and `[1]` recur all over a manifest by coincidence of vocabulary, not by
     * duplication. The rule is about RECORDS.
     */
    private const SIGNIFICANT_BYTES = 200;

    /**
     * The hot index, as written by the last build.
     */
    private static function __hot(): array
    {
        $path = storage_path(Manifest::CACHE_FILE);

        static::__assert_true(file_exists($path), 'the hot index exists at ' . $path);

        return include $path;
    }

    /**
     * The cold half, as written by the last build.
     */
    private static function __cold(): array
    {
        $path = storage_path(Manifest::COLD_FILE);

        static::__assert_true(file_exists($path), 'the cold index exists at ' . $path);

        return include $path;
    }

    /**
     * Every significant array value that appears inside a `files` record, as a set of
     * serialized strings.
     *
     * @param array $files
     * @return array<string,string> serialized value => the path it came from
     */
    private static function __record_values(array $files): array
    {
        $values = [];

        foreach ($files as $path => $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            foreach ($metadata as $value) {
                if (!is_array($value) || $value === []) {
                    continue;
                }

                $serialized = serialize($value);

                if (strlen($serialized) >= self::SIGNIFICANT_BYTES) {
                    $values[$serialized] = $path;
                }
            }
        }

        return $values;
    }

    /**
     * NO INDEX CONTAINS A RECORD THAT ALSO LIVES UNDER `files`.
     */
    public static function test_no_top_level_index_duplicates_a_file_record()
    {
        $hot = static::__hot();
        $files = $hot['data']['files'] + static::__cold();
        $record_values = static::__record_values($files);

        $offenders = [];

        foreach ($hot['data'] as $section => $contents) {
            if ($section === 'files' || !is_array($contents)) {
                continue;
            }

            foreach ($contents as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }

                foreach ($row as $field => $value) {
                    if (!is_array($value) || $value === []) {
                        continue;
                    }

                    $serialized = serialize($value);

                    if (isset($record_values[$serialized])) {
                        $offenders[] = "{$section}[{$key}][{$field}] repeats "
                            . strlen($serialized) . ' bytes of ' . $record_values[$serialized];
                    }
                }
            }
        }

        static::__assert_equals(
            [],
            $offenders,
            "an index must POINT AT a file record, never contain one:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * THE HOT INDEX CARRIES NO METHOD MAP IT IS NOT ASKED FOR.
     *
     * Models and their ancestors (Orm_Controller, get_relationships()), task services (Task,
     * Task_Concurrency) and the generated stubs are the whole permitted set: each is read at
     * request time. Anything else with a method map belongs in the cold half.
     */
    public static function test_hot_index_holds_method_maps_only_for_models_tasks_and_stubs()
    {
        $hot = static::__hot();
        $body = $hot['data'];

        $permitted = [];

        $model_classes = $body['php_subclass_index']['Rsx_Model_Abstract'] ?? [];
        $model_classes[] = 'Rsx_Model_Abstract';

        foreach ($model_classes as $class) {
            $file = $body['php_classes'][$class]['file'] ?? null;

            if ($file !== null) {
                $permitted[$file] = 'model lineage';
            }
        }

        foreach (['Task', 'Schedule', 'Command', 'Exclusive', 'Debounce'] as $attribute) {
            foreach ($body['attribute_index'][$attribute] ?? [] as $row) {
                $permitted[$row['file']] = 'task service';
            }
        }

        $offenders = [];

        foreach ($body['files'] as $path => $metadata) {
            $has_methods = isset($metadata['public_static_methods'])
                || isset($metadata['public_instance_methods'])
                || isset($metadata['methods']);

            if (!$has_methods) {
                continue;
            }

            if (isset($permitted[$path])
                || !empty($metadata['is_stub'])
                || !empty($metadata['is_model_stub'])) {
                continue;
            }

            $offenders[] = $path;
        }

        static::__assert_equals(
            [],
            $offenders,
            "these method maps are in the HOT index and nothing on the request path reads them:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * A file record does not repeat its own key as a value.
     */
    public static function test_file_records_do_not_repeat_their_path()
    {
        $files = static::__hot()['data']['files'] + static::__cold();
        $offenders = [];

        foreach ($files as $path => $metadata) {
            if (is_array($metadata) && isset($metadata['file'])) {
                $offenders[] = $path;
            }
        }

        static::__assert_equals(
            [],
            array_slice($offenders, 0, 10),
            'the path is the KEY; a record that repeats it as a value is 3% of the files map'
        );
    }

    /**
     * routes_by_target is DERIVED at load, never persisted.
     */
    public static function test_by_target_indexes_are_not_persisted()
    {
        $body = static::__hot()['data'];

        static::__assert_false(
            isset($body['routes_by_target']),
            'routes_by_target is a regroup of routes and is rebuilt at load'
        );

        static::__assert_false(
            isset($body['portal_routes_by_target']),
            'portal_routes_by_target is a regroup of portal_routes and is rebuilt at load'
        );

        // ...and the load-time derivation actually happened in THIS process.
        static::__assert_not_empty(
            Manifest::get_full_manifest()['data']['routes_by_target'] ?? [],
            'the loaded manifest carries the derived routes_by_target'
        );
    }

    /**
     * A route row names its SURFACE instead of carrying its own copy of the gate list.
     */
    public static function test_route_rows_reference_their_auth_surface()
    {
        $body = static::__hot()['data'];
        $surfaces = $body['auth']['surfaces'] ?? [];

        static::__assert_not_empty($surfaces, 'the surface index is built');

        $without_surface = [];
        $with_own_auth = [];

        foreach (['routes', 'portal_routes'] as $section) {
            foreach ($body[$section] ?? [] as $pattern => $row) {
                if (isset($row['auth'])) {
                    $with_own_auth[] = $section . ' ' . $pattern;
                }

                if (empty($row['surface']) || !isset($surfaces[$row['surface']])) {
                    $without_surface[] = $section . ' ' . $pattern
                        . ' -> ' . ($row['surface'] ?? '(none)');
                }
            }
        }

        static::__assert_equals([], $with_own_auth, 'no route row carries its own gate list');
        static::__assert_equals([], $without_surface, 'every route row names a surface that exists');
    }

    /**
     * The new indexes exist and answer through the accessors.
     */
    public static function test_new_indexes_answer_through_their_accessors()
    {
        $body = static::__hot()['data'];

        foreach (['blade_views', 'attribute_index', 'models_by_table', 'file_index'] as $section) {
            static::__assert_not_empty($body[$section] ?? [], "the {$section} index is built");
        }

        // file_index spans the WHOLE tree - that is what makes the staleness sweep possible
        // without loading the cold half.
        static::__assert_true(
            count($body['file_index']) > count($body['files']),
            'file_index covers every indexed file, not only the hot ones'
        );

        $view_id = array_key_first($body['blade_views']);
        static::__assert_equals(
            $body['blade_views'][$view_id],
            Manifest::find_view($view_id),
            'find_view() answers from blade_views'
        );
        static::__assert_true(Manifest::view_exists($view_id), 'view_exists() is the non-throwing half');

        $table = array_key_first($body['models_by_table']);
        static::__assert_equals(
            $body['models_by_table'][$table],
            Manifest::model_for_table($table),
            'model_for_table() answers from models_by_table'
        );

        static::__assert_not_empty(
            Manifest::by_attribute('Ajax_Endpoint'),
            'by_attribute() answers from attribute_index'
        );

        $record = Manifest::php_class_metadata('Manifest');
        static::__assert_equals(
            'App\RSpade\Core\Manifest\Manifest',
            $record['fqcn'] ?? null,
            'php_class_metadata() returns the hot class record'
        );
        static::__assert_false($record['abstract'], 'the hot class record carries abstractness');
    }
}
