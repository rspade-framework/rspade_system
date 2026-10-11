<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Session::find_by_token(),
 * Session::purge_playwright_sessions(), and the Session_Cleanup_Service hourly task
 * (identity-aware + per-type inactivity windows + chunked deletes).
 *
 * There is ONE session per browser and no realm column. A TYPE_WEB row is judged on
 * whether it carries an IDENTITY - login_user_id OR portal_user_id, since one row
 * serves both experiences - and the machine types keep their own short backstops. The
 * portal cases below exist because a rule that only looked at login_user_id would read
 * a portal-only browser session as anonymous and collect it far too soon.
 *
 * The sweeps perform DELETEs that must COMMIT to be observable, so
 * per-test transaction rollback is disabled and a DB reset runs first.
 */
class Session_Cleanup_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /**
     * Insert a _sessions row with the given age/identity and return its token.
     */
    private static function __insert_session(array $overrides = []): string
    {
        $token = bin2hex(random_bytes(16));
        $age_days = $overrides['age_days'] ?? 0;
        $when = now()->subDays($age_days);

        DB::table('_sessions')->insert([
            'session_token' => $token,
            'csrf_token'    => bin2hex(random_bytes(16)),
            'active'        => 1,
            'site_id'       => 0,
            'type_id'       => $overrides['type_id'] ?? Session::TYPE_WEB,
            'version'       => 1,
            'ip_address'    => '10.0.0.9',
            'user_agent'    => $overrides['user_agent'] ?? 'test-browser',
            'login_user_id' => $overrides['login_user_id'] ?? null,
            'portal_user_id' => $overrides['portal_user_id'] ?? null,
            'last_active'   => $overrides['last_active'] ?? $when,
            'created_at'    => $overrides['created_at'] ?? $when,
            'updated_at'    => $when,
        ]);

        return $token;
    }


    /**
     * Insert a _login_history row of the given age and return its id.
     */
    private static function __insert_login_history(int $age_days): int
    {
        return (int) DB::table('_login_history')->insertGetId([
            'login_user_id' => 4242,
            'email_attempted' => 'prune_' . uniqid() . '@example.com',
            'ip_address' => '10.0.0.11',
            'user_agent' => 'test-browser',
            'status' => 'success',
            'failure_reason' => null,
            'created_at' => now()->subDays($age_days),
        ]);
    }

    // -------------------------------------------------------------------------
    // find_by_token()
    // -------------------------------------------------------------------------

    public static function test_find_by_token_returns_null_for_unknown_token()
    {
        $result = Session::find_by_token('no-such-token-xyz-' . uniqid());
        static::__assert_null($result);
    }

    public static function test_find_by_token_finds_active_session()
    {
        $token = bin2hex(random_bytes(16));
        $csrf  = bin2hex(random_bytes(16));

        DB::table('_sessions')->insert([
            'session_token' => $token,
            'csrf_token'    => $csrf,
            'active'        => 1,
            'site_id'       => 0,
            'version'       => 1,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'last_active'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $session = Session::find_by_token($token);
        static::__assert_not_null($session);
        static::__assert_equals($token, $session->session_token);
    }

    public static function test_find_by_token_ignores_inactive_session()
    {
        $token = bin2hex(random_bytes(16));
        $csrf  = bin2hex(random_bytes(16));

        DB::table('_sessions')->insert([
            'session_token' => $token,
            'csrf_token'    => $csrf,
            'active'        => 0,
            'site_id'       => 0,
            'version'       => 1,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'last_active'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result = Session::find_by_token($token);
        static::__assert_null($result);
    }

    // -------------------------------------------------------------------------
    // Session_Cleanup_Service::cleanup_sessions() (the scheduled task)
    // -------------------------------------------------------------------------

    /**
     * A signed-in row is collected once it has been untouched for longer than the longer
     * of the two idle limits plus the Ajax grace. The cases below set both limits to 90
     * days, so a 40-day-old row separates "identified" from "anonymous" (30 days).
     */
    private static function __ninety_day_idle_limits(): void
    {
        config([
            'rsx.sessions.staff.max_idle_minutes' => 129600,
            'rsx.sessions.portal.max_idle_minutes' => 129600,
        ]);
    }

    public static function test_cleanup_sessions_task_applies_per_type_windows()
    {
        static::__ninety_day_idle_limits();

        // Beyond every window...
        $old_web = static::__insert_session(['age_days' => 200, 'login_user_id' => 12345]);
        $old_anonymous = static::__insert_session(['age_days' => 40]);
        // ...and within them (identified web 90 days here, anonymous 30 days).
        $mid_web = static::__insert_session(['age_days' => 40, 'login_user_id' => 12345]);
        $fresh_anonymous = static::__insert_session(['age_days' => 2]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['web'], 'web session past its window deleted');
        static::__assert_greater_than(0, $result['anonymous'], 'never-authenticated session past its window deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$old_web, $old_anonymous, $mid_web, $fresh_anonymous])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($old_web, $remaining, true), 'identified web session beyond the idle limit gone');
        static::__assert_false(in_array($old_anonymous, $remaining, true), 'anonymous beyond 30 days gone');
        static::__assert_true(in_array($mid_web, $remaining, true), 'identified web session within the idle limit kept (40d only passes the ANONYMOUS cutoff)');
        static::__assert_true(in_array($fresh_anonymous, $remaining, true), 'anonymous within 30 days kept');
    }

    public static function test_cleanup_sessions_task_expires_playwright_on_its_own_window()
    {
        // A PLAYWRIGHT (harness) session one day past its own window, and an authenticated
        // WEB session of the SAME age - which is nowhere near the 3-month web window. The
        // split proves the sweep reads the type column, not the age alone.
        $stale_harness = static::__insert_session([
            'type_id'       => Session::TYPE_PLAYWRIGHT,
            'login_user_id' => 12345,
            'age_days'      => 2,
        ]);
        $same_age_web = static::__insert_session([
            'login_user_id' => 12345,
            'age_days'      => 2,
        ]);
        // A harness session inside its window survives.
        $fresh_harness = static::__insert_session([
            'type_id'       => Session::TYPE_PLAYWRIGHT,
            'login_user_id' => 12345,
            'last_active'   => now()->subMinutes(10),
        ]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['playwright'], 'stale harness session deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$stale_harness, $same_age_web, $fresh_harness])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($stale_harness, $remaining, true), 'harness session past 1 day deleted');
        static::__assert_true(in_array($same_age_web, $remaining, true), 'web session of the same age untouched');
        static::__assert_true(in_array($fresh_harness, $remaining, true), 'recent harness session kept');
    }

    public static function test_cleanup_sessions_task_expires_cli_on_its_own_window()
    {
        // A CLI session normally deletes itself when its process ends; this window is
        // the backstop for a process that was killed first. Same shape as the harness
        // case above: a stale CLI row goes, a WEB row of the same age stays, and a CLI
        // row inside the window survives.
        $stale_cli = static::__insert_session([
            'type_id'       => Session::TYPE_CLI,
            'login_user_id' => 12345,
            'age_days'      => 2,
        ]);
        $same_age_web = static::__insert_session([
            'login_user_id' => 12345,
            'age_days'      => 2,
        ]);
        $fresh_cli = static::__insert_session([
            'type_id'       => Session::TYPE_CLI,
            'login_user_id' => 12345,
            'last_active'   => now()->subMinutes(10),
        ]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['cli'], 'stale CLI session deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$stale_cli, $same_age_web, $fresh_cli])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($stale_cli, $remaining, true), 'CLI session past 1 day deleted');
        static::__assert_true(in_array($same_age_web, $remaining, true), 'web session of the same age untouched');
        static::__assert_true(in_array($fresh_cli, $remaining, true), 'recent CLI session kept');
    }

    public static function test_cleanup_sessions_task_chunked_delete_clears_backlog()
    {
        // Backlog-recovery scenario: more deletable rows than one chunk. chunk_size 5
        // with 12 old rows forces 3 DELETE statements; every row must still go.
        $tokens = [];
        for ($i = 0; $i < 12; $i++) {
            $tokens[] = static::__insert_session(['age_days' => 40]);
        }

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions', ['chunk_size' => 5]);

        static::__assert_true($result['anonymous'] >= 12, 'all backlog rows deleted across chunks');

        $remaining = DB::table('_sessions')->whereIn('session_token', $tokens)->count();
        static::__assert_equals(0, $remaining, 'no backlog row survives the chunked delete');
    }

    // -------------------------------------------------------------------------
    // Session_Cleanup_Service::cleanup_login_history() (the retention prune)
    // -------------------------------------------------------------------------

    public static function test_cleanup_login_history_prunes_past_the_retention_window()
    {
        // Default retention is 365 days.
        $aged = static::__insert_login_history(400);
        $recent = static::__insert_login_history(10);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history');

        static::__assert_greater_than(0, $result['total_deleted']);
        static::__assert_equals(0, DB::table('_login_history')->where('id', $aged)->count(), 'the aged row is gone');
        static::__assert_equals(1, DB::table('_login_history')->where('id', $recent)->count(), 'a row inside the window is kept');
    }

    public static function test_cleanup_login_history_deletes_nothing_when_nothing_is_aged()
    {
        $recent = static::__insert_login_history(3);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history');

        static::__assert_equals(0, $result['total_deleted'], 'silent, and no rows touched');
        static::__assert_equals(1, DB::table('_login_history')->where('id', $recent)->count());
    }

    public static function test_cleanup_login_history_is_disabled_by_a_zero_retention()
    {
        $aged = static::__insert_login_history(400);
        $original = config('rsx.sessions.login_history_retention_days');

        try {
            config(['rsx.sessions.login_history_retention_days' => 0]);
            $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history');
        } finally {
            config(['rsx.sessions.login_history_retention_days' => $original]);
        }

        static::__assert_equals(0, $result['total_deleted']);
        static::__assert_equals(1, DB::table('_login_history')->where('id', $aged)->count(), '0 keeps rows forever');
    }

    public static function test_cleanup_login_history_clears_a_backlog_across_chunks()
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = static::__insert_login_history(400);
        }

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history', ['chunk_size' => 5]);

        static::__assert_true($result['total_deleted'] >= 12, 'all backlog rows deleted across chunks');
        static::__assert_equals(0, DB::table('_login_history')->whereIn('id', $ids)->count());
    }

    // -------------------------------------------------------------------------
    // Session::purge_playwright_sessions() (rsx:debug self-cleanup)
    // -------------------------------------------------------------------------

    public static function test_purge_playwright_sessions_takes_only_harness_rows_from_this_run()
    {
        $since = now()->subMinute();

        $run_harness = static::__insert_session(['type_id' => Session::TYPE_PLAYWRIGHT]);
        $run_web = static::__insert_session([]);
        // A harness row from another run, recent enough to still be in flight.
        $other_run_harness = static::__insert_session([
            'type_id'     => Session::TYPE_PLAYWRIGHT,
            'created_at'  => now()->subMinutes(5),
            'last_active' => now()->subSeconds(30),
        ]);

        $deleted = Session::purge_playwright_sessions($since->format('Y-m-d H:i:s.v'));

        static::__assert_greater_than(0, $deleted, 'the run\'s own harness session was deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$run_harness, $run_web, $other_run_harness])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($run_harness, $remaining, true), 'this run\'s harness session deleted');
        static::__assert_true(in_array($run_web, $remaining, true), 'a browser session created in the same window is never touched');
        static::__assert_true(in_array($other_run_harness, $remaining, true), 'a concurrent run\'s live harness session survives');
    }

    public static function test_purge_playwright_sessions_collects_stale_rows_from_earlier_runs()
    {
        // A harness row left behind by an earlier run - created before this run's window
        // and idle past the grace period. It must not wait out the playwright window.
        $abandoned = static::__insert_session([
            'type_id'     => Session::TYPE_PLAYWRIGHT,
            'created_at'  => now()->subHours(3),
            'last_active' => now()->subHours(3),
        ]);

        Session::purge_playwright_sessions(now()->format('Y-m-d H:i:s.v'));

        $count = DB::table('_sessions')->where('session_token', $abandoned)->count();
        static::__assert_equals(0, $count, 'abandoned harness session collected by the next run');
    }

    // -------------------------------------------------------------------------
    // A PORTAL identity counts as an identity
    // -------------------------------------------------------------------------

    /**
     * portal_user_id carries an FK to portal_users, so a real row is needed for any
     * test that puts a portal identity on a session.
     */
    private static function __make_portal_user_id(): int
    {
        $user = new Portal_User_Model();
        $user->site_id = 1;
        $user->email = 'cleanup_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return (int) $user->id;
    }

    private static function __insert_portal_session(array $overrides = []): string
    {
        return static::__insert_session($overrides + ['portal_user_id' => static::__make_portal_user_id()]);
    }

    public static function test_a_portal_identity_is_not_read_as_anonymous()
    {
        static::__ninety_day_idle_limits();

        // 40 days idle: past the 30-day anonymous window, well inside the 90-day
        // window an IDENTIFIED session gets here. Only the portal_user_id half of the
        // identity test saves it.
        $portal = static::__insert_portal_session(['age_days' => 40]);
        $anonymous = static::__insert_session(['age_days' => 40]);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$portal, $anonymous])
            ->pluck('session_token')
            ->all();

        static::__assert_true(in_array($portal, $remaining, true), 'a portal-only session is an IDENTIFIED session');
        static::__assert_false(in_array($anonymous, $remaining, true), 'the identity-less row of the same age IS collected');
    }

    public static function test_a_portal_identity_expires_on_the_idle_limit()
    {
        static::__ninety_day_idle_limits();

        $stale = static::__insert_portal_session(['age_days' => 200]);
        $fresh = static::__insert_portal_session(['age_days' => 40]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['web'], 'identified session past the idle limit deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$stale, $fresh])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($stale, $remaining, true), 'portal session beyond the idle limit gone');
        static::__assert_true(in_array($fresh, $remaining, true), 'portal session within the idle limit kept');
    }

    /**
     * The collection window follows the LONGER idle limit plus the Ajax grace: with the
     * limits at 30 days each, a signed-in row 29 days old is kept and one 31 days old goes.
     */
    public static function test_identified_rows_follow_the_configured_idle_limit()
    {
        config([
            'rsx.sessions.staff.max_idle_minutes' => 43200,
            'rsx.sessions.portal.max_idle_minutes' => 43200,
            'rsx.sessions.ajax_grace_minutes' => 120,
        ]);

        $inside = static::__insert_session(['age_days' => 29, 'login_user_id' => 12345]);
        $outside = static::__insert_session(['age_days' => 31, 'login_user_id' => 12345]);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        $remaining = DB::table('_sessions')->whereIn('session_token', [$inside, $outside])->pluck('session_token')->all();

        static::__assert_true(in_array($inside, $remaining, true), 'a signed-in row inside the idle limit is kept');
        static::__assert_false(in_array($outside, $remaining, true), 'a signed-in row past the idle limit and the grace is collected');
    }

    /**
     * An idle limit of 0 means a sign-in never goes idle, so no signed-in row may be
     * collected for its age - in either experience, because one row can carry both.
     */
    public static function test_an_unlimited_idle_window_collects_no_identified_row()
    {
        config([
            'rsx.sessions.staff.max_idle_minutes' => 43200,
            'rsx.sessions.portal.max_idle_minutes' => 0,
        ]);

        $ancient = static::__insert_session(['age_days' => 900, 'login_user_id' => 12345]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_equals(0, $result['web']);
        static::__assert_true(DB::table('_sessions')->where('session_token', $ancient)->exists(), 'the row is still there');
    }

    /**
     * A device record lives exactly as long as some session row still names it.
     */
    public static function test_a_device_no_row_names_is_collected()
    {
        $orphan = DB::table('_session_devices')->insertGetId(['user_agent' => 'orphan']);
        $in_use = DB::table('_session_devices')->insertGetId(['user_agent' => 'in use']);

        $token = static::__insert_session(['age_days' => 1, 'login_user_id' => 12345]);
        DB::table('_sessions')->where('session_token', $token)->update(['device_id' => $in_use]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['devices']);
        static::__assert_false(DB::table('_session_devices')->where('id', $orphan)->exists(), 'the orphan is gone');
        static::__assert_true(DB::table('_session_devices')->where('id', $in_use)->exists(), 'the device in use is kept');
    }

    public static function test_the_playwright_backstop_ignores_which_identity_a_row_carries()
    {
        $portal_harness = static::__insert_portal_session([
            'type_id'  => Session::TYPE_PLAYWRIGHT,
            'age_days' => 2,
        ]);
        $same_age_portal_web = static::__insert_portal_session(['age_days' => 2]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['playwright'], 'stale harness session deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$portal_harness, $same_age_portal_web])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($portal_harness, $remaining, true), 'a harness row past 1 day is deleted whatever it carries');
        static::__assert_true(in_array($same_age_portal_web, $remaining, true), 'a real browser session of the same age untouched');
    }

    public static function test_purge_playwright_sessions_collects_harness_rows_whatever_they_carry()
    {
        $since = now()->subMinute();

        $staff_harness = static::__insert_session(['type_id' => Session::TYPE_PLAYWRIGHT, 'login_user_id' => 12345]);
        $portal_harness = static::__insert_portal_session(['type_id' => Session::TYPE_PLAYWRIGHT]);
        $portal_web = static::__insert_portal_session([]);

        Session::purge_playwright_sessions($since->format('Y-m-d H:i:s.v'));

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$staff_harness, $portal_harness, $portal_web])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($staff_harness, $remaining, true), 'a staff-identity harness session deleted');
        static::__assert_false(in_array($portal_harness, $remaining, true), 'a portal-identity harness session deleted too - TYPE_PLAYWRIGHT is the whole predicate');
        static::__assert_true(in_array($portal_web, $remaining, true), 'a real browser session is never touched');
    }
}
