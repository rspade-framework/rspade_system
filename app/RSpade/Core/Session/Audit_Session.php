<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Session;

/**
 * Audit_Session - the durable record of an ACTING CONTEXT.
 *
 * Anything that records what somebody did - a revision, an application's activity log -
 * needs to say who did it and from where, and needs that to stay true for as long as the
 * record exists. A _sessions row cannot be what it points at: it is operational state,
 * rewritten as the browser signs in, switches site and impersonates, replaced at every
 * sign-in, deleted the moment a CLI process ends and collected when idle.
 *
 * An audit session is one row in _audit_sessions per distinct context:
 *
 *     site, staff identity, portal identity and its site, both impersonators,
 *     client address, session row, user agent
 *
 * When any of those changes, the next act belongs to a different row. A row is minted
 * only when something asks for it, so a visitor who logs nothing leaves nothing. It is
 * immutable apart from last_seen_at, and NEVER garbage-collected - it is the audit
 * trail's own memory.
 *
 * session_row_id and device_id are correlation numbers, not references: "every act of
 * this sign-in" and "every act from this browser" are equality tests among audit rows,
 * which keep working after the session and the device are gone. The session TOKEN is
 * never stored - it identifies nothing the row id does not, and it is a credential.
 *
 * Works with no session at all: a command, a task and a Bearer-key API call each have a
 * context (session_row_id null), which is exactly the case a session id could never
 * record.
 *
 * Reached through Session::get_audit_session_id() (record) and
 * Session::get_audit_session() (read back). Both tables are the framework's own; an
 * application stores the id and asks the framework for what it means.
 */
class Audit_Session
{
    /**
     * last_seen_at is advanced at most this often per context: it says "still in use",
     * to the minute. A write rate, not a timeout - nothing expires on it.
     */
    private const TOUCH_INTERVAL_SECONDS = 60;

    /**
     * Contexts already resolved by this process: tuple hash => [id, unix time of the
     * last write]. A request that records forty things resolves its context once.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private static array $resolved = [];

    /**
     * User agents already resolved by this process: hash => id.
     *
     * @var array<string, int>
     */
    private static array $user_agents = [];

    /**
     * User-agent strings already read by this process: id => string (null: no such row).
     *
     * @var array<int, string|null>
     */
    private static array $user_agent_strings = [];

    /**
     * An acting context DECLARED for the work this process is doing now - a task running as
     * the person who dispatched it (Task_Dispatcher_Context). While set, current_id() answers
     * it instead of deriving one, so what the run writes points at the dispatching request's
     * own context: its identity, its impersonators, its address and user agent.
     */
    private static ?int $declared_id = null;

    /**
     * The id of the audit session for whoever is acting right now, minting the row if
     * this context has not been seen before.
     *
     * A WRITER on _audit_sessions (and _user_agents, _ip_addresses), and on nothing
     * else: it reads the session accessors, which create no session.
     *
     * @return int
     */
    public static function current_id(): int
    {
        if (self::$declared_id !== null) {
            return self::$declared_id;
        }

        $context = self::__current_context();

        // The tuple: everything that makes this a DIFFERENT context when it changes.
        // device_id and session_type_id are recorded but are not part of it - a device
        // is only assigned at a session's first sign-in, and going from none to one is
        // not a new context; the type cannot change for a given session row.
        $tuple = [
            $context['site_id'],
            $context['login_user_id'],
            $context['portal_user_id'],
            $context['portal_site_id'],
            $context['impersonator_login_user_id'],
            $context['impersonator_user_id'],
            $context['ip_address'],
            $context['session_row_id'],
            $context['user_agent_id'],
        ];

        $hash = hash('sha256', json_encode($tuple));
        $now = time();

        if (isset(self::$resolved[$hash]) && $now - self::$resolved[$hash][1] < self::TOUCH_INTERVAL_SECONDS) {
            return self::$resolved[$hash][0];
        }

        // One statement finds or creates: a concurrent request with the same context
        // lands on the unique key and both read the same id. The first device seen for
        // the context is kept. The tuple hashes the address itself; the row stores the
        // id of its _ip_addresses row.
        DB::statement(
            'INSERT INTO _audit_sessions
                (tuple_hash, site_id, login_user_id, portal_user_id, portal_site_id,
                 impersonator_login_user_id, impersonator_user_id, ip_address_id, session_row_id,
                 user_agent_id, device_id, session_type_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                last_seen_at = CURRENT_TIMESTAMP(3),
                device_id = COALESCE(device_id, VALUES(device_id))',
            [
                $hash,
                $context['site_id'],
                $context['login_user_id'],
                $context['portal_user_id'],
                $context['portal_site_id'],
                $context['impersonator_login_user_id'],
                $context['impersonator_user_id'],
                Ip_Address::id($context['ip_address']),
                $context['session_row_id'],
                $context['user_agent_id'],
                $context['device_id'],
                $context['session_type_id'],
            ]
        );

        $id = (int) DB::getPdo()->lastInsertId();

        self::$resolved[$hash] = [$id, $now];

        return $id;
    }

    /**
     * The id of a user-agent string in _user_agents, recording it on first sight - or
     * null for no agent at all.
     *
     * _user_agents is PRIVATE to the framework: it exists to feed _sessions,
     * _session_devices, _login_history and _audit_sessions, each of which stores this id
     * where it used to store a truncated copy of the string. The string is kept whole and
     * matched by the hash of the whole, so one browser is one row everywhere. Readers get
     * the string back through the framework's own answers (the session listings,
     * Login_History, Session::get_audit_session()).
     *
     * Resolving advances the row's last_seen_at, which is what keeps the weekly sweep
     * (Session_Cleanup_Service::cleanup_user_agents) from deleting a row between this
     * call and the insert that references it.
     *
     * FRAMEWORK INTERNAL.
     *
     * @param string|null $user_agent
     * @return int|null
     */
    public static function user_agent_id(?string $user_agent): ?int
    {
        if ($user_agent === null || $user_agent === '') {
            return null;
        }

        $hash = hash('sha256', $user_agent);

        if (isset(self::$user_agents[$hash])) {
            return self::$user_agents[$hash];
        }

        DB::statement(
            'INSERT INTO _user_agents (ua_hash, user_agent) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_seen_at = CURRENT_TIMESTAMP(3)',
            [$hash, $user_agent]
        );

        $id = (int) DB::getPdo()->lastInsertId();

        self::$user_agents[$hash] = $id;
        self::$user_agent_strings[$id] = $user_agent;

        return $id;
    }

    /**
     * The user-agent string behind a _user_agents id, or null for no id.
     *
     * FRAMEWORK INTERNAL.
     *
     * @param int|null $user_agent_id
     * @return string|null
     */
    public static function user_agent_string(?int $user_agent_id): ?string
    {
        if (empty($user_agent_id)) {
            return null;
        }

        if (!array_key_exists($user_agent_id, self::$user_agent_strings)) {
            $row = DB::selectOne('SELECT user_agent FROM _user_agents WHERE id = ?', [$user_agent_id]);
            self::$user_agent_strings[$user_agent_id] = $row === null ? null : $row->user_agent;
        }

        return self::$user_agent_strings[$user_agent_id];
    }

    /**
     * One audit session as a reader needs it: the recorded context, with the address and
     * the user agent as strings. Null when there is no such row.
     *
     * @param int $audit_session_id
     * @return array|null
     */
    public static function describe(int $audit_session_id): ?array
    {
        $row = DB::selectOne('SELECT * FROM _audit_sessions WHERE id = ?', [$audit_session_id]);

        if ($row === null) {
            return null;
        }

        $int = fn ($value) => $value === null ? null : (int) $value;

        return [
            'id' => (int) $row->id,
            'site_id' => $int($row->site_id),
            'login_user_id' => $int($row->login_user_id),
            'portal_user_id' => $int($row->portal_user_id),
            'portal_site_id' => $int($row->portal_site_id),
            'impersonator_login_user_id' => $int($row->impersonator_login_user_id),
            'impersonator_user_id' => $int($row->impersonator_user_id),
            'ip_address' => Ip_Address::address($int($row->ip_address_id)),
            'user_agent' => self::user_agent_string($int($row->user_agent_id)),
            'session_row_id' => $int($row->session_row_id),
            'device_id' => $int($row->device_id),
            'session_type_id' => (int) $row->session_type_id,
            'first_seen_at' => \App\RSpade\Core\Time\Rsx_Time::to_iso($row->first_seen_at),
            'last_seen_at' => \App\RSpade\Core\Time\Rsx_Time::to_iso($row->last_seen_at),
        ];
    }

    /**
     * What a joined audit session answers, by published name: the expression behind each,
     * over the three aliases join() adds. The names are the keys Session::get_audit_session()
     * returns; the columns behind them are the framework's and may move.
     */
    private const JOINED_FIELDS = [
        'site_id' => 'audit_session.site_id',
        'login_user_id' => 'audit_session.login_user_id',
        'portal_user_id' => 'audit_session.portal_user_id',
        'portal_site_id' => 'audit_session.portal_site_id',
        'impersonator_login_user_id' => 'audit_session.impersonator_login_user_id',
        'impersonator_user_id' => 'audit_session.impersonator_user_id',
        'ip_address' => 'audit_ip.ip_address',
        'user_agent' => 'audit_user_agent.user_agent',
        'session_row_id' => 'audit_session.session_row_id',
        'device_id' => 'audit_session.device_id',
        'session_type_id' => 'audit_session.session_type_id',
        'first_seen_at' => 'audit_session.first_seen_at',
        'last_seen_at' => 'audit_session.last_seen_at',
    ];

    /**
     * Join the audit session onto a query of your own, so a list of logged acts can show,
     * search and sort by who performed each one and from where.
     *
     *     $query = Action_Log_Model::query();
     *     Audit_Session::join($query, 'action_logs.audit_session_id');
     *
     *     $query->where(Audit_Session::column('ip_address'), 'like', $like)
     *           ->orderBy(Audit_Session::column('first_seen_at'), 'desc');
     *
     *     foreach ($query->limit(25)->get() as $log) {
     *         $log->audit_ip_address;   $log->audit_user_agent;   $log->audit_login_user_id;
     *     }
     *
     * $column is YOUR column holding a Session::get_audit_session_id() value. The join is
     * a LEFT join, so a row with no audit session is still listed (its audit_* values are
     * null). Every published field is added to the select as `audit_<name>` - the names
     * are get_audit_session()'s keys, ip_address and user_agent as strings. A query that
     * selected nothing explicitly keeps its own table's columns.
     *
     * THIS IS THE WAY TO READ AUDIT SESSIONS IN BULK. The tables behind it are the
     * framework's and are never named in application code: a condition or an ordering on
     * an audit field is written with column(), which answers the expression to use.
     *
     * Call it once per query. Works on an Eloquent builder and on a query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $column The qualified column holding the audit session id ('table.column')
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder The same query
     */
    public static function join($query, string $column)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                "Audit_Session::join(): '{$column}' is not a qualified column. Name the table and the column holding the audit session id, e.g. 'action_logs.audit_session_id'."
            );
        }

        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;

        // Adding a select to a query that had none would REPLACE its implicit `*`.
        if (empty($base->columns)) {
            $query->select($base->from . '.*');
        }

        $query->leftJoin('_audit_sessions as audit_session', 'audit_session.id', '=', $column)
            ->leftJoin('_user_agents as audit_user_agent', 'audit_user_agent.id', '=', 'audit_session.user_agent_id')
            ->leftJoin('_ip_addresses as audit_ip', 'audit_ip.id', '=', 'audit_session.ip_address_id');

        foreach (self::JOINED_FIELDS as $name => $expression) {
            $query->addSelect($expression . ' as audit_' . $name);
        }

        return $query;
    }

    /**
     * The expression for one audit field in a query join() was applied to - for a where(),
     * an orderBy() or a groupBy(). $name is one of get_audit_session()'s keys.
     *
     * @param string $name
     * @return string
     */
    public static function column(string $name): string
    {
        if (!isset(self::JOINED_FIELDS[$name])) {
            throw new \InvalidArgumentException(
                "Audit_Session::column(): no audit field '{$name}'. Fields: " . implode(', ', array_keys(self::JOINED_FIELDS)) . '.'
            );
        }

        return self::JOINED_FIELDS[$name];
    }

    /**
     * Declare (an id) or end (null) the acting context current_id() answers.
     *
     * FRAMEWORK INTERNAL - Task_Dispatcher_Context is the one caller.
     *
     * @param int|null $audit_session_id
     * @return void
     */
    public static function _declare(?int $audit_session_id): void
    {
        self::$declared_id = $audit_session_id;
    }

    /**
     * Forget every id this process has resolved.
     *
     * A row minted inside a database transaction that then rolls back no longer exists,
     * and the memo would go on answering its id. Called on every rollback
     * (Transaction_Rollback_Cache_Reset) and between tests.
     *
     * FRAMEWORK INTERNAL.
     *
     * @return void
     */
    public static function _reset(): void
    {
        self::$resolved = [];
        self::$user_agents = [];
        self::$user_agent_strings = [];
    }

    /**
     * Who is acting right now, read from the session accessors. Creates no session.
     *
     * @return array<string, int|string|null>
     */
    private static function __current_context(): array
    {
        $is_cli = php_sapi_name() === 'cli';
        $session = !$is_cli && !Session::is_api_request() && Session::has_session() ? Session::get_session() : null;

        return [
            'site_id' => Session::get_site_id() ?: null,
            'login_user_id' => Session::get_login_user_id() ?: null,
            'portal_user_id' => Portal_Session::get_portal_user_id() ?: null,
            'portal_site_id' => $session ? ($session->portal_site_id ?: null) : null,
            'impersonator_login_user_id' => Session::get_impersonator_login_user_id() ?: null,
            'impersonator_user_id' => Portal_Session::get_impersonator_user_id() ?: null,
            'ip_address' => Session::get_client_ip(),
            'session_row_id' => $session ? (int) $session->id : null,
            'user_agent_id' => $is_cli ? null : self::user_agent_id($_SERVER['HTTP_USER_AGENT'] ?? null),
            'device_id' => $session ? ($session->device_id ?: null) : null,
            'session_type_id' => Session::_current_session_type(),
        ];
    }
}
