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
 * What is pinned: the KEY is the user session (a guest's act: the browser) x the site acted on
 * x the client address x the user agent - one context is one row however often it is asked
 * for, any of those changing is a different row, and returning to the first context returns
 * its row; a process with no browser (this runner, a command, a task) records NONE unless an
 * audit session was declared for it; a row minted inside a rolled-back transaction is not
 * answered again; and the framework stamps the id on every recorded unit of work.
 *
 * The test process runs a virtual session, so the keying is driven through
 * Audit_Session::_id_for_context() with contexts of the test's own. A real browser request is
 * http/audit_session.sh; an API key's calls are Api_Key_Session_Test.
 */
class Audit_Session_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Audit_Session::_declare(null);
    }

    private static function __row(int $id): ?object
    {
        return DB::selectOne('SELECT * FROM _audit_sessions WHERE id = ?', [$id]);
    }

    /**
     * A signed-in staff context; $overrides replaces any member.
     */
    private static function __context(array $overrides = []): array
    {
        return array_merge([
            'site_id' => 1,
            'login_user_id' => 501,
            'portal_user_id' => null,
            'portal_site_id' => null,
            'impersonator_login_user_id' => null,
            'impersonator_user_id' => null,
            'ip_address' => '10.0.0.1',
            'session_id' => 900001,
            'user_session_id' => 800001,
            'user_agent_id' => Audit_Session::user_agent_id('audit-test/1.0'),
            'session_type_id' => Session::TYPE_WEB,
            'timezone' => 'America/Chicago',
            'timezone_source' => 'browser',
        ], $overrides);
    }

    public static function test_a_process_with_no_browser_records_none()
    {
        Session::impersonate(1, 502, 602);
        $before = DB::table('_audit_sessions')->count();

        static::__assert_null(Session::get_audit_session_id(), 'a virtual session has no audit session');
        static::__assert_equals($before, DB::table('_audit_sessions')->count(), 'and asking minted none');
        static::__assert_null(Session::get_session_id(), 'nor a session');
    }

    public static function test_a_declared_audit_session_is_answered()
    {
        $declared = Audit_Session::_id_for_context(static::__context());

        Audit_Session::_declare($declared);

        static::__assert_equals($declared, Session::get_audit_session_id());
    }

    public static function test_one_context_is_one_row()
    {
        $first = Audit_Session::_id_for_context(static::__context());
        $second = Audit_Session::_id_for_context(static::__context());

        static::__assert_equals($first, $second);

        $row = static::__row($first);
        static::__assert_equals(1, (int) $row->site_id);
        static::__assert_equals(501, (int) $row->login_user_id);
        static::__assert_null($row->portal_user_id);
        static::__assert_null($row->impersonator_login_user_id);
        static::__assert_equals(900001, (int) $row->session_id);
        static::__assert_equals(800001, (int) $row->user_session_id);
        static::__assert_equals(Session::TYPE_WEB, (int) $row->session_type_id);
    }

    public static function test_a_different_user_session_address_agent_site_or_zone_is_a_different_row()
    {
        $first = Audit_Session::_id_for_context(static::__context());

        $others = [
            'user session' => Audit_Session::_id_for_context(static::__context(['user_session_id' => 800002])),
            'address' => Audit_Session::_id_for_context(static::__context(['ip_address' => '10.0.0.2'])),
            'user agent' => Audit_Session::_id_for_context(static::__context(['user_agent_id' => Audit_Session::user_agent_id('audit-test/2.0')])),
            'site' => Audit_Session::_id_for_context(static::__context(['site_id' => 2])),
            'display zone' => Audit_Session::_id_for_context(static::__context(['timezone' => 'Asia/Kolkata'])),
            'zone source' => Audit_Session::_id_for_context(static::__context(['timezone_source' => 'explicit'])),
        ];

        foreach ($others as $what => $id) {
            static::__assert_not_equals($first, $id, "a different {$what} is a different audit session");
        }

        static::__assert_equals(count($others), count(array_unique($others)), 'each is a row of its own');
        static::__assert_equals($first, Audit_Session::_id_for_context(static::__context()), 'the first context is the first row again');
    }

    /**
     * A guest has no user session: its acts are keyed on the browser.
     */
    public static function test_a_guest_is_keyed_on_its_browser()
    {
        $guest = ['login_user_id' => null, 'site_id' => null, 'user_session_id' => null];

        $one = Audit_Session::_id_for_context(static::__context($guest + ['session_id' => 900010]));
        $again = Audit_Session::_id_for_context(static::__context($guest + ['session_id' => 900010]));
        $other_browser = Audit_Session::_id_for_context(static::__context($guest + ['session_id' => 900011]));

        static::__assert_equals($one, $again);
        static::__assert_not_equals($one, $other_browser, 'another browser is another audit session');
        static::__assert_null(static::__row($one)->login_user_id);
        static::__assert_null(static::__row($one)->user_session_id);
    }

    /**
     * The memo must not outlive the row: a context minted inside a transaction that
     * rolls back is gone, and the next ask has to mint it again.
     */
    public static function test_a_row_rolled_back_is_not_answered_again()
    {
        DB::beginTransaction();
        $rolled_back = Audit_Session::_id_for_context(static::__context(['user_session_id' => 800020]));
        DB::rollBack();

        static::__assert_null(static::__row($rolled_back), 'the rolled-back row is gone');

        $again = Audit_Session::_id_for_context(static::__context(['user_session_id' => 800020]));

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
        $id = Audit_Session::_id_for_context(static::__context([
            'login_user_id' => 508,
            'user_session_id' => 800030,
            'user_agent_id' => Audit_Session::user_agent_id('audit-read-back/1.0'),
        ]));

        $read = Session::get_audit_session($id);

        static::__assert_equals($id, $read['id']);
        static::__assert_equals(508, $read['login_user_id']);
        static::__assert_equals(1, $read['site_id']);
        static::__assert_equals('10.0.0.1', $read['ip_address']);
        static::__assert_equals('audit-read-back/1.0', $read['user_agent']);
        static::__assert_equals(900001, $read['session_id']);
        static::__assert_equals(800030, $read['user_session_id']);
        static::__assert_equals(Session::TYPE_WEB, $read['session_type_id']);
        static::__assert_equals('America/Chicago', $read['timezone'], 'the display zone the context acted in');
        static::__assert_equals('browser', $read['timezone_source'], 'and where it came from');
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
        $audit_session_id = Audit_Session::_id_for_context(static::__context([
            'login_user_id' => 509,
            'user_session_id' => 800040,
            'ip_address' => '203.0.113.9',
            'user_agent_id' => Audit_Session::user_agent_id('audit-join/1.0'),
        ]));

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
        static::__assert_equals(Session::TYPE_WEB, (int) $rows[0]->audit_session_type_id);
        static::__assert_equals('America/Chicago', $rows[0]->audit_timezone);
        static::__assert_equals('browser', $rows[0]->audit_timezone_source);
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
        $audit_session_id = Audit_Session::_id_for_context(static::__context([
            'login_user_id' => 510,
            'user_session_id' => 800050,
            'ip_address' => '198.51.100.7',
        ]));

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
        Audit_Session::_declare(Audit_Session::_id_for_context(static::__context(['login_user_id' => 511, 'user_session_id' => 800060])));

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
        $session->type_id = Session::TYPE_WEB;
        $session->version = 1;
        $session->save();

        Audit_Session::_reset();

        static::__assert_equals($long, Session::find($session->id)->user_agent);
    }

    /**
     * The revision lane is attributed by the framework itself: a recorded unit of work
     * carries the audit session of whoever was acting when it was minted - and none where
     * nobody with a browser was.
     */
    public static function test_a_recorded_unit_of_work_carries_the_audit_session()
    {
        $declared = Audit_Session::_id_for_context(static::__context(['login_user_id' => 507, 'user_session_id' => 800070]));
        Audit_Session::_declare($declared);

        $transaction_id = Revision::unit_of_work('audit session stamp', function () {
            return Revision::transaction_id();
        });

        $stamped = DB::selectOne('SELECT audit_session_id FROM _transactions WHERE id = ?', [$transaction_id])->audit_session_id;

        static::__assert_equals($declared, (int) $stamped);

        Audit_Session::_declare(null);

        $unattributed = Revision::unit_of_work('no audit session', function () {
            return Revision::transaction_id();
        });

        static::__assert_null(DB::selectOne('SELECT audit_session_id FROM _transactions WHERE id = ?', [$unattributed])->audit_session_id);
    }
}
