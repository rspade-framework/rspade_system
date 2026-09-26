<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_Controller;

/**
 * The Tasks screen's endpoints: the running list, the history grid's filters, one row's
 * detail, Kill and Re-dispatch, the schedules list and Run now.
 *
 * Runs in the default per-test transaction; every row is planted inside it. No planted
 * row carries a worker_pid, so a kill signals no process at all. Re-dispatch goes through
 * Task::dispatch(), which under the test suite enqueues only - this class does not opt in
 * with Task::spawn_workers(true) - so the test asserts the new PENDING row, never
 * its execution. The schedule rows use the framework's own #[Schedule] declarations,
 * with their tracker rows deleted or planted inside the transaction.
 */
class Sys_Tasks_Controller_Test extends Rsx_Test_Abstract
{
    private const PROBE_CLASS = 'App\\RSpade\\Tests\\SysPanel\\Php\\Sys_Tasks_Probe_Service';

    public static function setup()
    {
        static::__acting_as_user(1);
    }

    /**
     * Insert a _tasks row: the probe task, overridden by $columns.
     */
    private static function __plant(array $columns = []): int
    {
        return (int) DB::table('_tasks')->insertGetId(array_merge([
            'class' => self::PROBE_CLASS,
            'method' => 'probe',
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode(['n' => 1]),
            'created_at' => now(),
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Tasks_Controller::$method(Request::create('/'), $params);
    }

    private static function __grid(array $params): array
    {
        $response = static::__endpoint('datagrid_fetch', array_merge(['per_page' => 100], $params));

        return array_column($response['records'], 'id');
    }

    private static function __assert_error(string $code, $response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);
    }

    /**
     * RP-TASKS-01 - running lists every RUNNING row and nothing else, datetimes as ISO
     * UTC, with the class basename and the cron flag.
     */
    public static function test_running_lists_running_rows()
    {
        $running = static::__plant([
            'status' => Task_Status::RUNNING,
            'started_at' => '2026-01-02 03:04:05.678',
            'last_heartbeat_at' => '2026-01-02 03:05:00.000',
            'worker_pid' => null,
        ]);
        $pending = static::__plant();

        $rows = static::__endpoint('running')['rows'];
        $ids = array_column($rows, 'id');

        static::__assert_true(in_array($running, $ids), 'the running row is listed');
        static::__assert_false(in_array($pending, $ids), 'a pending row is not');

        $row = $rows[array_search($running, $ids)];
        static::__assert_equals('Sys_Tasks_Probe_Service', $row['class_short'], 'class_short is the basename');
        static::__assert_equals('2026-01-02T03:04:05.678Z', $row['started_at'], 'started_at is ISO UTC');
        static::__assert_equals('2026-01-02T03:05:00.000Z', $row['last_heartbeat_at'], 'last_heartbeat_at is ISO UTC');
        static::__assert_false($row['is_cron'], 'a one-shot row is not a schedule');
    }

    /**
     * RP-TASKS-02 - The history grid's filters: status, class, since (finished within,
     * by completed_at - the Dashboard tile's definition), search on class or method.
     */
    public static function test_history_filters()
    {
        $failed_recent = static::__plant(['status' => Task_Status::FAILED, 'completed_at' => now()->subHours(2)]);
        $failed_old = static::__plant(['status' => Task_Status::FAILED, 'completed_at' => now()->subDays(2)]);
        $completed_recent = static::__plant(['status' => Task_Status::COMPLETED, 'completed_at' => now()->subHours(2)]);
        $other_class = static::__plant(['class' => 'Sys_Tasks_Other_Probe', 'method' => 'distinctive_method_xyz', 'status' => Task_Status::FAILED, 'completed_at' => now()->subHours(1)]);

        $failed = static::__grid(['status' => 'failed', 'class' => self::PROBE_CLASS]);
        static::__assert_true(in_array($failed_recent, $failed) && in_array($failed_old, $failed), 'status=failed keeps both failures');
        static::__assert_false(in_array($completed_recent, $failed), 'status=failed drops the completed row');
        static::__assert_false(in_array($other_class, $failed), 'class narrows to the one class');

        $failed_24h = static::__grid(['status' => 'failed', 'since' => '24h']);
        static::__assert_true(in_array($failed_recent, $failed_24h), 'since=24h keeps the recent failure');
        static::__assert_false(in_array($failed_old, $failed_24h), 'since=24h drops the two-day-old failure');

        $unknown = static::__grid(['status' => 'bogus', 'since' => 'forever', 'class' => self::PROBE_CLASS]);
        static::__assert_true(in_array($completed_recent, $unknown) && in_array($failed_old, $unknown), 'an unknown status or since value filters nothing');

        static::__assert_equals([$other_class], static::__grid(['filter' => 'distinctive_method']), 'search matches the method');
        static::__assert_equals([$other_class], static::__grid(['filter' => 'Other_Probe']), 'search matches the class');
        static::__assert_equals([], static::__grid(['filter' => '%']), 'a LIKE wildcard in the search is literal');

        $response = static::__endpoint('datagrid_fetch', ['filter' => 'distinctive_method']);
        $record = $response['records'][0];
        static::__assert_false(array_key_exists('params', $record) || array_key_exists('logs', $record), 'the list omits params and logs');
        static::__assert_true(str_ends_with($record['completed_at'], 'Z'), 'list datetimes are ISO UTC');

        $classes = array_column(static::__endpoint('class_options'), 'label', 'value');
        static::__assert_equals('Sys_Tasks_Probe_Service', $classes[self::PROBE_CLASS] ?? null, 'class_options labels each class by its basename');
    }

    /**
     * RP-TASKS-03 - detail returns the whole row decoded, with what may be done to it;
     * a missing id is not_found.
     */
    public static function test_detail_and_not_found()
    {
        $id = static::__plant([
            'status' => Task_Status::FAILED,
            'result' => json_encode(['ok' => false]),
            'logs' => "[a] one\n[b] two",
            'error' => 'boom',
            'completed_at' => now(),
        ]);

        $task = static::__endpoint('detail', ['id' => $id])['task'];

        static::__assert_equals(['n' => 1], $task['params'], 'params decoded');
        static::__assert_equals(['ok' => false], $task['result'], 'result decoded');
        static::__assert_equals(['[a] one', '[b] two'], $task['logs'], 'logs split into lines');
        static::__assert_equals('boom', $task['error'], 'error carried');
        static::__assert_false($task['can_kill'], 'a failed row cannot be killed');
        static::__assert_true($task['can_redispatch'], 'a failed one-shot row can be re-dispatched');

        $max = (int) DB::table('_tasks')->max('id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $max + 1000]), 'a missing id');
    }

    /**
     * RP-TASKS-04 - kill requires an explanation (a field error, the row untouched),
     * refuses a row that is not running, and settles a running one through Task_Killer.
     */
    public static function test_kill()
    {
        $running = static::__plant(['status' => Task_Status::RUNNING, 'started_at' => now(), 'worker_pid' => null]);

        $blank = static::__endpoint('kill', ['task_id' => $running, 'explanation' => '   ']);
        static::__assert_error(Ajax::ERROR_VALIDATION, $blank, 'a blank explanation');
        static::__assert_array_has_key('explanation', $blank->get_metadata(), 'the error targets the explanation field');
        static::__assert_equals(Task_Status::RUNNING, DB::table('_tasks')->where('id', $running)->value('status'), 'the row is untouched');

        $completed = static::__plant(['status' => Task_Status::COMPLETED, 'completed_at' => now()]);
        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('kill', ['task_id' => $completed, 'explanation' => 'x']), 'a completed row');

        $max = (int) DB::table('_tasks')->max('id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('kill', ['task_id' => $max + 1000, 'explanation' => 'x']), 'a missing row');

        $result = static::__endpoint('kill', ['task_id' => $running, 'explanation' => 'stuck on a probe']);
        static::__assert_equals(['id' => $running, 'outcome' => 'killed_no_process'], $result, 'the kill outcome');

        $row = DB::table('_tasks')->where('id', $running)->first();
        static::__assert_equals(Task_Status::KILLED, $row->status, 'the row is killed');
        static::__assert_equals('stuck on a probe', $row->status_reason, 'the explanation is recorded');
    }

    /**
     * RP-TASKS-05 - redispatch refuses a completed, running or schedule-tracker row, and
     * dispatches a failed one-shot as a NEW row with the same method, params and queue.
     */
    public static function test_redispatch()
    {
        $refused = [
            'completed' => static::__plant(['status' => Task_Status::COMPLETED, 'completed_at' => now()]),
            'running' => static::__plant(['status' => Task_Status::RUNNING, 'started_at' => now()]),
            'tracker' => static::__plant(['status' => Task_Status::FAILED, 'next_run_at' => now()->addHour()]),
        ];

        foreach ($refused as $label => $id) {
            static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('redispatch', ['id' => $id]), "a {$label} row");
        }

        $failed = static::__plant([
            'status' => Task_Status::FAILED,
            'queue' => 'reports',
            'params' => json_encode(['a' => 1, 'b' => 'two']),
            'completed_at' => now(),
        ]);

        $new_id = static::__endpoint('redispatch', ['id' => $failed])['id'];
        static::__assert_true($new_id > $failed, 'a new row is created');

        $row = DB::table('_tasks')->where('id', $new_id)->first();
        static::__assert_equals(self::PROBE_CLASS, $row->class, 'same class');
        static::__assert_equals('probe', $row->method, 'same method');
        static::__assert_equals('reports', $row->queue, 'same queue');
        static::__assert_equals(['a' => 1, 'b' => 'two'], json_decode($row->params, true), 'same params');
        static::__assert_equals(Task_Status::PENDING, $row->status, 'the new row is pending');
        static::__assert_equals(Task_Status::FAILED, DB::table('_tasks')->where('id', $failed)->value('status'), 'the old row is left as it was');
    }

    /**
     * The first framework #[Schedule] declaration whose concurrency mode is $mode
     * (null = unmanaged, 'exclusive', 'debounce').
     */
    private static function __declaration(?string $mode): array
    {
        foreach (Task::get_scheduled_tasks() as $declaration) {
            if (str_starts_with($declaration['class'], 'App\\RSpade\\')
                && Task_Concurrency::get_policy($declaration['class'], $declaration['method'])['mode'] === $mode) {
                return $declaration;
            }
        }

        shouldnt_happen('No framework #[Schedule] declaration with concurrency mode ' . var_export($mode, true));
    }

    private static function __schedule_row(array $declaration): array
    {
        foreach (static::__endpoint('schedules')['rows'] as $row) {
            if ($row['class'] === $declaration['class'] && $row['method'] === $declaration['method']) {
                return $row;
            }
        }

        shouldnt_happen("{$declaration['class']}::{$declaration['method']} is not listed");
    }

    /**
     * Every row of one identity is removed, so the test owns what exists for it.
     */
    private static function __clear_identity(array $declaration): void
    {
        DB::table('_tasks')->where('class', $declaration['class'])->where('method', $declaration['method'])->delete();
    }

    /**
     * RP-TASKS-06 - schedules lists every #[Schedule] declaration once, with its phrase and
     * its cron form; a declaration with no tracker carries tracker null; a tracker's
     * columns come back ISO UTC with a one-line capped error excerpt; failing turns on at
     * exactly rsx.tasks.failing_schedule_warn_after consecutive failures.
     */
    public static function test_schedules()
    {
        $response = static::__endpoint('schedules');
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after');
        static::__assert_equals($threshold, $response['failing_threshold'], 'the threshold is the config value');
        static::__assert_equals(count(Task::get_scheduled_tasks()), count($response['rows']), 'one row per declaration');

        $untracked = static::__declaration(null);
        static::__clear_identity($untracked);
        $row = static::__schedule_row($untracked);
        static::__assert_null($row['tracker'], 'a declaration with no tracker carries tracker null');
        static::__assert_false($row['failing'], 'an untracked declaration is not failing');
        static::__assert_equals($untracked['cron_expression'], $row['schedule'], 'schedule is the declared phrase');
        static::__assert_null($row['concurrency'], 'an unmanaged task has no concurrency mode');

        $tracked = static::__declaration('exclusive');
        static::__clear_identity($tracked);
        $tracker_id = static::__plant([
            'class' => $tracked['class'],
            'method' => $tracked['method'],
            'queue' => $tracked['queue'],
            'next_run_at' => '2026-03-04 05:06:00.000',
            'completed_at' => '2026-03-03 05:06:07.000',
            'cron_expression' => $tracked['cron_expression'],
            'consecutive_failures' => $threshold - 1,
            'last_error_at' => '2026-03-04 01:02:03.000',
            'error' => str_repeat('x', 200) . "\nsecond line",
        ]);

        $row = static::__schedule_row($tracked);
        static::__assert_equals('exclusive', $row['concurrency'], 'the concurrency mode');
        static::__assert_true((bool) preg_match('/^[\d*\/,\- ]+$/', $row['cron']), 'cron is a standard cron expression');
        static::__assert_equals($tracker_id, $row['tracker']['id'], 'the tracker is the planted row');
        static::__assert_equals('2026-03-04T05:06:00.000Z', $row['tracker']['next_run_at'], 'next_run_at is ISO UTC');
        static::__assert_equals('2026-03-03T05:06:07.000Z', $row['tracker']['completed_at'], 'completed_at (last success) is ISO UTC');
        static::__assert_equals('2026-03-04T01:02:03.000Z', $row['tracker']['last_error_at'], 'last_error_at is ISO UTC');
        static::__assert_equals(str_repeat('x', 117) . '...', $row['tracker']['error_excerpt'], 'the excerpt is the first line, capped');
        static::__assert_false($row['failing'], 'one failure below the threshold is not failing');

        DB::table('_tasks')->where('id', $tracker_id)->update(['consecutive_failures' => $threshold]);
        static::__assert_true(static::__schedule_row($tracked)['failing'], 'the threshold itself is failing');
    }

    /**
     * RP-TASKS-07 - run_schedule_now dispatches a declared schedule as a new one-shot row
     * (declared queue, no params) and leaves the tracker's next_run_at alone; a managed
     * task's second run coalesces onto the first; an undeclared class/method is not_found.
     */
    public static function test_run_schedule_now()
    {
        $declaration = static::__declaration(null);
        static::__clear_identity($declaration);
        $tracker_id = static::__plant([
            'class' => $declaration['class'],
            'method' => $declaration['method'],
            'queue' => $declaration['queue'],
            'params' => json_encode([]),
            'next_run_at' => '2030-01-01 03:00:00.000',
            'cron_expression' => $declaration['cron_expression'],
        ]);

        $result = static::__endpoint('run_schedule_now', ['class' => $declaration['class'], 'method' => $declaration['method']]);
        static::__assert_equals('dispatched', $result['outcome'], 'an unmanaged schedule is dispatched');
        static::__assert_null($result['concurrency'], 'no concurrency mode');
        static::__assert_true($result['id'] > $tracker_id, 'a new row');

        $row = DB::table('_tasks')->where('id', $result['id'])->first();
        static::__assert_equals(Task_Status::PENDING, $row->status, 'the new row is pending');
        static::__assert_equals($declaration['queue'], $row->queue, 'on the declared queue');
        static::__assert_equals([], json_decode($row->params, true), 'with no params');
        static::__assert_null($row->next_run_at, 'the new row is a one-shot');

        $tracker = DB::table('_tasks')->where('id', $tracker_id)->first();
        static::__assert_equals('2030-01-01 03:00:00.000', (string) $tracker->next_run_at, "the tracker's next_run_at is untouched");
        static::__assert_equals(Task_Status::PENDING, $tracker->status, 'the tracker is untouched');

        $managed = static::__declaration('exclusive');
        static::__clear_identity($managed);
        $first = static::__endpoint('run_schedule_now', ['class' => $managed['class'], 'method' => $managed['method']]);
        $second = static::__endpoint('run_schedule_now', ['class' => $managed['class'], 'method' => $managed['method']]);
        static::__assert_equals(['dispatched', 'exclusive'], [$first['outcome'], $first['concurrency']], 'the first run of an exclusive task is dispatched');
        static::__assert_equals(['coalesced', $first['id']], [$second['outcome'], $second['id']], 'the second coalesces onto the pending first');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('run_schedule_now', ['class' => self::PROBE_CLASS, 'method' => 'probe']), 'a task with no #[Schedule]');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('run_schedule_now', []), 'no class or method');
    }
}
