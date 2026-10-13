<?php

namespace App\RSpade\Core\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\MigrationPaths;

/**
 * ONE answer to "has this set of files changed since last time".
 *
 * Two shapes, both content-or-stat digests over a set of files, both used as CACHE KEYS by
 * code that must decide whether an expensive derivation can be skipped:
 *
 *   directories()      names+sizes+mtimes under a directory tree - the cheap shape, for
 *                      "did anything arrive here" (the environment-update gate, the test
 *                      runner's image fingerprint).
 *   migration_files()  the CONTENT of every migration file in the tree - the shape that
 *                      answers "which schema does this TREE define" (the test runner's
 *                      baseline key).
 *
 * Both existed as private methods on Rsx_Test_Command and are now one implementation, so a
 * second consumer cannot disagree with the first about what "changed" means.
 *
 * A third shape is not about files at all:
 *
 *   applied_migrations()  the NAMES of the migrations the connected database has had
 *                         applied - "which schema does this DATABASE have". A migration
 *                         file that is present is not a migration that has run, so this,
 *                         never migration_files(), is what a description of the live
 *                         schema is keyed on: it is the database half of
 *                         Manifest::get_state_key(), under which Rsx_Schema caches the
 *                         column maps.
 */
class Rsx_Fingerprint
{
    /**
     * sha1 over every file under the given directories: relative path, size and mtime.
     *
     * Stat-based, deliberately: it is asked on every build and must cost a directory walk,
     * not a read of every byte. A missing directory contributes nothing.
     *
     * @param array<int,string> $directories Absolute paths
     */
    public static function directories(array $directories): string
    {
        $rows = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $rows[] = substr($file->getPathname(), strlen($directory) + 1) . '|' . $file->getSize() . '|' . $file->getMTime();
            }
        }

        sort($rows, SORT_STRING);

        return sha1(implode("\n", $rows));
    }

    /**
     * md5 over the CONTENT of every migration file the tree ships.
     *
     * CONTENT, not mtime: a migration is the definition of the schema, and a checkout that
     * rewrites mtimes without changing a byte must not invalidate anything. It carries NO
     * database round trip by design - a caller that also needs the server version or a
     * schema-cache identity folds those in itself (Rsx_Test_Command does).
     */
    public static function migration_files(): string
    {
        $entries = [];

        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $entries[] = relative_path($file) . ':' . md5_file($file);
        }

        sort($entries);

        return md5(implode("\n", $entries));
    }

    /**
     * md5 over the sorted NAMES of every migration applied to the connected database.
     *
     * NAMES ONLY. Row ids, batch numbers and run times differ between two databases that
     * have had exactly the same migrations applied, and those two databases have the same
     * schema - so a cache entry written against one describes the other, and their
     * fingerprints must be equal for anything to be able to say so.
     *
     * ONE QUERY, AND NOT MEMOIZED. It is asked once per process by
     * Manifest::get_state_key(), which holds the memo and its reset; `migrate` asks it
     * directly, before and after a run, and must get the database's answer both times.
     *
     * A database with no migrations table is an unmigrated database, which is an ordinary
     * state (a fresh install before its first migrate) and answers the fingerprint of the
     * empty set. That is the ONE failure answered here: the table is not probed for first,
     * the query is simply run and "no such table" is read as "no migrations". Every other
     * failure - an unreachable server included - propagates.
     */
    public static function applied_migrations(): string
    {
        $table = config('database.migrations', 'migrations');

        // Raw SQL: the migrations table has no model, and this is asked before any model
        // could be.
        try {
            $rows = DB::select("SELECT `migration` FROM `{$table}`");
        } catch (QueryException $e) {
            if (self::__driver_error_code($e) !== self::MYSQL_NO_SUCH_TABLE) {
                throw $e;
            }

            return md5('');
        }

        $names = array_map(fn ($row) => $row->migration, $rows);

        sort($names, SORT_STRING);

        return md5(implode("\n", $names));
    }

    /** MySQL ER_NO_SUCH_TABLE. */
    private const MYSQL_NO_SUCH_TABLE = 1146;

    /**
     * The MySQL client and server error numbers that mean "there is no usable connection":
     * the server could not be reached, refused the credentials or the database, or dropped
     * the session. A failed QUERY on a working connection is never one of them.
     *
     *   2002 / 2003 / 2005   cannot connect (socket, host, unknown host)
     *   2006 / 2013          server has gone away / lost connection during query
     *   1040                 too many connections
     *   1044 / 1045 / 1049   access denied (database, user) / unknown database
     */
    private const MYSQL_CONNECTION_FAILURES = [2002, 2003, 2005, 2006, 2013, 1040, 1044, 1045, 1049];

    /**
     * Did this query fail because the database could not be reached at all?
     */
    public static function is_connection_failure(QueryException $e): bool
    {
        return in_array(self::__driver_error_code($e), self::MYSQL_CONNECTION_FAILURES, true);
    }

    /**
     * The driver's own error number behind a QueryException, or 0 when it carries none.
     *
     * A failed statement reports it in errorInfo[1] (getCode() is then the SQLSTATE string);
     * a failed CONNECT has no errorInfo and reports it as the PDOException's code.
     */
    private static function __driver_error_code(QueryException $e): int
    {
        $previous = $e->getPrevious();

        if (!$previous instanceof \PDOException) {
            return 0;
        }

        if (isset($previous->errorInfo[1])) {
            return (int) $previous->errorInfo[1];
        }

        return (int) $previous->getCode();
    }
}
