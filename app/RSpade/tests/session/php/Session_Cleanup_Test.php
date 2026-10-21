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
 * Tests for Session::find_by_token() and the Session_Cleanup_Service sweeps: the hourly guest
 * cleanup (chunked deletes), the daily retirement of unused browsers, and the retention
 * prunes.
 *
 * There is ONE session per browser. A TYPE_WEB row is deleted only when nobody has EVER
 * signed in on it - _sessions.first_signed_in_at is null - and it has gone unused for
 * rsx.sessions.guest_expiry_minutes; a browser that has had a sign-in is never deleted (its
 * user sessions are the record of where an account signed in), and neither is a machine
 * row. It is made inactive instead once unused for rsx.sessions.unused_session_days. The
 * portal cases exist because a rule that only looked at the staff realm would read a
 * portal-only browser as a guest's.
 *
 * The sweeps perform DELETEs that must COMMIT to be observable, so
 * per-test transaction rollback is disabled and a DB reset runs first.
 */
class Session_Cleanup_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /**
     * Insert a browser row of the given age - with a staff sign-in (login_user_id), a portal
     * one (portal_user_id), or none - and return its token. 'signed_out' records the sign-in
     * as already ended.
     */
    private static function __insert_session(array $overrides = []): string
    {
        $when = now()->subDays($overrides['age_days'] ?? 0);
        $sign_out = !empty($overrides['signed_out'])
            ? ['signed_out_at' => $when, 'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT]
            : [];

        $options = [
            'type_id' => $overrides['type_id'] ?? Session::TYPE_WEB,
            'last_active' => $overrides['last_active'] ?? $when,
            'created_at' => $overrides['created_at'] ?? $when,
        ];

        if (isset($overrides['login_user_id'])) {
            $options['staff'] = array_merge(['login_user_id' => $overrides['login_user_id'], 'site_id' => 1, 'last_active_at' => $when], $sign_out);
        }

        if (isset($overrides['portal_user_id'])) {
            $options['portal'] = array_merge(['portal_user_id' => $overrides['portal_user_id'], 'portal_site_id' => 1, 'last_active_at' => $when], $sign_out);
        }

        return static::__insert_session_fixture($options)['token'];
    }


    /**
     * Insert a _login_history row of the given age and return its id.
     */
    private static function __insert_login_history(int $age_days): int
    {
        return (int) DB::table('_login_history')->insertGetId([
            'login_user_id' => 4242,
            'email_attempted' => 'prune_' . uniqid() . '@example.com',
            'ip_address_id' => \App\RSpade\Core\Session\Ip_Address::id('10.0.0.11'),
            'user_agent_id' => \App\RSpade\Core\Session\Audit_Session::user_agent_id('test-browser'),
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
            'version'       => 1,
            'ip_address_id' => \App\RSpade\Core\Session\Ip_Address::id('127.0.0.1'),
            'user_agent_id'    => \App\RSpade\Core\Session\Audit_Session::user_agent_id('test'),
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
            'version'       => 1,
            'ip_address_id' => \App\RSpade\Core\Session\Ip_Address::id('127.0.0.1'),
            'user_agent_id'    => \App\RSpade\Core\Session\Audit_Session::user_agent_id('test'),
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

    public static function test_cleanup_sessions_task_deletes_only_guests_past_their_window()
    {
        // Past the 30-day guest window: a guest's browser, and browsers that HAVE had a
        // sign-in (signed in now, or long since signed out).
        $old_guest = static::__insert_session(['age_days' => 40]);
        $old_signed_in = static::__insert_session(['age_days' => 200, 'login_user_id' => 12345]);
        $old_signed_out = static::__insert_session(['age_days' => 200, 'login_user_id' => 12345, 'signed_out' => true]);
        // Within it.
        $fresh_guest = static::__insert_session(['age_days' => 2]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_greater_than(0, $result['guest_deleted'], 'a guest browser past its window deleted');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$old_guest, $old_signed_in, $old_signed_out, $fresh_guest])
            ->pluck('session_token')
            ->all();

        static::__assert_false(in_array($old_guest, $remaining, true), 'guest beyond 30 days gone');
        static::__assert_true(in_array($old_signed_in, $remaining, true), 'a browser with a sign-in is never deleted here');
        static::__assert_true(in_array($old_signed_out, $remaining, true), 'nor one whose sign-in has ended: it is the history');
        static::__assert_true(in_array($fresh_guest, $remaining, true), 'guest within 30 days kept');
    }

    /**
     * The guest predicate is the browser's own first_signed_in_at, not a look at its user
     * sessions: a browser stamped as signed in once is kept even with no user session row
     * left, and a guest's session values go with it.
     */
    public static function test_cleanup_sessions_task_reads_first_signed_in_at()
    {
        $stamped = static::__insert_session(['age_days' => 40]);
        DB::table('_sessions')->where('session_token', $stamped)->update(['first_signed_in_at' => now()->subDays(50)]);

        $guest = static::__insert_session(['age_days' => 40]);
        $guest_id = (int) DB::table('_sessions')->where('session_token', $guest)->value('id');
        DB::table('_session_values')->insert(['session_id' => $guest_id, 'realm_id' => Session::REALM_GUEST, 'value_key' => 'k', 'value' => '1']);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_equals(1, DB::table('_sessions')->where('session_token', $stamped)->count(), 'a browser that once had a sign-in is kept');
        static::__assert_equals(0, DB::table('_sessions')->where('session_token', $guest)->count(), 'the guest is deleted');
        static::__assert_equals(0, DB::table('_session_values')->where('session_id', $guest_id)->count(), 'with its values');
    }

    /**
     * The sweep deletes guests only. A harness row is reused by every rsx:debug run of its
     * account, an API key's row lives as long as the key, and a command has no row at all -
     * so an old harness row with no sign-in left on it is not a guest's, and stays.
     */
    public static function test_cleanup_sessions_task_leaves_harness_rows_alone()
    {
        $old_harness = static::__insert_session([
            'type_id'  => Session::TYPE_PLAYWRIGHT,
            'age_days' => 400,
        ]);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        static::__assert_equals(1, DB::table('_sessions')->where('session_token', $old_harness)->count(), 'a harness row is never swept as a guest');
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

        static::__assert_true($result['guest_deleted'] >= 12, 'all backlog rows deleted across chunks');

        $remaining = DB::table('_sessions')->whereIn('session_token', $tokens)->count();
        static::__assert_equals(0, $remaining, 'no backlog row survives the chunked delete');
    }

    // -------------------------------------------------------------------------
    // cleanup_sessions(): sign-ins past their limits
    // -------------------------------------------------------------------------

    /**
     * Run cleanup_sessions under known limits: 7 days from the sign-in, 8 hours idle and a
     * 2-hour Ajax grace, in both realms.
     */
    private static function __run_cleanup_with_limits(array $params = []): array
    {
        $original = config('rsx.sessions');

        try {
            config([
                'rsx.sessions.staff' => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
                'rsx.sessions.portal' => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
                'rsx.sessions.ajax_grace_minutes' => 120,
            ]);

            return static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions', $params);
        } finally {
            config(['rsx.sessions' => $original]);
        }
    }

    /**
     * A sign-in whose browser never came back is signed out once it is past a limit PLUS the
     * Ajax grace, under the reason a request would have recorded: the pointer is cleared,
     * the realm's values go, the browser keeps its token. One still inside the grace, and
     * one inside its limits, stay signed in.
     */
    public static function test_cleanup_sessions_signs_out_sign_ins_past_their_limits()
    {
        $length = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'signed_in_at' => now()->subDays(8), 'last_page_view_at' => now()->subMinutes(5)],
        ]);
        $idle = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'signed_in_at' => now()->subDay(), 'last_page_view_at' => now()->subHours(11)],
        ]);
        $in_grace = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'signed_in_at' => now()->subDay(), 'last_page_view_at' => now()->subHours(9)],
        ]);
        $fresh = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1],
        ]);
        DB::table('_session_values')->insert([
            ['session_id' => $idle['id'], 'realm_id' => Session::REALM_STAFF, 'value_key' => 'wizard_step', 'value' => '3'],
            ['session_id' => $idle['id'], 'realm_id' => Session::REALM_GUEST, 'value_key' => 'banner', 'value' => '1'],
        ]);

        $result = static::__run_cleanup_with_limits();

        static::__assert_true($result['timed_out_signed_out'] >= 2, 'both expired sign-ins counted');

        $user_session = fn (array $fixture) => DB::table('_user_sessions')->where('id', $fixture['staff_user_session_id'])->first();

        static::__assert_equals(Session::SIGN_OUT_TIMED_OUT_LENGTH, (int) $user_session($length)->sign_out_reason_id, 'past 7 days + grace from the sign-in');
        static::__assert_equals(Session::SIGN_OUT_TIMED_OUT_IDLE, (int) $user_session($idle)->sign_out_reason_id, 'past 8 hours + grace from the last page view');
        static::__assert_null($user_session($in_grace)->signed_out_at, 'past the idle limit but inside the Ajax grace: an open page may still save');
        static::__assert_null($user_session($fresh)->signed_out_at, 'inside its limits');

        static::__assert_null(DB::table('_sessions')->where('id', $idle['id'])->value('staff_user_session_id'), 'the browser points at nobody');
        static::__assert_equals($idle['token'], DB::table('_sessions')->where('id', $idle['id'])->value('session_token'), 'a remote browser keeps its token');
        static::__assert_equals(0, DB::table('_session_values')->where('session_id', $idle['id'])->where('realm_id', Session::REALM_STAFF)->count(), 'the staff values went with the sign-in');
        static::__assert_equals(1, DB::table('_session_values')->where('session_id', $idle['id'])->where('realm_id', Session::REALM_GUEST)->count(), 'the guest values stay');
    }

    /**
     * Found by either clock, a sign-in is recorded under the EARLIER expiry, as a request
     * would record it: here the idle limit passed days before the sign-in limit did.
     */
    public static function test_cleanup_sessions_records_the_earlier_expiry()
    {
        $both = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'signed_in_at' => now()->subDays(7)->subHours(3), 'last_page_view_at' => now()->subDays(7)->subHours(2)],
        ]);

        static::__run_cleanup_with_limits();

        static::__assert_equals(
            Session::SIGN_OUT_TIMED_OUT_IDLE,
            (int) DB::table('_user_sessions')->where('id', $both['staff_user_session_id'])->value('sign_out_reason_id')
        );
    }

    /**
     * Only the sign-in a browser POINTS AT runs its clocks: the impersonator's own sign-in,
     * suspended behind a live impersonation, is not signed out for idleness while the
     * impersonation is in use. An impersonation past its limits takes that sign-in with it.
     */
    public static function test_cleanup_sessions_judges_only_the_current_sign_in()
    {
        $live = static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => 777, 'site_id' => 1, 'last_page_view_at' => now()->subDays(2)],
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'impersonator_login_user_id' => 777],
        ]);
        $expired = static::__insert_session_fixture([
            'staff_own' => ['login_user_id' => 778, 'site_id' => 1, 'last_page_view_at' => now()->subDays(2)],
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'impersonator_login_user_id' => 778, 'last_page_view_at' => now()->subHours(11)],
        ]);

        static::__run_cleanup_with_limits();

        $signed_out_at = fn ($id) => DB::table('_user_sessions')->where('id', $id)->value('signed_out_at');

        static::__assert_null($signed_out_at($live['staff_own_user_session_id']), 'a suspended sign-in is judged when the browser returns to it');
        static::__assert_null($signed_out_at($live['staff_user_session_id']), 'the live impersonation stays');
        static::__assert_not_null($signed_out_at($expired['staff_user_session_id']), 'an idle impersonation is signed out');
        static::__assert_not_null($signed_out_at($expired['staff_own_user_session_id']), 'with the impersonator\'s own sign-in behind it');
    }

    /**
     * The portal realm runs the same sweep on its own limits, and a backlog larger than one
     * batch clears.
     */
    public static function test_cleanup_sessions_times_out_portal_sign_ins_across_batches()
    {
        $portal_user_id = static::__make_portal_user_id();
        $fixtures = [];

        for ($i = 0; $i < 7; $i++) {
            $fixtures[] = static::__insert_session_fixture([
                'portal' => ['portal_user_id' => $portal_user_id, 'portal_site_id' => 1, 'last_page_view_at' => now()->subHours(12)],
            ]);
        }

        static::__run_cleanup_with_limits(['chunk_size' => 3]);

        $ids = array_column($fixtures, 'portal_user_session_id');

        static::__assert_equals(7, DB::table('_user_sessions')->whereIn('id', $ids)->where('sign_out_reason_id', Session::SIGN_OUT_TIMED_OUT_IDLE)->count(), 'every portal sign-in past its idle limit, across batches');
        static::__assert_equals(0, DB::table('_sessions')->whereIn('id', array_column($fixtures, 'id'))->whereNotNull('portal_user_session_id')->count(), 'no browser still points at one');
    }

    /** A limit of 0 is no limit: the sweep reads nothing for that clock. */
    public static function test_cleanup_sessions_leaves_sign_ins_alone_when_the_limits_are_off()
    {
        $old = static::__insert_session_fixture([
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'signed_in_at' => now()->subDays(60), 'last_page_view_at' => now()->subDays(20)],
        ]);
        $original = config('rsx.sessions.staff');

        try {
            config(['rsx.sessions.staff' => ['max_length_minutes' => 0, 'max_idle_minutes' => 0]]);
            static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');
        } finally {
            config(['rsx.sessions.staff' => $original]);
        }

        static::__assert_null(DB::table('_user_sessions')->where('id', $old['staff_user_session_id'])->value('signed_out_at'));
    }

    // -------------------------------------------------------------------------
    // Session_Cleanup_Service::deactivate_unused_sessions() (the daily retirement)
    // -------------------------------------------------------------------------

    /**
     * A browser unused past rsx.sessions.unused_session_days (30) is made inactive - signed-in
     * history or not - and never deleted; a browser in use, and an API key's row however old,
     * stay active.
     */
    public static function test_deactivate_unused_sessions_retires_only_browsers_past_the_window()
    {
        $old_signed_in = static::__insert_session(['age_days' => 40, 'login_user_id' => 12345]);
        $old_guest = static::__insert_session(['age_days' => 31]);
        $fresh = static::__insert_session(['age_days' => 2, 'login_user_id' => 12345]);
        $old_api = static::__insert_session(['age_days' => 90, 'type_id' => Session::TYPE_API]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'deactivate_unused_sessions');

        $active = fn (string $token) => (int) DB::table('_sessions')->where('session_token', $token)->value('active');

        static::__assert_true($result['deactivated'] >= 2, 'both browsers past the window counted');
        static::__assert_equals(0, $active($old_signed_in), 'a browser unused for 40 days is inactive');
        static::__assert_equals(0, $active($old_guest), 'a guest unused for 31 days is inactive');
        static::__assert_equals(1, $active($fresh), 'a browser in use stays active');
        static::__assert_equals(1, $active($old_api), 'an API key\'s row is not a browser and is left alone');
        static::__assert_equals(1, DB::table('_sessions')->where('session_token', $old_signed_in)->count(), 'retired, never deleted');
    }

    /**
     * Retiring a browser signs out what is still signed in on it (SIGN_OUT_BROWSER_UNUSED): the
     * user session leaves the "signed in" set, the pointer is cleared and the realm's values go
     * with it. A sign-in on a browser still in use is untouched.
     */
    public static function test_deactivate_unused_sessions_signs_out_what_is_signed_in_there()
    {
        $old = static::__insert_session_fixture([
            'last_active' => now()->subDays(40),
            'staff' => ['login_user_id' => 12345, 'site_id' => 1, 'last_active_at' => now()->subDays(40)],
        ]);
        $fresh = static::__insert_session_fixture([
            'last_active' => now()->subDays(2),
            'staff' => ['login_user_id' => 12345, 'site_id' => 1],
        ]);
        DB::table('_session_values')->insert([
            'session_id' => $old['id'],
            'realm_id' => Session::REALM_STAFF,
            'value_key' => 'wizard_step',
            'value' => json_encode(3),
        ]);

        static::__run_task_method(Session_Cleanup_Service::class, 'deactivate_unused_sessions');

        $retired = DB::table('_user_sessions')->where('id', $old['staff_user_session_id'])->first();
        static::__assert_not_null($retired->signed_out_at, 'the sign-in on the unused browser is signed out');
        static::__assert_equals(Session::SIGN_OUT_BROWSER_UNUSED, (int) $retired->sign_out_reason_id);
        static::__assert_null(DB::table('_sessions')->where('id', $old['id'])->value('staff_user_session_id'), 'the browser points at nobody');
        static::__assert_equals(0, DB::table('_session_values')->where('session_id', $old['id'])->count(), 'the realm values went with the sign-in');
        static::__assert_equals(0, (int) DB::table('_sessions')->where('id', $old['id'])->value('active'));

        static::__assert_null(DB::table('_user_sessions')->where('id', $fresh['staff_user_session_id'])->value('signed_out_at'), 'a browser in use keeps its sign-in');
        static::__assert_not_contains_signed_in($old['staff_user_session_id']);

        $days = (int) config('rsx.sessions.unused_session_days');
        static::__assert_equals("Browser unused for {$days} days", Session::sign_out_reason_label(Session::SIGN_OUT_BROWSER_UNUSED));
    }

    /** The retired sign-in no longer counts as signed in for its account (the session cap's set). */
    private static function __assert_not_contains_signed_in(int $user_session_id): void
    {
        static::__assert_false(
            in_array($user_session_id, Session::_signed_in_user_session_ids(Session::REALM_STAFF, 12345, null, false), true),
            'a retired sign-in is not counted among the account\'s signed-in sessions'
        );
    }

    public static function test_deactivate_unused_sessions_clears_a_backlog_across_chunks()
    {
        $tokens = [];
        for ($i = 0; $i < 12; $i++) {
            $tokens[] = static::__insert_session(['age_days' => 45, 'login_user_id' => 12345]);
        }

        static::__run_task_method(Session_Cleanup_Service::class, 'deactivate_unused_sessions', ['chunk_size' => 5]);

        static::__assert_equals(0, DB::table('_sessions')->whereIn('session_token', $tokens)->where('active', 1)->count(), 'every backlog row retired across chunks');
    }

    public static function test_deactivate_unused_sessions_is_off_at_zero_days()
    {
        $old = static::__insert_session(['age_days' => 400, 'login_user_id' => 12345]);
        $original = config('rsx.sessions.unused_session_days');

        try {
            config(['rsx.sessions.unused_session_days' => 0]);
            $result = static::__run_task_method(Session_Cleanup_Service::class, 'deactivate_unused_sessions');
        } finally {
            config(['rsx.sessions.unused_session_days' => $original]);
        }

        static::__assert_equals(0, $result['deactivated']);
        static::__assert_equals(1, (int) DB::table('_sessions')->where('session_token', $old)->value('active'), '0 never retires a browser');
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

    public static function test_a_portal_sign_in_is_not_read_as_a_guest()
    {
        // 40 days idle: past the 30-day guest window. Only the portal sign-in saves it.
        $portal = static::__insert_portal_session(['age_days' => 40]);
        $guest = static::__insert_session(['age_days' => 40]);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_sessions');

        $remaining = DB::table('_sessions')
            ->whereIn('session_token', [$portal, $guest])
            ->pluck('session_token')
            ->all();

        static::__assert_true(in_array($portal, $remaining, true), 'a portal-only browser has had a sign-in');
        static::__assert_false(in_array($guest, $remaining, true), 'the guest browser of the same age IS collected');
    }

    // -------------------------------------------------------------------------
    // Session_Cleanup_Service::cleanup_user_agents() (the weekly sweep)
    // -------------------------------------------------------------------------

    /**
     * A user agent recorded $age_days ago, last resolved then.
     */
    private static function __insert_user_agent(string $user_agent, int $age_days): int
    {
        $id = \App\RSpade\Core\Session\Audit_Session::user_agent_id($user_agent);
        $when = now()->subDays($age_days);

        DB::table('_user_agents')->where('id', $id)->update(['first_seen_at' => $when, 'last_seen_at' => $when]);

        return $id;
    }

    /**
     * A string nothing names is deleted; one named by ANY of the referencing tables is kept -
     * each of them, so a table left out of the sweep's list would show here.
     */
    public static function test_cleanup_user_agents_deletes_only_what_nothing_names()
    {
        $orphan = static::__insert_user_agent('sweep-orphan', 10);
        $by_session = static::__insert_user_agent('sweep-session', 10);
        $by_login = static::__insert_user_agent('sweep-login', 10);
        $by_audit = static::__insert_user_agent('sweep-audit', 10);

        $token = static::__insert_session(['age_days' => 1, 'login_user_id' => 12345]);
        DB::table('_sessions')->where('session_token', $token)->update(['user_agent_id' => $by_session]);
        DB::table('_login_history')->insert([
            'email_attempted' => 'sweep@rspade.test',
            'ip_address_id' => \App\RSpade\Core\Session\Ip_Address::id('127.0.0.1'),
            'user_agent_id' => $by_login,
            'status' => 'success',
        ]);
        DB::table('_audit_sessions')->insert([
            'tuple_hash' => hash('sha256', 'sweep-audit'),
            'user_agent_id' => $by_audit,
            'session_type_id' => Session::TYPE_WEB,
        ]);

        $result = static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_user_agents');

        $remaining = DB::table('_user_agents')
            ->whereIn('id', [$orphan, $by_session, $by_login, $by_audit])
            ->pluck('id')
            ->all();

        static::__assert_greater_than(0, $result['total_deleted']);
        static::__assert_false(in_array($orphan, $remaining), 'the string nothing names is gone');
        static::__assert_true(in_array($by_session, $remaining), 'named by a session: kept');
        static::__assert_true(in_array($by_login, $remaining), 'named by a login-history row: kept');
        static::__assert_true(in_array($by_audit, $remaining), 'named by an audit session: kept');
    }

    /**
     * A string resolved within the last day is left alone even when nothing names it yet:
     * the insert that will reference it has to find it.
     */
    public static function test_cleanup_user_agents_leaves_a_string_resolved_today()
    {
        $fresh = static::__insert_user_agent('sweep-just-resolved', 0);

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_user_agents');

        static::__assert_true(DB::table('_user_agents')->where('id', $fresh)->exists());
    }

    /**
     * Resolving a string again counts as use: it advances last_seen_at.
     */
    public static function test_resolving_a_user_agent_again_protects_it_from_the_sweep()
    {
        $old = static::__insert_user_agent('sweep-resolved-again', 10);

        \App\RSpade\Core\Session\Audit_Session::_reset();
        \App\RSpade\Core\Session\Audit_Session::user_agent_id('sweep-resolved-again');

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_user_agents');

        static::__assert_true(DB::table('_user_agents')->where('id', $old)->exists());
    }
}
