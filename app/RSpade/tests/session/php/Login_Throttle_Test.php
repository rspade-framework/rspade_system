<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_Throttle: the framework's brute-force defense on the authentication surface.
 *
 * ISOLATION. The throttle's state is two redis keys per IP, living outside the per-test
 * transaction and outside the process - so every test invents an ADDRESS nobody else uses
 * (documentation range 203.0.113.0/24), passes it explicitly, and registers it for the
 * teardown reset. Nothing here reads the ambient client IP except the two tests whose
 * SUBJECT is that there isn't one.
 *
 * TIME is never waited on. The lockout is a stored expiry instant, so "it expires" is
 * asserted as "retry_after_seconds is inside the configured lockout and counting down",
 * with the lockout configured down to one minute. A test that slept for the window would
 * buy nothing the arithmetic does not already prove.
 *
 * NOT COVERED HERE - RsxAuth::attempt() THROWING. attempt() throttles on
 * Session::get_client_ip(), which is null in CLI by design (no remote party, nothing to
 * throttle), so a PHP test can only prove the CLI half of that contract - which it does,
 * below. The throw itself is verified live over HTTP against /login and /_portal/login;
 * see tests/session/test_catalog.md. Same split, and the same reason, as $touch_last_login
 * in Rsx_Auth_Attempt_Test.
 */
class Login_Throttle_Test extends Rsx_Test_Abstract
{
    /**
     * Addresses touched by these tests, cleared in teardown.
     */
    private static array $ips_used = [];

    /**
     * The throttle config as the test process found it, restored in teardown.
     */
    private static ?array $original_config = null;

    public static function setup()
    {
        static::$original_config = config('rsx.sessions.login_throttle');
    }

    public static function teardown()
    {
        foreach (static::$ips_used as $ip) {
            Login_Throttle::reset($ip);
        }

        static::$ips_used = [];

        if (static::$original_config !== null) {
            config(['rsx.sessions.login_throttle' => static::$original_config]);
        }
    }

    /**
     * An address nothing else uses, registered for teardown. Documentation-range prefix plus a
     * unique suffix: the throttle keys on the STRING the request presented, so a synthetic one
     * guarantees no collision with a parallel run or with a real client.
     */
    private static function __fresh_ip(): string
    {
        $ip = '203.0.113.' . random_int(1, 254) . ':' . uniqid();
        static::$ips_used[] = $ip;

        return $ip;
    }

    /**
     * Put the throttle into a known configuration for one test.
     */
    private static function __configure(
        bool $enabled = true,
        int $attempts = 3,
        int $window_minutes = 15,
        int $lockout_minutes = 15
    ): void {
        config(['rsx.sessions.login_throttle' => [
            'enabled' => $enabled,
            'attempts' => $attempts,
            'window_minutes' => $window_minutes,
            'lockout_minutes' => $lockout_minutes,
        ]]);
    }

    // -------------------------------------------------------------------------
    // The threshold
    // -------------------------------------------------------------------------

    /**
     * The budget is spent ON the Nth failure, not before it: the first attempts-1 failures
     * cost the client nothing at all.
     */
    public static function test_failures_below_the_budget_do_not_throttle()
    {
        static::__configure(attempts: 3);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);
        Login_Throttle::record_failure($ip);

        static::__assert_equals(0, Login_Throttle::retry_after_seconds($ip), 'two of three is not locked out');

        Login_Throttle::require_not_throttled($ip);
        static::__assert_true(true, 'require_not_throttled() returns rather than throwing');
    }

    /**
     * The Nth failure locks the address out.
     */
    public static function test_the_budgeted_failure_locks_the_address_out()
    {
        static::__configure(attempts: 3);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);
        Login_Throttle::record_failure($ip);
        Login_Throttle::record_failure($ip);

        static::__assert_greater_than(0, Login_Throttle::retry_after_seconds($ip), 'the third failure locks');

        static::__assert_throws(
            Auth_Throttled_Exception::class,
            fn () => Login_Throttle::require_not_throttled($ip),
            "You're doing that too fast"
        );
    }

    /**
     * The message is the user-facing string, verbatim, and the exception carries the
     * remaining time for a caller that wants to log or header it.
     */
    public static function test_the_exception_carries_the_message_and_the_remaining_time()
    {
        static::__configure(attempts: 1, lockout_minutes: 15);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);

        $exception = static::__assert_throws(
            Auth_Throttled_Exception::class,
            fn () => Login_Throttle::require_not_throttled($ip)
        );

        static::__assert_equals("You're doing that too fast", $exception->getMessage());
        static::__assert_equals(Auth_Throttled_Exception::MESSAGE, $exception->getMessage());
        static::__assert_greater_than(0, $exception->retry_after_seconds, 'the wait is carried on the exception');
        static::__assert_less_than(
            15 * 60 + 1,
            $exception->retry_after_seconds,
            'and it never exceeds the configured lockout'
        );
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------

    /**
     * The lockout is a countdown, not a flag: retry_after_seconds() answers what is LEFT of
     * the configured lockout, so a one-minute lockout can never answer more than 60.
     */
    public static function test_the_lockout_counts_down_within_the_configured_minutes()
    {
        static::__configure(attempts: 1, lockout_minutes: 1);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);

        $remaining = Login_Throttle::retry_after_seconds($ip);

        static::__assert_greater_than(0, $remaining, 'locked out now');
        static::__assert_less_than(61, $remaining, 'and released within the configured minute');
    }

    /**
     * A cleared address is a released address - the operator/test escape.
     */
    public static function test_reset_releases_the_address()
    {
        static::__configure(attempts: 1);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);
        static::__assert_greater_than(0, Login_Throttle::retry_after_seconds($ip), 'locked');

        Login_Throttle::reset($ip);

        static::__assert_equals(0, Login_Throttle::retry_after_seconds($ip), 'released');
        Login_Throttle::require_not_throttled($ip);

        // The failure counter went with it, so the budget starts over rather than the very
        // next failure re-locking the address.
        Login_Throttle::record_failure($ip);
        static::__assert_greater_than(0, Login_Throttle::retry_after_seconds($ip), 'counted from zero again');
    }

    // -------------------------------------------------------------------------
    // Isolation
    // -------------------------------------------------------------------------

    /**
     * One attacker's address must never lock anyone else out - the whole feature would be a
     * denial-of-service tool if it did.
     */
    public static function test_the_lockout_is_per_ip()
    {
        static::__configure(attempts: 2);
        $attacker = static::__fresh_ip();
        $bystander = static::__fresh_ip();

        Login_Throttle::record_failure($attacker);
        Login_Throttle::record_failure($attacker);

        static::__assert_greater_than(0, Login_Throttle::retry_after_seconds($attacker), 'the attacker is locked');
        static::__assert_equals(0, Login_Throttle::retry_after_seconds($bystander), 'nobody else is');

        Login_Throttle::require_not_throttled($bystander);
    }

    // -------------------------------------------------------------------------
    // The switch
    // -------------------------------------------------------------------------

    /**
     * Disabled means nothing is counted and nothing is enforced - for a deployment that
     * throttles at the edge instead.
     */
    public static function test_disabled_counts_nothing_and_enforces_nothing()
    {
        static::__configure(enabled: false, attempts: 2);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);
        Login_Throttle::record_failure($ip);
        Login_Throttle::record_failure($ip);

        static::__assert_equals(0, Login_Throttle::retry_after_seconds($ip), 'nothing enforced');
        Login_Throttle::require_not_throttled($ip);

        // Nothing was counted either: turning the switch back on does not reveal a lockout
        // the disabled window secretly accumulated.
        static::__configure(enabled: true, attempts: 2);
        static::__assert_equals(0, Login_Throttle::retry_after_seconds($ip), 'nothing counted while off');
    }

    /**
     * The switch also releases an address locked out before it was thrown.
     */
    public static function test_disabling_releases_an_existing_lockout()
    {
        static::__configure(attempts: 1);
        $ip = static::__fresh_ip();

        Login_Throttle::record_failure($ip);
        static::__assert_greater_than(0, Login_Throttle::retry_after_seconds($ip), 'locked while enabled');

        static::__configure(enabled: false, attempts: 1);

        static::__assert_equals(0, Login_Throttle::retry_after_seconds($ip), 'the switch wins');
        Login_Throttle::require_not_throttled($ip);
    }

    // -------------------------------------------------------------------------
    // No client IP
    // -------------------------------------------------------------------------

    /**
     * A caller with no remote party is not a caller anyone can throttle. Session::get_client_ip()
     * is null in CLI, so record_failure() counts nothing and require_not_throttled() passes -
     * otherwise every command, task and test on the box would share one bucket and a fixture
     * loop would lock the framework's own tooling out.
     */
    public static function test_a_caller_with_no_client_ip_is_never_throttled()
    {
        static::__configure(attempts: 1);

        Login_Throttle::record_failure();
        Login_Throttle::record_failure();

        static::__assert_equals(0, Login_Throttle::retry_after_seconds(), 'nothing counted without an address');
        Login_Throttle::require_not_throttled();
        static::__assert_true(true, 'and nothing enforced');
    }

    /**
     * The consequence at the seam that matters: attempt() throttles first, but in CLI there is
     * no address, so the framework's own commands and tests still authenticate however many
     * times they need to.
     */
    public static function test_attempt_is_not_throttled_in_cli()
    {
        static::__configure(attempts: 1);

        Login_Throttle::record_failure();

        static::__assert_false(
            RsxAuth::attempt(['email' => 'nobody_' . uniqid() . '@example.com', 'password' => 'wrong']),
            'attempt() still answers the credential question rather than refusing'
        );
    }

    // -------------------------------------------------------------------------
    // The Login_History seam
    // -------------------------------------------------------------------------

    /**
     * Every recorded failure feeds the throttle - that is the wiring that makes an app's own
     * outcomes (a bad second factor, a disabled account) count without the app doing anything.
     * In CLI record_failure() resolves no address, so what is asserted here is that the call
     * is made and stays harmless; the counting half is asserted with explicit addresses above.
     */
    public static function test_login_history_failures_reach_the_throttle_harmlessly_in_cli()
    {
        static::__configure(attempts: 1);

        Login_History::record_failure(
            'throttle_seam_' . uniqid() . '@example.com',
            Login_History::STATUS_FAILED_2FA
        );

        static::__assert_equals(0, Login_Throttle::retry_after_seconds(), 'no address, no lockout');
    }
}
