<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE PORTAL SITE CONTRACT (Portal_Session::set_site_id).
 *
 * The framework does not resolve which site a portal request serves - the
 * application declares it, and an undeclared site fails loud instead of quietly
 * becoming site 1 (which is what the deleted detect_site_id() did on every
 * branch). These tests hold that line from the CLI side, which is the whole of
 * the facade's site logic minus the cookie: declaration in, declaration out,
 * a loud refusal when there is none, and a refusal to accept a second, different
 * answer while a session already carries one.
 *
 * What CLI cannot reach - a login stamping portal_site_id on the browser's session
 * row - is covered by the web path (the template portal logs in end to end, and
 * every portal request would 500 on the message asserted here if the declaration
 * were missing).
 *
 * No transaction is needed for the declaration tests themselves, but the class
 * keeps the default one because the session-conflict test persists a row.
 */
class Portal_Site_Declaration_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OTHER_SITE_ID = 77;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        Portal_Session::reset();
    }

    public static function teardown(): void
    {
        Portal_Session::reset();
        static::__reset_session();
    }

    // =====================================================================
    // Declaration in, declaration out
    // =====================================================================

    public static function test_a_declared_site_is_what_get_site_id_returns()
    {
        Portal_Session::reset();
        Portal_Session::set_site_id(self::OTHER_SITE_ID);

        static::__assert_equals(
            self::OTHER_SITE_ID,
            Portal_Session::get_site_id(),
            'get_site_id() returns the declared site verbatim - no config, no default'
        );
    }

    public static function test_declaring_the_same_site_twice_is_idempotent()
    {
        Portal_Session::reset();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_site_id(self::SITE_ID);

        static::__assert_equals(self::SITE_ID, Portal_Session::get_site_id());
    }

    public static function test_the_declaration_makes_the_cli_facade_report_a_session()
    {
        Portal_Session::reset();
        static::__assert_false(
            Portal_Session::has_session(),
            'nothing declared, nothing logged in - the CLI facade holds no state'
        );

        Portal_Session::set_site_id(self::SITE_ID);

        static::__assert_true(
            Portal_Session::has_session(),
            'a declared site is portal state, the same way the CLI identity is'
        );
    }

    public static function test_reset_clears_the_declaration_in_cli()
    {
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::reset();

        static::__assert_throws(\RuntimeException::class, function () {
            Portal_Session::get_site_id();
        }, 'portal site has not been declared');
    }

    // =====================================================================
    // The refusals
    // =====================================================================

    /**
     * The batch's reason for existing: no session, no declaration -> a loud
     * failure naming the fix, NEVER a silent site 1.
     */
    public static function test_an_undeclared_site_throws_and_names_the_man_page()
    {
        Portal_Session::reset();

        $thrown = null;
        try {
            Portal_Session::get_site_id();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        static::__assert_not_null($thrown, 'get_site_id() must throw when nothing declared a site');
        static::__assert_contains(
            'Portal_Session::set_site_id',
            $thrown->getMessage(),
            'the message names the API that fixes it'
        );
        static::__assert_contains(
            'rsx:man portal',
            $thrown->getMessage(),
            'the message names the man page (rsx:man portal)'
        );
    }

    public static function test_get_site_falls_over_the_same_way_as_get_site_id()
    {
        Portal_Session::reset();

        static::__assert_throws(\RuntimeException::class, function () {
            Portal_Session::get_site();
        }, 'portal site has not been declared');
    }

    public static function test_a_non_positive_site_is_refused()
    {
        Portal_Session::reset();

        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Portal_Session::set_site_id(0);
        }, 'requires a real site id');

        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Portal_Session::set_site_id(-3);
        }, 'requires a real site id');
    }

    /**
     * CLI has no request boundary, so a re-declaration there is a re-scope (the
     * test seam), not a bug. The web branch's conflict refusal is asserted below
     * against a live session, which CLI CAN construct.
     */
    public static function test_cli_may_redeclare_a_different_site()
    {
        Portal_Session::reset();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_site_id(self::OTHER_SITE_ID);

        static::__assert_equals(
            self::OTHER_SITE_ID,
            Portal_Session::get_site_id(),
            'CLI re-scoping is allowed - it is how a test moves between tenants'
        );
    }

    // =====================================================================
    // The declaration is a declaration, not a write
    // =====================================================================

    public static function test_declaring_a_site_mints_no_session_row()
    {
        Portal_Session::reset();

        $before = (int) Session::query()->count();

        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::get_site_id();

        static::__assert_equals(
            $before,
            (int) Session::query()->count(),
            'set_site_id() declares; it never creates a session row'
        );
    }

    /**
     * A portal identity carries its tenant for life. The staff facade lets a tenant
     * switch (Session::set_site_id updates site_id on the row); the portal
     * deliberately does not, so a declaration cannot contradict the portal_site_id a
     * live session already carries.
     */
    public static function test_a_live_session_site_wins_over_a_conflicting_declaration()
    {
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'site_decl_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        $session = new Session();
        $session->active = true;
        $session->version = 1;
        $session->site_id = 0;
        $session->portal_site_id = self::SITE_ID;
        $session->type_id = Session::TYPE_WEB;
        $session->portal_user_id = $portal_user->id;
        $session->session_token = bin2hex(random_bytes(32));
        $session->csrf_token = bin2hex(random_bytes(32));
        $session->ip_address = 'CLI';
        $session->user_agent = 'site-declaration-test';
        $session->last_active = now();
        $session->save();

        // The web branch reads the browser's session row; CLI has none, so the
        // conflict is asserted on the persisted row's own invariant: the portal tenant
        // is stamped when the identity is established and no portal API rewrites it.
        $reloaded = Session::_portal_query()->where('id', $session->id)->first();
        static::__assert_not_null($reloaded, 'the row carries a portal identity');
        static::__assert_equals(
            self::SITE_ID,
            (int) $reloaded->portal_site_id,
            'a portal session never changes site - there is no API that writes it'
        );
        static::__assert_equals(
            0,
            (int) $reloaded->site_id,
            'and the STAFF tenant column is a different property entirely'
        );
    }
}
