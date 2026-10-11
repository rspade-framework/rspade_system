<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Portal\Portal_Session;
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
     * The id of the audit session for whoever is acting right now, minting the row if
     * this context has not been seen before.
     *
     * A WRITER on _audit_sessions (and _user_agents), and on nothing else: it reads the
     * session accessors, which create no session.
     *
     * @return int
     */
    public static function current_id(): int
    {
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
        // the context is kept.
        DB::statement(
            'INSERT INTO _audit_sessions
                (tuple_hash, site_id, login_user_id, portal_user_id, portal_site_id,
                 impersonator_login_user_id, impersonator_user_id, ip_address, session_row_id,
                 user_agent_id, device_id, session_type_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                last_seen_at = CURRENT_TIMESTAMP(3),
                device_id = COALESCE(device_id, VALUES(device_id))',
            array_merge([$hash], $tuple, [$context['device_id'], $context['session_type_id']])
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
     * One audit session as a reader needs it: the recorded context, with the user agent
     * as a string. Null when there is no such row.
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
            'ip_address' => $row->ip_address,
            'user_agent' => self::user_agent_string($int($row->user_agent_id)),
            'session_row_id' => $int($row->session_row_id),
            'device_id' => $int($row->device_id),
            'session_type_id' => (int) $row->session_type_id,
            'first_seen_at' => \App\RSpade\Core\Time\Rsx_Time::to_iso($row->first_seen_at),
            'last_seen_at' => \App\RSpade\Core\Time\Rsx_Time::to_iso($row->last_seen_at),
        ];
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
