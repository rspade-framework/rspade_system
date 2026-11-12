<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Rsx_Portal;

/**
 * Task_Gates - who may see a task run, and who may act on one, through the browser task
 * API. The single seam Rsx_Task_Controller, the three task topics and every application
 * endpoint that shows a run ask.
 *
 * THE DECISION IS PER TASK TYPE. A #[Task] the browser may show declares
 * #[Task_Access('<check>')] (baked by Task_Access_ManifestSupport):
 *
 *   #[Task_Access('dispatcher')]           built-in: the identity that dispatched the run may
 *                                          view, stop and cancel it; nobody else, nothing else
 *   #[Task_Access('my_check')]             a public static method on the same service:
 *                                          my_check(Task_Run_Model $run, $user, string $action): bool
 *   #[Task_Access(..., realm: 'portal')]   who asks: 'staff' (default), 'portal' or 'any'
 *
 * $action is 'view' (status, reports, output, messages, attachments, list membership) or one
 * of ACTIONS. $user is the realm's signed-in identity (User_Model on staff, Portal_User_Model
 * on the portal) and is never null: a caller not signed in in the request's realm is refused
 * before any check is asked.
 *
 * DENY BY DEFAULT. A task with no #[Task_Access], or one declared for the other realm, is
 * visible and controllable by DEVELOPERS only. A signed-in non-developer asking about such a
 * run is refused with an error NAMING THE TASK and the remedy (refusal_metadata()), which
 * Rsx_Task.js prints to the console; a caller who is not signed in gets the ordinary
 * not-found (anti-enumeration).
 *
 * A DEVELOPER (Session::is_developer(), staff realm) passes every question without a check
 * being asked - the developer console (/_sys) shows and controls every run.
 *
 * TENANCY. A run stamped with a site (site_id > 0) is shown only to a caller on that site;
 * a run with no site (system work) is the check's to decide.
 *
 * Listing (visible_page()) is limited to declared task types; 'dispatcher' types filter in
 * SQL, custom checks are asked row by row while the framework walks the runs (keyset) until
 * the page is full.
 */
class Task_Gates
{
    /** The built-in check name. */
    const DISPATCHER = 'dispatcher';

    /** The read question. */
    const VIEW = 'view';

    /** The lifecycle actions a check is asked about (besides VIEW). */
    const ACTIONS = ['stop', 'force_stop', 'force_kill', 'cancel', 'rerun'];

    /** What the built-in 'dispatcher' check allows its dispatcher. */
    const DISPATCHER_ACTIONS = ['view', 'stop', 'cancel'];

    const REALM_STAFF = 'staff';
    const REALM_PORTAL = 'portal';
    const REALM_ANY = 'any';
    const REALMS = [self::REALM_STAFF, self::REALM_PORTAL, self::REALM_ANY];

    /** The skill that explains #[Task_Access]; named in every refusal. */
    const SKILL = 'rspade:task-access';

    // Verdicts of decide()
    const ALLOWED = 'allowed';
    const DENIED = 'denied';
    const UNDECLARED = 'undeclared';
    const WRONG_REALM = 'wrong_realm';

    /**
     * The verdict on $action for this run, for the current caller: ALLOWED, DENIED (an
     * ordinary refusal - not found for a read), or - for a signed-in non-developer only -
     * UNDECLARED / WRONG_REALM (the task's declaration does not admit this realm at all).
     */
    public static function decide(Task_Run_Model $run, string $action): string
    {
        static::__assert_action($action);

        if (static::is_developer()) {
            return self::ALLOWED;
        }

        $user = static::current_user();
        if ($user === null) {
            return self::DENIED;
        }

        $declaration = static::declaration($run->class, $run->method);
        if ($declaration === null) {
            return self::UNDECLARED;
        }
        if (!static::__realm_admits($declaration['realm'])) {
            return self::WRONG_REALM;
        }

        $site_id = (int) $run->site_id;
        if ($site_id > 0 && $site_id !== static::__current_site_id()) {
            return self::DENIED;
        }

        return static::__ask($declaration, $run, $user, $action) ? self::ALLOWED : self::DENIED;
    }

    /**
     * May the current caller see this run - its status, reports, output and files?
     */
    public static function can_view(Task_Run_Model $run): bool
    {
        return static::decide($run, self::VIEW) === self::ALLOWED;
    }

    /**
     * May the current caller perform $action on this run?
     *
     * @param string $action One of ACTIONS
     */
    public static function can_control(Task_Run_Model $run, string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown task action '{$action}'; expected one of " . implode(', ', self::ACTIONS) . '.');
        }

        return static::decide($run, $action) === self::ALLOWED;
    }

    /**
     * The #[Task_Access] declaration of a task, or null when it declares none:
     * ['class', 'service', 'method', 'check', 'realm'].
     */
    public static function declaration(string $class, string $method): ?array
    {
        return Manifest::get_task_access()[ltrim($class, '\\') . '::' . $method] ?? null;
    }

    /**
     * The error message for an UNDECLARED / WRONG_REALM verdict: names the task, why the
     * browser task API refuses it, and the remedy.
     */
    public static function refusal_message(string $task_name, string $verdict): string
    {
        $realm = static::request_realm();

        if ($verdict === self::WRONG_REALM) {
            return "Task {$task_name} declares #[Task_Access] for another realm, so the browser task API refuses it "
                . "to {$realm} users. Add realm: '{$realm}' or realm: 'any' to its #[Task_Access] if {$realm} users "
                . 'should see it. See skill ' . self::SKILL . '.';
        }

        return "Task {$task_name} declares no #[Task_Access], so the browser task API refuses it to everyone but "
            . "developers. Add #[Task_Access('dispatcher')] (only the user who started a run may watch, stop and "
            . "cancel it) or #[Task_Access('<check method>')] to the task. See skill " . self::SKILL . '.';
    }

    /**
     * The metadata of the Ajax error an UNDECLARED / WRONG_REALM verdict is answered with.
     * `task_access` is the machine-readable marker Rsx_Task.js recognises.
     */
    public static function refusal_metadata(string $task_name, string $verdict): array
    {
        return [
            '_message' => static::refusal_message($task_name, $verdict),
            'task_access' => $verdict,
            'task' => $task_name,
            'skill' => self::SKILL,
        ];
    }

    /**
     * When a list filter names task types (class, and optionally method) of which NONE is
     * visible to the current signed-in non-developer for want of a declaration, the
     * [verdict, task_name] to refuse the list with; null otherwise (including every caller a
     * list should simply answer for - a developer, a signed-out caller, an unnamed filter).
     */
    public static function list_refusal(array $filter): ?array
    {
        if (!isset($filter['class']) || static::is_developer() || static::current_user() === null) {
            return null;
        }

        $class = (string) $filter['class'];
        if (!str_contains($class, '\\')) {
            $class = Manifest::php_class_metadata($class)['fqcn'] ?? $class;
        }
        $class = ltrim($class, '\\');

        if (isset($filter['method'])) {
            $declaration = static::declaration($class, (string) $filter['method']);
            $task_name = class_basename($class) . '::' . $filter['method'];
            if ($declaration === null) {
                return [self::UNDECLARED, $task_name];
            }

            return static::__realm_admits($declaration['realm']) ? null : [self::WRONG_REALM, $task_name];
        }

        foreach (static::__declared_for_realm() as $declaration) {
            if ($declaration['class'] === $class) {
                return null;
            }
        }

        return [self::UNDECLARED, class_basename($class) . '::*'];
    }

    /**
     * One page of the runs matching $filter (Task_Run_Model::search_query() keys) that the
     * current caller may see, newest first: ['tasks' => Task_Run_Model[], 'next_cursor' =>
     * ?string]. Pass next_cursor back for the next page; null means there is none.
     *
     * A developer sees every run (Task_Run_Model::find_page()). Anyone else sees only runs of
     * task types declaring #[Task_Access] for their realm: 'dispatcher' types narrowed in SQL
     * to the caller's own runs, custom checks asked for 'view' on each row as the runs are
     * walked by id, until the page is full or the runs run out - a page is never cut short
     * by invisible rows, and the cursor is the last row returned.
     *
     * @throws \InvalidArgumentException for a bad filter, cursor or page size
     */
    public static function visible_page(array $filter, ?string $cursor = null, int $page_size = Task_Run_Model::SEARCH_PAGE_SIZE): array
    {
        if (static::is_developer()) {
            return Task_Run_Model::find_page($filter, $cursor, $page_size);
        }

        if ($page_size < 1 || $page_size > Task_Run_Model::SEARCH_PAGE_SIZE) {
            throw new \InvalidArgumentException('A task search page holds 1 to ' . Task_Run_Model::SEARCH_PAGE_SIZE . ' runs.');
        }
        if ($cursor !== null && $cursor !== '' && !ctype_digit($cursor)) {
            throw new \InvalidArgumentException("Invalid task search cursor '{$cursor}'.");
        }

        $query = Task_Run_Model::search_query($filter);

        $user = static::current_user();
        $declared = static::__declared_for_realm();
        if ($user === null || $declared === []) {
            return ['tasks' => [], 'next_cursor' => null];
        }

        [$actor_type, $actor_id] = static::__actor_pair($user);
        $query->where(function ($types) use ($declared, $actor_type, $actor_id) {
            foreach ($declared as $declaration) {
                $types->orWhere(function ($type) use ($declaration, $actor_type, $actor_id) {
                    $type->where('class', $declaration['class'])->where('method', $declaration['method']);
                    if ($declaration['check'] === self::DISPATCHER) {
                        $type->where('dispatched_by_type', $actor_type)->where('dispatched_by_id', $actor_id);
                    }
                });
            }
        });

        $site_id = static::__current_site_id();
        $query->where(function ($site) use ($site_id) {
            $site->whereNull('site_id')->orWhere('site_id', 0)->orWhere('site_id', $site_id);
        });

        $after = ($cursor !== null && $cursor !== '') ? (int) $cursor : null;
        $kept = [];

        while (true) {
            $chunk = clone $query;
            if ($after !== null) {
                $chunk->where('id', '<', $after);
            }
            $rows = $chunk->orderByDesc('id')->limit($page_size + 1)->get()->all();

            foreach ($rows as $row) {
                $after = (int) $row->id;
                if (static::decide($row, self::VIEW) === self::ALLOWED) {
                    $kept[] = $row;
                    if (count($kept) > $page_size) {
                        break 2;
                    }
                }
            }

            if (count($rows) <= $page_size) {
                break;
            }
        }

        $more = count($kept) > $page_size;
        $kept = array_slice($kept, 0, $page_size);

        return [
            'tasks' => $kept,
            'next_cursor' => $more ? (string) end($kept)->id : null,
        ];
    }

    /**
     * May the current caller subscribe to one run's frames? Its view check decides; a run
     * that does not exist is refused.
     */
    public static function can_subscribe_to_task(int $task_id): bool
    {
        $run = $task_id > 0 ? Task_Run_Model::find($task_id) : null;

        return $run !== null && static::can_view($run);
    }

    /**
     * May the current caller subscribe to the list topic with this filter? A frame there
     * names a task type, never a run, so the question is whether a run of a type the filter
     * names can be visible to this caller at all: a developer, or a signed-in caller in a
     * realm for which such a type declares #[Task_Access].
     */
    public static function can_subscribe_to_list(array $filter = []): bool
    {
        if (static::is_developer()) {
            return true;
        }
        if (static::current_user() === null || static::__declared_for_realm() === []) {
            return false;
        }

        return static::list_refusal(array_intersect_key($filter, ['class' => true, 'method' => true])) === null;
    }

    /** A staff developer: passes every question without a check being asked. */
    public static function is_developer(): bool
    {
        return !Rsx_Portal::is_portal_request() && Session::is_developer();
    }

    /** 'staff' or 'portal': the realm the current request asks in. */
    public static function request_realm(): string
    {
        return Rsx_Portal::is_portal_request() ? self::REALM_PORTAL : self::REALM_STAFF;
    }

    /** The realm's signed-in identity (User_Model / Portal_User_Model), or null. */
    public static function current_user()
    {
        if (Rsx_Portal::is_portal_request()) {
            return Portal_Session::is_logged_in() ? Portal_Session::get_portal_user() : null;
        }

        return Session::is_logged_in() ? Session::get_user() : null;
    }

    // ------------------------------------------------------------------------------------

    private static function __assert_action(string $action): void
    {
        if ($action !== self::VIEW && !in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown task action '{$action}'; expected view or one of " . implode(', ', self::ACTIONS) . '.');
        }
    }

    private static function __realm_admits(string $declared_realm): bool
    {
        return $declared_realm === self::REALM_ANY || $declared_realm === static::request_realm();
    }

    /** Every declaration admitting the current request's realm. */
    private static function __declared_for_realm(): array
    {
        return array_values(array_filter(Manifest::get_task_access(), fn ($declaration) => static::__realm_admits($declaration['realm'])));
    }

    private static function __ask(array $declaration, Task_Run_Model $run, $user, string $action): bool
    {
        if ($declaration['check'] === self::DISPATCHER) {
            // dispatched_by_type is a type-ref column: it reads back as the class name.
            return in_array($action, self::DISPATCHER_ACTIONS, true)
                && $run->dispatched_by_type !== null
                && $run->dispatched_by_type === class_basename($user)
                && (int) $run->dispatched_by_id === (int) $user->id;
        }

        $class = $declaration['class'];
        $check = $declaration['check'];
        $answer = $class::$check($run, $user, $action);

        if (!is_bool($answer)) {
            throw new \RuntimeException("Task access check {$declaration['service']}::{$check}() must return a bool; it returned " . get_debug_type($answer) . '.');
        }

        return $answer;
    }

    /**
     * The dispatched_by pair a run started by $user carries, as STORED: [type ref id, id] -
     * for a query; a loaded row reads the type back as the class name.
     *
     * @return array{0: int, 1: int}
     */
    private static function __actor_pair($user): array
    {
        return [Type_Ref_Registry::class_to_id(class_basename($user)), (int) $user->id];
    }

    private static function __current_site_id(): int
    {
        return (int) Rsx_Site_Model_Abstract::get_current_site_id();
    }
}
