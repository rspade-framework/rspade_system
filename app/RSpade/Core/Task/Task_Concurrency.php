<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Concurrency - per-identity concurrency control for tasks.
 *
 * A task method may carry these markers, alone or together:
 *   #[Exclusive]          - at most one instance runs at a time
 *   #[Exclusive('group')] - the same, and additionally at most one task of the GROUP runs
 *                           at a time
 *   #[Debounce(seconds)]  - same single-instance guarantee, and the coalesced
 *                           follow-up run fires `seconds` after the prior run COMPLETED.
 *                           The argument is optional: a bare #[Debounce] has no delay,
 *                           and its follow-up is due the moment the prior run completes.
 *
 * All mean the SAME invariant, a distributed translation of the JS debounce()
 * (Core/Js/async.js): per IDENTITY, AT MOST ONE RUNNING + AT MOST ONE PENDING execution.
 *
 * A POLICY HAS TWO IDENTITIES, DECIDED SEPARATELY:
 *
 *   the PENDING identity (identity_key()) - what one pending row, the enqueue lock, the
 *   debounce delay and the re-anchor on settle are keyed on:
 *     - #[Exclusive]: the method - class::method, whatever the params. A queue drain is one
 *       job however it was asked for.
 *     - #[Debounce] (with or without #[Exclusive]): the method AND its params -
 *       class::method + params_hash. Two different parameter sets are two pieces of work,
 *       each debounced on its own.
 *
 *   the RUN-LOCK identity (run_lock_identity()) - what "running" is keyed on:
 *     - #[Exclusive('group')] (with or without #[Debounce]): the group.
 *     - everything else: the pending identity.
 *
 *   declaration                          pending identity        run lock           delay
 *   #[Exclusive]                         class::method           class::method      0
 *   #[Exclusive('g')]                    class::method           group g            0
 *   #[Debounce(n)]                       class::method + params  the same           n
 *   #[Debounce(n)] + #[Exclusive]        class::method + params  the same           n
 *   #[Debounce(n)] + #[Exclusive('g')]   class::method + params  group g            n
 *
 * A bare #[Exclusive] beside #[Debounce] adds nothing: the task is #[Debounce] alone.
 *
 * AN EXCLUSIVE GROUP serializes several methods against each other - six tasks that all
 * write one external resource, say. Every method naming the same group shares ONE run lock,
 * so one of them runs at a time, cluster-wide. PENDING stays per pending identity: a plain
 * member keeps one pending run per method, a debounced member one per parameter set, so a
 * run of one member queued behind another is never replaced by a third's - the group gates
 * execution, each identity still coalesces its own repeats. A pending run whose group is
 * busy is passed over by every worker and holds no worker slot while it waits; it is claimed
 * once the group's lock is free, oldest due first.
 *
 * A DEBOUNCED GROUP MEMBER takes no lock of its own identity. "At most one running per
 * debounce identity" is implied by the group's lock (one running across the whole group), so
 * whether a given identity is the one running is read from its RUNNING row
 * (is_identity_running()), never from a second lock.
 *
 * It holds however the task runs:
 *   - Task::dispatch() enqueues through enqueue_coalesced(): the existing pending run of the
 *     identity is returned, or a new one is created - so the caller always gets the id of THE
 *     pending run to watch.
 *   - a worker claims a run only while holding the identity's RUN lock (try_acquire_run_lock),
 *     and a schedule tick whose identity is already running is coalesced into that run;
 *   - an inline run (Task::internal(), rsx:task:run, a #[Command]) takes the run lock too,
 *     waiting for a running instance to finish.
 *
 * State is durable (`_tasks` rows) + cluster-safe (Task_Lock, a named rsx-lockd lock through
 * RsxLocks), so correctness never depends on an in-memory trigger:
 *   - running  -> the identity run-lock is held by a worker (or an inline runner)
 *   - queued   -> exactly one PENDING row for the identity
 *   - last_end -> the identity's last completed_at
 *   - timer    -> the pending row's scheduled_for (= last completed + delay)
 * The cron poller (rsx:task:process) is the backstop that runs a due pending row
 * even if the completing worker's eager re-check races or misses it.
 */
class Task_Concurrency
{
    /**
     * Resolve the concurrency policy for a task method from its manifest attributes.
     *
     * @param string $class  Fully-qualified service class
     * @param string $method Static task method
     * mode decides the PENDING identity (and the delay): 'debounce' whenever #[Debounce] is
     * declared, 'exclusive' for #[Exclusive] without it. group decides the RUN lock: the
     * #[Exclusive('group')] name whichever mode it sits beside, or null.
     *
     * @return array{mode: ?string, delay: int, group: ?string}  mode = 'exclusive' | 'debounce'
     *         | null.
     */
    public static function get_policy(string $class, string $method): array
    {
        $attributes = static::_method_attributes($class, $method);

        $exclusive = static::_attribute_args($attributes, 'Exclusive');
        $debounce = static::_attribute_args($attributes, 'Debounce');

        $group = null;
        if ($exclusive !== null) {
            $group = $exclusive[0] ?? null;

            if ($group !== null && (!is_string($group) || trim($group) === '')) {
                throw new \RuntimeException("#[Exclusive] on {$class}::{$method} takes one optional argument, the group name as a non-empty string.");
            }
        }

        if ($debounce !== null) {
            return ['mode' => 'debounce', 'delay' => max(0, (int) ($debounce[0] ?? 0)), 'group' => $group];
        }

        if ($exclusive !== null) {
            return ['mode' => 'exclusive', 'delay' => 0, 'group' => $group];
        }

        return ['mode' => null, 'delay' => 0, 'group' => null];
    }

    /**
     * The exclusive group a task method belongs to, or null.
     */
    public static function group_of(string $class, string $method): ?string
    {
        return static::get_policy($class, $method)['group'];
    }

    /**
     * Whether any task of an exclusive group is running right now, anywhere in the cluster.
     *
     * Asks the lock daemon whether the group's run lock is held; nothing is acquired.
     */
    public static function is_group_running(string $group): bool
    {
        return (new Task_Lock(static::run_lock_name(static::__group_identity($group))))->is_in_use();
    }

    /**
     * Whether a run of this PENDING identity is RUNNING, by its row: any run of the method for
     * #[Exclusive], a run with the same params_hash for #[Debounce]. What tells a group
     * member's own repeat from another member holding the group's lock.
     */
    public static function is_identity_running(string $class, string $method, string $params_hash): bool
    {
        return static::__identity_query($class, $method, $params_hash)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->exists();
    }

    /**
     * Every task method declaring #[Exclusive('$group')] - with or without #[Debounce] -
     * from the manifest.
     *
     * @return array<int, array{0: string, 1: string}> [fully-qualified class, method] pairs
     */
    public static function group_members(string $group): array
    {
        $members = [];

        foreach (Manifest::by_attribute('Exclusive') as $row) {
            if ($row['member'] === null || $row['class'] === null) {
                continue;
            }
            if (($row['instances'][0][0] ?? null) !== $group) {
                continue;
            }

            $fqcn = Manifest::php_class_metadata($row['class'])['fqcn'] ?? null;
            if ($fqcn !== null) {
                $members[] = [$fqcn, $row['member']];
            }
        }

        return $members;
    }

    /**
     * What a run's RUN lock is keyed on - the group for a task declaring #[Exclusive('group')]
     * (a debounced one included), else the pending identity (identity_key()) - or null for an
     * unmanaged task, which has no run lock. Two runs with the same answer can never run at
     * once.
     */
    public static function run_lock_identity(string $class, string $method, string $params_hash): ?string
    {
        $identity = static::identity_key($class, $method, $params_hash);
        if ($identity === null) {
            return null;
        }

        $group = static::get_policy($class, $method)['group'];

        return $group !== null ? static::__group_identity($group) : $identity;
    }

    /**
     * The PENDING runs that wait on the same run lock as a run of ($class, $method,
     * $params_hash): the identity's own pending run, and for a grouped task every pending run
     * of every member of the group (one per method for a plain member, one per parameter set
     * for a debounced one). One pending run per pending identity; empty for an unmanaged task.
     * Task-table reads only.
     *
     * @return int[]
     */
    public static function pending_ids_on_run_lock(string $class, string $method, string $params_hash): array
    {
        $policy = static::get_policy($class, $method);
        if ($policy['mode'] === null) {
            return [];
        }

        $query = DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_PENDING);

        if ($policy['group'] !== null) {
            $members = static::group_members($policy['group']);
            if ($members === []) {
                return [];
            }
            $query->where(function ($where) use ($members) {
                foreach ($members as [$member_class, $member_method]) {
                    $where->orWhere(function ($member) use ($member_class, $member_method) {
                        $member->where('class', $member_class)->where('method', $member_method);
                    });
                }
            });
        } else {
            $query->where('class', $class)->where('method', $method);
            if ($policy['mode'] === 'debounce') {
                $query->where('params_hash', $params_hash);
            }
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether a task method is single-instance (Exclusive or Debounce).
     */
    public static function is_managed(string $class, string $method): bool
    {
        return static::get_policy($class, $method)['mode'] !== null;
    }

    /**
     * The canonical hash of a parameter set: sha256 of its JSON with every object's keys
     * sorted, so {a:1,b:2} and {b:2,a:1} are one parameter set.
     */
    public static function params_hash(array $params): string
    {
        return hash('sha256', json_encode(static::__canonical($params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The PENDING identity's key: class::method for #[Exclusive], class::method::params_hash
     * for #[Debounce] (whatever #[Exclusive] it also declares). Null for an unmanaged task.
     * The run lock is keyed on run_lock_identity(), which differs for a grouped task.
     */
    public static function identity_key(string $class, string $method, string $params_hash): ?string
    {
        $mode = static::get_policy($class, $method)['mode'];

        if ($mode === 'exclusive') {
            return $class . '::' . $method;
        }
        if ($mode === 'debounce') {
            return $class . '::' . $method . '::' . $params_hash;
        }

        return null;
    }

    /**
     * Lock name for "this identity is running" (a Task_Lock / RsxLocks named lock).
     */
    public static function run_lock_name(string $identity_key): string
    {
        return 'rsxtask_run_' . md5($identity_key);
    }

    /**
     * Lock name guarding the coalescing check-and-enqueue section.
     */
    public static function enqueue_lock_name(string $identity_key): string
    {
        return 'rsxtask_enq_' . md5($identity_key);
    }

    /**
     * Coalescing enqueue: ensure AT MOST ONE pending execution row exists for the pending
     * identity, and return its id - the existing one, or the one just created by $insert.
     * Never asks about the run lock: a grouped task enqueues exactly as an ungrouped one.
     *
     * scheduled_for honors the debounce delay measured from the last completion:
     * max(now, last_completed_at + delay). Wrapped in a cluster-safe named lock so two
     * enqueuers can never both insert.
     *
     * @param callable $insert fn (string $scheduled_for): int - writes the pending row.
     */
    public static function enqueue_coalesced(string $class, string $method, string $params_hash, callable $insert): int
    {
        $identity = static::identity_key($class, $method, $params_hash);
        if ($identity === null) {
            shouldnt_happen("enqueue_coalesced() for {$class}::{$method}, which is neither #[Exclusive] nor #[Debounce]");
        }

        // Waits forever: whatever holds the lock is another enqueue of the same identity,
        // which is the thing this lock exists to serialize.
        $lock = new Task_Lock(static::enqueue_lock_name($identity));
        $lock->acquire();

        try {
            $existing = static::pending_row_id($class, $method, $params_hash);
            if ($existing !== null) {
                return $existing;
            }

            return (int) $insert(static::_next_scheduled_for($class, $method, $params_hash));
        } finally {
            $lock->release();
        }
    }

    /**
     * enqueue_coalesced() for a caller that may not wait: the enqueue lock is only PROBED.
     *
     * For the one caller that enqueues while holding a task pool lock (a schedule tick of a
     * grouped task whose group is held by a run of another pending identity), where waiting
     * on another lock is not allowed. When
     * the probe fails, another process is enqueuing this identity at this moment, and its
     * pending run is the one this call wanted.
     *
     * @param callable $insert fn (string $scheduled_for): int - writes the pending row.
     * @return int|null The pending run's id; null when the probe found an enqueue in flight.
     */
    public static function enqueue_coalesced_without_waiting(string $class, string $method, string $params_hash, callable $insert): ?int
    {
        $lock = new Task_Lock(static::enqueue_lock_name(static::__require_identity($class, $method, $params_hash)), 0);

        if (!$lock->acquire()) {
            return null;
        }

        try {
            $existing = static::pending_row_id($class, $method, $params_hash);
            if ($existing !== null) {
                return $existing;
            }

            return (int) $insert(static::_next_scheduled_for($class, $method, $params_hash));
        } finally {
            $lock->release();
        }
    }

    /**
     * The id of the single pending row for the identity of ($class, $method, $params_hash),
     * or null.
     */
    public static function pending_row_id(string $class, string $method, string $params_hash): ?int
    {
        $id = static::__identity_query($class, $method, $params_hash)
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Non-blocking attempt to acquire the run lock: the identity's, or the GROUP's for a
     * task declaring #[Exclusive('group')]. Returns the held lock (keep it and release when
     * the run finishes) or null if another run of the identity - or any task of the group -
     * holds it. An unmanaged task has no run lock and the caller must not ask.
     */
    public static function try_acquire_run_lock(string $class, string $method, string $params_hash): ?Task_Lock
    {
        $lock = new Task_Lock(static::run_lock_name(static::__run_identity($class, $method, $params_hash)), 0);
        if ($lock->acquire()) {
            return $lock;
        }

        return null;
    }

    /**
     * Acquire the run lock (the identity's, or the group's), waiting for as long as a running
     * instance - or another task of the group - holds it. The
     * inline runner's path: an inline run of a managed task waits its turn instead of running
     * beside the instance already running.
     */
    public static function acquire_run_lock(string $class, string $method, string $params_hash): Task_Lock
    {
        $lock = new Task_Lock(static::run_lock_name(static::__run_identity($class, $method, $params_hash)));
        $lock->acquire();

        return $lock;
    }

    /**
     * Re-anchor a coalesced pending run after a run of the same PENDING identity completes:
     * its scheduled_for becomes now + delay (matching the JS debounce
     * `finally { if queued -> setTimeout(delay) }`). No-op if nothing is pending. The pending
     * runs of the group's other identities are not touched: they were waiting for the lock,
     * not for this identity's delay.
     */
    public static function reschedule_pending_after_completion(string $class, string $method, string $params_hash): void
    {
        $policy = static::get_policy($class, $method);

        $pending_id = static::pending_row_id($class, $method, $params_hash);
        if ($pending_id === null) {
            return;
        }

        DB::table('_tasks')->where('id', $pending_id)->update([
            'scheduled_for' => now()->addSeconds($policy['delay']),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * Rows of one PENDING identity: every row of the method for #[Exclusive], the rows with
     * the same params_hash for #[Debounce]. Served by idx_tasks_identity.
     */
    private static function __identity_query(string $class, string $method, string $params_hash)
    {
        $query = DB::table('_tasks')->where('class', $class)->where('method', $method);

        if (static::get_policy($class, $method)['mode'] === 'debounce') {
            $query->where('params_hash', $params_hash);
        }

        return $query;
    }

    /**
     * What the RUN lock is keyed on: the group for a task declaring #[Exclusive('group')], the
     * pending identity for everything else. Pending rows are always keyed on the pending
     * identity.
     */
    private static function __run_identity(string $class, string $method, string $params_hash): string
    {
        $identity = static::__require_identity($class, $method, $params_hash);
        $group = static::get_policy($class, $method)['group'];

        return $group !== null ? static::__group_identity($group) : $identity;
    }

    /** The run-lock key of an exclusive group. No class::method identity can equal it. */
    private static function __group_identity(string $group): string
    {
        return 'group::' . $group;
    }

    private static function __require_identity(string $class, string $method, string $params_hash): string
    {
        $identity = static::identity_key($class, $method, $params_hash);
        if ($identity === null) {
            shouldnt_happen("Run lock asked for {$class}::{$method}, which is neither #[Exclusive] nor #[Debounce]");
        }

        return $identity;
    }

    /**
     * scheduled_for for a fresh coalesced enqueue: not before the debounce delay
     * has elapsed since the last completion of this identity.
     */
    private static function _next_scheduled_for(string $class, string $method, string $params_hash): string
    {
        $delay = static::get_policy($class, $method)['delay'];

        if ($delay <= 0) {
            return now()->format('Y-m-d H:i:s.v');
        }

        $last = static::__identity_query($class, $method, $params_hash)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at', 'desc')
            ->value('completed_at');

        if (!$last) {
            return now()->format('Y-m-d H:i:s.v');
        }

        $earliest = \Illuminate\Support\Carbon::parse($last)->addSeconds($delay);

        return ($earliest->isFuture() ? $earliest : now())->format('Y-m-d H:i:s.v');
    }

    /** Recursively sort the keys of every associative array, leaving lists in order. */
    private static function __canonical($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => static::__canonical($item), $value);
    }

    /**
     * Method attribute map from the manifest (keyed by simple attribute name).
     */
    private static function _method_attributes(string $class, string $method): array
    {
        $record = Manifest::php_class_metadata(Manifest::_normalize_class_name($class));

        if ($record === null || ($record['fqcn'] ?? null) !== ltrim($class, '\\')) {
            return [];
        }

        // A task service's file record is in the HOT half of the index for exactly this
        // read. This was a linear scan of every indexed file, per task dispatch.
        return Manifest::get_file($record['file'])['public_static_methods'][$method]['attributes'] ?? [];
    }

    /**
     * Arguments of the first instance of a named attribute, or null if absent.
     */
    private static function _attribute_args(array $attributes, string $name): ?array
    {
        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return $instances[0] ?? [];
            }
        }

        return null;
    }
}
