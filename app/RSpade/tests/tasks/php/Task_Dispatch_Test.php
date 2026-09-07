<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Task::dispatch(), Task::status(), and Task::internal().
 *
 * dispatch()/status() commit rows to the `_tasks` table, so this class sets
 * $requires_db_reset = true and $use_database_transactions = false (the runner
 * gives it a clean migrated baseline and restores it before the next class).
 * Task::internal() executes synchronously in-process.
 */
class Task_Dispatch_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // Task::dispatch() - validation checks (do not require the DB table)
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

    // -------------------------------------------------------------------------
    // Task::dispatch() - persists a pending task row in the _tasks table
    // -------------------------------------------------------------------------

    public static function test_dispatch_returns_integer_id()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['key' => 'value']);
        static::__assert_true(is_int($id));
        static::__assert_greater_than(0, $id);
    }

    public static function test_dispatch_creates_row_in_tasks_table()
    {
        $before = DB::table('_tasks')->count();
        Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_equals($before + 1, DB::table('_tasks')->count());
    }

    public static function test_dispatch_stores_pending_status()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::PENDING, $row->status);
    }

    public static function test_dispatch_stores_class_and_method()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        $row = DB::table('_tasks')->where('id', $id)->first();
        // dispatch() stores the resolved fully-qualified class name.
        static::__assert_true(str_ends_with($row->class, 'Test_Echo_Service'), 'class should resolve to Test_Echo_Service, got ' . $row->class);
        static::__assert_equals('echo_params', $row->method);
    }

    public static function test_dispatch_stores_params_as_json()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['a' => 1, 'b' => 'two']);
        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(['a' => 1, 'b' => 'two'], json_decode($row->params, true));
    }

    public static function test_dispatch_uses_default_queue()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals('default', $row->queue);
    }

    public static function test_dispatch_respects_custom_queue_option()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', [], ['queue' => 'reports']);
        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals('reports', $row->queue);
    }

    // -------------------------------------------------------------------------
    // Task::status() - reads back a dispatched task
    // -------------------------------------------------------------------------

    public static function test_status_returns_array_for_known_id()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_true(is_array(Task::status($id)));
    }

    public static function test_status_returns_null_for_unknown_id()
    {
        static::__assert_null(Task::status(999999999));
    }

    public static function test_status_contains_expected_keys()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        $status = Task::status($id);
        foreach (['id', 'class', 'method', 'queue', 'status', 'params', 'result', 'logs', 'error'] as $key) {
            static::__assert_array_has_key($key, $status);
        }
    }

    public static function test_status_status_field_is_pending_after_dispatch()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_equals(Task_Status::PENDING, Task::status($id)['status']);
    }

    public static function test_status_id_matches_dispatched_task()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_equals($id, Task::status($id)['id']);
    }

    public static function test_status_result_null_for_pending_task()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_null(Task::status($id)['result']);
    }

    public static function test_status_logs_is_empty_array_for_pending_task()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', []);
        static::__assert_equals([], Task::status($id)['logs']);
    }

    // -------------------------------------------------------------------------
    // Task::internal() - immediate execution using test service
    // -------------------------------------------------------------------------

    public static function test_internal_returns_task_return_value()
    {
        $result = Task::internal('Test_Echo_Service', 'echo_params', ['key' => 'value']);
        static::__assert_not_null($result);
        static::__assert_array_has_key('echo', $result);
        static::__assert_equals('value', $result['echo']['key']);
    }

    public static function test_internal_re_throws_exception_from_task()
    {
        static::__assert_throws(\Exception::class, function () {
            Task::internal('Test_Echo_Service', 'always_fail', []);
        }, 'deliberate test failure');
    }

    public static function test_internal_with_empty_params_returns_empty_echo()
    {
        $result = Task::internal('Test_Echo_Service', 'echo_params', []);
        static::__assert_array_has_key('echo', $result);
        static::__assert_count(0, $result['echo']);
    }
}
