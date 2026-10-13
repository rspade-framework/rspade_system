<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\Lifecycle;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Session\Audit_Session;

/**
 * Transaction_Rollback_Cache_Reset
 *
 * A rollback can undo a database write that the cache has ALREADY memoized, and nothing in
 * the cache knows it happened. The worked example is Type_Ref_Registry: class_to_id() lazily
 * INSERTs a _type_refs row and memoizes the class-to-id map in a process static AND in redis,
 * so a rollback leaves the row gone and both memos intact - and a later id_to_class() answers
 * a confidently WRONG class, which is a worse failure than a null.
 *
 * OWNER RULING: "if we can't ever *not* reset the cache at any moment, the cache is improperly
 * implemented." So this listener flushes the volatile cache on EVERY rollback - every nesting
 * level, not just the outermost, because a savepoint rollback undoes writes exactly the same
 * way. Losing a cache generation costs a recompute; keeping a stale one costs correctness.
 *
 * What makes that affordable is that non-cache state does not live in the cache database:
 * the reduced-volatility cache is database 2 and transient counters are database 3, and
 * locks and the task worker pool are not in Redis at all (rsx-lockd). The full map, and the _RVC_ convention,
 * are documented in the RsxCache class header.
 *
 * Registered from Rsx_Framework_Provider::boot(). Kept separate from Model_Lifecycle_Emissions
 * (whose own rollback listener discards buffered hooks) because this one is about CACHE
 * COHERENCE and fires regardless of whether anything was written through the model layer.
 */
class Transaction_Rollback_Cache_Reset
{
    /**
     * Whether the listener has been installed for this process.
     */
    private static bool $installed = false;

    /**
     * Install (once per process) the rollback cache reset.
     *
     * @return void
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        // Every nesting level: the event carries the connection and level, and neither
        // narrows what a rollback can have undone.
        Event::listen(TransactionRolledBack::class, function () {
            self::reset();
        });
    }

    /**
     * Drop every cached view of database state: the volatile cache, this process's copy
     * of the schema and the state key it is cached under (Rsx_Schema - a rollback can undo
     * the applied-migrations row the key was computed from), the type-ref registry's
     * process statics (which the cache flush alone cannot reach), and the audit-session
     * ids this process resolved (a row minted inside the rolled-back transaction is gone).
     *
     * Public because the test runner performs the same calls where no rollback event
     * fires - it drops and restores the whole test database instead, and swaps the
     * connection every one of these was filled from.
     *
     * @return void
     */
    public static function reset(): void
    {
        RsxCache::clear();
        Rsx_Schema::reset();
        Type_Ref_Registry::_reset_cached_state();
        Audit_Session::_reset();
    }
}
