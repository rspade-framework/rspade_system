<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseTransactionsManager;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Exceptions\Infrastructure_Outage;

/**
 * Rsx_DatabaseTransactionsManager - the database transactions manager the framework binds as
 * 'db.transactions' (Rsx_Framework_Provider::register()), so every connection the
 * DatabaseManager makes runs its after-commit callbacks through it.
 *
 * AN AFTER-COMMIT CALLBACK NEVER FAILS THE COMMIT. Laravel runs the callbacks registered with
 * DB::afterCommit() INSIDE Connection::commit(), after PDO::commit() has returned and the
 * transaction level was decremented, in a plain loop: the first callback that throws unwinds
 * through commit() - so the caller is told a write failed that committed - and every later
 * callback, and the TransactionCommitted event (File_Blob_Locks releases its deferred holds on
 * it), never runs. Here EACH callback runs on its own: one that throws is REPORTED through the
 * exception handler (the log; naming where the callback was declared) and the rest still run,
 * and commit() returns normally, so TransactionCommitted fires and the committing code completes
 * its bookkeeping. A callback is registered after the commit precisely because the write must
 * stand without it; its failure is reported, never answered.
 *
 * THE ONE EXCEPTION is an infrastructure outage (_is_contained() answers false: the lock daemon
 * or the database connection is gone - Infrastructure_Outage::is_outage()): it is not a failure
 * of the callback but of the platform the request runs on, so it still ends the request as a 500.
 * Every callback still runs first; then the first outage is rethrown from commit() (the data has
 * committed - the level is already decremented, so Site_Lock's commit reads it as committed and
 * never claims a rollback). TransactionCommitted does not fire for that commit.
 *
 * Scope: only callbacks run BY a commit. A callback registered with no transaction open runs at
 * once (DatabaseTransactionsManager::addCallback()) and its throw reaches the caller directly.
 * Rollback callbacks are unchanged.
 *
 * commit() restates the parent's (laravel/framework 13.x) with the one change at the callback
 * loop; re-read it against the parent on a framework upgrade.
 */
#[Instantiatable]
class Rsx_DatabaseTransactionsManager extends DatabaseTransactionsManager
{
    /**
     * Commit the root database transaction and execute callbacks - each contained.
     *
     * @param string $connection
     * @param int $levelBeingCommitted
     * @param int $newTransactionLevel
     * @return \Illuminate\Support\Collection|array
     */
    public function commit($connection, $levelBeingCommitted, $newTransactionLevel)
    {
        $this->stageTransactions($connection, $levelBeingCommitted);

        if (isset($this->currentTransaction[$connection])) {
            $this->currentTransaction[$connection] = $this->currentTransaction[$connection]->parent;
        }

        if (! $this->afterCommitCallbacksShouldBeExecuted($newTransactionLevel) &&
            $newTransactionLevel !== 0) {
            return [];
        }

        $this->pendingTransactions = $this->pendingTransactions->reject(
            fn ($transaction) => $transaction->connection === $connection &&
                $transaction->level >= $levelBeingCommitted
        )->values();

        [$for_this_connection, $for_other_connections] = $this->committedTransactions->partition(
            fn ($transaction) => $transaction->connection == $connection
        );

        $this->committedTransactions = $for_other_connections->values();

        $outage = null;

        foreach ($for_this_connection as $transaction) {
            foreach ($transaction->getCallbacks() as $callback) {
                try {
                    $callback();
                } catch (Throwable $e) {
                    if ($outage === null && !static::_is_contained($e)) {
                        $outage = $e;
                        continue;
                    }

                    static::_report_after_commit_failure($e, 'the after-commit callback ' . static::__origin($callback));
                }
            }
        }

        if ($outage !== null) {
            throw $outage;
        }

        return $for_this_connection;
    }

    /**
     * Whether a throwable met after a commit is contained (reported, the committed work answered
     * as it returned) - every one but an infrastructure outage (Infrastructure_Outage, the one
     * predicate for "the lock daemon or the database is gone").
     */
    public static function _is_contained(Throwable $e): bool
    {
        return !Infrastructure_Outage::is_outage($e);
    }

    /**
     * Report a failure met after a transaction committed, through the exception handler (the
     * log), wrapped so the report says the commit stands. Never thrown.
     *
     * @param Throwable $e
     * @param string $what What threw ('the after-commit callback declared at X.php:12')
     */
    public static function _report_after_commit_failure(Throwable $e, string $what): void
    {
        app(ExceptionHandler::class)->report(new RuntimeException(
            "After-commit failure: {$what} threw after its transaction committed. The committed write stands "
            . 'and the remaining after-commit work ran; this failure is reported, never answered to the caller. '
            . get_class($e) . ': ' . $e->getMessage(),
            0,
            $e
        ));
    }

    /** Where an after-commit callback was declared: file:line for a closure, else its name. */
    private static function __origin(callable $callback): string
    {
        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);

            return 'declared at ' . $reflection->getFileName() . ':' . $reflection->getStartLine();
        }

        if (is_array($callback)) {
            return (is_object($callback[0]) ? get_class($callback[0]) : $callback[0]) . '::' . $callback[1];
        }

        return is_string($callback) ? $callback : get_class($callback);
    }
}
