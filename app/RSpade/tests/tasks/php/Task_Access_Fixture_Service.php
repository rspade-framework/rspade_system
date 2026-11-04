<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Test-only service whose tasks declare each shape of #[Task_Access] Task_Gates_Test reads.
 * The tasks never run: the test inserts their run rows directly.
 *
 *   undeclared       no #[Task_Access] - developers only
 *   mine             'dispatcher' (staff)
 *   portal_mine      'dispatcher', realm portal
 *   watched          a custom check (staff): the runs in $visible_ids, for the actions in $allowed_actions
 *   watched_anywhere the same check, realm any
 *
 * The check records every question it is asked in $asked, so a test can prove what the
 * framework asks and what it never asks (a developer, a signed-out caller).
 */
class Task_Access_Fixture_Service extends Rsx_Service_Abstract
{
    /** Run ids the custom check admits. */
    public static array $visible_ids = [];

    /** Actions the custom check admits on those runs. */
    public static array $allowed_actions = ['view'];

    /** Every question asked: [run id, user class basename, action]. */
    public static array $asked = [];

    public static function reset(): void
    {
        self::$visible_ids = [];
        self::$allowed_actions = ['view'];
        self::$asked = [];
    }

    public static function watched_access(Task_Run_Model $run, $user, string $action): bool
    {
        self::$asked[] = [(int) $run->id, class_basename($user), $action];

        return in_array((int) $run->id, self::$visible_ids, true) && in_array($action, self::$allowed_actions, true);
    }

    #[Task('access fixture: undeclared')]
    public static function undeclared(Task_Instance $task, array $params = [])
    {
        return null;
    }

    #[Task('access fixture: the dispatcher sees it')]
    #[Task_Access('dispatcher')]
    public static function mine(Task_Instance $task, array $params = [])
    {
        return null;
    }

    #[Task('access fixture: the portal dispatcher sees it')]
    #[Task_Access('dispatcher', realm: 'portal')]
    public static function portal_mine(Task_Instance $task, array $params = [])
    {
        return null;
    }

    #[Task('access fixture: a custom check decides')]
    #[Task_Access('watched_access')]
    public static function watched(Task_Instance $task, array $params = [])
    {
        return null;
    }

    #[Task('access fixture: a custom check decides, in either realm')]
    #[Task_Access('watched_access', realm: 'any')]
    public static function watched_anywhere(Task_Instance $task, array $params = [])
    {
        return null;
    }
}
