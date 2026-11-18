<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

/**
 * A cluster lock or semaphore wait was ended by the PROGRESS WATCHDOG: the holder ahead of
 * this process showed no progress for rsx.locks.stall_seconds (600 by default), so the wait
 * - which would otherwise have lasted for as long as that silent holder lives - failed
 * instead, loudly and by name.
 *
 * The message names the lock, every holder (host:pid and connection), how long each has held
 * it and how long since it last showed progress. That is the diagnosis: the fix is on the
 * HOLDER's side - a long-running holder calls RsxLocks::still_working() (a task gets it from
 * every report, heartbeat() included), or the holder is hung and `php artisan
 * rsx:locks:status --kill-holder=<lock>` ends it.
 *
 * Deliberately NOT a Lock_Timeout_Exception. That class means "the caller's own wait budget
 * ran out" and its message is load-bearing (the framework updater greps it to classify a
 * retryable failure); a stall is a different fact about a different party. Not an outage
 * either: the daemon is up and answering, so this is an ordinary RuntimeException that ends
 * the request or run like any other error. Nothing catches it to carry on; the holder was
 * never touched and still holds the lock.
 */
#[Instantiatable]
class Lock_Stalled_Exception extends \RuntimeException
{
}
