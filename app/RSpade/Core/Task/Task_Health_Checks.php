<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Task_Health_Checks - scheduler probes for rsx:health.
 *
 * Declared next to the task machinery they probe. One reads the worker pools as rsx-lockd
 * accounts them; the other two read _task_schedules: one asks whether the
 * `rsx:task:process` cron is ticking at all, the other whether a schedule that IS ticking
 * keeps failing.
 *
 * There is NO scheduler heartbeat timestamp in the system - the only durable signal is
 * _task_schedules, which the cron reconciles and a scheduled-pool worker advances. Zero
 * schedules => the cron has never run. A schedule whose next_run_at is well in the past =>
 * nothing is advancing it. That
 * staleness signal CANNOT distinguish a missing cron tick from a wedged worker pool, so
 * it is a WARN-with-caveat in development - where a box that is never left running is
 * expected to have a stale schedule.
 *
 * ON A SEALED BUILD IT IS A FAIL. A production box with no scheduler is a box where
 * nothing is sweeping the mail queue, rendering documents, rotating logs or reaping
 * workers, and every one of those failures is silent. The caveat still stands (it cannot
 * say WHICH of the two is wrong), but "one of these two is broken" is a deploy-gating
 * fact there, not an advisory one.
 */
class Task_Health_Checks
{
    /** Minutes past next_run_at before the oldest schedule is considered stale. */
    private const STALE_MINUTES = 10;

    /** Characters of a failing schedule's explanation carried into the WARN detail. */
    private const REASON_EXCERPT_LENGTH = 60;

    /**
     * The task worker pools as rsx-lockd accounts them (Task_Pool::stats(), read without the
     * pool locks): how many workers each pool holds against its cap, and how many processes
     * are parked waiting for its lock.
     *
     * More members than a cap is a WARN - admission happens under the pool lock, so it means
     * the cap was lowered under running workers, or two configurations share a pool.
     *
     * RUNNING rows claimed under a previous rsx-lockd generation on ANOTHER host are a WARN
     * too (previous_generation_rows()): each host's rsx:task:process settles its own such
     * rows by pid, so they stay RUNNING for as long as their host runs no tick - a host that
     * was retired, or whose cron is gone.
     * An unreachable daemon or a refused pool.stats is a FAIL: nothing can be admitted. Under
     * the maintenance flag the daemon is stopped on purpose, which is INFO (the Lock Server
     * row's convention; the flag is read off disk, as a probe of the box right now).
     *
     * @return array
     */
    #[Health_Check('Task Worker Pools')]
    public static function task_worker_pool(): array
    {
        if (Framework_Maintenance::is_active_on_disk()) {
            return [
                'status' => 'INFO',
                'detail' => 'the maintenance flag is up - rsx-lockd is stopped on purpose, and no worker runs',
            ];
        }

        $parts = [];
        $over = [];
        $generation = null;
        foreach (Task_Pool::POOLS as $pool) {
            // The probe's expected failure is the finding itself: Task_Pool throws for an
            // unreachable daemon and for a refused pool.stats alike.
            try {
                $stats = Task_Pool::stats($pool);
            } catch (\RuntimeException $e) {
                return [
                    'status' => 'FAIL',
                    'detail' => "cannot read the {$pool} task worker pool from rsx-lockd: " . $e->getMessage(),
                    'remediation' => 'check the Lock Server row; a daemon that predates the pool.* ops must be'
                        . ' restarted (supervisorctl restart rsx-lockd)',
                ];
            }

            $cap = Task_Pool::max_workers($pool);
            $generation = $stats['generation'];
            $parts[] = "{$pool} {$stats['members']} of {$cap}" . ($stats['waiting'] ? " ({$stats['waiting']} waiting)" : '');
            if ($stats['members'] > $cap) {
                $over[] = $pool;
            }
        }

        $detail = implode('; ', $parts);

        if ($over !== []) {
            return [
                'status' => 'WARN',
                'detail' => $detail . ' - more members than rsx.tasks.pools.<pool>.max_workers in ' . implode(', ', $over),
                'remediation' => 'the cap was lowered under running workers (they drain as they finish),'
                    . ' or two configurations share one database and host',
            ];
        }

        $previous = self::previous_generation_rows((int) $generation);
        if ($previous['count'] > 0) {
            return [
                'status' => 'WARN',
                'detail' => $detail . '; ' . $previous['count'] . ' running task(s) from a previous rsx-lockd'
                    . ' generation on host(s) ' . implode(', ', $previous['hosts']) . '; each host\'s'
                    . ' rsx:task:process reaps its own - a host that no longer runs one leaves them RUNNING',
                'remediation' => 'confirm the rsx:task:process cron runs on each named host; for a host'
                    . ' that is gone, settle its runs with rsx:tasks:stop <id> --kill --explanation="..."',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $detail,
        ];
    }

    /**
     * RUNNING rows claimed by a pool member of an rsx-lockd generation other than $generation,
     * on a host other than this one - the rows this host's reaper can never settle.
     *
     * @param int $generation The daemon's current generation (Task_Pool::stats())
     * @return array{count: int, hosts: string[]}
     */
    public static function previous_generation_rows(int $generation): array
    {
        $rows = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->whereNotNull('worker_id')
            ->where('worker_generation', '!=', $generation)
            ->where('worker_host', '!=', Task_Pool::host())
            ->get(['worker_host']);

        $hosts = [];
        foreach ($rows as $row) {
            $hosts[$row->worker_host] = true;
        }
        $hosts = array_keys($hosts);
        sort($hosts);

        return ['count' => count($rows), 'hosts' => $hosts];
    }

    /**
     * Infer scheduler liveness from _task_schedules staleness.
     *
     * @return array
     */
    #[Health_Check('Task Scheduler Liveness')]
    public static function task_scheduler_liveness(): array
    {
        $schedule_count = DB::table('_task_schedules')->count();

        // A missing scheduler is silent, and on a sealed build everything it drives is
        // load-bearing - so the same finding gates a deploy there and advises here.
        $severity = Rsx::is_production() ? 'FAIL' : 'WARN';

        if ($schedule_count === 0) {
            return [
                'status' => $severity,
                'detail' => 'no schedules registered - rsx:task:process has never run',
                'remediation' => 'install the cron entry: * * * * * cd '
                    . base_path() . ' && php artisan rsx:task:process',
            ];
        }

        $oldest = DB::table('_task_schedules')->min('next_run_at');
        $stale_seconds = Rsx_Time::seconds_since($oldest);

        if ($stale_seconds > self::STALE_MINUTES * 60) {
            return [
                'status' => $severity,
                'detail' => $schedule_count . ' schedule(s); oldest is due ' . Rsx_Time::relative($oldest)
                    . ' (over ' . self::STALE_MINUTES . 'min stale). NOTE: this cannot distinguish a'
                    . ' missing cron tick from a stalled worker pool',
                'remediation' => 'verify the rsx:task:process cron is running and the worker pool is not wedged',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $schedule_count . ' schedule(s); next due ' . Rsx_Time::relative($oldest),
        ];
    }

    /**
     * Report schedules whose runs keep failing.
     *
     * A failing schedule is easy to miss: each failing run is FAILED, but the schedule runs
     * again at its next cadence, so it looks alive while the work never succeeds.
     * consecutive_failures is what distinguishes "retrying" from "broken every night for a
     * week".
     *
     * WARN, never FAIL: the schedule is still running, and one repeatedly-failing task
     * is not a reason to fail the environment's health.
     *
     * @return array
     */
    #[Health_Check('Task Schedule Failures')]
    public static function task_schedule_failures(): array
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after', 3);

        $failing = DB::table('_task_schedules')
            ->where('consecutive_failures', '>=', $threshold)
            ->orderByDesc('consecutive_failures')
            ->get(['class', 'method', 'consecutive_failures', 'last_error']);

        if ($failing->isEmpty()) {
            return [
                'status' => 'OK',
                'detail' => 'no schedule failing repeatedly',
            ];
        }

        // Bounded by the number of #[Schedule] definitions in the manifest, so the whole
        // offender list is named rather than a count with a "see the logs" pointer.
        $offenders = [];
        foreach ($failing as $schedule) {
            $offender = class_basename($schedule->class) . '::' . $schedule->method
                . ' (' . (int) $schedule->consecutive_failures . ' consecutive failures';

            $reason = static::__excerpt_reason($schedule->last_error);
            if ($reason !== '') {
                $offender .= ', last: ' . $reason;
            }

            $offenders[] = $offender . ')';
        }

        return [
            'status' => 'WARN',
            'detail' => count($offenders) . ' schedule(s) failing every run: ' . implode('; ', $offenders),
            'remediation' => 'inspect the failure with php artisan rsx:tasks:list and fix the task;'
                . ' the schedule keeps retrying at its cadence meanwhile',
        ];
    }

    /**
     * First line of a failure explanation, capped for a one-line health detail.
     *
     * @param string|null $reason The schedule's last_error.
     * @return string Empty when the row carries no explanation.
     */
    private static function __excerpt_reason(?string $reason): string
    {
        $reason = trim(explode("\n", (string) $reason)[0]);

        if (mb_strlen($reason) > self::REASON_EXCERPT_LENGTH) {
            $reason = mb_substr($reason, 0, self::REASON_EXCERPT_LENGTH - 3) . '...';
        }

        return $reason;
    }
}
