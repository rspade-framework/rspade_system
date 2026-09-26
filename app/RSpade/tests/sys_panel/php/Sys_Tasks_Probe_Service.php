<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * A #[Task] for Sys_Tasks_Controller_Test to plant rows of and re-dispatch.
 *
 * In the manifest only while rsx:test runs (the test tree is scanned then), so it never
 * exists on a served site. Side-effect free.
 */
class Sys_Tasks_Probe_Service extends Rsx_Service_Abstract
{
    #[Task('Echo params back (sys_panel test fixture)')]
    public static function probe(Task_Instance $task, array $params = []): array
    {
        return ['echo' => $params];
    }
}
