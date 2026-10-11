<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The audit session: the permanent record of an acting context that a logged act points
 * at instead of a session row.
 *
 * What is pinned: one context is one row however often it is asked for; a different
 * identity, tenant or impersonator is a different row and returning to the first context
 * returns its row; the record works with no session at all (this runner is a CLI process,
 * which is exactly the case a session id could never attribute); a row minted inside a
 * rolled-back transaction is not answered again; and the framework stamps the id on
 * every recorded unit of work.
 *
 * Identity lives in process statics that a transaction rollback does not undo, so every
 * test resets it first.
 */
class Audit_Session_Test extends Rsx_Test_Abstract
{
    private static function __start_clean(): void
    {
        Session::logout();
        Session::cli_set_impersonator_login_user_id(null);
        static::__reset_session();
    }

    private static function __row(int $id): ?object
    {
        return DB::selectOne('SELECT * FROM _audit_sessions WHERE id = ?', [$id]);
    }

    public static function test_one_context_is_one_row()
    {
        static::__start_clean();
        Session::impersonate(1, 501, 601);

        $first = Session::get_audit_session_id();
        $second = Session::get_audit_session_id();

        static::__assert_equals($first, $second);

        $row = static::__row($first);
        static::__assert_equals(1, (int) $row->site_id);
        static::__assert_equals(501, (int) $row->login_user_id);
        static::__assert_null($row->portal_user_id);
        static::__assert_null($row->impersonator_login_user_id);
        static::__assert_equals(Session::TYPE_CLI, (int) $row->session_type_id);
    }

    /**
     * A command or a task has a context and no session. Asking for the audit session
     * must not mint one.
     */
    public static function test_a_process_with_no_session_is_attributable_and_mints_none()
    {
        static::__start_clean();
        Session::impersonate(1, 502, 602);

        $had_session = Session::has_session();
        $row = static::__row(Session::get_audit_session_id());

        static::__assert_null($row->session_row_id, 'no session row is recorded for a CLI process');
        static::__assert_null($row->ip_address);
        static::__assert_null($row->user_agent_id);
        static::__assert_equals($had_session, Session::has_session(), 'asking created no session');
    }

    public static function test_a_different_identity_or_tenant_is_a_different_row()
    {
        static::__start_clean();

        Session::impersonate(1, 503, 603);
        $first = Session::get_audit_session_id();

        Session::impersonate(1, 504, 604);
        $other_identity = Session::get_audit_session_id();

        Session::impersonate(2, 503, 603);
        $other_site = Session::get_audit_session_id();

        static::__assert_not_equals($first, $other_identity);
        static::__assert_not_equals($first, $other_site);
        static::__assert_not_equals($other_identity, $other_site);

        Session::impersonate(1, 503, 603);
        static::__assert_equals($first, Session::get_audit_session_id(), 'the first context is the first row again');
    }

    public static function test_nobody_signed_in_is_still_a_context()
    {
        static::__start_clean();

        $row = static::__row(Session::get_audit_session_id());

        static::__assert_null($row->login_user_id);
        static::__assert_null($row->site_id);
    }

    public static function test_impersonation_is_recorded_as_its_own_context()
    {
        static::__start_clean();
        Session::impersonate(1, 505, 605);
        $own = Session::get_audit_session_id();

        Session::cli_set_impersonator_login_user_id(999);
        $impersonating = Session::get_audit_session_id();

        static::__assert_not_equals($own, $impersonating);
        static::__assert_equals(999, (int) static::__row($impersonating)->impersonator_login_user_id);
    }

    /**
     * The memo must not outlive the row: a context minted inside a transaction that
     * rolls back is gone, and the next ask has to mint it again.
     */
    public static function test_a_row_rolled_back_is_not_answered_again()
    {
        static::__start_clean();
        Session::impersonate(1, 506, 606);

        DB::beginTransaction();
        $rolled_back = Session::get_audit_session_id();
        DB::rollBack();

        static::__assert_null(static::__row($rolled_back), 'the rolled-back row is gone');

        $again = Session::get_audit_session_id();

        static::__assert_not_null(static::__row($again), 'the context was minted again');
    }

    public static function test_a_user_agent_is_stored_once_and_in_full()
    {
        $long = 'Mozilla/5.0 (' . str_repeat('very long token; ', 60) . ') AppleWebKit/537.36';

        $first = Audit_Session::user_agent_id($long);
        $second = Audit_Session::user_agent_id($long);
        $other = Audit_Session::user_agent_id($long . ' Edg/1');

        static::__assert_equals($first, $second);
        static::__assert_not_equals($first, $other);
        static::__assert_greater_than(512, strlen($long));
        static::__assert_equals($long, DB::selectOne('SELECT user_agent FROM _user_agents WHERE id = ?', [$first])->user_agent);
        static::__assert_equals(1, (int) DB::selectOne('SELECT COUNT(*) AS c FROM _user_agents WHERE ua_hash = ?', [hash('sha256', $long)])->c);
    }

    public static function test_no_user_agent_is_null()
    {
        static::__assert_null(Audit_Session::user_agent_id(null));
        static::__assert_null(Audit_Session::user_agent_id(''));
        static::__assert_null(Audit_Session::user_agent_string(null));
    }

    /**
     * An application stores the id and reads the context back through the framework,
     * with the user agent as a string - never from the table.
     */
    public static function test_an_audit_session_is_read_back_through_the_framework()
    {
        static::__start_clean();
        Session::impersonate(1, 508, 608);

        $id = Session::get_audit_session_id();
        $agent_id = Audit_Session::user_agent_id('audit-read-back/1.0');
        DB::statement('UPDATE _audit_sessions SET user_agent_id = ? WHERE id = ?', [$agent_id, $id]);

        $read = Session::get_audit_session($id);

        static::__assert_equals($id, $read['id']);
        static::__assert_equals(508, $read['login_user_id']);
        static::__assert_equals(1, $read['site_id']);
        static::__assert_equals('audit-read-back/1.0', $read['user_agent']);
        static::__assert_equals(Session::TYPE_CLI, $read['session_type_id']);
        static::__assert_not_empty($read['first_seen_at']);
        static::__assert_null(Session::get_audit_session(0), 'no such audit session');
    }

    /**
     * A session row answers its user agent as the string it was given, however long.
     */
    public static function test_a_session_row_answers_its_user_agent_as_a_string()
    {
        $long = 'Mozilla/5.0 (' . str_repeat('x', 400) . ')';

        $session = new Session();
        $session->session_token = bin2hex(random_bytes(32));
        $session->csrf_token = bin2hex(random_bytes(32));
        $session->ip_address = '127.0.0.1';
        $session->user_agent = $long;
        $session->last_active = now();
        $session->active = true;
        $session->site_id = 0;
        $session->type_id = Session::TYPE_WEB;
        $session->version = 1;
        $session->save();

        Audit_Session::_reset();

        static::__assert_equals($long, Session::find($session->id)->user_agent);
    }

    /**
     * The revision lane is attributed by the framework itself: a recorded unit of work
     * carries the audit session of whoever was acting when it was minted.
     */
    public static function test_a_recorded_unit_of_work_carries_the_audit_session()
    {
        static::__start_clean();
        Session::impersonate(1, 507, 607);

        $transaction_id = Revision::unit_of_work('audit session stamp', function () {
            return Revision::transaction_id();
        });

        $stamped = DB::selectOne('SELECT audit_session_id FROM _transactions WHERE id = ?', [$transaction_id])->audit_session_id;

        static::__assert_equals(Session::get_audit_session_id(), (int) $stamped);
    }
}
