<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lockd_Unavailable_Exception;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Task_Health_Checks - scheduler probes for rsx:health.
 *
 * Declared next to the task machinery they probe. One reads the task system's switch; one
 * reads the worker pools as rsx-lockd accounts them; the other two read _task_schedules: one asks whether the
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
     * Whether the task system is on (Task::disabled_state()).
     *
     * OFF is a FAIL: no queued run starts and no schedule runs, silently, for as long as
     * nobody turns it back on - and a box left off after a server replacement or a
     * maintenance window whose disable step failed looks healthy in every other row. The
     * detail names since when, by whom and why, and the remediation is the command.
     *
     * Under the maintenance flag the system is off because the window turned it off, and
     * leaving the window turns it back on: that is INFO (the flag is read off disk, as a probe
     * of the box right now - the Task Worker Pools row's convention).
     *
     * @return array
     */
    #[Health_Check('Task System')]
    public static function task_system(): array
    {
        $state = Task::disabled_state();

        if ($state === null) {
            return [
                'status' => 'OK',
                'detail' => 'enabled',
            ];
        }

        $detail = "disabled since {$state['disabled_at']} by {$state['disabled_by']} on {$state['host']}"
            . ($state['reason'] !== null ? " ({$state['reason']})" : '');

        if (Framework_Maintenance::is_active_on_disk()) {
            return [
                'status' => 'INFO',
                'detail' => $detail . ' - the maintenance flag is up; php artisan rsx:maintenance:disable turns the task system back on',
            ];
        }

        return [
            'status' => 'FAIL',
            'detail' => $detail . ' - no queued task starts and no schedule runs',
            'remediation' => 'php artisan rsx:tasks:enable',
        ];
    }

    /**
     * The task worker pools as rsx-lockd accounts them (Task_Pool::stats(), read without the
     * pool locks): how many workers each pool holds against its cap, and how many processes
     * are parked waiting for its lock.
     *
     * More members than a cap is a WARN - admission happens under the pool lock, so it means
     * the cap was lowered under running workers, or two configurations share a pool.
     *
     * RUNNING rows on ANOTHER host that rsx-lockd cannot answer for - claimed under a previous
     * generation, or run inline - are a WARN too (unanswered_foreign_rows()): their own host's
     * rsx:task:process judges them by pid, and when that host has gone silent (retired,
     * recreated, its cron gone) any host's tick settles them once they and their host have
     * shown no sign of life for rsx.tasks.vanished_host_timeout. The detail says when the
     * next one is due to be settled; nothing is asked of the operator.
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
            // The probe's expected failure is the finding itself: Task_Pool throws the outage
            // for an unreachable daemon and a RuntimeException for a refused pool.stats. A
            // health check's job is to report the daemon DOWN, so it catches both.
            try {
                $stats = Task_Pool::stats($pool);
            } catch (\RuntimeException|Lockd_Unavailable_Exception $e) {
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

        $unanswered = self::unanswered_foreign_rows((int) $generation);
        if ($unanswered['count'] > 0) {
            $limit = Task::vanished_host_timeout();
            $minutes = (int) ceil($unanswered['next_settle_seconds'] / 60);
            $next = $minutes > 0 ? "next in about {$minutes} min" : 'next on the coming tick';

            return [
                'status' => 'WARN',
                'detail' => $detail . '; ' . $unanswered['count'] . ' running task(s) on host(s) '
                    . implode(', ', $unanswered['hosts']) . ' that rsx-lockd cannot answer for; each is'
                    . " settled automatically once it and its host have been silent for {$limit}s"
                    . " (rsx.tasks.vanished_host_timeout; {$next})",
                'remediation' => 'none needed; a host that is alive must run rsx:task:process',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $detail,
        ];
    }

    /**
     * RUNNING rows on a host other than this one that rsx-lockd cannot answer for: claimed by a
     * pool member of a generation other than $generation, or run inline (no worker_id). Their
     * own host judges them by pid; when it has gone silent, the vanished-host rule settles them.
     *
     * @param int $generation The daemon's current generation (Task_Pool::stats())
     * @return array{count: int, hosts: string[], next_settle_seconds: int|null} next_settle_seconds:
     *         how long until the first of them passes rsx.tasks.vanished_host_timeout (0 = already
     *         past it, settled by the next tick); null when there are none
     */
    public static function unanswered_foreign_rows(int $generation): array
    {
        $rows = DB::table('_tasks')
            ->select('worker_host')
            ->selectRaw(Task::sql_seconds_since_evidence() . ' AS seconds_since_evidence')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->where(function ($query) use ($generation) {
                $query->whereNull('worker_id')->orWhere('worker_generation', '!=', $generation);
            })
            ->whereNotNull('worker_host')
            ->where('worker_host', '!=', Task_Pool::host())
            ->get();

        $hosts = [];
        $next = null;
        foreach ($rows as $row) {
            $hosts[$row->worker_host] = true;
            $remaining = max(0, Task::vanished_host_timeout() - (int) $row->seconds_since_evidence);
            $next = $next === null ? $remaining : min($next, $remaining);
        }
        $hosts = array_keys($hosts);
        sort($hosts);

        return ['count' => count($rows), 'hosts' => $hosts, 'next_settle_seconds' => $next];
    }

    /**
     * Infer scheduler liveness from _task_schedules staleness.
     *
     * @return array
     */
    #[Health_Check('Task Scheduler Liveness')]
    public static function task_scheduler_liveness(): array
    {
        // With the task system off no schedule advances, by design: the Task System row is
        // the finding, and a stale schedule here would only repeat it as a second one.
        if (!Task::is_enabled()) {
            return [
                'status' => 'INFO',
                'detail' => 'the task system is disabled - no schedule runs (see the Task System row)',
            ];
        }

        $schedule_count = DB::table('_task_schedules')->count();

        // A missing scheduler is silent, and on a sealed build everything it drives is
        // load-bearing - so the same finding gates a deploy there and advises here.
        $severity = Rsx::is_production() ? 'FAIL' : 'WARN';

        if ($schedule_count === 0) {
            return [
                'status' => $severity,
                'detail' => 'no schedules registered - rsx:task:process has never run',
                'remediation' => 'install the cron entry: * * * * * cd '
                    . base_path() . ' && php artisan rsx:task:process'
                    . ' (in the RSpade container: supervisorctl status tasks, rspade-taskd status)',
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
                'remediation' => 'verify the scheduler is running - the rsx:task:process cron line, or in the RSpade container the `tasks` program (rspade-taskd status) - and the worker pool is not wedged',
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
