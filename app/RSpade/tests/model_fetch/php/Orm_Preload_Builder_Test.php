<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use App\RSpade\Core\Database\Orm_Fetch_Preload;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RestrictedEloquentBuilder::find() against the ORM batch preload.
 *
 * The whole safety argument for the preload is that it can only ever answer a query that
 * would have returned that exact row anyway. Every guard below is one way a find() can
 * mean something ELSE - a narrowed query, a widened one, a projected one - and each must
 * MISS the cache and run the real query. A false hit here would silently hand a caller a
 * record its query said it should not get, so these are the tests that matter most.
 *
 * The preload is populated directly (not through Orm_Controller) so each case isolates
 * the builder guard it is about.
 */
class Orm_Preload_Builder_Test extends Rsx_Test_Abstract
{
    /**
     * The hit: a pristine default-scoped find() returns the preloaded INSTANCE ITSELF
     * (identity, not an equal copy - that is what proves no query ran).
     */
    public static function test_pristine_find_is_served_from_the_preload()
    {
        $row = static::__preload_one_task();

        static::__assert_true($row === Task_Model::find($row->id), 'expected the preloaded instance');

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    /**
     * A CONSTRAINED find() misses: the extra where narrows the result, and the preload
     * knows nothing about it.
     */
    public static function test_constrained_find_misses_the_preload()
    {
        $row = static::__preload_one_task();

        $found = Task_Model::where('id', $row->id)->find($row->id);

        static::__assert_not_null($found);
        static::__assert_false($row === $found, 'a constrained find must run the real query');

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    /**
     * A SCOPE-STRIPPED find() misses. withTrashed() widens the result past the
     * default-scoped set the preload was built from - this is the guard that makes a
     * model whose fetch() body reaches for trashed rows degrade to unbatched instead of
     * getting a wrong answer.
     */
    public static function test_scope_stripped_find_misses_the_preload()
    {
        $row = static::__preload_one_task();

        $found = Task_Model::withTrashed()->find($row->id);

        static::__assert_not_null($found);
        static::__assert_false($row === $found, 'a withTrashed find must run the real query');

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    /**
     * A PROJECTED find() misses: it must produce a partially-hydrated model, and the
     * preloaded instance carries every column.
     */
    public static function test_column_projected_find_misses_the_preload()
    {
        $row = static::__preload_one_task();

        $found = Task_Model::find($row->id, ['id']);

        static::__assert_not_null($found);
        static::__assert_false($row === $found, 'a projected find must run the real query');

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    /**
     * After clear() there is nothing to serve - the state does not survive the endpoint
     * invocation that built it.
     */
    public static function test_cleared_preload_serves_nothing()
    {
        $row = static::__preload_one_task();

        Orm_Fetch_Preload::clear();

        static::__assert_null(Orm_Fetch_Preload::get(get_class($row), $row->id));
        static::__assert_false($row === Task_Model::find($row->id));

        static::__reset_session();
    }

    /**
     * Ids are normalized to int on the way in and on the way out, so a numeric-string id
     * finds the row a numeric id stored. A non-numeric id is simply not a key.
     */
    public static function test_get_normalizes_the_id()
    {
        $row = static::__preload_one_task();

        static::__assert_true($row === Orm_Fetch_Preload::get(get_class($row), (string) $row->id));
        static::__assert_true($row === Orm_Fetch_Preload::get(get_class($row), (int) $row->id));
        static::__assert_null(Orm_Fetch_Preload::get(get_class($row), 'not-an-id'));

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    /**
     * The cache is keyed by model class: a preload for one model never answers for
     * another, even for the same id.
     */
    public static function test_preload_is_keyed_by_model_class()
    {
        $row = static::__preload_one_task();

        static::__assert_null(Orm_Fetch_Preload::get(get_class(new Client_Model()), $row->id));

        Orm_Fetch_Preload::clear();
        static::__reset_session();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * The tenant these tests act as. The fixture row is created per test and rolled back
     * with the test's transaction.
     */
    private const SITE_ID = 1;

    /**
     * Create one task, load it the way the endpoint does (one whereIn under default
     * scopes), and put it in the preload. Returns the preloaded instance.
     */
    private static function __preload_one_task()
    {
        static::__acting_as_site(self::SITE_ID);

        $task = new Task_Model();
        $task->site_id = self::SITE_ID;
        $task->title = 'Model fetch preload fixture';
        $task->save();

        $rows = Task_Model::whereIn('id', [(int) $task->id])->get();

        // Keyed by the row's REAL class - the same string RestrictedEloquentBuilder::find()
        // derives with get_class($this->getModel()). Template-app models are not `use`d
        // here (their namespace is manifest-generated and a hardcoded \Rsx\ FQCN is
        // forbidden), so a `Task_Model::class` constant in this namespace would silently
        // name a class that does not exist. Static CALLS resolve by simple name through
        // the autoloader and are fine.
        Orm_Fetch_Preload::populate(get_class($rows->first()), $rows);

        return $rows->first();
    }
}
