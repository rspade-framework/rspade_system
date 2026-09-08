<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The framework half of the portal authorization contract: Portal_User_Model's own
 * portal_can_read() rule and the Portal_Authorizable::portal_fetch() gate above it.
 *
 * Only framework types appear here. Which RECORDS an application's own models expose to
 * a portal identity - membership scoping, sharing, roles - is that application's
 * contract, tested in its own suite (rsx/tests).
 *
 * The portal IDENTITY is set via the Portal_Session CLI setters; the staff Session site
 * is aligned with __acting_as_site() because site-scoped models read it. Runs in the
 * default per-test transaction (rolled back afterward).
 */
class Portal_Authorization_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Seed a portal user (active + verified), optionally linked to a contact.
     */
    private static function __make_portal_user(?int $contact_id = null): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'portal_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        if ($contact_id !== null) {
            $user->contact_id = $contact_id;
        }
        $user->save();

        return $user;
    }

    /**
     * Impersonate a portal user (CLI portal session identity) on the test site.
     */
    private static function __login_portal(int $portal_user_id): void
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    public static function setup(): void
    {
        // Align the staff Session site so site-scoped models seed and query on SITE_ID.
        static::__acting_as_site(self::SITE_ID);
    }

    // =====================================================================
    // own-record (Portal_User_Model::portal_can_read)
    // =====================================================================

    public static function test_own_record_grant_and_deny()
    {
        $user_a = static::__make_portal_user();
        $user_b = static::__make_portal_user();

        static::__login_portal($user_a->id);

        static::__assert_true($user_a->portal_can_read(), 'portal user may read own record');
        static::__assert_false($user_b->portal_can_read(), 'portal user may not read another user record');
    }

    public static function test_portal_fetch_gates_on_session_and_ownership()
    {
        $user_a = static::__make_portal_user();
        $user_b = static::__make_portal_user();

        // Not logged in -> false (session gate).
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id(0);
        static::__assert_false(Portal_User_Model::portal_fetch($user_a->id), 'portal_fetch denies without a session');

        // Logged in as A: own record returns an array, B's returns false.
        static::__login_portal($user_a->id);
        $own = Portal_User_Model::portal_fetch($user_a->id);
        static::__assert_true(is_array($own), 'portal_fetch returns array for own record');
        static::__assert_equals($user_a->id, $own['id'] ?? null);
        static::__assert_false(Portal_User_Model::portal_fetch($user_b->id), 'portal_fetch denies another user record');
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }
}
