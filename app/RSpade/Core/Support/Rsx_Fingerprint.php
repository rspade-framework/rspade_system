<?php

namespace App\RSpade\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 *                         schema is keyed on (the model module's column map) and what a
 *                         build records about the database it was built against.
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
     * schema - so a build made against one describes the other, and their fingerprints must
     * be equal for anything to be able to say so.
     *
     * A database with no migrations table is an unmigrated database, which is an ordinary
     * state (a fresh install before its first migrate) and answers the fingerprint of the
     * empty set.
     */
    public static function applied_migrations(): string
    {
        $table = config('database.migrations', 'migrations');

        if (!Schema::hasTable($table)) {
            return md5('');
        }

        // Raw SQL: this runs mid-build, before any model can be asked, and the migrations
        // table has none.
        $names = array_map(
            fn ($row) => $row->migration,
            DB::select("SELECT `migration` FROM `{$table}`")
        );

        sort($names, SORT_STRING);

        return md5(implode("\n", $names));
    }
}
