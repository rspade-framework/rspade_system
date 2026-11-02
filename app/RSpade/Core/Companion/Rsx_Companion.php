<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Companion\Companion_Control_Exception;
use App\RSpade\Core\Companion\Companion_Daemon_Client;
use App\RSpade\Core\Companion\Companion_Flags;
use App\RSpade\Core\Companion\Companion_Gates;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Companion - the application's view of its companion applications, and the operator verbs.
 *
 *   names() / all() / status($name)        what each companion is doing (below)
 *   instances($name)                        its live instances and the recent ended ones
 *   output_after($name, $cursor, $streams)  output lines after an opaque cursor
 *   request_stats($name)                    endpoint calls, errors and latency, last hour
 *   pause / resume / stop / start / restart($name)
 *
 * EVERY CALL ASKS Companion_Gates. A read of a companion the viewer may not see answers exactly
 * like one that is not declared (null, or absent from a list); a verb the viewer may not perform
 * throws AjaxUnauthorizedException. A developer passes every gate.
 *
 * READS COME FROM REDIS (Companion_Store), never from the daemon, so they answer while
 * rsx-companiond is down - from what it last wrote. A host's summaries count only while its
 * daemon's own heartbeat is fresh (SUMMARY_STALE_SECONDS); past that the companion reads
 * `unknown`. Desired state is the flag file on THIS box.
 *
 * VERBS GO THROUGH THE DAEMON on this box (its control socket), which must be running: with none
 * listening a verb throws Companion_Control_Exception saying so, as it does when the daemon
 * refuses one (pause on a companion that is not running). A verb is asynchronous - it returns
 * once the daemon has it, and the companion's topic reports the outcome. Each verb is recorded in
 * the application log with who asked. stop holds the companion stopped with its desired state
 * untouched, until start or restart; the desired state itself (rsx:companion_apps:start / stop)
 * is the command line's alone.
 *
 * A STATUS is an array:
 *   name, description, single_instance, contract_key   the declaration
 *   desired             'running' | 'stopped' (this box's flag)
 *   maintenance         why maintenance holds every companion down, or null
 *   status              stopped, waiting, starting, running, paused, stopping, crashed (in
 *                       backoff), exited, killed - or unknown when no live daemon reports it
 *   unknown_reason      why it is unknown, else null
 *   held                an operator stopped it (stop); desired untouched
 *   waiting_reason      what a waiting companion waits for
 *   host, instance_id, pid, started_at (ISO), uptime_seconds
 *   restart_count, crash_streak, crash_looping, next_restart_at (ISO, in backoff)
 *   last_heartbeat (ISO), heartbeat_age_seconds, heartbeat_stale
 *   status_line, state (the decoded document), progress {current, total} | null, last_error
 *   last_exit           {instance_id, status, how_ended, code, signal, at (ISO)} | null
 *   is_live             an instance exists that is starting, running, paused or stopping
 *   hosts               [{host, status, held, instance_id, waiting_reason}] every live daemon's view
 *   can                 {pause, resume, stop, start, restart}: what the viewer may do (gates only)
 */
class Rsx_Companion
{
    /** Output entries per read; the caller reads on from the cursor it was given. */
    const READ_PAGE = 1000;

    /** The window request_stats() sums. */
    const STATS_MINUTES = 60;

    /** How many ended instances instances() answers by default. */
    const HISTORY_SHOWN = 20;

    /**
     * A DISPLAY THRESHOLD, never a timeout: when a host's summaries stop counting. The daemon
     * rewrites its own heartbeat every 30 seconds, so 90 is three rewrites missed - a daemon that
     * is gone, not one that is busy. Nothing is stopped or refused when it passes; the companion
     * reads `unknown` until a daemon writes again.
     */
    const SUMMARY_STALE_SECONDS = 90;

    /**
     * A DISPLAY THRESHOLD, never a timeout: when a companion's heartbeat reads as stale. The SDK
     * heartbeats every 10 seconds and the daemon carries it to Redis on its 30 second record
     * rewrite, so a healthy heartbeat seen here is at most about 40 seconds old; 90 is well past
     * that. A process with no SDK never heartbeats and is never stale.
     */
    const HEARTBEAT_STALE_SECONDS = 90;

    /** Consecutive crashes after which a companion in backoff is crash-looping. */
    const CRASH_LOOP_STREAK = 3;

    /** The statuses of an instance that exists. */
    const LIVE_STATUSES = ['starting', 'running', 'paused', 'stopping'];

    /** Output streams: the process's two, and the daemon's own lines about it. */
    const STREAMS = ['stdout', 'stderr', 'daemon'];

    // ------------------------------------------------------------------------------------
    // READS
    // ------------------------------------------------------------------------------------

    /**
     * The declared companions the viewer may see, sorted.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return Companion_Gates::viewable_names();
    }

    /**
     * The status of every companion the viewer may see, in name order.
     *
     * @return array<int, array>
     */
    public static function all(): array
    {
        $summaries = static::__summaries();

        return array_map(fn ($name) => static::__with_can(static::__compose($name, $summaries)), static::names());
    }

    /**
     * One companion's status, or null when it is not declared or the viewer may not see it.
     */
    public static function status(string $name): ?array
    {
        if (!Companion_Gates::can_view($name)) {
            return null;
        }

        return static::__with_can(static::__compose($name, static::__summaries()));
    }

    /**
     * One companion's status with no gate asked, for the framework's own readers that run with
     * no viewer (rsx:health). Application code calls status().
     *
     * @internal
     */
    public static function read_status(string $name): array
    {
        return static::__compose($name, static::__summaries());
    }

    /**
     * The live instance records (every host) and the newest $count ended ones, newest first:
     * {live: [...], history: [...]}, each an instance record with started_at / ended_at / last
     * heartbeat as ISO and state decoded. Null when the viewer may not see the companion.
     */
    public static function instances(string $name, int $count = self::HISTORY_SHOWN): ?array
    {
        if (!Companion_Gates::can_view($name)) {
            return null;
        }

        return [
            'live' => array_map(fn ($record) => static::__present_record($record), Companion_Store::read_live_instances($name)),
            'history' => array_map(fn ($record) => static::__present_record($record), Companion_Store::read_history($name, $count)),
        ];
    }

    /**
     * Output after an opaque cursor: {lines: [{id, stream, line, instance, at}], last_id, more,
     * trimmed, is_live}. Pass last_id back as the next cursor; null reads from the oldest line
     * the live stream holds.
     *
     * The stream is capped (rsx.companions.output_stream_max_lines) and evictable; the log file
     * under storage/logs/companions/<name>/ is the record. `trimmed` is true when lines this
     * reader has not seen may have been dropped from the stream before the first line answered:
     * a console says so rather than presenting a gap as continuous.
     *
     * Null when the viewer may not see the companion.
     *
     * @param string|null $cursor A last_id this method answered, or null
     * @param array<int, string> $streams Any of STREAMS
     * @throws \InvalidArgumentException for a cursor or stream this method never answers
     */
    public static function output_after(string $name, ?string $cursor, array $streams = self::STREAMS, int $limit = self::READ_PAGE): ?array
    {
        if (!Companion_Gates::can_view($name)) {
            return null;
        }

        if ($cursor !== null && !preg_match('/^\d{1,20}-\d{1,20}$/', $cursor)) {
            throw new \InvalidArgumentException("Not an output cursor: '{$cursor}'");
        }

        foreach ($streams as $stream) {
            if (!in_array($stream, self::STREAMS, true)) {
                throw new \InvalidArgumentException("Unknown output stream '{$stream}'; expected any of " . implode(', ', self::STREAMS) . '.');
            }
        }

        $limit = max(1, min($limit, self::READ_PAGE));
        $read = Companion_Store::read_output($name, $cursor, $limit);
        $is_live = static::read_status($name)['is_live'];

        if ($read === null) {
            return ['lines' => [], 'last_id' => $cursor, 'more' => false, 'trimmed' => false, 'is_live' => $is_live];
        }

        $entries = $read['entries'];

        // Dropped lines this reader never saw: the stream has trimmed some, and they came after
        // the reader's cursor - which is so whenever the oldest line kept is newer than it.
        $trimmed = $read['dropped'] > 0
            && $read['first_id'] !== null
            && ($cursor === null || static::__id_compare($cursor, $read['first_id']) < 0)
            && ($cursor !== null || $entries !== []);

        $lines = array_values(array_filter($entries, fn ($entry) => in_array($entry['stream'], $streams, true)));

        return [
            'lines' => $lines,
            'last_id' => $entries !== [] ? end($entries)['id'] : $cursor,
            'more' => count($entries) === $limit,
            'trimmed' => $trimmed,
            'is_live' => $is_live,
        ];
    }

    /**
     * Endpoint traffic over the last $minutes: endpoint => {calls, errors, avg_ms}. Daemon verbs
     * appear as _daemon/ready and _daemon/event. Counters are evictable: a lost minute is a gap.
     * Null when the viewer may not see the companion.
     */
    public static function request_stats(string $name, int $minutes = self::STATS_MINUTES): ?array
    {
        if (!Companion_Gates::can_view($name)) {
            return null;
        }

        $answer = [];
        foreach (Companion_Store::read_request_stats($name, $minutes) as $endpoint => $totals) {
            $answer[$endpoint] = [
                'calls' => $totals['calls'],
                'errors' => $totals['errors'],
                'avg_ms' => $totals['calls'] > 0 ? (int) round($totals['ms'] / $totals['calls']) : 0,
            ];
        }

        return $answer;
    }

    // ------------------------------------------------------------------------------------
    // VERBS
    // ------------------------------------------------------------------------------------

    /** Ask a companion that uses the SDK to pause its work; the process keeps running. */
    public static function pause(string $name): void
    {
        static::control($name, 'pause');
    }

    /** Resume a paused companion. */
    public static function resume(string $name): void
    {
        static::control($name, 'resume');
    }

    /** A graceful stop, held until start or restart; desired state untouched. */
    public static function stop(string $name): void
    {
        static::control($name, 'stop');
    }

    /** Release a hold (or a restart policy that ended it): it starts if it is desired. */
    public static function start(string $name): void
    {
        static::control($name, 'start');
    }

    /** A graceful stop and an immediate start, with no backoff; desired state untouched. */
    public static function restart(string $name): void
    {
        static::control($name, 'restart');
    }

    /**
     * Send one operator verb to this box's daemon.
     *
     * @param string $action One of Companion_Gates::ACTIONS
     * @throws AjaxUnauthorizedException when the viewer may not, or the companion is not one they can see
     * @throws Companion_Control_Exception when no daemon listens, or it refuses the verb
     */
    public static function control(string $name, string $action): void
    {
        if (!Companion_Gates::can_control($name, $action)) {
            throw new AjaxUnauthorizedException("You may not {$action} this companion.");
        }

        try {
            $answer = Companion_Daemon_Client::send('control', ['name' => $name, 'action' => $action]);
        } catch (RuntimeException $e) {
            throw new Companion_Control_Exception(static::__plain_refusal($e->getMessage()), 0, $e);
        }

        if ($answer === null) {
            throw new Companion_Control_Exception(
                "rsx-companiond is not running on this box, so {$name} cannot be told to {$action} now."
            );
        }

        $actor = Session::get_login_user();
        Log::info("Companion {$name}: {$action} requested", [
            'by' => $actor !== null ? $actor->email : null,
            'login_user_id' => $actor !== null ? $actor->id : null,
            'audit_session_id' => Session::get_audit_session_id(),
        ]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * Every host's summaries, or a reason none can be read: ['hosts' => ..., 'error' => ?string].
     */
    private static function __summaries(): array
    {
        if (Framework_Maintenance::is_active()) {
            return ['hosts' => [], 'error' => 'maintenance mode has stopped redis'];
        }

        try {
            return ['hosts' => Companion_Store::read_summaries() ?? [], 'error' => null];
        } catch (RuntimeException $e) {
            return ['hosts' => [], 'error' => 'redis cannot be read: ' . $e->getMessage()];
        }
    }

    private static function __compose(string $name, array $summaries): array
    {
        $declaration = Manifest::get_companions()[$name] ?? null;

        if ($declaration === null) {
            throw new \InvalidArgumentException("No companion is declared with the name '{$name}'.");
        }

        $now_ms = (int) floor(microtime(true) * 1000);
        $local_host = (string) gethostname();

        $status = [
            'name' => $name,
            'description' => $declaration['description'],
            'single_instance' => (bool) $declaration['single_instance'],
            'contract_key' => $declaration['endpoints_hash'],
            'desired' => Companion_Flags::is_desired($name) ? 'running' : 'stopped',
            'maintenance' => static::maintenance_reason(),
            'status' => 'unknown',
            'unknown_reason' => null,
            'held' => false,
            'waiting_reason' => null,
            'host' => null,
            'instance_id' => null,
            'pid' => null,
            'started_at' => null,
            'uptime_seconds' => null,
            'restart_count' => null,
            'crash_streak' => null,
            'crash_looping' => false,
            'next_restart_at' => null,
            'last_heartbeat' => null,
            'heartbeat_age_seconds' => null,
            'heartbeat_stale' => false,
            'status_line' => null,
            'state' => null,
            'progress' => null,
            'last_error' => null,
            'last_exit' => null,
            'is_live' => false,
            'hosts' => [],
        ];

        if ($summaries['error'] !== null) {
            $status['unknown_reason'] = 'Its state cannot be read: ' . $summaries['error'] . '.';

            return $status;
        }

        // Each live daemon's view of this companion; the local one first.
        $views = [];
        foreach ($summaries['hosts'] as $host => $entry) {
            $daemon = $entry['daemon'];
            $fresh = is_array($daemon) && $now_ms - (int) ($daemon['heartbeat'] ?? 0) <= self::SUMMARY_STALE_SECONDS * 1000;

            if ($fresh && isset($entry['companions'][$name])) {
                $views[$host] = $entry['companions'][$name];
            }
        }
        uksort($views, fn ($a, $b) => ($b === $local_host) <=> ($a === $local_host) ?: strcmp($a, $b));

        foreach ($views as $host => $view) {
            $status['hosts'][] = [
                'host' => $host,
                'status' => $view['status'] ?? null,
                'held' => (bool) ($view['held'] ?? false),
                'instance_id' => $view['instance_id'] ?? null,
                'waiting_reason' => $view['waiting_reason'] ?? null,
            ];
        }

        if ($views === []) {
            $status['unknown_reason'] = 'No running rsx-companiond reports it.';

            return $status;
        }

        // The view to show: the host running an instance (a single-instance companion runs on
        // one host and waits on the others), else this box's, else the first.
        $chosen_host = array_key_first($views);
        foreach ($views as $host => $view) {
            if (!empty($view['instance_id'])) {
                $chosen_host = $host;
                break;
            }
        }
        $view = $views[$chosen_host];

        // The instance record is the fresher source for what the process reports.
        $record = [];
        if (!empty($view['instance_id'])) {
            try {
                $record = Companion_Store::read_instance((string) $view['instance_id']) ?? [];
            } catch (RuntimeException $e) {
                $record = [];
            }
        }
        $pick = fn ($field) => ($record[$field] ?? '') !== '' ? $record[$field] : ($view[$field] ?? null);

        $started_at = $view['started_at'] ?? null;
        $heartbeat = $pick('last_heartbeat');
        $state = $pick('state');
        $progress_total = $pick('progress_total');
        $streak = (int) ($view['crash_streak'] ?? 0);

        $status['status'] = (string) ($view['status'] ?? 'unknown');
        $status['held'] = (bool) ($view['held'] ?? false);
        $status['waiting_reason'] = $view['waiting_reason'] ?? null;
        $status['host'] = $chosen_host;
        $status['instance_id'] = $view['instance_id'] ?? null;
        $status['pid'] = isset($view['pid']) ? (int) $view['pid'] : null;
        $status['started_at'] = static::__ms_iso($started_at);
        $status['uptime_seconds'] = $started_at && $status['instance_id'] ? max(0, intdiv($now_ms - (int) $started_at, 1000)) : null;
        $status['restart_count'] = (int) ($view['restart_count'] ?? 0);
        $status['crash_streak'] = $streak;
        $status['crash_looping'] = $status['status'] === 'crashed' && $streak >= self::CRASH_LOOP_STREAK;
        $status['next_restart_at'] = static::__ms_iso($view['next_restart_at'] ?? null);
        $status['last_heartbeat'] = static::__ms_iso($heartbeat);
        $status['heartbeat_age_seconds'] = $heartbeat ? max(0, intdiv($now_ms - (int) $heartbeat, 1000)) : null;
        $status['status_line'] = ($pick('status_line') ?? '') !== '' ? (string) $pick('status_line') : null;
        $status['state'] = is_string($state) && $state !== '' ? json_decode($state, true) : null;
        $status['progress'] = $progress_total !== null && $progress_total !== '' ? ['current' => (float) $pick('progress_current'), 'total' => (float) $progress_total] : null;
        $status['last_error'] = ($pick('last_error') ?? '') !== '' ? (string) $pick('last_error') : null;
        $status['is_live'] = $status['instance_id'] !== null && in_array($status['status'], self::LIVE_STATUSES, true);
        $status['heartbeat_stale'] = $status['is_live'] && $heartbeat && $status['heartbeat_age_seconds'] > self::HEARTBEAT_STALE_SECONDS;

        $exit = $view['last_exit'] ?? null;
        if (is_array($exit)) {
            $exit['at'] = static::__ms_iso($exit['at'] ?? null);
            $status['last_exit'] = $exit;
        }

        return $status;
    }

    /**
     * Why maintenance holds every companion down right now, or null. Read off disk: this is
     * what the box is doing, not what this process booted into.
     */
    public static function maintenance_reason(): ?string
    {
        if (Framework_Maintenance::is_active_on_disk()) {
            return 'maintenance mode: ' . Framework_Maintenance::reason();
        }

        if (Companion_Flags::is_maintenance_on()) {
            return 'companion maintenance is on (rsx:companion_apps:maintenance_off ends it)';
        }

        return null;
    }

    private static function __with_can(array $status): array
    {
        $status['can'] = [];
        foreach (Companion_Gates::ACTIONS as $action) {
            $status['can'][$action] = Companion_Gates::can_control($status['name'], $action);
        }

        return $status;
    }

    private static function __present_record(array $record): array
    {
        foreach (['started_at', 'ended_at', 'last_heartbeat'] as $field) {
            if (array_key_exists($field, $record)) {
                $record[$field] = static::__ms_iso($record[$field]);
            }
        }

        if (isset($record['state']) && is_string($record['state'])) {
            $record['state'] = $record['state'] === '' ? null : json_decode($record['state'], true);
        }

        foreach (['exit_code', 'restart_count', 'pid'] as $field) {
            if (isset($record[$field]) && $record[$field] !== '' && is_numeric($record[$field])) {
                $record[$field] = (int) $record[$field];
            } elseif (isset($record[$field]) && $record[$field] === '') {
                $record[$field] = null;
            }
        }

        return $record;
    }

    /** Unix milliseconds (int or digit string) as ISO 8601 UTC with milliseconds, or null. */
    private static function __ms_iso($ms): ?string
    {
        if ($ms === null || $ms === '' || !is_numeric($ms)) {
            return null;
        }

        $ms = (int) $ms;

        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    /** Compare two stream ids "<ms>-<seq>" numerically. */
    private static function __id_compare(string $a, string $b): int
    {
        [$a_ms, $a_seq] = array_map('intval', explode('-', $a, 2) + [1 => '0']);
        [$b_ms, $b_seq] = array_map('intval', explode('-', $b, 2) + [1 => '0']);

        return [$a_ms, $a_seq] <=> [$b_ms, $b_seq];
    }

    /** The daemon's refusal, without the transport's wording. */
    private static function __plain_refusal(string $message): string
    {
        return preg_replace('/^rsx-companiond refused control: /', '', $message);
    }
}
