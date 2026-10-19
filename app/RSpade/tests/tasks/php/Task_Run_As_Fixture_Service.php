<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Fixture tasks for Task_Run_As_Dispatcher_Test: each records, into its run's state, who the
 * session says is acting while it runs.
 */
class Task_Run_As_Fixture_Service extends Rsx_Service_Abstract
{
    #[Task('Fixture: runs as its dispatcher (test-only)')]
    #[Run_As_Dispatcher]
    public static function as_dispatcher(Task_Instance $task, array $params = [])
    {
        $task->state(static::__who());

        return null;
    }

    #[Task('Fixture: runs as nobody (test-only)')]
    public static function as_nobody(Task_Instance $task, array $params = [])
    {
        $task->state(static::__who());

        return null;
    }

    /**
     * The acting context the session answers right now.
     *
     * @return array
     */
    private static function __who(): array
    {
        $portal = Rsx_Portal::is_portal_request();

        return [
            'ran' => true,
            'portal' => $portal,
            'login_user_id' => Session::get_login_user_id(),
            'user_id' => Session::get_user_id(),
            'site_id' => $portal ? Portal_Session::get_site_id() : Session::get_site_id(),
            'portal_user_id' => $portal ? Portal_Session::get_portal_user_id() : null,
            'impersonator_login_user_id' => Session::get_impersonator_login_user_id(),
            'actor' => Rsx_Model_Abstract::_resolve_context_actor(),
            'audit_session_id' => Session::get_audit_session_id(),
        ];
    }
}
