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

/**
 * Flush timing for Realtime_Emissions, via the capture seam. The class opts OUT of the
 * per-test transaction so it can control transaction boundaries explicitly:
 *   - no transaction     -> flush runs immediately
 *   - inside transaction -> flush deferred until commit
 *   - rolled back        -> flush discarded
 *
 * The capture seam records what WOULD be published, so assertions never depend on a
 * live Redis subscriber. queue_model_change() is called on in-memory instances, so the
 * transactions here carry no real writes — they exist only to drive afterCommit timing.
 */
class Realtime_Emissions_Flush_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __model(int $id): Realtime_Fixture_Model
    {
        $model = new Realtime_Fixture_Model();
        $model->id = $id;
        $model->site_id = 1;

        return $model;
    }

    public static function test_flush_is_immediate_when_no_transaction_active()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        Realtime_Emissions::queue_model_change(static::__model(5));

        $captured = Realtime_Emissions::_testing_captured();
        static::__assert_count(1, $captured, 'no active transaction -> flush runs immediately');
        static::__assert_equals('Realtime_Fixture_Model', $captured[0]['model']);
        static::__assert_equals(5, $captured[0]['id']);
    }

    public static function test_flush_is_deferred_until_commit()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        Realtime_Emissions::queue_model_change(static::__model(6));
        static::__assert_count(0, Realtime_Emissions::_testing_captured(), 'not flushed while the transaction is open');

        DB::commit();
        static::__assert_count(1, Realtime_Emissions::_testing_captured(), 'flushed on commit');
    }

    public static function test_flush_is_discarded_on_rollback()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        Realtime_Emissions::queue_model_change(static::__model(7));
        DB::rollBack();

        static::__assert_count(0, Realtime_Emissions::_testing_captured(), 'rollback discards the pending flush');
    }

    public static function test_write_after_rollback_still_flushes_without_stale_entries()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        // A rolled-back generation must neither publish nor poison later ones:
        // the discarded afterCommit callback may not suppress the next flush
        // (under-emit), and the rolled-back entry may not ride along (over-emit).
        DB::beginTransaction();
        Realtime_Emissions::queue_model_change(static::__model(9));
        DB::rollBack();

        Realtime_Emissions::queue_model_change(static::__model(10));

        $captured = Realtime_Emissions::_testing_captured();
        static::__assert_count(1, $captured, 'post-rollback write flushes exactly itself');
        static::__assert_equals(10, $captured[0]['id'], 'the rolled-back entry was discarded');
    }

    public static function test_dedupe_survives_to_a_single_flushed_emission()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        $model = static::__model(8);
        Realtime_Emissions::queue_model_change($model);
        Realtime_Emissions::queue_model_change($model);
        DB::commit();

        static::__assert_count(1, Realtime_Emissions::_testing_captured(), 'two queues of one record flush as one emission');
    }
}
