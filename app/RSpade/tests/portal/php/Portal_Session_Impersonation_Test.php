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
 * Tests for the framework's portal impersonation primitives on Portal_Session:
 * create_impersonation_session() (a single-use HANDOFF row - pure transport, no
 * last_login touch), claim_impersonation() (resolve + burn, then stamp the payload
 * onto the CLAIMING browser's own session), expiry,
 * is_impersonating()/get_impersonator_user_id() via the CLI flag, and
 * stop_impersonation(). Read-only enforcement is the application's job and is covered
 * by the app suite, not here.
 *
 * Row assertions read _sessions directly (Portal_Session is a facade over the portal
 * PROPERTIES of the one session row and has no query builder of its own).
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Portal_Session_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;
    private const IMPERSONATOR_ID = 99; // arbitrary staff user id (no FK on the column)

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        // Clear any CLI impersonation/identity flags between tests.
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);
    }

    /**
     * Seed an active, verified portal user on the test site.
     */
    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'impersonate_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    // =====================================================================
    // create_impersonation_session
    // =====================================================================

    public static function test_create_creates_a_single_use_handoff_row()
    {
        $user = static::__make_portal_user();

        $handoff = Portal_Session::create_impersonation_session($user->id, self::IMPERSONATOR_ID, self::SITE_ID);

        static::__assert_equals(64, strlen($handoff), 'handoff token is 32 bytes hex');

        $session = Session::where('handoff_token', $handoff)->first();
        static::__assert_not_null($session, 'handoff row created');
        static::__assert_equals($user->id, (int) $session->portal_user_id, 'bound to the target portal user');
        static::__assert_equals(self::IMPERSONATOR_ID, (int) $session->impersonator_user_id, 'stamped with the staff impersonator id');
        static::__assert_not_null($session->impersonation_started_at, 'impersonation_started_at recorded');
        static::__assert_not_null($session->handoff_expires_at, 'handoff has an expiry');
    }

    public static function test_create_does_not_touch_target_last_login()
    {
        $user = static::__make_portal_user();
        static::__assert_null($user->last_login, 'seeded user has no last_login');

        Portal_Session::create_impersonation_session($user->id, self::IMPERSONATOR_ID, self::SITE_ID);

        $reloaded = Portal_User_Model::find($user->id);
        static::__assert_null($reloaded->last_login, 'impersonation must not pollute the contact last_login');
    }

    // =====================================================================
    // claim_impersonation (resolve + single-use burn) + expiry
    // =====================================================================

    public static function test_claim_succeeds_once_then_token_is_burned()
    {
        $user = static::__make_portal_user();
        $handoff = Portal_Session::create_impersonation_session($user->id, self::IMPERSONATOR_ID, self::SITE_ID);

        static::__assert_true(Portal_Session::claim_impersonation($handoff), 'first claim succeeds');
        static::__assert_false(Portal_Session::claim_impersonation($handoff), 'second claim fails (token burned)');

        static::__assert_false(
            Session::where('handoff_token', $handoff)->exists(),
            'the transport row is consumed by the claim'
        );

        // In CLI there is no browser to stamp, so the claim lands on the CLI overrides.
        static::__assert_equals($user->id, Portal_Session::get_portal_user_id(), 'the claimed portal identity is in force');
        static::__assert_true(Portal_Session::is_impersonating(), 'and it is an impersonation');
    }

    public static function test_claim_fails_for_expired_handoff()
    {
        $user = static::__make_portal_user();
        $handoff = Portal_Session::create_impersonation_session($user->id, self::IMPERSONATOR_ID, self::SITE_ID);

        // Force the handoff window into the past.
        Session::where('handoff_token', $handoff)->raw_bulk()->update(['handoff_expires_at' => now()->subMinutes(5)]);

        static::__assert_false(Portal_Session::claim_impersonation($handoff), 'expired handoff is rejected');
    }

    public static function test_claim_fails_for_unknown_token()
    {
        static::__assert_false(Portal_Session::claim_impersonation('does-not-exist'), 'unknown token rejected');
        static::__assert_false(Portal_Session::claim_impersonation(''), 'empty token rejected');
    }

    // =====================================================================
    // is_impersonating / get_impersonator_user_id / stop_impersonation (CLI flag)
    // =====================================================================

    public static function test_is_impersonating_reflects_cli_flag()
    {
        // teardown() runs once per class, and a claim earlier in this class leaves the
        // CLI overrides set - so this test establishes its own clean slate.
        Portal_Session::cli_set_impersonator_user_id(null);
        Portal_Session::cli_set_portal_user_id(0);

        static::__assert_false(Portal_Session::is_impersonating(), 'no impersonation by default');
        static::__assert_null(Portal_Session::get_impersonator_user_id(), 'no impersonator by default');

        Portal_Session::cli_set_impersonator_user_id(self::IMPERSONATOR_ID);

        static::__assert_true(Portal_Session::is_impersonating(), 'flag set -> impersonating');
        static::__assert_equals(self::IMPERSONATOR_ID, Portal_Session::get_impersonator_user_id(), 'impersonator id reported');
    }

    public static function test_stop_impersonation_clears_the_flag()
    {
        Portal_Session::cli_set_portal_user_id(123);
        Portal_Session::cli_set_impersonator_user_id(self::IMPERSONATOR_ID);
        static::__assert_true(Portal_Session::is_impersonating(), 'impersonating before stop');

        Portal_Session::stop_impersonation();

        static::__assert_false(Portal_Session::is_impersonating(), 'no longer impersonating after stop');
    }
}
