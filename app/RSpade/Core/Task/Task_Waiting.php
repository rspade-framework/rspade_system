<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Task_Waiting - what a PENDING run is waiting for, worked out WHEN IT IS READ.
 *
 * Nothing here is stored: the answer is derived from the run's own row, the lock daemon and
 * the running rows at the moment somebody asks, so it cannot go stale and a holder that died
 * leaves nothing to clean up. The first reason that applies, in this order:
 *
 *   disabled   the task system is off (Task::is_enabled()): no worker claims anything,
 *              whatever else is true of the run
 *                  "task system disabled - php artisan rsx:tasks:enable"
 *   scheduled  the run is not due yet (scheduled_for is in the future)
 *                  "scheduled for <time>"
 *   lock       one of its #[Exclusive] / #[Debounce] run locks is held - by a run of the
 *              same identity, or of any member of one of its exclusive groups. A run needs
 *              every lock at once, so it names every one that is held:
 *                  waiting for exclusive lock "<group>" (held by task #<id>)
 *                  waiting for exclusive locks "<a>", "<b>" (held by task #<id>, task #<id>)
 *                  waiting for exclusive lock (held by task #<id>)        no group name
 *              A holder is the RUNNING run with that lock identity. In the moment between
 *              a lock being taken and its run's row being marked running there is no such
 *              row, and that holder is left out.
 *   workers    every worker that could claim it is busy (both task pools claim pending runs)
 *                  "waiting for a free worker (<busy> of <max> busy)"
 *   queued     none of the above: due, unlocked, a worker slot free - it starts when a worker
 *              reaches it (a finishing worker's next claim, or the next cron tick's spawn)
 *                  "waiting for a worker to start"
 *
 * The answer is {reason, text, lock, held_by_task_id}: lock is the held groups' names, comma-
 * separated (null for an ungrouped lock and for every other reason), held_by_task_id the
 * first holder's run id - the only
 * thing about the holder a viewer is told. A run that is not pending answers null.
 *
 * ONE INSTANCE PER READ. An instance remembers what it has asked - the running rows (one
 * query, bounded by the pools' caps plus the inline runs in flight), the pools' caps, and each
 * lock's state (one daemon round trip per lock identity) - so describing a page of pending
 * runs costs the same few reads as describing one. Never keep an instance beyond the read it
 * was made for: what it remembers is a snapshot. The switch is one of the things it remembers
 * (one read per instance).
 */
#[Instantiatable]
class Task_Waiting
{
    const REASON_DISABLED = 'disabled';
    const REASON_SCHEDULED = 'scheduled';
    const REASON_LOCK = 'lock';
    const REASON_WORKERS = 'workers';
    const REASON_QUEUED = 'queued';

    /** @var array<string, int>|null run lock identity => the lowest RUNNING run id holding it */
    private ?array $holders = null;

    /** RUNNING runs claimed by a pool worker. */
    private int $busy_workers = 0;

    /** @var array<string, bool> run lock identity => held, per the lock daemon */
    private array $lock_in_use = [];

    private ?int $max_workers = null;

    /** Whether the task system is on, read once for this instance. */
    private ?bool $system_enabled = null;

    /**
     * What one run is waiting for; null unless it is pending. For several runs, make one
     * instance and call describe() on each.
     *
     * @return array{reason: string, text: string, lock: ?string, held_by_task_id: ?int}|null
     */
    public static function of(Task_Run_Model $run): ?array
    {
        return (new self())->describe($run);
    }

    /**
     * @return array{reason: string, text: string, lock: ?string, held_by_task_id: ?int}|null
     */
    public function describe(Task_Run_Model $run): ?array
    {
        if ((int) $run->status_id !== Task_Run_Model::STATUS_PENDING) {
            return null;
        }

        $this->system_enabled ??= Task::is_enabled();
        if (!$this->system_enabled) {
            return static::__answer(self::REASON_DISABLED, 'task system disabled - php artisan rsx:tasks:enable');
        }

        if ($run->scheduled_for !== null && Rsx_Time::is_future($run->scheduled_for)) {
            return static::__answer(self::REASON_SCHEDULED, 'scheduled for ' . Rsx_Time::format_datetime($run->scheduled_for));
        }

        $class = (string) $run->class;
        $method = (string) $run->method;

        // Every run lock the task takes that is held right now. A run needs them all at once,
        // so it waits while ANY is held - and says which.
        $groups = Task_Concurrency::groups_of($class, $method);
        $busy = [];
        foreach (Task_Concurrency::run_lock_identities($class, $method, (string) $run->params_hash) as $identity) {
            if ($this->__lock_in_use($identity)) {
                $busy[$identity] = $this->__holders()[$identity] ?? null;
            }
        }

        if ($busy !== []) {
            $names = [];
            foreach (array_keys($busy) as $identity) {
                foreach ($groups as $group) {
                    if (Task_Concurrency::group_identity($group) === $identity) {
                        $names[] = $group;
                    }
                }
            }
            $holders = array_values(array_unique(array_filter($busy, fn ($holder) => $holder !== null)));

            $text = 'waiting for exclusive lock' . (count($names) > 1 ? 's' : '');
            if ($names !== []) {
                $text .= ' ' . implode(', ', array_map(fn ($name) => '"' . $name . '"', $names));
            }
            if ($holders !== []) {
                $text .= ' (held by ' . implode(', ', array_map(fn ($holder) => 'task #' . $holder, $holders)) . ')';
            }

            return static::__answer(self::REASON_LOCK, $text, $names === [] ? null : implode(', ', $names), $holders[0] ?? null);
        }

        $this->__holders();
        $max = $this->__max_workers();
        if ($this->busy_workers >= $max) {
            return static::__answer(self::REASON_WORKERS, "waiting for a free worker ({$this->busy_workers} of {$max} busy)");
        }

        return static::__answer(self::REASON_QUEUED, 'waiting for a worker to start');
    }

    private static function __answer(string $reason, string $text, ?string $lock = null, ?int $held_by_task_id = null): array
    {
        return ['reason' => $reason, 'text' => $text, 'lock' => $lock, 'held_by_task_id' => $held_by_task_id];
    }

    /** Whether a run lock is held right now, asked of the lock daemon once per identity. */
    private function __lock_in_use(string $identity): bool
    {
        return $this->lock_in_use[$identity]
            ??= (new Task_Lock(Task_Concurrency::run_lock_name($identity)))->is_in_use();
    }

    /**
     * The RUNNING runs, read once: which run holds each run lock identity, and how many pool
     * workers are busy. Bounded by the pools' caps plus the inline runs in flight.
     *
     * @return array<string, int>
     */
    private function __holders(): array
    {
        if ($this->holders !== null) {
            return $this->holders;
        }

        $this->holders = [];
        $this->busy_workers = 0;

        $running = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->orderBy('id')
            ->get(['id', 'class', 'method', 'params_hash', 'worker_id']);

        foreach ($running as $row) {
            if ($row->worker_id !== null) {
                $this->busy_workers++;
            }

            foreach (Task_Concurrency::run_lock_identities($row->class, $row->method, (string) $row->params_hash) as $identity) {
                if (!isset($this->holders[$identity])) {
                    $this->holders[$identity] = (int) $row->id;
                }
            }
        }

        return $this->holders;
    }

    /** The workers that can claim a pending run: the on_demand pool's and the scheduled pool's. */
    private function __max_workers(): int
    {
        return $this->max_workers
            ??= Task_Pool::max_workers(Task_Pool::ON_DEMAND) + Task_Pool::max_workers(Task_Pool::SCHEDULED);
    }
}
