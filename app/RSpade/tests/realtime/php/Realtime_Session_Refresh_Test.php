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
 * Control-plane (targeted refresh) plumbing for session/user refresh pushes. Mirrors
 * Realtime_Emissions_Flush_Test: opts out of the per-test transaction to control commit
 * timing explicitly, and asserts via the control capture seam (records what WOULD transmit,
 * so no live relay/Redis subscriber is needed). The queue_* methods are called directly to
 * bypass Realtime's enable gate; a separate pair of tests exercises that gate through the
 * public push_* API.
 */
class Realtime_Session_Refresh_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __begin(): void
    {
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_set_web_context(null);
        Realtime_Emissions::_testing_start_control_capture();
    }

    // -------------------------------------------------------------------------
    // Flush timing (CLI immediate / deferred / discarded)
    // -------------------------------------------------------------------------

    public static function test_session_refresh_flushes_immediately_with_no_transaction()
    {
        static::__begin();

        Realtime_Emissions::queue_session_refresh('staff', 4001);

        $captured = Realtime_Emissions::_testing_captured_control();
        static::__assert_count(1, $captured, 'no active transaction -> flush runs immediately');
        static::__assert_equals('session_refresh', $captured[0]['kind']);
        static::__assert_equals('staff', $captured[0]['realm']);
        static::__assert_equals(4001, $captured[0]['session_id']);
    }

    public static function test_user_refresh_frame_shape()
    {
        static::__begin();

        Realtime_Emissions::queue_user_refresh(7, 55);

        $captured = Realtime_Emissions::_testing_captured_control();
        static::__assert_count(1, $captured);
        static::__assert_equals('user_refresh', $captured[0]['kind']);
        static::__assert_equals('staff', $captured[0]['realm'], 'user refresh is always the staff realm');
        static::__assert_equals(7, $captured[0]['site_id']);
        static::__assert_equals(55, $captured[0]['user_id']);
    }

    public static function test_control_frame_deferred_until_commit()
    {
        static::__begin();

        DB::beginTransaction();
        Realtime_Emissions::queue_session_refresh('portal', 4002);
        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'not flushed while the transaction is open');

        DB::commit();
        static::__assert_count(1, Realtime_Emissions::_testing_captured_control(), 'flushed on commit');
        static::__assert_equals('portal', Realtime_Emissions::_testing_captured_control()[0]['realm']);
    }

    public static function test_control_frame_discarded_on_rollback()
    {
        static::__begin();

        DB::beginTransaction();
        Realtime_Emissions::queue_session_refresh('staff', 4003);
        DB::rollBack();

        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'rollback discards the pending control frame');
    }

    // -------------------------------------------------------------------------
    // Dedup
    // -------------------------------------------------------------------------

    public static function test_identical_session_pushes_dedupe_to_one()
    {
        static::__begin();

        DB::beginTransaction();
        Realtime_Emissions::queue_session_refresh('staff', 4004);
        Realtime_Emissions::queue_session_refresh('staff', 4004);
        DB::commit();

        static::__assert_count(1, Realtime_Emissions::_testing_captured_control(), 'two identical session pushes collapse to one frame');
    }

    public static function test_distinct_sessions_are_not_deduped()
    {
        static::__begin();

        DB::beginTransaction();
        Realtime_Emissions::queue_session_refresh('staff', 4005);
        Realtime_Emissions::queue_session_refresh('staff', 4006);
        DB::commit();

        static::__assert_count(2, Realtime_Emissions::_testing_captured_control(), 'different session ids are distinct frames');
    }

    public static function test_same_id_different_realm_are_distinct()
    {
        static::__begin();

        DB::beginTransaction();
        Realtime_Emissions::queue_session_refresh('staff', 4007);
        Realtime_Emissions::queue_session_refresh('portal', 4007);
        DB::commit();

        static::__assert_count(2, Realtime_Emissions::_testing_captured_control(), 'realm is part of the dedup identity');
    }

    // -------------------------------------------------------------------------
    // Non-positive id guards
    // -------------------------------------------------------------------------

    public static function test_nonpositive_ids_are_ignored()
    {
        static::__begin();

        Realtime_Emissions::queue_session_refresh('staff', 0);
        Realtime_Emissions::queue_user_refresh(1, 0);

        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'a non-positive id is a meaningless target');
    }

    // -------------------------------------------------------------------------
    // Web outbox: staged (not transmitted) until request termination, request-wide dedup
    // -------------------------------------------------------------------------

    public static function test_web_context_stages_control_outbox_then_transmits()
    {
        static::__begin();
        Realtime_Emissions::_testing_set_web_context(true);

        Realtime_Emissions::queue_session_refresh('staff', 4008);

        static::__assert_count(1, Realtime_Emissions::_testing_control_outbox(), 'web path stages the frame instead of transmitting at flush');

        Realtime_Emissions::_testing_transmit_outbox();
        static::__assert_count(0, Realtime_Emissions::_testing_control_outbox(), 'termination transmit drains the outbox');

        Realtime_Emissions::_testing_set_web_context(null);
    }

    public static function test_web_context_dedups_control_across_the_request()
    {
        static::__begin();
        Realtime_Emissions::_testing_set_web_context(true);

        // Two separate flushes (each is its own generation) of the same session in one request.
        Realtime_Emissions::queue_session_refresh('staff', 4009);
        Realtime_Emissions::queue_session_refresh('staff', 4009);

        static::__assert_count(1, Realtime_Emissions::_testing_control_outbox(), 'one refresh per session per request');

        Realtime_Emissions::_testing_set_web_context(null);
    }

    // -------------------------------------------------------------------------
    // Public push_* API enable gate
    // -------------------------------------------------------------------------

    public static function test_push_is_noop_when_realtime_disabled()
    {
        static::__begin();
        $prior = config('rsx.realtime.enabled');
        config(['rsx.realtime.enabled' => false]);

        Realtime::push_session_refresh('staff', 4010);
        Realtime::push_user_refresh(1, 4011);

        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'push_* is a no-op when realtime is disabled');

        config(['rsx.realtime.enabled' => $prior]);
    }

    public static function test_push_queues_when_realtime_enabled()
    {
        static::__begin();
        $prior = config('rsx.realtime.enabled');
        config(['rsx.realtime.enabled' => true]);

        Realtime::push_session_refresh('staff', 4012);

        static::__assert_count(1, Realtime_Emissions::_testing_captured_control(), 'push_* queues when realtime is enabled');

        config(['rsx.realtime.enabled' => $prior]);
    }
}
