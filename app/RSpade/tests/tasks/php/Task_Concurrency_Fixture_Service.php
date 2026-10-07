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
 * Test-only service exercising the concurrency attributes (#[Exclusive] /
 * #[Debounce]) and the plain (unmanaged) case. No #[Schedule] - never auto-run by
 * the cron. Methods are trivial and side-effect-free; each reports what ran.
 */
class Task_Concurrency_Fixture_Service extends Rsx_Service_Abstract
{
    #[Task('Fixture exclusive task (test-only)')]
    #[Exclusive]
    public static function exclusive_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'exclusive']);

        return null;
    }

    #[Task('Fixture debounce task (test-only)')]
    #[Debounce(30)]
    public static function debounce_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'debounce']);

        return null;
    }

    #[Task('Fixture plain unmanaged task (test-only)')]
    public static function plain_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'plain']);

        return null;
    }
}
