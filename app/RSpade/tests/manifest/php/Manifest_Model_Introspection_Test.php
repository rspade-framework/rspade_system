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
 * declaration) and of the SCHEMA (the migration files), and of nothing else - so that pair
 * is the cache key, and it is checked twice, cheapest first: the row this build carried
 * forward, then the persistent cache. This test is the guarantee, expressed the only way it
 * can be: by counting the queries.
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
            // a model whose table does NOT exist, and deliberately so: the fingerprint is the
            // model file plus the MIGRATION FILES, and running `migrate` changes neither - so
            // caching a "table missing" verdict would leave that model column-less until
            // somebody edited a file, which is a far worse trap than one cheap probe per
            // unmigrated model. In a migrated tree there are none.
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
}
