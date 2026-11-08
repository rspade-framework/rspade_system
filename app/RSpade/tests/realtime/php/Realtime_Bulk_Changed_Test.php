<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Realtime::bulk_changed() - the published emitter kick after a write that ran outside the
 * model layer. A raw bulk UPDATE marks nothing by itself; followed by bulk_changed() the
 * emitter kick rides the transaction: once at commit however often it was called, and
 * never when the transaction rolls back.
 *
 * The class opts OUT of the per-test transaction so it owns the transaction boundaries.
 * The emitter-kick capture counts flushes that would ask the gated emitter dispatch; the
 * gates themselves are Realtime_Emitter_Dispatch_Gate_Test's subject.
 */
class Realtime_Bulk_Changed_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__drop_table();

        DB::statement('CREATE TABLE realtime_bulk_changed_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            is_read TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::table('realtime_bulk_changed_fixtures')->insert([['is_read' => 0], ['is_read' => 0], ['is_read' => 0]]);
    }

    public static function teardown()
    {
        static::__drop_table();
        Realtime_Emissions::_testing_reset();
    }

    private static function __drop_table()
    {
        DB::statement('DROP TABLE IF EXISTS realtime_bulk_changed_fixtures');
    }

    private static function __mark_all(int $is_read): void
    {
        DB::table('realtime_bulk_changed_fixtures')->where('id', '>', 0)->update(['is_read' => $is_read]);
    }

    public static function test_raw_bulk_update_alone_does_not_kick_emitters()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        static::__mark_all(1);
        DB::commit();

        static::__assert_false(Realtime_Emissions::_testing_emitters_dirty(), 'a raw statement marks nothing by itself');
        static::__assert_equals(0, Realtime_Emissions::_testing_captured_emitter_kicks(), 'nothing flushes for a raw statement');
    }

    public static function test_bulk_changed_kicks_emitters_once_after_commit()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        static::__mark_all(1);
        Realtime::bulk_changed();
        static::__mark_all(0);
        Realtime::bulk_changed();

        static::__assert_true(Realtime_Emissions::_testing_emitters_dirty(), 'marked inside the open transaction');
        static::__assert_equals(0, Realtime_Emissions::_testing_captured_emitter_kicks(), 'nothing flushes before commit');

        DB::commit();

        static::__assert_equals(1, Realtime_Emissions::_testing_captured_emitter_kicks(), 'two calls in one transaction kick the emitters once, at commit');
        static::__assert_false(Realtime_Emissions::_testing_emitters_dirty(), 'the flush drained the flag');
        static::__assert_count(0, Realtime_Emissions::_testing_captured(), 'bulk_changed() publishes no model-change frame');
    }

    public static function test_bulk_changed_is_dropped_on_rollback()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        DB::beginTransaction();
        static::__mark_all(1);
        Realtime::bulk_changed();
        DB::rollBack();

        static::__assert_equals(0, Realtime_Emissions::_testing_captured_emitter_kicks(), 'a rolled-back transaction kicks nothing');
        static::__assert_false(Realtime_Emissions::_testing_emitters_dirty(), 'the rollback cleared the flag');

        // The rolled-back mark must not ride a later, unrelated commit either.
        DB::beginTransaction();
        static::__mark_all(0);
        DB::commit();

        static::__assert_equals(0, Realtime_Emissions::_testing_captured_emitter_kicks(), 'a later commit carries no stale kick');
    }

    public static function test_bulk_changed_outside_a_transaction_flushes_immediately()
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();

        static::__mark_all(1);
        Realtime::bulk_changed();

        static::__assert_equals(1, Realtime_Emissions::_testing_captured_emitter_kicks(), 'no open transaction -> the kick flushes at once');
    }
}
