<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Concurrency;

/**
 * Task_Dispatcher_Context - a task declared #[Run_As_Dispatcher] runs AS the person who
 * dispatched it.
 *
 * Moving a person's request off the request thread changes WHEN it runs, never WHO did it. A
 * run records its dispatcher at dispatch (Task_Runner::insert_row(): dispatched_by_type/_id,
 * site_id and audit_session_id). When a worker - or rsx:task:process --once - executes a run
 * whose task declares #[Run_As_Dispatcher], run() puts that context back for the duration of
 * pre_task() and the method, and clears it afterwards:
 *
 *   - THE IDENTITY AND THE SITE: a staff dispatcher through Session::impersonate() (site,
 *     login identity, site user); a portal dispatcher through Portal_Session (its site, the
 *     portal user) with the request realm declared portal. So the session getters,
 *     Rsx_Model_Abstract::_resolve_context_actor() and the site scope answer as they did in
 *     the dispatching request - revisions, the created_by/updated_by pairs and an
 *     application's own log all name the dispatcher.
 *   - IMPERSONATION, FAITHFULLY: the run acts as the EFFECTIVE identity the dispatcher was,
 *     and the impersonator recorded on the dispatch's audit session is declared too
 *     (Session::get_impersonator_login_user_id(), Portal_Session::get_impersonator_user_id()).
 *   - THE ACTING CONTEXT: Audit_Session answers the dispatch's audit_session_id, so
 *     _transactions.audit_session_id and anything else that records it point at the
 *     dispatching request's own context - its address, user agent and device.
 *
 * No _sessions row is created and no cookie exists: it is a declaration.
 *
 * THE DISPATCHER MUST STILL BE ALLOWED TO ACT. An account can be disabled, lose its site, or
 * be deleted between dispatch and execution. The run then REFUSES - it fails with the reason
 * and nothing executes - and never falls back to running as nobody. The test is the
 * framework's own (users.is_enabled and sites.is_enabled, User_Model::is_active(); a portal
 * user's row and its site); account statuses an application defines are its own to check.
 *
 * An inline run (Task::internal()) executes in its dispatcher's own process, under its own
 * context, so nothing is restored there.
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
     * Run $call as the dispatcher of run $task_id, then clear the context.
     *
     * @param int $task_id
     * @param callable $call
     * @return mixed What $call returned
     * @throws RuntimeException The run has no dispatcher, or the dispatcher may no longer act
     */
    public static function run(int $task_id, callable $call): mixed
    {
        $row = DB::table('_tasks')->where('id', $task_id)->first();

        if ($row === null || empty($row->dispatched_by_type) || empty($row->dispatched_by_id)) {
            throw new RuntimeException(
                'This task runs as its dispatcher (#[Run_As_Dispatcher]), and run #' . $task_id . ' records none. '
                . 'Dispatch it from a signed-in request.'
            );
        }

        $type = Type_Ref_Registry::id_to_class((int) $row->dispatched_by_type);
        $id = (int) $row->dispatched_by_id;
        $impersonators = static::__impersonators($row->audit_session_id !== null ? (int) $row->audit_session_id : null);

        try {
            match ($type) {
                Rsx_Model_Abstract::AUDIT_ACTOR_MODELS['staff_site'] => static::__as_site_user($id, $impersonators),
                Rsx_Model_Abstract::AUDIT_ACTOR_MODELS['cross_site'] => static::__as_login_user($id, (int) $row->site_id, $impersonators),
                Rsx_Model_Abstract::AUDIT_ACTOR_MODELS['portal'] => static::__as_portal_user($id, $impersonators),
                default => throw new RuntimeException("Run #{$task_id} was dispatched by a {$type}, which is not an identity a task can run as."),
            };

            Audit_Session::_declare($row->audit_session_id !== null ? (int) $row->audit_session_id : null);

            return $call();
        } finally {
            static::__clear();
        }
    }

    /**
     * A staff dispatcher signed in on a site: the site user's own membership.
     */
    private static function __as_site_user(int $user_id, array $impersonators): void
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find($user_id));

        if ($user === null || !$user->is_active()) {
            throw new RuntimeException(
                "The user who dispatched this task (User_Model #{$user_id}) may no longer act on its site - "
                . 'the account or the site was disabled or deleted since - so the task was not run.'
            );
        }

        static::__require_login_user((int) $user->login_user_id);

        Rsx_Portal::set_portal_request(false);
        Session::impersonate((int) $user->site_id, (int) $user->login_user_id, (int) $user->id);
        Session::cli_set_impersonator_login_user_id($impersonators['staff']);
    }

    /**
     * A staff dispatcher with no membership on the run's site (site 0, or an identity acting
     * across sites).
     */
    private static function __as_login_user(int $login_user_id, int $site_id, array $impersonators): void
    {
        static::__require_login_user($login_user_id);

        $user_id = null;

        if ($site_id > 0) {
            $site = Site_Model::find($site_id);

            if ($site === null || !$site->is_active()) {
                throw new RuntimeException(
                    "The site this task was dispatched on (#{$site_id}) was disabled or deleted since, so the task was not run."
                );
            }

            $user = User_Model::without_site_scope(fn () => User_Model::where('login_user_id', $login_user_id)->where('site_id', $site_id)->first());
            $user_id = $user !== null && $user->is_active() ? (int) $user->id : null;
        }

        Rsx_Portal::set_portal_request(false);
        Session::impersonate($site_id, $login_user_id, $user_id);
        Session::cli_set_impersonator_login_user_id($impersonators['staff']);
    }

    /**
     * A portal dispatcher: the portal realm, its site, the portal user.
     */
    private static function __as_portal_user(int $portal_user_id, array $impersonators): void
    {
        $portal_user = Portal_User_Model::without_site_scope(fn () => Portal_User_Model::find($portal_user_id));

        if ($portal_user === null) {
            throw new RuntimeException(
                "The portal user who dispatched this task (Portal_User_Model #{$portal_user_id}) was deleted since, so the task was not run."
            );
        }

        $site = Site_Model::find((int) $portal_user->site_id);

        if ($site === null || !$site->is_active()) {
            throw new RuntimeException(
                "The portal site this task was dispatched on (#{$portal_user->site_id}) was disabled or deleted since, so the task was not run."
            );
        }

        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id((int) $portal_user->site_id);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
        Portal_Session::cli_set_impersonator_user_id($impersonators['portal']);
    }

    /**
     * The login identity behind a staff dispatcher still exists.
     */
    private static function __require_login_user(int $login_user_id): void
    {
        if (Login_User_Model::find($login_user_id) === null) {
            throw new RuntimeException(
                "The login identity that dispatched this task (#{$login_user_id}) was deleted since, so the task was not run."
            );
        }
    }

    /**
     * The impersonators the dispatching request carried, from its audit session.
     *
     * @return array{staff: int|null, portal: int|null}
     */
    private static function __impersonators(?int $audit_session_id): array
    {
        $context = $audit_session_id !== null ? Session::get_audit_session($audit_session_id) : null;

        return [
            'staff' => !empty($context['impersonator_login_user_id']) ? (int) $context['impersonator_login_user_id'] : null,
            'portal' => !empty($context['impersonator_user_id']) ? (int) $context['impersonator_user_id'] : null,
        ];
    }

    /**
     * Back to a process acting as nobody, so the next run on this worker starts clean.
     */
    private static function __clear(): void
    {
        Audit_Session::_declare(null);
        Session::reset_impersonation();
        Portal_Session::_clear_cli_identity();
        Rsx_Request_Channel::reset();
    }
}
