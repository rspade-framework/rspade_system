<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Task\Task;

/**
 * The endpoint Task_Signed_In_Browser_Test drives: it starts a #[Run_As_Dispatcher] run the
 * way an application's mass edit or export endpoint does - validate, dispatch, answer the
 * handle. Indexed only under a test run.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Task_Signed_In_Browser_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('is_logged_in')]
    public static function start(Request $request, array $params = [])
    {
        return ['task_id' => Task::dispatch('Task_Run_As_Fixture_Service', 'as_dispatcher', ['tag' => (string) $params['tag']])];
    }
}
