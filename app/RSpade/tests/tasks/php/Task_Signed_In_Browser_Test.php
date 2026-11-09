<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The harness's signed-in browser: __as_signed_in_browser() / __as_signed_in_portal_browser()
 * and __run_pending_task().
 *
 * An endpoint called under the helper dispatches a #[Run_As_Dispatcher] task bound to the
 * helper's sign-in (the run stays PENDING under the suite) and answers its id;
 * __run_pending_task() executes that run as a worker would, as the dispatcher - identity,
 * browser, user session and audit session - and gives the test back its own context. The
 * runner's reset between tests leaves no sign-in behind (the declared identity stays - it is the
 * class's, as __acting_as_user()'s is).
 */
class Task_Signed_In_Browser_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    /** A portal user on site 1, saved as the portal realm would (the fixture leaves the process blank). */
    private static function __portal_user(): Portal_User_Model
    {
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(1);

        try {
            $portal_user = new Portal_User_Model();
            $portal_user->site_id = 1;
            $portal_user->email = 'signed_in_' . uniqid() . '@example.com';
            $portal_user->set_password('secret-password');
            $portal_user->is_verified = true;
            $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
            $portal_user->save();
        } finally {
            Session::_end_virtual();
        }

        return $portal_user;
    }

    private static function __user(): User_Model
    {
        return User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID));
    }

    public static function test_the_helper_signs_the_test_in_on_a_browser()
    {
        $user = static::__user();
        $browser_id = static::__as_signed_in_browser(self::USER_ID);

        static::__assert_equals($browser_id, Session::get_session_id(), 'the browser');
        static::__assert_true(Session::has_session(), 'a browser is present');
        static::__assert_equals((int) $user->id, Session::get_user_id(), 'the member');
        static::__assert_equals((int) $user->login_user_id, Session::get_login_user_id(), 'the identity');
        static::__assert_equals((int) $user->site_id, (int) Session::get_site_id(), 'the site');

        $user_session = DB::table('_user_sessions')->where('id', Session::get_user_session_id())->first();
        static::__assert_equals($browser_id, (int) $user_session->session_id, 'the sign-in is on that browser');
        static::__assert_equals((int) $user->login_user_id, (int) $user_session->login_user_id, 'the sign-in is the member');
        static::__assert_equals($user_session->id, DB::table('_sessions')->where('id', $browser_id)->value('staff_user_session_id'), 'the browser points at it');

        $audit = Session::get_audit_session(Session::get_audit_session_id());
        static::__assert_equals((int) $user_session->id, (int) $audit['user_session_id'], 'the audit session is the sign-in');
    }

    public static function test_an_endpoint_dispatches_bound_to_the_sign_in_and_the_run_executes_as_the_dispatcher()
    {
        $user = static::__user();
        $browser_id = static::__as_signed_in_browser(self::USER_ID);
        $user_session_id = Session::get_user_session_id();
        $audit_session_id = Session::get_audit_session_id();

        $answer = Ajax::internal('Task_Signed_In_Browser_Fixture_Controller', 'start', ['tag' => 'endpoint']);
        $task_id = (int) $answer['task_id'];

        $run = Task_Run_Model::find($task_id);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $run->status_id, 'the dispatch stays pending under the suite');
        static::__assert_equals($user_session_id, (int) $run->user_session_id, 'bound to the sign-in');
        static::__assert_equals($audit_session_id, (int) $run->audit_session_id, 'the dispatching audit session');
        static::__assert_equals((int) $user->id, (int) $run->dispatched_by_id, 'dispatched by the member');
        static::__assert_equals(['tag' => 'endpoint'], $run->params, 'the endpoint passed its params');

        $settled = static::__run_pending_task($task_id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $settled->status_id, 'the run completed: ' . (string) $settled->error);

        $state = $settled->state();
        static::__assert_equals((int) $user->id, $state['user_id'], 'ran as the member');
        static::__assert_equals((int) $user->login_user_id, $state['login_user_id'], 'ran as the identity');
        static::__assert_equals($browser_id, $state['session_id'], 'on the dispatching browser');
        static::__assert_equals($user_session_id, $state['user_session_id'], 'bound to the sign-in');
        static::__assert_equals($audit_session_id, $state['audit_session_id'], 'recording the dispatching audit session');

        static::__assert_equals($user_session_id, Session::get_user_session_id(), 'the test is given its own context back');
    }

    public static function test_an_impersonated_sign_in_carries_its_impersonator()
    {
        $impersonator = (int) static::__user()->login_user_id;
        static::__as_signed_in_browser(self::USER_ID, ['impersonator_login_user_id' => $impersonator]);

        static::__assert_equals($impersonator, Session::get_impersonator_login_user_id(), 'the test acts under the impersonation');

        $settled = static::__run_pending_task(Task::dispatch('Task_Run_As_Fixture_Service', 'as_dispatcher'));
        static::__assert_equals($impersonator, $settled->state()['impersonator_login_user_id'], 'the run carries it');
    }

    public static function test_a_portal_sign_in_dispatches_a_portal_run()
    {
        $portal_user = static::__portal_user();

        static::__as_signed_in_portal_browser((int) $portal_user->id);
        $settled = static::__run_pending_task(Task::dispatch('Task_Run_As_Fixture_Service', 'as_dispatcher'));

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $settled->status_id, 'the run completed: ' . (string) $settled->error);
        static::__assert_true($settled->state()['portal'], 'in the portal realm');
        static::__assert_equals((int) $portal_user->id, $settled->state()['portal_user_id'], 'as the portal user');
    }

    public static function test_the_runner_reset_leaves_no_sign_in()
    {
        static::__as_signed_in_browser(self::USER_ID, ['impersonator_login_user_id' => (int) static::__user()->login_user_id]);

        Session::_testing_reset();

        static::__assert_equals(null, Session::get_session_id(), 'no browser');
        static::__assert_equals(null, Session::get_user_session_id(), 'no sign-in');
        static::__assert_equals(null, Session::get_audit_session_id(), 'no audit session');
        static::__assert_equals(null, Session::get_impersonator_login_user_id(), 'no impersonator');
    }
}
