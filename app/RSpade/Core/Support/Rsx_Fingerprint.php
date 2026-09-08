<?php

namespace App\RSpade\Core\Support;

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
 *                      answers "could the database schema have moved", which is what the
 *                      model module keys its column introspection on.
 *
 * Both existed as private methods on Rsx_Test_Command and are now one implementation, so a
 * second consumer cannot disagree with the first about what "changed" means.
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
}
