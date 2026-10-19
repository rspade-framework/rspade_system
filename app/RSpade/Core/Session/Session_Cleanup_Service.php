<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Session_Cleanup_Service
 *
 * Hourly retention sweeps for the session concern's tables: cleanup_sessions() expires
 * idle `_sessions` rows by type, cleanup_login_history() prunes `_login_history` past its
 * retention window, cleanup_session_links() removes expired `_session_links` handshake codes.
 * Separate tasks because they answer separate questions (inactivity, age, expiry) and any
 * may be disabled without touching the others.
 *
 * For sessions this is the ONE retention mechanism.
 *
 * A session expires by INACTIVITY. A BROWSER session (TYPE_WEB) is judged on whether
 * it carries an IDENTITY - either one, since one row serves both experiences - and
 * the machine types keep their own short backstops whatever they carry:
 *
 *   TYPE_WEB with login_user_id OR portal_user_id set
 *              rsx.sessions.web_timeout_minutes        (default 3 months)
 *   TYPE_WEB with neither
 *              rsx.sessions.anonymous_timeout_minutes  (default 30 days)
 *   TYPE_API   rsx.sessions.api_timeout_minutes        (default 7 days)
 *   TYPE_PLAYWRIGHT
 *              rsx.sessions.playwright_timeout_minutes (default 1 day)
 *   TYPE_CLI   rsx.sessions.cli_timeout_minutes        (default 1 day)
 *
 * The identity test spans BOTH columns because the row does: a browser signed into
 * the client portal and never into the staff app is a real, in-use session, and a
 * rule that only looked at login_user_id would collect it at the anonymous window.
 * There is no separate portal rule and no realm predicate - there is no realm.
 *
 * The type is read from the column - stamped by the writer at creation - never
 * sniffed out of the user-agent. A UA-matching sweep was what this replaced, and it
 * was both attacker-influenced and wrong about anything that did not announce itself.
 *
 * Deletes are CHUNKED (bounded DELETE ... LIMIT batches) so the first run against a
 * server with months of backlog never issues one giant lock-holding DELETE. Both tasks
 * are #[Exclusive]: a slow backlog-recovery run overlapping its own next tick would
 * re-scan the same range for no benefit.
 */
class Session_Cleanup_Service extends Rsx_Service_Abstract
{
    /**
     * Rows deleted per DELETE statement. Small enough to keep each statement's
     * lock hold time trivial, large enough that a 50k backlog clears in a few
     * statements. Overridable per run via $params['chunk_size'] (testing).
     */
    private const DELETE_CHUNK_SIZE = 10000;

    /**
     * Every table with a user_agent_id column pointing into _user_agents. A string is an
     * orphan only when none of them names it.
     */
    private const USER_AGENT_REFERENCES = ['_sessions', '_session_devices', '_login_history', '_audit_sessions'];

    /**
     * Delete rows matching a base query in bounded chunks until none remain, or until
     * a stop is requested (checked between chunks; the caller re-checks after the pass).
     * Heartbeats between chunks so a watcher can tell a long backlog-recovery run from a
     * stuck one.
     *
     * @param callable $query_builder Returns a fresh base query (re-built per chunk;
     *                                a DELETE consumes the builder)
     * @param Task_Instance $task Task instance for heartbeats
     * @param int $chunk_size Rows per DELETE statement
     * @return int Total rows deleted
     */
    private static function _delete_chunked(callable $query_builder, Task_Instance $task, int $chunk_size): int
    {
        $total = 0;

        while (true) {
            if ($task->is_stop_requested()) {
                break;
            }

            $deleted = $query_builder()->limit($chunk_size)->delete();
            $total += $deleted;

            if ($deleted < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        return $total;
    }

    /**
     * Expire sessions that have gone unused for longer than their type allows.
     *
     * _audit_sessions is NOT session state and is never collected, here or anywhere: it is
     * what a logged act points at once its session is gone. (_user_agents rows it names
     * live as long as it does; the rest are swept by cleanup_user_agents().)
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return null
     */
    #[Task('Expire idle sessions by type (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_sessions(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $deleted = [];

        // Browser sessions that carry NO identity - in either experience. (A retired row
        // keeps the identity it had and falls under the next rule.)
        $deleted['anonymous'] = self::_delete_chunked(
            fn () => DB::table('_sessions')
                ->where('type_id', Session::TYPE_WEB)
                ->whereNull('login_user_id')
                ->whereNull('portal_user_id')
                ->where('last_active', '<', self::_cutoff('anonymous_timeout_minutes')),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - stopped after deleting ' . array_sum($deleted) . ' idle session(s).');
            $task->summary('Stopped after deleting ' . array_sum($deleted) . ' idle sessions.');

            return null;
        }

        // Browser sessions that DO carry an identity, staff or portal. Whether such a
        // sign-in is still VALID is decided at request time (Session::enforce_timeouts());
        // this only collects rows nobody has come back to. A row is collected once it
        // has been untouched for longer than any sign-in on it could still be usable:
        // the longer of the two idle limits, plus the Ajax grace. An unlimited idle
        // window on either side means such rows are never collected for age.
        $identity_cutoff = self::_identity_cutoff();

        $deleted['web'] = $identity_cutoff === null ? 0 : self::_delete_chunked(
            fn () => DB::table('_sessions')
                ->where('type_id', Session::TYPE_WEB)
                ->where(function ($query) {
                    $query->whereNotNull('login_user_id')
                          ->orWhereNotNull('portal_user_id');
                })
                ->where('last_active', '<', $identity_cutoff),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - stopped after deleting ' . array_sum($deleted) . ' idle session(s).');
            $task->summary('Stopped after deleting ' . array_sum($deleted) . ' idle sessions.');

            return null;
        }

        // Device records no session row points at any more: every row of that browser
        // has been collected, so there is nothing left to tie together.
        $deleted['devices'] = self::_delete_chunked(
            fn () => DB::table('_session_devices')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('_sessions')
                          ->whereColumn('_sessions.device_id', '_session_devices.id');
                }),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout('Stop requested - stopped after deleting ' . array_sum($deleted) . ' idle session(s).');
            $task->summary('Stopped after deleting ' . array_sum($deleted) . ' idle sessions.');

            return null;
        }

        // Machine types: their own short backstops, whatever identity they carry.
        foreach ([
            'api' => Session::TYPE_API,
            'playwright' => Session::TYPE_PLAYWRIGHT,
            'cli' => Session::TYPE_CLI,
        ] as $name => $type_id) {
            $deleted[$name] = self::_delete_chunked(
                fn () => DB::table('_sessions')
                    ->where('type_id', $type_id)
                    ->where('last_active', '<', self::_cutoff($name . '_timeout_minutes')),
                $task,
                $chunk_size
            );

            if ($task->is_stop_requested()) {
                $task->stdout('Stop requested - stopped after deleting ' . array_sum($deleted) . ' idle session(s).');
            $task->summary('Stopped after deleting ' . array_sum($deleted) . ' idle sessions.');

                return null;
            }
        }

        $total = array_sum($deleted);

        // One line per kind that had something to expire, then the outcome - including when
        // every kind was within its window, so an idle sweep is visibly an idle sweep.
        foreach ($deleted as $name => $count) {
            if ($count > 0) {
                $task->stdout("Deleted {$count} idle {$name} session(s).");
            }
        }
        $task->stdout($total > 0
            ? "Deleted {$total} idle session(s) in all."
            : 'No sessions are past their idle window; nothing deleted.');

        $deleted['total_deleted'] = $total;

        $task->state($deleted);
        $task->summary("Deleted {$total} idle sessions.");

        return null;
    }

    /**
     * Delete the user-agent strings nothing refers to any more.
     *
     * _user_agents holds each string once, and four tables point into it: _sessions,
     * _session_devices, _login_history and _audit_sessions. A browser session can be
     * minted by any visitor with any string, so once the rows that named a string have
     * been collected the string goes too. One an audit session names is never an orphan,
     * because audit sessions are never collected.
     *
     * A row resolved within the last day is left alone whatever points at it: resolving
     * (Audit_Session::user_agent_id()) advances last_seen_at immediately before the
     * insert that will reference the row, and that insert must find it.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return null
     */
    #[Task('Delete user-agent strings no session, device, login or audit record names (weekly)')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance weekly on sunday')]
    public static function cleanup_user_agents(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $settled_before = now()->subDay();

        $deleted = self::_delete_chunked(
            function () use ($settled_before) {
                $query = DB::table('_user_agents')->where('last_seen_at', '<', $settled_before);

                foreach (self::USER_AGENT_REFERENCES as $table) {
                    $query->whereNotExists(function ($referenced) use ($table) {
                        $referenced->select(DB::raw(1))
                            ->from($table)
                            ->whereColumn($table . '.user_agent_id', '_user_agents.id');
                    });
                }

                return $query;
            },
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after deleting {$deleted} unreferenced user agent(s).");
            $task->summary("Stopped after deleting {$deleted} unreferenced user agents.");

            return null;
        }

        $task->stdout($deleted > 0
            ? "Deleted {$deleted} unreferenced user agent(s)."
            : 'Every user agent is still referenced; nothing deleted.');

        $task->state(['total_deleted' => $deleted]);
        $task->summary("Deleted {$deleted} unreferenced user agents.");

        return null;
    }

    /**
     * Prune aged rows from the login-history table.
     *
     * `_login_history` holds successes and passkey enrollment outcomes - failed sign-in attempts
     * are ephemeral counters and never become rows (see Login_History) - so it grows with real
     * use rather than with attack volume. It still grows without bound over years, which is what this sweep answers.
     *
     * Chunked like the session sweep.
     * rsx.sessions.login_history_retention_days at 0 or null disables the prune entirely.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return null
     */
    #[Task('Prune login history past its retention window (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_login_history(Task_Instance $task, array $params = [])
    {
        $retention_days = (int) config('rsx.sessions.login_history_retention_days');

        if ($retention_days <= 0) {
            $task->stdout('Login history is kept forever (rsx.sessions.login_history_retention_days is 0); nothing deleted.');
            $task->state(['total_deleted' => 0]);
            $task->summary('Login history retention is disabled; nothing was deleted.');

            return null;
        }

        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $cutoff = now()->subDays($retention_days);

        $deleted = self::_delete_chunked(
            fn () => DB::table('_login_history')->where('created_at', '<', $cutoff),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after deleting {$deleted} login history row(s).");
            $task->summary("Stopped after deleting {$deleted} login history rows.");

            return null;
        }

        $task->stdout($deleted > 0
            ? "Deleted {$deleted} login history row(s) older than {$retention_days} days."
            : "No login history rows are older than {$retention_days} days; nothing deleted.");

        $task->state(['total_deleted' => $deleted]);
        $task->summary("Deleted {$deleted} login history rows older than {$retention_days} days.");

        return null;
    }

    /**
     * Delete the linked-session handshake codes whose window has passed.
     *
     * A code is unredeemable the instant it expires (Session_Link filters on expires_at),
     * and a redeemed one is deleted by its redemption, so this only reclaims the space of
     * links nobody finished - an abandoned tab, a refused leg. A link whose session ends
     * first goes with it (ON DELETE CASCADE).
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return null
     */
    #[Task('Delete expired linked-session handshake codes (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_session_links(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $now = now();

        $deleted = self::_delete_chunked(
            fn () => DB::table('_session_links')->where('expires_at', '<', $now),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after deleting {$deleted} expired session link(s).");
            $task->summary("Stopped after deleting {$deleted} expired session links.");

            return null;
        }

        $task->stdout($deleted > 0
            ? "Deleted {$deleted} expired session link(s)."
            : 'No session links have expired; nothing deleted.');

        $task->state(['total_deleted' => $deleted]);
        $task->summary("Deleted {$deleted} expired session links.");

        return null;
    }

    /**
     * End the impersonations that have run longer than rsx.sessions.impersonation_max_days.
     *
     * An impersonation has no sign-in of its own to expire: it rides the impersonator's
     * browser session, so one left open is the target's identity on somebody else's
     * browser for as long as that browser stays signed in. Past the limit:
     *
     *   - a STAFF impersonation's row is RETIRED - made inactive, still recording whose
     *     identity it carried and who was acting as them. The impersonator's browser is
     *     signed out and signs in again as itself. The row leaves both session listings
     *     with that, since they answer active rows only, and is collected with the other
     *     inactive rows.
     *   - a portal VIEW AS CLIENT loses its portal properties only: the row is the staff
     *     member's own session, which the portal view ending is no reason to end.
     *
     * The audit record of the impersonation (_audit_sessions) is permanent and untouched.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (unused)
     * @return null
     */
    /**
     * Delete two-factor browser trust that has run out (_two_factor_trusted_devices), in
     * both realms. A row past trusted_until is already ignored by device_is_trusted(); this
     * keeps the table to the browsers still trusted. Trusting again inserts a fresh row.
     *
     * @param Task_Instance $task
     * @param array $params Unused
     * @return null
     */
    #[Task('Delete expired two-factor browser trust (runs daily)')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function cleanup_trusted_devices(Task_Instance $task, array $params = [])
    {
        $task->status('Deleting expired two-factor browser trust');

        $deleted = \App\RSpade\Core\TwoFactor\Rsx_Two_Factor::_delete_expired_trust();

        $task->stdout("Deleted {$deleted} expired trusted-browser row(s).");
        $task->summary("{$deleted} expired trusted browser(s) removed.");

        return null;
    }

    #[Task('End impersonations older than rsx.sessions.impersonation_max_days (runs daily)')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function end_stale_impersonations(Task_Instance $task, array $params = [])
    {
        $days = config('rsx.sessions.impersonation_max_days');
        if (!is_int($days) || $days < 0) {
            throw new \RuntimeException('rsx.sessions.impersonation_max_days must be an integer >= 0, got ' . var_export($days, true));
        }

        if ($days === 0) {
            $task->stdout('rsx.sessions.impersonation_max_days is 0: impersonations are never ended for age.');
            $task->summary('Impersonation age limit is off; nothing ended.');

            return null;
        }

        $cutoff = now()->subDays($days);
        $task->status("Ending impersonations older than {$days} day(s)");

        $staff = Session::whereNotNull('impersonator_login_user_id')
            ->where('active', true)
            ->where('impersonation_started_at', '<', $cutoff)
            ->raw_bulk()
            ->update(['active' => false]);

        // One statement over the matching rows; the columns are the portal property set
        // Session::_clear_portal_properties_on() clears.
        $portal = Session::whereNotNull('impersonator_user_id')
            ->whereNull('impersonator_login_user_id')
            ->where('active', true)
            ->where('impersonation_started_at', '<', $cutoff)
            ->raw_bulk()
            ->update([
                'portal_user_id' => null,
                'portal_site_id' => null,
                'portal_login_at' => null,
                'portal_last_page_view_at' => null,
                'impersonator_user_id' => null,
                'impersonation_started_at' => null,
            ]);

        $task->stdout(($staff + $portal) > 0
            ? "Ended {$staff} staff impersonation(s) and {$portal} portal view-as-client session(s) older than {$days} day(s)."
            : "No impersonation is older than {$days} day(s); nothing ended.");

        $task->state(['staff_ended' => $staff, 'portal_ended' => $portal]);
        $task->summary("Ended {$staff} staff and {$portal} portal impersonations older than {$days} days.");

        return null;
    }

    /**
     * The last_active cutoff before which a signed-in browser session is collected, or
     * null when either experience's idle window is unlimited.
     *
     * @return \Carbon\Carbon|null
     */
    private static function _identity_cutoff()
    {
        $staff = (int) config('rsx.sessions.staff.max_idle_minutes');
        $portal = (int) config('rsx.sessions.portal.max_idle_minutes');

        if ($staff <= 0 || $portal <= 0) {
            return null;
        }

        return now()->subMinutes(max($staff, $portal) + (int) config('rsx.sessions.ajax_grace_minutes'));
    }

    /**
     * Resolve one rsx.sessions.* inactivity window to an absolute cutoff.
     *
     * @param string $config_key Key under rsx.sessions (e.g. 'web_timeout_minutes')
     * @return \Carbon\Carbon
     */
    private static function _cutoff(string $config_key)
    {
        $minutes = (int) config('rsx.sessions.' . $config_key);

        if ($minutes <= 0) {
            shouldnt_happen("rsx.sessions.{$config_key} must be a positive number of minutes");
        }

        return now()->subMinutes($minutes);
    }
}
