<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Changed_Topic;
use App\RSpade\Core\Task\Task_Gates;
use App\RSpade\Core\Task\Task_List_Changed_Topic;
use App\RSpade\Core\Task\Task_Output_Topic;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Task_Gates - who may see a run and who may act on one. DENY BY DEFAULT in both realms:
 * with no handler for a gate, nobody passes it; a developer (staff realm only) passes every
 * gate without a handler being asked; with handlers, the gate is theirs.
 *
 * Handlers are stood in through the Event_Registry test seam (_set_test_handlers), which also
 * pins "no handler" whatever the installed application declares. The portal realm is the
 * Rsx_Portal::set_portal_request() seam. Rows roll back with the per-test transaction.
 */
class Task_Gates_Test extends Rsx_Test_Abstract
{
    private const STAFF_EVENTS = [Task_Gates::VIEW, Task_Gates::VIEW_SCOPE, Task_Gates::CONTROL];
    private const PORTAL_EVENTS = [Task_Gates::PORTAL_VIEW, Task_Gates::PORTAL_VIEW_SCOPE, Task_Gates::PORTAL_CONTROL];

    public static function setup()
    {
        static::__no_handlers();
        static::__reset_session();
        Rsx_Portal::set_portal_request(false);
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
        Rsx_Portal::set_portal_request(false);
        static::__reset_session();
    }

    private static function __no_handlers(): void
    {
        foreach (array_merge(self::STAFF_EVENTS, self::PORTAL_EVENTS) as $event) {
            Event_Registry::_set_test_handlers($event, []);
        }
    }

    private static function __run(): Task_Run_Model
    {
        return Task_Run_Model::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED));
    }

    private static function __visible_ids(): array
    {
        return Task_Gates::scope_viewable(Task_Run_Model::query())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private static function __assert_all_denied(Task_Run_Model $run, string $realm): void
    {
        static::__assert_false(Task_Gates::can_view($run), "{$realm}: view denied");
        foreach (Task_Gates::ACTIONS as $action) {
            static::__assert_false(Task_Gates::can_control($run, $action), "{$realm}: {$action} denied");
        }
        static::__assert_equals([], static::__visible_ids(), "{$realm}: the list scope shows nothing");
        static::__assert_false(Task_Gates::can_subscribe_to_task((int) $run->id), "{$realm}: no subscription to a run");
        static::__assert_false(Task_Gates::can_subscribe_to_list(), "{$realm}: no subscription to the list");
    }

    // -------------------------------------------------------------------------
    // Deny by default
    // -------------------------------------------------------------------------

    public static function test_no_handler_denies_everything_in_the_staff_realm()
    {
        static::__assert_all_denied(static::__run(), 'staff, signed out');
    }

    public static function test_no_handler_denies_everything_in_the_portal_realm()
    {
        $run = static::__run();
        Rsx_Portal::set_portal_request(true);

        static::__assert_all_denied($run, 'portal');
    }

    // -------------------------------------------------------------------------
    // The developer bypass
    // -------------------------------------------------------------------------

    public static function test_a_developer_passes_every_staff_gate_without_a_handler()
    {
        $run = static::__run();
        static::__acting_as_user(1);
        try {
            static::__assert_developer_passes($run);
        } finally {
            static::__reset_session();
        }
    }

    private static function __assert_developer_passes(Task_Run_Model $run): void
    {
        static::__assert_true(Session::is_developer(), 'fixture: user 1 is a developer');

        static::__assert_true(Task_Gates::can_view($run));
        foreach (Task_Gates::ACTIONS as $action) {
            static::__assert_true(Task_Gates::can_control($run, $action), "{$action} allowed");
        }
        static::__assert_true(in_array((int) $run->id, static::__visible_ids(), true), 'the list scope is unnarrowed');
        static::__assert_true(Task_Gates::can_subscribe_to_task((int) $run->id));
        static::__assert_true(Task_Gates::can_subscribe_to_list());

        static::__assert_true(Task_Changed_Topic::can_subscribe(['id' => $run->id]), 'the run topic asks the view gate');
        static::__assert_true(Task_Output_Topic::can_subscribe(['id' => $run->id]), 'so does the output topic');
        static::__assert_true(Task_List_Changed_Topic::can_subscribe([]), 'and the list topic');
        static::__assert_false(Task_Changed_Topic::can_subscribe(['id' => 2147480000]), 'a run that does not exist is refused');
    }

    public static function test_the_developer_bypass_is_staff_only()
    {
        $run = static::__run();
        static::__acting_as_user(1);
        Rsx_Portal::set_portal_request(true);

        try {
            static::__assert_false(Task_Gates::can_view($run), 'a portal request is never a developer\'s');
            static::__assert_false(Task_Gates::can_control($run, 'stop'));
        } finally {
            static::__reset_session();
        }
    }

    // -------------------------------------------------------------------------
    // Handlers decide
    // -------------------------------------------------------------------------

    public static function test_view_and_control_handlers_decide_and_see_the_run_user_and_action()
    {
        $run = static::__run();
        $seen = [];

        Event_Registry::_set_test_handlers(Task_Gates::VIEW, [function ($data) use (&$seen) {
            $seen[] = ['view', (int) $data['task']->id, $data['user']];

            return true;
        }]);
        Event_Registry::_set_test_handlers(Task_Gates::CONTROL, [function ($data) use (&$seen) {
            $seen[] = ['control', (int) $data['task']->id, $data['action']];

            return $data['action'] === 'stop' ? true : 'not you';
        }]);

        static::__assert_true(Task_Gates::can_view($run));
        static::__assert_true(Task_Gates::can_control($run, 'stop'));
        static::__assert_false(Task_Gates::can_control($run, 'rerun'), 'a non-true answer denies');
        static::__assert_equals(
            [['view', (int) $run->id, null], ['control', (int) $run->id, 'stop'], ['control', (int) $run->id, 'rerun']],
            $seen,
            'each handler is handed the run, the realm\'s user and the action'
        );

        static::__assert_throws(\InvalidArgumentException::class, fn () => Task_Gates::can_control($run, 'delete'), "Unknown task action 'delete'");
    }

    public static function test_the_portal_realm_asks_the_portal_handlers()
    {
        $run = static::__run();
        Event_Registry::_set_test_handlers(Task_Gates::VIEW, [fn ($data) => true]);
        Rsx_Portal::set_portal_request(true);

        static::__assert_false(Task_Gates::can_view($run), 'a staff handler answers nothing on the portal');

        Event_Registry::_set_test_handlers(Task_Gates::PORTAL_VIEW, [fn ($data) => true]);
        static::__assert_true(Task_Gates::can_view($run));
    }

    public static function test_the_view_scope_handler_narrows_the_list()
    {
        $shown = static::__run();
        static::__run();

        Event_Registry::_set_test_handlers(Task_Gates::VIEW_SCOPE, [fn ($query) => $query->where('id', $shown->id)]);

        static::__assert_equals([(int) $shown->id], static::__visible_ids());
        static::__assert_false(Task_Gates::can_subscribe_to_list(), 'a list subscription still needs a signed-in viewer');
    }
}
