<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Task\Rsx_Task_Controller;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Rsx_Task_Controller::report - one report's value. A state_list can be asked for only its
 * first (oldest) N items, and always says how many it holds, so a queue viewer sized to N rows
 * fetches N. Acts as user 1, a developer, who passes every task gate.
 */
class Task_Report_Endpoint_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __run_with_queue(array $items): int
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, Task_Runner::running_fields());
        $task = Task_Instance::find($id);
        $task->state_list($items);
        $task->summary('done');
        $task->flush();

        return $id;
    }

    private static function __report(array $params)
    {
        return Rsx_Task_Controller::report(Request::create('/'), $params);
    }

    public static function test_a_limit_answers_the_first_items_and_the_total()
    {
        $id = static::__run_with_queue(['one', 'two', 'three', 'four', 'five']);

        $limited = static::__report(['task_id' => $id, 'kind' => 'state_list', 'limit' => 2]);
        static::__assert_equals(['one', 'two'], $limited['value'], 'the oldest two');
        static::__assert_equals(5, $limited['total']);

        $whole = static::__report(['task_id' => $id, 'kind' => 'state_list']);
        static::__assert_equals(['one', 'two', 'three', 'four', 'five'], $whole['value'], 'no limit: every item');
        static::__assert_equals(5, $whole['total']);

        $roomy = static::__report(['task_id' => $id, 'kind' => 'state_list', 'limit' => 50]);
        static::__assert_equals(5, count($roomy['value']), 'a limit above the length is every item');
    }

    public static function test_a_limit_below_one_is_refused()
    {
        $id = static::__run_with_queue(['one']);

        foreach ([0, -3, 'many'] as $bad) {
            static::__assert_instance_of(Error_Response::class, static::__report(['task_id' => $id, 'kind' => 'state_list', 'limit' => $bad]), "limit {$bad}");
        }
    }

    public static function test_total_is_null_for_every_other_kind()
    {
        $id = static::__run_with_queue(['one']);

        $summary = static::__report(['task_id' => $id, 'kind' => 'summary', 'limit' => 1]);
        static::__assert_equals('done', $summary['value'], 'a limit does not touch another kind');
        static::__assert_null($summary['total']);
    }
}
