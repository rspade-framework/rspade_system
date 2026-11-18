<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\Lockd_Connection;
use App\RSpade\Lib\Support\Process\Process_Identity;

/**
 * What rsx-lockd holds right now, read for an operator - and the one way to end a holder.
 * The engine behind `php artisan rsx:locks:status` and the /_sys Locks screen; both render
 * snapshot() and both kill through kill_holder(), so the identity checks live once.
 *
 * NAMES. The daemon knows a lock by its wire name, which carries this environment's
 * connection scope (`cluster:<32 hex>__SITE_1`). A snapshot shows it as the application
 * names it (`cluster:SITE_1`, a semaphore by its bare name); a lock of ANOTHER environment
 * sharing the daemon (a test database, a second checkout) keeps its wire name and is marked
 * `foreign`. A lock taken unscoped ($db_scoped = false) is already `cluster:<name>`.
 *
 * KILLING A HOLDER. The daemon reports each connection's host, pid and - from its hello - the
 * process START TIME. A pid alone is never proof (the kernel reuses pids; a container's pid
 * space restarts at 1), so kill_holder() signals a holder only when ALL of these hold for
 * EVERY holder of the lock: it is on THIS host (gethostname() as its hello reported), it is
 * not this process, its hello carried a start time, and Process_Identity says that pid is
 * still the process that started then. Anything else refuses the whole kill and says why.
 * Then SIGTERM; the connection closing at the process's exit is what frees the lock.
 */
class Lock_Status
{
    /**
     * Everything the daemon holds and queues, named for an operator.
     *
     * @return array{available: bool, reason?: string, endpoint: string, host: string,
     *               stall_seconds: int, wait_notice_seconds: int, connections: int,
     *               counters: array, locks: array, semaphores: array, pools: array}
     *         Each lock / semaphore: name, wire_name, foreign, (max_slots), holders[]
     *         {conn_id, host, pid, start, mode|slot, held_ms, progress_ms, is_self, is_local},
     *         waiters[] {conn_id, host, pid, mode, waiting_ms, timeout, stall}.
     */
    public static function snapshot(): array
    {
        $endpoint = Lockd_Connection::default_endpoint();
        $base = [
            'endpoint' => $endpoint['host'] . ':' . $endpoint['port'],
            'host' => (string) gethostname(),
            'stall_seconds' => (int) config('rsx.locks.stall_seconds', 600),
            'wait_notice_seconds' => (int) config('rsx.locks.wait_notice_seconds', 30),
        ];

        // A quiesced fleet has no daemon, and nothing is held anywhere (cluster locks are
        // no-op grants for the window) - an answer, not an outage.
        if (Framework_Maintenance::is_active_on_disk()) {
            return $base + [
                'available' => false,
                'reason' => 'The fleet is quiesced for maintenance; rsx-lockd is stopped on purpose and no cluster lock is held anywhere.',
            ];
        }

        $dump = Lockd_Client::request(['op' => 'dump']);

        if (($dump['status'] ?? 'error') !== 'ok') {
            throw new \RuntimeException('rsx-lockd refused the dump request: ' . ($dump['message'] ?? 'unknown error'));
        }

        $connections = [];
        foreach ($dump['connections'] as $conn) {
            $connections[$conn['conn_id']] = $conn;
        }

        $self_pid = getmypid();
        $self_host = $base['host'];
        $who = function (string $conn_id) use ($connections, $self_pid, $self_host): array {
            $conn = $connections[$conn_id] ?? [];
            $host = $conn['host'] ?? null;
            $pid = $conn['pid'] ?? null;

            return [
                'conn_id' => $conn_id,
                'host' => $host,
                'pid' => $pid,
                'start' => $conn['start'] ?? null,
                'is_local' => $host === $self_host,
                'is_self' => $host === $self_host && $pid === $self_pid,
            ];
        };

        $locks = [];
        foreach ($dump['locks'] as $lock) {
            $named = self::__display_name($lock['name'], 'lock');
            $locks[] = $named + [
                'holders' => array_map(fn ($h) => $who($h['conn_id']) + [
                    'mode' => $h['mode'],
                    'held_ms' => (int) $h['held_ms'],
                    'progress_ms' => (int) ($h['progress_ms'] ?? $h['held_ms']),
                ], $lock['holders']),
                'waiters' => array_map(fn ($q) => $who($q['conn_id']) + [
                    'mode' => $q['mode'],
                    'waiting_ms' => (int) $q['waiting_ms'],
                    'timeout' => $q['timeout'] ?? null,
                    'stall' => $q['stall'] ?? null,
                ], $lock['queue']),
            ];
        }

        $semaphores = [];
        foreach ($dump['semaphores'] as $semaphore) {
            $named = self::__display_name($semaphore['name'], 'semaphore');
            $semaphores[] = $named + [
                'max_slots' => (int) $semaphore['max_slots'],
                'holders' => array_map(fn ($h) => $who($h['conn_id']) + [
                    'slot' => (int) $h['slot'],
                    'held_ms' => (int) $h['held_ms'],
                    'progress_ms' => (int) ($h['progress_ms'] ?? $h['held_ms']),
                ], $semaphore['holders']),
                'waiters' => array_map(fn ($q) => $who($q['conn_id']) + [
                    'mode' => null,
                    'waiting_ms' => (int) $q['waiting_ms'],
                    'timeout' => $q['timeout'] ?? null,
                    'stall' => $q['stall'] ?? null,
                ], $semaphore['queue']),
            ];
        }

        $pools = [];
        foreach ($dump['pools'] as $pool) {
            $pools[] = [
                'pool' => $pool['pool'],
                'members' => count($pool['members'] ?? []),
                'holder' => $pool['holder'] === null ? null : $who($pool['holder']),
                'held_ms' => $pool['held_ms'] ?? null,
                'waiting' => count($pool['queue'] ?? []),
            ];
        }

        $by_name = fn ($a, $b) => strcmp($a['name'], $b['name']);
        usort($locks, $by_name);
        usort($semaphores, $by_name);

        return $base + [
            'available' => true,
            'connections' => count($dump['connections']),
            'counters' => $dump['counters'],
            'locks' => $locks,
            'semaphores' => $semaphores,
            'pools' => $pools,
        ];
    }

    /**
     * End the process holding a lock (or every slot holder of a semaphore), by name as
     * snapshot() shows it (`cluster:SITE_1`, `SITE_1`, a semaphore's name, or a wire name).
     *
     * ALL OR NOTHING: every holder is checked first (THIS host, not this process, a start
     * time on record, Process_Identity proving the pid is still that process), and one that
     * fails refuses the whole kill. Then SIGTERM to each - the lock is freed when the
     * process exits and its connection closes. Each signal is logged as a warning.
     *
     * @return array{ok: bool, message: string, killed: array<int, array{host: string, pid: int, conn_id: string}>}
     */
    public static function kill_holder(string $name): array
    {
        $refuse = fn (string $message) => ['ok' => false, 'message' => $message, 'killed' => []];

        $name = trim($name);
        if ($name === '') {
            return $refuse('Name the lock whose holder to end, as rsx:locks:status shows it.');
        }

        $snapshot = self::snapshot();
        if (!$snapshot['available']) {
            return $refuse($snapshot['reason']);
        }

        $matches = [];
        foreach (array_merge($snapshot['locks'], $snapshot['semaphores']) as $entry) {
            if ($entry['name'] === $name || $entry['wire_name'] === $name || $entry['name'] === 'cluster:' . $name) {
                $matches[] = $entry;
            }
        }

        if ($matches === []) {
            return $refuse("No lock or semaphore named {$name} is held or waited for right now (rsx:locks:status lists them).");
        }
        if (count($matches) > 1) {
            return $refuse("{$name} names both a lock and a semaphore; give the full name as rsx:locks:status shows it.");
        }

        $entry = $matches[0];
        if ($entry['holders'] === []) {
            return $refuse("{$entry['name']} has no holder right now.");
        }

        foreach ($entry['holders'] as $holder) {
            $who = ($holder['host'] ?? '?') . ':' . ($holder['pid'] ?? '?') . " (connection {$holder['conn_id']})";

            if (!$holder['is_local']) {
                return $refuse("{$entry['name']} is held by {$who}, which is not this host ({$snapshot['host']}): "
                    . 'a pid names a process only on its own host. Run this on that host. Nothing was signalled.');
            }
            if ($holder['is_self']) {
                return $refuse("{$entry['name']} is held by {$who}, which is this process. Nothing was signalled.");
            }
            if ($holder['start'] === null) {
                return $refuse("{$entry['name']} is held by {$who}, whose connection reported no process start time, "
                    . 'so the pid cannot be proven to be that process. Nothing was signalled.');
            }
            if (!Process_Identity::is_running((int) $holder['pid'], (int) $holder['start'])) {
                return $refuse("{$entry['name']} is held by {$who}, but pid {$holder['pid']} is no longer the process "
                    . 'that connected (its start time does not match, or it has exited). Nothing was signalled.');
            }
        }

        $killed = [];
        foreach ($entry['holders'] as $holder) {
            $pid = (int) $holder['pid'];
            $who = "{$holder['host']}:{$pid} (connection {$holder['conn_id']})";

            if (!posix_kill($pid, SIGTERM)) {
                $error = posix_strerror(posix_get_last_error());

                return [
                    'ok' => false,
                    'message' => "SIGTERM to {$who} failed: {$error}."
                        . ($killed === [] ? '' : ' Already signalled: ' . implode(', ', array_map(fn ($k) => "{$k['host']}:{$k['pid']}", $killed)) . '.'),
                    'killed' => $killed,
                ];
            }

            Log::warning("Lock_Status: sent SIGTERM to {$who}, the holder of {$entry['name']}, held "
                . number_format($holder['held_ms'] / 1000, 1, '.', '') . 's, last progress '
                . number_format($holder['progress_ms'] / 1000, 1, '.', '') . 's ago (by pid ' . getmypid() . ')');

            $killed[] = ['host' => $holder['host'], 'pid' => $pid, 'conn_id' => $holder['conn_id']];
        }

        return [
            'ok' => true,
            'message' => 'Sent SIGTERM to ' . implode(', ', array_map(fn ($k) => "{$k['host']}:{$k['pid']}", $killed))
                . ". {$entry['name']} is freed when the process exits and its connection closes.",
            'killed' => $killed,
        ];
    }

    /**
     * A wire name as the application names it, plus the wire name and whether the lock
     * belongs to another environment sharing the daemon.
     *
     * @return array{name: string, wire_name: string, foreign: bool}
     */
    private static function __display_name(string $wire_name, string $kind): array
    {
        $scope = Rsx_Connection_Scope::token();
        // A lock's wire name is `<domain>:<scope>__<name>` (cluster:, or another client's own
        // domain such as the companion daemon's companion:); a semaphore's is `<scope>__<name>`.
        $pattern = $kind === 'lock' ? '/^([a-z_]+:)([0-9a-f]{32})__(.*)$/s' : '/^()([0-9a-f]{32})__(.*)$/s';

        if (preg_match($pattern, $wire_name, $m) === 1) {
            if ($m[2] === $scope) {
                return ['name' => $m[1] . $m[3], 'wire_name' => $wire_name, 'foreign' => false];
            }

            return ['name' => $wire_name, 'wire_name' => $wire_name, 'foreign' => true];
        }

        return ['name' => $wire_name, 'wire_name' => $wire_name, 'foreign' => false];
    }
}
