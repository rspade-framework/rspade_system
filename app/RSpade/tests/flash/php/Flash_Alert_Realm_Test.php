<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Flash\Php;

use ReflectionMethod;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Flash\Flash_Alert;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Tests for flash-alert SESSION + EXPERIENCE scoping.
 *
 * Two predicates, because they answer two different questions. There is ONE session
 * per browser, so both facades resolve the SAME session id: session_id is what keeps
 * one browser's alerts out of another browser's page, and it can say nothing about
 * which experience queued a row. The is_portal column answers that second question -
 * a portal page delivers only portal-queued alerts, a staff page only staff-queued
 * ones, and each experience's queue is capped independently.
 *
 * A PHP test runs in console context, where the public writers print instead of
 * persisting (that is its own contract - see Flash_Alert_Cli_Test), so the write
 * path is reached through the protected _resolve_session_id() and the read through
 * _pending_for_session(), the same way the cap test reaches _enforce_session_cap().
 */
class Flash_Alert_Realm_Test extends Rsx_Test_Abstract
{
    private static int $_session_a_id = 0;

    private static int $_session_b_id = 0;

    public static function setup()
    {
        static::$_session_a_id = static::__make_session('flash-session-a');
        static::$_session_b_id = static::__make_session('flash-session-b');
    }

    public static function teardown()
    {
        Rsx_Portal::set_portal_request(false);

        // Flash rows cascade with the session.
        Session::whereIn('id', [static::$_session_a_id, static::$_session_b_id])
            ->raw_bulk()
            ->delete();
    }

    /**
     * One _sessions row - one browser's session, standing in for one of two browsers.
     */
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

    private static function __seed(int $session_id, string $message, bool $is_portal = false): int
    {
        $alert = new Flash_Alert_Model();
        $alert->session_id = $session_id;
        $alert->is_portal = $is_portal;
        $alert->type_id = Flash_Alert_Model::TYPE_SUCCESS;
        $alert->message = $message;
        $alert->created_at = now();
        $alert->save();

        return (int) $alert->id;
    }

    private static function __invoke_protected(string $method, array $args)
    {
        $reflection = new ReflectionMethod(Flash_Alert::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    private static function __session_row_count(): int
    {
        return (int) Session::query()->count();
    }

    // =====================================================================

    /**
     * A portal-context write mints ONE session - the browser's - and never a second
     * one alongside it. (The two-row design this replaced was what made a portal flash
     * write mint an extra staff session and set an extra cookie.)
     */
    public static function test_portal_context_write_mints_exactly_one_session()
    {
        static::__reset_session();
        Rsx_Portal::set_portal_request(true);

        $before = static::__session_row_count();

        $session_id = static::__invoke_protected('_resolve_session_id', []);

        static::__assert_equals(
            $before + 1,
            static::__session_row_count(),
            'exactly one session row is created'
        );
        static::__assert_equals(
            (int) Session::get_session_id(),
            (int) $session_id,
            'and it is THE session - the same one the staff facade reports'
        );
    }

    /**
     * The staff branch is still the staff branch: outside portal context the write
     * resolves through Session, which yields a real session id.
     */
    public static function test_staff_context_write_resolves_the_staff_session()
    {
        static::__reset_session();
        Rsx_Portal::set_portal_request(false);

        $session_id = static::__invoke_protected('_resolve_session_id', []);

        static::__assert_true($session_id > 0, 'the staff facade yields a real session id');
        static::__assert_equals(
            (int) Session::get_session_id(),
            (int) $session_id,
            'and it is THIS process/request session, not some other row'
        );
    }

    /**
     * Two BROWSERS: a read keyed on one session's id never sees the other's alerts.
     */
    public static function test_a_read_never_sees_another_sessions_alerts()
    {
        static::__seed(static::$_session_a_id, 'message a');
        static::__seed(static::$_session_b_id, 'message b');

        $messages = static::__invoke_protected('_pending_for_session', [static::$_session_a_id, false]);

        static::__assert_count(1, $messages);
        static::__assert_equals('message a', $messages[0]['message']);

        static::__assert_equals(
            1,
            Flash_Alert_Model::where('session_id', static::$_session_b_id)->count(),
            'the other session\'s row is still queued - this read never consumed it'
        );
    }

    public static function test_a_read_consumes_only_its_own_sessions_alerts()
    {
        static::__seed(static::$_session_a_id, 'message a');
        static::__seed(static::$_session_b_id, 'message b');

        $messages = static::__invoke_protected('_pending_for_session', [static::$_session_b_id, false]);

        static::__assert_count(1, $messages);
        static::__assert_equals('message b', $messages[0]['message']);

        static::__assert_equals(
            1,
            Flash_Alert_Model::where('session_id', static::$_session_a_id)->count(),
            'the other session\'s row is still queued'
        );
    }

    /**
     * One-time delivery: the read hands the set over and deletes it.
     */
    public static function test_the_read_deletes_what_it_returns()
    {
        static::__seed(static::$_session_b_id, 'first');
        static::__seed(static::$_session_b_id, 'second');

        $first = static::__invoke_protected('_pending_for_session', [static::$_session_b_id, false]);
        $second = static::__invoke_protected('_pending_for_session', [static::$_session_b_id, false]);

        static::__assert_count(2, $first, 'the whole set is returned, never a page of it');
        static::__assert_count(0, $second, 'and it is gone on the next read');
    }

    /**
     * The expiry sweep is session-scoped, which is exactly why the hourly retention
     * task exists - see Flash_Alert_Cleanup_Service.
     */
    public static function test_the_read_sweeps_alerts_older_than_one_minute()
    {
        $stale = static::__seed(static::$_session_b_id, 'stale');
        Flash_Alert_Model::where('id', $stale)
            ->raw_bulk()
            ->update(['created_at' => now()->subMinutes(5)]);

        static::__seed(static::$_session_b_id, 'fresh');

        $messages = static::__invoke_protected('_pending_for_session', [static::$_session_b_id, false]);

        static::__assert_count(1, $messages);
        static::__assert_equals('fresh', $messages[0]['message'], 'the expired alert is dropped, not delivered');
    }

    /**
     * The cap is keyed on the resolved session id, so it is session-correct for free.
     */
    public static function test_the_cap_applies_per_session()
    {
        config(['rsx.flash.max_alerts_per_session' => 2]);

        for ($i = 1; $i <= 6; $i++) {
            static::__seed(static::$_session_b_id, 'alert ' . $i);
        }
        static::__seed(static::$_session_a_id, 'other session untouched');

        static::__invoke_protected('_enforce_session_cap', [static::$_session_b_id, false]);

        static::__assert_equals(
            2,
            Flash_Alert_Model::where('session_id', static::$_session_b_id)->count(),
            'the session is trimmed to the cap'
        );
        static::__assert_equals(
            1,
            Flash_Alert_Model::where('session_id', static::$_session_a_id)->count(),
            'another browser session is never in scope'
        );
    }

    public static function test_two_browser_sessions_hold_different_session_ids()
    {
        static::__assert_true(
            static::$_session_a_id !== static::$_session_b_id,
            'globally unique ids are what make one session_id column sufficient'
        );
    }

    /**
     * One browser has ONE session, so both facades resolve the same id. That is the
     * premise the is_portal column exists for: the session id can no longer be the
     * thing that separates the two experiences, because it is the same value in both.
     */
    public static function test_both_facades_resolve_the_same_session_id()
    {
        static::__reset_session();

        Rsx_Portal::set_portal_request(false);
        $staff_id = (int) static::__invoke_protected('_resolve_session_id', []);

        Rsx_Portal::set_portal_request(true);
        $portal_id = (int) static::__invoke_protected('_resolve_session_id', []);

        static::__assert_equals($staff_id, $portal_id, 'one browser, one session, one id');
    }

    // =====================================================================
    // EXPERIENCE SCOPING - the same session, two independent queues
    // =====================================================================

    /**
     * A portal page's alert is invisible to a staff read on the SAME browser session,
     * and is still there afterwards waiting for a portal page.
     */
    public static function test_a_staff_read_never_sees_a_portal_queued_alert()
    {
        static::__seed(static::$_session_a_id, 'staff message', false);
        static::__seed(static::$_session_a_id, 'portal message', true);

        $messages = static::__invoke_protected('_pending_for_session', [static::$_session_a_id, false]);

        static::__assert_count(1, $messages, 'only the staff-queued alert is delivered');
        static::__assert_equals('staff message', $messages[0]['message']);

        static::__assert_equals(
            1,
            Flash_Alert_Model::where('session_id', static::$_session_a_id)
                ->where('is_portal', true)
                ->count(),
            'the portal alert is still queued - a staff read neither delivered nor consumed it'
        );
    }

    /**
     * And the mirror: a portal page never picks up a staff-queued alert.
     */
    public static function test_a_portal_read_never_sees_a_staff_queued_alert()
    {
        static::__seed(static::$_session_a_id, 'staff message', false);
        static::__seed(static::$_session_a_id, 'portal message', true);

        $messages = static::__invoke_protected('_pending_for_session', [static::$_session_a_id, true]);

        static::__assert_count(1, $messages, 'only the portal-queued alert is delivered');
        static::__assert_equals('portal message', $messages[0]['message']);

        static::__assert_equals(
            1,
            Flash_Alert_Model::where('session_id', static::$_session_a_id)
                ->where('is_portal', false)
                ->count(),
            'the staff alert is still queued for the staff tab'
        );
    }

    /**
     * The one-minute expiry sweep carries the experience predicate too: a staff read
     * must not delete the portal's stale rows out from under it. (The age-based,
     * experience-blind sweep is the hourly Flash_Alert_Cleanup_Service task.)
     */
    public static function test_the_read_expiry_sweep_leaves_the_other_experience_alone()
    {
        $stale_portal = static::__seed(static::$_session_a_id, 'stale portal', true);
        Flash_Alert_Model::where('id', $stale_portal)
            ->raw_bulk()
            ->update(['created_at' => now()->subMinutes(5)]);

        static::__seed(static::$_session_a_id, 'staff message', false);

        static::__invoke_protected('_pending_for_session', [static::$_session_a_id, false]);

        static::__assert_equals(
            1,
            Flash_Alert_Model::where('id', $stale_portal)->count(),
            'the portal row survives a staff read, stale or not'
        );
    }

    /**
     * The public write path stamps the experience from the REQUEST, so the row a portal
     * request queues is readable only by a portal read.
     */
    public static function test_the_writer_stamps_the_requests_experience()
    {
        static::__reset_session();
        Rsx_Portal::set_portal_request(true);

        $session_id = (int) static::__invoke_protected('_resolve_session_id', []);
        $is_portal = Rsx_Portal::is_portal_request();

        static::__assert_true($is_portal, 'the request declares itself portal');

        // The console writers print instead of persisting (the CLI contract), so the
        // row is seeded with the experience the writer WOULD stamp.
        static::__seed($session_id, 'from a portal request', $is_portal);

        $staff_read = static::__invoke_protected('_pending_for_session', [$session_id, false]);
        static::__assert_count(0, $staff_read, 'a staff read finds nothing');

        $portal_read = static::__invoke_protected('_pending_for_session', [$session_id, true]);
        static::__assert_count(1, $portal_read);
        static::__assert_equals('from a portal request', $portal_read[0]['message']);
    }
}
