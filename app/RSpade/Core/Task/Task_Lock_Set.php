<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Task\Task_Lock;

/**
 * Task_Lock_Set - every RUN lock one run of a task needs, held together.
 *
 * A task declaring several #[Exclusive('group')] markers runs only while it holds the lock
 * of EVERY group it names (Task_Concurrency::run_lock_identities()), so its run lock is a set.
 * A task with one lock - a bare #[Exclusive], a #[Debounce] identity, a single group - is a
 * set of one. Held from the claim to the settle, like the single lock it replaces.
 *
 * ALL OR NONE. try_acquire() probes each lock without waiting and, the moment one is held
 * elsewhere, releases what it already took and answers false: a run never holds part of its
 * locks while it waits, so a run needing {a, b} can never starve a run needing only a by
 * sitting on a. acquire() (the inline runner's path) waits for each in turn, in NAME ORDER:
 * two inline runs needing the same locks always ask for them in the same order, so they can
 * never each hold one and wait on the other.
 */
#[Instantiatable]
class Task_Lock_Set
{
    /** @var array<int, Task_Lock> */
    private array $locks = [];

    /** @var array<int, string> lock names, sorted */
    private array $names;

    /**
     * @param array<int, string> $lock_names Task_Concurrency::run_lock_name() of each identity
     */
    public function __construct(array $lock_names)
    {
        if ($lock_names === []) {
            shouldnt_happen('Task_Lock_Set needs at least one lock name');
        }

        $names = array_values(array_unique($lock_names));
        sort($names, SORT_STRING);
        $this->names = $names;
    }

    /**
     * Take every lock now, or none: false the moment one is held elsewhere, with the ones
     * already taken released.
     */
    public function try_acquire(): bool
    {
        foreach ($this->names as $name) {
            $lock = new Task_Lock($name, 0);
            if (!$lock->acquire()) {
                $this->release();

                return false;
            }
            $this->locks[] = $lock;
        }

        return true;
    }

    /** Take every lock, waiting for each in name order for as long as it is held. */
    public function acquire(): void
    {
        foreach ($this->names as $name) {
            $lock = new Task_Lock($name);
            $lock->acquire();
            $this->locks[] = $lock;
        }
    }

    /** Release every lock this set holds. */
    public function release(): void
    {
        foreach (array_reverse($this->locks) as $lock) {
            $lock->release();
        }
        $this->locks = [];
    }

    /** Whether this set holds its locks. */
    public function is_locked(): bool
    {
        return $this->locks !== [];
    }
}
