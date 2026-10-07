<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Gates - who may see a task run, and who may act on one. The single seam every task
 * endpoint, topic and widget asks.
 *
 * A run's params, reports, output and files are operator data, and stopping or rerunning one
 * is an operator act, so the APPLICATION decides who may do either, through event handlers in
 * /rsx/handlers/ - and with no handler, NOBODY may (deny by default, in both realms):
 *
 *   staff realm                        portal realm
 *   task.view.authorize   (gate)       portal.task.view.authorize   (gate)
 *   task.view.scope       (filter)     portal.task.view.scope       (filter)
 *   task.control.authorize (gate)      portal.task.control.authorize (gate)
 *
 * - The view GATE answers for one run: data {task: Task_Run_Model, user}; a handler returns true
 *   to allow. Every handler must allow (Rsx::trigger_gate()).
 * - The view SCOPE narrows a list query to the runs the viewer may see: each handler receives
 *   the Task_Run_Model query builder and returns it narrowed (Rsx::trigger_filter()). A list is
 *   the scope; a single run is the gate - keep the two rules the same.
 * - The control GATE answers for one action on one run: data {task, user, action}, action
 *   one of stop, force_stop, force_kill, cancel, rerun.
 *
 * `user` is the realm's own identity: Session::get_user() on staff, the portal user on a
 * portal request.
 *
 * A DEVELOPER (Session::is_developer(), staff realm) passes every gate without any handler
 * being asked - the developer console (/_sys) shows and controls every run.
 */
class Task_Gates
{
    const VIEW = 'task.view.authorize';
    const VIEW_SCOPE = 'task.view.scope';
    const CONTROL = 'task.control.authorize';

    const PORTAL_VIEW = 'portal.task.view.authorize';
    const PORTAL_VIEW_SCOPE = 'portal.task.view.scope';
    const PORTAL_CONTROL = 'portal.task.control.authorize';

    /** The lifecycle actions the control gate is asked about. */
    const ACTIONS = ['stop', 'force_stop', 'force_kill', 'cancel', 'rerun'];

    /**
     * May the current viewer see this run - its status, reports, output and files?
     */
    public static function can_view(Task_Run_Model $task): bool
    {
        if (static::__developer()) {
            return true;
        }

        $event = Rsx_Portal::is_portal_request() ? self::PORTAL_VIEW : self::VIEW;
        if (!Event_Registry::has_handlers($event)) {
            return false;
        }

        return Rsx::trigger_gate($event, ['task' => $task, 'user' => static::__user()]) === true;
    }

    /**
     * Narrow a Task_Run_Model query to the runs the current viewer may see.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function scope_viewable($query)
    {
        if (static::__developer()) {
            return $query;
        }

        $event = Rsx_Portal::is_portal_request() ? self::PORTAL_VIEW_SCOPE : self::VIEW_SCOPE;
        if (!Event_Registry::has_handlers($event)) {
            return $query->whereRaw('0 = 1');
        }

        return Rsx::trigger_filter($event, $query);
    }

    /**
     * May the current viewer perform $action on this run?
     *
     * @param string $action One of ACTIONS
     */
    public static function can_control(Task_Run_Model $task, string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown task action '{$action}'; expected one of " . implode(', ', self::ACTIONS) . '.');
        }

        if (static::__developer()) {
            return true;
        }

        $event = Rsx_Portal::is_portal_request() ? self::PORTAL_CONTROL : self::CONTROL;
        if (!Event_Registry::has_handlers($event)) {
            return false;
        }

        return Rsx::trigger_gate($event, ['task' => $task, 'user' => static::__user(), 'action' => $action]) === true;
    }

    /**
     * May the current viewer subscribe to one run's frames? Its view gate decides; a run that
     * does not exist is refused.
     */
    public static function can_subscribe_to_task(int $task_id): bool
    {
        $task = $task_id > 0 ? Task_Run_Model::find($task_id) : null;

        return $task !== null && static::can_view($task);
    }

    /**
     * May the current viewer subscribe to the list topic? A frame there names no run, so the
     * question is only whether any run can be visible to this viewer at all: a developer, or a
     * signed-in viewer in a realm whose view scope has a handler.
     */
    public static function can_subscribe_to_list(): bool
    {
        if (static::__developer()) {
            return true;
        }

        if (Rsx_Portal::is_portal_request()) {
            return Portal_Session::is_logged_in() && Event_Registry::has_handlers(self::PORTAL_VIEW_SCOPE);
        }

        return Session::is_logged_in() && Event_Registry::has_handlers(self::VIEW_SCOPE);
    }

    private static function __developer(): bool
    {
        return !Rsx_Portal::is_portal_request() && Session::is_developer();
    }

    private static function __user()
    {
        return Rsx_Portal::is_portal_request() ? Portal_Session::get_portal_user() : Session::get_user();
    }
}
