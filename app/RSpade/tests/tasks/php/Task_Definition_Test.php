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
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for task definition discovery and metadata.
 *
 * These tests read manifest metadata only - no tasks are dispatched or run,
 * so no DB writes occur. Default transaction-based isolation is correct here.
 */
class Task_Definition_Test extends Rsx_Test_Abstract
{
    // -------------------------------------------------------------------------
    // Task_Status value object
    // -------------------------------------------------------------------------

    public static function test_task_status_pending_constant()
    {
        static::__assert_equals('pending', Task_Status::PENDING);
    }

    public static function test_task_status_running_constant()
    {
        static::__assert_equals('running', Task_Status::RUNNING);
    }

    public static function test_task_status_completed_constant()
    {
        static::__assert_equals('completed', Task_Status::COMPLETED);
    }

    public static function test_task_status_failed_constant()
    {
        static::__assert_equals('failed', Task_Status::FAILED);
    }

    public static function test_task_status_value_returns_string()
    {
        $s = new Task_Status(Task_Status::PENDING);
        static::__assert_equals('pending', $s->value());
    }

    public static function test_task_status_is_pending()
    {
        $s = new Task_Status(Task_Status::PENDING);
        static::__assert_true($s->is_pending());
        static::__assert_false($s->is_running());
        static::__assert_false($s->is_completed());
        static::__assert_false($s->is_failed());
    }

    public static function test_task_status_is_running()
    {
        $s = new Task_Status(Task_Status::RUNNING);
        static::__assert_true($s->is_running());
        static::__assert_false($s->is_pending());
    }

    public static function test_task_status_is_completed()
    {
        $s = new Task_Status(Task_Status::COMPLETED);
        static::__assert_true($s->is_completed());
        static::__assert_true($s->is_terminal());
        static::__assert_false($s->is_pending());
    }

    public static function test_task_status_is_failed()
    {
        $s = new Task_Status(Task_Status::FAILED);
        static::__assert_true($s->is_failed());
        static::__assert_true($s->is_terminal());
    }

    public static function test_task_status_to_string()
    {
        $s = new Task_Status(Task_Status::COMPLETED);
        static::__assert_equals('completed', (string) $s);
    }

    public static function test_task_status_rejects_invalid_value()
    {
        static::__assert_throws(\InvalidArgumentException::class, function () {
            new Task_Status('invalid_status');
        });
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
            static::__assert_array_has_key('queue', $task);
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
    // Task_Instance construction and getters
    // -------------------------------------------------------------------------

    public static function test_task_instance_getters_for_immediate_mode()
    {
        $instance = new Task_Instance('My_Service', 'my_task', ['foo' => 'bar'], 'default', true);

        static::__assert_equals('My_Service', $instance->get_class());
        static::__assert_equals('my_task', $instance->get_method());
        static::__assert_equals(['foo' => 'bar'], $instance->get_params());
        static::__assert_equals('default', $instance->get_queue());
        static::__assert_true($instance->is_immediate());
        static::__assert_null($instance->get_id());
    }

    public static function test_task_instance_initial_status_is_pending()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        static::__assert_equals(Task_Status::PENDING, $instance->get_status()->value());
    }

    public static function test_task_instance_mark_started_changes_status_to_running()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $instance->mark_started();
        static::__assert_equals(Task_Status::RUNNING, $instance->get_status()->value());
    }

    public static function test_task_instance_mark_completed_changes_status()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $instance->mark_completed(['result' => 'ok']);
        static::__assert_equals(Task_Status::COMPLETED, $instance->get_status()->value());
    }

    public static function test_task_instance_mark_failed_changes_status()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $instance->mark_failed('something went wrong');
        static::__assert_equals(Task_Status::FAILED, $instance->get_status()->value());
    }

    public static function test_task_instance_info_appends_log_entry()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $instance->info('first message');
        $instance->info('second message');

        $logs = $instance->get_logs();
        static::__assert_count(2, $logs);
        static::__assert_contains('first message', $logs[0]);
        static::__assert_contains('[info]', $logs[0]);
    }

    public static function test_task_instance_error_log_includes_level()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $instance->error('something broke');

        $logs = $instance->get_logs();
        static::__assert_count(1, $logs);
        static::__assert_contains('[error]', $logs[0]);
        static::__assert_contains('something broke', $logs[0]);
    }

    public static function test_task_instance_get_temp_dir_creates_directory()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $dir = $instance->get_temp_dir();

        static::__assert_not_empty($dir);
        static::__assert_true(is_dir($dir), "Temp directory does not exist: {$dir}");

        // Clean up
        $instance->cleanup_temp_dir();
        static::__assert_false(is_dir($dir), 'Temp directory should have been removed after cleanup');
    }

    public static function test_task_instance_get_temp_dir_same_on_second_call()
    {
        $instance = new Task_Instance('My_Service', 'my_task', [], 'default', true);
        $dir1 = $instance->get_temp_dir();
        $dir2 = $instance->get_temp_dir();

        static::__assert_equals($dir1, $dir2);

        $instance->cleanup_temp_dir();
    }

    // -------------------------------------------------------------------------
    // Task::internal() - immediate execution
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
