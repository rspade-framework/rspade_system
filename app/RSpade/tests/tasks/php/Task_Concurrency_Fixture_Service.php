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
 * Test-only service exercising the concurrency attributes (#[Exclusive], a two-member
 * #[Exclusive('fixture_group')] group, #[Debounce], #[Debounce] beside a bare #[Exclusive],
 * and a second group, 'fixture_combo_group', of one #[Debounce] member and one plain member)
 * and the plain (unmanaged) case. No #[Schedule] - never auto-run by the cron. Methods are
 * trivial and side-effect-free; each reports what ran.
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

    #[Task('Fixture debounce task with no delay (test-only)')]
    #[Debounce]
    public static function debounce_no_delay_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'debounce_no_delay']);

        return null;
    }

    #[Task('Fixture plain unmanaged task (test-only)')]
    public static function plain_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'plain']);

        return null;
    }

    #[Task('Fixture grouped exclusive task alpha (test-only)')]
    #[Exclusive('fixture_group')]
    public static function group_alpha(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'group_alpha']);

        return null;
    }

    #[Task('Fixture grouped exclusive task beta (test-only)')]
    #[Exclusive('fixture_group')]
    public static function group_beta(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'group_beta']);

        return null;
    }

    #[Task('Fixture debounce task also declaring a bare exclusive (test-only)')]
    #[Debounce(30)]
    #[Exclusive]
    public static function debounce_exclusive_task(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'debounce_exclusive']);

        return null;
    }

    #[Task('Fixture debounced member of the combo group (test-only)')]
    #[Debounce(30)]
    #[Exclusive('fixture_combo_group')]
    public static function combo_debounced(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'combo_debounced']);

        return null;
    }

    #[Task('Fixture plain member of the combo group (test-only)')]
    #[Exclusive('fixture_combo_group')]
    public static function combo_plain(Task_Instance $task, array $params = [])
    {
        $task->state(['ran' => 'combo_plain']);

        return null;
    }
}
