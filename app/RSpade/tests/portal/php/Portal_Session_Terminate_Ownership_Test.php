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
 * Portal_Session::terminate_session() is a SELF-SERVICE device-session action: it ends the portal
 * identity on one of the CURRENT portal user's own sessions. The owning-user predicate is part of
 * the statement, so another portal user's session id matches zero rows and the method returns
 * false - the caller does not have to remember to pre-scope. Cross-user (admin) termination lives
 * in terminate_all_sessions_for_user().
 *
 * It CLEARS THE PORTAL PROPERTIES and never deletes the row: there is one session per browser, and
 * that row may also carry a staff login. "Terminated" therefore means portal_user_id is gone, not
 * that the row is. The column keeps its FK to portal_users, so real Portal_User_Model rows are
 * seeded. Site-scoped seeding aligns the STAFF Session site via __acting_as_site(); the portal
 * IDENTITY is set through the Portal_Session CLI setters. Default per-test transaction.
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

    private static function __insert_session(int $portal_user_id): int
    {
        $session = new Session();
        $session->active = true;
        $session->version = 1;
        $session->site_id = 0;
        $session->type_id = Session::TYPE_WEB;
        $session->portal_user_id = $portal_user_id;
        $session->portal_site_id = self::SITE_ID;
        $session->session_token = bin2hex(random_bytes(16));
        $session->csrf_token = bin2hex(random_bytes(16));
        $session->ip_address = '10.0.0.8';
        $session->user_agent = 'portal-terminate-ownership-test';
        $session->last_active = now();
        $session->save();

        return (int) $session->id;
    }

    /**
     * Whether the row still carries a portal identity - which is what "the session
     * exists" means to the portal. The ROW always survives.
     */
    private static function __exists(int $session_id): bool
    {
        return Session::_portal_query()->where('id', $session_id)->exists();
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

        $session_id = static::__insert_session($owner->id);

        static::__assert_true(
            Portal_Session::terminate_session($session_id),
            'a portal user may terminate their own session'
        );
        static::__assert_false(static::__exists($session_id), 'the portal identity is cleared');
        static::__assert_true(
            Session::where('id', $session_id)->exists(),
            'the ROW survives - it is that browser session'
        );
    }

    public static function test_another_users_session_is_untouched_and_returns_false()
    {
        $owner = static::__make_portal_user();
        $other = static::__make_portal_user();
        static::__act_as($owner->id);

        $foreign_session_id = static::__insert_session($other->id);

        static::__assert_false(
            Portal_Session::terminate_session($foreign_session_id),
            'terminating a session you do not own must report failure'
        );
        static::__assert_true(
            static::__exists($foreign_session_id),
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
        $session_id = static::__insert_session($owner->id);

        Portal_Session::logout();

        static::__assert_false(Portal_Session::terminate_session($session_id));
        static::__assert_true(static::__exists($session_id), 'the row is intact');
    }

    public static function teardown(): void
    {
        Portal_Session::logout();
        static::__reset_session();
    }
}
