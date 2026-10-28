<?php

namespace App\RSpade\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * A database connection of its own, for a write that must COMMIT whatever the caller's
 * transaction does. FRAMEWORK INTERNAL - documented in Core/Database/CLAUDE.md only.
 *
 *     $id = Rsx_Side_Connection::run(fn (Connection $db) => $db->table('_x')->insertGetId([...]));
 *
 * The callback runs on a second connection to the SAME database: what it writes commits on its
 * own, and a rollback of the caller's transaction does not undo it. That is right for shared,
 * append-only reference data whose rows mean the same whoever wrote them - the polymorphic type
 * registry, where rolling back the caller's work must never un-register a class.
 *
 * ITS OPPOSITE is deferring a side effect until the caller COMMITS (Model_Lifecycle_Emissions,
 * Realtime_Emissions check the transaction level and wait). Pick by the question: must this
 * happen even if the caller's work is thrown away (here), or only if it is kept (defer)?
 *
 * THREE RULES:
 *   - The config is cloned from the DEFAULT connection at the time of the call, and the
 *     connection is named for the database it reaches - so a process that switches its default
 *     database (the test runner does) never writes through a connection opened for the old one.
 *   - The connection is closed when the callback returns: a side connection held for a worker's
 *     lifetime is one more MySQL connection per process, for writes that are rare.
 *   - NEVER write a row the caller's own open transaction has written or locked. The side
 *     connection is a different session, so it waits on its parent's lock while the parent waits
 *     on it - a cycle MySQL cannot see, which ends only at the lock-wait timeout. Reference data
 *     the caller never touches in its transaction is the one safe subject.
 *
 * It sees only COMMITTED data: a row the caller inserted and has not committed is invisible here.
 */
class Rsx_Side_Connection
{
    /**
     * Run $callback on a side connection to the default connection's database and return what it
     * returns. The connection is opened for the call and closed after it.
     *
     * @param callable(Connection): mixed $callback
     * @return mixed
     */
    public static function run(callable $callback)
    {
        $default = (string) config('database.default');
        $config = (array) config("database.connections.{$default}");
        $name = $default . '__side_' . md5((string) ($config['database'] ?? '') . '@' . (string) ($config['host'] ?? ''));

        config(["database.connections.{$name}" => $config]);

        try {
            return $callback(DB::connection($name));
        } finally {
            DB::purge($name);
        }
    }
}
