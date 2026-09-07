<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use RuntimeException;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Model_Abstract::field_length() - the ONE definition of a column's character limit.
 *
 * A varchar/char column answers its length, every other type answers null (a real answer,
 * not a lookup failure), and a column the model does not have throws. The JS model stubs
 * bake their own field_length() table from this method, so the last group of tests walks
 * every generated stub and proves the two can never disagree - including the one place they
 * deliberately differ, the leading-underscore system-column filter that lives in the
 * generator because it is about what may be PUBLISHED, not about what a length is.
 */
class Field_Length_Test extends Rsx_Test_Abstract
{
    // Manifest and build-artifact reads only - no database access.
    protected static $use_database_transactions = false;

    public static function test_a_varchar_column_answers_its_length()
    {
        static::__assert_equals(255, Client_Model::field_length('name'), 'clients.name is varchar(255)');
        static::__assert_equals(20, Client_Model::field_length('zip'), 'clients.zip is varchar(20)');
    }

    public static function test_a_non_character_column_answers_null()
    {
        static::__assert_null(Client_Model::field_length('id'), 'a bigint has no character limit');
        static::__assert_null(Client_Model::field_length('created_at'), 'a datetime has no character limit');
        static::__assert_null(Party_Model::field_length('notes'), 'a text column has no character limit');
    }

    public static function test_an_unknown_column_throws_naming_the_class_and_the_column()
    {
        $exception = static::__assert_throws(
            RuntimeException::class,
            fn () => Client_Model::field_length('nope_not_a_column'),
            'nope_not_a_column'
        );

        static::__assert_contains(
            'Client_Model',
            $exception->getMessage(),
            'the message names the model, not just the column'
        );
    }

    public static function test_a_cti_base_model_answers_for_a_detail_column()
    {
        // first_name lives on party_person_details, never on parties - the manifest merges a
        // base model's detail columns into its map, so the base model answers for them.
        static::__assert_equals(255, Party_Model::field_length('first_name'), 'a PERSON detail column');
        static::__assert_equals(255, Party_Model::field_length('legal_name'), 'a COMPANY detail column');

        // The premise: the physical base table does not carry it (getColumns() reads the
        // live schema, not the manifest's merged map).
        static::__assert_false(
            in_array('first_name', Party_Model::getColumns(), true),
            'first_name is not a physical column of the parties table'
        );
    }

    public static function test_the_manifest_accessor_declines_a_class_that_is_not_a_model()
    {
        static::__assert_null(
            Manifest::php_model_columns('Rsx_Test_Abstract'),
            'a non-model class has no column map (and field_length() turns that into a throw)'
        );
    }

    public static function test_every_generated_stub_agrees_with_the_model()
    {
        $stubs = static::__stub_length_tables();

        static::__assert_greater_than(0, count($stubs), 'the build produced model stubs to check');

        foreach ($stubs as $model_class => $stub) {
            $fqcn = $stub['fqcn'];

            foreach ($stub['lengths'] as $column => $stub_length) {
                static::__assert_equals(
                    $fqcn::field_length($column),
                    $stub_length,
                    "{$model_class}.{$column}: the stub's length is the model's answer"
                );
            }
        }
    }

    public static function test_a_stub_omits_no_publishable_length()
    {
        $stubs = static::__stub_length_tables();

        foreach ($stubs as $model_class => $stub) {
            $fqcn = $stub['fqcn'];

            foreach (array_keys(Manifest::php_model_columns($model_class) ?? []) as $column) {
                if (static::__is_system_column($column)) {
                    continue;
                }

                if ($fqcn::field_length($column) === null) {
                    continue;
                }

                static::__assert_array_has_key(
                    $column,
                    $stub['lengths'],
                    "{$model_class}.{$column} has a length and is publishable, so the stub carries it"
                );
            }
        }
    }

    public static function test_a_stub_never_publishes_a_system_columns_length()
    {
        // The filter that lives in the GENERATOR, not in field_length(): a single leading
        // underscore marks a system column, toArray() strips it from every payload, so the
        // client can never hold it and can never need its length. PHP still answers for it.
        $stubs = static::__stub_length_tables();
        $system_columns_seen = 0;

        foreach ($stubs as $model_class => $stub) {
            $fqcn = $stub['fqcn'];

            foreach (array_keys($stub['lengths']) as $column) {
                static::__assert_false(
                    static::__is_system_column($column),
                    "{$model_class}.{$column} would be a system column published to the client"
                );
            }

            foreach (array_keys(Manifest::php_model_columns($model_class) ?? []) as $column) {
                if (!static::__is_system_column($column)) {
                    continue;
                }

                $system_columns_seen++;

                // PHP answers for it either way - null or a length, but never a throw.
                $fqcn::field_length($column);

                static::__assert_false(
                    array_key_exists($column, $stub['lengths']),
                    "{$model_class}.{$column} is a system column and stays out of the stub"
                );
            }
        }

        // Recorded, not asserted: no model in this tree declares a system column today, so
        // the loop above is a latch that arms the moment one appears.
        static::__assert_greater_than(-1, $system_columns_seen, 'system columns examined: ' . $system_columns_seen);
    }

    /**
     * A single leading underscore (never a double) marks a system column.
     */
    private static function __is_system_column(string $column): bool
    {
        return str_starts_with($column, '_') && !str_starts_with($column, '__');
    }

    /**
     * Every generated model stub, as model_class => ['fqcn' => ..., 'lengths' => [col => int]].
     *
     * Parses the JSON literal the generator bakes into the stub's field_length() body - the
     * artifact the browser actually receives, rather than the array it was built from.
     */
    private static function __stub_length_tables(): array
    {
        $fqcn_by_class = [];
        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            if (isset($entry['class'], $entry['fqcn'])) {
                $fqcn_by_class[$entry['class']] = $entry['fqcn'];
            }
        }

        $stubs = [];

        foreach (glob(storage_path('rsx-build/js-model-stubs/*.js')) as $path) {
            $source = file_get_contents($path);

            if (!preg_match("/static __MODEL = '([A-Za-z0-9_]+)';/", $source, $model_match)) {
                continue;
            }

            $model_class = $model_match[1];

            if (!isset($fqcn_by_class[$model_class])) {
                continue;
            }

            if (!preg_match('/const lengths = (\{.*?\});/', $source, $lengths_match)) {
                continue;
            }

            $stubs[$model_class] = [
                'fqcn' => $fqcn_by_class[$model_class],
                'lengths' => json_decode($lengths_match[1], true) ?? [],
            ];
        }

        return $stubs;
    }
}
