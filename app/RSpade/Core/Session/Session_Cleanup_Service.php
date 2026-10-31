<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Session_Cleanup_Service
 *
 * The retention sweeps for the session concern's tables. Separate tasks because they answer
 * separate questions (a guest gone quiet, a browser gone unused, a record past its age, a
 * code past its window) and any may be disabled without touching the others:
 *
 *   cleanup_sessions            hourly: DELETES guest browser sessions - nobody ever signed in
 *                               on them (_sessions.first_signed_in_at is null) - unused for
 *                               rsx.sessions.guest_expiry_minutes, signs out the sign-ins past
 *                               their rsx.sessions.staff / portal limits, and signs out what is
 *                               still signed in through an EXPIRED API key
 *   deactivate_unused_sessions  daily maintenance: makes every browser unused for
 *                               rsx.sessions.unused_session_days INACTIVE, signing out what
 *                               is still signed in on it
 *   cleanup_login_history       hourly: prunes _login_history / _portal_login_history /
 *                               _site_sign_ins
 *   cleanup_session_links       hourly: removes expired _session_links handshake codes
 *   cleanup_trusted_devices     daily maintenance: expired two-factor browser trust
 *   end_stale_impersonations    daily maintenance: impersonations past their age limit
 *   cleanup_user_agents         weekly maintenance: _user_agents strings nothing names
 *
 * A BROWSER THAT HAS EVER HAD A SIGN-IN IS NEVER DELETED. Its user sessions are the history of
 * where an account signed in, so it is only ever made inactive (kept, never resumed). Only a
 * guest's row - minted for any visitor something asked a session of, crawlers included - is
 * deleted, with its session values. The machine rows are never swept: an API key's one row
 * lives as long as the key (deleted with it), a command or a task has no row at all (a virtual
 * session), and the rsx:debug harness reuses one row per account.
 *
 * Deletes and updates are CHUNKED (bounded ... LIMIT batches) so the first run against a
 * server with months of backlog never issues one giant lock-holding statement. Every task is
 * #[Exclusive]: a slow backlog-recovery run overlapping its own next tick would re-scan the
 * same range for no benefit.
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
    private const USER_AGENT_REFERENCES = ['_sessions', '_login_history', '_portal_login_history', '_site_sign_ins', '_audit_sessions'];

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
     * Delete the guest browser sessions that have gone unused past
     * rsx.sessions.guest_expiry_minutes, sign out the sign-ins that have outlived their
     * limits, and sign out what is still signed in through an expired API key.
     *
     * A GUEST is a browser (TYPE_WEB) nobody has ever signed in on, in either experience:
     * _sessions.first_signed_in_at is null (Session stamps it at the browser's first sign-in
     * and never clears it). A machine row is never a guest, whatever it holds. Its session
     * values and session links go with it (ON DELETE CASCADE). Read through
     * idx_sessions_guest_expiry (first_signed_in_at, last_active); the type is checked on
     * the rows that range finds.
     *
     * A SIGN-IN PAST ITS LIMITS is ended when its browser next makes a request
     * (Session::enforce_timeouts()); this ends the ones whose browser never comes back, so
     * "where you're signed in" stops listing them as signed in. Per realm (staff, portal) and
     * per clock - rsx.sessions.<realm>.max_length_minutes from the sign-in, max_idle_minutes
     * from the last page view, 0 = no limit - with rsx.sessions.ajax_grace_minutes added: a
     * page left open past its limit may still save through Ajax for that long, so the sweep
     * never ends a sign-in sooner than a request would. Recorded SIGN_OUT_TIMED_OUT_LENGTH /
     * _IDLE, through the ordinary sign-out (pointer cleared, the realm's values cleared, a
     * refresh push; the browser's token is left alone). Session::_end_timed_out_user_sessions().
     *
     * An API key past its expires_at is refused when it is presented, and its user session
     * is signed out then (SIGN_OUT_API_KEY_EXPIRED); this signs out the ones nobody presents
     * again, so "where you're signed in" stops listing them within the hour.
     *
     * _audit_sessions is NOT session state and is never collected, here or anywhere: it is
     * what a logged act points at once its session is gone. (_user_agents rows it names
     * live as long as it does; the rest are swept by cleanup_user_agents().)
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: rows per DELETE, testing)
     * @return null
     */
    #[Task('Delete idle guest sessions, sign out expired sign-ins and expired API keys (runs hourly)')]
    #[Exclusive]
    #[Schedule('hourly')]
    public static function cleanup_sessions(Task_Instance $task, array $params = [])
    {
        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $cutoff = self::_cutoff('guest_expiry_minutes');

        $task->status('Deleting guest sessions unused since ' . $cutoff->toIso8601String());

        $deleted = self::_delete_chunked(
            fn () => DB::table('_sessions')
                ->whereNull('first_signed_in_at')
                ->where('last_active', '<', $cutoff)
                ->where('type_id', Session::TYPE_WEB),
            $task,
            $chunk_size
        );

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after deleting {$deleted} guest session(s).");
            $task->summary("Stopped after deleting {$deleted} guest sessions.");

            return null;
        }

        $task->stdout($deleted > 0
            ? "Deleted {$deleted} guest session(s) unused past their window."
            : 'No guest session is past its window; nothing deleted.');

        $task->status('Signing out sign-ins past their limits');

        $timed_out = 0;

        foreach (['staff', 'portal'] as $realm) {
            foreach (['length', 'idle'] as $clock) {
                while (!$task->is_stop_requested()) {
                    $read = Session::_end_timed_out_user_sessions($realm, $clock, $chunk_size);
                    $timed_out += $read;

                    if ($read < $chunk_size) {
                        break;
                    }

                    $task->heartbeat();
                }
            }
        }

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after signing out {$timed_out} sign-in(s) past their limits.");
            $task->summary("Deleted {$deleted} guest sessions; stopped after signing out {$timed_out} expired sign-ins.");

            return null;
        }

        $task->stdout($timed_out > 0
            ? "Signed out {$timed_out} sign-in(s) past their length or idle limit."
            : 'No sign-in is past its length or idle limit.');

        $task->status('Signing out sessions of expired API keys');

        $signed_out = Session::_end_expired_api_key_sessions();

        $task->stdout($signed_out > 0
            ? "Signed out {$signed_out} session(s) of expired API key(s)."
            : 'No session is signed in through an expired API key.');

        $task->state(['guest_deleted' => $deleted, 'timed_out_signed_out' => $timed_out, 'api_key_expired_signed_out' => $signed_out]);
        $task->summary("Deleted {$deleted} guest sessions; signed out {$timed_out} expired sign-ins and {$signed_out} expired API key sessions.");

        return null;
    }

    /**
     * Make every browser session unused for rsx.sessions.unused_session_days INACTIVE, signing
     * out whatever is still signed in on it.
     *
     * Session::init() retires such a browser the moment its cookie is presented again; this
     * retires the ones never presented again, so the active set is the browsers actually in
     * use. Each one's signed-in user sessions are signed out (Session::SIGN_OUT_BROWSER_UNUSED)
     * first, so an account is no longer listed as signed in on a browser nobody has used for
     * a month and that sign-in stops counting toward rsx.sessions.max_web_sessions_per_user
     * (Session::_deactivate_browsers()). Nothing is deleted - a browser's user sessions are the
     * record of where an account signed in - and an inactive row is never resumed. An API key's row is not a browser and
     * is left alone: it lives as long as its key. Read through
     * idx_sessions_active_last_active (active, last_active). unused_session_days at 0 turns
     * it off, as it does the check in init().
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (chunk_size: browsers per batch, testing)
     * @return null
     */
    #[Task('Make browser sessions unused for rsx.sessions.unused_session_days inactive (runs daily)')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    public static function deactivate_unused_sessions(Task_Instance $task, array $params = [])
    {
        $days = (int) config('rsx.sessions.unused_session_days');

        if ($days <= 0) {
            $task->stdout('rsx.sessions.unused_session_days is 0: browser sessions are never retired for disuse.');
            $task->state(['deactivated' => 0]);
            $task->summary('Unused-session retirement is off; nothing changed.');

            return null;
        }

        $chunk_size = (int) ($params['chunk_size'] ?? self::DELETE_CHUNK_SIZE);
        $cutoff = now()->subDays($days);

        $task->status("Retiring browser sessions unused for {$days} day(s)");

        // Batches of ids, each signed out and made inactive before the next is read: a
        // deactivated row stops matching, so the loop ends when a batch comes back short.
        $deactivated = 0;

        while (!$task->is_stop_requested()) {
            $ids = DB::table('_sessions')
                ->where('active', true)
                ->where('last_active', '<', $cutoff)
                ->where('type_id', '<>', Session::TYPE_API)
                ->limit($chunk_size)
                ->pluck('id')
                ->all();

            $deactivated += Session::_deactivate_browsers($ids);

            if (count($ids) < $chunk_size) {
                break;
            }

            $task->heartbeat();
        }

        if ($task->is_stop_requested()) {
            $task->stdout("Stop requested - stopped after retiring {$deactivated} browser session(s).");
            $task->summary("Stopped after retiring {$deactivated} unused browser sessions.");

            return null;
        }

        $task->stdout($deactivated > 0
            ? "Made {$deactivated} browser session(s) unused for {$days} day(s) inactive."
            : "No active browser session has gone unused for {$days} day(s); nothing changed.");

        $task->state(['deactivated' => $deactivated]);
        $task->summary("Retired {$deactivated} browser sessions unused for {$days} days.");

        return null;
    }

    /**
     * Delete the user-agent strings nothing refers to any more.
     *
     * _user_agents holds each string once, and five tables point into it: _sessions,
     * _login_history, _portal_login_history, _site_sign_ins and _audit_sessions. A browser session can be
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
    #[Task('Delete user-agent strings no session, login or audit record names (weekly)')]
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
     * Prune aged rows from both realms' login-history tables, and from the staff site
     * sign-in history (_site_sign_ins, Site_Sign_In_History) on the same window.
     *
     * `_login_history` (staff) and `_portal_login_history` (client portal) hold successes,
     * post-password refusals and passkey enrollment outcomes - failed sign-in attempts are
     * ephemeral counters and never become rows (see Login_History_Abstract) - so they grow with
     * real use rather than with attack volume. They still grow without bound over years, which
     * is what this sweep answers. One retention window covers both.
     *
     * Chunked like the guest-session sweep.
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

        $deleted = 0;

        $histories = [
            fn () => DB::table('_login_history')->where('created_at', '<', $cutoff),
            fn () => DB::table('_portal_login_history')->where('created_at', '<', $cutoff),
            fn () => DB::table('_site_sign_ins')->where('created_at', '<', $cutoff),
        ];

        foreach ($histories as $query) {
            if ($task->is_stop_requested()) {
                break;
            }

            $deleted += self::_delete_chunked(
                $query,
                $task,
                $chunk_size
            );
        }

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

    /**
     * End the impersonations that have run longer than rsx.sessions.impersonation_max_days.
     *
     * An impersonation has no sign-in of its own to expire: it rides the impersonator's
     * browser session, so one left open is the target's identity on somebody else's
     * browser for as long as that browser stays signed in. Past the limit
     * (Session::_end_stale_impersonations(), measured from the impersonation's start):
     *
     *   - a STAFF impersonation's user session is signed out together with the
     *     impersonator's own sign-in behind it: the browser signs in again as itself;
     *   - a portal VIEW AS CLIENT's user session is signed out alone: the staff member's own
     *     sign-in on that browser carries on.
     *
     * The audit record of the impersonation (_audit_sessions) is permanent and untouched.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (unused)
     * @return null
     */
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

        $task->status("Ending impersonations older than {$days} day(s)");

        $ended = Session::_end_stale_impersonations(now()->subDays($days)->toIso8601String());
        $staff = $ended['staff'];
        $portal = $ended['portal'];

        $task->stdout(($staff + $portal) > 0
            ? "Ended {$staff} staff impersonation(s) and {$portal} portal view-as-client session(s) older than {$days} day(s)."
            : "No impersonation is older than {$days} day(s); nothing ended.");

        $task->state(['staff_ended' => $staff, 'portal_ended' => $portal]);
        $task->summary("Ended {$staff} staff and {$portal} portal impersonations older than {$days} days.");

        return null;
    }

    /**
     * Resolve one rsx.sessions.* inactivity window to an absolute cutoff.
     *
     * @param string $config_key Key under rsx.sessions (e.g. 'guest_expiry_minutes')
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
