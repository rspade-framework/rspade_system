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
 * A task method may carry (mutually exclusive) markers:
 *   #[Exclusive]          - at most one instance runs at a time
 *   #[Debounce(seconds)]  - same single-instance guarantee, and the coalesced
 *                           follow-up run fires `seconds` after the prior run COMPLETED.
 *
 * Both mean the SAME invariant, a distributed translation of the JS debounce()
 * (Core/Js/async.js): per IDENTITY, AT MOST ONE RUNNING + AT MOST ONE PENDING execution.
 * The identity differs:
 *   - #[Exclusive]: the method - class::method, whatever the params. A queue drain is one
 *     job however it was asked for.
 *   - #[Debounce]:  the method AND its params - class::method + params_hash. Two different
 *     parameter sets are two pieces of work, each debounced on its own.
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
     * @return array{mode: ?string, delay: int}  mode = 'exclusive' | 'debounce' | null
     */
    public static function get_policy(string $class, string $method): array
    {
        $attributes = static::_method_attributes($class, $method);

        $has_exclusive = static::_has_attribute($attributes, 'Exclusive');
        $debounce = static::_attribute_args($attributes, 'Debounce');

        if ($has_exclusive) {
            return ['mode' => 'exclusive', 'delay' => 0];
        }

        if ($debounce !== null) {
            $delay = (int) ($debounce[0] ?? 0);

            return ['mode' => 'debounce', 'delay' => max(0, $delay)];
        }

        return ['mode' => null, 'delay' => 0];
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
     * The identity's key: class::method for #[Exclusive], class::method::params_hash for
     * #[Debounce]. Null for an unmanaged task.
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
     * Coalescing enqueue: ensure AT MOST ONE pending execution row exists for the identity,
     * and return its id - the existing one, or the one just created by $insert.
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
     * Non-blocking attempt to acquire the identity run-lock. Returns the held lock
     * (keep it and release when the run finishes) or null if another run of the identity
     * holds it. Null for an unmanaged task is never returned: such a task has no run lock and
     * the caller must not ask.
     */
    public static function try_acquire_run_lock(string $class, string $method, string $params_hash): ?Task_Lock
    {
        $lock = new Task_Lock(static::run_lock_name(static::__require_identity($class, $method, $params_hash)), 0);
        if ($lock->acquire()) {
            return $lock;
        }

        return null;
    }

    /**
     * Acquire the identity run-lock, waiting for as long as a running instance holds it. The
     * inline runner's path: an inline run of a managed task waits its turn instead of running
     * beside the instance already running.
     */
    public static function acquire_run_lock(string $class, string $method, string $params_hash): Task_Lock
    {
        $lock = new Task_Lock(static::run_lock_name(static::__require_identity($class, $method, $params_hash)));
        $lock->acquire();

        return $lock;
    }

    /**
     * Re-anchor a coalesced pending run after a run of the same identity completes:
     * its scheduled_for becomes now + delay (matching the JS debounce
     * `finally { if queued -> setTimeout(delay) }`). No-op if nothing is pending.
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
     * Rows of one identity: every row of the method for #[Exclusive], the rows with the same
     * params_hash for #[Debounce]. Served by idx_tasks_identity.
     */
    private static function __identity_query(string $class, string $method, string $params_hash)
    {
        $query = DB::table('_tasks')->where('class', $class)->where('method', $method);

        if (static::get_policy($class, $method)['mode'] === 'debounce') {
            $query->where('params_hash', $params_hash);
        }

        return $query;
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

    private static function _has_attribute(array $attributes, string $name): bool
    {
        foreach ($attributes as $attr_name => $instances) {
            if ($attr_name === $name || str_ends_with($attr_name, '\\' . $name)) {
                return true;
            }
        }

        return false;
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
