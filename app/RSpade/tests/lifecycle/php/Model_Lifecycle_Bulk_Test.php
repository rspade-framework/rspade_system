<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Registry;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Bulk_Fixture_Model;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Bulk_Plain_Fixture_Model;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Bulk_Soft_Fixture_Model;

/**
 * Interim bulk-write behavior: Model::where(...)->update()/->delete() = FETCH-THEN-ITERATE.
 *
 * A user bulk write on a model with a side-effect surface (a realtime surface OR an overridden
 * after_* hook) loads the affected rows and runs each through its own $model->save()/->delete(),
 * so the after_update/after_delete hooks (and, for a $realtime model, its realtime frame) fire
 * PER RECORD via the single-write path. There is no bulk-capture, no async Task, no row cap.
 *
 * The load-bearing piece is the re-entrancy guard: a model's OWN single-row write already routes
 * through RestrictedEloquentBuilder::update()/delete() (Eloquent's performUpdate /
 * performDeleteOnModel), so without the guard "load rows and call save() on each" would recurse.
 * Rsx_Model_Abstract bumps a depth counter around parent::save()/parent::delete(); the builder
 * runs the RAW statement while depth > 0 and only fetch-then-iterates when depth == 0.
 *
 * Covers: bulk update fires after_update per row with the changed fields; bulk delete fires
 * after_delete per row (the hydrated instance's attributes reach the hook); a SoftDeletes bulk
 * delete fires after_delete once and NEVER after_update for the internal deleted_at write;
 * ->raw_bulk() does the raw statement and fires nothing; a raw Expression update routes to raw;
 * a rolled-back bulk fires nothing; a plain (no-surface) model does one raw statement (no
 * fetch-then-iterate); and a single save()/delete() fires exactly once (no recursion / double).
 *
 * The class opts OUT of the per-test transaction (afterCommit only flushes on a real commit) and
 * uses a fresh migrated DB so its own commits do not leak; each test resets rows + recorders +
 * the emission buffer first (statics are process-global).
 */
class Model_Lifecycle_Bulk_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE lifecycle_bulk_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            parent_id BIGINT NULL,
            name VARCHAR(255) NULL,
            counter BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE lifecycle_bulk_soft_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            parent_id BIGINT NULL,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_by_id BIGINT DEFAULT NULL,
            deleted_by_type BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function teardown()
    {
        static::__drop_tables();
        Model_Lifecycle_Emissions::_testing_reset();
        Model_Lifecycle_Registry::_testing_reset();
    }

    private static function __drop_tables()
    {
        DB::statement('DROP TABLE IF EXISTS lifecycle_bulk_fixtures');
        DB::statement('DROP TABLE IF EXISTS lifecycle_bulk_soft_fixtures');
    }

    /**
     * Clear committed rows + recorders + buffer before each test (no per-test transaction, so
     * committed state would otherwise leak across methods).
     */
    private static function __reset()
    {
        DB::statement('DELETE FROM lifecycle_bulk_fixtures');
        DB::statement('DELETE FROM lifecycle_bulk_soft_fixtures');
        Model_Lifecycle_Emissions::_testing_reset();
        Lifecycle_Bulk_Fixture_Model::_reset();
        Lifecycle_Bulk_Soft_Fixture_Model::_reset();
    }

    private static function __insert(int $parent_id, string $name): int
    {
        $row = new Lifecycle_Bulk_Fixture_Model();
        $row->site_id = 1;
        $row->parent_id = $parent_id;
        $row->name = $name;
        $row->save();

        return (int) $row->id;
    }

    private static function __insert_soft(int $parent_id, string $name): int
    {
        $row = new Lifecycle_Bulk_Soft_Fixture_Model();
        $row->site_id = 1;
        $row->parent_id = $parent_id;
        $row->name = $name;
        $row->save();

        return (int) $row->id;
    }

    // -------------------------------------------------------------------------
    // Bulk UPDATE fires after_update PER RECORD with the changed fields
    // -------------------------------------------------------------------------

    public static function test_bulk_update_fires_after_update_per_record()
    {
        static::__reset();

        $id_a = static::__insert(1, 'a');
        $id_b = static::__insert(1, 'b');
        $id_c = static::__insert(2, 'c'); // NOT affected

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        // No wrapping transaction -> the builder's DB::transaction commits immediately ->
        // afterCommit flushes -> the hooks run inline, one per affected record.
        $affected = Lifecycle_Bulk_Fixture_Model::where('parent_id', 1)->update(['name' => 'bulk']);

        static::__assert_equals(2, $affected, 'bulk update returns the affected-record count');

        $calls = Lifecycle_Bulk_Fixture_Model::$update_calls;
        static::__assert_count(2, $calls, 'after_update ran once per affected row');

        $ids = [$calls[0]['id'], $calls[1]['id']];
        static::__assert_true(in_array($id_a, $ids, true) && in_array($id_b, $ids, true), 'the two affected ids fired');
        static::__assert_false(in_array($id_c, $ids, true), 'the unaffected row did not fire');
        static::__assert_true(in_array('name', $calls[0]['changed'], true), 'after_update received the changed field name');

        // The DB actually holds the new value on the affected rows only.
        static::__assert_equals('bulk', Lifecycle_Bulk_Fixture_Model::find($id_a)->name);
        static::__assert_equals('c', Lifecycle_Bulk_Fixture_Model::find($id_c)->name);
    }

    // -------------------------------------------------------------------------
    // Bulk DELETE (hard) fires after_delete PER RECORD; the row's attributes reach the hook
    // -------------------------------------------------------------------------

    public static function test_bulk_delete_fires_after_delete_per_record()
    {
        static::__reset();

        $id_a = static::__insert(4, 'blob-a');
        $id_b = static::__insert(4, 'blob-b');

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        $affected = Lifecycle_Bulk_Fixture_Model::where('parent_id', 4)->delete();

        static::__assert_equals(2, $affected, 'bulk delete returns the affected-record count');

        $calls = Lifecycle_Bulk_Fixture_Model::$delete_calls;
        static::__assert_count(2, $calls, 'after_delete ran once per deleted row');

        $names = [$calls[0]['name'], $calls[1]['name']];
        static::__assert_true(in_array('blob-a', $names, true) && in_array('blob-b', $names, true), 'the hydrated record attributes reached the hook');
        $ids = [$calls[0]['id'], $calls[1]['id']];
        static::__assert_true(in_array($id_a, $ids, true) && in_array($id_b, $ids, true));

        static::__assert_equals(0, (int) Lifecycle_Bulk_Fixture_Model::where('parent_id', 4)->count(), 'rows are physically gone');
    }

    // -------------------------------------------------------------------------
    // SoftDeletes bulk delete fires after_delete ONCE, never after_update (deleted_at write)
    // -------------------------------------------------------------------------

    public static function test_soft_delete_bulk_fires_after_delete_once_not_after_update()
    {
        static::__reset();

        $id = static::__insert_soft(5, 'soft');

        Lifecycle_Bulk_Soft_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        // A SoftDeletes ->delete() becomes an UPDATE of deleted_at, issued through the builder's
        // update() from inside $model->delete(). Under the single-write guard that internal update
        // runs RAW, so the record fires after_delete exactly once and NEVER after_update.
        Lifecycle_Bulk_Soft_Fixture_Model::where('parent_id', 5)->delete();

        static::__assert_count(1, Lifecycle_Bulk_Soft_Fixture_Model::$delete_calls, 'after_delete fired exactly once');
        static::__assert_count(0, Lifecycle_Bulk_Soft_Fixture_Model::$update_calls, 'after_update NEVER fired for the deleted_at write');
        static::__assert_equals($id, Lifecycle_Bulk_Soft_Fixture_Model::$delete_calls[0]['id']);

        // The row survives with deleted_at set (soft delete).
        static::__assert_equals(0, (int) Lifecycle_Bulk_Soft_Fixture_Model::where('parent_id', 5)->count(), 'default scope hides the soft-deleted row');
        static::__assert_equals(1, (int) Lifecycle_Bulk_Soft_Fixture_Model::withTrashed()->where('parent_id', 5)->count(), 'the row still exists, soft-deleted');
    }

    // -------------------------------------------------------------------------
    // ->raw_bulk() does the raw statement and fires NOTHING per-record
    // -------------------------------------------------------------------------

    public static function test_raw_bulk_update_does_raw_and_fires_nothing()
    {
        static::__reset();

        $id = static::__insert(9, 'orig');

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        $affected = Lifecycle_Bulk_Fixture_Model::where('parent_id', 9)->raw_bulk()->update(['name' => 'quiet']);

        static::__assert_equals(1, $affected, 'the raw statement still reports the affected count');
        static::__assert_count(0, Lifecycle_Bulk_Fixture_Model::$update_calls, 'raw_bulk() fired no after_update');
        static::__assert_equals('quiet', Lifecycle_Bulk_Fixture_Model::find($id)->name, 'but the raw UPDATE did write the value');
    }

    public static function test_raw_bulk_delete_does_raw_and_fires_nothing()
    {
        static::__reset();

        static::__insert(10, 'gone-a');
        static::__insert(10, 'gone-b');

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        $affected = Lifecycle_Bulk_Fixture_Model::where('parent_id', 10)->raw_bulk()->delete();

        static::__assert_equals(2, $affected, 'the raw delete reports the affected count');
        static::__assert_count(0, Lifecycle_Bulk_Fixture_Model::$delete_calls, 'raw_bulk() fired no after_delete');
        static::__assert_equals(0, (int) Lifecycle_Bulk_Fixture_Model::where('parent_id', 10)->count(), 'but the raw DELETE removed the rows');
    }

    // -------------------------------------------------------------------------
    // A raw Expression update routes to the raw statement (cannot iterate per-record)
    // -------------------------------------------------------------------------

    public static function test_raw_expression_update_routes_to_raw()
    {
        static::__reset();

        $id = static::__insert(11, 'expr');
        // Seed a known counter via the raw escape hatch (no hook).
        Lifecycle_Bulk_Fixture_Model::where('id', $id)->raw_bulk()->update(['counter' => 5]);

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        // DB::raw('counter + 1') is a set-based expression; it forces the raw path (no hook).
        $affected = Lifecycle_Bulk_Fixture_Model::where('parent_id', 11)->update(['counter' => DB::raw('counter + 1')]);

        static::__assert_equals(1, $affected, 'the expression update affected the row');
        static::__assert_count(0, Lifecycle_Bulk_Fixture_Model::$update_calls, 'a raw Expression update fired no after_update (routed to raw)');
        static::__assert_equals(6, (int) Lifecycle_Bulk_Fixture_Model::find($id)->counter, 'the expression was applied (5 + 1)');
    }

    // -------------------------------------------------------------------------
    // A rolled-back bulk op fires nothing
    // -------------------------------------------------------------------------

    public static function test_rolled_back_bulk_fires_nothing()
    {
        static::__reset();

        DB::beginTransaction();
        static::__insert(7, 'a');
        static::__insert(7, 'b');

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        Lifecycle_Bulk_Fixture_Model::where('parent_id', 7)->update(['name' => 'doomed']);
        Lifecycle_Bulk_Fixture_Model::where('parent_id', 7)->delete();

        // Outermost rollback discards the buffered generation; no afterCommit flush happens.
        DB::rollBack();

        static::__assert_count(0, Lifecycle_Bulk_Fixture_Model::$update_calls, 'no after_update fired on a rolled-back bulk write');
        static::__assert_count(0, Lifecycle_Bulk_Fixture_Model::$delete_calls, 'no after_delete fired on a rolled-back bulk write');
        static::__assert_count(0, Model_Lifecycle_Emissions::_testing_pending(), 'the buffer was discarded');
    }

    // -------------------------------------------------------------------------
    // A plain (no-surface) model bulk does ONE raw statement (no fetch-then-iterate)
    // -------------------------------------------------------------------------

    public static function test_plain_model_bulk_does_one_raw_statement()
    {
        static::__reset();

        $row = new Lifecycle_Bulk_Plain_Fixture_Model();
        $row->site_id = 1;
        $row->parent_id = 8;
        $row->name = 'plain';
        $row->save();

        $row2 = new Lifecycle_Bulk_Plain_Fixture_Model();
        $row2->site_id = 1;
        $row2->parent_id = 8;
        $row2->name = 'plain2';
        $row2->save();

        Model_Lifecycle_Emissions::_testing_reset();

        // A plain model has no side-effect surface, so the builder must take the raw fast path:
        // exactly one UPDATE statement (no SELECT + per-row UPDATE fetch-then-iterate).
        DB::flushQueryLog();
        DB::enableQueryLog();
        $affected = Lifecycle_Bulk_Plain_Fixture_Model::where('parent_id', 8)->update(['name' => 'q']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        static::__assert_equals(2, $affected, 'the raw update affected both rows');
        static::__assert_count(1, $queries, 'a plain model bulk update issues exactly one raw statement (no fetch-then-iterate)');
        static::__assert_true(str_contains(strtolower($queries[0]['query']), 'update'), 'and that single statement is the UPDATE');
        static::__assert_count(0, Model_Lifecycle_Emissions::_testing_pending(), 'a plain model buffers no lifecycle entry');
    }

    // -------------------------------------------------------------------------
    // The re-entrancy guard: a single save()/delete() fires EXACTLY ONCE (no recursion/double)
    // -------------------------------------------------------------------------

    public static function test_single_save_fires_after_update_exactly_once()
    {
        static::__reset();

        $id = static::__insert(12, 'orig');
        $model = Lifecycle_Bulk_Fixture_Model::find($id);

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        $model->name = 'changed';
        $model->save();

        // Exactly one: the single-row UPDATE reached the builder under the guard and ran raw,
        // so it did NOT re-enter the user-bulk fetch-then-iterate path (which would double or
        // recurse). Reaching a bounded count at all proves no infinite recursion.
        static::__assert_count(1, Lifecycle_Bulk_Fixture_Model::$update_calls, 'a single save() fires after_update exactly once');
        static::__assert_equals($id, Lifecycle_Bulk_Fixture_Model::$update_calls[0]['id']);
        static::__assert_equals(0, Rsx_Model_Abstract::$_single_model_write_depth, 'the write guard was released (finally)');
    }

    public static function test_single_delete_fires_after_delete_exactly_once()
    {
        static::__reset();

        $id = static::__insert(13, 'doomed');
        $model = Lifecycle_Bulk_Fixture_Model::find($id);

        Lifecycle_Bulk_Fixture_Model::_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        $model->delete();

        static::__assert_count(1, Lifecycle_Bulk_Fixture_Model::$delete_calls, 'a single delete() fires after_delete exactly once');
        static::__assert_equals($id, Lifecycle_Bulk_Fixture_Model::$delete_calls[0]['id']);
        static::__assert_equals(0, Rsx_Model_Abstract::$_single_model_write_depth, 'the write guard was released (finally)');
    }
}
