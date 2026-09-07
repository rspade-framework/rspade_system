<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Test-only service used by Task_Worker_Execution_Test to observe the WORKER's
 * execution behavior (claim priority, cron recycle, completion).
 *
 * No #[Schedule] attribute - these tasks are never auto-run by the cron
 * processor. The worker runs in the SAME process as the test, so the static
 * $run_order array records the real execution order across the worker's task
 * calls and is readable by the test afterward (DATETIME started_at has no
 * sub-second resolution, so ordering can only be captured this way).
 */
class Task_Exec_Fixture_Service extends Rsx_Service_Abstract
{
    /**
     * Records the marker of each task run, in execution order.
     * @var array
     */
    public static array $run_order = [];

    #[Task('exec fixture marker a')]
    public static function marker_a(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'A';
        return ['marker' => 'A'];
    }

    #[Task('exec fixture marker b')]
    public static function marker_b(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'B';
        return ['marker' => 'B'];
    }

    /**
     * Always throws an ordinary Exception - the failure path of the terminal writers.
     */
    #[Task('exec fixture that always throws')]
    public static function always_throws(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'THROW';
        throw new \Exception('fixture exploded on purpose');
    }

    /**
     * Always throws an \Error (not an Exception) - the widened Throwable catch in the
     * worker is what records this on the row at all.
     */
    #[Task('exec fixture that raises a TypeError')]
    public static function raises_type_error(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'TYPE_ERROR';
        throw new \TypeError('fixture type error on purpose');
    }
}
