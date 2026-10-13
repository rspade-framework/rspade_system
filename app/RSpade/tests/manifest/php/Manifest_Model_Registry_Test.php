<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Modules\Model_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Manifest\Php\Registry_Absent_Table_Fixture_Model;

/**
 * THE MODEL REGISTRY HOLDS CODE FACTS ONLY.
 *
 * `data.models` says which models the tree declares and which table each one names. It
 * says nothing the database would have to be asked: no columns, no record of which
 * migrations were applied, and no opinion on whether a table exists. Two things follow, and
 * they are what this class asserts:
 *
 *   - building it issues NO statement of any kind, so a build needs no database;
 *   - the code key computed over the index cannot vary with the schema, so a migration
 *     leaves the build - and its seal - exactly as it was.
 *
 * DB::listen is the instrument, and the module is driven directly rather than through a
 * build, because a build in a child process cannot be listened to.
 */
class Manifest_Model_Registry_Test extends Rsx_Test_Abstract
{
    // Nothing here writes; the point is that the module does not even read.
    protected static $use_database_transactions = false;

    public static function test_building_the_registry_issues_no_statement_at_all()
    {
        $data = Manifest::get_full_manifest();

        $queries = [];
        $listening = true;

        // Laravel has no unlisten; the flag is what stops the closure collecting.
        DB::listen(function ($query) use (&$queries, &$listening) {
            if ($listening) {
                $queries[] = $query->sql;
            }
        });

        try {
            Model_ManifestSupport::rebuild($data);
        } finally {
            $listening = false;
        }

        static::__assert_not_empty($data['data']['models'], 'the pass produced a registry');
        static::__assert_equals(
            [],
            $queries,
            "the model module asked the database nothing:\n  " . implode("\n  ", $queries)
        );
    }

    public static function test_a_pass_reproduces_the_registry_the_index_carries()
    {
        $data = Manifest::get_full_manifest();
        $before = $data['data']['models'];

        // Derived whole from the class map: the section it is handed plays no part.
        $data['data']['models'] = ['Stale_Row' => ['table' => 'nothing']];
        Model_ManifestSupport::rebuild($data);

        static::__assert_equals(
            json_encode($before),
            json_encode($data['data']['models']),
            'the registry is a pure function of the indexed classes'
        );
    }

    public static function test_a_row_is_the_class_its_file_and_its_declared_table()
    {
        $models = Manifest::get_full_manifest()['data']['models'];

        static::__assert_not_empty($models);

        foreach ($models as $class => $row) {
            static::__assert_equals(
                ['fqcn', 'file', 'table', 'class'],
                array_keys($row),
                "the {$class} row carries exactly the code facts - no columns, no fingerprint"
            );
            static::__assert_equals($class, $row['class']);
            static::__assert_equals($row['fqcn']::get_table_static(), $row['table'], "{$class}'s table is its own declaration");
        }

        static::__assert_false(
            array_key_exists('applied_migrations', Manifest::get_full_manifest()['data']),
            'the index records nothing about which migrations a database has applied'
        );
    }

    /**
     * A model is a model whether or not its table exists: the registry, the table index and
     * is_php_model_class() all answer for one whose table is in no database.
     */
    public static function test_a_model_whose_table_does_not_exist_is_registered()
    {
        $class = 'Registry_Absent_Table_Fixture_Model';
        $table = Registry_Absent_Table_Fixture_Model::get_table_static();

        static::__assert_false(Rsx_Schema::has_table($table), "sanity: {$table} is in no database");

        $record = Manifest::php_model_record($class);

        static::__assert_not_null($record, 'the model is in the registry');
        static::__assert_equals($table, $record['table']);
        static::__assert_true(Manifest::is_php_model_class($class));
        static::__assert_equals($class, Manifest::model_for_table($table), 'and models_by_table maps its table to it');
    }
}
