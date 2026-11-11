<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Database\LostConnectionException;
use PDOException;
use Throwable;
use App\RSpade\Core\Locks\Lockd_Unavailable_Exception;

/**
 * Infrastructure_Outage - THE one answer to "is this throwable an outage of the platform the
 * request runs on?": the lock daemon gone (Lockd_Unavailable_Exception) or the database
 * connection gone (a refused, unreachable or lost MySQL connection).
 *
 * An outage is not a failure of the code that met it, so it is never answered like one:
 *
 *   - Ajax: it escapes the per-call fault handling and fails the whole HTTP request with
 *     status 500 (Ajax::call_envelope(), Ajax_Exception_Handler); no later call of the batch
 *     runs.
 *   - External API and companion channel: the channel's 500 (internal_error).
 *   - Page: the framework's 500 error page.
 *   - Task: the run settles FAILED; a command exits non-zero.
 *   - After a commit: never contained (Rsx_DatabaseTransactionsManager::_is_contained()) - every
 *     after-commit callback still runs, then the outage ends the request without claiming
 *     a rollback.
 *
 * Every caller is told USER_MESSAGE (through Rsx_Diagnostics::user_message()) in place of
 * the generic error sentence - the Ajax 500 body, the external API's internal_error and the
 * 500 page; a developer caller still gets the exception's own detail.
 *
 * It is reported once, by the front controller, like any fault. There is no substitute path and no
 * degraded mode. FRAMEWORK-INTERNAL: framework code that must catch broadly (a per-item
 * catch that records an item's failure and moves on) lets an outage through with
 * rethrow_if_outage(); application code never names an outage at all.
 *
 * A database outage is recognised by the MySQL client error code of a PDOException in the
 * chain (Laravel's QueryException is one), never by message text.
 */
class Infrastructure_Outage
{
    /**
     * What every caller is told about an outage. User-safe: it names no service, host or
     * error - only that the failure is temporary and worth retrying.
     */
    public const USER_MESSAGE = 'The service is temporarily unavailable. Please try again in a moment.';

    /**
     * MySQL client / server error codes that mean the connection to the database is gone:
     * 2002 CR_CONNECTION_ERROR, 2003 CR_CONN_HOST_ERROR, 2005 CR_UNKNOWN_HOST,
     * 2006 CR_SERVER_GONE_ERROR, 2013 CR_SERVER_LOST, 1053 ER_SERVER_SHUTDOWN.
     */
    private const DATABASE_CONNECTION_CODES = [2002, 2003, 2005, 2006, 2013, 1053];

    /**
     * Is $e - or anything it wraps - an infrastructure outage?
     *
     * @param Throwable $e
     * @return bool
     */
    public static function is_outage(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof Lockd_Unavailable_Exception || $current instanceof LostConnectionException) {
                return true;
            }

            if ($current instanceof PDOException && static::__is_database_connection_failure($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rethrow $e when it is an outage; return otherwise. For a framework catch that must be
     * broad (it records one item's failure and carries on with the next) - an outage is never
     * one item's failure.
     *
     * @param Throwable $e
     * @return void
     * @throws Throwable $e, when it is an outage
     */
    public static function rethrow_if_outage(Throwable $e): void
    {
        if (static::is_outage($e)) {
            throw $e;
        }
    }

    private static function __is_database_connection_failure(PDOException $e): bool
    {
        // errorInfo carries the driver code for a failed statement; a failed connect (the
        // PDO constructor) leaves it unset and carries the code as the exception code.
        $code = $e->errorInfo[1] ?? $e->getCode();

        return is_numeric($code) && in_array((int) $code, self::DATABASE_CONNECTION_CODES, true);
    }
}
