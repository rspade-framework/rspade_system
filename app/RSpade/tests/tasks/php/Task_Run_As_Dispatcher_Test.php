<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\CodeQuality\Rules\PHP\TaskRunAsDispatcher_CodeQualityRule;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * #[Run_As_Dispatcher]: a dispatched task runs as the person who dispatched it.
 *
 * A worker executing such a run puts the dispatcher's identity, site, realm, impersonator and
 * acting context (the dispatch's audit session) back for the call and clears them after; a
 * task without the declaration still runs as nobody; a dispatcher who may no longer act fails
 * the run with nothing executed; a dispatch with nobody signed in is refused. Rows roll back
 * with the per-test transaction; the worker runs in-process.
 */
class Task_Run_As_Dispatcher_Test extends Rsx_Test_Abstract
{
    private const SERVICE = 'Task_Run_As_Fixture_Service';
    private const USER_ID = 1;

    private static function __clear_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
    }

    private static function __run_worker(): void
    {
        Artisan::call('rsx:task:worker');
    }

    public static function test_a_dispatch_with_nobody_signed_in_is_refused()
    {
        static::__reset_session();

        static::__assert_throws(\RuntimeException::class, function () {
            Task::dispatch(self::SERVICE, 'as_dispatcher');
        }, 'dispatched with nobody signed in');
    }

    public static function test_a_worker_runs_it_as_the_dispatcher_and_clears_the_context()
    {
        static::__clear_queue();
        static::__acting_as_user(self::USER_ID);
        $user = User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID));
        $dispatch_audit_session_id = Session::get_audit_session_id();

        $id = Task::dispatch(self::SERVICE, 'as_dispatcher');
        static::__assert_equals($dispatch_audit_session_id, (int) DB::table('_tasks')->where('id', $id)->value('audit_session_id'), 'the acting context is recorded at dispatch');

        static::__reset_session();
        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);

        $who = $run->state();
        static::__assert_false($who['portal']);
        static::__assert_equals((int) $user->login_user_id, (int) $who['login_user_id']);
        static::__assert_equals((int) $user->id, (int) $who['user_id']);
        static::__assert_equals((int) $user->site_id, (int) $who['site_id']);
        static::__assert_equals(['type' => 'User_Model', 'id' => (int) $user->id], $who['actor'], 'writes are attributed to the dispatcher');
        static::__assert_equals($dispatch_audit_session_id, (int) $who['audit_session_id'], 'and filed under the dispatching context');

        static::__assert_null(Session::get_login_user_id(), 'the worker is back to acting as nobody');
    }

    public static function test_a_task_without_the_declaration_still_runs_as_nobody()
    {
        static::__clear_queue();
        static::__acting_as_user(self::USER_ID);
        $id = Task::dispatch(self::SERVICE, 'as_nobody');

        static::__reset_session();
        static::__run_worker();

        $who = Task_Run_Model::find($id)->state();
        static::__assert_true($who['ran']);
        static::__assert_null($who['login_user_id']);
        static::__assert_null($who['actor']);
    }

    public static function test_a_disabled_dispatcher_fails_the_run_and_nothing_executes()
    {
        static::__clear_queue();
        static::__acting_as_user(self::USER_ID);
        $id = Task::dispatch(self::SERVICE, 'as_dispatcher');

        static::__reset_session();
        DB::table('users')->where('id', self::USER_ID)->update(['is_enabled' => 0]);
        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id);
        static::__assert_contains('may no longer act', (string) $run->error);
        static::__assert_empty($run->state() ?: [], 'the method never ran');
    }

    public static function test_the_impersonator_is_carried()
    {
        static::__clear_queue();
        static::__acting_as_user(self::USER_ID);
        $impersonator_login_user_id = (int) User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID))->login_user_id;
        Session::cli_set_impersonator_login_user_id($impersonator_login_user_id);

        $id = Task::dispatch(self::SERVICE, 'as_dispatcher');

        static::__reset_session();
        static::__run_worker();

        static::__assert_equals($impersonator_login_user_id, (int) Task_Run_Model::find($id)->state()['impersonator_login_user_id']);
    }

    public static function test_a_portal_dispatcher_runs_in_the_portal_realm()
    {
        static::__clear_queue();
        static::__reset_session();

        // The portal realm first: a site-scoped save stamps the realm's site.
        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(1);
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = 1;
        $portal_user->email = 'run_as_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Portal_Session::cli_set_portal_user_id((int) $portal_user->id);

        try {
            $id = Task::dispatch(self::SERVICE, 'as_dispatcher');
        } finally {
            Portal_Session::_clear_cli_identity();
            Rsx_Portal::set_portal_request(false);
        }

        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the run completed: ' . $run->error);

        $who = $run->state();
        static::__assert_true($who['portal']);
        static::__assert_equals((int) $portal_user->id, (int) $who['portal_user_id']);
        static::__assert_equals(1, (int) $who['site_id']);
        static::__assert_equals(['type' => 'Portal_User_Model', 'id' => (int) $portal_user->id], $who['actor']);
        static::__assert_false(Rsx_Portal::is_portal_request(), 'the realm is cleared after the run');
    }

    public static function test_an_inline_run_is_already_in_its_dispatchers_context()
    {
        static::__acting_as_user(self::USER_ID);

        $run = Task::internal(self::SERVICE, 'as_dispatcher');

        static::__assert_equals(self::USER_ID, (int) $run->state()['user_id']);
        static::__assert_equals(self::USER_ID, (int) Session::get_user_id(), 'the caller keeps its own context');
    }

    public static function test_the_build_rule_names_each_misplacement()
    {
        static::__assert_equals([], TaskRunAsDispatcher_CodeQualityRule::attribute_problems('ok', ['Task', 'Run_As_Dispatcher']));
        static::__assert_equals([], TaskRunAsDispatcher_CodeQualityRule::attribute_problems('none', ['Task', 'Schedule']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('bare', ['Run_As_Dispatcher']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('tick', ['Task', 'Run_As_Dispatcher', 'Schedule']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('cmd', ['Task', 'Run_As_Dispatcher', 'Command']));
    }
}
