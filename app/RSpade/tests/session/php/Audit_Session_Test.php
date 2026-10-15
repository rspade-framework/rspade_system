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
use App\RSpade\Core\Session\Ip_Address;
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
        static::__assert_null($row->ip_address_id);
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
     * join() puts the audit session on a query of the caller's own: every published field
     * as audit_<name>, the base table's columns kept, and rows with no audit session
     * still listed.
     */
    public static function test_join_adds_the_audit_fields_to_a_query_of_the_callers_own()
    {
        static::__start_clean();
        Session::impersonate(1, 509, 609);

        $audit_session_id = Session::get_audit_session_id();
        $agent_id = Audit_Session::user_agent_id('audit-join/1.0');
        DB::statement('UPDATE _audit_sessions SET user_agent_id = ?, ip_address_id = ? WHERE id = ?', [$agent_id, Ip_Address::id('203.0.113.9'), $audit_session_id]);

        $attributed = DB::table('_login_history')->insertGetId([
            'login_user_id' => $audit_session_id, 'email_attempted' => 'join@rspade.test', 'ip_address_id' => Ip_Address::id('10.0.0.1'), 'status' => 'success',
        ]);
        $unattributed = DB::table('_login_history')->insertGetId([
            'login_user_id' => 0, 'email_attempted' => 'join-none@rspade.test', 'ip_address_id' => Ip_Address::id('10.0.0.2'), 'status' => 'success',
        ]);

        // Any column holding an audit session id will do; login_user_id stands in for one.
        $query = DB::table('_login_history')->whereIn('_login_history.id', [$attributed, $unattributed]);
        $returned = Audit_Session::join($query, '_login_history.login_user_id');
        $rows = $query->orderBy('_login_history.id')->get()->all();

        static::__assert_true($returned === $query, 'the same query comes back');
        static::__assert_count(2, $rows, 'a row with no audit session is still listed');

        static::__assert_equals('join@rspade.test', $rows[0]->email_attempted, "the query's own columns are kept");
        static::__assert_equals('203.0.113.9', $rows[0]->audit_ip_address);
        static::__assert_equals('audit-join/1.0', $rows[0]->audit_user_agent);
        static::__assert_equals(509, (int) $rows[0]->audit_login_user_id);
        static::__assert_equals(1, (int) $rows[0]->audit_site_id);
        static::__assert_equals(Session::TYPE_CLI, (int) $rows[0]->audit_session_type_id);
        static::__assert_not_empty($rows[0]->audit_first_seen_at);

        static::__assert_null($rows[1]->audit_ip_address);
        static::__assert_null($rows[1]->audit_user_agent);

        static::__assert_equals(
            array_keys(array_diff_key(Session::get_audit_session($audit_session_id), ['id' => true])),
            array_map(fn ($key) => substr($key, 6), array_values(array_filter(array_keys((array) $rows[0]), fn ($key) => str_starts_with($key, 'audit_')))),
            'the joined fields are exactly the fields get_audit_session() answers'
        );
    }

    /**
     * A condition or an ordering on an audit field is written with column(), so the
     * caller never names the tables behind the join.
     */
    public static function test_column_names_an_audit_field_for_filtering_and_sorting()
    {
        static::__start_clean();
        Session::impersonate(1, 510, 610);

        $audit_session_id = Session::get_audit_session_id();
        DB::statement('UPDATE _audit_sessions SET ip_address_id = ? WHERE id = ?', [Ip_Address::id('198.51.100.7'), $audit_session_id]);

        DB::table('_login_history')->insert([
            ['login_user_id' => $audit_session_id, 'email_attempted' => 'col-a@rspade.test', 'ip_address_id' => Ip_Address::id('x'), 'status' => 'success'],
            ['login_user_id' => 0, 'email_attempted' => 'col-b@rspade.test', 'ip_address_id' => Ip_Address::id('x'), 'status' => 'success'],
        ]);

        $query = DB::table('_login_history')->whereIn('email_attempted', ['col-a@rspade.test', 'col-b@rspade.test']);
        Audit_Session::join($query, '_login_history.login_user_id');

        $matches = $query->where(Audit_Session::column('ip_address'), '198.51.100.7')
            ->orderBy(Audit_Session::column('first_seen_at'), 'desc')
            ->get()
            ->all();

        static::__assert_count(1, $matches);
        static::__assert_equals('col-a@rspade.test', $matches[0]->email_attempted);

        static::__assert_throws(\InvalidArgumentException::class, fn () => Audit_Session::column('tuple_hash'), 'no audit field');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Audit_Session::join(DB::table('_login_history'), 'login_user_id'), 'not a qualified column');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Audit_Session::join(DB::table('_login_history'), 'a.b; DROP TABLE x'));
    }

    /**
     * On an Eloquent query the rows are still the caller's models, with the audit fields
     * as attributes.
     */
    public static function test_join_works_on_an_eloquent_query()
    {
        static::__start_clean();
        Session::impersonate(1, 511, 611);

        $transaction_id = Revision::unit_of_work('audit join', fn () => Revision::transaction_id());

        $query = \App\RSpade\Core\Revisions\Transaction_Model::query()->where('_transactions.id', $transaction_id);
        Audit_Session::join($query, '_transactions.audit_session_id');
        $transaction = $query->first();

        static::__assert_instance_of(\App\RSpade\Core\Revisions\Transaction_Model::class, $transaction);
        static::__assert_equals($transaction_id, (int) $transaction->id);
        static::__assert_equals(511, (int) $transaction->audit_login_user_id);
        static::__assert_equals('audit join', $transaction->description, "the model's own columns are kept");
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
