<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Concurrency;

/**
 * Task_Dispatcher_Context - a task declared #[Run_As_Dispatcher] runs AS the request that
 * dispatched it: it is that request's Ajax endpoint, run asynchronously.
 *
 * Moving a person's request off the request thread changes WHEN it runs, never WHO did it.
 * Task_Runner::insert_row() binds the run to the dispatching request at dispatch: its USER
 * SESSION (_tasks.user_session_id - the sign-in on that browser, staff, portal or an API key's)
 * and its audit session. When a worker - or rsx:task:process --once - executes the run,
 * begin() loads that user session and starts a FROZEN, BOUND virtual session from it
 * (Session::_begin_virtual()) for pre_task() and the method:
 *
 *   - THE IDENTITY, THE SITE, THE REALM: the user session's - a staff sign-in's login identity,
 *     membership and site, a portal sign-in's portal user and site (portal realm), an API
 *     key's owner. So the session getters, Rsx_Model_Abstract::_resolve_context_actor() and
 *     the site scope answer as they did in the dispatching request - revisions, the
 *     created_by/updated_by pairs and an application's own log all name the dispatcher.
 *   - IMPERSONATION, FAITHFULLY: a user session's identity includes its impersonator, so a
 *     run dispatched under an impersonation runs as the impersonated account with the
 *     impersonator declared, as the request did.
 *   - THE BROWSER: Session::get_session_id() answers the dispatching browser's row and
 *     Session::get_user_session_id() the user session, so a nested dispatch is bound to the
 *     same sign-in.
 *   - THE ACTING CONTEXT: Session::get_audit_session_id() answers the dispatch's audit
 *     session, so _transactions.audit_session_id and anything else that records it point at
 *     the dispatching request's own context - its address, user agent and browser.
 *   - THE TIMEZONE: Rsx_Time resolves from the dispatcher (its cache is cleared on the way in
 *     and on the way out).
 *
 * THE BINDING HOLDS AFTER SIGN-OUT. The run acts as the user session as it was when the task
 * was dispatched - its identity never changes - even when that browser has signed out since,
 * or signed into another account: the run is the dispatching request, finishing later.
 *
 * FROZEN. Nothing the task does can make it act as anybody else: every identity, site, realm
 * and impersonation setter of Session and Portal_Session throws, and so do the browser's
 * session values (a value belongs to the browser as it is NOW, which is not the request the
 * run stands for). See Session::_refuse_if_frozen().
 *
 * THE DISPATCHER MUST STILL BE ALLOWED TO ACT. An account can be disabled, lose its site, or
 * be deleted between dispatch and execution. The run then REFUSES - begin() throws, the run
 * fails with the reason as its error, and nothing executes - and never runs as nobody. The
 * test is the framework's own: the login identity exists, and on a site the membership is
 * active (users.is_enabled and sites.is_enabled, User_Model::is_active()); a portal user
 * exists, may sign in (Portal_User_Model::can_login()) and its site is active; and the user
 * session itself still exists. Account statuses an application defines are its own to check.
 *
 * Every run - this one or any other - ends with Session::_end_virtual() (Task_Runner), which
 * lifts the freeze and blanks the process. An inline run (Task::internal()) executes in its
 * caller's own context, so nothing is begun there.
 *
 * See rsx:man tasks, RUNNING AS THE DISPATCHER.
 */
class Task_Dispatcher_Context
{
    /**
     * Does this task declare #[Run_As_Dispatcher]?
     *
     * @param string $class Service FQCN
     * @param string $method
     * @return bool
     */
    public static function is_declared(string $class, string $method): bool
    {
        foreach (array_keys(Task_Concurrency::_method_attributes($class, $method)) as $attribute) {
            if ($attribute === 'Run_As_Dispatcher' || str_ends_with($attribute, '\\Run_As_Dispatcher')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start the frozen virtual session run $task_id is bound to.
     *
     * @param int $task_id
     * @return void
     * @throws RuntimeException The run is bound to no user session, or its dispatcher may no
     *         longer act - the run is refused and nothing executes
     */
    public static function begin(int $task_id): void
    {
        $row = DB::table('_tasks')->where('id', $task_id)->first(['user_session_id', 'audit_session_id']);

        if ($row === null || $row->user_session_id === null) {
            throw new RuntimeException(
                'This task runs as the user who dispatched it (#[Run_As_Dispatcher]), and run #' . $task_id . ' is bound to no '
                . 'sign-in, so it was not run. Dispatch it with Task::dispatch() from a signed-in request.'
            );
        }

        $user_session = DB::table('_user_sessions')->where('id', (int) $row->user_session_id)->first();

        if ($user_session === null) {
            throw new RuntimeException(
                "The sign-in this task was dispatched from (user session #{$row->user_session_id}) no longer exists, so the task was not run."
            );
        }

        $bound = [
            'session_id' => (int) $user_session->session_id,
            'user_session_id' => (int) $user_session->id,
            'audit_session_id' => $row->audit_session_id === null ? null : (int) $row->audit_session_id,
            'frozen' => true,
        ];

        $context = match ((int) $user_session->realm_id) {
            Session::REALM_STAFF => static::__staff_context($user_session, 'staff'),
            Session::REALM_API => static::__staff_context($user_session, 'api'),
            Session::REALM_PORTAL => static::__portal_context($user_session),
            default => throw new RuntimeException("User session #{$user_session->id} is in realm {$user_session->realm_id}, which a task cannot run as."),
        };

        Session::_begin_virtual($context + $bound);
    }

    /**
     * A staff sign-in, or an API key's owner: the login identity, the site, the membership
     * there and the impersonator.
     *
     * @param object $user_session
     * @param string $realm 'staff' or 'api'
     * @return array
     */
    private static function __staff_context(object $user_session, string $realm): array
    {
        $login_user_id = (int) $user_session->login_user_id;
        $site_id = (int) ($user_session->site_id ?? 0);

        if (Login_User_Model::find($login_user_id) === null) {
            throw new RuntimeException(
                "The login identity that dispatched this task (#{$login_user_id}) was deleted since, so the task was not run."
            );
        }

        $user_id = null;

        if ($site_id > 0) {
            $user = User_Model::without_site_scope(fn () => User_Model::where('login_user_id', $login_user_id)->where('site_id', $site_id)->first());

            if ($user === null || !$user->is_active()) {
                throw new RuntimeException(
                    "The user who dispatched this task (login identity #{$login_user_id}) may no longer act on site #{$site_id} - "
                    . 'the membership or the site was disabled or deleted since - so the task was not run.'
                );
            }

            $user_id = (int) $user->id;
        }

        return [
            'realm' => $realm,
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'user_id' => $user_id,
            'impersonator_login_user_id' => $user_session->impersonator_login_user_id === null ? null : (int) $user_session->impersonator_login_user_id,
        ];
    }

    /**
     * A portal sign-in: the portal user, its site and a View as Client impersonator.
     *
     * @param object $user_session
     * @return array
     */
    private static function __portal_context(object $user_session): array
    {
        $portal_user_id = (int) $user_session->portal_user_id;
        $portal_user = Portal_User_Model::without_site_scope(fn () => Portal_User_Model::find($portal_user_id));

        if ($portal_user === null || !$portal_user->can_login()) {
            throw new RuntimeException(
                "The portal user who dispatched this task (Portal_User_Model #{$portal_user_id}) was disabled or deleted since, so the task was not run."
            );
        }

        $site_id = (int) $portal_user->site_id;
        $site = Site_Model::find($site_id);

        if ($site === null || !$site->is_active()) {
            throw new RuntimeException(
                "The portal site this task was dispatched on (#{$site_id}) was disabled or deleted since, so the task was not run."
            );
        }

        return [
            'realm' => 'portal',
            'portal_user_id' => $portal_user_id,
            'portal_site_id' => $site_id,
            'impersonator_user_id' => $user_session->impersonator_user_id === null ? null : (int) $user_session->impersonator_user_id,
        ];
    }
}
