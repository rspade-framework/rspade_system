<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Database;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BUILD THE SHIPPED SCHEMA CACHE.
 *
 * A fresh install replays every migration ever written to arrive at a schema that is,
 * by definition, already known. This command records that known end state once - as a
 * mysqldump plus an archive of whatever blobs the migrations wrote - so an empty
 * database restores it and then applies only the migrations NEWER than the cache.
 *
 * ======================================================================================
 * IT OPERATES ON THE LIVE DATABASE, SO IT BACKS THE LIVE DATABASE UP FIRST
 * ======================================================================================
 *
 * The cache must contain the migrations' output and NOTHING else, which means the build
 * runs against an empty database and an empty blob store - this developer's own. So the
 * sequence is: back the live data up, wipe, migrate from zero, dump the result, wipe
 * again, restore the live data, and only THEN delete the backup.
 *
 * Two properties make that safe to interrupt:
 *
 *   - THE BACKUP IS COMPLETE BEFORE ANYTHING IS DESTROYED. The dump is written to a
 *     .partial and renamed on success; the blob store is MOVED (a rename), not copied.
 *     No step that destroys live data runs until its backup is in place, so an
 *     interrupt before that point has nothing to recover.
 *   - THE BACKUP IS DELETED LAST. Recovery - from a Ctrl-C, an exception, or a later
 *     re-run - restores from a backup that is still on disk. If a previous run was
 *     interrupted and left backups behind, this command REFUSES TO OVERWRITE THEM and
 *     uses them as the restore source instead (they hold the live data; whatever is in
 *     the database and the blob store right now is cache-build residue).
 *
 * While backups exist, `rsx:maintenance:disable` REFUSES to bring services back up -
 * see bin/maintenance-mode.sh. Step 7 deletes them BEFORE it lowers the window, so the
 * refusal condition is already false by then and no override flag is needed.
 *
 * ======================================================================================
 * WHAT IT PRODUCES
 * ======================================================================================
 *
 *   rsx/resource/db/schema_cache.sql.gz    the migrated database, gzipped mysqldump
 *   rsx/resource/db/uploads_cache.tar.gz   the blob store's contents, relative paths
 *   rsx/resource/db/README.md              what those two files are
 *
 * resource/ is manifest-invisible and SHIPS with the application, so the cache travels
 * with the code whose migrations produced it. The restore side lives in
 * Maint_Migrate::maybe_restore_schema_cache().
 */
class Db_Dump_Cache_Command extends Command
{
    protected $signature = 'rsx:db:dump_cache';

    protected $description = 'Rebuild the shipped schema cache (rsx/resource/db/schema_cache.sql.gz + uploads_cache.tar.gz) that a fresh install restores instead of replaying every migration. Backs the live database and blob store up, builds the cache from zero, and restores them.';

    /**
     * Test seam: root the produced cache files somewhere other than rsx/resource/db so a
     * test never writes into the shipped tree. `--_` convention (see Rsx_Internal_Flags).
     */
    public const CACHE_DIR_FLAG = '--_cache-dir';

    /** The two artifacts, by basename. Named once; every consumer reads them from here. */
    public const SCHEMA_CACHE_FILE = 'schema_cache.sql.gz';
    public const UPLOADS_CACHE_FILE = 'uploads_cache.tar.gz';

    /** Default (shipped) location of the cache, relative to the PROJECT root. */
    public const CACHE_DIR_RELATIVE = 'rsx/resource/db';

    /** Where the live backup and the in-progress marker live, relative to the storage root. */
    public const WORK_DIR_RELATIVE = 'rsx-tmp/db_cache';

    public const LIVE_DUMP_FILE = 'live_db.sql.gz';
    public const MARKER_FILE = '.in_progress';

    /** Suffix appended to the blob root to name its sibling backup. */
    public const BLOB_BACKUP_SUFFIX = '_tmp';

    /**
     * The recovery vocabulary. __recovery_plan() returns an ordered list of these and
     * __execute_recovery_plan() performs them; naming them makes the state machine
     * assertable without a database (see the db_cache test concern).
     */
    public const ACTION_RECREATE_DATABASE = 'recreate_database';
    public const ACTION_RESTORE_DATABASE = 'restore_database';
    public const ACTION_CLEAR_BUILD_BLOB_STORE = 'clear_build_blob_store';
    public const ACTION_MOVE_BLOB_BACKUP_BACK = 'move_blob_backup_back';
    public const ACTION_DELETE_LIVE_DUMP = 'delete_live_dump';

    /** Keys of the array backup_decision() returns. */
    public const DECISION_TAKE_DUMP = 'take_dump';
    public const DECISION_MOVE_BLOB_STORE = 'move_blob_store';
    public const DECISION_REUSE_EXISTING = 'reuse_existing';

    // ---------------------------------------------------------------------------------
    // THE STATE MACHINE
    //
    // Recovery decides what to undo from these four facts and nothing else. Each is set
    // only AFTER the operation it describes has completed, which is what makes an
    // interrupt at any point recoverable: a half-finished step leaves its flag false,
    // and recovery correctly does nothing about it.
    // ---------------------------------------------------------------------------------

    /** A COMPLETE live database dump exists at $this->live_dump_path (ours or a prior run's). */
    protected bool $live_dump_ok = false;

    /** A live blob-store backup exists at <blob_root>_tmp (ours or a prior run's). */
    protected bool $blob_backup_ok = false;

    /** The database no longer holds live data (it has been dropped at least once). */
    protected bool $db_is_build_state = false;

    /** The blob store directory no longer holds live blobs (it is a build store). */
    protected bool $blob_store_is_build_state = false;

    /** We raised the maintenance window and are responsible for lowering it. */
    protected bool $maintenance_raised = false;

    /** Recovery has already run - never run it twice (finally + shutdown both reach it). */
    protected bool $recovery_done = false;

    protected string $work_dir;
    protected string $live_dump_path;
    protected string $marker_path;
    protected string $cache_dir;
    protected string $blob_root;
    protected string $blob_backup;

    public function handle(): int
    {
        // Step 0 - REFUSAL. A sealed build's source tree is immutable and its services are
        // serving; wiping the database underneath it is not a thing this command may
        // decide to do. Development is where migrations are authored, and it is the only
        // place a cache built from them means anything.
        if (!Rsx::is_development()) {
            $this->error('[ERROR] rsx:db:dump_cache runs in DEVELOPMENT mode only (this box is ' . Rsx::get_mode_label() . ').');
            $this->info('');
            $this->info('  Building the cache DROPS AND RECREATES this database and empties the blob');
            $this->info('  store, restoring both afterwards. A sealed debug/production build serves');
            $this->info('  live traffic from a read-only tree - neither the wipe nor the rewritten');
            $this->info('  rsx/resource/db artifacts belong there.');
            $this->info('');
            $this->info('  Build the cache in development and ship it with the code:');
            $this->info('      php artisan rsx:db:dump_cache');

            return 1;
        }

        $this->__resolve_paths();

        // A Ctrl-C is the interrupt this command is most likely to see, and it must run
        // the same recovery an exception runs. Async signals turn SIGINT/SIGTERM into a
        // throw at the next VM tick, which the try/finally below catches like any other
        // failure. The shutdown hook is the belt for a fatal that never reaches finally.
        $this->__install_signal_handlers();
        register_shutdown_function(function (): void {
            $this->__recover_live_data_if_needed();
        });

        try {
            $this->__step_enter_maintenance();
            $this->__step_back_up_live_data();
            $this->__step_reset_database();
            $this->__step_migrate_from_zero();
            $this->__step_write_cache();
            $this->__step_reset_after_build();
            $this->__step_restore_live_data();
        } catch (\Throwable $e) {
            $this->error('');
            $this->error('[ERROR] ' . $e->getMessage());
            $this->__recover_live_data_if_needed();

            return 1;
        }

        // Step 7 (tail) - the backup is gone, so the maintenance-disable refusal condition
        // is already false. Nothing to override.
        $this->recovery_done = true;
        $this->__lower_maintenance();

        $this->info('');
        $this->info('[OK] Schema cache rebuilt.');
        $this->info('     ' . $this->cache_dir . '/' . self::SCHEMA_CACHE_FILE . '  ' . $this->__human_size($this->cache_dir . '/' . self::SCHEMA_CACHE_FILE));
        $this->info('     ' . $this->cache_dir . '/' . self::UPLOADS_CACHE_FILE . '  ' . $this->__human_size($this->cache_dir . '/' . self::UPLOADS_CACHE_FILE));

        return 0;
    }

    // =================================================================================
    // STEPS
    // =================================================================================

    /**
     * [1/7] Enter maintenance mode - "migration mode" in the operator's words.
     *
     * The real mechanism, through the real command, so services stop in the real order:
     * task workers are killed, then realtime / fpc / php-fpm / rsx-lockd / redis go down.
     * MySQL is deliberately NOT one of them - this command needs it.
     *
     * migrate's own `.migrating` flag is NOT raised: it belongs to the datadir-snapshot
     * path, which step 4 skips (see __step_migrate_from_zero), and everything it would
     * buy - the 503, the refusal of automated runners - the maintenance window already
     * provides. Raising a flag nothing in this flow reads would only be one more piece of
     * state an interrupt could strand.
     */
    protected function __step_enter_maintenance(): void
    {
        $this->info('[1/7] Entering maintenance mode...');

        $exit_code = Rsx_Artisan::passthru('rsx:maintenance:enable', ['--reason=schema cache build']);
        if ($exit_code !== 0) {
            throw new \RuntimeException('Could not enter maintenance mode (exit ' . $exit_code . '). Nothing has been touched.');
        }

        $this->maintenance_raised = true;
    }

    /**
     * [2/7] Back the live data up: a gzipped mysqldump, and a sibling RENAME of the blob
     * store. Neither the database nor the store is touched until its own backup is in
     * place, so an interrupt inside this step never costs live data.
     *
     * THE NO-OVERWRITE RULE. A backup already sitting here is a previous run's, it holds
     * THIS developer's live data, and whatever the database and blob store hold right now
     * is that run's cache-build residue. Overwriting the backup with the residue would
     * destroy the only copy. So an existing backup is kept and used, and only the MISSING
     * half is taken - the two halves are independent.
     */
    protected function __step_back_up_live_data(): void
    {
        $this->info('[2/7] Backing up live data...');

        ensure_directory($this->work_dir);

        // An interrupted dump leaves a .partial. It is not a backup and must never be
        // mistaken for one.
        $partial = $this->live_dump_path . '.partial';
        if (is_file($partial)) {
            $this->warn('      Discarding an incomplete dump from an interrupted run: ' . $partial);
            unlink($partial);
        }

        $dump_exists = is_file($this->live_dump_path);
        $blob_backup_exists = is_dir($this->blob_backup);
        $decision = self::backup_decision($dump_exists, $blob_backup_exists, is_dir($this->blob_root));

        if ($decision[self::DECISION_REUSE_EXISTING]) {
            $this->warn('      A previous run was interrupted and left live backups in place.');
            $this->warn('      They will NOT be overwritten - they are the restore source.');
            if ($dump_exists) {
                $this->warn('        ' . $this->live_dump_path);
            }
            if ($blob_backup_exists) {
                $this->warn('        ' . $this->blob_backup);
            }
        }

        // The marker names the blob root for bin/maintenance-mode.sh, which runs pre-boot
        // and has no config to ask. Written BEFORE the first backup so the refusal is
        // armed for the whole window in which a backup can exist.
        $this->__write_marker();

        if (!$decision[self::DECISION_TAKE_DUMP]) {
            $this->live_dump_ok = true;
        } else {
            $this->__dump_database($this->__database_name(), $partial);
            if (!rename($partial, $this->live_dump_path)) {
                throw new \RuntimeException('Could not finalize the live dump: ' . $partial . ' -> ' . $this->live_dump_path);
            }
            $this->live_dump_ok = true;
            $this->info('      Live database dumped to ' . $this->live_dump_path . ' (' . $this->__human_size($this->live_dump_path) . ')');
        }

        if ($blob_backup_exists) {
            // A previous run already moved the live store aside; whatever sits at the blob
            // root now is that run's build residue.
            $this->blob_backup_ok = true;
            $this->blob_store_is_build_state = true;
        } elseif ($decision[self::DECISION_MOVE_BLOB_STORE]) {
            if (!rename($this->blob_root, $this->blob_backup)) {
                throw new \RuntimeException('Could not move the blob store aside: ' . $this->blob_root . ' -> ' . $this->blob_backup);
            }
            // ONE statement, because the rename makes both true at the same instant: the
            // backup now holds the live blobs, and whatever the blob root becomes from here
            // is build state. An interrupt between two assignments would strand the store.
            $this->blob_backup_ok = $this->blob_store_is_build_state = true;
            $this->info('      Blob store moved aside to ' . $this->blob_backup);
        } else {
            // No blob store on disk at all - nothing to back up, and nothing to restore.
            $this->blob_store_is_build_state = true;
            $this->info('      No blob store at ' . $this->blob_root . ' - nothing to move aside.');
        }

        // An empty store in its place, whatever we found.
        ensure_directory($this->blob_root);
    }

    /**
     * THE NO-OVERWRITE RULE, as a pure function of what is on disk.
     *
     * Extracted for the same reason __recovery_plan() is: this is a data-safety rule, and
     * a rule that can only be exercised by actually destroying a database is a rule
     * nobody checks. Step 2 drives from exactly this, so the rule under test is the rule
     * that runs.
     *
     *   take_dump        no dump on disk -> take one. A dump that IS on disk was written
     *                    by an interrupted run, holds the live database, and must never be
     *                    overwritten by the cache-build residue that is in the database now.
     *   move_blob_store  the blob backup does not exist AND a blob store does -> move it
     *                    aside. If the backup already exists, the store sitting at the blob
     *                    root is that earlier run's residue, not live data, so moving it
     *                    would overwrite the only copy of the live blobs.
     *   reuse_existing   at least one backup was already there. Purely informational - it
     *                    is what the operator is told - but it is the whole point: the two
     *                    halves are INDEPENDENT, and only the missing one is taken.
     *
     * @return array<string, bool>
     */
    public static function backup_decision(bool $dump_exists, bool $blob_backup_exists, bool $blob_root_exists): array
    {
        return [
            self::DECISION_TAKE_DUMP => !$dump_exists,
            self::DECISION_MOVE_BLOB_STORE => !$blob_backup_exists && $blob_root_exists,
            self::DECISION_REUSE_EXISTING => $dump_exists || $blob_backup_exists,
        ];
    }

    /** [3/7] Drop and recreate the database. Live data is on disk in the dump by now. */
    protected function __step_reset_database(): void
    {
        $this->info('[3/7] Resetting the database...');

        $this->__recreate_database();
        $this->db_is_build_state = true;
    }

    /**
     * [4/7] Migrate an empty database from zero.
     *
     * --_no-initial-user: the cache must carry NO user. A fresh install's first-run
     * screen creates user 1 after the restore, and an account baked into the cache would
     * take that id.
     *
     * --_no-snapshot: skip the datadir snapshot. The snapshot exists to make a bad
     * migration undoable, and here there is nothing to undo - the database was created
     * empty three lines ago and the only data worth protecting is already in the live
     * dump. Taking it would stop MySQL, copy the whole datadir and start it again, purely
     * to protect an empty database. Both flags follow the `--_` convention: internal, no
     * InputOption, stripped from argv pre-boot.
     */
    protected function __step_migrate_from_zero(): void
    {
        $this->info('[4/7] Migrating from zero...');
        $this->info('');

        // DELETE THE OLD CACHE FIRST, or this is not a rebuild.
        //
        // migrate restores the shipped cache whenever it finds no tables at all - and no
        // tables at all is exactly the state step 3 just created. Left in place, the build
        // restored the artifact it exists to replace and then applied only the migrations
        // newer than it, so THE CACHE REGENERATED FROM ITSELF: whatever was baked in stayed
        // baked in forever, deleting the migration that seeded a row changed nothing
        // (the row arrived in the restore, not from the chain), and the deleted migration
        // stayed marked as already-run in the restored _migrations table. A successful
        // build silently wrote a stale artifact. Field report, 2026-08-24.
        //
        // Safe to delete outright: these two files are COMMITTED, so a build that fails
        // before writing the new ones leaves them one `git checkout` away, and the live
        // database and blob store are already backed up by steps 1-2 either way.
        foreach ([self::SCHEMA_CACHE_FILE, self::UPLOADS_CACHE_FILE] as $file) {
            $path = $this->cache_dir . '/' . $file;
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException(
                    'Could not delete the existing cache at ' . $path . '. The rebuild would have '
                    . 'restored it instead of replaying the migrations, so it was not attempted.'
                );
            }
        }

        $exit_code = Rsx_Artisan::passthru('migrate', ['--force', '--_no-initial-user', Maint_Migrate::NO_SNAPSHOT_FLAG]);
        if ($exit_code !== 0) {
            throw new \RuntimeException('The migration run failed (exit ' . $exit_code . '). The cache was NOT written.');
        }
    }

    /** [5/7] Dump the migrated database and archive whatever blobs the migrations wrote. */
    protected function __step_write_cache(): void
    {
        $this->info('');
        $this->info('[5/7] Writing the schema cache to ' . $this->cache_dir . '...');

        ensure_directory($this->cache_dir);

        $schema_target = $this->cache_dir . '/' . self::SCHEMA_CACHE_FILE;
        $uploads_target = $this->cache_dir . '/' . self::UPLOADS_CACHE_FILE;

        // Same .partial-then-rename discipline as the live dump: a crash mid-write must
        // never leave a truncated cache that a fresh install would restore.
        $this->__dump_database($this->__database_name(), $schema_target . '.partial');
        if (!rename($schema_target . '.partial', $schema_target)) {
            throw new \RuntimeException('Could not finalize the schema cache: ' . $schema_target);
        }

        // -C <root> . : relative paths inside the archive, so extraction lands in ANY blob
        // root (the test-isolated one included).
        $this->__stream(
            'tar -czf ' . escapeshellarg($uploads_target . '.partial')
            . ' -C ' . escapeshellarg($this->blob_root) . ' .',
            [],
            'archiving the blob store'
        );
        if (!rename($uploads_target . '.partial', $uploads_target)) {
            throw new \RuntimeException('Could not finalize the uploads cache: ' . $uploads_target);
        }

        $this->__write_cache_readme();
    }

    /** [6/7] Wipe the cache-build database and blob store. */
    protected function __step_reset_after_build(): void
    {
        $this->info('[6/7] Clearing the cache-build database and blob store...');

        $this->__recreate_database();
        $this->__clear_build_blob_store();
    }

    /**
     * [7/7] Put the live data back, and only then delete the backup.
     *
     * Ordering is the whole safety property: the dump is deleted after BOTH restores have
     * succeeded, so an interrupt anywhere in this step still leaves a complete backup for
     * the next run (or the recovery path) to restore from.
     */
    protected function __step_restore_live_data(): void
    {
        $this->info('[7/7] Restoring live data...');

        $this->__execute_recovery_plan($this->__recovery_plan());

        $this->info('      Live data restored.');
    }

    // =================================================================================
    // RECOVERY
    // =================================================================================

    /**
     * Put the live data back after an interrupt or an exception.
     *
     * WHICH STEPS APPLY IS DECIDED BY THE FOUR STATE FLAGS, not by guessing where we
     * were. Concretely:
     *
     *   interrupted in [1]  nothing set  -> lower maintenance, done. No data was touched.
     *   interrupted in [2]  during the dump: live_dump_ok is still false and the database
     *                       was never dropped, so there is nothing to restore - the
     *                       .partial is discarded by the next run.
     *                       after the dump, during the blob rename: blob_backup_ok
     *                       decides whether the rename happened; the store is put back
     *                       either way and the dump is deleted last.
     *   interrupted in [3]-[6]  db_is_build_state and blob_store_is_build_state are both
     *                       set: recreate the database, restore the dump, clear the build
     *                       store, move the backup back, delete the dump.
     *   interrupted in [7]  identical to the above - the restore is idempotent, which is
     *                       exactly why the dump is not deleted until it has finished.
     *
     * If recovery itself fails the backups are LEFT IN PLACE and named, the maintenance
     * window stays UP (its disable refusal keeps it there while a backup exists), and
     * re-running this command picks them up under the no-overwrite rule.
     */
    protected function __recover_live_data_if_needed(): void
    {
        if ($this->recovery_done) {
            return;
        }
        $this->recovery_done = true;

        $plan = $this->__recovery_plan();

        // Nothing was destroyed and no backup was taken - there is nothing to put back.
        if ($plan === [self::ACTION_DELETE_LIVE_DUMP] && !$this->live_dump_ok) {
            $this->__discard_marker_if_no_backups();
            $this->__lower_maintenance();

            return;
        }

        $this->warn('');
        $this->warn('[WARNING] Interrupted - restoring live data.');

        try {
            $this->__execute_recovery_plan($plan);
        } catch (\Throwable $e) {
            $this->error('');
            $this->error('[ERROR] Recovery failed: ' . $e->getMessage());
            $this->error('');
            $this->error('  YOUR LIVE DATA IS STILL ON DISK. Nothing below has been deleted:');
            if (is_file($this->live_dump_path)) {
                $this->error('    ' . $this->live_dump_path);
            }
            if (is_dir($this->blob_backup)) {
                $this->error('    ' . $this->blob_backup);
            }
            $this->error('');
            $this->error('  Re-running the command completes the restore from exactly these backups:');
            $this->error('      php artisan rsx:db:dump_cache');
            $this->error('');
            $this->error('  Maintenance mode is left UP on purpose, and rsx:maintenance:disable refuses');
            $this->error('  to lower it while those backups exist.');

            return;
        }

        $this->info('[OK] Live data restored. The cache was NOT rebuilt.');
        $this->__lower_maintenance();
    }

    /**
     * THE RECOVERY STATE MACHINE, as a pure function of the four state flags.
     *
     * Extracted so it can be reasoned about - and tested - without a database, a blob
     * store or an interrupt. Step 7 and the recovery path both execute the plan this
     * returns, so there is exactly one description of "put the live data back" and it is
     * the one under test.
     *
     * Read it as: undo what was actually done, in the reverse order it was done, and
     * delete the backup only after everything else has succeeded.
     *
     *   db_is_build_state           the database was dropped -> recreate it, and refill it
     *                               from the dump if a complete dump exists.
     *   blob_store_is_build_state   the blob root holds build residue -> empty it.
     *   blob_backup_ok              <blob_root>_tmp holds the live blobs -> move it back.
     *   (always, last)              delete the live dump. LAST is the safety property:
     *                               every action above is idempotent and re-runnable for
     *                               exactly as long as the dump is still there.
     *
     * @return array<int, string> ordered ACTION_* tokens
     */
    public function __recovery_plan(): array
    {
        $plan = [];

        if ($this->db_is_build_state) {
            $plan[] = self::ACTION_RECREATE_DATABASE;

            if ($this->live_dump_ok) {
                $plan[] = self::ACTION_RESTORE_DATABASE;
            }
        }

        if ($this->blob_store_is_build_state) {
            $plan[] = self::ACTION_CLEAR_BUILD_BLOB_STORE;
        }

        if ($this->blob_backup_ok) {
            $plan[] = self::ACTION_MOVE_BLOB_BACKUP_BACK;
        }

        $plan[] = self::ACTION_DELETE_LIVE_DUMP;

        return $plan;
    }

    /**
     * Test seam: declare the state the plan is computed from. No production caller - the
     * steps set these flags as they complete their own work.
     */
    public function __set_recovery_state(
        bool $live_dump_ok,
        bool $blob_backup_ok,
        bool $db_is_build_state,
        bool $blob_store_is_build_state
    ): void {
        $this->live_dump_ok = $live_dump_ok;
        $this->blob_backup_ok = $blob_backup_ok;
        $this->db_is_build_state = $db_is_build_state;
        $this->blob_store_is_build_state = $blob_store_is_build_state;
    }

    /** Run a plan produced by __recovery_plan(). Throws on the first action that fails. */
    protected function __execute_recovery_plan(array $plan): void
    {
        foreach ($plan as $action) {
            switch ($action) {
                case self::ACTION_RECREATE_DATABASE:
                    $this->__recreate_database();
                    $this->db_is_build_state = false;
                    break;

                case self::ACTION_RESTORE_DATABASE:
                    $this->__restore_database_from_dump($this->__database_name(), $this->live_dump_path);
                    break;

                case self::ACTION_CLEAR_BUILD_BLOB_STORE:
                    $this->__clear_build_blob_store();
                    $this->blob_store_is_build_state = false;
                    break;

                case self::ACTION_MOVE_BLOB_BACKUP_BACK:
                    // rmdir, not rm -rf: the clear above leaves an EMPTY directory behind
                    // and rename() will not replace a directory that exists.
                    if (is_dir($this->blob_root)) {
                        @rmdir($this->blob_root);
                    }
                    if (!rename($this->blob_backup, $this->blob_root)) {
                        throw new \RuntimeException('Could not move the blob store back: ' . $this->blob_backup . ' -> ' . $this->blob_root);
                    }
                    $this->blob_backup_ok = false;
                    break;

                case self::ACTION_DELETE_LIVE_DUMP:
                    if (is_file($this->live_dump_path)) {
                        unlink($this->live_dump_path);
                    }
                    $this->live_dump_ok = false;
                    $this->__discard_marker_if_no_backups();
                    break;

                default:
                    shouldnt_happen('Unknown recovery action: ' . $action);
            }
        }
    }

    // =================================================================================
    // PRIMITIVES
    // =================================================================================

    protected function __resolve_paths(): void
    {
        $this->work_dir = storage_path(self::WORK_DIR_RELATIVE);
        $this->live_dump_path = $this->work_dir . '/' . self::LIVE_DUMP_FILE;
        $this->marker_path = $this->work_dir . '/' . self::MARKER_FILE;

        $override = Rsx_Internal_Flags::get(self::CACHE_DIR_FLAG);
        $this->cache_dir = !empty($override)
            ? rtrim($override, '/')
            : rsx_project_file_path(self::CACHE_DIR_RELATIVE);

        // The blob store, through the ONE choke point that resolves it (Rsx_File_Paths).
        $this->blob_root = rtrim(Rsx_File_Paths::blob_root(), '/');
        $this->blob_backup = $this->blob_root . self::BLOB_BACKUP_SUFFIX;
    }

    /**
     * Turn SIGINT/SIGTERM into an ordinary throw so the catch above runs recovery.
     *
     * NOT a timeout and not a deadline - it is the operator's own Ctrl-C arriving as
     * something this command can act on instead of a hard kill mid-restore.
     */
    protected function __install_signal_handlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->warn('[WARNING] pcntl is not available: Ctrl-C will not run the recovery path.');
            $this->warn('          The backups are still deleted last, so a re-run recovers.');

            return;
        }

        pcntl_async_signals(true);

        $handler = static function (int $signal): void {
            throw new \RuntimeException('Interrupted by signal ' . $signal . '.');
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /** The database the DEFAULT connection points at - never a literal. */
    protected function __database_name(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.database');
    }

    /** The default connection's config array. */
    protected function __connection(): array
    {
        return config('database.connections.' . config('database.default'));
    }

    /**
     * The credential channel: the password rides in the child's ENVIRONMENT, never on the
     * command line, where `ps` shows it to every user on the box. Same contract as
     * Rsx_Test_Command's dump/restore and Maint_Migrate::wait_for_mysql_ready().
     *
     * @return array<string, string>
     */
    protected function __mysql_env(): array
    {
        $conn = $this->__connection();
        $password = (string) $conn['password'];

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    /** The shared `-h -P -u` prefix for the mysql/mysqldump client. */
    protected function __client_flags(): string
    {
        $conn = $this->__connection();

        return '-h' . escapeshellarg((string) $conn['host'])
            . ' -P' . escapeshellarg((string) $conn['port'])
            . ' -u' . escapeshellarg((string) $conn['username']);
    }

    /**
     * Dump $database to $gz_target, gzipped, with the same options the test harness uses.
     *
     * Streamed through mysqlpv in line-log mode so the operator sees table names go by
     * rather than a silent wait. pipefail makes a mysqldump failure the pipeline's exit
     * status - without it the pipeline reports gzip's success and we would ship a dump of
     * an error message.
     */
    protected function __dump_database(string $database, string $gz_target): void
    {
        $pipeline = 'set -o pipefail; mysqldump ' . $this->__client_flags()
            . ' --no-tablespaces --single-transaction --quick --lock-tables=false '
            . escapeshellarg($database)
            . self::mysqlpv_pipe_segment()
            . ' | gzip > ' . escapeshellarg($gz_target);

        $this->__stream($pipeline, $this->__mysql_env(), 'dumping ' . $database);

        if (!is_file($gz_target) || filesize($gz_target) === 0) {
            throw new \RuntimeException('The dump of ' . $database . ' produced no output: ' . $gz_target);
        }
    }

    /** Stream a gzipped dump back into $database, progress visible the whole way. */
    protected function __restore_database_from_dump(string $database, string $gz_source): void
    {
        $pipeline = 'set -o pipefail; cat ' . escapeshellarg($gz_source)
            . ' | gunzip'
            . self::mysqlpv_pipe_segment()
            . ' | mysql ' . $this->__client_flags() . ' ' . escapeshellarg($database);

        $this->__stream($pipeline, $this->__mysql_env(), 'restoring ' . $database);
    }

    /**
     * The `| mysqlpv -l` segment of a dump/restore pipeline, or an EMPTY STRING when this
     * box cannot run it.
     *
     * bin/mysqlpv is a PYTHON 3 script - a pipe viewer that passes the stream through
     * unchanged and writes one progress line per table to stderr. It is a convenience, not
     * a participant: the bytes are identical with or without it, so a box with no
     * interpreter runs the plain pipeline and simply sees no per-table progress.
     *
     * ONLY python3 IS ACCEPTED. A bare `python` is python2 on Debian-family systems, and
     * python2 dies on this script's f-strings AFTER the pipeline has already started -
     * which, on the dump side, would write an error message where a backup should be.
     * Absent interpreter, absent script: no segment, and the pipeline is correct either way.
     *
     * The container ships python3 (resource/docker/Dockerfile, base layer - the RESTORE
     * side runs on every target, not just development).
     */
    public static function mysqlpv_pipe_segment(): string
    {
        $script = base_path('bin/mysqlpv');

        if (!is_file($script) || !command_exists('python3')) {
            return '';
        }

        return ' | python3 ' . escapeshellarg($script) . ' -l';
    }

    /**
     * DROP + CREATE the database through the mysql CLI rather than the framework's own
     * connection: dropping the database a live PDO handle is bound to leaves that handle
     * pointing at nothing. The purge afterwards makes the next framework query reconnect.
     */
    protected function __recreate_database(): void
    {
        $database = $this->__database_name();

        $sql = 'DROP DATABASE IF EXISTS `' . $database . '`;'
            . ' CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';

        $command = 'mysql ' . $this->__client_flags() . ' -e ' . escapeshellarg($sql) . ' 2>&1';

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, $this->__mysql_env());

        if ($exit_code !== 0) {
            throw new \RuntimeException('Could not recreate the database ' . $database . ': ' . implode("\n", $output));
        }

        DB::purge(config('database.default'));
    }

    /**
     * Empty the blob store directory, leaving the directory itself in place.
     *
     * Content-addressed blobs, so there is nothing here to preserve selectively: whatever
     * is in the store at this point was written by the cache build, and the live bytes
     * are safe in <blob_root>_tmp.
     */
    protected function __clear_build_blob_store(): void
    {
        if (is_dir($this->blob_root)) {
            rmdir_recursive($this->blob_root, false);

            return;
        }

        ensure_directory($this->blob_root);
    }

    /**
     * The in-progress marker bin/maintenance-mode.sh reads. Its CONTENT is the absolute
     * blob root, because that script runs pre-boot and cannot ask config() where the blob
     * store is.
     */
    protected function __write_marker(): void
    {
        ensure_directory($this->work_dir);
        file_put_contents($this->marker_path, $this->blob_root . "\n");
    }

    /** Remove the marker once no backup remains - the refusal must not outlive the risk. */
    protected function __discard_marker_if_no_backups(): void
    {
        if (is_file($this->live_dump_path) || is_dir($this->blob_backup)) {
            return;
        }

        if (is_file($this->marker_path)) {
            unlink($this->marker_path);
        }
    }

    /** Lower the window if WE raised it. Idempotent; safe to reach twice. */
    protected function __lower_maintenance(): void
    {
        if (!$this->maintenance_raised) {
            return;
        }

        $this->maintenance_raised = false;

        $this->info('');
        $this->info('Leaving maintenance mode...');
        Rsx_Artisan::passthru('rsx:maintenance:disable');
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
    protected function __stream(string $pipeline, array $env, string $context): void
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

    protected function __human_size(string $path): string
    {
        if (!is_file($path)) {
            return '(missing)';
        }

        return bytes_to_human((int) filesize($path));
    }

    /**
     * The README that ships beside the two artifacts. Regenerated every build so it can
     * never describe a cache it does not sit next to.
     */
    protected function __write_cache_readme(): void
    {
        $readme = <<<'MARKDOWN'
# Cached schema

Two build artifacts that let a FRESH install arrive at the current schema without
replaying every migration ever written.

| File | What it is |
|---|---|
| `schema_cache.sql.gz` | A gzipped `mysqldump` of a database migrated from zero. No user rows: the first-run screen creates user 1 after the restore. |
| `uploads_cache.tar.gz` | The content-addressed blob store's contents at that same point - whatever the data-seed migrations wrote. Relative paths, so it extracts into any blob root. |

## Who writes them

`php artisan rsx:db:dump_cache`, in development mode only. It backs the live database
and blob store up, wipes both, migrates from zero, records the result here, and then
restores the live data.

REGENERATED RARELY - every few months, and only when the operator asks for it by name.
A stale cache is not a bug: migrations newer than it apply on top, because the restore
is a pre-migrate step and not a replacement for the migration run.

## Who reads them

`php artisan migrate`. When the database has NO TABLES AT ALL and
`schema_cache.sql.gz` is present, the restore runs first and the migration run then
applies only the migrations NEWER than the cache. A non-empty database, or a missing
cache, migrates exactly as it always did.

These files are generated. Edit the migrations, not the cache.
MARKDOWN;

        file_put_contents($this->cache_dir . '/README.md', $readme . "\n");
    }
}
