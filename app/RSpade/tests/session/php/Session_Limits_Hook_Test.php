<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * APPLICATION-SUPPLIED SIGN-IN LIMITS: the session.limits resolve hook.
 *
 * Session::get_sign_in_limits($realm) is the one reader of a realm's max_length_minutes /
 * max_idle_minutes: the session.limits hook first (an array naming either limit or both,
 * each an integer >= 0), config('rsx.sessions.<realm>.<key>') for whatever it does not name.
 * The request check (Session::__expiry_of(), behind enforce_timeouts()) and the hourly sweep
 * (Session::_end_timed_out_user_sessions()) both read through it, so a supplied limit - shorter
 * or longer than the config - moves both together. A malformed answer throws; the hook is asked
 * once per realm until the memo is forgotten.
 *
 * The request check is reached through __expiry_of() by reflection: a CLI test has no browser,
 * and _timeout_expiry() reads the current browser's sign-in. Clocks are fixture timestamps
 * relative to now(); nothing waits. Handlers are test stand-ins
 * (Event_Registry::_set_test_handlers()), so the application's own never run here. The sweep
 * signs out through committed writes, so the class opts out of the per-test transaction.
 */
class Session_Limits_Hook_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /** The config the tests judge against: 7 days, 8 hours idle, 2 hours of Ajax grace. */
    private const CONFIG = [
        'rsx.sessions.staff' => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
        'rsx.sessions.portal' => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
        'rsx.sessions.ajax_grace_minutes' => 120,
    ];

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        Session::_forget_sign_in_limits();
    }

    /**
     * Run $fn with the known config and session.limits answered by $handler (null: no handler
     * registered), the memo forgotten on the way in and out.
     */
    private static function __with_limits(?callable $handler, callable $fn)
    {
        $original = config('rsx.sessions');
        Event_Registry::_set_test_handlers('session.limits', $handler === null ? [] : [$handler]);
        Session::_forget_sign_in_limits();

        try {
            config(self::CONFIG);

            return $fn();
        } finally {
            config(['rsx.sessions' => $original]);
            Event_Registry::_clear_test_handlers();
            Session::_forget_sign_in_limits();
        }
    }

    /** A staff-only answer: the given limits for 'staff', a decline for 'portal'. */
    private static function __staff_answer(array $answer): callable
    {
        return fn (array $data) => $data['realm'] === 'staff' ? $answer : null;
    }

    /** A staff sign-in whose last page view was $idle_minutes ago (signed in two days ago). */
    private static function __staff_sign_in(int $idle_minutes): array
    {
        return static::__insert_session_fixture([
            'staff' => [
                'login_user_id' => 12345,
                'site_id' => 1,
                'signed_in_at' => now()->subDays(2),
                'last_page_view_at' => now()->subMinutes($idle_minutes),
            ],
        ]);
    }

    /** The request check's verdict on a fixture's staff sign-in: 'idle' / 'length' when past a limit now, else null. */
    private static function __request_verdict(array $fixture): ?string
    {
        $row = DB::table('_user_sessions')->where('id', $fixture['staff_user_session_id'])->first();
        $expiry = (new \ReflectionMethod(Session::class, '__expiry_of'))->invoke(null, $row, 'staff');

        return ($expiry !== null && now()->gte($expiry['at'])) ? $expiry['reason'] : null;
    }

    private static function __sign_out_reason(array $fixture): ?int
    {
        $reason = DB::table('_user_sessions')->where('id', $fixture['staff_user_session_id'])->value('sign_out_reason_id');

        return $reason === null ? null : (int) $reason;
    }

    // -------------------------------------------------------------------------
    // Both readers follow a supplied limit, in both directions
    // -------------------------------------------------------------------------

    /**
     * A supplied idle limit SHORTER than the config (60 minutes against 480): the request check
     * ends a sign-in past it and keeps one within it; the sweep ends an abandoned one at the
     * limit plus the grace and leaves one still inside the grace.
     */
    public static function test_a_shorter_supplied_idle_limit_moves_both_readers()
    {
        $past_limit = static::__staff_sign_in(90);
        $within = static::__staff_sign_in(30);
        $past_grace = static::__staff_sign_in(200);

        static::__with_limits(static::__staff_answer(['max_idle_minutes' => 60]), function () use ($past_limit, $within, $past_grace) {
            static::__assert_equals('idle', static::__request_verdict($past_limit), 'request check: 90 minutes idle is past a 60-minute limit');
            static::__assert_null(static::__request_verdict($within), 'request check: 30 minutes idle is within it');

            static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');
        });

        static::__assert_equals(Session::SIGN_OUT_TIMED_OUT_IDLE, static::__sign_out_reason($past_grace), 'sweep: 200 minutes is past 60 + 120 minutes of grace');
        static::__assert_null(static::__sign_out_reason($past_limit), 'sweep: 90 minutes is past the limit but inside the grace');
        static::__assert_null(static::__sign_out_reason($within), 'sweep: within the limit');
    }

    /**
     * A supplied idle limit LONGER than the config (24 hours against 8): a sign-in 11 hours
     * idle - which the config alone would end at a request and in the sweep - is kept by both;
     * one 27 hours idle (past 24 hours + the grace) is ended by both.
     */
    public static function test_a_longer_supplied_idle_limit_moves_both_readers()
    {
        $eleven_hours = static::__staff_sign_in(11 * 60);
        $twenty_seven_hours = static::__staff_sign_in(27 * 60);

        static::__with_limits(static::__staff_answer(['max_idle_minutes' => 1440]), function () use ($eleven_hours, $twenty_seven_hours) {
            static::__assert_null(static::__request_verdict($eleven_hours), 'request check: 11 hours idle is within a 24-hour limit');
            static::__assert_equals('idle', static::__request_verdict($twenty_seven_hours), 'request check: 27 hours idle is past it');

            static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');
        });

        static::__assert_null(static::__sign_out_reason($eleven_hours), 'sweep: kept under the longer limit (the config alone would end it)');
        static::__assert_equals(Session::SIGN_OUT_TIMED_OUT_IDLE, static::__sign_out_reason($twenty_seven_hours), 'sweep: past 24 hours + the grace');
    }

    // -------------------------------------------------------------------------
    // Fallback per key, and no handler
    // -------------------------------------------------------------------------

    /** An answer naming one limit leaves the other at its config value; a realm declined is all config. */
    public static function test_an_unnamed_limit_comes_from_config()
    {
        static::__with_limits(static::__staff_answer(['max_idle_minutes' => 60]), function () {
            static::__assert_equals(
                ['max_length_minutes' => 10080, 'max_idle_minutes' => 60],
                Session::get_sign_in_limits('staff'),
                'idle supplied, length from config'
            );
            static::__assert_equals(
                ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
                Session::get_sign_in_limits('portal'),
                'the portal realm declined: both from config'
            );
        });

        static::__with_limits(static::__staff_answer([]), function () {
            static::__assert_equals(
                ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
                Session::get_sign_in_limits('staff'),
                'an empty answer names neither limit'
            );
        });
    }

    /** With no handler registered the limits are the config's, 0 (no limit) included. */
    public static function test_no_handler_reads_the_config()
    {
        static::__with_limits(null, function () {
            static::__assert_equals(['max_length_minutes' => 10080, 'max_idle_minutes' => 480], Session::get_sign_in_limits('staff'));

            config(['rsx.sessions.staff' => ['max_length_minutes' => 0, 'max_idle_minutes' => 0]]);
            static::__assert_equals(['max_length_minutes' => 0, 'max_idle_minutes' => 0], Session::get_sign_in_limits('staff'), 'the config is read on every call');
        });
    }

    /** A supplied 0 is "no limit", exactly as in the config. */
    public static function test_a_supplied_zero_is_no_limit()
    {
        $idle = static::__staff_sign_in(20 * 60);

        static::__with_limits(static::__staff_answer(['max_idle_minutes' => 0]), function () use ($idle) {
            static::__assert_null(static::__request_verdict($idle), 'no idle limit applies');
        });
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** Every malformed answer throws, naming the hook and the realm - never "no limit". */
    public static function test_a_malformed_answer_throws()
    {
        $malformed = [
            'negative' => ['max_idle_minutes' => -1],
            'numeric string' => ['max_idle_minutes' => '60'],
            'float' => ['max_length_minutes' => 60.0],
            'null value' => ['max_idle_minutes' => null],
            'unknown key' => ['ajax_grace_minutes' => 30],
            'list' => [60, 480],
        ];

        foreach ($malformed as $label => $answer) {
            static::__with_limits(static::__staff_answer($answer), function () use ($label) {
                $e = static::__assert_throws(\RuntimeException::class, fn () => Session::get_sign_in_limits('staff'), "session.limits handler's answer for realm 'staff'");
                static::__assert_contains('session.limits', $e->getMessage(), $label);
            });
        }

        static::__with_limits(fn (array $data) => 60, function () {
            static::__assert_throws(\RuntimeException::class, fn () => Session::get_sign_in_limits('portal'), "realm 'portal' must be an array");
        });
    }

    // -------------------------------------------------------------------------
    // Memoization
    // -------------------------------------------------------------------------

    /**
     * The hook is asked once per realm however many reads - the resolver, the request check and
     * a whole sweep over both realms - and again only after the memo is forgotten.
     */
    public static function test_the_hook_is_asked_once_per_realm()
    {
        $asked = ['staff' => 0, 'portal' => 0];
        $fixture = static::__staff_sign_in(90);

        static::__with_limits(function (array $data) use (&$asked) {
            $asked[$data['realm']]++;

            return ['max_idle_minutes' => 60];
        }, function () use ($fixture, &$asked) {
            for ($i = 0; $i < 5; $i++) {
                Session::get_sign_in_limits('staff');
            }
            static::__request_verdict($fixture);
            static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

            static::__assert_equals(['staff' => 1, 'portal' => 1], $asked, 'once per realm');

            Session::_forget_sign_in_limits();
            Session::get_sign_in_limits('staff');

            static::__assert_equals(2, $asked['staff'], 'asked again after the memo is forgotten');
        });
    }
}
