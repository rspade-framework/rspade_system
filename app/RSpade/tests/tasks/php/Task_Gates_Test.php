<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Rsx_Task_Controller;
use App\RSpade\Core\Task\Task_Changed_Topic;
use App\RSpade\Core\Task\Task_Gates;
use App\RSpade\Core\Task\Task_List_Changed_Topic;
use App\RSpade\Core\Task\Task_Output_Topic;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Access_Fixture_Service;

/**
 * Task_Gates - who may see a run and who may act on one through the browser task API, decided
 * PER TASK TYPE by #[Task_Access] (Task_Access_Fixture_Service declares each shape).
 *
 * Deny by default: a task with no #[Task_Access] is a developer's only; a signed-in
 * non-developer asking about one gets the MARKED error naming the task, and a caller who is
 * not signed in the ordinary not-found. The built-in 'dispatcher' admits the dispatching
 * identity to view, stop and cancel. A custom check is asked with the run, the realm's user
 * and the action. The realm argument decides which realm may ask at all. Listing walks the
 * runs until the page is full.
 *
 * Identities are created per test (rolled back with the per-test transaction); user 1 is the
 * baseline developer. The portal realm is the Rsx_Portal::set_portal_request() seam.
 */
class Task_Gates_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function teardown()
    {
        static::__fresh();
    }

    /** Every test starts here: setup()/teardown() are per CLASS, and the fixture is static. */
    private static function __fresh(): void
    {
        Task_Access_Fixture_Service::reset();
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        static::__reset_session();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** A non-developer staff user on SITE_ID; its id. */
    private static function __staff_user(): int
    {
        static::__acting_as_site(self::SITE_ID);

        $login_user = new Login_User_Model();
        $login_user->email = 'task_gates_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->first_name = 'Task';
        $user->last_name = 'Gates';
        $user->email = $login_user->email;
        $user->role_id = static::most_privileged_role_id();
        $user->is_enabled = true;
        $user->save();

        static::__reset_session();

        return (int) $user->id;
    }

    /** A portal user on SITE_ID; its id. */
    private static function __portal_user(): int
    {
        static::__acting_as_site(self::SITE_ID);

        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'task_gates_portal_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        static::__reset_session();

        return (int) $user->id;
    }

    private static function __as_portal_user(int $portal_user_id): void
    {
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    /** A run of a fixture task, dispatched by whoever is acting now (nobody by default). */
    private static function __run(string $method): Task_Run_Model
    {
        return Task_Run_Model::find(Task_Runner::insert_row(Task_Access_Fixture_Service::class, $method, [], Task_Run_Model::ORIGIN_DISPATCHED));
    }

    /** A run of $method dispatched by staff user $user_id; the session is reset after. */
    private static function __run_by(string $method, int $user_id): Task_Run_Model
    {
        static::__acting_as_user($user_id);
        $run = static::__run($method);
        static::__reset_session();

        return $run;
    }

    private static function __get_status(Task_Run_Model $run)
    {
        return Rsx_Task_Controller::get(Request::create('/'), ['task_id' => $run->id]);
    }

    private static function __assert_not_found($response, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $response, $message);
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $response->get_error_code(), $message);
        static::__assert_false(isset($response->get_metadata()['task_access']), "{$message}: no task named");
    }

    private static function __assert_marked($response, string $verdict, string $task_name, string $message): void
    {
        static::__assert_instance_of(Error_Response::class, $response, $message);
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $response->get_error_code(), $message);
        $metadata = $response->get_metadata();
        static::__assert_equals($verdict, $metadata['task_access'] ?? null, "{$message}: the marker");
        static::__assert_equals($task_name, $metadata['task'] ?? null, "{$message}: the task");
        static::__assert_contains($task_name, $response->get_reason(), "{$message}: the message names the task");
        static::__assert_contains('#[Task_Access', $response->get_reason(), "{$message}: the message names the remedy");
        static::__assert_contains(Task_Gates::SKILL, $response->get_reason(), "{$message}: and the skill");
    }

    private static function __page_ids(array $filter, ?string $cursor, int $page_size): array
    {
        $page = Task_Gates::visible_page($filter, $cursor, $page_size);

        return [array_map(fn ($run) => (int) $run->id, $page['tasks']), $page['next_cursor']];
    }

    // -------------------------------------------------------------------------
    // Undeclared: developers only, a marked error for everyone else signed in
    // -------------------------------------------------------------------------

    public static function test_an_undeclared_task_is_a_developers_and_the_topics_ask_the_same()
    {
        static::__fresh();
        $run = static::__run('undeclared');
        static::__acting_as_user(1);

        static::__assert_true(Session::is_developer(), 'fixture: user 1 is a developer');
        static::__assert_true(Task_Gates::can_view($run));
        foreach (Task_Gates::ACTIONS as $action) {
            static::__assert_true(Task_Gates::can_control($run, $action), "a developer may {$action}");
        }
        static::__assert_equals([], Task_Access_Fixture_Service::$asked, 'no check is asked for a developer');
        static::__assert_true(is_array(static::__get_status($run)), 'the endpoint answers the developer');
        static::__assert_true(Task_Changed_Topic::can_subscribe(['id' => $run->id]));
        static::__assert_true(Task_Output_Topic::can_subscribe(['id' => $run->id]));
        static::__assert_true(Task_List_Changed_Topic::can_subscribe(['class' => 'Task_Access_Fixture_Service', 'method' => 'undeclared']));
        static::__assert_false(Task_Changed_Topic::can_subscribe(['id' => 2147480000]), 'a run that does not exist is refused');

        [$ids] = static::__page_ids(['ids' => [$run->id]], null, 10);
        static::__assert_equals([(int) $run->id], $ids, 'a developer lists every run');
    }

    public static function test_an_undeclared_task_refuses_a_signed_in_user_by_name()
    {
        static::__fresh();
        $run = static::__run('undeclared');
        static::__acting_as_user(static::__staff_user());

        static::__assert_equals(Task_Gates::UNDECLARED, Task_Gates::decide($run, Task_Gates::VIEW));
        static::__assert_false(Task_Gates::can_view($run));
        static::__assert_marked(static::__get_status($run), Task_Gates::UNDECLARED, 'Task_Access_Fixture_Service::undeclared', 'get');
        static::__assert_marked(
            Rsx_Task_Controller::output(Request::create('/'), ['task_id' => $run->id]),
            Task_Gates::UNDECLARED,
            'Task_Access_Fixture_Service::undeclared',
            'output'
        );
        static::__assert_marked(
            Rsx_Task_Controller::stop(Request::create('/'), ['task_id' => $run->id]),
            Task_Gates::UNDECLARED,
            'Task_Access_Fixture_Service::undeclared',
            'stop'
        );
        static::__assert_marked(
            Rsx_Task_Controller::find(Request::create('/'), ['filter' => ['class' => 'Task_Access_Fixture_Service', 'method' => 'undeclared']]),
            Task_Gates::UNDECLARED,
            'Task_Access_Fixture_Service::undeclared',
            'a list naming only that task'
        );

        static::__assert_false(Task_Changed_Topic::can_subscribe(['id' => $run->id]), 'no subscription to the run');
        static::__assert_false(
            Task_List_Changed_Topic::can_subscribe(['class' => 'Task_Access_Fixture_Service', 'method' => 'undeclared']),
            'nor to that task\'s list frames'
        );

        [$ids] = static::__page_ids(['ids' => [$run->id]], null, 10);
        static::__assert_equals([], $ids, 'an unnamed list simply leaves it out');
    }

    public static function test_an_undeclared_task_is_plain_not_found_for_a_caller_not_signed_in()
    {
        static::__fresh();
        $run = static::__run('undeclared');

        static::__assert_equals(Task_Gates::DENIED, Task_Gates::decide($run, Task_Gates::VIEW));
        static::__assert_not_found(static::__get_status($run), 'staff, signed out');
        static::__assert_not_found(Rsx_Task_Controller::get(Request::create('/'), ['task_id' => 2147480000]), 'a missing run');
        static::__assert_false(Task_List_Changed_Topic::can_subscribe([]), 'no list subscription signed out');

        Rsx_Portal::set_portal_request(true);
        static::__assert_not_found(static::__get_status($run), 'portal, signed out');
    }

    // -------------------------------------------------------------------------
    // The built-in 'dispatcher'
    // -------------------------------------------------------------------------

    public static function test_the_dispatcher_may_view_stop_and_cancel_and_nothing_else()
    {
        static::__fresh();
        $owner = static::__staff_user();
        $run = static::__run_by('mine', $owner);

        static::__acting_as_user($owner);
        static::__assert_true(Task_Gates::can_view($run), 'view');
        static::__assert_true(Task_Gates::can_control($run, 'stop'), 'stop');
        static::__assert_true(Task_Gates::can_control($run, 'cancel'), 'cancel');
        foreach (['force_stop', 'force_kill', 'rerun'] as $action) {
            static::__assert_false(Task_Gates::can_control($run, $action), "{$action} is refused");
        }
        $status = static::__get_status($run);
        static::__assert_equals(['stop' => true, 'force_stop' => false, 'force_kill' => false, 'cancel' => true, 'rerun' => false], $status['can']);
        static::__assert_true(Task_List_Changed_Topic::can_subscribe(['class' => 'Task_Access_Fixture_Service', 'method' => 'mine']));
    }

    public static function test_the_dispatcher_check_refuses_everybody_else_as_not_found()
    {
        static::__fresh();
        $run = static::__run_by('mine', static::__staff_user());
        $nobody_run = static::__run('mine');

        static::__acting_as_user(static::__staff_user());
        static::__assert_equals(Task_Gates::DENIED, Task_Gates::decide($run, Task_Gates::VIEW));
        static::__assert_not_found(static::__get_status($run), 'another user\'s run');
        static::__assert_false(Task_Gates::can_view($nobody_run), 'a run nobody dispatched is nobody\'s');

        [$ids] = static::__page_ids(['ids' => [$run->id, $nobody_run->id]], null, 10);
        static::__assert_equals([], $ids, 'the list leaves out what the dispatcher check refuses');
    }

    // -------------------------------------------------------------------------
    // A custom check
    // -------------------------------------------------------------------------

    public static function test_a_custom_check_decides_and_is_handed_the_run_user_and_action()
    {
        static::__fresh();
        $run = static::__run('watched');
        $user_id = static::__staff_user();
        static::__acting_as_user($user_id);

        static::__assert_false(Task_Gates::can_view($run), 'not admitted yet');
        static::__assert_not_found(static::__get_status($run), 'a declared task the check refuses is not found');

        Task_Access_Fixture_Service::$visible_ids = [(int) $run->id];
        Task_Access_Fixture_Service::$allowed_actions = ['view', 'stop'];
        Task_Access_Fixture_Service::$asked = [];

        static::__assert_true(Task_Gates::can_view($run));
        static::__assert_true(Task_Gates::can_control($run, 'stop'));
        static::__assert_false(Task_Gates::can_control($run, 'rerun'));
        static::__assert_equals(
            [[(int) $run->id, 'User_Model', 'view'], [(int) $run->id, 'User_Model', 'stop'], [(int) $run->id, 'User_Model', 'rerun']],
            Task_Access_Fixture_Service::$asked,
            'each question hands the check the run, the realm\'s user and the action'
        );

        static::__assert_throws(\InvalidArgumentException::class, fn () => Task_Gates::can_control($run, 'delete'), "Unknown task action 'delete'");
    }

    public static function test_a_run_of_another_site_is_refused_before_the_check()
    {
        static::__fresh();
        $id = (int) static::__run('watched')->id;
        DB::table('_tasks')->where('id', $id)->update(['site_id' => self::SITE_ID + 1000]);
        $run = Task_Run_Model::find($id);
        Task_Access_Fixture_Service::$visible_ids = [(int) $run->id];

        static::__acting_as_user(static::__staff_user());
        static::__assert_false(Task_Gates::can_view($run));
        static::__assert_equals([], Task_Access_Fixture_Service::$asked, 'the check is never asked about another site\'s run');
    }

    // -------------------------------------------------------------------------
    // The realm argument
    // -------------------------------------------------------------------------

    public static function test_the_realm_argument_decides_which_realm_may_ask()
    {
        static::__fresh();
        $portal_user = static::__portal_user();
        static::__as_portal_user($portal_user);
        $portal_run = static::__run('portal_mine');
        $staff_run = static::__run('watched');
        $any_run = static::__run('watched_anywhere');
        Task_Access_Fixture_Service::$visible_ids = [(int) $staff_run->id, (int) $any_run->id];

        static::__assert_true(Task_Gates::can_view($portal_run), 'the portal dispatcher sees their run');
        static::__assert_true(Task_Gates::can_control($portal_run, 'cancel'));
        static::__assert_equals(Task_Gates::WRONG_REALM, Task_Gates::decide($staff_run, Task_Gates::VIEW), 'a staff-realm task refuses the portal');
        static::__assert_marked(static::__get_status($staff_run), Task_Gates::WRONG_REALM, 'Task_Access_Fixture_Service::watched', 'portal asking a staff task');
        static::__assert_true(Task_Gates::can_view($any_run), "realm 'any' admits the portal");
        static::__assert_equals('Portal_User_Model', Task_Access_Fixture_Service::$asked[0][1] ?? null, 'the portal user is handed to the check');

        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_user(static::__staff_user());
        static::__assert_equals(Task_Gates::WRONG_REALM, Task_Gates::decide($portal_run, Task_Gates::VIEW), 'a portal task refuses staff');
        static::__assert_true(Task_Gates::can_view($any_run), "realm 'any' admits staff");
    }

    public static function test_the_developer_bypass_is_staff_only()
    {
        static::__fresh();
        $run = static::__run('undeclared');
        static::__acting_as_user(1);
        Rsx_Portal::set_portal_request(true);

        static::__assert_false(Task_Gates::can_view($run), 'a portal request is never a developer\'s');
        static::__assert_false(Task_Gates::is_developer());
    }

    // -------------------------------------------------------------------------
    // Listing walks until the page is full
    // -------------------------------------------------------------------------

    public static function test_a_page_is_filled_past_invisible_rows_and_the_cursor_follows_the_rows_returned()
    {
        static::__fresh();
        $user_id = static::__staff_user();
        $runs = [];
        foreach (range(1, 6) as $i) {
            $runs[] = static::__run('watched');
        }
        $own = static::__run_by('mine', $user_id);
        $others = static::__run_by('mine', static::__staff_user());
        $undeclared = static::__run('undeclared');

        // Visible: runs[0], runs[2] (custom) and $own (dispatcher); every other row is invisible.
        Task_Access_Fixture_Service::$visible_ids = [(int) $runs[0]->id, (int) $runs[2]->id];
        $all_ids = array_merge(array_map(fn ($run) => (int) $run->id, $runs), [(int) $own->id, (int) $others->id, (int) $undeclared->id]);

        static::__acting_as_user($user_id);

        [$first, $cursor] = static::__page_ids(['ids' => $all_ids], null, 2);
        static::__assert_equals([(int) $own->id, (int) $runs[2]->id], $first, 'newest first, skipping the invisible rows between them');
        static::__assert_equals((string) $runs[2]->id, $cursor, 'the cursor is the last row returned');

        [$second, $cursor] = static::__page_ids(['ids' => $all_ids], $cursor, 2);
        static::__assert_equals([(int) $runs[0]->id], $second, 'the next page walks on from the cursor');
        static::__assert_null($cursor, 'and says there is no more');

        $asked_ids = array_unique(array_column(Task_Access_Fixture_Service::$asked, 0));
        static::__assert_false(in_array((int) $others->id, $asked_ids, true), 'dispatcher runs are filtered in SQL, never asked about');
        static::__assert_false(in_array((int) $undeclared->id, $asked_ids, true), 'undeclared runs are never read');

        $found = Rsx_Task_Controller::find(Request::create('/'), ['filter' => ['ids' => $all_ids]]);
        static::__assert_equals([(int) $own->id, (int) $runs[2]->id, (int) $runs[0]->id], array_map(fn ($status) => (int) $status['id'], $found['tasks']), 'find() answers the same set');

        $paged = Rsx_Task_Controller::page(Request::create('/'), ['filter' => ['ids' => $all_ids], 'page_size' => 2]);
        static::__assert_equals((string) $runs[2]->id, $paged['next_cursor'], 'page() carries the same cursor');
    }
}
