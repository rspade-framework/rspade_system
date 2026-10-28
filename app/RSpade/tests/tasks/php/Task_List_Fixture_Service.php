<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Tests\Tasks\Php\Task_List_Fixture_Base_Abstract;

/**
 * Test-only service under an abstract intermediate (Task_List_Fixture_Base_Abstract), for
 * Task_List_Command_Test. Never scheduled, never dispatched.
 */
class Task_List_Fixture_Service extends Task_List_Fixture_Base_Abstract
{
    #[Task('task list fixture under an abstract base')]
    public static function listed_under_a_base(Task_Instance $task, array $params = [])
    {
        return null;
    }
}
