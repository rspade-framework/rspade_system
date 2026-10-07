<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Test_Echo_Service;

/**
 * Task::dispatch() writes a PENDING run row; Task::internal() runs a task in this process and
 * returns its settled row.
 *
 * dispatch() commits rows to `_tasks`, so this class sets $requires_db_reset = true and
 * $use_database_transactions = false (the runner gives it a clean migrated baseline and
 * restores it before the next class). Under the suite dispatch() spawns no worker, so every
 * dispatched row is still pending when it is read back.
 */
class Task_Dispatch_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Session::logout();
    }

    private static function __row(int $id): Task_Run_Model
    {
        return Task_Run_Model::find($id);
    }

    // -------------------------------------------------------------------------
    // Task::dispatch() - validation
    // -------------------------------------------------------------------------

    public static function test_dispatch_throws_for_unknown_service()
    {
        static::__assert_throws(\Exception::class, function () {
            Task::dispatch('NonExistent_Service_Xyz_999', 'some_task', []);
        }, 'Service class not found');
    }

    public static function test_dispatch_throws_for_method_without_task_attribute()
    {
        // pre_task() exists on Rsx_Service_Abstract but lacks #[Task]
        static::__assert_throws(\Exception::class, function () {
            Task::dispatch('Session_Cleanup_Service', 'pre_task', []);
        });
    }

    public static function test_dispatch_refuses_an_unknown_option()
    {
        $before = DB::table('_tasks')->count();

        static::__assert_throws(\Exception::class, function () {
            Task::dispatch('Test_Echo_Service', 'echo_params', [], ['queue' => 'reports']);
        }, 'options are scheduled_for and timeout; unknown: queue');

        static::__assert_equals($before, DB::table('_tasks')->count(), 'nothing was enqueued');
    }

    public static function test_dispatch_refuses_scheduled_for_on_a_managed_task()
    {
        $before = DB::table('_tasks')->count();

        static::__assert_throws(\Exception::class, function () {
            Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task', [], ['scheduled_for' => '2030-01-01 00:00:00']);
        }, 'times its own runs; scheduled_for cannot be given');

        static::__assert_equals($before, DB::table('_tasks')->count(), 'nothing was enqueued');
    }

    // -------------------------------------------------------------------------
    // Task::dispatch() - the pending row
    // -------------------------------------------------------------------------

    public static function test_dispatch_returns_the_id_of_one_new_row()
    {
        $before = DB::table('_tasks')->count();
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['key' => 'value']);

        static::__assert_true(is_int($id) && $id > 0, 'an integer id');
        static::__assert_equals($before + 1, DB::table('_tasks')->count(), 'exactly one row');
        static::__assert_not_null(static::__row($id));
    }

    public static function test_dispatch_writes_a_pending_dispatched_row()
    {
        $params = ['a' => 1, 'b' => 'two'];
        $before = time();
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', $params);
        $row = static::__row($id);

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id);
        static::__assert_equals(Task_Run_Model::ORIGIN_DISPATCHED, (int) $row->origin_id);
        static::__assert_equals(Test_Echo_Service::class, $row->class, 'the resolved fully-qualified class');
        static::__assert_equals('echo_params', $row->method);
        static::__assert_equals($params, $row->params);
        static::__assert_equals(Task_Concurrency::params_hash($params), $row->params_hash);
        static::__assert_true(strtotime($row->scheduled_for) <= time() && strtotime($row->scheduled_for) >= $before - 1, 'due now');
        static::__assert_equals((int) config('rsx.tasks.default_timeout'), (int) $row->timeout, 'the configured default cap');
        static::__assert_null($row->started_at);
        static::__assert_null($row->completed_at);
        static::__assert_null($row->stop_requested_at);
        static::__assert_null($row->pool_id, 'no pool until a worker claims it');
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->schedule_id);
        static::__assert_equals(0, (int) $row->abandon_count);
    }

    public static function test_dispatch_options_set_the_timeout_and_a_future_start()
    {
        $later = date('Y-m-d H:i:s', time() + 3600);
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', [], ['timeout' => 45, 'scheduled_for' => $later]);
        $row = static::__row($id);

        static::__assert_equals(45, (int) $row->timeout);
        static::__assert_equals(strtotime($later), strtotime($row->scheduled_for), 'a future scheduled_for defers the run');
    }

    public static function test_dispatch_records_who_dispatched_it()
    {
        Session::logout();
        $anonymous = static::__row(Task::dispatch('Test_Echo_Service', 'echo_params'));
        static::__assert_null($anonymous->dispatched_by_id, 'nobody signed in: no dispatcher');
        static::__assert_null($anonymous->dispatched_by_type);

        static::__acting_as_user(1);
        $signed_in = static::__row(Task::dispatch('Test_Echo_Service', 'echo_params'));
        $actor = \App\RSpade\Core\Database\Models\Rsx_Model_Abstract::_resolve_context_actor();

        static::__assert_not_null($actor, 'fixture: a signed-in identity resolves');
        $stored = DB::table('_tasks')->where('id', $signed_in->id)->first();
        static::__assert_equals((int) $actor['id'], (int) $stored->dispatched_by_id, 'the dispatcher id');
        static::__assert_equals(Type_Ref_Registry::class_to_id($actor['type']), (int) $stored->dispatched_by_type, 'the dispatcher type, as a type-ref id');
        static::__assert_equals(1, (int) $signed_in->site_id, 'and the site it was dispatched for');
    }

    // -------------------------------------------------------------------------
    // Task::internal() - a recorded run in this process
    // -------------------------------------------------------------------------

    public static function test_internal_returns_the_settled_inline_row()
    {
        $run = Task::internal('Test_Echo_Service', 'echo_params', ['key' => 'value']);

        static::__assert_instance_of(Task_Run_Model::class, $run);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(Task_Run_Model::ORIGIN_INLINE, (int) $run->origin_id);
        static::__assert_equals(0, (int) $run->return_code);
        static::__assert_null($run->error);
        static::__assert_not_null($run->started_at);
        static::__assert_not_null($run->completed_at);
        static::__assert_equals(getmypid(), (int) $run->worker_pid, 'run by this process');
        static::__assert_null($run->worker_id, 'which is no pool member');
        static::__assert_equals(['echo' => ['key' => 'value']], $run->state(), 'its reports are on the run');
    }

    public static function test_internal_settles_a_throwing_task_failed_then_rethrows()
    {
        $before = (int) DB::table('_tasks')->max('id');

        static::__assert_throws(\Exception::class, function () {
            Task::internal('Test_Echo_Service', 'always_fail', []);
        }, 'deliberate test failure');

        $run = Task_Run_Model::where('id', '>', $before)->where('method', 'always_fail')->first();
        static::__assert_not_null($run, 'the run was recorded');
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'and settled before the rethrow');
        static::__assert_equals(1, (int) $run->return_code);
        static::__assert_equals('Exception: deliberate test failure', $run->error);
    }

    public static function test_internal_returns_a_failure_code_without_throwing()
    {
        $run = Task::internal('Test_Echo_Service', 'exit_with', ['code' => 4]);

        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id);
        static::__assert_equals(4, (int) $run->return_code);
    }
}
