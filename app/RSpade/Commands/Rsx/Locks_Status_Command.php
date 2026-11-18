<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Locks\Lock_Status;
use Illuminate\Console\Command;

/**
 * rsx:locks:status - every cluster lock and semaphore rsx-lockd holds right now, who holds
 * it (connection, host:pid), for how long and since when it last showed progress; every
 * waiter with how long it has waited and the bound it waits under; the worker pools.
 *
 *   --kill-holder=<lock>   end the process holding <lock> (as this command names it, e.g.
 *                          cluster:SITE_1 or a semaphore's name): refused unless every
 *                          holder is on THIS host, is not this process, and is proven to be
 *                          the process that took the lock (pid + start time). SIGTERM; the
 *                          lock frees when the process exits. Lock_Status::kill_holder().
 *   --json                 the snapshot as JSON (Lock_Status::snapshot())
 *
 * Exit 0 with the report, or after a kill was sent; 1 when a kill is refused or fails.
 */
class Locks_Status_Command extends Command
{
    protected $signature = 'rsx:locks:status
                            {--kill-holder= : End the process holding this lock (as the report names it), after proving its identity}
                            {--json : Print the snapshot as JSON}';

    protected $description = 'Show every lock and semaphore rsx-lockd holds, its holder and waiters; optionally end a hung holder';

    public function handle()
    {
        $kill = $this->option('kill-holder');

        if ($kill !== null) {
            $result = Lock_Status::kill_holder((string) $kill);

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } elseif ($result['ok']) {
                $this->info('[OK] ' . $result['message']);
            } else {
                $this->error('[ERROR] ' . $result['message']);
            }

            return $result['ok'] ? 0 : 1;
        }

        $snapshot = Lock_Status::snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return 0;
        }

        if (!$snapshot['available']) {
            $this->warn('[WARNING] ' . $snapshot['reason']);

            return 0;
        }

        $this->line("rsx-lockd at {$snapshot['endpoint']} - seen from {$snapshot['host']}");
        $this->line('  connections: ' . $snapshot['connections']
            . '   locks: ' . count($snapshot['locks'])
            . '   semaphores: ' . count($snapshot['semaphores'])
            . '   pools: ' . count($snapshot['pools']));
        $this->line('  stall window: ' . ($snapshot['stall_seconds'] > 0 ? $snapshot['stall_seconds'] . 's' : 'off')
            . ' (rsx.locks.stall_seconds)   web wait notice: '
            . ($snapshot['wait_notice_seconds'] > 0 ? $snapshot['wait_notice_seconds'] . 's' : 'off')
            . '   lifetime stalled: ' . ($snapshot['counters']['stalled'] ?? 0)
            . '   timed out: ' . ($snapshot['counters']['timed_out'] ?? 0)
            . '   deadlocks refused: ' . ($snapshot['counters']['deadlocked'] ?? 0));

        $this->line('');
        $this->line('LOCKS');
        if ($snapshot['locks'] === []) {
            $this->line('  (none held or waited for)');
        }
        foreach ($snapshot['locks'] as $lock) {
            $this->__print_entry($lock, fn ($h) => strtoupper($h['mode']), $snapshot['stall_seconds']);
        }

        $this->line('');
        $this->line('SEMAPHORES');
        if ($snapshot['semaphores'] === []) {
            $this->line('  (none held or waited for)');
        }
        foreach ($snapshot['semaphores'] as $semaphore) {
            $semaphore['name'] .= '  ' . count($semaphore['holders']) . '/' . $semaphore['max_slots'] . ' slots';
            $this->__print_entry($semaphore, fn ($h) => 'SLOT ' . $h['slot'], $snapshot['stall_seconds']);
        }

        $this->line('');
        $this->line('POOLS');
        if ($snapshot['pools'] === []) {
            $this->line('  (none)');
        }
        foreach ($snapshot['pools'] as $pool) {
            $this->line('  ' . $pool['pool'] . '   members: ' . $pool['members']
                . '   lock: ' . ($pool['holder'] === null
                    ? 'free'
                    : 'held by ' . $this->__who($pool['holder']) . ' for ' . $this->__seconds((int) $pool['held_ms']))
                . '   waiting: ' . $pool['waiting']);
        }

        return 0;
    }

    private function __print_entry(array $entry, callable $what, int $stall_seconds): void
    {
        $this->line('  ' . $entry['name'] . ($entry['foreign'] ? '   (another environment)' : ''));

        foreach ($entry['holders'] as $holder) {
            // Only worth a warning when somebody is waiting: a silent holder with no queue
            // behind it costs nobody anything (a lease held for a daemon's whole life is one).
            $silent = $entry['waiters'] !== [] && $stall_seconds > 0 && $holder['progress_ms'] >= $stall_seconds * 1000
                ? '   [WARNING] silent for longer than the stall window, with waiters' : '';
            $this->line('    HELD     ' . str_pad($what($holder), 7) . ' by ' . $this->__who($holder)
                . '   for ' . $this->__seconds($holder['held_ms'])
                . '   last progress ' . $this->__seconds($holder['progress_ms']) . ' ago' . $silent);
        }

        foreach ($entry['waiters'] as $waiter) {
            $bounds = [];
            $bounds[] = $waiter['timeout'] === null ? 'no timeout' : 'timeout ' . $waiter['timeout'] . 's';
            if ($waiter['stall'] !== null) {
                $bounds[] = 'stall watch ' . $waiter['stall'] . 's';
            }
            $this->line('    WAITING  ' . str_pad($waiter['mode'] === null ? 'SLOT' : strtoupper($waiter['mode']), 7)
                . ' by ' . $this->__who($waiter)
                . '   for ' . $this->__seconds($waiter['waiting_ms']) . '   (' . implode(', ', $bounds) . ')');
        }
    }

    private function __who(array $party): string
    {
        return $party['conn_id'] . ' ' . ($party['host'] ?? '?') . ':' . ($party['pid'] ?? '?')
            . ($party['is_self'] ? ' (this command)' : '');
    }

    private function __seconds(int $ms): string
    {
        return number_format($ms / 1000, 1, '.', '') . 's';
    }
}
