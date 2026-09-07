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
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Cycle_Fixture_Model;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Fixture_Model;
use App\RSpade\Tests\Lifecycle\Php\Lifecycle_Plain_Fixture_Model;

/**
 * Phase 1 of the model-lifecycle engine: the additive single-write hook surface
 * (after_create / after_update / after_delete) plus the declares-a-hook gate and the
 * afterCommit dispatch buffer.
 *
 * The class opts OUT of the runner's per-test transaction so it can drive commit/rollback
 * boundaries explicitly (the afterCommit flush only fires on a real commit) and observe the
 * hooks actually running. Each test resets the buffer + fixture recorders first (statics are
 * process-global).
 */
class Model_Lifecycle_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__drop_table();

        DB::statement('CREATE TABLE lifecycle_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            counter BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function teardown()
    {
        static::__drop_table();
        Model_Lifecycle_Emissions::_testing_reset();
        Model_Lifecycle_Registry::_testing_reset();
    }

    private static function __drop_table()
    {
        DB::statement('DROP TABLE IF EXISTS lifecycle_fixtures');
    }

    private static function __reset()
    {
        Model_Lifecycle_Emissions::_testing_reset();
        Lifecycle_Fixture_Model::_reset();
        Lifecycle_Cycle_Fixture_Model::_reset();
    }

    // -------------------------------------------------------------------------
    // The gate
    // -------------------------------------------------------------------------

    public static function test_gate_detects_declared_and_undeclared_surface()
    {
        static::__assert_true(
            Model_Lifecycle_Registry::has_lifecycle_surface(Lifecycle_Fixture_Model::class),
            'a model overriding the hooks has lifecycle surface'
        );
        static::__assert_false(
            Model_Lifecycle_Registry::has_lifecycle_surface(Lifecycle_Plain_Fixture_Model::class),
            'a model overriding no hook has no lifecycle surface'
        );

        // Per-event granularity: the cycle fixture overrides ONLY after_update.
        static::__assert_true(Model_Lifecycle_Registry::declares(Lifecycle_Cycle_Fixture_Model::class, 'after_update'));
        static::__assert_false(Model_Lifecycle_Registry::declares(Lifecycle_Cycle_Fixture_Model::class, 'after_create'));
        static::__assert_false(Model_Lifecycle_Registry::declares(Lifecycle_Cycle_Fixture_Model::class, 'after_delete'));
    }

    // -------------------------------------------------------------------------
    // Single-write hooks fire (no explicit transaction -> flush is immediate)
    // -------------------------------------------------------------------------

    public static function test_after_create_fires_on_insert()
    {
        static::__reset();

        $model = new Lifecycle_Fixture_Model();
        $model->name = 'created';
        $model->site_id = 1;
        $model->save();

        $calls = Lifecycle_Fixture_Model::$calls;
        static::__assert_count(1, $calls, 'an insert fires exactly one hook');
        static::__assert_equals('create', $calls[0]['event']);
        static::__assert_equals((int) $model->id, $calls[0]['id'], 'after_create runs on the persisted record (has an id)');
    }

    public static function test_after_update_fires_with_changed_fields()
    {
        static::__reset();

        $model = new Lifecycle_Fixture_Model();
        $model->name = 'original';
        $model->site_id = 1;
        $model->save();

        // Discard the create; observe only the update.
        static::__reset_after_persisting($model);

        $model->name = 'changed';
        $model->save();

        $calls = Lifecycle_Fixture_Model::$calls;
        static::__assert_count(1, $calls, 'a dirty update fires exactly one hook');
        static::__assert_equals('update', $calls[0]['event']);
        static::__assert_true(in_array('name', $calls[0]['changed'], true), 'after_update receives the changed field name');
    }

    public static function test_after_delete_fires()
    {
        static::__reset();

        $model = new Lifecycle_Fixture_Model();
        $model->name = 'doomed';
        $model->site_id = 1;
        $model->save();
        $id = (int) $model->id;

        static::__reset_after_persisting($model);

        $model->delete();

        $calls = Lifecycle_Fixture_Model::$calls;
        static::__assert_count(1, $calls, 'a delete fires exactly one hook');
        static::__assert_equals('delete', $calls[0]['event']);
        static::__assert_equals($id, $calls[0]['id']);
    }

    public static function test_update_with_no_changes_fires_nothing()
    {
        static::__reset();

        $model = new Lifecycle_Fixture_Model();
        $model->name = 'stable';
        $model->site_id = 1;
        $model->save();

        static::__reset_after_persisting($model);

        // Re-save with no dirty attributes: Laravel performs no UPDATE, so no hook fires.
        $model->save();

        static::__assert_count(0, Lifecycle_Fixture_Model::$calls, 'a no-op save queues no lifecycle event');
    }

    // -------------------------------------------------------------------------
    // Commit / rollback semantics
    // -------------------------------------------------------------------------

    public static function test_hook_fires_only_after_commit()
    {
        static::__reset();

        DB::beginTransaction();
        $model = new Lifecycle_Fixture_Model();
        $model->name = 'pending';
        $model->site_id = 1;
        $model->save();

        static::__assert_count(0, Lifecycle_Fixture_Model::$calls, 'the hook has not fired while the transaction is open');

        DB::commit();

        static::__assert_count(1, Lifecycle_Fixture_Model::$calls, 'the hook fires on commit');
        static::__assert_equals('create', Lifecycle_Fixture_Model::$calls[0]['event']);
    }

    public static function test_rollback_discards_the_hook()
    {
        static::__reset();

        DB::beginTransaction();
        $model = new Lifecycle_Fixture_Model();
        $model->name = 'rolled back';
        $model->site_id = 1;
        $model->save();
        DB::rollBack();

        static::__assert_count(0, Lifecycle_Fixture_Model::$calls, 'a rolled-back write fires no lifecycle hook');
    }

    // -------------------------------------------------------------------------
    // No-surface model pays no dispatch cost
    // -------------------------------------------------------------------------

    public static function test_plain_model_incurs_no_dispatch()
    {
        Model_Lifecycle_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_start_capture();

        $model = new Lifecycle_Plain_Fixture_Model();
        $model->name = 'plain';
        $model->site_id = 1;
        $model->save();

        static::__assert_count(0, Model_Lifecycle_Emissions::_testing_captured(), 'a hookless model dispatches nothing');
    }

    // -------------------------------------------------------------------------
    // Re-entrancy / cycle guard
    // -------------------------------------------------------------------------

    public static function test_hook_that_writes_does_not_infinite_loop()
    {
        static::__reset();

        $model = new Lifecycle_Cycle_Fixture_Model();
        $model->name = 'cycle';
        $model->site_id = 1;
        $model->save();

        // Reaching here at all proves termination (an uncapped cycle would exhaust the
        // stack). after_update re-writes the record every call; the flush-depth cap bounds
        // the runs.
        static::__reset_cycle_after_persisting($model);

        $model->counter = 1;
        $model->save();

        $runs = Lifecycle_Cycle_Fixture_Model::$update_calls;
        static::__assert_greater_than(1, $runs, 'the self-writing hook recursed at least once');
        static::__assert_less_than(26, $runs, 'the flush-depth cap bounded the runaway cycle');
    }

    // -------------------------------------------------------------------------
    // Helpers: reset recorders WITHOUT losing the just-persisted instance state.
    // -------------------------------------------------------------------------

    private static function __reset_after_persisting(Lifecycle_Fixture_Model $model)
    {
        Model_Lifecycle_Emissions::_testing_reset();
        Lifecycle_Fixture_Model::_reset();
    }

    private static function __reset_cycle_after_persisting(Lifecycle_Cycle_Fixture_Model $model)
    {
        Model_Lifecycle_Emissions::_testing_reset();
        Lifecycle_Cycle_Fixture_Model::_reset();
    }
}
