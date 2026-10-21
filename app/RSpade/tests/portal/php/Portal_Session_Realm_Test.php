<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * REALM ISOLATION on the one browser row.
 *
 * There is ONE session per browser - one `rsx` cookie, one _sessions row - and it holds two
 * pointers: the staff user session and the portal user session signed in on it. BOTH SET AT
 * ONCE IS LEGAL - the same human, in the same browser, signed into both.
 *
 * The line these tests hold: neither experience's writes touch the other's pointer or user
 * sessions, and neither experience's session-management surface reaches a user session of
 * the other realm. Ending a portal sign-in signs out the PORTAL user session; it never deletes
 * or deactivates the row, because the row is the browser's session.
 *
 * Rows are minted directly here (the login paths that normally mint them are web-only).
 *
 * Default per-test transaction: every assertion reads on the same connection.
 */
class Portal_Session_Realm_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** Arbitrary staff ids - the impersonator column carries no FK. */
    private const IMPERSONATOR_ID = 4242;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'realm_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * A browser signed in as whichever identities the caller names.
     *
     * @param int|null $portal_user_id Portal identity, or null for none
     * @param int|null $login_user_id  Staff identity, or null for none
     */
    private static function __make_session(
        ?int $portal_user_id = null,
        ?int $login_user_id = null,
        int $minutes_idle = 0,
        int $type_id = Session::TYPE_WEB
    ): array {
        $when = now()->subMinutes($minutes_idle);
        $options = ['type_id' => $type_id, 'last_active' => $when];

        if ($login_user_id !== null) {
            $options['staff'] = ['login_user_id' => $login_user_id, 'site_id' => self::SITE_ID, 'last_active_at' => $when];
        }

        if ($portal_user_id !== null) {
            $options['portal'] = ['portal_user_id' => $portal_user_id, 'portal_site_id' => self::SITE_ID, 'last_active_at' => $when];
        }

        return static::__insert_session_fixture($options);
    }

    private static function __user_session(?int $id): ?object
    {
        return $id === null ? null : DB::table('_user_sessions')->where('id', $id)->first();
    }

    private static function __browser(array $fixture): object
    {
        return DB::table('_sessions')->where('id', $fixture['id'])->first();
    }

    // =====================================================================
    // Shape - the two realms are independent
    // =====================================================================

    public static function test_a_portal_sign_in_sets_no_staff_pointer()
    {
        $user = static::__make_portal_user();
        $fixture = static::__make_session($user->id);

        $browser = static::__browser($fixture);
        $portal = static::__user_session($browser->portal_user_session_id);

        static::__assert_equals($user->id, (int) $portal->portal_user_id, 'portal identity on the portal user session');
        static::__assert_equals(self::SITE_ID, (int) $portal->portal_site_id, 'portal tenant on it too');
        static::__assert_null($portal->login_user_id, 'no staff identity on it');
        static::__assert_null($browser->staff_user_session_id, 'the staff pointer stays empty');
    }

    /**
     * The shape the old two-row design existed to prevent, and that this model calls normal:
     * one browser, one row, both realms signed in.
     */
    public static function test_both_realms_on_one_row_is_legal()
    {
        $user = static::__make_portal_user();
        $fixture = static::__make_session($user->id, 777100);

        $browser = static::__browser($fixture);

        static::__assert_equals($user->id, (int) static::__user_session($browser->portal_user_session_id)->portal_user_id, 'portal sign-in present');
        static::__assert_equals(777100, (int) static::__user_session($browser->staff_user_session_id)->login_user_id, 'staff sign-in present on the SAME row');
    }

    /**
     * "View as Client" points the staff browser's portal pointer at an impersonation user
     * session (Session::_apply_portal_impersonation - the seam both the same-host path and the
     * linked-session handshake use). The staff sign-in on that row is untouched.
     */
    public static function test_view_as_client_moves_only_the_portal_pointer()
    {
        $user = static::__make_portal_user();
        $fixture = static::__make_session(null, 777300);

        static::__assert_true(
            Session::_apply_portal_impersonation($fixture['id'], $user->id, self::SITE_ID, self::IMPERSONATOR_ID),
            'applied to a live row'
        );

        $browser = static::__browser($fixture);
        $portal = static::__user_session($browser->portal_user_session_id);

        static::__assert_equals($user->id, (int) $portal->portal_user_id, 'bound to the target portal user');
        static::__assert_equals(self::SITE_ID, (int) $portal->portal_site_id, 'carries the portal tenant');
        static::__assert_equals(self::IMPERSONATOR_ID, (int) $portal->impersonator_user_id, 'staff impersonator recorded');
        static::__assert_not_null($portal->started_at, 'start time recorded');
        static::__assert_equals($fixture['staff_user_session_id'], (int) $browser->staff_user_session_id, 'the staff sign-in on the same row is untouched');
        static::__assert_null(static::__user_session($fixture['staff_user_session_id'])->impersonator_login_user_id, 'and is no staff impersonation');
    }

    // =====================================================================
    // Token resolution - a session is "a portal session" only if it is signed in there
    // =====================================================================

    public static function test_a_session_with_no_portal_sign_in_is_not_a_portal_session()
    {
        $staff = static::__make_session(null, 777001);

        static::__assert_null(
            Portal_Session::find_by_token($staff['token']),
            'a browser with no portal sign-in resolves to nothing through the portal facade'
        );
        static::__assert_not_null(
            Session::find_by_token($staff['token']),
            'the same token still resolves as what it is: the browser session'
        );
    }

    public static function test_a_portal_session_resolves_through_both_facades()
    {
        $user = static::__make_portal_user();
        $portal = static::__make_session($user->id);

        static::__assert_not_null(Portal_Session::find_by_token($portal['token']), 'signed into the portal, so the portal facade sees it');
        static::__assert_not_null(Session::find_by_token($portal['token']), 'and it is still the browser session');
    }

    // =====================================================================
    // Session-management surfaces stay in their own realm
    // =====================================================================

    public static function test_staff_session_list_never_returns_a_portal_user_session()
    {
        $user = static::__make_portal_user();
        static::__make_session($user->id);

        static::__assert_count(0, Session::get_sessions_for_user($user->id), 'staff listing is empty for a portal user id');
    }

    public static function test_portal_session_list_never_returns_a_staff_user_session()
    {
        $login_user_id = 777002;
        static::__make_session(null, $login_user_id);

        static::__assert_count(0, Portal_Session::get_sessions_for_user($login_user_id), 'portal listing is empty for a staff login user id');
    }

    public static function test_portal_termination_never_reaches_a_staff_user_session()
    {
        $user = static::__make_portal_user();
        $staff = static::__make_session(null, 777003);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        static::__assert_false(
            Portal_Session::terminate_session($staff['staff_user_session_id']),
            'a staff user session is not terminable through the portal facade'
        );
        static::__assert_null(static::__user_session($staff['staff_user_session_id'])->signed_out_at, 'it is still signed in');
    }

    /**
     * The subject here is REALM ISOLATION, not authorization, so it exercises the unguarded
     * primitive directly. The public Session::terminate_all_sessions_for_user() wraps this
     * same body in the cross-user authorization guard.
     */
    public static function test_staff_termination_never_reaches_a_portal_user_session()
    {
        $user = static::__make_portal_user();
        $portal = static::__make_session($user->id);

        static::__assert_equals(
            0,
            Session::_deactivate_sessions_for_user($user->id),
            'the staff admin sweep matches nothing without a staff identity'
        );
        static::__assert_null(static::__user_session($portal['portal_user_session_id'])->signed_out_at, 'the portal sign-in is intact');
    }

    /**
     * Ending a portal sign-in leaves the staff sign-in on the same row alone - the reason
     * termination moves a pointer instead of deleting rows.
     */
    public static function test_portal_termination_preserves_the_staff_sign_in_on_the_same_row()
    {
        $user = static::__make_portal_user();
        $shared = static::__make_session($user->id, 777004);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        static::__assert_true(
            Portal_Session::terminate_session($shared['portal_user_session_id']),
            'the portal sign-in on that browser is ended'
        );

        $browser = static::__browser($shared);

        static::__assert_null($browser->portal_user_session_id, 'portal pointer cleared');
        static::__assert_equals($shared['staff_user_session_id'], (int) $browser->staff_user_session_id, 'the staff sign-in is untouched');
        static::__assert_equals(1, (int) $browser->active, 'and the session is still usable');
    }

    // =====================================================================
    // The concurrent-session cap
    // =====================================================================

    private static function __enforce_portal_cap(int $portal_user_id): void
    {
        Session::_enforce_session_cap(Session::REALM_PORTAL, $portal_user_id);
    }

    private static function __signed_in(array $fixture): bool
    {
        return static::__user_session($fixture['portal_user_session_id'])->signed_out_at === null;
    }

    public static function test_portal_cap_signs_out_the_oldest_beyond_the_limit()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);

        $user = static::__make_portal_user();
        $newest = static::__make_session($user->id, null, 1);
        $second = static::__make_session($user->id, null, 2);
        $oldest = static::__make_session($user->id, null, 3);

        static::__enforce_portal_cap($user->id);

        static::__assert_true(static::__signed_in($newest), 'most recent kept');
        static::__assert_true(static::__signed_in($second), '2nd most recent kept (the cap)');
        static::__assert_false(static::__signed_in($oldest), 'the oldest portal sign-in is ended');
        static::__assert_equals(1, (int) static::__browser($oldest)->active, 'the evicted browser row SURVIVES');
    }

    public static function test_portal_cap_never_counts_or_evicts_harness_sessions()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);

        $user = static::__make_portal_user();
        $harness = static::__make_session($user->id, null, 1, Session::TYPE_PLAYWRIGHT);
        $web_new = static::__make_session($user->id, null, 2);
        $web_old = static::__make_session($user->id, null, 3);

        static::__enforce_portal_cap($user->id);

        static::__assert_true(static::__signed_in($harness), 'a test run is never signed out by a login');
        static::__assert_true(static::__signed_in($web_new), 'both web sessions fit the cap of 2...');
        static::__assert_true(static::__signed_in($web_old), '...harness rows did not consume a slot');
    }

    public static function test_portal_cap_never_touches_a_staff_user_session()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 1]);

        $user = static::__make_portal_user();
        static::__make_session($user->id, null, 1);
        static::__make_session($user->id, null, 2);

        // The same integer in the OTHER realm's identity column: not in the portal cap's scope.
        $staff = static::__make_session(null, $user->id);

        static::__enforce_portal_cap($user->id);

        static::__assert_null(static::__user_session($staff['staff_user_session_id'])->signed_out_at, 'a staff sign-in is never in the portal cap scope');
    }
}
