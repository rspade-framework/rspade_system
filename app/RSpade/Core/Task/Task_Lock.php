<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use RuntimeException;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * Task_Lock - the task queue's named locks, held across control flow.
 *
 * WHAT THIS USED TO BE. Until 2026-08-22 this class was a SECOND, PARALLEL lock system:
 * MySQL advisory locks via GET_LOCK()/RELEASE_LOCK(), running alongside RsxLocks for no
 * reason other than age. It is now a thin adapter over RsxLocks, so the framework has one
 * lock implementation again. The class survives the migration because a lock here is
 * ACQUIRED IN ONE FUNCTION AND RELEASED IN ANOTHER - Task_Concurrency::try_acquire_run_lock()
 * hands a held lock to the worker, which releases it when the run finishes - and an object
 * carries that hold across the boundary more honestly than a bare token string.
 *
 * WAITING IS FOREVER, AND THAT IS THE POINT. Every one of these locks previously waited a
 * hardcoded 5 seconds and then gave up, which meant a busy box could DECLINE TO DO WORK IT
 * HAD BEEN ASSIGNED - an enqueue silently dropped, a tick silently skipped - with no log and
 * no error. That is the no-timeout mandate's exact failure shape, and it is why $timeout now
 * defaults to null (wait forever).
 *
 * THE ONE LEGITIMATE NON-BLOCKING CASE is $timeout = 0, used only by the identity RUN lock:
 * "is another worker already running this identity?" There, not-acquiring is the ANSWER, not
 * a failure - it is #[Exclusive] semantics - and the caller yields to the peer that already
 * holds it. That is a probe, not a deadline.
 */
#[Instantiatable]
class Task_Lock
{
    private string $lock_name;
    private ?int $timeout;
    private ?string $token = null;

    /**
     * @param string   $lock_name Unique lock identifier
     * @param int|null $timeout   NULL (default) waits forever. 0 is a non-blocking probe.
     *                            Any other value is a bounded wait and must be justified
     *                            against the no-timeout mandate before you write it.
     */
    public function __construct(string $lock_name, ?int $timeout = null)
    {
        $this->lock_name = $lock_name;
        $this->timeout = $timeout;
    }

    /**
     * Acquire the lock.
     *
     * Returns TRUE once held. Returns FALSE only in the non-blocking case ($timeout = 0)
     * when a peer holds it - the sole outcome a caller is entitled to treat as ordinary.
     * A genuine lock fault (daemon refusal, deadlock cycle) still throws.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        if ($this->token !== null) {
            return true;
        }

        if ($this->timeout === 0) {
            // Non-blocking probe: contention is an expected answer, so it is caught here
            // and reported as false. Nothing else about the failure is swallowed - a
            // deadlock or a refusal carries a different message and is re-thrown.
            try {
                $this->token = RsxLocks::named_write_lock($this->lock_name, 0);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'deadlock')) {
                    throw $e;
                }

                return false;
            }

            return true;
        }

        $this->token = RsxLocks::named_write_lock($this->lock_name, $this->timeout);

        return true;
    }

    /**
     * Release the lock.
     *
     * @return bool True if this instance held it and released it.
     */
    public function release(): bool
    {
        if ($this->token === null) {
            return false;
        }

        $released = RsxLocks::release_lock($this->token);
        $this->token = null;

        return $released;
    }

    /**
     * Whether THIS instance currently holds the lock.
     *
     * @return bool
     */
    public function is_locked(): bool
    {
        return $this->token !== null;
    }

    /**
     * Whether the lock is currently held by ANY process, this one included.
     *
     * Asks rsx-lockd directly rather than probing by trying to take it. A take-and-release
     * probe looks equivalent and is not: RsxLocks is RE-ENTRANT and reference-counted per
     * process, so a probe issued from the process that already holds the lock SUCCEEDS and
     * would report the lock free while the caller is standing on it.
     *
     * @return bool
     */
    public function is_in_use(): bool
    {
        if ($this->token !== null) {
            return true;
        }

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $this->lock_name);

        return (bool) $stats['writer_active'] || (int) $stats['readers_active'] > 0;
    }

    /**
     * Release on destruction, so an abandoned instance never strands a hold.
     */
    public function __destruct()
    {
        if ($this->token !== null) {
            $this->release();
        }
    }
}
