<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

/**
 * The lock daemon (rsx-lockd) is gone: no connect attempt succeeded, it refused the hello,
 * or an open connection died. Thrown by Lockd_Connection - and so by every RsxLocks,
 * Site_Lock and Task_Pool call that needs the daemon - at the point the connection gives up.
 *
 * THE DAEMON IS INFRASTRUCTURE, LIKE MYSQLD. Losing it is an outage, and an outage ends the
 * WHOLE request as an HTTP 500 on every channel, exactly as a lost database connection does
 * (Infrastructure_Outage, the one predicate for both). There is no substitute path and no degraded
 * mode; the one planned exception is a maintenance window (RsxLocks' straggler path).
 *
 * FRAMEWORK-INTERNAL. It exists so the framework can recognise the outage at its own
 * boundaries - the Ajax per-call fault handler lets it through to fail the request, the
 * after-commit containment rethrows it, the task pool books its membership lost. It is not
 * an API: application code never catches it, names it or branches on it.
 *
 * Deliberately NOT a RuntimeException. A catch written for an ordinary lock failure - a
 * wait timeout (Lock_Timeout_Exception, a RuntimeException), a refusal - never receives an
 * outage, so contention can never be mistaken for a missing daemon.
 */
#[Instantiatable]
class Lockd_Unavailable_Exception extends \Exception
{
}
