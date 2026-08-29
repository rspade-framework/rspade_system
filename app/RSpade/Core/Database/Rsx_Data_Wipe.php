<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;

/**
 * THE one implementation of "destroy this application's data".
 *
 * Two commands need it and neither may own it:
 *
 *   - rsx:db:rebuild_provision_cache_snapshot wipes the database and the blob store so it can record what a
 *     migration run FROM ZERO produces, then restores the developer's live data.
 *   - rsx:database_and_storage_reset wipes both and leaves the operator on the
 *     fresh-install state.
 *
 * They differ entirely in what they do around the wipe and not at all in the wipe
 * itself, so the wipe lives here once. A second spelling of "drop the database" is a
 * second place for the drop to be subtly wrong, and this is not code that gets a second
 * chance to be right.
 *
 * NO TIMEOUTS anywhere below. How long a drop or a recursive delete takes is a function
 * of how much data there is, which this code does not get an opinion about.
 */
class Rsx_Data_Wipe
{
    /** Keys of the array clear_directory_contents() returns. */
    public const REMOVED_FILES = 'files';
    public const REMOVED_BYTES = 'bytes';

    /**
     * DROP + CREATE the default connection's database through the mysql CLI rather than
     * the framework's own connection: dropping the database a live PDO handle is bound to
     * leaves that handle pointing at nothing. The purge afterwards makes the next
     * framework query reconnect.
     */
    public static function recreate_database(): void
    {
        $database = self::database_name();

        $sql = 'DROP DATABASE IF EXISTS `' . $database . '`;'
            . ' CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';

        $command = 'mysql ' . self::client_flags() . ' -e ' . escapeshellarg($sql) . ' 2>&1';

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, self::mysql_env());

        if ($exit_code !== 0) {
            throw new \RuntimeException('Could not recreate the database ' . $database . ': ' . implode("\n", $output));
        }

        DB::purge(config('database.default'));
    }

    /**
     * Empty a directory, leaving the DIRECTORY ITSELF - and its mode and ownership - in
     * place. Returns what was removed, because a command that destroys files owes the
     * operator a number.
     *
     * The count is taken by walking the tree BEFORE deleting it: there is no way to size
     * a file after unlinking it, and the counts are the whole record of the blast radius.
     *
     * A root that does not exist is created empty rather than treated as an error. The
     * post-condition this function promises is "an empty directory is there", and that is
     * as true of a store that was never written to as of one that was.
     *
     * @return array{files: int, bytes: int}
     */
    public static function clear_directory_contents(string $root): array
    {
        $root = rtrim($root, '/');

        if (!is_dir($root)) {
            ensure_directory($root);

            return [self::REMOVED_FILES => 0, self::REMOVED_BYTES => 0];
        }

        $removed = self::measure_directory_contents($root);

        rmdir_recursive($root, false);

        return $removed;
    }

    /**
     * Files and bytes currently under $root, counted recursively. Symlinks are counted as
     * entries but never followed - their target's size is not this tree's bytes.
     *
     * @return array{files: int, bytes: int}
     */
    public static function measure_directory_contents(string $root): array
    {
        $files = 0;
        $bytes = 0;

        if (!is_dir($root)) {
            return [self::REMOVED_FILES => 0, self::REMOVED_BYTES => 0];
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink()) {
                $files++;
                continue;
            }

            if ($item->isFile()) {
                $files++;
                $bytes += (int) $item->getSize();
            }
        }

        return [self::REMOVED_FILES => $files, self::REMOVED_BYTES => $bytes];
    }

    /**
     * How many tables the application database holds right now - the "N tables dropped"
     * a reset reports, counted before it drops them.
     */
    public static function count_tables(): int
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = ?',
            [self::database_name()]
        );

        return (int) ($rows[0]->table_count ?? 0);
    }

    /** The database the DEFAULT connection points at - never a literal. */
    public static function database_name(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.database');
    }

    /** The default connection's config array. */
    public static function connection(): array
    {
        return (array) config('database.connections.' . config('database.default'));
    }

    /**
     * The credential channel: the password rides in the child's ENVIRONMENT, never on the
     * command line, where `ps` shows it to every user on the box. Same contract as
     * Rsx_Test_Command's dump/restore and Maint_Migrate::wait_for_mysql_ready().
     *
     * @return array<string, string>
     */
    public static function mysql_env(): array
    {
        $conn = self::connection();
        $password = (string) $conn['password'];

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    /** The shared `-h -P -u` prefix for the mysql/mysqldump client. */
    public static function client_flags(): string
    {
        $conn = self::connection();

        return '-h' . escapeshellarg((string) $conn['host'])
            . ' -P' . escapeshellarg((string) $conn['port'])
            . ' -u' . escapeshellarg((string) $conn['username']);
    }

    /**
     * Run a shell pipeline with its output STREAMED to our own stdout - the operator
     * watches a multi-minute dump go by instead of staring at nothing.
     *
     * exec_safe() is the framework's captured-output wrapper and is the wrong tool here:
     * it buffers to EOF, so a dump would print nothing until it finished. passthru() is
     * the streaming counterpart, wrapped in an EXPLICIT `bash -c` because it otherwise
     * hands the line to /bin/sh, which is dash here (project policy).
     *
     * $env is applied to THIS process for the duration of the call, which is how the
     * child inherits MYSQL_PWD without it ever appearing in argv.
     *
     * NO TIMEOUT: how long a dump or a restore takes is a function of the database's
     * size, which this code does not get an opinion about.
     *
     * @param array<string, string> $env
     */
    public static function stream(string $pipeline, array $env, string $context): void
    {
        $restore = [];
        foreach ($env as $key => $value) {
            $existing = getenv($key);
            $restore[$key] = $existing === false ? null : $existing;
            putenv($key . '=' . $value);
        }

        try {
            $exit_code = 0;
            \passthru('bash -c ' . escapeshellarg($pipeline), $exit_code);
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }

        if ($exit_code !== 0) {
            throw new \RuntimeException('Failed while ' . $context . ' (exit ' . $exit_code . ').');
        }
    }
}
