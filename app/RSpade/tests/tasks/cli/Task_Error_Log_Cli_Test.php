<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Cli;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A run that ends in a PHP error writes that error to the Laravel log as well as recording it
 * on the run, every line prefixed `[TASK #<id>] <Service>::<method>: `.
 *
 * Driven for real through `rsx:task:run` in a child process, because the fatal case IS the
 * process ending: a warning (raised as an ErrorException, so the run fails with it), a fatal
 * error (memory exhausted mid-run - the run records the fatal, and the log names the run), and
 * a failure the task RETURNS (its own verdict, which stays on the run and off the log).
 *
 * Each child is pointed at the TEST database and commits its run, so the class provisions a
 * clean baseline and reads the rows the children wrote. The log is read from the offset it
 * had before the child ran.
 */
class Task_Error_Log_Cli_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private const SERVICE = 'Task_Exec_Fixture_Service';

    /**
     * Run one fixture method in a child; answer its run row and what it added to the log.
     *
     * @return array{0: ?Task_Run_Model, 1: string}
     */
    private static function __run(string $method, array $args = []): array
    {
        $log = (string) config('logging.channels.single.path');
        clearstatcache();
        $offset = is_file($log) ? filesize($log) : 0;

        $output = [];
        Rsx_Artisan::run('rsx:task:run', array_merge([self::SERVICE, $method], $args), $output, [
            'DB_DATABASE' => (string) config('database.connections.test.database'),
        ]);

        clearstatcache();
        $added = is_file($log) ? (string) file_get_contents($log, false, null, $offset) : '';

        $id = DB::table('_tasks')->where('method', $method)->max('id');

        return [$id === null ? null : Task_Run_Model::find((int) $id), $added];
    }

    public static function test_a_php_warning_is_recorded_and_logged_under_the_task_id()
    {
        [$run, $log] = static::__run('raises_warning');

        static::__assert_not_null($run, 'the run row exists');
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'the warning failed the run');
        static::__assert_contains('fixture_missing_key', (string) $run->error, 'the run records the warning');

        $prefix = "[TASK #{$run->id}] Task_Exec_Fixture_Service::raises_warning: ";
        static::__assert_contains($prefix . 'ErrorException', $log, 'the log line carries the task id and the error');
        static::__assert_contains('fixture_missing_key', $log, 'the log line carries the warning text');
    }

    public static function test_a_fatal_error_is_recorded_on_the_run_and_logged_under_the_task_id()
    {
        [$run, $log] = static::__run('dies_of_a_fatal_error');

        static::__assert_not_null($run, 'the run row exists');
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'the inline run was settled as abandoned');
        static::__assert_contains('PHP Fatal error: Allowed memory size', (string) $run->error, 'the run records the fatal');

        static::__assert_contains(
            "[TASK #{$run->id}] Task_Exec_Fixture_Service::dies_of_a_fatal_error: PHP Fatal error: Allowed memory size",
            $log,
            'the log line carries the task id and the fatal'
        );
    }

    public static function test_a_returned_failure_stays_off_the_log()
    {
        [$run, $log] = static::__run('returns_kind', ['--kind=false']);

        static::__assert_not_null($run, 'the run row exists');
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id, 'returning false failed the run');
        static::__assert_true(!str_contains($log, "[TASK #{$run->id}]"), 'a returned failure writes no log line');
    }
}
