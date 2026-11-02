<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Companion_Gates - who may watch a companion application, and who may act on one. The single
 * seam Rsx_Companion, its controller and its realtime topics ask.
 *
 * A companion's status, state document, instances and output are operator data, and pausing or
 * stopping one is an operator act, so the APPLICATION decides who may do either, through event
 * handlers in /rsx/handlers/ - and with no handler, NOBODY may (deny by default):
 *
 *   companion.view.authorize      (gate)   data {companion: name, user}
 *   companion.control.authorize   (gate)   data {companion: name, user, action}
 *
 * A handler returns true to allow; every handler must allow (Rsx::trigger_gate()). `action` is
 * one of ACTIONS. `user` is Session::get_user(), null when nobody is signed in (a command or a
 * task): a handler that answers for those callers too decides that itself.
 *
 * A DEVELOPER (Session::is_developer()) passes every gate without any handler being asked:
 * the /_sys panel shows and controls every companion.
 *
 * NO SCOPE HOOK. The task system has a list filter because a list of runs is an unbounded
 * query that cannot be asked about one row at a time. A list of companions is the handful of
 * names the build declared, so a list is each name the view gate allows - one rule, never two
 * that could disagree.
 *
 * STAFF ONLY. A portal request is refused by every gate: a companion is part of the operator's
 * installation, never a client's.
 */
class Companion_Gates
{
    const VIEW = 'companion.view.authorize';
    const CONTROL = 'companion.control.authorize';

    /** The operator verbs the control gate is asked about. */
    const ACTIONS = ['pause', 'resume', 'stop', 'start', 'restart'];

    /**
     * May the current viewer see this companion - its status, state, instances and output?
     * An undeclared name is never viewable.
     */
    public static function can_view(string $name): bool
    {
        if (!static::__is_declared($name) || Rsx_Portal::is_portal_request()) {
            return false;
        }

        if (Session::is_developer()) {
            return true;
        }

        if (!Event_Registry::has_handlers(self::VIEW)) {
            return false;
        }

        return Rsx::trigger_gate(self::VIEW, ['companion' => $name, 'user' => Session::get_user()]) === true;
    }

    /**
     * May the current viewer perform $action on this companion? Viewing is part of the
     * question: a companion the viewer cannot see cannot be acted on either.
     *
     * @param string $action One of ACTIONS
     */
    public static function can_control(string $name, string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown companion action '{$action}'; expected one of " . implode(', ', self::ACTIONS) . '.');
        }

        if (!static::can_view($name)) {
            return false;
        }

        if (Session::is_developer()) {
            return true;
        }

        if (!Event_Registry::has_handlers(self::CONTROL)) {
            return false;
        }

        return Rsx::trigger_gate(self::CONTROL, ['companion' => $name, 'user' => Session::get_user(), 'action' => $action]) === true;
    }

    /**
     * The declared names the current viewer may see, sorted.
     *
     * @return array<int, string>
     */
    public static function viewable_names(): array
    {
        return array_values(array_filter(array_keys(Manifest::get_companions()), fn ($name) => static::can_view($name)));
    }

    /**
     * May the current viewer subscribe to a companion topic with this filter? {name} asks that
     * companion's view gate. An empty filter hears every companion, so it needs every one of
     * them to be viewable (and at least one to exist).
     */
    public static function can_subscribe(array $filter): bool
    {
        if (isset($filter['name'])) {
            return is_string($filter['name']) && static::can_view($filter['name']);
        }

        if ($filter !== []) {
            return false;
        }

        // Every companion, or none declared at all: an empty install leaks nothing, and the
        // panel's list subscribes with this filter whether or not a companion exists.
        $declared = array_keys(Manifest::get_companions());

        return count(static::viewable_names()) === count($declared);
    }

    private static function __is_declared(string $name): bool
    {
        return isset(Manifest::get_companions()[$name]);
    }
}
