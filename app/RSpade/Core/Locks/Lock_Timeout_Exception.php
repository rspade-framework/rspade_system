<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

/**
 * A lock acquired with a wait budget ($timeout) was not granted within it: another holder
 * has it. CONTENTION, and nothing else - the typed answer a caller that probes a lock
 * ($timeout = 0) or bounds its wait catches. A deadlock refusal or a daemon refusal is a
 * plain RuntimeException; an unreachable daemon is Lockd_Unavailable_Exception, which is
 * not a RuntimeException at all and never reaches a catch written for this.
 *
 * The message names the lock and the wait, and the daemon's own event log names the holder
 * (RsxLocks::set_lock_logging()).
 */
#[Instantiatable]
class Lock_Timeout_Exception extends \RuntimeException
{
}
