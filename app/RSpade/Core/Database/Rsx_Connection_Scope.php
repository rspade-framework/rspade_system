<?php

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;

/**
 * The per-environment isolation token: md5(current database name @ database host).
 *
 * This is the ONE definition of "which environment am I" that box-wide coordination state
 * is namespaced under, so two RSpade environments sharing one machine's rsx-lockd daemon,
 * flock directory, or Redis never collide on state that is not theirs. The parallel test
 * runner's per-worker databases are the motivating case (each worker restores its own
 * database on the shared tmpfs mysqld and shares one Redis with its siblings), but the same
 * is true of a dev database and a test database side by side.
 *
 * A lock protects data, and the task worker registry counts workers that operate on data;
 * that data lives in a specific database on a specific host, so the (database, host) pair is
 * the natural boundary. Same pair -> same namespace (a real web cluster, every node on one
 * database and host, coordinates exactly as before); a different pair -> a disjoint
 * namespace.
 *
 * Consumers: RsxLocks (cluster + flock lock names) and Task_Worker_Registry (the worker
 * ZSET). Both MUST derive the token here so the spelling can never drift between them.
 *
 * The database NAME is read LIVE from the default connection, so it tracks a runtime switch
 * - RSpade only ever changes databases through Laravel's own config-plus-reconnect path,
 * never a raw USE, so getDatabaseName() is authoritative. It falls back to the configured
 * default when no connection has resolved a name yet (a caller before the DB layer is even
 * bound still gets a stable token rather than a crash). The HOST comes straight from config.
 * The pair is md5-hashed, so the token is a fixed 32-character, wire-, filesystem- and
 * Redis-safe string whatever the database name or host contains.
 */
class Rsx_Connection_Scope
{
    /**
     * The 32-character isolation token for the current database connection.
     *
     * @return string
     */
    public static function token(): string
    {
        $default = (string) config('database.default');

        $database = null;
        if (app()->bound('db')) {
            $database = DB::connection()->getDatabaseName();
        }
        if ($database === null || $database === '') {
            $database = (string) config("database.connections.{$default}.database");
        }

        $host = (string) config("database.connections.{$default}.host");

        return md5($database . '@' . $host);
    }
}
