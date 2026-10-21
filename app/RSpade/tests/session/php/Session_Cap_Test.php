<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the concurrent-session cap (rsx.sessions.max_web_sessions_per_user).
 *
 * Sign-in is where an account's session count grows, so sign-in is where the cap is
 * applied: the N most recently active SIGNED-IN user sessions of the account on WEB
 * browsers survive, everything older is signed out (SIGN_OUT_SESSION_CAP) and its browser's
 * pointer cleared - the browser row itself is untouched. The cap is invoked through
 * Session::_enforce_session_cap(), the function both sign-in paths call - calling
 * set_login_user_id() would need a live web request (it is a declaration under the CLI SAPI).
 *
 * Sign-outs must COMMIT to be observable across the queries below, so per-test transaction
 * rollback is disabled and a DB reset runs first.
 */
class Session_Cap_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    // Each test gets its OWN login user: the DB reset is per-CLASS, so rows left by an
    // earlier test would otherwise count toward a later test's cap.
    private static int $_user_seq = 987000;

    private static function __next_user(): int
    {
        return ++self::$_user_seq;
    }

    /**
     * A browser signed in as $login_user_id, last active $minutes_idle ago. Answers the
     * fixture (browser id + user session id).
     */
    private static function __sign_in(int $login_user_id, int $minutes_idle, array $browser = []): array
    {
        $when = now()->subMinutes($minutes_idle);

        return static::__insert_session_fixture(array_merge($browser, [
            'last_active' => $when,
            'staff' => ['login_user_id' => $login_user_id, 'site_id' => 1, 'last_active_at' => $when],
        ]));
    }

    private static function __enforce(int $login_user_id): void
    {
        Session::_enforce_session_cap(Session::REALM_STAFF, $login_user_id);
    }

    private static function __is_signed_in(array $fixture): bool
    {
        $user_session = DB::table('_user_sessions')->where('id', $fixture['staff_user_session_id'])->first();
        $pointer = DB::table('_sessions')->where('id', $fixture['id'])->value('staff_user_session_id');

        return $user_session->signed_out_at === null && (int) $pointer === (int) $fixture['staff_user_session_id'];
    }

    // =====================================================================
    // The cap itself
    // =====================================================================

    public static function test_cap_keeps_the_n_most_recent_and_signs_out_the_rest()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 3]);
        $user = static::__next_user();

        // Five browsers, newest first by construction.
        $newest = static::__sign_in($user, 1);
        $second = static::__sign_in($user, 2);
        $third = static::__sign_in($user, 3);
        $fourth = static::__sign_in($user, 4);
        $oldest = static::__sign_in($user, 5);

        static::__enforce($user);

        static::__assert_true(static::__is_signed_in($newest), 'most recent session kept');
        static::__assert_true(static::__is_signed_in($second), '2nd most recent kept');
        static::__assert_true(static::__is_signed_in($third), '3rd most recent kept (the cap)');
        static::__assert_false(static::__is_signed_in($fourth), '4th most recent signed out');
        static::__assert_false(static::__is_signed_in($oldest), 'oldest signed out');

        static::__assert_equals(
            Session::SIGN_OUT_SESSION_CAP,
            (int) DB::table('_user_sessions')->where('id', $oldest['staff_user_session_id'])->value('sign_out_reason_id'),
            'an evicted sign-in records the cap as its reason'
        );
        static::__assert_equals(
            1,
            (int) DB::table('_sessions')->where('id', $oldest['id'])->value('active'),
            'the evicted browser row itself stays active - only its sign-in ended'
        );
    }

    public static function test_cap_is_a_noop_when_under_the_limit()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 25]);
        $user = static::__next_user();

        $a = static::__sign_in($user, 1);
        $b = static::__sign_in($user, 2);

        static::__enforce($user);

        static::__assert_true(static::__is_signed_in($a));
        static::__assert_true(static::__is_signed_in($b));
    }

    // =====================================================================
    // Disabling
    // =====================================================================

    public static function test_zero_disables_the_cap_entirely()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 0]);
        $user = static::__next_user();

        $fixtures = [];
        for ($i = 1; $i <= 5; $i++) {
            $fixtures[] = static::__sign_in($user, $i);
        }

        static::__enforce($user);

        foreach ($fixtures as $fixture) {
            static::__assert_true(static::__is_signed_in($fixture), 'nothing is signed out when the cap is 0');
        }
    }

    public static function test_null_disables_the_cap_entirely()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => null]);
        $user = static::__next_user();

        $fixtures = [];
        for ($i = 1; $i <= 5; $i++) {
            $fixtures[] = static::__sign_in($user, $i);
        }

        static::__enforce($user);

        foreach ($fixtures as $fixture) {
            static::__assert_true(static::__is_signed_in($fixture), 'nothing is signed out when the cap is null');
        }
    }

    // =====================================================================
    // Scope - who the cap may NOT touch
    // =====================================================================

    public static function test_cap_never_touches_another_users_sessions()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 1]);
        $user = static::__next_user();
        $other_user = static::__next_user();

        $mine = static::__sign_in($user, 1);
        $mine_older = static::__sign_in($user, 2);
        $theirs = static::__sign_in($other_user, 9);

        static::__enforce($user);

        static::__assert_true(static::__is_signed_in($mine), 'my most recent kept');
        static::__assert_false(static::__is_signed_in($mine_older), 'my older session signed out');
        static::__assert_true(static::__is_signed_in($theirs), 'another user is never in scope');
    }

    public static function test_cap_never_counts_or_evicts_machine_sessions()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);
        $user = static::__next_user();

        // Two harness browsers that are the MOST recent of all. If they counted toward the
        // cap they would consume both slots and sign out both real browsers.
        $harness_a = static::__sign_in($user, 1, ['type_id' => Session::TYPE_PLAYWRIGHT]);
        $harness_b = static::__sign_in($user, 2, ['type_id' => Session::TYPE_PLAYWRIGHT]);
        $api = static::__sign_in($user, 3, ['type_id' => Session::TYPE_API]);

        $web_new = static::__sign_in($user, 4);
        $web_old = static::__sign_in($user, 5);

        static::__enforce($user);

        static::__assert_true(static::__is_signed_in($harness_a), 'a test run is never signed out by a login');
        static::__assert_true(static::__is_signed_in($harness_b), 'a test run is never signed out by a login');
        static::__assert_true(static::__is_signed_in($api), 'an API session is never signed out by a login');
        static::__assert_true(static::__is_signed_in($web_new), 'both web sessions fit the cap of 2...');
        static::__assert_true(static::__is_signed_in($web_old), '...because machine sessions did not consume the slots');
    }

    public static function test_cap_never_counts_an_impersonation_of_the_account()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 1]);
        $user = static::__next_user();
        $developer = static::__next_user();

        // Somebody else acting as this account, more recently than the account itself.
        $impersonation = static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => $developer, 'site_id' => 1],
            'staff' => ['login_user_id' => $user, 'site_id' => 1, 'impersonator_login_user_id' => $developer],
        ]);
        $own = static::__sign_in($user, 5);

        static::__enforce($user);

        static::__assert_true(static::__is_signed_in($own), "the account's own sign-in is the only one counted");
        static::__assert_true(static::__is_signed_in($impersonation), 'an impersonation of the account is not evicted by its cap');
    }
}
