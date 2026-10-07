<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * List live task state: the worker pools, the RUNNING runs (with worker PIDs), the count of
 * queued runs, then every #[Schedule] with its cadence and failure record. Distinct from
 * rsx:task:list, which lists task DEFINITIONS from the manifest and touches no database.
 */
class Tasks_List_Command extends Command
{
    protected $signature = 'rsx:tasks:list';
    protected $description = 'List the worker pools, running task runs (with worker PIDs), queued count and schedules';

    /** Characters of the failure explanation shown in the Schedules table. */
    private const ERROR_EXCERPT_LENGTH = 50;

    public function handle()
    {
        $this->list_pools();
        $this->newLine();
        $this->list_running_tasks();
        $this->newLine();
        $this->list_schedules();

        return 0;
    }

    /** Each pool's members against its cap. */
    private function list_pools(): void
    {
        $rows = [];
        foreach (Task_Pool::POOLS as $pool) {
            $rows[] = [$pool, Task_Pool::stats($pool)['members'] . ' of ' . Task_Pool::max_workers($pool)];
        }

        $this->table(['Pool', 'Workers'], $rows);
    }

    /**
     * Runs currently executing, with the worker PID that owns each, then the queued count.
     */
    private function list_running_tasks(): void
    {
        $rows = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->orderBy('started_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No running tasks.');
        } else {
            $this->table(
                ['ID', 'Task', 'Origin', 'Worker PID', 'Started', 'Progress', 'Status'],
                $rows->map(fn ($r) => [
                    $r->id,
                    class_basename($r->class) . '::' . $r->method,
                    Task_Run_Model::$enums['origin_id'][(int) $r->origin_id]['label'],
                    $r->worker_pid ?? '-',
                    $r->started_at ?? '-',
                    $this->progress_text($r),
                    $r->status_text ?? '',
                ])->all()
            );
        }

        $queued = DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_PENDING)->count();
        $this->info("Queued: {$queued}");
    }

    private function progress_text(object $row): string
    {
        $parts = [];
        if ($row->progress_percent !== null) {
            $parts[] = rtrim(rtrim((string) $row->progress_percent, '0'), '.') . '%';
        }
        if ($row->progress_total !== null) {
            $parts[] = "{$row->progress_done} of {$row->progress_total}";
        }

        return $parts ? implode(', ', $parts) : '-';
    }

    /**
     * Every #[Schedule]: its cadence and its failure record. consecutive_failures counts runs
     * that failed since the last success.
     *
     * The whole set is listed, unpaginated: there is exactly one row per #[Schedule] in the
     * manifest, so this is bounded by the codebase, not by customer activity.
     */
    private function list_schedules(): void
    {
        $rows = DB::table('_task_schedules')
            ->get()
            // Sort by the DISPLAYED name (class basename), not the FQCN.
            ->sortBy(fn ($r) => class_basename($r->class) . '::' . $r->method)
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No schedules registered.');

            return;
        }

        $this->info('Schedules');

        $this->table(
            ['Task', 'Cron', 'Next run', 'Last success', 'Failures', 'Last error'],
            $rows->map(function ($r) {
                $failures = (int) $r->consecutive_failures;

                return [
                    class_basename($r->class) . '::' . $r->method,
                    $r->cron_expression,
                    $r->next_run_at,
                    $r->last_success_at ?? '-',
                    $failures > 0 ? $failures : '-',
                    $failures > 0 ? $this->describe_last_error($r) : '',
                ];
            })->all()
        );
    }

    /**
     * "<last_error_at>: <excerpt>" for a schedule with a live failure streak.
     */
    private function describe_last_error(object $row): string
    {
        $when = $row->last_error_at ?? '-';
        $text = trim(explode("\n", trim((string) $row->last_error))[0]);

        if ($text === '') {
            return $when;
        }

        if (mb_strlen($text) > self::ERROR_EXCERPT_LENGTH) {
            $text = mb_substr($text, 0, self::ERROR_EXCERPT_LENGTH - 3) . '...';
        }

        return $when . ': ' . $text;
    }
}
