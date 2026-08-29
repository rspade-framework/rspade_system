<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Database;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Database\Rsx_Data_Wipe;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;

/**
 * RETURN THIS APPLICATION TO THE FRESH-INSTALL STATE: every table dropped, every stored
 * file deleted, then migrate.
 *
 * The use case is iterating on a bulk import. An import that has to be run, inspected,
 * corrected and re-run needs a zero state to run against, and the only route to one was
 * rebuilding the container - which rebuilds a schema and a codebase that were never
 * wrong. This resets the DATA and nothing else.
 *
 * ======================================================================================
 * THE REFUSAL TEXT IS THE FEATURE
 * ======================================================================================
 *
 * Nothing here is hidden behind an environment or a role: a command an operator cannot
 * run is a command they work around. What stands between them and the wipe is that they
 * must READ WHAT THEY ARE AGREEING TO before they can agree to it. So the first thing
 * this command does - before it resolves a path, opens a socket or looks at the
 * database - is print the whole consequence in plain language and refuse.
 *
 * Naming the flag without explaining the consequence, and explaining the consequence
 * without naming the flag, both fail. The text does both, and refusal_text() is a PURE
 * FUNCTION so the tests exercise the real thing without a wipe ever happening.
 *
 * ======================================================================================
 * WHAT THE SNAPSHOT DOES AND DOES NOT COVER
 * ======================================================================================
 *
 * Where migrate could snapshot the datadir, this command takes that snapshot before the
 * drop and rolls it back if the wipe or the migrate fails, exactly as migrate does. That
 * protects the OPERATION - a reset that dies halfway leaves a coherent database.
 *
 * It is not an undo. A SUCCESSFUL reset commits (deletes) the snapshot, and the deleted
 * FILES were never in it at all: there is no filesystem snapshot, so a file removed by
 * step 6 is gone the moment it is removed, failure or success. The only route back after
 * this command does its job is a backup the operator took beforehand, which is what the
 * refusal text says in those words.
 *
 * NOT rsx:clean. That resets the system/ tree; this resets the data. The names are
 * deliberately dissimilar so an operator reaching for one cannot land on the other.
 */
class Database_And_Storage_Reset_Command extends Maint_Migrate
{
    protected $signature = 'rsx:database_and_storage_reset
        {--yes : Confirm the wipe. Without it the command refuses and prints what it would destroy.}
        {--force : Additionally required when this box is running a sealed production build.}
        {--no-migrate : Leave the schema dropped instead of re-migrating (for an operator who intends to restore a dump).}';

    protected $description = 'Drop every table and delete every stored file, then migrate to the fresh-install state';

    /** We raised the maintenance window and are responsible for lowering it. */
    protected bool $maintenance_raised = false;

    /** Why the command refused, as returned by refusal_reason(). */
    public const REFUSAL_UNCONFIRMED = 'unconfirmed';
    public const REFUSAL_SEALED = 'sealed';

    /**
     * WHETHER THIS INVOCATION MAY PROCEED, as a pure function of the three facts that
     * decide it. Null means proceed; anything else is a REFUSAL_* reason.
     *
     * Extracted for the same reason Db_Rebuild_Provision_Cache_Snapshot_Command::backup_decision() is: a
     * data-safety rule that can only be exercised by actually destroying a database is a
     * rule nobody checks. handle() drives from exactly this, so the rule under test is
     * the rule that runs.
     *
     * The sealed case is tested FIRST and does not care whether --yes was given, because
     * under a seal --yes alone is not enough and a refusal naming only --yes would send
     * the operator back for a second refusal. One refusal, both flags.
     */
    public static function refusal_reason(bool $confirmed, bool $forced, bool $is_sealed): ?string
    {
        if ($is_sealed && !$forced) {
            return self::REFUSAL_SEALED;
        }

        if (!$confirmed) {
            return self::REFUSAL_UNCONFIRMED;
        }

        return null;
    }

    /**
     * The refusal, in full, as lines. The FIRST line is the headline (printed as an
     * error); the rest is the body.
     *
     * Pure: every fact it states about this installation arrives as an argument, so the
     * text can be asserted without a database, a blob store or a wipe.
     *
     * @param string[] $roots The configured file roots that will be emptied.
     * @return string[]
     */
    public static function refusal_text(string $reason, string $database, array $roots): array
    {
        $lines = [
            '[ERROR] rsx:database_and_storage_reset DESTROYS THIS APPLICATION\'S DATA. Nothing has been touched.',
            '',
            '  THE DATABASE: every table in `' . $database . '` is DROPPED - not emptied, dropped.',
            '',
            '    That is every business record and every user account and login. Every client',
            '    and contact, every invoice, every time entry, every document record, every',
            '    setting, every session and every stored credential. Nothing is exempted,',
            '    nothing is filtered, and no tenant is spared - the reset is total by',
            '    definition.',
            '',
            '  THE FILES: every file under these roots is DELETED.',
            '',
        ];

        foreach ($roots as $root) {
            $lines[] = '      ' . $root;
        }

        $lines = array_merge($lines, [
            '',
            '    These are the ACTUAL UPLOADED BYTES of every document in the system, not a',
            '    cache that rebuilds itself. The directories and their permissions survive;',
            '    their contents do not. Nothing outside these roots is touched.',
            '',
            '  THERE IS NO UNDO. The application cannot bring any of this back - not the rows,',
            '  and above all not the files. The ONLY route back is a database backup and a copy',
            '  of the file store that you took BEFORE running this command. If you do not have',
            '  both, stop and take them now.',
            '',
            '  Afterwards the schema is re-migrated, so you are left on the fresh-install state:',
            '  an empty application with no data and no users, ready to be seeded again.',
            '',
        ]);

        if ($reason === self::REFUSAL_SEALED) {
            $lines = array_merge($lines, [
                '  THIS BOX IS RUNNING A SEALED PRODUCTION BUILD. That is a machine serving real',
                '  traffic to real people, and the data above is theirs. Two flags are required',
                '  here - --yes to confirm the wipe, and --force to confirm you mean it on this',
                '  machine:',
                '',
                '      php artisan rsx:database_and_storage_reset --yes --force',
            ]);
        } else {
            $lines = array_merge($lines, [
                '  If that is exactly what you want, confirm it with --yes:',
                '',
                '      php artisan rsx:database_and_storage_reset --yes',
            ]);
        }

        return $lines;
    }

    /**
     * The one line the operator keeps in their scrollback: what this run actually
     * destroyed. Pure, so the wording is tested without a wipe.
     */
    public static function announcement(int $tables, int $files, int $bytes, bool $migrated): string
    {
        return '[OK] Dropped ' . $tables . ' table' . ($tables === 1 ? '' : 's')
            . ', removed ' . $files . ' file' . ($files === 1 ? '' : 's')
            . ' (' . bytes_to_human($bytes) . ' freed); '
            . ($migrated ? 'migrated to the fresh-install state' : 'schema left dropped (--no-migrate)')
            . '.';
    }

    /**
     * The file roots this command empties - the framework's THREE configured roots, read
     * through the one choke point that resolves them (Rsx_File_Paths). Never a literal
     * storage_path('uploads'): a test run relocates the whole file subsystem, and a reset
     * that ignored that would reach past the relocation into the developer's real store.
     *
     * @return string[]
     */
    public static function configured_roots(): array
    {
        return [
            Rsx_File_Paths::blob_root(),
            Rsx_File_Paths::thumbnails_root(),
            Rsx_File_Paths::renditions_root(),
        ];
    }

    public function handle()
    {
        // -----------------------------------------------------------------------------
        // STEP 1 - THE REFUSAL, BEFORE ANYTHING ELSE.
        //
        // No path is resolved, no window is raised, no count is taken. An operator who
        // typed this by accident, or who typed it on purpose without knowing what it
        // means, finishes reading this having lost nothing.
        // -----------------------------------------------------------------------------
        $reason = self::refusal_reason(
            (bool) $this->option('yes'),
            (bool) $this->option('force'),
            Rsx_Prod_Seal::is_sealed()
        );

        if ($reason !== null) {
            // House style, like every other refusal in this codebase: the headline
            // through error(), the body through line(), both on ordinary output. One
            // stream means an operator who pipes the run somewhere reads the refusal in
            // the same place as the run it replaced.
            $lines = self::refusal_text($reason, Rsx_Data_Wipe::database_name(), self::configured_roots());
            $this->error(array_shift($lines));
            foreach ($lines as $line) {
                $this->line($line);
            }

            return 1;
        }

        // STEP 2 - a fresh process, as rsx:clean requires for the same reason: this
        // command drops the database its caller is holding a connection to.
        $this->prevent_call_from_another_command();

        // A Ctrl-C must run the same rollback an exception runs, not leave a half-wiped
        // database behind. Async signals turn SIGINT/SIGTERM into a throw at the next VM
        // tick, which the catch below treats like any other failure. NOT a timeout: it
        // arms no clock and bounds nothing.
        $this->__install_signal_handlers();

        $snapshot_taken = false;

        try {
            // The real maintenance window, through the real command, so services stop in
            // the real order. Web traffic gets a 503 and automated task runners are
            // refused for the whole reset; MySQL deliberately stays up.
            $this->__step_enter_maintenance();

            // The snapshot, where one can actually be taken.
            $snapshot_taken = $this->__step_take_snapshot();

            // Measure, then destroy.
            $removed = $this->__step_wipe();

            // Back to a bootable schema, unless the operator asked for the bare database.
            $migrated = $this->__step_migrate();

            // The snapshot did its job; release it.
            if ($snapshot_taken) {
                $this->info('');
                $this->commit_snapshot();
            }

            $this->info('');
            $this->info(self::announcement(
                $removed['tables'],
                $removed[Rsx_Data_Wipe::REMOVED_FILES],
                $removed[Rsx_Data_Wipe::REMOVED_BYTES],
                $migrated
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->error('');
            $this->error('[ERROR] ' . $e->getMessage());

            if ($snapshot_taken) {
                $this->warn('Rolling the database back to the pre-reset snapshot...');
                $this->rollback_snapshot();
                $this->cleanup_migration_mode();
                $this->info('[OK] Database restored to its pre-reset state.');
                $this->warn('Files already deleted by this run are NOT restored - the snapshot covers the');
                $this->warn('database only. Restore them from your own backup if the run got that far.');
            }

            return 1;
        } finally {
            $this->__lower_maintenance();
        }
    }

    // =================================================================================
    // STEPS
    // =================================================================================

    /** [1/5] Take the application down for the duration. */
    protected function __step_enter_maintenance(): void
    {
        $this->info('[1/5] Entering maintenance mode...');

        $exit_code = Rsx_Artisan::passthru('rsx:maintenance:enable', ['--reason=data reset']);
        if ($exit_code !== 0) {
            throw new \RuntimeException('Could not enter maintenance mode (exit ' . $exit_code . '). Nothing has been touched.');
        }

        $this->maintenance_raised = true;
    }

    /**
     * [2/5] Snapshot the datadir where the mechanism exists, and say plainly which
     * outcome the operator is in either way.
     *
     * The predicate is migrate's own (inherited), so there is exactly one answer on this
     * box to the question "can a snapshot be taken here" - and when it cannot, every
     * reason is printed rather than summarised. Somebody who believes they are protected
     * and is not is the failure this refuses to allow.
     */
    protected function __step_take_snapshot(): bool
    {
        $this->info('');
        $this->info('[2/5] Snapshot...');

        if (!$this->snapshot_protection_engaged()) {
            $this->warn('[WARNING]  Running WITHOUT snapshot protection - a failed reset will NOT be rolled back.');
            foreach ($this->snapshot_skipped_reasons() as $skipped_reason) {
                $this->line('   - ' . $skipped_reason);
            }

            return false;
        }

        if (!$this->create_snapshot()) {
            throw new \RuntimeException('Could not create the pre-reset snapshot. Nothing has been destroyed.');
        }

        $this->line('   A failure below rolls the DATABASE back to this point. Deleted files are');
        $this->line('   not covered - there is no filesystem snapshot.');

        return true;
    }

    /**
     * [3/5] Measure, then destroy: the tables, then the contents of the three configured
     * roots. The counts are taken first because there is no way to size a file after
     * unlinking it, and the counts are the record of the blast radius.
     *
     * @return array{tables: int, files: int, bytes: int}
     */
    protected function __step_wipe(): array
    {
        $this->info('');
        $this->info('[3/5] Dropping the database...');

        $tables = Rsx_Data_Wipe::count_tables();
        Rsx_Data_Wipe::recreate_database();
        $this->line('   ' . $tables . ' table' . ($tables === 1 ? '' : 's') . ' dropped from `' . Rsx_Data_Wipe::database_name() . '`.');

        $this->info('');
        $this->info('[4/5] Emptying the file roots...');

        $files = 0;
        $bytes = 0;
        foreach (self::configured_roots() as $root) {
            $removed = Rsx_Data_Wipe::clear_directory_contents($root);
            $files += $removed[Rsx_Data_Wipe::REMOVED_FILES];
            $bytes += $removed[Rsx_Data_Wipe::REMOVED_BYTES];
            $this->line('   ' . $root . ': ' . $removed[Rsx_Data_Wipe::REMOVED_FILES] . ' file(s), '
                . bytes_to_human($removed[Rsx_Data_Wipe::REMOVED_BYTES]) . '.');
        }

        return [
            'tables' => $tables,
            Rsx_Data_Wipe::REMOVED_FILES => $files,
            Rsx_Data_Wipe::REMOVED_BYTES => $bytes,
        ];
    }

    /**
     * [5/5] Re-migrate, so the operator is left BOOTABLE rather than staring at a
     * database with no tables.
     *
     * A database with no tables at all is exactly the state that makes migrate take the
     * fresh-install path: it restores the shipped schema cache and then applies whatever
     * migrations are newer. So the landing point is the fresh-install state, not "empty".
     *
     * NO_SNAPSHOT_FLAG because THIS command owns the snapshot. A child that took a second
     * one would stop MySQL and copy a datadir we have already copied, and its commit
     * would delete the copy this command is still relying on.
     */
    protected function __step_migrate(): bool
    {
        if ($this->option('no-migrate')) {
            $this->info('');
            $this->info('[5/5] Skipping migrate (--no-migrate) - the schema is left dropped.');

            // The build-scoped cache describes a schema that no longer exists. The
            // migrate path clears it itself; this path has to.
            RsxCache::clear();
            $this->info('[OK] Cache cleared');

            return false;
        }

        $this->info('');
        $this->info('[5/5] Migrating to the fresh-install state...');
        $this->info('');

        $exit_code = Rsx_Artisan::passthru('migrate', ['--force', Maint_Migrate::NO_SNAPSHOT_FLAG]);
        if ($exit_code !== 0) {
            throw new \RuntimeException('The migration run failed (exit ' . $exit_code . ').');
        }

        return true;
    }

    // =================================================================================
    // SUPPORT
    // =================================================================================

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
     * Turn SIGINT/SIGTERM into an ordinary throw so the catch above rolls back.
     *
     * pcntl is unconditional here: Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS declares
     * it and the boot check enforces that tier under the CLI SAPI.
     */
    protected function __install_signal_handlers(): void
    {
        pcntl_async_signals(true);

        $handler = static function (int $signal): void {
            throw new \RuntimeException('Interrupted by signal ' . $signal . '.');
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /**
     * Inherited from Maint_Migrate, which reads its own --framework-only option. This
     * command has no such option, and a reset is never a schema-only subset.
     */
    protected function is_framework_only_run(): bool
    {
        return false;
    }

    /**
     * Prevent being invoked via $this->call() from another command: this drops the
     * database the calling process holds an open connection to. Same guard, same reason,
     * as rsx:clean.
     */
    protected function prevent_call_from_another_command(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            if (!isset($frame['class'], $frame['function'])) {
                continue;
            }

            if ($frame['function'] === 'call' && str_contains($frame['class'], 'Command')) {
                $this->error('[ERROR] rsx:database_and_storage_reset cannot be called via $this->call().');
                $this->line('  It drops the database this process is connected to and re-migrates it.');
                $this->line('  Spawn it as its own process instead:');
                $this->line('');
                $this->line('      Rsx_Artisan::passthru(\'rsx:database_and_storage_reset\', [\'--yes\']);');

                exit(1);
            }
        }
    }
}
