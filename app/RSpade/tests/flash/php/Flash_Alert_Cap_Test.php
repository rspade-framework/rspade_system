<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Flash\Php;

use ReflectionMethod;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Flash\Flash_Alert;
use App\RSpade\Lib\Flash\Flash_Alert_Model;

/**
 * Tests for the per-session flash-alert cap (rsx.flash.max_alerts_per_session).
 *
 * The cap lives on the WRITER on purpose. get_pending_messages() hands the whole set to the
 * browser and deletes it in the same breath, so a LIMIT on the READ would silently drop
 * alerts the user was supposed to see - the exact truncation the framework forbids. Bounding
 * what can be QUEUED keeps the read honest: it still returns everything that exists.
 *
 * The cap is reached through the protected _enforce_session_cap(), because Flash_Alert's
 * public writers return early in console context (there is no session to deliver to).
 *
 * The cap is keyed on (session_id, is_portal), not on the session alone: one browser has
 * one session shared by both experiences, and a shared cap would let a runaway portal page
 * evict the staff alerts sitting beside it.
 */
class Flash_Alert_Cap_Test extends Rsx_Test_Abstract
{
    /**
     * _flash_alerts carries an FK to _sessions, so the fixture needs REAL session rows -
     * two of them, to prove the cap never reaches across sessions.
     */
    private static int $_session_id = 0;

    private static int $_other_session_id = 0;

    public static function setup()
    {
        static::$_session_id = static::__make_session('flash-cap-a');
        static::$_other_session_id = static::__make_session('flash-cap-b');
    }

    public static function teardown()
    {
        // Flash rows cascade with the session.
        Session::whereIn('id', [static::$_session_id, static::$_other_session_id])
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

    private static function __seed(int $count, ?int $session_id = null, bool $is_portal = false): array
    {
        $session_id = $session_id ?? static::$_session_id;
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $alert = new Flash_Alert_Model();
            $alert->session_id = $session_id;
            $alert->is_portal = $is_portal;
            $alert->type_id = Flash_Alert_Model::TYPE_INFO;
            $alert->message = 'alert ' . $i;
            // Ascending created_at so "oldest" is unambiguous.
            $alert->created_at = now()->subMinutes($count - $i);
            $alert->save();

            $ids[] = (int) $alert->id;
        }

        return $ids;
    }

    private static function __enforce(?int $session_id = null, bool $is_portal = false): void
    {
        $session_id = $session_id ?? static::$_session_id;

        $method = new ReflectionMethod(Flash_Alert::class, '_enforce_session_cap');
        $method->setAccessible(true);
        $method->invokeArgs(null, [$session_id, $is_portal]);
    }

    private static function __pending(?int $session_id = null, bool $is_portal = false): int
    {
        return Flash_Alert_Model::where('session_id', $session_id ?? static::$_session_id)
            ->where('is_portal', $is_portal)
            ->count();
    }

    // =====================================================================

    public static function test_cap_trims_a_runaway_session_to_the_limit()
    {
        config(['rsx.flash.max_alerts_per_session' => 5]);
        static::__seed(12);

        static::__enforce();

        static::__assert_equals(5, static::__pending(), 'trimmed to the cap');
    }

    public static function test_cap_keeps_the_newest_and_drops_the_oldest()
    {
        config(['rsx.flash.max_alerts_per_session' => 3]);
        $ids = static::__seed(6);   // seeded oldest-first

        static::__enforce();

        $survivors = Flash_Alert_Model::where('session_id', static::$_session_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        static::__assert_count(3, $survivors);
        static::__assert_equals(
            array_slice($ids, -3),
            $survivors,
            'the NEWEST alerts survive - they describe what the user just did'
        );
    }

    public static function test_no_trim_when_under_the_cap()
    {
        config(['rsx.flash.max_alerts_per_session' => 50]);
        static::__seed(4);

        static::__enforce();

        static::__assert_equals(4, static::__pending(), 'a normal request is never trimmed');
    }

    public static function test_zero_disables_the_cap()
    {
        config(['rsx.flash.max_alerts_per_session' => 0]);
        static::__seed(9);

        static::__enforce();

        static::__assert_equals(9, static::__pending(), 'nothing is dropped when disabled');
    }

    public static function test_null_disables_the_cap()
    {
        config(['rsx.flash.max_alerts_per_session' => null]);
        static::__seed(9);

        static::__enforce();

        static::__assert_equals(9, static::__pending());
    }

    public static function test_cap_never_touches_another_session()
    {
        config(['rsx.flash.max_alerts_per_session' => 2]);
        static::__seed(6);
        static::__seed(6, static::$_other_session_id);

        static::__enforce();

        static::__assert_equals(2, static::__pending(), 'the acting session is trimmed');
        static::__assert_equals(
            6,
            static::__pending(static::$_other_session_id),
            'another session is never in scope'
        );
    }

    /**
     * The cap is an EVICTION policy, so sharing it across experiences would make a
     * runaway portal page delete the staff alerts queued on the same browser session.
     * One browser, one session, two independently-capped queues.
     */
    public static function test_cap_is_scoped_per_experience()
    {
        config(['rsx.flash.max_alerts_per_session' => 2]);
        static::__seed(6, static::$_session_id, true);    // the portal's runaway queue
        static::__seed(4, static::$_session_id, false);   // the staff alerts beside it

        static::__enforce(static::$_session_id, true);

        static::__assert_equals(
            2,
            static::__pending(static::$_session_id, true),
            'the portal queue is trimmed to the cap'
        );
        static::__assert_equals(
            4,
            static::__pending(static::$_session_id, false),
            'the staff alerts on the same session are untouched'
        );
    }
}
