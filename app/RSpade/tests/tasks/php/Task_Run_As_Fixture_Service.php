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
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Fixture tasks for Task_Run_As_Dispatcher_Test: each records, into its run's state, who the
 * session says is acting while it runs - and the dispatcher task also records what happens
 * when it tries to change that, or to reach the browser's session values.
 */
class Task_Run_As_Fixture_Service extends Rsx_Service_Abstract
{
    /**
     * Every run in THIS process, in execution order: 'dispatcher', or as_nobody's 'tag' param
     * ('untagged' without one). A worker run in-process by the test shares the static.
     *
     * @var string[]
     */
    public static array $run_order = [];

    #[Task('Fixture: runs as its dispatcher (test-only)')]
    #[Run_As_Dispatcher]
    public static function as_dispatcher(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = 'dispatcher';
        $who = static::__who();

        if (!empty($params['probe'])) {
            $who['refused'] = [
                'set_site_id' => static::__refusal(fn () => Session::set_site_id(999)),
                'set_login_user_id' => static::__refusal(fn () => Session::set_login_user_id(999)),
                'logout' => static::__refusal(fn () => Session::logout()),
                'get_value' => static::__refusal(fn () => Session::get_value('anything')),
                'put_value' => static::__refusal(fn () => Session::put_value('anything', 1)),
                'portal_set_site_id' => static::__refusal(fn () => Portal_Session::set_site_id(999)),
            ];
        }

        $task->state($who);

        return null;
    }

    #[Task('Fixture: runs as nobody (test-only)')]
    public static function as_nobody(Task_Instance $task, array $params = [])
    {
        self::$run_order[] = $params['tag'] ?? 'untagged';
        $task->state(static::__who());

        return null;
    }

    #[Task('Fixture: declares another identity, site, realm and audit session (test-only)')]
    public static function rewrites_context(Task_Instance $task, array $params = [])
    {
        Session::set_login_user_id(424242);
        Session::set_site_id(4242);
        Session::set_temporary_site_id(4243);
        Rsx_Portal::set_portal_request(true);
        \App\RSpade\Core\Session\Audit_Session::_declare(424242);

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
            'session_id' => Session::get_session_id(),
            'user_session_id' => Session::get_user_session_id(),
            'timezone' => Rsx_Time::get_user_timezone(),
        ];
    }

    /**
     * The message $call threw, or null when it did not throw.
     */
    private static function __refusal(callable $call): ?string
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
