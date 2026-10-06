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
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_UNKNOWN, $row['worker_state'], 'a row with no pid has an unknown worker');
        static::__assert_false(array_key_exists('worker_id', $row), 'worker_id stays on the server');
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
     * RP-TASKS-03 - detail returns the row decoded, with what may be done to it, and
     * without its logs (the console reads them through logs()) or updated_at (which moves
     * with every log line); a missing id is not_found.
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
        static::__assert_false(array_key_exists('logs', $task), 'the logs are not part of the detail');
        static::__assert_false(array_key_exists('updated_at', $task), 'nor is updated_at');
        static::__assert_null($task['worker_state'], 'a row that is not running has no worker state');
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

        $completed = static::__plant(['status' => Task_Status::COMPLETED, 'completed_at' => now()]);
        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('kill', ['task_id' => $completed, 'explanation' => 'x']), 'a completed row');

        $max = (int) DB::table('_tasks')->max('id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('kill', ['task_id' => $max + 1000, 'explanation' => 'x']), 'a missing row');

        $result = static::__endpoint('kill', ['task_id' => $running, 'explanation' => 'stuck on a probe']);
        static::__assert_equals(['id' => $running, 'outcome' => 'killed_no_process'], $result, 'the kill outcome');

        $row = DB::table('_tasks')->where('id', $running)->first();
        static::__assert_equals(Task_Status::KILLED, $row->status, 'the row is killed');
        static::__assert_equals('stuck on a probe', $row->status_reason, 'the explanation is recorded');

        $unexplained = static::__plant(['status' => Task_Status::RUNNING, 'started_at' => now(), 'worker_pid' => null]);
        $result = static::__endpoint('kill', ['task_id' => $unexplained, 'explanation' => '   ']);
        static::__assert_equals(['id' => $unexplained, 'outcome' => 'killed_no_process'], $result, 'a blank explanation still kills');
        static::__assert_equals(
            _Sys_Tasks_Controller::KILL_DEFAULT_REASON,
            DB::table('_tasks')->where('id', $unexplained)->value('status_reason'),
            'and records the default reason'
        );
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

        // The last run is the later of the last success and the last failure.
        static::__assert_equals('2026-03-04T01:02:03.000Z', $row['tracker']['last_run_at'], 'a failure after the last success is the last run');
        static::__assert_equals('failed', $row['tracker']['last_outcome'], 'a plain failure reads failed');

        DB::table('_tasks')->where('id', $tracker_id)->update(['status_reason' => 'abandoned (recycled): worker 7 gone']);
        static::__assert_equals('abandoned', static::__schedule_row($tracked)['tracker']['last_outcome'], 'the reaper\'s prefix reads abandoned');

        DB::table('_tasks')->where('id', $tracker_id)->update(['completed_at' => '2026-03-04 02:00:00.000', 'status_reason' => null]);
        $after_success = static::__schedule_row($tracked)['tracker'];
        static::__assert_equals('2026-03-04T02:00:00.000Z', $after_success['last_run_at'], 'a later success is the last run');
        static::__assert_equals('completed', $after_success['last_outcome'], 'and reads completed');

        DB::table('_tasks')->where('id', $tracker_id)->update(['completed_at' => null, 'last_error_at' => null]);
        $never = static::__schedule_row($tracked)['tracker'];
        static::__assert_null($never['last_run_at'], 'a tracker that never ran has no last run');
        static::__assert_null($never['last_outcome'], 'and no outcome');
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

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    private const DEAD_PID = 2147480000;

    /**
     * RP-TASKS-09 - worker_state is evidence about the claiming process, never the row's
     * age: a pool worker's pid that is not a live worker is gone, a row from another host
     * is elsewhere, a row run outside the pool (no worker_id) is judged by its pid alone.
     */
    public static function test_worker_state()
    {
        $row = fn (array $columns) => (object) array_merge(['worker_pid' => null, 'worker_id' => null, 'worker_host' => null], $columns);

        static::__assert_equals(_Sys_Tasks_Controller::WORKER_GONE, _Sys_Tasks_Controller::worker_state($row(['worker_pid' => self::DEAD_PID, 'worker_id' => 3])), 'a dead pool worker is gone');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_GONE, _Sys_Tasks_Controller::worker_state($row(['worker_pid' => getmypid(), 'worker_id' => 3])), 'a live process that is not a task worker is not the claiming worker');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_ALIVE, _Sys_Tasks_Controller::worker_state($row(['worker_pid' => getmypid()])), 'an inline run is judged by its pid');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_GONE, _Sys_Tasks_Controller::worker_state($row(['worker_pid' => self::DEAD_PID])), 'an inline run whose pid is gone');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_ELSEWHERE, _Sys_Tasks_Controller::worker_state($row(['worker_pid' => self::DEAD_PID, 'worker_id' => 3, 'worker_host' => 'another-host.invalid'])), 'another host is never judged from here');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_UNKNOWN, _Sys_Tasks_Controller::worker_state($row([])), 'no pid recorded');

        $id = static::__plant(['status' => Task_Status::RUNNING, 'started_at' => now(), 'worker_pid' => self::DEAD_PID, 'worker_id' => 3]);
        $listed = array_column(static::__endpoint('running')['rows'], 'worker_state', 'id');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_GONE, $listed[$id] ?? null, 'the Running list carries it');
        static::__assert_equals(_Sys_Tasks_Controller::WORKER_GONE, static::__endpoint('detail', ['id' => $id])['task']['worker_state'], 'and so does the detail');
    }

    /**
     * RP-TASKS-10 - logs() hands the console the lines after its offset; a new run of the
     * row (another started_at) or a log shorter than the offset answers the whole log with
     * restart; a missing id is not_found.
     */
    public static function test_logs_tail()
    {
        $id = static::__plant([
            'status' => Task_Status::RUNNING,
            'started_at' => '2026-01-02 03:04:05.000',
            'last_heartbeat_at' => '2026-01-02 03:04:06.000',
            'logs' => "one\ntwo\nthree",
        ]);

        $first = static::__endpoint('logs', ['id' => $id]);
        static::__assert_equals(['one', 'two', 'three'], $first['lines'], 'from 0, every line');
        static::__assert_equals(3, $first['offset'], 'the offset is the line count');
        static::__assert_equals('2026-01-02T03:04:05.000Z', $first['run'], 'the run is the row started_at');
        static::__assert_false($first['restart'], 'a first read is not a restart');
        static::__assert_equals('running', $first['status']);
        static::__assert_equals('2026-01-02T03:04:06.000Z', $first['last_heartbeat_at']);

        DB::table('_tasks')->where('id', $id)->update(['logs' => "one\ntwo\nthree\nfour"]);
        $next = static::__endpoint('logs', ['id' => $id, 'offset' => 3, 'run' => $first['run']]);
        static::__assert_equals(['four'], $next['lines'], 'only the new line');
        static::__assert_false($next['restart']);

        $same = static::__endpoint('logs', ['id' => $id, 'offset' => 4, 'run' => $first['run']]);
        static::__assert_equals([], $same['lines'], 'asking again repeats nothing');

        DB::table('_tasks')->where('id', $id)->update(['started_at' => '2026-01-02 04:00:00.000', 'logs' => "next run\na\nb\nc\nd"]);
        $new_run = static::__endpoint('logs', ['id' => $id, 'offset' => 4, 'run' => $first['run']]);
        static::__assert_true($new_run['restart'], 'another run restarts the console');
        static::__assert_equals(['next run', 'a', 'b', 'c', 'd'], $new_run['lines'], 'with the whole new log');

        DB::table('_tasks')->where('id', $id)->update(['logs' => 'short']);
        $shorter = static::__endpoint('logs', ['id' => $id, 'offset' => 5, 'run' => $new_run['run']]);
        static::__assert_true($shorter['restart'], 'a log shorter than the offset restarts the console');
        static::__assert_equals(['short'], $shorter['lines']);

        $max = (int) DB::table('_tasks')->max('id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('logs', ['id' => $max + 1000]), 'a missing id');
    }

    /**
     * RP-TASKS-11 - queues() counts each label's pending and running work beside the pool,
     * and the history grid filters by queue.
     */
    public static function test_queues_and_queue_filter()
    {
        $queue = 'rp_tasks_queue_' . uniqid();
        $other = $queue . '_other';

        $running = static::__plant(['queue' => $queue, 'status' => Task_Status::RUNNING, 'started_at' => now(), 'worker_pid' => self::DEAD_PID, 'worker_id' => 3]);
        static::__plant(['queue' => $queue, 'status' => Task_Status::RUNNING, 'started_at' => now(), 'worker_pid' => null]);
        static::__plant(['queue' => $queue, 'created_at' => '2026-01-02 03:04:05.000']);
        static::__plant(['queue' => $queue, 'scheduled_for' => now()->subMinute()]);
        static::__plant(['queue' => $queue, 'scheduled_for' => now()->addHour()]);
        static::__plant(['queue' => $queue, 'next_run_at' => now()->subMinute(), 'cron_expression' => '* * * * *']);
        static::__plant(['queue' => $queue, 'next_run_at' => now()->addHour(), 'cron_expression' => '0 * * * *']);
        static::__plant(['queue' => $queue, 'status' => Task_Status::COMPLETED, 'completed_at' => now()]);
        $elsewhere = static::__plant(['queue' => $other]);

        $response = static::__endpoint('queues');
        $by_queue = array_column($response['queues'], null, 'queue');

        static::__assert_equals([
            'queue' => $queue,
            'running' => 2,
            'stale_running' => 1,
            'pending_due' => 2,
            'pending_later' => 1,
            'schedules' => 2,
            'schedules_due' => 1,
            'oldest_due_at' => '2026-01-02T03:04:05.000Z',
        ], $by_queue[$queue] ?? null, 'the counts for the queue, a completed row left out');
        static::__assert_equals(1, $by_queue[$other]['pending_due'] ?? null, 'each label is its own row');

        static::__assert_true(is_int($response['pool']['max_workers']) && $response['pool']['max_workers'] >= 1, 'the pool cap');
        static::__assert_true(is_int($response['pool']['members']), 'the live worker count');

        $filtered = static::__grid(['queue' => $queue, 'status' => 'running']);
        static::__assert_true(in_array($running, $filtered), 'the grid filters by queue');
        static::__assert_false(in_array($elsewhere, static::__grid(['queue' => $queue])), 'and leaves other queues out');

        $options = array_column(static::__endpoint('queue_options'), 'label', 'value');
        static::__assert_equals($queue, $options[$queue] ?? null, 'queue_options lists every label in use');
    }
}
