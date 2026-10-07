<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_Controller;

/**
 * The Tasks screens' endpoints: the grid's order and filters, one
 * run's detail with its attachments, the lifecycle actions, the schedules list with Run now,
 * and the worker pools.
 *
 * Runs in the default per-test transaction; every run is planted inside it with
 * Task_Runner::insert_row(). No planted run carries a live worker, so nothing is signalled:
 * a force kill only records its request. Task::dispatch() enqueues only under the suite.
 */
class Sys_Tasks_Controller_Test extends Rsx_Test_Abstract
{
    private const PROBE_CLASS = 'App\\RSpade\\Tests\\SysPanel\\Php\\Sys_Tasks_Probe_Service';

    public static function setup()
    {
        static::__acting_as_user(1);
        DB::table('_tasks')->delete();
    }

    /** A probe run in $status_id, overridden by $fields. */
    private static function __plant(int $status_id, array $fields = []): int
    {
        return Task_Runner::insert_row(self::PROBE_CLASS, 'probe', ['n' => 1], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(['status_id' => $status_id], $fields));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Tasks_Controller::$method(Request::create('/'), $params);
    }

    private static function __assert_error(string $code, $response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);
    }

    /** RP-TASKS-01 - the grid lists running runs (oldest start first), then queued runs (earliest due first), then finished runs newest first; each row's actions match its state */
    public static function test_grid_order()
    {
        $finished_old = static::__plant(Task_Run_Model::STATUS_COMPLETED, ['completed_at' => '2026-01-01 00:00:00.000']);
        $finished_new = static::__plant(Task_Run_Model::STATUS_FAILED, ['completed_at' => '2026-02-01 00:00:00.000']);
        $queued_late = static::__plant(Task_Run_Model::STATUS_PENDING, ['scheduled_for' => '2030-01-02 00:00:00.000']);
        $queued_early = static::__plant(Task_Run_Model::STATUS_PENDING, ['scheduled_for' => '2030-01-01 00:00:00.000']);
        $running_new = static::__plant(Task_Run_Model::STATUS_RUNNING, ['started_at' => '2026-01-02 00:00:00.000']);
        $running_old = static::__plant(Task_Run_Model::STATUS_RUNNING, ['started_at' => '2026-01-01 00:00:00.000']);

        $response = static::__endpoint('tasks_fetch', ['per_page' => 100]);

        static::__assert_equals([$running_old, $running_new, $queued_early, $queued_late, $finished_new, $finished_old], array_column($response['records'], 'id'));
        static::__assert_equals(6, $response['total']);
        static::__assert_equals(['stop', 'force_stop', 'force_kill'], $response['records'][0]['actions'], 'a running run offers the three stops');
        static::__assert_equals(['stop', 'cancel'], $response['records'][2]['actions'], 'a queued run offers stop and cancel');
        static::__assert_equals(['rerun'], $response['records'][4]['actions'], 'a finished run offers run again');
    }

    /** RP-TASKS-02 - the grid's filters: scope (active / completed - every finished status), status, origin, class and search */
    public static function test_grid_filters()
    {
        $running = static::__plant(Task_Run_Model::STATUS_RUNNING, ['started_at' => '2026-01-01 00:00:00.000']);
        $pending = static::__plant(Task_Run_Model::STATUS_PENDING, ['scheduled_for' => '2030-01-01 00:00:00.000']);
        $failed = static::__plant(Task_Run_Model::STATUS_FAILED, ['completed_at' => '2026-03-01 00:00:00.000']);
        $stopped = static::__plant(Task_Run_Model::STATUS_STOPPED, ['completed_at' => '2026-02-01 00:00:00.000']);
        $inline = static::__plant(Task_Run_Model::STATUS_COMPLETED, ['completed_at' => '2026-01-01 00:00:00.000', 'origin_id' => Task_Run_Model::ORIGIN_INLINE]);

        $ids = fn (array $params) => array_column(static::__endpoint('tasks_fetch', $params + ['per_page' => 100])['records'], 'id');

        static::__assert_equals([$running, $pending, $failed, $stopped, $inline], $ids([]), 'every run, active on top');
        static::__assert_equals([$running, $pending], $ids(['scope' => 'active']));
        static::__assert_equals([$failed, $stopped, $inline], $ids(['scope' => 'completed']), 'completed covers failed and stopped runs too');
        static::__assert_equals([$stopped], $ids(['status' => Task_Run_Model::STATUS_STOPPED]));
        static::__assert_equals([$inline], $ids(['origin' => Task_Run_Model::ORIGIN_INLINE]));
        static::__assert_equals([$running, $pending, $failed, $stopped, $inline], $ids(['class' => self::PROBE_CLASS, 'filter' => 'probe']));
        static::__assert_equals([], $ids(['filter' => 'no_such_task_%']), 'search wildcards are literal');
        static::__assert_equals('Sys_Tasks_Probe_Service', static::__endpoint('class_options')[0]['label']);
    }

    /** RP-TASKS-03 - detail carries params, worker evidence (only while running) and attachments; a missing id is not_found */
    public static function test_detail()
    {
        $id = static::__plant(Task_Run_Model::STATUS_COMPLETED, ['completed_at' => now(), 'worker_pid' => 4242, 'worker_host' => 'some-host']);
        Task_Instance::find($id)->attach_bytes('report.txt', "hello\n", 'report.txt', 'text/plain');

        $task = static::__endpoint('detail', ['id' => $id])['task'];
        static::__assert_equals(['n' => 1], $task['params']);
        static::__assert_equals('Sys_Tasks_Probe_Service::probe', $task['name']);
        static::__assert_equals(['rerun'], $task['actions']);
        static::__assert_null($task['worker_state'], 'no worker state off a running run');
        static::__assert_null($task['worker_pid'], 'no worker shown once the run settled');
        static::__assert_null($task['worker_host'], 'no worker host shown once the run settled');
        static::__assert_equals('report.txt', $task['attachments'][0]['name']);
        static::__assert_contains("/_sys/tasks/{$id}/attachments/report.txt", $task['attachments'][0]['url']);

        $response = static::__endpoint('attachment', ['id' => $id, 'name' => 'report.txt']);
        static::__assert_equals("hello\n", file_get_contents($response->getFile()->getPathname()));

        static::__assert_error('not_found', static::__endpoint('detail', ['id' => $id + 1000]), 'missing run');
    }

    /** RP-TASKS-04 - act performs each lifecycle action, records an operator line, and refuses one that no longer applies */
    public static function test_act()
    {
        $running = static::__plant(Task_Run_Model::STATUS_RUNNING, ['worker_pid' => 999999999, 'worker_host' => 'elsewhere-host']);
        $pending = static::__plant(Task_Run_Model::STATUS_PENDING, ['scheduled_for' => '2030-01-01 00:00:00.000']);
        $finished = static::__plant(Task_Run_Model::STATUS_FAILED, ['completed_at' => now()]);

        $stopped = static::__endpoint('act', ['task_id' => $running, 'task_action' => 'stop', 'explanation' => 'enough']);
        static::__assert_not_null($stopped['task']['stop_requested_at']);
        static::__assert_contains('Graceful stop requested', implode("\n", array_column(Task_Run_Model::find($running)->output_after(null, ['operator']), 'line')));

        $killed = static::__endpoint('act', ['task_id' => $running, 'task_action' => 'force_kill']);
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, $killed['task']['status_id'], 'a force kill is carried out by a kill worker, not here');
        static::__assert_equals(1, DB::table('_task_kill_requests')->where('task_id', $running)->count());

        $cancelled = static::__endpoint('act', ['task_id' => $pending, 'task_action' => 'cancel']);
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, $cancelled['task']['status_id']);
        static::__assert_error('validation', static::__endpoint('act', ['task_id' => $pending, 'task_action' => 'cancel']), 'a second cancel no longer applies');

        $rerun = static::__endpoint('act', ['task_id' => $finished, 'task_action' => 'rerun']);
        static::__assert_greater_than($finished, $rerun['rerun_id']);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) Task_Run_Model::find($rerun['rerun_id'])->status_id);

        static::__assert_error('validation', static::__endpoint('act', ['task_id' => $running, 'task_action' => 'rerun']), 'a live run cannot be rerun');
        static::__assert_error('validation', static::__endpoint('act', ['task_id' => $running, 'task_action' => 'explode']), 'unknown action');
        static::__assert_error('validation', static::__endpoint('act', ['task_id' => $running, 'task_action' => 'force_stop', 'grace_seconds' => 'soon']), 'a non-numeric grace');
        static::__assert_error('not_found', static::__endpoint('act', ['task_id' => $finished + 1000, 'task_action' => 'stop']), 'missing run');
    }

    /** RP-TASKS-05 - schedules lists every declaration with its registration and statistics; Run now dispatches and coalesces */
    public static function test_schedules_and_run_now()
    {
        DB::table('_task_schedules')->delete();
        $declarations = Task::get_scheduled_tasks();

        $rows = static::__endpoint('schedules')['rows'];
        static::__assert_equals(count($declarations), count($rows), 'one row per declaration');
        static::__assert_null($rows[0]['registered'], 'nothing registered yet');

        $exclusive = null;
        foreach ($declarations as $declaration) {
            if ($declaration['class'] === 'App\\RSpade\\Core\\Mail\\Mail_Queue_Service' && $declaration['method'] === 'send_pending_queue') {
                $exclusive = $declaration;
            }
        }
        static::__assert_not_null($exclusive);

        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after');
        DB::table('_task_schedules')->insert([
            'class' => $exclusive['class'], 'method' => $exclusive['method'], 'cron_expression' => $exclusive['cron_expression'],
            'next_run_at' => '2030-01-01 00:00:00.000', 'consecutive_failures' => $threshold, 'last_error' => "first line\nsecond line",
            'last_error_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = collect(static::__endpoint('schedules')['rows'])->first(fn ($r) => $r['class'] === $exclusive['class'] && $r['method'] === $exclusive['method']);
        static::__assert_true($row['failing']);
        static::__assert_equals('first line', $row['registered']['error_excerpt']);
        static::__assert_equals('exclusive', $row['concurrency']);

        $first = static::__endpoint('run_schedule_now', ['class' => $exclusive['class'], 'method' => $exclusive['method']]);
        $second = static::__endpoint('run_schedule_now', ['class' => $exclusive['class'], 'method' => $exclusive['method']]);
        static::__assert_equals('dispatched', $first['outcome']);
        static::__assert_equals('coalesced', $second['outcome']);
        static::__assert_equals($first['id'], $second['id']);
        static::__assert_equals('2030-01-01 00:00:00', substr((string) DB::table('_task_schedules')->where('class', $exclusive['class'])->value('next_run_at'), 0, 19), 'the schedule is untouched');

        static::__assert_error('not_found', static::__endpoint('run_schedule_now', ['class' => self::PROBE_CLASS, 'method' => 'probe']), 'undeclared');
    }

    /** RP-TASKS-06 - workers reports every pool against its cap, the running runs, and the next queued runs with their total */
    public static function test_workers()
    {
        $running = static::__plant(Task_Run_Model::STATUS_RUNNING, ['worker_pid' => 999999999, 'worker_host' => 'elsewhere-host', 'pool_id' => Task_Run_Model::POOL_ON_DEMAND]);
        for ($i = 0; $i < 12; $i++) {
            static::__plant(Task_Run_Model::STATUS_PENDING, ['scheduled_for' => '2030-01-01 00:00:00.000']);
        }

        $workers = static::__endpoint('workers');

        static::__assert_equals(['on_demand', 'scheduled', 'kill'], array_column($workers['pools'], 'pool'));
        static::__assert_equals((int) config('rsx.tasks.pools.kill.max_workers'), $workers['pools'][2]['max_workers']);
        static::__assert_equals([$running], array_column($workers['running'], 'id'));
        static::__assert_equals('elsewhere', $workers['running'][0]['worker_state']);
        static::__assert_equals(_Sys_Tasks_Controller::UPCOMING_LIMIT, count($workers['queued']));
        static::__assert_equals(12, $workers['queued_total']);
    }
}
