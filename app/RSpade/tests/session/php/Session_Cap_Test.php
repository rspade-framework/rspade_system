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
 * Sign-in is where a user's session count grows, so sign-in is where the cap is
 * applied: the N most recently active WEB sessions survive, everything older is
 * deactivated. The cap is invoked through the private __enforce_web_session_cap(),
 * reached here via reflection - calling set_login_user_id() would need a live web
 * request (it is a no-op under the CLI SAPI).
 *
 * Deactivation must COMMIT to be observable across the queries below, so per-test
 * transaction rollback is disabled and a DB reset runs first.
 */
class Session_Cap_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    // Each test gets its OWN login user: the DB reset is per-CLASS, so rows left by
    // an earlier test would otherwise count toward a later test's cap.
    private static int $_user_seq = 987000;

    private static function __next_user(): int
    {
        return ++self::$_user_seq;
    }

    /**
     * Insert one session row and return its token.
     *
     * @param int $login_user_id Owner of the session
     * @param int $minutes_idle How long ago it was last active (ordering handle)
     * @param array $overrides type_id, login_user_id, active
     */
    private static function __insert_session(int $login_user_id, int $minutes_idle, array $overrides = []): string
    {
        $token = bin2hex(random_bytes(16));
        $when = now()->subMinutes($minutes_idle);

        DB::table('_sessions')->insert([
            'session_token' => $token,
            'csrf_token'    => bin2hex(random_bytes(16)),
            'active'        => $overrides['active'] ?? 1,
            'site_id'       => 0,
            'type_id'       => $overrides['type_id'] ?? Session::TYPE_WEB,
            'version'       => 1,
            'ip_address'    => '10.0.0.9',
            'user_agent'    => 'test-browser',
            'login_user_id' => $overrides['login_user_id'] ?? $login_user_id,
            'last_active'   => $when,
            'created_at'    => $when,
            'updated_at'    => $when,
        ]);

        return $token;
    }

    private static function __enforce(int $login_user_id): void
    {
        $method = new \ReflectionMethod(Session::class, '__enforce_web_session_cap');
        $method->setAccessible(true);
        $method->invokeArgs(null, [$login_user_id]);
    }

    private static function __is_active(string $token): bool
    {
        return (bool) DB::table('_sessions')->where('session_token', $token)->value('active');
    }

    // =====================================================================
    // The cap itself
    // =====================================================================

    public static function test_cap_keeps_the_n_most_recent_and_signs_out_the_rest()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 3]);
        $user = static::__next_user();

        // Five web sessions, newest first by construction.
        $newest = static::__insert_session($user, 1);
        $second = static::__insert_session($user, 2);
        $third  = static::__insert_session($user, 3);
        $fourth = static::__insert_session($user, 4);
        $oldest = static::__insert_session($user, 5);

        static::__enforce($user);

        static::__assert_true(static::__is_active($newest), 'most recent session kept');
        static::__assert_true(static::__is_active($second), '2nd most recent kept');
        static::__assert_true(static::__is_active($third), '3rd most recent kept (the cap)');
        static::__assert_false(static::__is_active($fourth), '4th most recent signed out');
        static::__assert_false(static::__is_active($oldest), 'oldest signed out');
    }

    public static function test_cap_is_a_noop_when_under_the_limit()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 25]);
        $user = static::__next_user();

        $a = static::__insert_session($user, 1);
        $b = static::__insert_session($user, 2);

        static::__enforce($user);

        static::__assert_true(static::__is_active($a));
        static::__assert_true(static::__is_active($b));
    }

    // =====================================================================
    // Disabling
    // =====================================================================

    public static function test_zero_disables_the_cap_entirely()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 0]);
        $user = static::__next_user();

        $tokens = [];
        for ($i = 1; $i <= 5; $i++) {
            $tokens[] = static::__insert_session($user, $i);
        }

        static::__enforce($user);

        foreach ($tokens as $token) {
            static::__assert_true(static::__is_active($token), 'nothing is signed out when the cap is 0');
        }
    }

    public static function test_null_disables_the_cap_entirely()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => null]);
        $user = static::__next_user();

        $tokens = [];
        for ($i = 1; $i <= 5; $i++) {
            $tokens[] = static::__insert_session($user, $i);
        }

        static::__enforce($user);

        foreach ($tokens as $token) {
            static::__assert_true(static::__is_active($token), 'nothing is signed out when the cap is null');
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

        $mine = static::__insert_session($user, 1);
        $mine_older = static::__insert_session($user, 2);
        $theirs = static::__insert_session($other_user, 9);

        static::__enforce($user);

        static::__assert_true(static::__is_active($mine), 'my most recent kept');
        static::__assert_false(static::__is_active($mine_older), 'my older session signed out');
        static::__assert_true(static::__is_active($theirs), 'another user is never in scope');
    }

    public static function test_cap_never_counts_or_evicts_machine_sessions()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);
        $user = static::__next_user();

        // Two harness sessions that are the MOST recent of all. If they counted
        // toward the cap they would consume both slots and sign out both browsers.
        $harness_a = static::__insert_session($user, 1, ['type_id' => Session::TYPE_PLAYWRIGHT]);
        $harness_b = static::__insert_session($user, 2, ['type_id' => Session::TYPE_PLAYWRIGHT]);
        $api = static::__insert_session($user, 3, ['type_id' => Session::TYPE_API]);

        $web_new = static::__insert_session($user, 4);
        $web_old = static::__insert_session($user, 5);

        static::__enforce($user);

        static::__assert_true(static::__is_active($harness_a), 'a test run is never signed out by a login');
        static::__assert_true(static::__is_active($harness_b), 'a test run is never signed out by a login');
        static::__assert_true(static::__is_active($api), 'an API session is never signed out by a login');
        static::__assert_true(static::__is_active($web_new), 'both web sessions fit the cap of 2...');
        static::__assert_true(static::__is_active($web_old), '...because machine sessions did not consume the slots');
    }
}
