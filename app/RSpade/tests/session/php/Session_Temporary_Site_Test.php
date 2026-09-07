<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Session::set_temporary_site_id() - DECLARING a tenant for the rest of
 * the script without writing anything anywhere.
 *
 * The property that matters, and the reason the method exists rather than reusing
 * set_site_id(): it must create NO _sessions row and emit NO cookie, in either
 * mode, while still making get_site_id() and has_session() answer for the declared
 * tenant. set_site_id() cannot promise that - in web mode it writes site_id onto an
 * existing row, and the sign-in path it shares can bring a row and a Set-Cookie into
 * being.
 *
 * Row creation is asserted directly by counting _sessions. The no-cookie half is
 * structural rather than observable from a PHP test: the method body is three static
 * assignments with no mode branch, and the get_site_id()/has_session() branches that
 * read it are placed BEFORE their self::init() calls - so in web mode the cookie is
 * never even read, let alone set. Session_Cookie_Flags_Test covers the paths that do
 * emit one.
 */
class Session_Temporary_Site_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::reset_impersonation();
    }

    /**
     * A clean slate for ONE test.
     *
     * setup() runs once per CLASS, not per method (Rsx_Test_Abstract::_run_tests),
     * so static session state carries between the tests below unless each one clears
     * it for itself. Every test here starts by calling this.
     */
    private static function __fresh(): void
    {
        Session::reset_impersonation();
    }

    private static function __session_row_count(): int
    {
        return (int) DB::table('_sessions')->count();
    }

    // =====================================================================
    // The core promise: it writes nothing
    // =====================================================================

    /**
     * No _sessions row comes into existence, however much is asked of the session
     * afterwards. This is the whole point of the method.
     */
    public static function test_declaring_a_tenant_creates_no_session_row()
    {
        self::__fresh();

        $before = self::__session_row_count();

        Session::set_temporary_site_id(4242);

        // Exercise every accessor a caller might reach for - none may mint.
        Session::has_session();
        Session::get_site_id();
        Session::is_logged_in();
        Session::get_login_user_id();
        Session::get_user_id();

        static::__assert_equals($before, self::__session_row_count());
    }

    /**
     * An existing session row is NOT rewritten - the declaration is ambient, not a
     * mutation. A browser mid-session keeps the tenant it had, and gets it back when
     * the declaration is cleared.
     */
    public static function test_an_existing_row_is_left_untouched()
    {
        self::__fresh();

        Session::impersonate(9, null, null);
        $session_id = Session::get_session_id();

        $before = DB::table('_sessions')->where('id', $session_id)->value('site_id');
        static::__assert_equals(9, (int) $before);

        Session::set_temporary_site_id(4242);

        $after = DB::table('_sessions')->where('id', $session_id)->value('site_id');
        static::__assert_equals(9, (int) $after, 'the stored row must not be rewritten');

        // ...and the declaration still wins for the reader.
        static::__assert_equals(4242, Session::get_site_id());

        Session::clear_temporary_site_id();
        static::__assert_equals(9, Session::get_site_id(), 'clearing returns to the stored row');
    }

    // =====================================================================
    // What the declaration makes true
    // =====================================================================

    public static function test_get_site_id_returns_the_declared_tenant()
    {
        self::__fresh();

        static::__assert_equals(0, Session::get_site_id());

        Session::set_temporary_site_id(7);

        static::__assert_equals(7, Session::get_site_id());
    }

    /**
     * has_session() must answer TRUE. Site-scoped code guards on it before trusting
     * get_site_id(), so a declared tenant that failed that guard would be a tenant
     * half the framework refused to see - which is the bug this method exists to fix.
     */
    public static function test_has_session_is_true_for_a_declared_tenant()
    {
        self::__fresh();

        static::__assert_false(Session::has_session());

        Session::set_temporary_site_id(7);

        static::__assert_true(Session::has_session());
    }

    /**
     * A tenant is not an identity. Declaring one signs nobody in.
     */
    public static function test_declaring_a_tenant_signs_nobody_in()
    {
        self::__fresh();

        Session::set_temporary_site_id(7);

        static::__assert_false(Session::is_logged_in());
        static::__assert_null(Session::get_login_user_id());
        static::__assert_null(Session::get_user_id());
    }

    /**
     * It outranks the CLI static, because the caller is asserting the context this
     * execution runs in.
     */
    public static function test_it_outranks_a_declared_cli_context()
    {
        self::__fresh();

        Session::impersonate(9, null, null);
        static::__assert_equals(9, Session::get_site_id());

        Session::set_temporary_site_id(4242);
        static::__assert_equals(4242, Session::get_site_id());
    }

    // =====================================================================
    // Clearing
    // =====================================================================

    public static function test_clear_returns_to_normal_resolution()
    {
        self::__fresh();

        Session::set_temporary_site_id(7);
        static::__assert_true(Session::has_temporary_site_id());

        Session::clear_temporary_site_id();

        static::__assert_false(Session::has_temporary_site_id());
        static::__assert_false(Session::has_session());
        static::__assert_equals(0, Session::get_site_id());
    }

    /**
     * Safe with nothing in force - it is one assignment, not an undo of a write.
     */
    public static function test_clear_is_safe_when_nothing_was_declared()
    {
        self::__fresh();

        $before = self::__session_row_count();

        Session::clear_temporary_site_id();

        static::__assert_false(Session::has_temporary_site_id());
        static::__assert_equals($before, self::__session_row_count());
    }

    /**
     * Re-declaring replaces rather than stacking.
     */
    public static function test_redeclaring_replaces_the_previous_value()
    {
        self::__fresh();

        Session::set_temporary_site_id(7);
        Session::set_temporary_site_id(8);

        static::__assert_equals(8, Session::get_site_id());

        Session::clear_temporary_site_id();

        static::__assert_equals(0, Session::get_site_id(), 'one clear is enough - values do not stack');
    }

    /**
     * The test reset seam drops it, so a declaration cannot leak into the next test.
     */
    public static function test_reset_impersonation_clears_the_declaration()
    {
        self::__fresh();

        Session::set_temporary_site_id(7);

        Session::reset_impersonation();

        static::__assert_false(Session::has_temporary_site_id());
        static::__assert_equals(0, Session::get_site_id());
    }
}
