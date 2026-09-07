<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Plain_Fixture_Model;
use App\RSpade\Tests\Realtime\Php\Realtime_Silent_Fixture_Model;

/**
 * The save()/delete() hook in Rsx_Model_Abstract, exercised with real writes and
 * realtime enabled at runtime:
 *   - $realtime = true  -> the write queues a model-change emission
 *   - $realtime = false -> no model-change emission
 *   - $realtime_silent  -> the write does NOT kick the emitter engine; a non-silent
 *                          write DOES (independent of the emission opt-in)
 *
 * Each write runs inside the runner's wrapping transaction, so the afterCommit flush is
 * deferred; the tests peek the buffer / dirty flag before the transaction rolls back.
 */
class Realtime_Emissions_Hook_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__drop_table();

        DB::statement('CREATE TABLE realtime_emissions_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by BIGINT DEFAULT NULL,
            updated_by BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // The hook only fires when realtime is enabled; this env defaults it off.
        config(['rsx.realtime.enabled' => true]);
    }

    public static function teardown()
    {
        static::__drop_table();
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
    }

    private static function __drop_table()
    {
        DB::statement('DROP TABLE IF EXISTS realtime_emissions_fixtures');
    }

    public static function test_realtime_model_save_queues_change()
    {
        Realtime_Emissions::_testing_reset();

        $model = new Realtime_Fixture_Model();
        $model->name = 'first';
        $model->site_id = 1;
        $model->save();

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_count(1, $pending, 'an opt-in model save queues one emission');
        static::__assert_equals('Realtime_Fixture_Model', $pending[0]['model']);
        static::__assert_equals((int) $model->id, $pending[0]['id']);
    }

    public static function test_non_realtime_model_save_does_not_queue()
    {
        Realtime_Emissions::_testing_reset();

        $model = new Realtime_Plain_Fixture_Model();
        $model->name = 'plain';
        $model->site_id = 1;
        $model->save();

        static::__assert_count(0, Realtime_Emissions::_testing_pending(), '$realtime = false queues no model change');
    }

    public static function test_non_silent_write_marks_emitters_dirty()
    {
        Realtime_Emissions::_testing_reset();

        // Plain fixture opts OUT of emission but is NOT silent -> still kicks emitters.
        $model = new Realtime_Plain_Fixture_Model();
        $model->name = 'plain';
        $model->site_id = 1;
        $model->save();

        static::__assert_true(Realtime_Emissions::_testing_emitters_dirty(), 'a non-silent write marks emitters dirty even without opting into emission');
    }

    public static function test_silent_write_does_not_mark_emitters_dirty()
    {
        Realtime_Emissions::_testing_reset();

        $model = new Realtime_Silent_Fixture_Model();
        $model->name = 'silent';
        $model->site_id = 1;
        $model->save();

        static::__assert_false(Realtime_Emissions::_testing_emitters_dirty(), 'a $realtime_silent write does not kick the emitter engine');
    }

    public static function test_delete_of_realtime_model_queues_change()
    {
        Realtime_Emissions::_testing_reset();

        $model = new Realtime_Fixture_Model();
        $model->name = 'to delete';
        $model->site_id = 1;
        $model->save();
        $id = (int) $model->id;

        // Discard the save's emission so we observe only the delete.
        Realtime_Emissions::_testing_reset();
        $model->delete();

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_count(1, $pending, 'a delete of an opt-in model queues one emission');
        static::__assert_equals($id, $pending[0]['id']);
    }
}
