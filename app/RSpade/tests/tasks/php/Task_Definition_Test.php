<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Tests for task definition discovery and metadata.
 *
 * Metadata and handles: the run model's lifecycle vocabulary, the cron parser, scheduled-task
 * discovery from the manifest, the Task_Instance of a run row, and the guard errors of
 * Task::internal(). The few rows written roll back with the per-test transaction.
 */
class Task_Definition_Test extends Rsx_Test_Abstract
{
    // -------------------------------------------------------------------------
    // Task_Run_Model - the lifecycle vocabulary of a run
    // -------------------------------------------------------------------------

    public static function test_status_constants_are_the_seven_lifecycle_states()
    {
        static::__assert_equals(
            [1 => 'STATUS_PENDING', 2 => 'STATUS_RUNNING', 3 => 'STATUS_COMPLETED', 4 => 'STATUS_FAILED', 5 => 'STATUS_STOPPED', 6 => 'STATUS_KILLED', 7 => 'STATUS_CANCELLED'],
            array_map(fn ($enum) => $enum['constant'], Task_Run_Model::$enums['status_id'])
        );
        static::__assert_equals(1, Task_Run_Model::STATUS_PENDING);
        static::__assert_equals(7, Task_Run_Model::STATUS_CANCELLED);
    }

    public static function test_only_pending_and_running_are_live()
    {
        static::__assert_equals([Task_Run_Model::STATUS_PENDING, Task_Run_Model::STATUS_RUNNING], Task_Run_Model::LIVE_STATUSES);

        foreach (Task_Run_Model::$enums['status_id'] as $id => $enum) {
            $run = new Task_Run_Model();
            $run->status_id = $id;
            $live = in_array($id, Task_Run_Model::LIVE_STATUSES, true);

            static::__assert_equals($live, $run->is_live(), "{$enum['constant']} is_live()");
            static::__assert_equals(!$live, $run->is_terminal(), "{$enum['constant']} is_terminal()");
            static::__assert_equals(!$live, $enum['terminal'], "{$enum['constant']} carries terminal in its enum");
        }
    }

    public static function test_origin_and_pool_constants()
    {
        static::__assert_equals([1, 2, 3], [Task_Run_Model::ORIGIN_DISPATCHED, Task_Run_Model::ORIGIN_SCHEDULED, Task_Run_Model::ORIGIN_INLINE]);
        static::__assert_equals([1, 2], [Task_Run_Model::POOL_ON_DEMAND, Task_Run_Model::POOL_SCHEDULED]);
        static::__assert_equals(['on_demand', 'scheduled', 'kill'], Task_Pool::POOLS);
    }

    // -------------------------------------------------------------------------
    // Cron_Parser - expression parsing
    // -------------------------------------------------------------------------

    public static function test_cron_parser_accepts_daily_expression()
    {
        $parser = new Cron_Parser('0 3 * * *');
        static::__assert_equals('0 3 * * *', $parser->get_expression());
    }

    public static function test_cron_parser_rejects_invalid_expression()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            new Cron_Parser('not a cron expression');
        });
    }

    public static function test_cron_parser_rejects_too_few_parts()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            new Cron_Parser('0 3 * *');
        });
    }

    public static function test_cron_parser_is_valid_returns_true_for_valid()
    {
        static::__assert_true(Cron_Parser::is_valid('0 3 * * *'));
        static::__assert_true(Cron_Parser::is_valid('*/15 * * * *'));
        static::__assert_true(Cron_Parser::is_valid('0 0 1 * *'));
    }

    public static function test_cron_parser_is_valid_returns_false_for_invalid()
    {
        static::__assert_false(Cron_Parser::is_valid('not valid'));
        static::__assert_false(Cron_Parser::is_valid('60 0 * * *'));  // minute 60 is out of range
    }

    public static function test_cron_parser_get_next_run_time_returns_integer()
    {
        $parser = new Cron_Parser('0 3 * * *');
        $next = $parser->get_next_run_time(time());
        static::__assert_true(is_int($next));
        static::__assert_greater_than(time(), $next);
    }

    public static function test_cron_parser_next_run_at_least_one_minute_ahead()
    {
        $parser = new Cron_Parser('* * * * *');
        $from = time();
        $next = $parser->get_next_run_time($from);
        // get_next_run_time always starts from next minute
        static::__assert_true($next > $from);
    }

    // -------------------------------------------------------------------------
    // Cron_Parser - human-readable schedule syntax
    // -------------------------------------------------------------------------

    public static function test_cron_parser_accepts_human_readable_phrases()
    {
        static::__assert_true(Cron_Parser::is_valid('every minute'));
        static::__assert_true(Cron_Parser::is_valid('every 5 minutes'));
        static::__assert_true(Cron_Parser::is_valid('every 6 hours'));
        static::__assert_true(Cron_Parser::is_valid('hourly'));
        static::__assert_true(Cron_Parser::is_valid('daily'));
        static::__assert_true(Cron_Parser::is_valid('daily at 2am'));
        static::__assert_true(Cron_Parser::is_valid('daily at 14:30'));
        static::__assert_true(Cron_Parser::is_valid('weekly on monday at 9:30am'));
        static::__assert_true(Cron_Parser::is_valid('monthly'));
    }

    public static function test_cron_parser_phrase_matches_equivalent_cron()
    {
        // A phrase and its canonical cron must schedule identically.
        $from = time();

        $pairs = [
            ['every minute', '* * * * *'],
            ['every 5 minutes', '*/5 * * * *'],
            ['every 6 hours', '0 */6 * * *'],
            ['hourly', '0 * * * *'],
            ['daily at 2am', '0 2 * * *'],
            ['daily at 14:30', '30 14 * * *'],
            ['weekly on monday at 9:30am', '30 9 * * 1'],
            ['monthly', '0 0 1 * *'],
        ];

        foreach ($pairs as [$phrase, $cron]) {
            $phrase_next = (new Cron_Parser($phrase))->get_next_run_time($from);
            $cron_next = (new Cron_Parser($cron))->get_next_run_time($from);
            static::__assert_equals($cron_next, $phrase_next, "'{$phrase}' should match '{$cron}'");
        }
    }

    public static function test_cron_parser_preserves_original_phrase()
    {
        // get_expression() returns what the developer wrote, not the normalized cron.
        $parser = new Cron_Parser('every 5 minutes');
        static::__assert_equals('every 5 minutes', $parser->get_expression());
    }

    public static function test_cron_parser_rejects_out_of_range_phrases()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            new Cron_Parser('every 99 minutes');  // minute interval > 59
        });
        static::__assert_throws(\InvalidArgumentException::class, function () {
            new Cron_Parser('daily at 25:00');  // hour > 23
        });
        static::__assert_false(Cron_Parser::is_valid('every 5 potatoes'));
    }

    // -------------------------------------------------------------------------
    // Task::get_scheduled_tasks() - manifest discovery
    // -------------------------------------------------------------------------

    public static function test_get_scheduled_tasks_returns_array()
    {
        $tasks = Task::get_scheduled_tasks();
        static::__assert_true(is_array($tasks));
    }

    public static function test_get_scheduled_tasks_includes_session_cleanup()
    {
        $tasks = Task::get_scheduled_tasks();
        $found = false;

        foreach ($tasks as $task) {
            if (str_contains($task['class'], 'Session_Cleanup_Service')
                && $task['method'] === 'cleanup_sessions') {
                $found = true;
                break;
            }
        }

        static::__assert_true($found, 'Session_Cleanup_Service::cleanup_sessions not found in scheduled tasks');
    }

    public static function test_get_scheduled_tasks_each_has_required_keys()
    {
        $tasks = Task::get_scheduled_tasks();
        static::__assert_greater_than(0, count($tasks));

        foreach ($tasks as $task) {
            static::__assert_array_has_key('class', $task);
            static::__assert_array_has_key('method', $task);
            static::__assert_array_has_key('cron_expression', $task);
        }
    }

    public static function test_get_scheduled_tasks_cron_expressions_are_valid()
    {
        $tasks = Task::get_scheduled_tasks();

        foreach ($tasks as $task) {
            $expr = $task['cron_expression'];
            static::__assert_true(
                Cron_Parser::is_valid($expr),
                "Invalid cron expression '{$expr}' in {$task['class']}::{$task['method']}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Task_Instance - the handle of one run's row
    // -------------------------------------------------------------------------

    /** A RUNNING inline run of the exec fixture in this process, and its instance. */
    private static function __instance(array $params = []): Task_Instance
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', $params, Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());

        return Task_Instance::find($id);
    }

    public static function test_task_instance_has_no_public_constructor()
    {
        static::__assert_true(
            (new \ReflectionMethod(Task_Instance::class, '__construct'))->isPrivate(),
            'an instance exists only for a run row: find() / for_row()'
        );
        static::__assert_null(Task_Instance::find(2147480000), 'a missing run has no instance');
    }

    public static function test_task_instance_getters_read_the_row()
    {
        $instance = static::__instance(['foo' => 'bar']);

        static::__assert_equals(Task_Exec_Fixture_Service::class, $instance->get_class());
        static::__assert_equals('marker_a', $instance->get_method());
        static::__assert_equals(['foo' => 'bar'], $instance->get_params());
        static::__assert_greater_than(0, $instance->get_id());
        static::__assert_equals($instance->get_id(), Task_Instance::for_row(Task_Run_Model::find($instance->get_id()))->get_id());
    }

    public static function test_task_instance_get_temp_dir_creates_directory()
    {
        $instance = static::__instance();
        $dir = $instance->get_temp_dir();

        static::__assert_not_empty($dir);
        static::__assert_true(is_dir($dir), "Temp directory does not exist: {$dir}");
        static::__assert_true(str_ends_with($dir, '/task_' . $instance->get_id()), 'named for its run');

        $instance->cleanup_temp_dir();
        static::__assert_false(is_dir($dir), 'Temp directory should have been removed after cleanup');
    }

    public static function test_task_instance_get_temp_dir_same_on_second_call()
    {
        $instance = static::__instance();
        $dir1 = $instance->get_temp_dir();
        $dir2 = $instance->get_temp_dir();

        static::__assert_equals($dir1, $dir2);

        $instance->cleanup_temp_dir();
    }

    // -------------------------------------------------------------------------
    // Task::internal() - guard errors
    // -------------------------------------------------------------------------

    public static function test_task_internal_throws_for_unknown_service()
    {
        static::__assert_throws(\Exception::class, function () {
            Task::internal('NonExistent_Service_Xyz_999', 'some_task');
        }, 'Service class not found');
    }

    public static function test_task_internal_throws_for_missing_task_attribute()
    {
        // Rsx_Service_Abstract itself has no #[Task] methods - pre_task exists but lacks attribute
        static::__assert_throws(\Exception::class, function () {
            Task::internal('Session_Cleanup_Service', 'pre_task');
        });
    }
}
