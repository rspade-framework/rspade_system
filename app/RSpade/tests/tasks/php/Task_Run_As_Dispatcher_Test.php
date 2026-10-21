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
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Tasks\Php\Task_Run_As_Fixture_Service;

/**
 * #[Run_As_Dispatcher]: a dispatched task runs bound to the sign-in that dispatched it.
 *
 * Dispatch stamps the requester's user session, audit session and claim tier. A worker runs
 * the task in a FROZEN virtual session built from that user session - its identity, site,
 * realm and impersonator, the browser's id, the audit session and the user's timezone - even
 * after that browser signed out and another account signed in on it; changing identity or
 * reaching session values throws. A dispatcher who may no longer act (membership or site
 * disabled, login identity deleted, portal user disabled, the user session gone) fails the run
 * with nothing executed. Every worker run ends blank, and the worker claims by tier then age.
 *
 * INLINE (Task::internal(), __run_task_method()) the run executes in its caller's context and
 * keeps it: a sign-in, or an identity the caller declared on its virtual session (staff, or a
 * portal user in the portal realm) - the run acts as it and dispatched_by names it. With
 * nobody acting an inline run is refused, naming the command-line flags; a DISPATCHED run
 * still needs a sign-in, a declared identity notwithstanding. rsx:task:run --user/--site is
 * Task_Run_As_Dispatcher_Cli_Test.
 *
 * The test process runs a virtual session and cannot sign in through the web path, so a
 * "signed-in request" is a browser fixture with a user session on it
 * (__insert_session_fixture()) and a virtual session BOUND to it (Session::_begin_virtual())
 * for the dispatch - the context the web request would have had. Rows roll back with the
 * per-test transaction; the worker runs in-process.
 */
class Task_Run_As_Dispatcher_Test extends Rsx_Test_Abstract
{
    private const SERVICE = 'Task_Run_As_Fixture_Service';
    private const USER_ID = 1;
    private const TIMEZONE = 'Pacific/Chatham';

    private static function __clear_queue(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
        Task_Run_As_Fixture_Service::$run_order = [];
    }

    private static function __run_worker(string $pool = Task_Pool::ON_DEMAND): void
    {
        Artisan::call('rsx:task:worker', ['--pool' => $pool]);
    }

    private static function __user(): User_Model
    {
        return User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID));
    }

    /**
     * A browser with user 1 signed in on it (optionally under an impersonator), and the audit
     * session a request from it would have recorded.
     *
     * @return array{browser: int, user_session: int, audit_session: int}
     */
    private static function __signed_in_browser(?int $impersonator_login_user_id = null): array
    {
        $user = static::__user();
        $fixture = static::__insert_session_fixture([
            'staff' => [
                'login_user_id' => (int) $user->login_user_id,
                'site_id' => (int) $user->site_id,
                'impersonator_login_user_id' => $impersonator_login_user_id,
            ],
        ]);

        $audit_session_id = Audit_Session::_id_for_context([
            'site_id' => (int) $user->site_id,
            'login_user_id' => (int) $user->login_user_id,
            'portal_user_id' => null,
            'portal_site_id' => null,
            'impersonator_login_user_id' => $impersonator_login_user_id,
            'impersonator_user_id' => null,
            'ip_address' => '10.0.0.1',
            'session_id' => $fixture['id'],
            'user_session_id' => $fixture['staff_user_session_id'],
            'user_agent_id' => Audit_Session::user_agent_id('dispatching-browser/1.0'),
            'session_type_id' => Session::TYPE_WEB,
        ]);

        return ['browser' => $fixture['id'], 'user_session' => $fixture['staff_user_session_id'], 'audit_session' => $audit_session_id];
    }

    /**
     * Dispatch from the request a signed-in browser makes: a virtual session bound to its user
     * session for the call, ended after.
     */
    private static function __dispatch_from(array $request, array $identity, string $method = 'as_dispatcher', array $params = []): int
    {
        Session::_begin_virtual($identity + [
            'session_id' => $request['browser'],
            'user_session_id' => $request['user_session'],
            'audit_session_id' => $request['audit_session'] ?? null,
        ]);

        try {
            return Task::dispatch(self::SERVICE, $method, $params);
        } finally {
            Session::_end_virtual();
        }
    }

    private static function __staff_identity(?int $impersonator_login_user_id = null): array
    {
        $user = static::__user();

        return [
            'realm' => 'staff',
            'login_user_id' => (int) $user->login_user_id,
            'site_id' => (int) $user->site_id,
            'user_id' => (int) $user->id,
            'impersonator_login_user_id' => $impersonator_login_user_id,
        ];
    }

    private static function __row(int $id): object
    {
        return DB::table('_tasks')->where('id', $id)->first();
    }

    public static function teardown()
    {
        Session::_end_virtual();
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    public static function test_a_dispatch_with_nobody_signed_in_is_refused()
    {
        static::__reset_session();

        static::__assert_throws(\RuntimeException::class, function () {
            Task::dispatch(self::SERVICE, 'as_dispatcher');
        }, 'can only be started from a signed-in request');
    }

    /**
     * A DISPATCHED run needs a sign-in even when an identity is declared: a worker is another
     * process and rebuilds the actor from the sign-in alone.
     */
    public static function test_a_dispatch_with_a_declared_identity_but_no_sign_in_is_refused()
    {
        static::__acting_as_user(self::USER_ID);

        static::__assert_throws(\RuntimeException::class, function () {
            Task::dispatch(self::SERVICE, 'as_dispatcher');
        }, 'can only be started from a signed-in request');
    }

    public static function test_dispatch_stamps_the_sign_in_the_audit_session_and_the_tier()
    {
        static::__clear_queue();
        $request = static::__signed_in_browser();

        $dispatcher = static::__row(static::__dispatch_from($request, static::__staff_identity()));
        static::__assert_equals($request['user_session'], (int) $dispatcher->user_session_id, 'the requester\'s user session');
        static::__assert_equals($request['audit_session'], (int) $dispatcher->audit_session_id, 'the requester\'s audit session');
        static::__assert_equals(Task_Runner::PRIORITY_RUN_AS_DISPATCHER, (int) $dispatcher->priority);

        $plain = static::__row(static::__dispatch_from($request, static::__staff_identity(), 'as_nobody'));
        static::__assert_equals($request['user_session'], (int) $plain->user_session_id, 'every dispatch records where it came from');
        static::__assert_equals(Task_Runner::PRIORITY_ON_DEMAND, (int) $plain->priority);

        $scheduled = static::__row(Task_Runner::insert_row(Task_Run_As_Fixture_Service::class, 'as_nobody', [], Task_Run_Model::ORIGIN_SCHEDULED));
        static::__assert_equals(Task_Runner::PRIORITY_SCHEDULED, (int) $scheduled->priority);
        static::__assert_null($scheduled->user_session_id, 'a scheduled run has no requester');
    }

    // -------------------------------------------------------------------------
    // The frozen, bound run
    // -------------------------------------------------------------------------

    /**
     * Dispatched by A; A signs out and B signs in on that browser; the run still executes as
     * A, on A's site, on that browser, under A's audit session, in A's timezone - and cannot
     * change any of it.
     */
    public static function test_the_run_is_bound_to_the_dispatching_sign_in()
    {
        static::__clear_queue();
        $user = static::__user();
        DB::table('login_users')->where('id', $user->login_user_id)->update(['timezone' => self::TIMEZONE]);
        $request = static::__signed_in_browser();

        $id = static::__dispatch_from($request, static::__staff_identity(), 'as_dispatcher', ['probe' => true]);

        // A signs out; B signs in on the same browser.
        Session::_sign_out_user_sessions([$request['user_session']], Session::SIGN_OUT_SIGNED_OUT, false);
        $b_identity = ['login_user_id' => 990002, 'site_id' => 1, 'impersonator_login_user_id' => null];
        $b = (int) DB::table('_user_sessions')->insertGetId($b_identity + [
            'session_id' => $request['browser'],
            'realm_id' => Session::REALM_STAFF,
            'tuple_hash' => Session::_user_session_tuple_hash($request['browser'], Session::REALM_STAFF, $b_identity),
            'first_signed_in_at' => now(),
            'signed_in_at' => now(),
            'started_at' => now(),
            'last_active_at' => now(),
        ]);
        DB::table('_sessions')->where('id', $request['browser'])->update(['staff_user_session_id' => $b]);

        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the run completed: ' . $run->error);

        $who = $run->state();
        static::__assert_false($who['portal']);
        static::__assert_equals((int) $user->login_user_id, (int) $who['login_user_id'], 'runs as A, not B');
        static::__assert_equals((int) $user->id, (int) $who['user_id']);
        static::__assert_equals((int) $user->site_id, (int) $who['site_id'], 'on A\'s site');
        static::__assert_equals($request['browser'], (int) $who['session_id'], 'get_session_id() is the dispatching browser');
        static::__assert_equals($request['user_session'], (int) $who['user_session_id'], 'get_user_session_id() is A\'s sign-in');
        static::__assert_equals(['type' => 'User_Model', 'id' => (int) $user->id], $who['actor'], 'writes are attributed to A');
        static::__assert_equals($request['audit_session'], (int) $who['audit_session_id'], 'and filed under A\'s audit session');
        static::__assert_equals(self::TIMEZONE, $who['timezone'], 'in A\'s timezone');

        foreach (['set_site_id', 'set_login_user_id', 'logout', 'get_value', 'put_value', 'portal_set_site_id'] as $call) {
            static::__assert_not_null($who['refused'][$call], "{$call}() throws in a run bound to its dispatcher");
            static::__assert_contains('Run_As_Dispatcher', $who['refused'][$call]);
        }

        static::__assert_null(Session::get_login_user_id(), 'the worker hands back the context it was called with');
        static::__assert_false(Session::_is_frozen());
    }

    public static function test_the_impersonator_is_carried()
    {
        static::__clear_queue();
        $impersonator_login_user_id = 990001;
        $request = static::__signed_in_browser($impersonator_login_user_id);

        $id = static::__dispatch_from($request, static::__staff_identity($impersonator_login_user_id));
        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the run completed: ' . $run->error);
        static::__assert_equals($impersonator_login_user_id, (int) $run->state()['impersonator_login_user_id']);
    }

    public static function test_a_portal_dispatcher_runs_in_the_portal_realm()
    {
        static::__clear_queue();
        $portal_user = static::__portal_user();
        $fixture = static::__insert_session_fixture(['portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => 1]]);

        $id = static::__dispatch_from(
            ['browser' => $fixture['id'], 'user_session' => $fixture['portal_user_session_id']],
            ['realm' => 'portal', 'portal_user_id' => (int) $portal_user->id, 'portal_site_id' => 1]
        );

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

    // -------------------------------------------------------------------------
    // Refusals - FAILED, nothing executed
    // -------------------------------------------------------------------------

    public static function test_a_disabled_membership_fails_the_run()
    {
        static::__assert_refused(fn () => DB::table('users')->where('id', self::USER_ID)->update(['is_enabled' => 0]), 'may no longer act');
    }

    public static function test_a_deleted_login_identity_fails_the_run()
    {
        static::__assert_refused(
            fn () => DB::table('login_users')->where('id', static::__user()->login_user_id)->update(['deleted_at' => now()]),
            'was deleted since'
        );
    }

    public static function test_a_missing_user_session_fails_the_run()
    {
        static::__assert_refused(fn (array $request) => DB::table('_user_sessions')->where('id', $request['user_session'])->delete(), 'no longer exists');
    }

    public static function test_a_disabled_portal_user_fails_the_run()
    {
        static::__clear_queue();
        $portal_user = static::__portal_user();
        $fixture = static::__insert_session_fixture(['portal' => ['portal_user_id' => (int) $portal_user->id, 'portal_site_id' => 1]]);

        $id = static::__dispatch_from(
            ['browser' => $fixture['id'], 'user_session' => $fixture['portal_user_session_id']],
            ['realm' => 'portal', 'portal_user_id' => (int) $portal_user->id, 'portal_site_id' => 1]
        );

        DB::table('portal_users')->where('id', $portal_user->id)->update(['status_id' => Portal_User_Model::STATUS_SUSPENDED]);
        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id);
        static::__assert_contains('disabled or deleted', (string) $run->error);
        static::__assert_empty($run->state() ?: [], 'the method never ran');
    }

    /** A signed-out sign-in is NOT a refusal: the run is the request it stood for. */
    public static function test_a_signed_out_sign_in_still_runs()
    {
        static::__clear_queue();
        $request = static::__signed_in_browser();
        $id = static::__dispatch_from($request, static::__staff_identity());

        Session::_sign_out_user_sessions([$request['user_session']], Session::SIGN_OUT_TIMED_OUT_IDLE, false);
        static::__run_worker();

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) Task_Run_Model::find($id)->status_id);
    }

    private static function __assert_refused(callable $after_dispatch, string $reason): void
    {
        static::__clear_queue();
        $request = static::__signed_in_browser();
        $id = static::__dispatch_from($request, static::__staff_identity());

        $after_dispatch($request);
        static::__run_worker();

        $run = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id);
        static::__assert_contains($reason, (string) $run->error);
        static::__assert_empty($run->state() ?: [], 'the method never ran');
        static::__assert_equals([], Task_Run_As_Fixture_Service::$run_order);
    }

    // -------------------------------------------------------------------------
    // Every run ends blank; Task::internal() keeps its caller's context
    // -------------------------------------------------------------------------

    /**
     * One worker, two runs: the dispatcher run first (its tier), then a plain run, which
     * sees nobody - no identity, browser, sign-in, audit session or realm, and the server's
     * timezone rather than the dispatcher's.
     */
    public static function test_nothing_one_run_was_reaches_the_next()
    {
        static::__clear_queue();
        DB::table('login_users')->where('id', static::__user()->login_user_id)->update(['timezone' => self::TIMEZONE]);
        $request = static::__signed_in_browser();

        $plain_id = Task::dispatch(self::SERVICE, 'as_nobody', ['tag' => 'plain']);
        static::__dispatch_from($request, static::__staff_identity());

        static::__run_worker();

        static::__assert_equals(['dispatcher', 'plain'], Task_Run_As_Fixture_Service::$run_order, 'the dispatcher run went first');

        $who = Task_Run_Model::find($plain_id)->state();
        static::__assert_null($who['login_user_id']);
        static::__assert_null($who['user_id']);
        static::__assert_equals(0, (int) $who['site_id']);
        static::__assert_null($who['actor']);
        static::__assert_null($who['session_id']);
        static::__assert_null($who['user_session_id']);
        static::__assert_null($who['audit_session_id']);
        static::__assert_false($who['portal']);
        static::__assert_equals(Rsx_Time::get_server_timezone(), $who['timezone'], 'the dispatcher\'s timezone did not leak');
    }

    public static function test_an_inline_run_executes_in_its_callers_context_and_restores_it()
    {
        $request = static::__signed_in_browser();
        Session::_begin_virtual(static::__staff_identity() + [
            'session_id' => $request['browser'],
            'user_session_id' => $request['user_session'],
            'audit_session_id' => $request['audit_session'],
        ]);

        try {
            $run = Task::internal(self::SERVICE, 'as_dispatcher');
            static::__assert_equals(self::USER_ID, (int) $run->state()['user_id'], 'runs as the caller');

            Task::internal(self::SERVICE, 'rewrites_context');

            static::__assert_equals((int) static::__user()->login_user_id, (int) Session::get_login_user_id(), 'the caller\'s identity is back');
            static::__assert_equals((int) static::__user()->site_id, Session::get_site_id(), 'its site');
            static::__assert_false(Session::has_temporary_site_id(), 'no declared tenant left behind');
            static::__assert_false(Rsx_Portal::is_portal_request(), 'its realm');
            static::__assert_equals($request['audit_session'], Session::get_audit_session_id(), 'its audit session');
            static::__assert_equals($request['user_session'], Session::get_user_session_id(), 'its sign-in');
        } finally {
            Session::_end_virtual();
        }
    }

    /**
     * A declared identity with no sign-in: the inline run acts as it - identity, membership,
     * site and attribution - with no browser, sign-in or audit session, and the row records
     * the actor and no user session.
     */
    public static function test_an_inline_run_acts_as_the_declared_identity()
    {
        $user = static::__user();
        static::__acting_as_user(self::USER_ID);

        $run = Task::internal(self::SERVICE, 'as_dispatcher');

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the run completed: ' . $run->error);
        $who = $run->state();
        static::__assert_equals((int) $user->login_user_id, (int) $who['login_user_id'], 'runs as the declared identity');
        static::__assert_equals((int) $user->id, (int) $who['user_id'], 'its membership');
        static::__assert_equals((int) $user->site_id, (int) $who['site_id'], 'on its site');
        static::__assert_equals(['type' => 'User_Model', 'id' => (int) $user->id], $who['actor'], 'writes are attributed to it');
        static::__assert_null($who['session_id'], 'no browser');
        static::__assert_null($who['user_session_id'], 'no sign-in');
        static::__assert_null($who['audit_session_id'], 'no audit session');

        $row = static::__row((int) $run->id);
        static::__assert_equals(Task_Run_Model::ORIGIN_INLINE, (int) $row->origin_id);
        static::__assert_equals((int) $user->id, (int) $row->dispatched_by_id, 'dispatched_by names the declared identity');
        static::__assert_equals(\App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'), (int) $row->dispatched_by_type);
        static::__assert_null($row->user_session_id);
        static::__assert_equals((int) $user->site_id, (int) $row->site_id);

        static::__assert_equals((int) $user->login_user_id, (int) Session::get_login_user_id(), 'the caller keeps its identity');
    }

    /** The test seam: __run_task_method() after __acting_as_user() runs as that user. */
    public static function test_the_test_harness_runs_it_as_the_acting_user()
    {
        static::__acting_as_user(self::USER_ID);

        $who = static::__run_task_method(Task_Run_As_Fixture_Service::class, 'as_dispatcher');

        static::__assert_equals(self::USER_ID, (int) $who['user_id']);
        static::__assert_equals(['type' => 'User_Model', 'id' => self::USER_ID], $who['actor']);
    }

    /** A portal user declared in the portal realm is an actor too. */
    public static function test_an_inline_run_acts_as_a_declared_portal_user()
    {
        $portal_user = static::__portal_user();

        Rsx_Portal::set_portal_request(true);
        \App\RSpade\Core\Portal\Portal_Session::set_site_id(1);
        \App\RSpade\Core\Portal\Portal_Session::set_portal_user_id((int) $portal_user->id);

        try {
            $run = Task::internal(self::SERVICE, 'as_dispatcher');
        } finally {
            Session::_end_virtual();
        }

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'the run completed: ' . $run->error);
        $who = $run->state();
        static::__assert_true($who['portal']);
        static::__assert_equals((int) $portal_user->id, (int) $who['portal_user_id']);
        static::__assert_equals(['type' => 'Portal_User_Model', 'id' => (int) $portal_user->id], $who['actor']);
        static::__assert_equals((int) $portal_user->id, (int) static::__row((int) $run->id)->dispatched_by_id);
    }

    public static function test_an_inline_run_with_nobody_acting_is_refused_naming_the_flags()
    {
        static::__reset_session();
        Task_Run_As_Fixture_Service::$run_order = [];
        $before = (int) DB::table('_tasks')->max('id');

        static::__assert_throws(\RuntimeException::class, function () {
            Task::internal(self::SERVICE, 'as_dispatcher');
        }, 'nobody is acting here');

        static::__assert_throws(\RuntimeException::class, function () {
            Task::internal(self::SERVICE, 'as_dispatcher');
        }, 'rsx:task:run Task_Run_As_Fixture_Service as_dispatcher --user=<id|email> --site=<id>');

        static::__assert_throws(\RuntimeException::class, function () {
            static::__run_task_method(Task_Run_As_Fixture_Service::class, 'as_dispatcher');
        }, 'nobody is acting here');

        static::__assert_equals($before, (int) DB::table('_tasks')->max('id'), 'no row was written');
        static::__assert_equals([], Task_Run_As_Fixture_Service::$run_order, 'nothing ran');
    }

    /**
     * A site alone is not an actor: a declared tenant with nobody acting on it is still
     * nobody.
     */
    public static function test_a_declared_site_alone_is_not_an_actor()
    {
        static::__reset_session();
        static::__acting_as_site(1);

        static::__assert_throws(\RuntimeException::class, function () {
            Task::internal(self::SERVICE, 'as_dispatcher');
        }, 'nobody is acting here');
    }

    // -------------------------------------------------------------------------
    // Claim order
    // -------------------------------------------------------------------------

    /**
     * A dispatcher run beats an older plain on-demand run, which beats a due schedule; within
     * a tier the oldest due run goes first, whatever its id.
     */
    public static function test_the_claim_order_is_tier_then_age()
    {
        static::__clear_queue();
        $request = static::__signed_in_browser();

        DB::table('_task_schedules')->insert([
            'class' => Task_Run_As_Fixture_Service::class,
            'method' => 'as_nobody',
            'cron_expression' => 'daily at 3am',
            'timezone' => 'UTC',
            'next_run_at' => date('Y-m-d H:i:s', time() - 600) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $second = Task::dispatch(self::SERVICE, 'as_nobody', ['tag' => 'second']);
        $first = Task::dispatch(self::SERVICE, 'as_nobody', ['tag' => 'first']);
        DB::table('_tasks')->where('id', $second)->update(['scheduled_for' => now()->subMinutes(2)->format('Y-m-d H:i:s.v')]);
        DB::table('_tasks')->where('id', $first)->update(['scheduled_for' => now()->subMinutes(3)->format('Y-m-d H:i:s.v')]);
        static::__dispatch_from($request, static::__staff_identity());

        static::__run_worker(Task_Pool::SCHEDULED);

        static::__assert_equals(['dispatcher', 'first', 'second', 'untagged'], Task_Run_As_Fixture_Service::$run_order);
    }

    public static function test_the_build_rule_names_each_misplacement()
    {
        static::__assert_equals([], TaskRunAsDispatcher_CodeQualityRule::attribute_problems('ok', ['Task', 'Run_As_Dispatcher']));
        static::__assert_equals([], TaskRunAsDispatcher_CodeQualityRule::attribute_problems('none', ['Task', 'Schedule']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('bare', ['Run_As_Dispatcher']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('tick', ['Task', 'Run_As_Dispatcher', 'Schedule']));
        static::__assert_count(1, TaskRunAsDispatcher_CodeQualityRule::attribute_problems('cmd', ['Task', 'Run_As_Dispatcher', 'Command']));
    }

    /** An active, verified portal user on site 1. */
    private static function __portal_user(): Portal_User_Model
    {
        Rsx_Portal::set_portal_request(true);
        \App\RSpade\Core\Portal\Portal_Session::set_site_id(1);

        try {
            $portal_user = new Portal_User_Model();
            $portal_user->site_id = 1;
            $portal_user->email = 'run_as_' . uniqid() . '@example.com';
            $portal_user->set_password('secret-password');
            $portal_user->is_verified = true;
            $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
            $portal_user->save();
        } finally {
            Session::_end_virtual();
        }

        return $portal_user;
    }
}
