<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Run_Outcome;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE RETURN CONTRACT: a task method's return value is its return code.
 *
 *   null, true, 0 (===)    success: COMPLETED, return_code 0, no error
 *   false                  failure: FAILED, return_code 1
 *   any other integer      failure: FAILED, that return code
 *   anything else          failure: FAILED, return_code 1, the error names the type
 *   a throw                failure: FAILED, return_code 1, error "Class: message"
 *
 * and a console runner exits with the return code clamped to 1..255.
 *
 * Each shape is run for real through Task::internal() on the exec fixture's returns_kind()
 * and read back off the settled row; the clamping is Task_Run_Outcome::exit_code(), a pure
 * function. Rows roll back with the per-test transaction.
 */
class Task_Return_Contract_Test extends Rsx_Test_Abstract
{
    private static function __run(string $kind): Task_Run_Model
    {
        return Task::internal('Task_Exec_Fixture_Service', 'returns_kind', ['kind' => $kind]);
    }

    private static function __assert_success(string $kind): void
    {
        $run = static::__run($kind);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, "{$kind}: completed");
        static::__assert_equals(0, (int) $run->return_code, "{$kind}: return code 0");
        static::__assert_null($run->error, "{$kind}: no error");
    }

    private static function __assert_failure(string $kind, int $return_code, string $error_contains): void
    {
        $run = static::__run($kind);

        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, "{$kind}: failed");
        static::__assert_equals($return_code, (int) $run->return_code, "{$kind}: return code {$return_code}");
        static::__assert_contains($error_contains, (string) $run->error, "{$kind}: the error says why: " . $run->error);

        $lines = array_column($run->output_after(null, ['stderr']), 'line');
        static::__assert_true(in_array('Task failed: ' . $run->error, $lines, true), "{$kind}: the failure is a stderr line of the run");
    }

    // -------------------------------------------------------------------------
    // Success
    // -------------------------------------------------------------------------

    public static function test_null_true_and_zero_are_success()
    {
        static::__assert_success('null');
        static::__assert_success('true');
        static::__assert_success('zero');
    }

    // -------------------------------------------------------------------------
    // Failure codes
    // -------------------------------------------------------------------------

    public static function test_false_is_failure_code_one()
    {
        static::__assert_failure('false', 1, 'The task returned false.');
    }

    public static function test_another_integer_is_its_own_failure_code()
    {
        static::__assert_failure('seven', 7, 'The task returned exit code 7.');
        static::__assert_failure('negative', -3, 'The task returned exit code -3.');
        static::__assert_failure('large', 300, 'The task returned exit code 300.');
    }

    /**
     * A task reports data with summary(), state() or an attachment, never through its return
     * value - so a value of any other type is a failure, and the error names the type.
     */
    public static function test_any_other_type_is_failure_code_one()
    {
        static::__assert_failure('array', 1, 'The task returned array');
        static::__assert_failure('string', 1, 'The task returned string');
        static::__assert_failure('zero_string', 1, 'The task returned string');
        static::__assert_failure('float', 1, 'The task returned float');
        static::__assert_failure('object', 1, 'The task returned stdClass');
    }

    // -------------------------------------------------------------------------
    // A throw
    // -------------------------------------------------------------------------

    public static function test_a_throw_is_failure_code_one_with_the_exception_as_the_error()
    {
        foreach (['always_throws' => 'Exception: fixture exploded on purpose', 'raises_type_error' => 'TypeError: fixture type error on purpose'] as $method => $error) {
            $before = (int) Task_Run_Model::max('id');

            $thrown = static::__assert_throws(\Throwable::class, fn () => Task::internal('Task_Exec_Fixture_Service', $method));
            static::__assert_equals($error, get_class($thrown) . ': ' . $thrown->getMessage(), 'internal() rethrows what the task threw');

            $run = Task_Run_Model::where('id', '>', $before)->where('method', $method)->first();
            static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, "{$method}: failed");
            static::__assert_equals(1, (int) $run->return_code, "{$method}: return code 1");
            static::__assert_equals($error, $run->error, "{$method}: the exception class and message");
        }
    }

    // -------------------------------------------------------------------------
    // The exit code a console runner uses
    // -------------------------------------------------------------------------

    public static function test_exit_code_clamps_to_one_through_255()
    {
        $cases = [
            [null, 0],
            [true, 0],
            [0, 0],
            [false, 1],
            [7, 7],
            [255, 255],
            [256, 1],
            [-3, 1],
            [['x'], 1],
        ];

        foreach ($cases as [$value, $exit_code]) {
            static::__assert_equals(
                $exit_code,
                Task_Run_Outcome::from_return($value)->exit_code(),
                var_export($value, true) . " exits {$exit_code}"
            );
        }

        static::__assert_equals(1, Task_Run_Outcome::from_throwable(new \Exception('x'))->exit_code(), 'a throw exits 1');
    }
}
