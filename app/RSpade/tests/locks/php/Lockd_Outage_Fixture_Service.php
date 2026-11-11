<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Tasks for Lockd_Outage_Test: a plain task that takes a named cluster lock, and a #[Debounce]
 * task whose dispatch takes its enqueue lock. Indexed only under a test run.
 */
class Lockd_Outage_Fixture_Service extends Rsx_Service_Abstract
{
    #[Task]
    public static function takes_a_lock(Task_Instance $task, array $params = [])
    {
        RsxLocks::release_lock(RsxLocks::named_write_lock('lockd_outage_fixture_task'));
    }

    #[Task]
    #[Debounce(30)]
    public static function debounced(Task_Instance $task, array $params = [])
    {
    }
}
