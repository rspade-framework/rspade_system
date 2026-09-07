<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * PROPERTY ISOLATION on the one session table.
 *
 * There is ONE session per browser - one `rsx` cookie, one _sessions row - and no
 * realm discriminator at all. The row is a property bag: the staff experience reads
 * and writes login_user_id / site_id / impersonator_login_user_id, the portal
 * experience reads and writes portal_user_id / portal_site_id / impersonator_user_id,
 * and BOTH SETS ON ONE ROW AT ONCE IS LEGAL - the same human, in the same browser,
 * signed into both.
 *
 * What used to be row isolation is therefore PROPERTY isolation, and that is the line
 * these tests hold: neither experience's writes may touch the other's columns, and
 * neither experience's session-management surface may reach a row that carries only
 * the other's identity. Ending a portal identity CLEARS PROPERTIES; it never deletes
 * or deactivates a row, because the row is the browser's session.
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
     * Mint a browser session row carrying whichever identities the caller names.
     *
     * @param int|null $portal_user_id Portal identity, or null for none
     * @param int|null $login_user_id  Staff identity, or null for none
     */
    private static function __make_session(
        ?int $portal_user_id = null,
        ?int $login_user_id = null,
        int $minutes_idle = 0,
        int $type_id = Session::TYPE_WEB
    ): Session {
        $session = new Session();
        $session->site_id = $login_user_id ? self::SITE_ID : 0;
        $session->type_id = $type_id;
        $session->login_user_id = $login_user_id;
        $session->portal_user_id = $portal_user_id;
        $session->portal_site_id = $portal_user_id ? self::SITE_ID : null;
        $session->session_token = bin2hex(random_bytes(16));
        $session->csrf_token = bin2hex(random_bytes(16));
        $session->ip_address = '10.0.0.11';
        $session->user_agent = 'session-property-test';
        $session->last_active = now()->subMinutes($minutes_idle);
        $session->active = true;
        $session->version = 1;
        $session->save();

        return $session;
    }

    // =====================================================================
    // Row shape - the two property sets are independent
    // =====================================================================

    public static function test_a_portal_identity_sets_no_staff_property()
    {
        $user = static::__make_portal_user();
        $session = static::__make_session($user->id);

        $row = Session::find($session->id);

        static::__assert_equals($user->id, (int) $row->portal_user_id, 'portal identity on portal_user_id');
        static::__assert_equals(self::SITE_ID, (int) $row->portal_site_id, 'portal tenant on portal_site_id');
        static::__assert_null($row->login_user_id, 'the staff identity column stays empty');
        static::__assert_equals(0, (int) $row->site_id, 'site_id is STAFF-only and stays unset');
    }

    /**
     * The property that the two-row design existed to prevent, and that the corrected
     * model calls normal: one browser, one row, both identities.
     */
    public static function test_both_identities_on_one_row_is_legal()
    {
        $user = static::__make_portal_user();
        $session = static::__make_session($user->id, 777100);

        $row = Session::find($session->id);

        static::__assert_equals($user->id, (int) $row->portal_user_id, 'portal identity present');
        static::__assert_equals(777100, (int) $row->login_user_id, 'staff identity present on the SAME row');
    }

    /**
     * create_impersonation_session() is the one row-minting portal path reachable
     * outside a web request. The row it mints is pure HANDOFF TRANSPORT - it carries
     * the payload the claiming browser will copy onto its own session.
     */
    public static function test_impersonation_handoff_carries_only_portal_properties()
    {
        $user = static::__make_portal_user();

        $handoff = Portal_Session::create_impersonation_session($user->id, self::IMPERSONATOR_ID, self::SITE_ID);

        $row = Session::where('handoff_token', $handoff)->first();

        static::__assert_not_null($row, 'handoff row created');
        static::__assert_equals($user->id, (int) $row->portal_user_id, 'bound to the target portal user');
        static::__assert_equals(self::SITE_ID, (int) $row->portal_site_id, 'carries the portal tenant');
        static::__assert_null($row->login_user_id, 'no staff identity');
        static::__assert_equals(self::IMPERSONATOR_ID, (int) $row->impersonator_user_id, 'staff impersonator recorded');
        static::__assert_null($row->impersonator_login_user_id, 'the staff impersonation column stays empty');
    }

    // =====================================================================
    // Token resolution - a session is "a portal session" only if it says so
    // =====================================================================

    public static function test_a_session_with_no_portal_identity_is_not_a_portal_session()
    {
        $staff = static::__make_session(null, 777001);

        static::__assert_null(
            Portal_Session::find_by_token($staff->session_token),
            'a browser session with no portal identity resolves to nothing through the portal facade'
        );
        static::__assert_not_null(
            Session::find_by_token($staff->session_token),
            'the same token still resolves as what it is: the browser session'
        );
    }

    public static function test_a_portal_session_resolves_through_both_facades()
    {
        $user = static::__make_portal_user();
        $portal = static::__make_session($user->id);

        static::__assert_not_null(
            Portal_Session::find_by_token($portal->session_token),
            'it carries a portal identity, so the portal facade sees it'
        );
        static::__assert_not_null(
            Session::find_by_token($portal->session_token),
            'and it is still the browser session, so the staff facade sees the row'
        );
    }

    // =====================================================================
    // Session-management surfaces stay on their own properties
    // =====================================================================

    public static function test_staff_session_list_never_returns_a_portal_only_row()
    {
        $user = static::__make_portal_user();
        static::__make_session($user->id);

        // The row carries NULL login_user_id, so the staff listing - keyed on that
        // column - cannot see it, whatever its portal_user_id happens to equal.
        static::__assert_count(0, Session::get_sessions_for_user($user->id), 'staff listing is empty for a portal user id');
    }

    public static function test_portal_session_list_never_returns_a_staff_only_row()
    {
        $login_user_id = 777002;
        static::__make_session(null, $login_user_id);

        static::__assert_count(
            0,
            Portal_Session::get_sessions_for_user($login_user_id),
            'portal listing is empty for a staff login user id'
        );
    }

    public static function test_portal_termination_never_reaches_a_staff_only_row()
    {
        $user = static::__make_portal_user();
        $staff = static::__make_session(null, 777003);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        static::__assert_false(
            Portal_Session::terminate_session((int) $staff->id),
            'a session with no portal identity is not terminable through the portal facade'
        );
        static::__assert_true(
            Session::where('id', $staff->id)->exists(),
            'the row is intact'
        );
    }

    /**
     * The subject here is REALM ISOLATION, not authorization, so it exercises the
     * unguarded deactivation primitive directly. The public
     * Session::terminate_all_sessions_for_user() wraps this same body in the
     * cross-user authorization guard (an actor and a target ROLE, neither of which a
     * portal-only row has); making that guard the subject would only prove the guard.
     */
    public static function test_staff_termination_never_reaches_a_portal_only_row()
    {
        $user = static::__make_portal_user();
        $portal = static::__make_session($user->id);

        static::__assert_equals(
            0,
            Session::_deactivate_sessions_for_user($user->id),
            'the staff admin sweep matches nothing without a staff identity'
        );
        static::__assert_true(
            Session::where('id', $portal->id)->where('active', true)->exists(),
            'the row is intact and still active'
        );
    }

    /**
     * Ending a portal identity must leave the staff login on the same row alone -
     * the whole reason termination clears properties instead of deleting rows.
     */
    public static function test_portal_termination_preserves_the_staff_login_on_the_same_row()
    {
        $user = static::__make_portal_user();
        $shared = static::__make_session($user->id, 777004);

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($user->id);

        static::__assert_true(
            Portal_Session::terminate_session((int) $shared->id),
            'the portal identity on that device is ended'
        );

        $row = Session::find($shared->id);

        static::__assert_not_null($row, 'the row survives - it is that browser session');
        static::__assert_null($row->portal_user_id, 'portal identity cleared');
        static::__assert_null($row->portal_site_id, 'portal tenant cleared');
        static::__assert_equals(777004, (int) $row->login_user_id, 'the staff login is untouched');
        static::__assert_true((bool) $row->active, 'and the session is still usable');
    }

    // =====================================================================
    // The concurrent-session cap
    // =====================================================================

    /**
     * The portal cap is applied at sign-in, which is web-only, so it is reached here
     * through the private method - the same technique Session_Cap_Test uses.
     */
    private static function __enforce_portal_cap(int $portal_user_id): void
    {
        $method = new \ReflectionMethod(Portal_Session::class, '__enforce_web_session_cap');
        $method->setAccessible(true);
        $method->invokeArgs(null, [$portal_user_id]);
    }

    public static function test_portal_cap_clears_the_oldest_beyond_the_limit()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);

        $user = static::__make_portal_user();
        $newest = static::__make_session($user->id, null, 1);
        $second = static::__make_session($user->id, null, 2);
        $oldest = static::__make_session($user->id, null, 3);

        static::__enforce_portal_cap($user->id);

        static::__assert_equals($user->id, (int) Session::find($newest->id)->portal_user_id, 'most recent kept');
        static::__assert_equals($user->id, (int) Session::find($second->id)->portal_user_id, '2nd most recent kept (the cap)');

        $evicted = Session::find($oldest->id);

        static::__assert_not_null($evicted, 'the evicted row SURVIVES - it is a browser session');
        static::__assert_null($evicted->portal_user_id, 'only the portal identity is cleared');
    }

    public static function test_portal_cap_never_counts_or_evicts_harness_sessions()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 2]);

        $user = static::__make_portal_user();
        $harness = static::__make_session($user->id, null, 1, Session::TYPE_PLAYWRIGHT);
        $web_new = static::__make_session($user->id, null, 2);
        $web_old = static::__make_session($user->id, null, 3);

        static::__enforce_portal_cap($user->id);

        static::__assert_equals($user->id, (int) Session::find($harness->id)->portal_user_id, 'a test run is never signed out by a login');
        static::__assert_equals($user->id, (int) Session::find($web_new->id)->portal_user_id, 'both web sessions fit the cap of 2...');
        static::__assert_equals($user->id, (int) Session::find($web_old->id)->portal_user_id, '...harness rows did not consume a slot');
    }

    public static function test_portal_cap_never_touches_a_staff_property()
    {
        config(['rsx.sessions.max_web_sessions_per_user' => 1]);

        $user = static::__make_portal_user();
        static::__make_session($user->id, null, 1);
        static::__make_session($user->id, null, 2);

        // Same integer in the OTHER experience's identity column: the cap narrows on
        // portal_user_id, so nothing here is in its scope.
        $staff = static::__make_session(null, $user->id);

        static::__enforce_portal_cap($user->id);

        $row = Session::find($staff->id);

        static::__assert_equals($user->id, (int) $row->login_user_id, 'a staff identity is never in the portal cap scope');
        static::__assert_true((bool) $row->active, 'and its row is untouched');
    }
}
