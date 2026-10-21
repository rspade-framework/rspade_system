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
 * Portal_Session::terminate_session() is a SELF-SERVICE action: it signs out one of the CURRENT
 * portal account's own portal user sessions on another browser. The owning-account predicate is
 * part of the lookup, so another portal user's user session id matches nothing and the method
 * returns false - the caller does not have to remember to pre-scope. Cross-user (admin)
 * termination lives in terminate_all_sessions_for_user().
 *
 * It signs out the PORTAL user session and never deletes the browser row: there is one session
 * per browser, and it may also carry a staff sign-in. The portal user session keeps its FK to
 * portal_users, so real Portal_User_Model rows are seeded. The portal IDENTITY of the caller is
 * set through the Portal_Session CLI setters. Default per-test transaction.
 */
class Portal_Session_Terminate_Ownership_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'terminate_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /** A browser signed into the portal as $portal_user_id; answers the fixture. */
    private static function __insert_session(int $portal_user_id): array
    {
        return static::__insert_session_fixture([
            'portal' => ['portal_user_id' => $portal_user_id, 'portal_site_id' => self::SITE_ID],
        ]);
    }

    /**
     * Whether the portal user session is still signed in - which is what "the session exists"
     * means to the portal. The browser ROW always survives.
     */
    private static function __exists(array $fixture): bool
    {
        return \Illuminate\Support\Facades\DB::table('_user_sessions')
            ->where('id', $fixture['portal_user_session_id'])
            ->whereNull('signed_out_at')
            ->exists();
    }

    private static function __act_as(int $portal_user_id): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    public static function test_own_session_is_terminated()
    {
        $owner = static::__make_portal_user();
        static::__act_as($owner->id);

        $fixture = static::__insert_session($owner->id);

        static::__assert_true(
            Portal_Session::terminate_session($fixture['portal_user_session_id']),
            'a portal user may end their own session'
        );
        static::__assert_false(static::__exists($fixture), 'the portal user session is signed out');
        static::__assert_true(
            Session::where('id', $fixture['id'])->where('active', true)->whereNull('portal_user_session_id')->exists(),
            'the ROW survives, its portal pointer cleared - it is that browser\'s session'
        );
    }

    public static function test_another_users_session_is_untouched_and_returns_false()
    {
        $owner = static::__make_portal_user();
        $other = static::__make_portal_user();
        static::__act_as($owner->id);

        $foreign = static::__insert_session($other->id);

        static::__assert_false(
            Portal_Session::terminate_session($foreign['portal_user_session_id']),
            'ending a session you do not own must report failure'
        );
        static::__assert_true(
            static::__exists($foreign),
            'the other portal user identity must be intact'
        );
    }

    /**
     * With no portal identity there is no ownership to establish, so the call is a fail-closed
     * no-op rather than an unscoped delete.
     */
    public static function test_logged_out_caller_terminates_nothing()
    {
        $owner = static::__make_portal_user();
        $fixture = static::__insert_session($owner->id);

        Portal_Session::logout();

        static::__assert_false(Portal_Session::terminate_session($fixture['portal_user_session_id']));
        static::__assert_true(static::__exists($fixture), 'the user session is intact');
    }

    public static function teardown(): void
    {
        Portal_Session::logout();
        static::__reset_session();
    }
}
