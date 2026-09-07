<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Flash\Php;

use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Flash\Flash_Alert_Cleanup_Service;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Flash_Alert_Cleanup_Service::cleanup_expired_alerts - the hourly retention sweep
 * (owner ruling 2026-08-09).
 *
 * The read path expires alerts at one minute, but only for the session doing the
 * reading. A visitor handed an alert who never comes back leaves the row behind
 * until their SESSION is deleted months later. This unconditional age rule is what
 * collects it, on any session - an abandoned alert is abandoned whoever queued it.
 *
 * The task is invoked directly with an immediate Task_Instance (info() buffers,
 * heartbeat() no-ops off a worker). Default transaction isolation: the DELETE is
 * visible on the same connection and rolls back afterward.
 */
class Flash_Alert_Sweep_Test extends Rsx_Test_Abstract
{
    private static int $_session_a_id = 0;

    private static int $_session_b_id = 0;

    public static function setup()
    {
        static::$_session_a_id = static::__make_session('flash-sweep-a');
        static::$_session_b_id = static::__make_session('flash-sweep-b');
    }

    public static function teardown()
    {
        Session::whereIn('id', [static::$_session_a_id, static::$_session_b_id])
            ->raw_bulk()
            ->delete();
    }

    private static function __make_session(string $tag): int
    {
        $session = new Session();
        $session->session_token = $tag . '-' . bin2hex(random_bytes(16));
        $session->csrf_token = bin2hex(random_bytes(16));
        $session->active = true;
        $session->site_id = 0;
        $session->type_id = Session::TYPE_WEB;
        $session->version = 1;
        $session->ip_address = '10.0.0.9';
        $session->user_agent = 'test-browser';
        $session->last_active = now();
        $session->save();

        return (int) $session->id;
    }

    /**
     * Seed one alert of a given age. created_at is set through a raw update because
     * the column defaults to CURRENT_TIMESTAMP on write.
     */
    private static function __seed(int $session_id, string $message, int $age_minutes): int
    {
        $alert = new Flash_Alert_Model();
        $alert->session_id = $session_id;
        $alert->type_id = Flash_Alert_Model::TYPE_INFO;
        $alert->message = $message;
        $alert->created_at = now()->subMinutes($age_minutes);
        $alert->save();

        Flash_Alert_Model::where('id', $alert->id)
            ->raw_bulk()
            ->update(['created_at' => now()->subMinutes($age_minutes)]);

        return (int) $alert->id;
    }

    private static function __exists(int $id): bool
    {
        return Flash_Alert_Model::where('id', $id)->exists();
    }

    private static function __task(): Task_Instance
    {
        return new Task_Instance(Flash_Alert_Cleanup_Service::class, 'cleanup_expired_alerts');
    }

    // =====================================================================

    public static function test_alerts_older_than_the_window_are_deleted()
    {
        $stale = static::__seed(static::$_session_a_id, 'abandoned', 45);

        $result = Flash_Alert_Cleanup_Service::cleanup_expired_alerts(static::__task());

        static::__assert_false(static::__exists($stale), 'a 45-minute-old alert is collected');
        static::__assert_equals(30, $result['retention_minutes']);
        static::__assert_greater_than(0, $result['deleted']);
    }

    public static function test_fresh_alerts_are_kept()
    {
        $fresh = static::__seed(static::$_session_a_id, 'still waiting', 2);

        Flash_Alert_Cleanup_Service::cleanup_expired_alerts(static::__task());

        static::__assert_true(
            static::__exists($fresh),
            'an alert the browser may still be coming back for is never swept'
        );
    }

    public static function test_every_session_is_swept()
    {
        $stale_a = static::__seed(static::$_session_a_id, 'abandoned a', 60);
        $stale_b = static::__seed(static::$_session_b_id, 'abandoned b', 60);
        $fresh_b = static::__seed(static::$_session_b_id, 'fresh b', 1);

        Flash_Alert_Cleanup_Service::cleanup_expired_alerts(static::__task());

        static::__assert_false(static::__exists($stale_a), 'one session swept');
        static::__assert_false(static::__exists($stale_b), 'the other swept too - the rule is session-agnostic');
        static::__assert_true(static::__exists($fresh_b), 'and age is the only predicate');
    }

    /**
     * The chunk loop must not stop at the first full batch.
     */
    public static function test_the_sweep_runs_until_nothing_matches()
    {
        for ($i = 0; $i < 5; $i++) {
            static::__seed(static::$_session_a_id, 'batch ' . $i, 90);
        }

        Flash_Alert_Cleanup_Service::cleanup_expired_alerts(static::__task(), ['chunk_size' => 2]);

        static::__assert_equals(
            0,
            Flash_Alert_Model::where('session_id', static::$_session_a_id)->count(),
            'a chunk size smaller than the backlog still clears it'
        );
    }
}
