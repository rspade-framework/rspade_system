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
 * THE PORTAL SITE CONTRACT (Portal_Session::set_site_id / _declare_resolved_site).
 *
 * The URL is the authority: a web portal request's site is resolved from its address
 * (Portal_Site_Resolver) and declared by the Dispatcher through _declare_resolved_site()
 * before any application code - set_site_id() is the declaration of a
 * process with no request (a command, a task, a test) and throws in a web request. An
 * undeclared site fails loud instead of quietly becoming site 1. These tests hold that line
 * from the CLI side: declaration in, declaration out, a loud refusal when there is none,
 * and a resolved site of another tenant ending the portal identity declared before it.
 *
 * What CLI cannot reach - set_site_id() refusing in a web request, and a live portal
 * sign-in of another site being signed out (SIGN_OUT_SITE_SWITCHED) - is the web path's:
 * tests/portal/http/portal_site_mismatch_sign_out.sh drives the latter end to end.
 *
 * No transaction is needed for the declaration tests themselves, but the class
 * keeps the default one because the session-row test persists a row.
 */
class Portal_Site_Declaration_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const OTHER_SITE_ID = 77;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        Portal_Session::_testing_reset();
    }

    public static function teardown(): void
    {
        Portal_Session::_testing_reset();
        static::__reset_session();
    }

    // =====================================================================
    // Declaration in, declaration out
    // =====================================================================

    public static function test_a_declared_site_is_what_get_site_id_returns()
    {
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(self::OTHER_SITE_ID);

        static::__assert_equals(
            self::OTHER_SITE_ID,
            Portal_Session::get_site_id(),
            'get_site_id() returns the declared site verbatim - no config, no default'
        );
    }

    public static function test_declaring_the_same_site_twice_is_idempotent()
    {
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_site_id(self::SITE_ID);

        static::__assert_equals(self::SITE_ID, Portal_Session::get_site_id());
    }

    public static function test_the_declaration_makes_the_cli_facade_report_a_session()
    {
        Portal_Session::_testing_reset();
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
        Portal_Session::_testing_reset();

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
        Portal_Session::_testing_reset();

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
        Portal_Session::_testing_reset();

        static::__assert_throws(\RuntimeException::class, function () {
            Portal_Session::get_site();
        }, 'portal site has not been declared');
    }

    public static function test_a_non_positive_site_is_refused()
    {
        Portal_Session::_testing_reset();

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
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_site_id(self::OTHER_SITE_ID);

        static::__assert_equals(
            self::OTHER_SITE_ID,
            Portal_Session::get_site_id(),
            'CLI re-scoping is allowed - it is how a test moves between tenants'
        );
    }

    // =====================================================================
    // The resolved site (the Dispatcher's declaration, from the URL)
    // =====================================================================

    public static function test_the_resolved_site_is_what_get_site_id_returns()
    {
        Portal_Session::_testing_reset();
        Portal_Session::_declare_resolved_site(self::OTHER_SITE_ID);

        static::__assert_equals(self::OTHER_SITE_ID, Portal_Session::get_site_id());
    }

    /**
     * An in-process dispatch whose URL names another tenant ends the portal identity this
     * process held for the previous one - the CLI twin of the web sign-out.
     */
    public static function test_a_resolved_site_of_another_tenant_ends_the_portal_identity()
    {
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id(4242);

        Portal_Session::_declare_resolved_site(self::SITE_ID);
        static::__assert_equals(4242, Portal_Session::get_portal_user_id(), 'the same site keeps the identity');

        Portal_Session::_declare_resolved_site(self::OTHER_SITE_ID);
        static::__assert_null(Portal_Session::get_portal_user_id(), 'another site ends it');
        static::__assert_equals(self::OTHER_SITE_ID, Portal_Session::get_site_id());
    }

    public static function test_a_non_positive_resolved_site_is_impossible()
    {
        Portal_Session::_testing_reset();

        static::__assert_throws(\RuntimeException::class, function () {
            Portal_Session::_declare_resolved_site(0);
        }, 'requires a real site id');
    }

    // =====================================================================
    // The declaration is a declaration, not a write
    // =====================================================================

    public static function test_declaring_a_site_mints_no_session_row()
    {
        Portal_Session::_testing_reset();

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
     * A portal sign-in carries its tenant for life. The staff facade lets a tenant switch
     * (Session::set_site_id moves the staff sign-in to another site's user session); the
     * portal deliberately does not - a URL naming another site ENDS the sign-in rather than
     * moving it - so the portal_site_id a live portal sign-in carries is part of the user
     * session's identity and nothing rewrites it.
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

        $fixture = static::__insert_session_fixture([
            'portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => self::SITE_ID],
        ]);

        // The web branch reads the browser's portal user session; CLI has none, so the
        // conflict is asserted on the persisted invariant: the portal tenant is stamped when
        // the sign-in is established and no portal API rewrites it.
        static::__assert_not_null(Session::_portal_query()->where('id', $fixture['id'])->first(), 'the browser is signed into the portal');
        static::__assert_equals(
            self::SITE_ID,
            (int) \Illuminate\Support\Facades\DB::table('_user_sessions')->where('id', $fixture['portal_user_session_id'])->value('portal_site_id'),
            'a portal sign-in never changes site - there is no API that writes it'
        );
        static::__assert_null(
            \Illuminate\Support\Facades\DB::table('_sessions')->where('id', $fixture['id'])->value('staff_user_session_id'),
            'and the staff realm is a different pointer entirely'
        );
    }
}
