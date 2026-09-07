<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Result_Set;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Rsx_Result_Set - the foreach-able handle over a whole result set.
 *
 * The properties that matter: a plain foreach reaches EVERY record no matter how
 * many pages it takes; count() asks SQL rather than walking; and the collection
 * methods stay lazy. Chunk sizes here are deliberately tiny (1-2) so the paging
 * actually runs several times against a handful of rows.
 *
 * Site_Model is the fixture: it is a framework-core table with no side-effect
 * surface, so inserts here are cheap and fire nothing.
 */
class Rsx_Result_Set_Test extends Rsx_Test_Abstract
{
    private const ROW_COUNT = 7;

    private static array $_ids = [];

    public static function setup()
    {
        static::$_ids = [];

        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $site = new Site_Model();
            $site->slug = 'result-set-fixture-' . $i;
            $site->name = 'result-set-fixture-' . $i;
            $site->timezone = 'UTC';
            $site->save();

            static::$_ids[] = (int) $site->id;
        }
    }

    public static function teardown()
    {
        // forceDelete: Site_Model soft-deletes, and a fixture must leave nothing behind.
        Site_Model::whereIn('id', static::$_ids)->forceDelete();
        static::$_ids = [];
    }

    private static function __result_set(int $chunk_size = 2): Rsx_Result_Set
    {
        return Site_Model::whereIn('id', static::$_ids)->result_set($chunk_size);
    }

    // =====================================================================
    // The core promise: foreach reaches everything, across pages
    // =====================================================================

    public static function test_foreach_walks_every_record_across_pages()
    {
        // chunk_size 2 over 7 rows = 4 pages. Every row must still arrive, exactly once.
        $seen = [];
        foreach (static::__result_set(2) as $site) {
            $seen[] = (int) $site->id;
        }

        static::__assert_count(self::ROW_COUNT, $seen, 'every record arrived');
        static::__assert_count(self::ROW_COUNT, array_unique($seen), 'no record arrived twice');
    }

    public static function test_a_chunk_size_of_one_still_returns_everything()
    {
        $count = 0;
        foreach (static::__result_set(1) as $site) {
            $count++;
        }

        static::__assert_equals(self::ROW_COUNT, $count, 'one row per page still walks the whole set');
    }

    public static function test_the_set_is_re_iterable()
    {
        // getIterator() clones the query, so a second foreach is a fresh walk rather
        // than an exhausted generator.
        $set = static::__result_set(2);

        $first = 0;
        foreach ($set as $site) {
            $first++;
        }

        $second = 0;
        foreach ($set as $site) {
            $second++;
        }

        static::__assert_equals($first, $second, 'iterating twice yields the same set');
        static::__assert_equals(self::ROW_COUNT, $second);
    }

    // =====================================================================
    // count() must be SQL, not a walk
    // =====================================================================

    public static function test_count_matches_the_row_count()
    {
        static::__assert_equals(self::ROW_COUNT, static::__result_set(2)->count());
    }

    public static function test_count_issues_one_aggregate_query_not_a_walk()
    {
        // The whole point: counting must not page through the table. With chunk_size 1
        // a walk would cost 8 queries; a COUNT costs exactly one.
        DB::enableQueryLog();
        DB::flushQueryLog();

        static::__result_set(1)->count();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        static::__assert_count(1, $queries, 'count() is a single aggregate query');
        static::__assert_contains('count(*)', strtolower($queries[0]['query']), 'and it is a COUNT');
    }

    // =====================================================================
    // Single-row accessors
    // =====================================================================

    public static function test_first_returns_a_record()
    {
        $first = static::__result_set(2)->first();

        static::__assert_not_null($first);
        static::__assert_true(in_array((int) $first->id, static::$_ids, true));
    }

    public static function test_is_empty_is_false_for_a_populated_set()
    {
        static::__assert_false(static::__result_set(2)->is_empty());
    }

    public static function test_is_empty_is_true_for_a_matchless_query()
    {
        $empty = Site_Model::where('id', -1)->result_set();

        static::__assert_true($empty->is_empty());
        static::__assert_equals(0, $empty->count());
        static::__assert_null($empty->first());
    }

    public static function test_empty_set_foreach_runs_zero_times()
    {
        $count = 0;
        foreach (Site_Model::where('id', -1)->result_set() as $site) {
            $count++;
        }

        static::__assert_equals(0, $count);
    }

    // =====================================================================
    // Materializing + forwarded collection methods
    // =====================================================================

    public static function test_all_materializes_the_whole_set()
    {
        static::__assert_count(self::ROW_COUNT, static::__result_set(2)->all());
    }

    public static function test_forwarded_collection_methods_work_and_stay_lazy()
    {
        // map() forwards to the LazyCollection; take(2) must short-circuit the walk
        // rather than mapping all 7 first.
        $ids = static::__result_set(1)->map(fn ($site) => (int) $site->id)->take(2)->all();

        static::__assert_count(2, $ids, 'take() short-circuits the lazy walk');
        static::__assert_true(in_array($ids[0], static::$_ids, true));
    }

    public static function test_query_returns_a_usable_builder_escape_hatch()
    {
        $builder = static::__result_set(2)->query();

        static::__assert_equals(self::ROW_COUNT, $builder->count());
    }

    // =====================================================================
    // Guards
    // =====================================================================

    public static function test_a_non_positive_chunk_size_fails_loud()
    {
        static::__assert_throws(
            \Exception::class,
            function () {
                new Rsx_Result_Set(Site_Model::query(), 0);
            }
        );
    }
}
