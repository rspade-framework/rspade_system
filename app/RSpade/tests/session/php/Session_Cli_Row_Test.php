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
 * Tests for the CLI session ROW - Session::get_session_id() yields a real session in
 * every mode, so a CLI process mints a TYPE_CLI _sessions row on first demand, holds
 * it for the process, and deletes it at process end.
 *
 * Complements Session_Cli_Test, which covers the CLI identity overrides (site, login
 * user, impersonation) that create no row at all.
 *
 * The mint and the delete both happen inside the per-test transaction, so they are
 * fully observable here and roll back with everything else.
 */
class Session_Cli_Row_Test extends Rsx_Test_Abstract
{
    /**
     * Every test starts from a clean slate: no identity overrides, no session row.
     * reset_impersonation() drops both.
     */
    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::reset_impersonation();
    }

    /**
     * The _sessions row for a session id, as a raw record (no model, no scopes).
     */
    private static function __row(int $session_id)
    {
        return DB::table('_sessions')->where('id', $session_id)->first();
    }

    // -------------------------------------------------------------------------
    // Minting
    // -------------------------------------------------------------------------

    public static function test_first_get_session_id_creates_a_real_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        $row = static::__row($session_id);

        static::__assert_not_null($row, 'get_session_id() created a _sessions row in CLI');
        static::__assert_equals(Session::TYPE_CLI, (int) $row->type_id, 'row is stamped TYPE_CLI');
        static::__assert_equals('CLI', $row->ip_address, 'ip_address records what created the row');
        static::__assert_equals('CLI', $row->user_agent, 'user_agent records what created the row');
        static::__assert_equals(1, (int) $row->active, 'the row is active');
        static::__assert_not_empty($row->session_token, 'a token is minted (never transmitted)');
        static::__assert_not_empty($row->csrf_token, 'a csrf token is minted');
    }

    public static function test_second_call_returns_the_same_session()
    {
        Session::reset_impersonation();

        $first = Session::get_session_id();
        $second = Session::get_session_id();

        static::__assert_equals($first, $second, 'the process holds one session for its lifetime');
        static::__assert_equals(1, DB::table('_sessions')->where('id', $first)->count(), 'exactly one row');
    }

    public static function test_get_session_returns_the_same_row_as_get_session_id()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        $session = Session::get_session();

        static::__assert_instance_of(Session::class, $session);
        static::__assert_equals($session_id, (int) $session->id);
    }

    public static function test_has_session_is_true_once_a_row_is_minted()
    {
        Session::reset_impersonation();
        static::__assert_false(Session::has_session(), 'nothing demanded a session yet');

        Session::get_session_id();

        static::__assert_true(Session::has_session(), 'the minted row is a session');
    }

    public static function test_csrf_token_comes_from_the_minted_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        $row = static::__row($session_id);

        static::__assert_equals($row->csrf_token, Session::get_csrf_token());
        static::__assert_true(Session::verify_csrf_token($row->csrf_token));
    }

    // -------------------------------------------------------------------------
    // Laziness - a command that never asks creates nothing
    // -------------------------------------------------------------------------

    public static function test_reading_identity_mints_nothing()
    {
        Session::reset_impersonation();

        $before = DB::table('_sessions')->where('type_id', Session::TYPE_CLI)->count();

        Session::get_site_id();
        Session::get_login_user_id();
        Session::get_user_id();
        Session::is_logged_in();
        Session::has_session();

        $after = DB::table('_sessions')->where('type_id', Session::TYPE_CLI)->count();

        static::__assert_equals($before, $after, 'the getters create nothing');
    }

    public static function test_declaring_identity_mints_nothing()
    {
        Session::reset_impersonation();

        $before = DB::table('_sessions')->where('type_id', Session::TYPE_CLI)->count();

        Session::set_site_id(7);
        Session::set_login_user_id(4242);
        Session::impersonate(7, 4242, 99);

        $after = DB::table('_sessions')->where('type_id', Session::TYPE_CLI)->count();

        static::__assert_equals($before, $after, 'the setters declare context, they do not demand a session');
    }

    // -------------------------------------------------------------------------
    // The row follows the CLI identity overrides
    // -------------------------------------------------------------------------

    public static function test_set_login_user_id_attaches_the_login_to_the_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        Session::set_login_user_id(4242);

        $row = static::__row($session_id);
        static::__assert_equals(4242, (int) $row->login_user_id, 'the login lands on the session row');
    }

    public static function test_logout_clears_the_login_on_the_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        Session::set_login_user_id(4242);
        Session::logout();

        $row = static::__row($session_id);
        static::__assert_null($row->login_user_id, 'logout clears the row too');
    }

    public static function test_set_site_id_lands_on_the_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        Session::set_site_id(7);

        $row = static::__row($session_id);
        static::__assert_equals(7, (int) $row->site_id);
    }

    public static function test_identity_declared_before_the_demand_seeds_the_row()
    {
        Session::reset_impersonation();

        Session::impersonate(7, 4242, 99);
        $session_id = Session::get_session_id();

        $row = static::__row($session_id);
        static::__assert_equals(7, (int) $row->site_id, 'site seeded at mint time');
        static::__assert_equals(4242, (int) $row->login_user_id, 'login seeded at mint time');
    }

    // -------------------------------------------------------------------------
    // End of life
    // -------------------------------------------------------------------------

    public static function test_end_of_process_cleanup_deletes_the_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        static::__assert_not_null(static::__row($session_id), 'row exists mid-process');

        // The shutdown hook's body, called directly - a test cannot exit the process.
        Session::_cli_end_session();

        static::__assert_null(static::__row($session_id), 'the row is gone when the process ends');
        static::__assert_false(Session::has_session(), 'and the handle is forgotten');
    }

    public static function test_cleanup_is_a_no_op_when_nothing_was_minted()
    {
        Session::reset_impersonation();

        $before = DB::table('_sessions')->count();
        Session::_cli_end_session();
        $after = DB::table('_sessions')->count();

        static::__assert_equals($before, $after, 'no session, nothing to delete');
    }

    public static function test_a_later_demand_mints_a_fresh_session()
    {
        Session::reset_impersonation();

        $first = Session::get_session_id();
        Session::_cli_end_session();
        $second = Session::get_session_id();

        static::__assert_false($first === $second, 'a new row, not a resurrection of the deleted one');
        static::__assert_not_null(static::__row($second));
    }

    public static function test_reset_impersonation_drops_the_row()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        Session::reset_impersonation();

        static::__assert_null(static::__row($session_id), 'a clean slate includes the session row');
    }

    public static function test_reset_deletes_rather_than_deactivates()
    {
        Session::reset_impersonation();

        $session_id = Session::get_session_id();
        Session::reset();

        static::__assert_null(static::__row($session_id), 'CLI reset is a delete, not a deactivation');
    }
}
