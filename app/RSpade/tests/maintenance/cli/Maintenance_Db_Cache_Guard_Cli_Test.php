<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Commands\Database\Db_Rebuild_Provision_Cache_Snapshot_Command;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE SCHEMA-CACHE GUARD in bin/maintenance-mode.sh.
 *
 * While rsx:db:rebuild_provision_cache_snapshot holds a live backup on disk, the database this application
 * serves is cache-build scratch and the blob store is empty. Bringing php-fpm back up
 * over that serves an application that has lost its data - and invites writes into a
 * database that is about to be dropped. So `rsx:maintenance:disable` REFUSES while a
 * backup exists.
 *
 * The command's own step 7 deletes the backups BEFORE it lowers the window, so the
 * refusal is already false on the happy path; what this guard catches is the INTERRUPTED
 * build.
 *
 * Everything here runs with --no-services: no supervisord unit is ever touched, and the
 * flag is cleared unconditionally in teardown() so an aborted run cannot leave the box
 * in 503.
 */
class Maintenance_Db_Cache_Guard_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const REASON = 'framework test db-cache guard';
    const REFUSAL_MARKER = 'a schema-cache build is in progress or was interrupted';

    /** A sandbox blob root, so nothing here can name the real store. */
    protected static function __fake_blob_root(): string
    {
        return storage_path('rsx-tmp/test-db-cache-guard/files');
    }

    protected static function __work_dir(): string
    {
        return storage_path(Db_Rebuild_Provision_Cache_Snapshot_Command::WORK_DIR_RELATIVE);
    }

    protected static function __marker_path(): string
    {
        return static::__work_dir() . '/' . Db_Rebuild_Provision_Cache_Snapshot_Command::MARKER_FILE;
    }

    protected static function __dump_path(): string
    {
        return static::__work_dir() . '/' . Db_Rebuild_Provision_Cache_Snapshot_Command::LIVE_DUMP_FILE;
    }

    /** bash bin/maintenance-mode.sh <args>, returning [rc, output]. */
    protected static function __script(string $args): array
    {
        $out = [];
        $rc = 0;
        \exec_safe('bash ' . escapeshellarg(base_path('bin/maintenance-mode.sh')) . ' ' . $args, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    protected static function __enable(): void
    {
        [$rc, $output] = static::__script('enable --no-services --reason=' . escapeshellarg(self::REASON));
        static::__assert_equals(0, $rc, 'enable must succeed: ' . $output);
    }

    /**
     * REFUSE TO RUN OVER A REAL BUILD. These tests write a fake dump and marker into the
     * REAL work directory - that is the only place bin/maintenance-mode.sh looks - so if
     * an actual rsx:db:rebuild_provision_cache_snapshot is mid-flight (or was interrupted), running here would
     * clobber a file holding this developer's live database. Skip instead.
     *
     * @return bool true when it is safe to proceed
     */
    protected static function __safe_to_run(): bool
    {
        if (is_file(static::__dump_path()) || is_file(static::__marker_path())) {
            static::__skip('a real rsx:db:rebuild_provision_cache_snapshot build is in progress or was interrupted');

            return false;
        }

        return true;
    }

    /** Remove every artifact this test can create. Safe to call repeatedly. */
    protected static function __cleanup(): void
    {
        foreach ([static::__marker_path(), static::__dump_path()] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $sandbox = storage_path('rsx-tmp/test-db-cache-guard');
        if (is_dir($sandbox)) {
            rmdir_recursive($sandbox);
        }

        Framework_Maintenance::clear();
    }

    public static function teardown()
    {
        static::__cleanup();
    }

    /**
     * A live DUMP on disk is enough on its own: half a backup is still live data nobody
     * may serve around.
     */
    public static function test_a_live_dump_refuses_the_disable()
    {
        if (!static::__safe_to_run()) {
            return;
        }

        try {
            static::__enable();

            ensure_directory(static::__work_dir());
            file_put_contents(static::__dump_path(), 'not a real dump');
            file_put_contents(static::__marker_path(), static::__fake_blob_root() . "\n");

            [$rc, $output] = static::__script('disable --no-services');

            static::__assert_equals(1, $rc, 'the disable must be refused: ' . $output);
            static::__assert_contains(self::REFUSAL_MARKER, $output);
            static::__assert_contains(static::__dump_path(), $output, 'the refusal must name the backup');
            static::__assert_contains('php artisan rsx:db:rebuild_provision_cache_snapshot', $output, 'the refusal must name the way out');
            static::__assert_true(
                Framework_Maintenance::is_active_on_disk(),
                'the window must still be up after a refusal'
            );
        } finally {
            static::__cleanup();
        }
    }

    /**
     * The BLOB half alone also refuses - and it is found through the marker, which is the
     * only way a pre-boot bash script can learn where the blob store lives.
     */
    public static function test_a_blob_backup_named_by_the_marker_refuses_the_disable()
    {
        if (!static::__safe_to_run()) {
            return;
        }

        try {
            static::__enable();

            ensure_directory(static::__work_dir());
            ensure_directory(static::__fake_blob_root() . Db_Rebuild_Provision_Cache_Snapshot_Command::BLOB_BACKUP_SUFFIX);
            file_put_contents(static::__marker_path(), static::__fake_blob_root() . "\n");

            [$rc, $output] = static::__script('disable --no-services');

            static::__assert_equals(1, $rc, 'the disable must be refused: ' . $output);
            static::__assert_contains(self::REFUSAL_MARKER, $output);
            static::__assert_contains(
                static::__fake_blob_root() . Db_Rebuild_Provision_Cache_Snapshot_Command::BLOB_BACKUP_SUFFIX,
                $output,
                'the refusal must name the blob backup the marker pointed at'
            );
        } finally {
            static::__cleanup();
        }
    }

    /**
     * A marker with NO backups left beside it is not a refusal. This is the state step 7
     * passes through on its way out - the backups are already gone - and it is why the
     * command needs no override token.
     */
    public static function test_a_marker_with_no_backups_does_not_refuse()
    {
        if (!static::__safe_to_run()) {
            return;
        }

        try {
            static::__enable();

            ensure_directory(static::__work_dir());
            file_put_contents(static::__marker_path(), static::__fake_blob_root() . "\n");

            [$rc, $output] = static::__script('disable --no-services');

            static::__assert_equals(0, $rc, 'nothing is at risk, so nothing is refused: ' . $output);
            static::__assert_true(!str_contains($output, self::REFUSAL_MARKER), $output);
            static::__assert_false(Framework_Maintenance::is_active_on_disk(), 'the flag must be gone');
        } finally {
            static::__cleanup();
        }
    }

    /** --force is the deliberate operator override, exactly as it is for the merge guard. */
    public static function test_force_overrides_the_refusal()
    {
        if (!static::__safe_to_run()) {
            return;
        }

        try {
            static::__enable();

            ensure_directory(static::__work_dir());
            file_put_contents(static::__dump_path(), 'not a real dump');
            file_put_contents(static::__marker_path(), static::__fake_blob_root() . "\n");

            [$rc, $output] = static::__script('disable --no-services --force');

            static::__assert_equals(0, $rc, '--force must lower the window: ' . $output);
            static::__assert_false(Framework_Maintenance::is_active_on_disk());
        } finally {
            static::__cleanup();
        }
    }

    /** No marker, no backups, no guard: the ordinary disable is untouched. */
    public static function test_an_ordinary_disable_is_unaffected()
    {
        if (!static::__safe_to_run()) {
            return;
        }

        try {
            static::__enable();

            [$rc, $output] = static::__script('disable --no-services');

            static::__assert_equals(0, $rc, 'the ordinary disable must succeed: ' . $output);
            static::__assert_false(Framework_Maintenance::is_active_on_disk());
        } finally {
            static::__cleanup();
        }
    }
}
