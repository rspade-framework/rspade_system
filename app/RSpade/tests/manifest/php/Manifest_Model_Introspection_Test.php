<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Modules\Model_ManifestSupport;
use App\RSpade\Core\Support\Rsx_Fingerprint;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MODEL MODULE DOES NOT TALK TO MYSQL WHEN NOTHING IT DEPENDS ON MOVED.
 *
 * Baseline (2026-09-07): ~110-130 round trips PER REBUILD - `Schema::hasTable` plus
 * `SHOW COLUMNS` for every model, plus one more pair per detail table - on every rebuild,
 * including the ones a developer's editor triggers by saving a stylesheet. A persistent
 * cache was read at the top of the loop and then thrown away, because the code fell through
 * and re-queried anyway.
 *
 * The columns a model reports are a function of the MODEL FILE (its table name, its detail
 * declaration) and of the SCHEMA (the migrations APPLIED to the database), and of nothing
 * else - so that pair is the cache key, and it is checked twice, cheapest first: the row this
 * build carried forward, then the persistent cache. This test is the guarantee, expressed
 * the only way it can be: by counting the queries.
 *
 * AND IT TALKS TO MYSQL WHEN THE SCHEMA DID MOVE. The key is the applied migrations, never
 * the migration files: a file that has arrived is not a migration that has run, and a build
 * made between the two filed the old columns under a key that outlived them.
 *
 * DB::listen is the instrument, and the module is driven directly rather than through a
 * build, because a build in a child process cannot be listened to.
 */
class Manifest_Model_Introspection_Test extends Rsx_Test_Abstract
{
    // The module reads the database when it misses; the test's job is to prove it does not.
    protected static $use_database_transactions = false;

    /**
     * Run one Model_ManifestSupport pass and return every SQL statement it issued.
     *
     * @return array<int,string>
     */
    private static function __queries_for_pass(array &$data): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        Model_ManifestSupport::process($data, [], []);

        // Laravel has no unlisten; the closure simply stops being fed once this returns
        // because nothing else in the test issues a statement it cares about.
        return $queries;
    }

    /**
     * Two consecutive passes over an unchanged tree issue no schema introspection at all.
     */
    public static function test_consecutive_passes_issue_no_schema_queries()
    {
        $data = Manifest::get_full_manifest();

        static::__assert_true(
            !empty($data['data']['models']),
            'the manifest carries a model registry to start from'
        );

        $first = static::__queries_for_pass($data);
        $second = static::__queries_for_pass($data);

        foreach (['first' => $first, 'second' => $second] as $label => $queries) {
            // SHOW COLUMNS is the whole measurement. A `Schema::hasTable` probe survives for
            // a model whose table does NOT exist, and deliberately so: a "table missing"
            // verdict is never cached, so the model is asked about again on every build
            // until its table arrives - one cheap probe per unmigrated model. In a migrated
            // tree there are none.
            $introspection = array_values(array_filter(
                $queries,
                fn ($sql) => stripos($sql, 'show columns') !== false
            ));

            static::__assert_equals(
                [],
                $introspection,
                "the {$label} pass issued no schema introspection:\n  " . implode("\n  ", $introspection)
            );
        }
    }

    /**
     * The carried-forward row is used WITHOUT a cache round trip, and the registry that comes
     * out is the one that went in.
     */
    public static function test_the_registry_survives_a_pass_unchanged()
    {
        $data = Manifest::get_full_manifest();
        $before = $data['data']['models'];

        Model_ManifestSupport::process($data, [], []);

        static::__assert_equals(
            json_encode($before),
            json_encode($data['data']['models']),
            'a pass over an unchanged tree leaves the model registry exactly as it found it'
        );
    }

    /**
     * Every row carries the fingerprint the skip is decided on. A row without one would be
     * re-introspected on every build for ever, silently.
     */
    public static function test_every_model_row_carries_its_fingerprint()
    {
        $manifest = Manifest::get_full_manifest();

        foreach ($manifest['data']['models'] as $class => $row) {
            static::__assert_true(
                !empty($row['fingerprint']),
                "the {$class} row records the model-file + schema fingerprint it was built for"
            );
        }
    }

    /**
     * A migration applied AFTER a build is seen by the next one: the applied set moved, so
     * the carried-forward row is not trusted and the model's columns are read again.
     *
     * The migration is performed for real - a column added to a framework table and a row
     * in the migrations table, both removed afterwards - because the defect this guards was
     * exactly a real column the build went on not knowing about.
     */
    public static function test_a_migration_applied_after_the_build_is_seen_by_the_next()
    {
        $table = '_email_blocked_addresses';
        $class = 'Email_Blocked_Address_Model';
        $column = 'zz_schema_probe';
        $migrations_table = config('database.migrations', 'migrations');
        $migration = '9999_12_31_235959_manifest_model_introspection_probe';

        $data = Manifest::get_full_manifest();

        static::__assert_true(isset($data['data']['models'][$class]), "{$class} is in the model registry");
        static::__assert_false(
            isset($data['data']['models'][$class]['columns'][$column]),
            'the probe column does not exist yet'
        );

        $recorded_before = $data['data']['applied_migrations'];

        try {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` BIGINT NULL");
            DB::table($migrations_table)->insert(['migration' => $migration, 'batch' => 999999]);

            Model_ManifestSupport::process($data, [], []);

            static::__assert_true(
                isset($data['data']['models'][$class]['columns'][$column]),
                'the column the migration added is in the rebuilt column map'
            );
            static::__assert_not_equals(
                $recorded_before,
                $data['data']['applied_migrations'],
                'the build records the applied set it was rebuilt against'
            );
            static::__assert_equals(Rsx_Fingerprint::applied_migrations(), $data['data']['applied_migrations']);
        } finally {
            DB::table($migrations_table)->where('migration', $migration)->delete();
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }
}
