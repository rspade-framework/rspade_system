<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Test-only service the tasks/ tests run through workers, Task::internal() and the runner.
 *
 * No #[Schedule] attribute - the cron tick never registers these; a test that needs a schedule
 * writes its own _task_schedules row pointed at a method here. The worker runs in the SAME
 * process as the test, so the static $run_order array records the real execution order
 * across the worker's runs and is readable by the test afterward.
 *
 * Every method follows the return contract: null for success, an integer or false for a
 * failure code, and any data it produces reported through the run ($task->state()).
 */
class Task_Exec_Fixture_Service extends Rsx_Service_Abstract
{
    /**
     * Records the marker of each run, in execution order.
     * @var array
     */
    public static array $run_order = [];

    #[Task('exec fixture marker a')]
    public static function marker_a(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'A';
        $task->state(['marker' => 'A']);

        return null;
    }

    #[Task('exec fixture marker b')]
    public static function marker_b(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'B';
        $task->state(['marker' => 'B']);

        return null;
    }

    /**
     * Returns the value named by $params['kind'] - every shape the return contract decides.
     */
    #[Task('exec fixture returning a chosen value')]
    public static function returns_kind(Task_Instance $task, array $params = [])
    {
        return match ($params['kind']) {
            'null' => null,
            'true' => true,
            'false' => false,
            'zero' => 0,
            'seven' => 7,
            'negative' => -3,
            'large' => 300,
            'array' => ['done' => true],
            'string' => 'ok',
            'zero_string' => '0',
            'float' => 1.5,
            'object' => new \stdClass(),
        };
    }

    /**
     * A cooperatively stoppable task: runs up to `batches` batches, checking
     * is_stop_requested() before each one, and itself requests a stop after batch
     * `request_stop_after` - standing in for an operator's request arriving mid-run.
     */
    #[Task('exec fixture stoppable batches')]
    public static function stoppable_batches(Task_Instance $task, array $params = [])
    {
        $done = 0;

        for ($batch = 1; $batch <= (int) ($params['batches'] ?? 10); $batch++) {
            if ($task->is_stop_requested()) {
                break;
            }

            $done++;

            if ($batch === (int) ($params['request_stop_after'] ?? 0)) {
                Task_Run_Model::find($task->get_id())->request_stop('fixture operator');
            }
        }

        $task->state(['batches_done' => $done]);

        return null;
    }

    /**
     * Always throws an ordinary Exception.
     */
    #[Task('exec fixture that always throws')]
    public static function always_throws(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'THROW';
        throw new \Exception('fixture exploded on purpose');
    }

    /**
     * Always throws an \Error (not an Exception) - recorded like any other failure.
     */
    #[Task('exec fixture that raises a TypeError')]
    public static function raises_type_error(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'TYPE_ERROR';
        throw new \TypeError('fixture type error on purpose');
    }

    /**
     * Writes between echoes, opens a buffer it never closes, and ends on a partial line -
     * every shape the runner's stdout capture records.
     */
    #[Task('exec fixture that prints')]
    public static function prints_output(Task_Instance $task, array $params = [])
    {
        $task->stdout('before the echo');
        echo "first printed line\nsecond printed line\n";
        $task->stderr('between');
        ob_start();
        echo "from a buffer left open\n";
        print 'trailing partial';

        return null;
    }

    /**
     * Prints, then throws: the printed line is recorded before the run is settled failed.
     */
    #[Task('exec fixture that prints and throws')]
    public static function prints_then_throws(Task_Instance $task, array $params = [])
    {
        echo "printed before the throw\n";
        throw new \Exception('fixture exploded after printing');
    }
}
