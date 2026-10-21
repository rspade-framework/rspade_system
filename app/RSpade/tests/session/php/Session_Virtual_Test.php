<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The VIRTUAL SESSION - what a process with no browser (a command, a task, this test
 * runner) runs. It never has a _sessions row, a _user_sessions row or an _audit_sessions
 * row: get_session_id() answers null, identity lives in statics, session values refuse a
 * write, and get_audit_session_id() answers null unless an audit session was declared.
 *
 * Complements Session_Cli_Test, which covers the declared identity's getters and setters.
 */
class Session_Virtual_Test extends Rsx_Test_Abstract
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
     * Row counts of the three session tables.
     *
     * @return array{sessions: int, user_sessions: int, audit_sessions: int}
     */
    private static function __counts(): array
    {
        return [
            'sessions' => DB::table('_sessions')->count(),
            'user_sessions' => DB::table('_user_sessions')->count(),
            'audit_sessions' => DB::table('_audit_sessions')->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // No browser, no row
    // -------------------------------------------------------------------------

    public static function test_get_session_id_is_null()
    {
        Session::reset_impersonation();

        static::__assert_true(Session::_is_virtual(), 'a CLI process runs a virtual session');
        static::__assert_null(Session::get_session_id(), 'a process with no browser has no session id');
        static::__assert_false(Session::has_session(), 'nothing declared, no session context');
    }

    public static function test_get_session_throws()
    {
        static::__assert_throws(\RuntimeException::class, fn () => Session::get_session(), 'virtual session');
    }

    public static function test_declaring_a_context_is_a_session_context_with_no_row()
    {
        Session::impersonate(1, 1, 1);

        static::__assert_true(Session::has_session(), 'a declared identity is a session context');
        static::__assert_null(Session::get_session_id(), 'and still has no row');
    }

    /**
     * A command that signs in and out, impersonates, switches site and records a unit of
     * revision history writes nothing to any session table - and its transaction carries
     * no audit session.
     */
    public static function test_a_command_signing_in_and_out_and_recording_creates_no_session_rows()
    {
        $before = static::__counts();

        Session::impersonate(1, 1, 1);
        Session::set_login_user_id(1);
        Session::set_site_id(1);
        Session::cli_set_impersonator_login_user_id(null);
        Session::get_session_id();
        Session::get_csrf_token();
        Session::get_user_session_id();
        $audit_session_id = Session::get_audit_session_id();
        Session::logout();
        Session::set_login_user_id(1);

        Revision::_reset_request_state('test', 'Session_Virtual_Test');
        $transaction_id = Revision::transaction_id();

        static::__assert_null($audit_session_id, 'no audit session in a virtual session');
        static::__assert_null(
            DB::table('_transactions')->where('id', $transaction_id)->value('audit_session_id'),
            'the recorded unit of work carries no audit session'
        );
        static::__assert_equals($before, static::__counts(), 'no _sessions, _user_sessions or _audit_sessions row was written');
    }

    public static function test_a_declared_audit_session_is_answered()
    {
        Session::_begin_virtual(['site_id' => 1, 'login_user_id' => 1, 'user_id' => 1]);
        \App\RSpade\Core\Session\Audit_Session::_declare(424242);

        try {
            static::__assert_equals(424242, Session::get_audit_session_id(), 'the declared acting context is the answer');
        } finally {
            Session::_end_virtual();
        }

        static::__assert_null(Session::get_audit_session_id(), '_end_virtual() ends the declaration');
    }

    // -------------------------------------------------------------------------
    // _begin_virtual() / _end_virtual()
    // -------------------------------------------------------------------------

    public static function test_begin_virtual_declares_the_context_given()
    {
        Session::_begin_virtual(['site_id' => 3, 'login_user_id' => 7, 'user_id' => 9, 'impersonator_login_user_id' => 2]);

        try {
            static::__assert_equals(3, Session::get_site_id());
            static::__assert_equals(7, Session::get_login_user_id());
            static::__assert_equals(9, Session::get_user_id());
            static::__assert_true(Session::is_impersonating(), 'the impersonator is declared');
            static::__assert_equals(2, Session::get_impersonator_login_user_id());
        } finally {
            Session::_end_virtual();
        }
    }

    public static function test_end_virtual_blanks_the_process()
    {
        Session::_begin_virtual(['site_id' => 3, 'login_user_id' => 7, 'user_id' => 9]);
        Session::set_temporary_site_id(5);

        Session::_end_virtual();

        static::__assert_equals(0, Session::get_site_id(), 'no site');
        static::__assert_null(Session::get_login_user_id(), 'nobody');
        static::__assert_null(Session::get_user_id());
        static::__assert_false(Session::has_temporary_site_id(), 'the declared tenant ends too');
        static::__assert_false(Session::is_impersonating());
        static::__assert_false(Session::has_session());
    }

    public static function test_begin_virtual_starts_fresh()
    {
        Session::_begin_virtual(['site_id' => 3, 'login_user_id' => 7, 'user_id' => 9, 'impersonator_login_user_id' => 2]);
        Session::_begin_virtual(['site_id' => 4]);

        try {
            static::__assert_equals(4, Session::get_site_id());
            static::__assert_null(Session::get_login_user_id(), 'the previous identity is gone');
            static::__assert_false(Session::is_impersonating(), 'and its impersonation');
        } finally {
            Session::_end_virtual();
        }
    }

    // -------------------------------------------------------------------------
    // Session values belong to a browser
    // -------------------------------------------------------------------------

    public static function test_put_value_refuses_with_no_browser()
    {
        Session::impersonate(1, 1, 1);

        static::__assert_throws(Rsx_Caller_Exception::class, fn () => Session::put_value('virtual_key', 1), 'belong to a browser');
    }

    public static function test_the_value_readers_answer_absent_with_no_browser()
    {
        Session::impersonate(1, 1, 1);
        $before = static::__counts();

        static::__assert_equals('default', Session::get_value('virtual_key', 'default'), 'no browser, no value');
        Session::forget_value('virtual_key');

        static::__assert_equals($before, static::__counts(), 'the readers create nothing');
    }

    /**
     * A test acting as a browser (Rsx_Test_Abstract::__as_browser()) has a row to keep
     * values on, and get_session_id() answers it.
     */
    public static function test_a_test_acting_as_a_browser_has_its_row()
    {
        $session_id = static::__as_browser();

        static::__assert_equals($session_id, Session::get_session_id());
        static::__assert_true(Session::has_session());
        static::__assert_equals($session_id, (int) Session::get_session()->id);

        Session::put_value('virtual_key', ['a' => 1]);

        static::__assert_equals(['a' => 1], Session::get_value('virtual_key'));
        static::__assert_equals(1, DB::table('_session_values')->where('session_id', $session_id)->count());

        Session::forget_value('virtual_key');

        static::__assert_null(Session::get_value('virtual_key'));
    }

    // -------------------------------------------------------------------------
    // The hard exits
    // -------------------------------------------------------------------------

    public static function test_reset_ends_the_declared_impersonation_and_writes_nothing()
    {
        Session::impersonate(1, 1, 1);
        Session::cli_set_impersonator_login_user_id(2);
        $before = static::__counts();

        Session::reset();

        static::__assert_false(Session::is_impersonating(), 'reset is the hard exit');
        static::__assert_equals($before, static::__counts());
    }
}
