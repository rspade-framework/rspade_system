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
 * Test-only service carrying an #[On_Build] task (Task_On_Build_Test). Indexed only while
 * rsx:test runs, and the scheduler tick skips the #[On_Build] step under the suite, so this
 * task is dispatched only when a test calls Task_On_Build::dispatch_for_build() itself.
 */
class Task_On_Build_Fixture_Service extends Rsx_Service_Abstract
{
    #[Task('Fixture build-triggered task (test-only)')]
    #[Exclusive]
    #[On_Build]
    public static function after_build(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'after_build']);

        return null;
    }
}
