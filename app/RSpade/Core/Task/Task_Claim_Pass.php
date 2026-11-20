<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

/**
 * One claim pass of a task worker: the runs it set aside, and the run locks they wait on.
 *
 * A PASS is the worker taking the queue once: it walks the due pending runs in claim order
 * (priority, scheduled_for, id) until it claims one or finds none. A new pass starts every
 * time the worker takes the queue - at its start and after every run it settles - so what a
 * pass set aside lives for that pass only and is reconsidered on the next one, whoever freed
 * the lock in between.
 *
 * QUEUE ORDER WITHIN A LOCK. A run is set aside when it cannot take every run lock it needs
 * (Task_Concurrency::run_lock_identities(): its exclusive groups, or its own identity). From
 * then on the pass sets aside every later run that needs ANY of those locks, without trying
 * them - so a lock that frees mid-pass is never taken by a younger run while an older one is
 * waiting for it; the next pass gives it to the oldest. A run needing none of them (another
 * group, an unmanaged run) still goes ahead, so a busy group never holds up the rest of the
 * queue.
 */
#[Instantiatable]
class Task_Claim_Pass
{
    /** @var array<int, int> run ids set aside this pass */
    private array $set_aside_ids = [];

    /** @var array<string, true> run-lock identities a set-aside run waits on */
    private array $waited_on = [];

    /**
     * Set a run aside for the rest of this pass, with the run-lock identities it needs.
     *
     * @param array<int, string> $identities
     */
    public function set_aside(int $run_id, array $identities): void
    {
        $this->set_aside_ids[] = $run_id;
        foreach ($identities as $identity) {
            $this->waited_on[$identity] = true;
        }
    }

    /**
     * Whether a run needing these run-lock identities must wait behind a run already set aside
     * this pass (it shares one of their locks).
     *
     * @param array<int, string> $identities
     */
    public function waits_behind_a_set_aside_run(array $identities): bool
    {
        foreach ($identities as $identity) {
            if (isset($this->waited_on[$identity])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> the run ids set aside this pass */
    public function set_aside_ids(): array
    {
        return $this->set_aside_ids;
    }
}
