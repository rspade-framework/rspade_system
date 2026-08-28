<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Migrate;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use App\Providers\AppServiceProvider;
use App\RSpade\Core\Database\MigrationValidator;
use App\RSpade\Core\Database\SqlQueryTransformer;
use App\RSpade\Core\Rsx;
use App\RSpade\SchemaQuality\SchemaQualityChecker;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Env\Rsx_Initial_User;
use App\RSpade\Core\Revisions\Revision_Dictionary;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Commands\Database\Db_Dump_Cache_Command;

/**
 * Unified migration command.
 *
 * SNAPSHOT-PROTECTED run - development mode, inside the RSpade DEVELOPMENT container,
 * against a LOCAL database host (snapshot_protection_available(); all three required):
 * - Stops the supervised mysqld and copies /var/lib/mysql before migrating
 * - Runs migrations with validation and normalization
 * - On success: commits changes, removes the snapshot, regenerates constants/bundles
 * - On failure: restores the datadir from the snapshot and exits migration mode
 *
 * BARE run - anything else, INCLUDING a production-target container and a development
 * run pointed at an external database:
 * - Runs migrations and schema normalization with NO snapshot and NO rollback
 * - Prints every reason protection is off (run_without_snapshot)
 * - Does NOT update source code (constants, bundles)
 *
 * The mechanism is physical - stop a local mysqld, copy and replace its data directory -
 * so it is performed only where all three of those things are true. A rollback that would
 * wipe and repopulate a /var/lib/mysql which is not the live database would report success
 * while the real database stayed broken; a false rollback is worse than no rollback.
 *
 * This command is automatically used when running 'php artisan migrate' due to
 * a modification in the artisan script.
 */
class Maint_Migrate extends Command
{
    use PrivilegedCommandTrait;

    protected $signature = 'migrate {--force} {--seed} {--step} {--path=*} {--framework-only : Run only framework migrations (system/database/migrations)} {--rsx-storage-root= : INTERNAL - test-isolation seam. Roots the file subsystem (blob/thumbnail/rendition store) at this absolute path so a data-seed migration writing blobs stays in the test-scoped store. Set only by rsx:test provisioning; never used in normal migrations. See backlog B-38.}';

    protected $description = 'Run migrations with automatic snapshot protection in development mode';

    /**
     * Framework-internal: skip the datadir snapshot (see handle()). Passed by
     * rsx:db:dump_cache, which holds a full dump of the live database and is migrating a
     * database it just created empty.
     */
    public const NO_SNAPSHOT_FLAG = '--_no-snapshot';

    protected $flag_file = '/var/www/html/.migrating';
    protected $mysql_data_dir = '/var/lib/mysql';
    protected $backup_dir = '/var/lib/mysql_backup';

    // Replaceable: Migrate_Restore_Command subclasses this command for its snapshot
    // machinery (rollback_snapshot and friends) and replaces handle() wholesale - a
    // restore never runs the migrate flow.
    #[Replaceable]
    public function handle()
    {
        // Test-isolation seam (backlog B-38): when the rsx:test provisioning
        // subprocess passes --rsx-storage-root, root the entire file subsystem there
        // so a data-seed migration writing blobs (e.g. import_sample_documents) lands
        // in the test-scoped store, never the shared developer blob store. This is a
        // per-invocation --flag, not an env var (owner ruling on invocation intent).
        $storage_root = $this->option('rsx-storage-root');
        if (!empty($storage_root)) {
            config(['rsx.files.storage_root' => $storage_root]);
        }

        // SNAPSHOT PROTECTION IS TAKEN ONLY WHERE IT CAN ACTUALLY BE PERFORMED.
        //
        // The mechanism is physical: stop the LOCAL, SUPERVISED mysqld, copy
        // /var/lib/mysql, and - on failure - wipe that directory and copy the
        // snapshot back. Every one of those three verbs is an assumption about the
        // machine, and the whole thing is only correct where all of them hold:
        //
        //   1. DEVELOPMENT mode. The undo exists for a developer breaking schemas
        //      all day against a database that is theirs to break.
        //   2. An RSpade DEVELOPMENT container. The marker /.rspade_container_dev is
        //      exactly this distinction, and it matters: the PRODUCTION container
        //      carries /.rspade_container too, but ships mysql-client ONLY. There is
        //      no mysqld to stop and no datadir to copy there - `supervisorctl status
        //      mysql` answers "no such process".
        //   3. A LOCAL database host. Pointed at an external DB, /var/lib/mysql is
        //      not the database at all.
        //
        // Anything else runs BARE, exactly as production does, and says which
        // condition disabled protection (run_without_snapshot).
        //
        // THE RETIRED PROMISE: this used to snapshot in the production container too
        // and KEEP the copy, on the theory that it was the last image of the database
        // before the change. That promise was never deliverable - the production
        // container has no mysqld - and a promise of protection that cannot be kept is
        // worse than none. The dangerous half was never the snapshot but the ROLLBACK:
        // restoring a /var/lib/mysql that is not the live database would report a
        // successful rollback while the real database stayed broken. A rollback we
        // cannot perform must not be attempted or advertised.
        //
        // The one refusal below is a DIFFERENT question and stays: DEVELOPMENT mode
        // outside the container is somebody's own machine, and stopping their MySQL is
        // not ours to do.
        if (Rsx::is_development() && !$this->probe_is_rspade_container()) {
            return $this->refuse_development_outside_container();
        }

        if ($this->snapshot_protection_engaged()) {
            return $this->run_with_snapshot();
        }

        return $this->run_without_snapshot();
    }

    /**
     * Whether the stop-MySQL / snapshot / restore-on-failure machinery can ACTUALLY be
     * performed on this machine, against this database.
     *
     * Three conditions, all required - see the block in handle() for why each one is
     * load-bearing. This answers only "is the mechanism available here"; it says nothing
     * about whether a particular RUN wants it (--framework-only, --_no-snapshot), which is
     * snapshot_protection_engaged()'s question.
     */
    protected function snapshot_protection_available(): bool
    {
        return $this->snapshot_protection_unavailable_reason() === null;
    }

    /**
     * Why snapshot protection is unavailable here, in one operator-readable clause - or
     * null when it IS available.
     *
     * The reason is not decoration. Somebody who believes they are protected and is not
     * is the exact failure this predicate exists to prevent, so every path that proceeds
     * unprotected prints which condition disabled it.
     */
    protected function snapshot_protection_unavailable_reason(): ?string
    {
        if (!$this->probe_is_development()) {
            return 'the application is in ' . Rsx::get_mode_label() . ' mode, not development';
        }

        if (!$this->probe_is_rspade_container()) {
            return 'this is not an RSpade container (/.rspade_container absent), so there is no'
                . ' supervised MySQL service to stop';
        }

        if (!$this->probe_is_rspade_dev_container()) {
            return 'this is a production-target RSpade container (/.rspade_container_dev absent),'
                . ' which ships mysql-client only - there is no local mysqld to stop and no datadir to copy';
        }

        if (!$this->is_local_database()) {
            return 'the configured database host (' . $this->configured_database_host_label() . ') is not'
                . ' local, so /var/lib/mysql is not this database';
        }

        return null;
    }

    /**
     * Whether THIS RUN takes a snapshot: the mechanism is available AND nothing about the
     * invocation suppresses it.
     */
    protected function snapshot_protection_engaged(): bool
    {
        return count($this->snapshot_skipped_reasons()) === 0;
    }

    /**
     * Every reason this run proceeds without snapshot protection, most specific first.
     * Empty means the run IS protected.
     *
     * @return string[]
     */
    protected function snapshot_skipped_reasons(): array
    {
        $reasons = [];

        // Framework-only runs never snapshot: they are a schema-only subset used by
        // tooling, and the snapshot exists to protect a developer's data.
        if ($this->is_framework_only_run()) {
            $reasons[] = 'this is a --framework-only run (a schema-only subset used by tooling)';
        }

        // --_no-snapshot is the statement made by a caller that has ALREADY taken a better
        // backup and knows this database is empty: rsx:db:dump_cache holds a full gzipped
        // dump of the live database on disk and has just dropped and recreated the database
        // this run migrates. Stopping MySQL to copy an empty datadir would protect nothing
        // and cost the whole stop/copy/start. Framework-internal (the `--_` convention): no
        // InputOption, stripped from argv pre-boot, invisible to help.
        if ($this->is_snapshot_suppressed_by_flag()) {
            $reasons[] = 'the caller passed ' . self::NO_SNAPSHOT_FLAG
                . ' (it holds its own backup of this database)';
        }

        $unavailable = $this->snapshot_protection_unavailable_reason();
        if ($unavailable !== null) {
            $reasons[] = $unavailable;
        }

        return $reasons;
    }

    /**
     * Whether the configured database lives on THIS machine, so that /var/lib/mysql is in
     * fact the database being migrated.
     *
     * A configured unix socket is local by construction - a socket connection cannot reach
     * another host - and is checked first because a socket connection ignores the host
     * value entirely. Otherwise only the three exact loopback spellings count; matching is
     * exact so that a hostname merely BEGINNING with a loopback literal
     * ("127.0.0.1.attacker.example", "localhost.example.com") is correctly remote.
     *
     * Read from config, never by parsing .env - the connection RSpade actually opens is the
     * one config describes.
     */
    protected function is_local_database(): bool
    {
        $db = $this->configured_database_connection();

        $socket = trim((string) ($db['unix_socket'] ?? ''));
        if ($socket !== '') {
            return true;
        }

        $host = strtolower(trim((string) ($db['host'] ?? '')));

        // An IPv6 literal is conventionally written bracketed in a host field.
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * The configured host, for the operator-facing reason string.
     */
    protected function configured_database_host_label(): string
    {
        $db = $this->configured_database_connection();
        $host = trim((string) ($db['host'] ?? ''));

        return $host !== '' ? $host : '(no host configured)';
    }

    /**
     * The default connection's config array. A seam so the predicate is testable without a
     * database or a configured environment.
     */
    protected function configured_database_connection(): array
    {
        return (array) config('database.connections.' . config('database.default'));
    }

    /**
     * Environment probes, each its own overridable seam so snapshot_protection_available()
     * is testable without a container, a database or a process.
     */
    protected function probe_is_development(): bool
    {
        return Rsx::is_development();
    }

    protected function probe_is_rspade_container(): bool
    {
        return Rsx::is_rspade_container();
    }

    protected function probe_is_rspade_dev_container(): bool
    {
        return Rsx::is_rspade_dev_container();
    }

    protected function is_framework_only_run(): bool
    {
        return (bool) $this->option('framework-only');
    }

    protected function is_snapshot_suppressed_by_flag(): bool
    {
        return Rsx_Internal_Flags::has(self::NO_SNAPSHOT_FLAG);
    }

    /**
     * Development mode, no RSpade container: refuse, and say exactly what to type.
     *
     * An error that only says "wrong environment" leaves somebody guessing at the
     * invocation. This prints it.
     */
    protected function refuse_development_outside_container(): int
    {
        $this->error('[ERROR] Development-mode migrations run inside the RSpade container.');
        $this->info('');
        $this->info('  Migrations in development are snapshot-protected: the database is copied');
        $this->info('  first, so a bad migration rolls back instead of leaving you to repair it.');
        $this->info('  That protection is the container\'s - it stops the supervised MySQL');
        $this->info('  service and copies its data directory - and this is not that container.');
        $this->info('');
        $this->info('  Run it there instead:');
        $this->info('');
        $this->info('      docker compose exec app php artisan migrate');
        $this->info('');
        $this->info('  Or from a shell inside the container:');
        $this->info('');
        $this->info('      docker compose exec app bash');
        $this->info('      php artisan migrate');
        $this->info('');
        $this->info('  A deployed application migrates here normally - this refusal is only for');
        $this->info('  development mode (RSX_MODE=development).');

        return 1;
    }

    /**
     * Run migrations with automatic snapshot protection.
     *
     * Reached only when snapshot_protection_engaged() is true, which is to say: development
     * mode, in the RSpade DEVELOPMENT container, against a local database. The snapshot is
     * discarded on success - a developer wants the undo, not a growing pile of copies.
     */
    protected function run_with_snapshot(): int
    {
        $this->info(' Development container: automatic snapshot protection');
        $this->info('');

        // Step 1: Create snapshot
        $this->info('[1/4] Creating database snapshot...');
        if (!$this->create_snapshot()) {
            return 1;
        }

        // Step 2: Run migrations
        $this->info('');
        $this->info('[2/4] Running migrations...');
        $migration_result = $this->execute_migrations();

        if ($migration_result !== 0) {
            // Migration failed - rollback and exit migration mode
            $this->error('');
            $this->error('[ERROR] Migration failed!');
            $this->warn(' Automatically rolling back to snapshot...');
            $this->info('');

            $this->rollback_snapshot();
            $this->cleanup_migration_mode();

            $this->info('');
            $this->info('[OK] Database restored to pre-migration state.');
            $this->info('');
            $this->line('Fix your migration files and run "php artisan migrate" again.');

            return 1;
        }

        // Step 3: Run schema quality check
        $this->info('');
        $this->info('[3/4] Running schema quality check...');

        $checker = new SchemaQualityChecker();
        $checker->check();

        if ($checker->has_violations()) {
            $this->error('[ERROR] Schema standards check failed with ' . $checker->get_violation_count() . ' violation(s):');
            $this->info('');

            // Display violations
            $grouped = $checker->get_violations_by_severity();
            foreach (['critical', 'high', 'medium', 'low'] as $severity) {
                if (!empty($grouped[$severity])) {
                    $this->line(strtoupper($severity) . ' VIOLATIONS:');
                    foreach ($grouped[$severity] as $violation) {
                        $this->line($violation->format_output());
                    }
                }
            }

            $this->info('');
            $this->warn(' Rolling back due to schema violations...');

            $this->rollback_snapshot();
            $this->cleanup_migration_mode();

            $this->info('');
            $this->warn('[WARNING]  Migration has been rolled back. Fix the schema violations and try again.');
            return 1;
        }

        $this->info('[OK] Schema quality check passed.');

        // Step 4: Commit.
        $this->info('');
        $this->info('[4/4] Committing changes...');

        $this->commit_snapshot();

        // Post-migration source regeneration, which only makes sense where the
        // source tree is writable and gets edited.
        if (Rsx::is_development()) {
            $this->info('');
            $this->info('Running post-migration tasks...');

            // Regenerate model constants
            $this->call('rsx:constants:regenerate');

            // Recompile bundles
            $this->newLine();
            $this->info('Recompiling bundles...');
            Rsx_Artisan::passthru('rsx:bundle:compile');
        }

        // The build-scoped cache describes a schema that has just changed underneath it.
        // Nothing here knows which entries are now wrong, so all of them go.
        RsxCache::clear();
        $this->info('[OK] Cache cleared');

        $this->info('');
        $this->info('[OK] Migration completed successfully!');

        return 0;
    }

    /**
     * Run migrations WITHOUT snapshot protection, naming every reason protection is off.
     *
     * The reasons are printed rather than summarised because "development mode" is no
     * longer a synonym for "protected": a development run in a production-target container,
     * or against an external database host, lands here too. An operator who cannot tell
     * WHICH condition disabled the undo is the operator who finds out by needing it.
     */
    protected function run_without_snapshot(): int
    {
        $is_framework_only = $this->is_framework_only_run();
        $reasons = $this->snapshot_skipped_reasons();

        $this->warn('[WARNING]  Running WITHOUT snapshot protection - a failed migration will NOT be rolled back.');
        foreach ($reasons as $reason) {
            $this->line('   - ' . $reason);
        }
        $this->info(' Constants and bundles will not be regenerated by this run.');
        $this->info('');

        // Run migrations
        $migration_result = $this->execute_migrations();

        if ($migration_result !== 0) {
            $this->error('');
            $this->error('[ERROR] Migration failed!');
            $this->warn('[WARNING]  No snapshot available - database may be in inconsistent state.');
            return 1;
        }

        // In debug/production mode, check manifest consistency with database
        if (!$is_framework_only) {
            AppServiceProvider::disable_query_echo();
            $this->info('');
            $consistency_check_exit = $this->call('rsx:migrate:check_consistency');
            if ($consistency_check_exit !== 0) {
                $this->warn('[WARNING]  Manifest-database consistency check failed.');
                $this->warn('Source code may be out of sync with database schema.');
            }
        }

        // See the note on the snapshot path: the schema moved, so the cache goes.
        RsxCache::clear();
        $this->info('[OK] Cache cleared');

        $this->info('');
        $this->info('[OK] Migration completed!');

        return 0;
    }

    /**
     * Execute the actual migration process
     */
    protected function execute_migrations(): int
    {
        // Enable SQL query transformation for migrations
        SqlQueryTransformer::enable();
        $this->register_query_transformer();

        // Enable full query logging to stdout for migrations
        AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_ALL_STDOUT);

        // THE INITIAL PROVISION. Before anything creates a table - ensure_migrations_table_exists()
        // included, since the migrations table is itself a table and would make the database
        // look non-empty. A no-op on every run but the first.
        //
        // A failed restore returns non-zero rather than throwing past this method, so the
        // caller's migration-failure branch handles it the way it handles every other
        // pre-migration failure (the snapshot path rolls back and clears migration mode).
        try {
            $this->maybe_restore_schema_cache();
        } catch (\Throwable $e) {
            $this->error('Restoring the cached schema failed: ' . $e->getMessage());
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();

            return 1;
        }

        // Ensure migrations table exists (create it if needed)
        $this->ensure_migrations_table_exists();

        $is_framework_only = $this->option('framework-only');
        $is_development = Rsx::is_development();

        // Get all the options
        $force = $this->option('force');
        $seed = $this->option('seed');
        $step = $this->option('step');
        $paths = $this->option('path');

        // Check if path option is used and throw exception
        if (!empty($paths)) {
            $this->error('[ERROR] Migration by path is disabled!');
            $this->error('');
            $this->line('This command enforces running all pending migrations in order.');
            $this->line('Please run migrations without the --path option.');
            SqlQueryTransformer::disable();
            return 1;
        }

        // Determine which migration paths to use for whitelist check
        $paths_to_check = $is_framework_only
            ? [database_path('migrations')]
            : MigrationPaths::get_all_paths();

        // Check migration whitelist
        if (!$this->checkMigrationWhitelist($paths_to_check)) {
            SqlQueryTransformer::disable();
            return 1;
        }

        // Validate migration files for Schema builder usage (only in development)
        if ($is_development && !$this->_validate_schema_rules()) {
            SqlQueryTransformer::disable();
            return 1;
        }

        // Run normalize_schema BEFORE migrations to fix existing tables.
        // normalize_schema without --production REQUIRES the .migrating flag the snapshot
        // path writes, so this must ask the SAME question handle() asked when it chose the
        // path - otherwise an unprotected run fails on a snapshot it was never going to take.
        $requiredColumnsArgs = $this->snapshot_protection_engaged() ? [] : ['--production' => true];

        $this->info("\n Pre-migration normalization (fixing existing tables)...\n");
        // normalize_schema fails loud (throws) rather than performing a logical
        // rollback of its own. Convert a throw (or a non-zero exit) into a non-zero
        // return so the caller's migration-failure branch (run_with_snapshot ->
        // rollback_snapshot + cleanup_migration_mode) performs the real datadir
        // snapshot rollback, exactly as the mid-loop normalization failure does.
        try {
            $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);
        } catch (\Exception $e) {
            $this->error('Pre-migration normalization failed: ' . $e->getMessage());
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return 1;
        }
        if ($normalizeExitCode !== 0) {
            $this->error('Pre-migration normalization failed');
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return $normalizeExitCode;
        }

        // Use a buffered output to capture migration output
        $bufferedOutput = new BufferedOutput();

        try {
            // Run the standard migrations and capture the output
            $originalSqlMode = DB::selectOne('SELECT @@sql_mode as sql_mode')->sql_mode;
            DB::statement("SET sql_mode = REPLACE(@@sql_mode, 'NO_ZERO_DATE', '');");

            // Run migrations directly via migrator to avoid recursion
            $migrator = app('migrator');
            $migrator->setOutput($bufferedOutput);

            // Use all migration paths (framework + user), or just framework if --framework-only
            $migrationPaths = $is_framework_only
                ? [database_path('migrations')]
                : MigrationPaths::get_all_paths();

            // Run migrations one-by-one with normalization after each
            $this->run_migrations_with_normalization($migrator, $migrationPaths, $step, $requiredColumnsArgs);

            $exitCode = 0;

            // Handle seeding if requested
            if ($seed) {
                $this->call('db:seed', ['--force' => $force]);
            }

            $migrationOutput = $bufferedOutput->fetch();

            // Show the output from the migration command
            $this->output->write($migrationOutput);

            DB::statement('SET sql_mode = ?', [$originalSqlMode]);

        } catch (\Exception $e) {
            // Restore SQL mode
            try {
                if (isset($originalSqlMode)) {
                    DB::statement('SET sql_mode = ?', [$originalSqlMode]);
                }
            } catch (\Exception $sqlEx) {
                // Ignore SQL mode restore errors
            }

            $this->error('');
            $this->error('Error: ' . $e->getMessage());

            // Disable query logging before returning
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();

            return 1;
        }

        // Run normalize_schema AFTER migrations to add framework columns to new tables
        $this->info("\n Post-migration normalization (adding framework columns to new tables)...\n");

        // Switch to destructive-only query logging for normalize_schema
        AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_DESTRUCTIVE_STDOUT);

        // normalize_schema fails loud (throws) rather than performing a logical
        // rollback of its own. Convert a throw (or a non-zero exit) into a non-zero
        // return so the caller's migration-failure branch (run_with_snapshot ->
        // rollback_snapshot + cleanup_migration_mode) performs the real datadir
        // snapshot rollback, exactly as the mid-loop normalization failure does.
        try {
            $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);
        } catch (\Exception $e) {
            $this->error('Post-migration normalization failed: ' . $e->getMessage());
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return 1;
        }
        if ($normalizeExitCode !== 0) {
            $this->error('Post-migration normalization failed');
            AppServiceProvider::disable_query_echo();
            SqlQueryTransformer::disable();
            return $normalizeExitCode;
        }

        // Disable query logging
        AppServiceProvider::disable_query_echo();
        SqlQueryTransformer::disable();

        // THE INITIAL USER - after the FINAL normalize pass, so the schema is at the tip
        // by construction. This is deliberately NOT a migration: it runs model code and,
        // through user.initial.created, application HANDLER code, and both are current
        // code that only works against the current schema. A migration replays at one
        // fixed point in history, which is the coupling that forbids it. See
        // Rsx_Initial_User and php artisan rsx:man initial_user.
        //
        // Shared by both paths - run_with_snapshot() and run_without_snapshot() both
        // reach it here - so a fresh install's `php artisan migrate` yields its admin
        // account in either. A failure returns non-zero like a normalization failure
        // does, so the snapshot path performs its real rollback.
        if (!$this->create_initial_user_if_needed()) {
            return 1;
        }

        // THE REVISION DICTIONARY - here for the same reason the initial user is here,
        // and skipped for the same reason too. It is DERIVED from the finished schema
        // (every column name in information_schema) and from the manifest's models
        // (every enum label), so it is only correct once the final normalize pass has
        // run. A framework-only run migrates a schema-only subset with none of the
        // application's tables in it, and a dictionary built from that would be a
        // permanently-recorded description of half a database.
        if (!$this->option('framework-only') && !$this->build_revision_dictionary_if_stale()) {
            return 1;
        }

        return $exitCode;
    }

    /**
     * Build a new revision-history compression dictionary when the current one is
     * missing or past config('rsx.revisions.dictionary_max_age_days').
     *
     * Silent when there is nothing to do, which is every run inside the cadence; one
     * line when a dictionary is built. Dictionary rows are append-only and every stored
     * revision names the one it was written against, so building a new one never
     * invalidates an old revision.
     *
     * @return bool false when the build failed (the caller turns that into a rollback)
     */
    protected function build_revision_dictionary_if_stale(): bool
    {
        try {
            $id = Revision_Dictionary::regenerate_if_stale();
        } catch (\Throwable $e) {
            $this->error('');
            $this->error('Building the revision dictionary failed: ' . $e->getMessage());

            return false;
        }

        if ($id !== null) {
            $token_count = (int) DB::table('_revision_dictionaries')->where('id', $id)->value('token_count');
            $this->info('[OK] Revision dictionary ' . $id . ' built (' . $token_count . ' tokens)');
        }

        return true;
    }

    /**
     * Create the application's initial user from RSPADE_DEFAULT_* when this database
     * still needs one. Silent when there is nothing to do, which is every run after the
     * first; one line when an account is created.
     *
     * SKIPPED ENTIRELY by --_no-initial-user, the framework-internal flag the test
     * runner passes when it provisions the test database: that database gets its
     * baseline identity from Rsx_Test_Command as a separate step immediately after this
     * migrate, and an env-seeded account would take the id 1 that identity requires.
     *
     * @return bool false when the seed failed (the caller turns that into a rollback)
     */
    protected function create_initial_user_if_needed(): bool
    {
        if (Rsx_Internal_Flags::has('--_no-initial-user')) {
            return true;
        }

        // A framework-only run migrates a schema-only subset for tooling - the
        // application's own tables are not there, so neither the account's site nor an
        // application handler's tables can be assumed.
        if ($this->option('framework-only')) {
            return true;
        }

        try {
            $user = Rsx_Initial_User::create_from_env_if_needed();
        } catch (\Throwable $e) {
            $this->error('');
            $this->error('Creating the initial user failed: ' . $e->getMessage());

            return false;
        }

        if ($user !== null) {
            $this->info('[OK] Initial user created: ' . $user->email . ' (id ' . $user->id . ')');
        }

        return true;
    }

    /**
     * Create a database snapshot
     */
    protected function create_snapshot(): bool
    {
        // Check if already in migration mode (shouldn't happen with new unified command)
        if (file_exists($this->flag_file)) {
            // Clean up stale migration mode
            $this->warn('[WARNING]  Found stale migration mode flag. Cleaning up...');
            $this->cleanup_migration_mode();
        }

        try {
            // Before touching anything, CONFIRM the mysqld topology is the single supervised
            // instance this flow assumes (B-47). A stray second mysqld would keep serving the
            // datadir after `supervisorctl stop mysql`, so the snapshot would either capture
            // inconsistent data or hang - this fails loud with a diagnostic instead.
            $this->preflight_mysqld_topology();

            // Stop MySQL and CONFIRM it is actually down before copying. Copying a live
            // datadir would capture an inconsistent snapshot.
            $this->shell_exec_privileged('supervisorctl stop mysql 2>&1');
            $this->wait_for_mysql_stopped();

            // Always remove any old backup unconditionally (rm -rf is a no-op if absent).
            // Do NOT gate on is_dir(): PHP can't reliably stat the mysql-owned datadir, and
            // a skipped removal makes the next copy NEST into an existing dir (datadir
            // corruption). run_privileged_command throws on failure, so a failed removal
            // aborts the snapshot instead of silently nesting.
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);

            // Copy the datadir with `cp -rT`:
            //   -T (--no-target-directory): treat DEST as the literal target, so the copy
            //      can NEVER nest as backup_dir/mysql/ even if backup_dir somehow exists.
            //      THIS is the fix for the datadir-corruption bug (`cp -r` nested on the
            //      basename collision between the `mysql` datadir and its `mysql/` subdir).
            //   -r (not -a): do NOT preserve ownership/perms/times. The datadir is an
            //      idmapped btrfs mount that rejects those attribute ops ("Operation not
            //      permitted"), which would abort the copy. The backup is only a holding
            //      copy; on rollback we chown it back to mysql:mysql.
            $this->run_privileged_command(['cp', '-rT', $this->mysql_data_dir, $this->backup_dir]);

            // Integrity guard: refuse to trust a backup that is not a sane datadir (e.g.
            // the nested-copy corruption signature). A bad backup must never be restorable.
            if (!$this->verify_datadir_sane($this->backup_dir)) {
                throw new \Exception('Snapshot integrity check failed: ' . $this->backup_dir . ' is not a clean datadir (nested-copy corruption?). Snapshot aborted.');
            }

            // Start MySQL
            $this->shell_exec_privileged('mkdir -p /var/run/mysqld');
            $this->shell_exec_privileged('chown -R mysql:mysql /var/run/mysqld');
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            // Wait for MySQL to be ready
            $this->wait_for_mysql_ready();

            // Create migration flag file
            file_put_contents_safe($this->flag_file, json_encode([
                'started_at' => now()->toIso8601String(),
                'started_by' => get_current_user(),
                'backup_dir' => $this->backup_dir,
            ], JSON_PRETTY_PRINT));

            $this->info('[OK] Snapshot created successfully.');
            return true;

        } catch (\Exception $e) {
            $this->error('[ERROR] Failed to create snapshot: ' . $e->getMessage());

            // Try to restart MySQL
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            return false;
        }
    }

    /**
     * Rollback to snapshot
     */
    protected function rollback_snapshot(): bool
    {
        if (!is_dir($this->backup_dir)) {
            $this->error('[ERROR] Backup directory not found!');
            return false;
        }

        // NEVER restore a corrupt backup over the live datadir. Verify the backup is a
        // sane datadir BEFORE we touch (clear) the live data, so a bad snapshot can't
        // destroy a recoverable datadir.
        if (!$this->verify_datadir_sane($this->backup_dir)) {
            $this->error('[ERROR] Refusing to roll back: snapshot at ' . $this->backup_dir . ' failed its integrity check (nested-copy corruption?). The live datadir is left untouched.');
            return false;
        }

        try {
            // Stop MySQL and CONFIRM it is down before mutating the datadir.
            $this->shell_exec_privileged('supervisorctl stop mysql 2>&1');
            $this->wait_for_mysql_stopped();

            // Clear the live datadir safely. `find -mindepth 1 -delete` removes every entry
            // (including dotfiles) WITHOUT the `rm -rf datadir/.*` hazard, where `.*`
            // expands to `..` and could reach the parent.
            $this->shell_exec_privileged("find {$this->mysql_data_dir} -mindepth 1 -delete 2>/dev/null");

            // Restore with `cp -rT` so the copy lands AS the datadir (never nested).
            // `-r` (not -a) because this idmapped btrfs mount rejects attribute
            // preservation; ownership is restored explicitly below.
            $this->run_privileged_command(['cp', '-rT', $this->backup_dir, $this->mysql_data_dir]);

            // Restore mysql:mysql ownership (cp -rT did not preserve it).
            $this->run_privileged_command(['chown', '-R', 'mysql:mysql', $this->mysql_data_dir]);

            // Verify the restored datadir is sane before bringing MySQL back up.
            if (!$this->verify_datadir_sane($this->mysql_data_dir)) {
                throw new \Exception('Restore integrity check failed: ' . $this->mysql_data_dir . ' is not a clean datadir after rollback.');
            }

            // Start MySQL
            $this->shell_exec_privileged('mkdir -p /var/run/mysqld');
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            // Wait for MySQL to be ready
            $this->wait_for_mysql_ready();

            return true;

        } catch (\Exception $e) {
            $this->error('[ERROR] Rollback failed: ' . $e->getMessage());

            // Try to restart MySQL
            $this->shell_exec_privileged('supervisorctl start mysql 2>&1');

            return false;
        }
    }

    /**
     * Commit snapshot (remove backup and flag)
     */
    protected function commit_snapshot(): void
    {
        // Remove backup directory
        if (is_dir($this->backup_dir)) {
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);
        }

        // Remove migration flag
        if (file_exists($this->flag_file)) {
            unlink($this->flag_file);
        }

        $this->info('[OK] Snapshot committed - backup removed.');
    }

    /**
     * Cleanup migration mode (remove flag and backup)
     */
    protected function cleanup_migration_mode(): void
    {
        if (is_dir($this->backup_dir)) {
            $this->run_privileged_command(['rm', '-rf', $this->backup_dir]);
        }

        if (file_exists($this->flag_file)) {
            unlink($this->flag_file);
        }
    }

    /**
     * Wait for MySQL to be ready for connections
     *
     * Credentials come from the configured default connection - never hardcoded - and the
     * password rides in the child's environment (MYSQL_PWD), never on the command line,
     * where `ps` would expose it to every user on the box.
     */
    protected function wait_for_mysql_ready(): void
    {
        $connection = config('database.default');
        $db = config('database.connections.' . $connection);

        $command = 'mysql'
            . ' -h' . escapeshellarg((string) $db['host'])
            . ' -P' . escapeshellarg((string) $db['port'])
            . ' -u' . escapeshellarg((string) $db['username'])
            . ' -e ' . escapeshellarg("SELECT 'mysql_ready_probe'")
            . ' 2>/dev/null';

        $env = [];
        if ((string) $db['password'] !== '') {
            $env['MYSQL_PWD'] = (string) $db['password'];
        }

        $max_attempts = 120;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $output = [];
            $return_var = 0;
            \exec_safe($command, $output, $return_var, $env);

            if ($return_var === 0 && str_contains(implode("\n", $output), 'mysql_ready_probe')) {
                return;
            }

            sleep(1);
            $attempt++;
        }

        throw new \Exception('MySQL did not start within 120 seconds');
    }

    /**
     * Wait until mysqld has fully stopped before copying the datadir. Copying while
     * mysqld is still flushing/holding files open captures an inconsistent snapshot.
     */
    protected function wait_for_mysql_stopped(): void
    {
        $max_attempts = 60;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            // pgrep returns empty when no mysqld process remains.
            $running = trim($this->shell_exec_privileged('pgrep -x mysqld 2>/dev/null') ?? '');
            if ($running === '') {
                return;
            }
            sleep(1);
            $attempt++;
        }

        throw new \Exception('MySQL did not stop within 60 seconds');
    }

    /**
     * Fail LOUD, before anything is stopped, if the mysqld process topology is not the single
     * supervised instance the snapshot flow assumes (B-47).
     *
     * The snapshot stops MySQL with `supervisorctl stop mysql`, then waits for EVERY mysqld to
     * exit (wait_for_mysql_stopped) before copying the datadir. But `supervisorctl stop` only
     * stops the instance supervisor manages. If a stray SECOND mysqld is running - a leftover
     * manual `mysqld`/`mysqld_safe` from an earlier recovery, unmanaged by supervisor - it
     * keeps SERVING the datadir: the wait times out after 60s and every migration silently
     * fails. In the field this masqueraded as a generic timeout and went unnoticed for DAYS,
     * with pulled columns/tables simply absent until a runtime "unknown column" 500 exposed it.
     *
     * Two bad shapes are rejected here, with a diagnostic naming the pids, which one supervisor
     * manages, and how to kill the stray - so the migration never proceeds as if it succeeded:
     *   - MORE THAN ONE mysqld is running (at least one is a stray), or
     *   - the single running mysqld is NOT the one supervisor manages (stopping mysql would not
     *     stop the serving instance).
     *
     * A mysqld is "supervised" when its pid IS supervisor's managed pid (the common case, where
     * the program execs mysqld directly) OR is a descendant of it (the mysqld_safe-wrapper case,
     * where supervisor tracks the wrapper and mysqld is its child). Zero running mysqld is not
     * the rogue condition and is left to proceed: `supervisorctl stop` is then a no-op, the
     * datadir is cold and therefore consistent, and the flow starts MySQL again at the end.
     */
    protected function preflight_mysqld_topology(): void
    {
        $mysqld_pids = $this->_running_mysqld_pids();

        // Nothing serving - not a rogue topology (see docblock). Let the flow proceed.
        if (count($mysqld_pids) === 0) {
            return;
        }

        $supervised_pid = $this->_supervised_mysqld_pid();

        // Classify every running mysqld as supervised or stray.
        $supervised = [];
        $stray = [];
        foreach ($mysqld_pids as $pid) {
            $is_supervised = $supervised_pid !== null
                && ($pid === $supervised_pid || $this->_pid_has_ancestor($pid, $supervised_pid));
            if ($is_supervised) {
                $supervised[] = $pid;
            } else {
                $stray[] = $pid;
            }
        }

        // The good shape: exactly one mysqld, and supervisor manages it.
        if (count($stray) === 0 && count($supervised) === 1) {
            return;
        }

        // Bad topology - assemble a diagnostic and abort. create_snapshot()'s catch turns the
        // thrown message into a loud "[ERROR] Failed to create snapshot: ..." and returns
        // false, so run_with_snapshot() exits 1 without running a single migration.
        $lines = [];
        $lines[] = 'mysqld process topology is not the single supervised instance the snapshot requires (B-47).';
        $lines[] = '';
        $lines[] = 'Running mysqld processes:';
        foreach ($mysqld_pids as $pid) {
            $tag = in_array($pid, $stray, true) ? '[STRAY]     ' : '[supervised]';
            $lines[] = '  ' . $tag . ' pid ' . $pid . '  ' . $this->_pid_command_line($pid);
        }
        $lines[] = '';
        $lines[] = $supervised_pid !== null
            ? 'Supervisor manages mysqld pid ' . $supervised_pid . ' ([program:mysql]).'
            : 'Supervisor does NOT report a RUNNING mysql program, so NONE of the above is supervised.';
        $lines[] = '';
        $lines[] = '`supervisorctl stop mysql` only stops the supervised instance. A stray mysqld would';
        $lines[] = 'keep serving the datadir, so the snapshot would capture inconsistent data or hang';
        $lines[] = 'waiting for a process that never exits. Stop the stray instance(s) and retry:';
        foreach ($stray as $pid) {
            $lines[] = '  sudo kill ' . $pid . '        # graceful; use  sudo kill -9 ' . $pid . '  if it will not exit';
        }
        if (count($stray) === 0) {
            // No stray, but the single serving mysqld is not the supervised one (e.g. supervisor
            // reports mysql stopped while a manual mysqld serves). Point at supervisor.
            $lines[] = '  # Bring the serving mysqld back under supervisor: stop it, then';
            $lines[] = '  #   sudo supervisorctl start mysql';
        }

        throw new \Exception(implode("\n", $lines));
    }

    /**
     * The pids of every running `mysqld` process (exact command name), as ints.
     */
    protected function _running_mysqld_pids(): array
    {
        $raw = trim($this->shell_exec_privileged('pgrep -x mysqld 2>/dev/null') ?? '');
        if ($raw === '') {
            return [];
        }

        $pids = [];
        foreach (preg_split('/\s+/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && ctype_digit($line)) {
                $pids[] = (int) $line;
            }
        }

        return $pids;
    }

    /**
     * The pid supervisor manages for the `mysql` program, or null if it is not RUNNING.
     *
     * `supervisorctl status mysql` prints e.g.
     *   mysql                            RUNNING   pid 3706152, uptime 22:27:09
     * We only trust the pid when the state is RUNNING; STOPPED/FATAL/STARTING report no live pid.
     */
    protected function _supervised_mysqld_pid(): ?int
    {
        $status = trim($this->shell_exec_privileged('supervisorctl status mysql 2>/dev/null') ?? '');
        if ($status === '' || !str_contains($status, 'RUNNING')) {
            return null;
        }

        $marker = 'pid ';
        $at = strpos($status, $marker);
        if ($at === false) {
            return null;
        }

        // (int) reads the leading digits and stops at the trailing ", uptime ...".
        $pid = (int) substr($status, $at + strlen($marker));
        return $pid > 0 ? $pid : null;
    }

    /**
     * Whether $ancestor appears anywhere in $pid's parent chain (walking ppids up to init).
     *
     * The visited-set guards against a malformed cycle; it is a correctness bound on a graph
     * walk, NOT a time budget - there is no deadline here.
     */
    protected function _pid_has_ancestor(int $pid, int $ancestor): bool
    {
        $seen = [];
        $current = $pid;

        while ($current > 1 && !isset($seen[$current])) {
            $seen[$current] = true;

            $ppid_raw = trim($this->shell_exec_privileged(
                'ps -o ppid= -p ' . escapeshellarg((string) $current) . ' 2>/dev/null'
            ) ?? '');
            if ($ppid_raw === '' || !ctype_digit($ppid_raw)) {
                return false;
            }

            $ppid = (int) $ppid_raw;
            if ($ppid === $ancestor) {
                return true;
            }
            $current = $ppid;
        }

        return false;
    }

    /**
     * The full command line of a pid (for the diagnostic), or a placeholder if it has exited.
     */
    protected function _pid_command_line(int $pid): string
    {
        $args = trim($this->shell_exec_privileged(
            'ps -o args= -p ' . escapeshellarg((string) $pid) . ' 2>/dev/null'
        ) ?? '');

        return $args !== '' ? $args : '(process exited)';
    }

    /**
     * Cheap integrity check that a directory is a sane MySQL datadir, NOT the nested-copy
     * corruption that a `cp -r SRC DEST`-into-existing-DEST produces. Run via privileged
     * shell because the datadir is mysql:mysql 0700 and PHP cannot stat it directly.
     *
     * Corruption signature: the system-schema subdir `mysql/` ends up holding a full
     * datadir copy, so `mysql/ibdata1` (a datadir-root file) or a nested `mysql/mysql/`
     * appears. A clean datadir has `mysql/` (system schema) but neither of those.
     */
    protected function verify_datadir_sane(string $dir): bool
    {
        $probe = function (string $test_expr) {
            return trim($this->shell_exec_privileged("$test_expr && echo yes || echo no") ?? '') === 'yes';
        };

        $has_system_schema = $probe("test -d " . escapeshellarg("$dir/mysql"));
        $nested_ibdata = $probe("test -e " . escapeshellarg("$dir/mysql/ibdata1"));
        $nested_schema = $probe("test -d " . escapeshellarg("$dir/mysql/mysql"));

        return $has_system_schema && !$nested_ibdata && !$nested_schema;
    }

    /**
     * Register query transformation listener
     */
    protected function register_query_transformer(): void
    {
        $original_statement = \Closure::bind(function () {
            return $this->connection->statement(...func_get_args());
        }, DB::getFacadeRoot(), DB::getFacadeRoot());

        DB::macro('statement', function ($query, $bindings = []) use ($original_statement) {
            $transformed = SqlQueryTransformer::transform($query);
            return DB::connection()->statement($transformed, $bindings);
        });
    }

    /**
     * Check if all pending migrations are whitelisted
     */
    protected function checkMigrationWhitelist(array $paths): bool
    {
        $whitelistPaths = [
            database_path('migrations/.migration_whitelist'),
            base_path('rsx/resource/migrations/.migration_whitelist'),
        ];

        $whitelistedMigrations = [];
        $foundAtLeastOne = false;

        foreach ($whitelistPaths as $whitelistPath) {
            if (file_exists($whitelistPath)) {
                $foundAtLeastOne = true;
                $whitelist = json_decode(file_get_contents($whitelistPath), true);
                $migrations = array_keys($whitelist['migrations'] ?? []);
                $whitelistedMigrations = array_merge($whitelistedMigrations, $migrations);
            }
        }

        if (!$foundAtLeastOne) {
            $this->warn('[WARNING]  No migration whitelist found. Creating one with existing migrations...');
            $this->createInitialWhitelist();
            return true;
        }

        // Discover recursively so migrations in nested subdirectories (e.g.
        // database/migrations/rspade/) are validated against the whitelist too.
        // The whitelist is keyed by basename, matching how Laravel records
        // migrations, so basename is the unit of comparison here.
        $migrationFiles = [];
        foreach ($paths as $path) {
            foreach (MigrationPaths::_scan_migration_files($path) as $file) {
                $migrationFiles[] = basename($file);
            }
        }

        $unauthorizedMigrations = array_diff($migrationFiles, $whitelistedMigrations);

        if (!empty($unauthorizedMigrations)) {
            $this->error('[ERROR] Unauthorized migrations detected!');
            $this->error('');
            $this->line('The following migrations were not created via php artisan make:migration:');
            foreach ($unauthorizedMigrations as $migration) {
                $this->line('   - ' . $migration);
            }
            $this->error('');
            $this->warn('[WARNING]  Manually created migrations can cause timestamp conflicts.');
            $this->error('');
            $this->line('To fix: Create migrations using "php artisan make:migration [name]"');

            return false;
        }

        return true;
    }

    /**
     * Create initial whitelist with existing migrations
     */
    protected function createInitialWhitelist(): void
    {
        $whitelistLocations = [
            database_path('migrations/.migration_whitelist') => database_path('migrations'),
            base_path('rsx/resource/migrations/.migration_whitelist') => base_path('rsx/resource/migrations'),
        ];

        $totalMigrations = 0;

        foreach ($whitelistLocations as $whitelistPath => $migrationPath) {
            if (!is_dir($migrationPath)) {
                continue;
            }

            $whitelist = [
                'description' => 'This file tracks migrations created via php artisan make:migration',
                'purpose' => 'Prevents manually created migrations from running to avoid timestamp conflicts',
                'migrations' => [],
            ];

            foreach (glob($migrationPath . '/*.php') as $file) {
                $filename = basename($file);
                $whitelist['migrations'][$filename] = [
                    'created_at' => 'pre-whitelist',
                    'created_by' => 'system',
                    'command' => 'existing migration',
                ];
            }

            if (!empty($whitelist['migrations'])) {
                file_put_contents_safe($whitelistPath, json_encode($whitelist, JSON_PRETTY_PRINT));
                $count = count($whitelist['migrations']);
                $totalMigrations += $count;
                $location = str_replace(base_path() . '/', '', dirname($whitelistPath));
                $this->info("[OK] Created whitelist in {$location} with {$count} migration(s).");
            }
        }

        if ($totalMigrations === 0) {
            $this->info('[OK] No existing migrations found. Empty whitelists created.');
        }
    }

    /**
     * Validate migration files for Schema builder usage
     */
    protected function _validate_schema_rules(): bool
    {
        $repository = app('migration.repository');
        $pending_migrations = MigrationValidator::get_pending_migrations($repository);

        if (empty($pending_migrations)) {
            return true;
        }

        $this->info('Validating migration files for Schema builder usage...');

        $has_violations = false;

        foreach ($pending_migrations as $migration_path) {
            try {
                MigrationValidator::validate_migration_file($migration_path);
                $this->info("  [OK] " . basename($migration_path));
            } catch (\RuntimeException $e) {
                $has_violations = true;
                break;
            }
        }

        if ($has_violations) {
            $this->error('');
            $this->error('Migration validation failed!');
            $this->info('');
            $this->line('Please update your migration to use raw SQL instead of Schema builder.');
            $this->line('See: php artisan rsx:man migrations');
            return false;
        }

        // Remove down() methods
        $processed_migrations = [];
        foreach ($pending_migrations as $migration_path) {
            if (MigrationValidator::remove_down_method($migration_path)) {
                $processed_migrations[] = $migration_path;
            }
        }

        if (!empty($processed_migrations)) {
            $this->info('');
            $this->warn('[WARNING]  Modified migration files:');
            foreach ($processed_migrations as $path) {
                $this->line('  - ' . basename($path) . ' - down() method removed');
            }
            $this->info('');
        }

        return true;
    }

    /**
     * THE INITIAL PROVISION: restore the shipped schema cache into an EMPTY database.
     *
     * A fresh install replays every migration ever written to arrive at a schema that is
     * already known. rsx:db:dump_cache records that known end state - a gzipped mysqldump
     * plus an archive of whatever blobs the data-seed migrations wrote - into
     * rsx/resource/db/, which ships with the application. This restores it, and the normal
     * migration run then continues on top: migrations NEWER than the cache apply, both
     * normalize passes run, and the initial user is created exactly as always.
     *
     * TWO CONDITIONS, both required:
     *
     *   - THE DATABASE HAS NO TABLES AT ALL. Not "no migrations table", not "no rows" -
     *     zero tables. That is the only state in which restoring a whole database over
     *     the top of it can be right, and it is precisely the fresh-clone state.
     *   - THE CACHE EXISTS. No cache is not an error; it is every application that has
     *     never run the dump command, and they migrate exactly as they always did.
     *
     * A --framework-only run is excluded: it migrates a schema-only subset for tooling,
     * and the cache is the whole application's schema.
     *
     * The blob archive is EXTRACTED OVER the store, never after clearing it. The store is
     * content-addressed, so a file already present under a given hash has identical bytes
     * by construction and there is nothing a collision could destroy.
     */
    protected function maybe_restore_schema_cache(): void
    {
        if ($this->option('framework-only')) {
            return;
        }

        $table_count = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->c;

        if ($table_count > 0) {
            return;
        }

        // --_cache-dir is the same test seam rsx:db:dump_cache honours, so a test can
        // produce a cache into a sandbox and prove this restores it - without ever writing
        // into the shipped rsx/resource/db.
        $cache_dir_override = Rsx_Internal_Flags::get(Db_Dump_Cache_Command::CACHE_DIR_FLAG);
        $cache_dir = !empty($cache_dir_override)
            ? rtrim($cache_dir_override, '/')
            : rsx_project_file_path(Db_Dump_Cache_Command::CACHE_DIR_RELATIVE);

        $schema_cache = $cache_dir . '/' . Db_Dump_Cache_Command::SCHEMA_CACHE_FILE;

        if (!is_file($schema_cache)) {
            return;
        }

        $this->info('[..] Restoring cached schema from ' . $schema_cache . '...');

        $connection = config('database.default');
        $db = config('database.connections.' . $connection);

        $client_flags = '-h' . escapeshellarg((string) $db['host'])
            . ' -P' . escapeshellarg((string) $db['port'])
            . ' -u' . escapeshellarg((string) $db['username']);

        // pipefail so a corrupt archive is the pipeline's exit status rather than mysql's
        // cheerful success on a truncated stream. Streamed through mysqlpv (line-log mode)
        // so a large restore names each table as it lands instead of sitting silent - and
        // mysqlpv_pipe_segment() returns nothing on a box with no python3, where the plain
        // pipeline moves identical bytes with no progress reporting.
        $this->stream_shell_pipeline(
            'set -o pipefail; cat ' . escapeshellarg($schema_cache)
            . ' | gunzip'
            . Db_Dump_Cache_Command::mysqlpv_pipe_segment()
            . ' | mysql ' . $client_flags . ' ' . escapeshellarg((string) $db['database']),
            (string) $db['password'],
            'restoring the cached schema'
        );

        // The connection may hold a handle opened against the database as it was before
        // the restore; make the next query reconnect.
        DB::purge($connection);

        $uploads_cache = $cache_dir . '/' . Db_Dump_Cache_Command::UPLOADS_CACHE_FILE;

        if (is_file($uploads_cache)) {
            // Rsx_File_Paths is the ONE resolver for the blob store, so this honours the
            // test-isolated root that --rsx-storage-root selected earlier in handle().
            $blob_root = Rsx_File_Paths::blob_root();
            ensure_directory($blob_root);

            $this->info('[..] Restoring cached uploads into ' . $blob_root . '...');
            $this->stream_shell_pipeline(
                'tar -xzf ' . escapeshellarg($uploads_cache) . ' -C ' . escapeshellarg($blob_root),
                '',
                'restoring the cached uploads'
            );
        }

        $this->info('[OK] Cached schema restored. Applying any migrations newer than the cache...');
        $this->info('');
    }

    /**
     * Run a shell pipeline with its output STREAMED to our own stdout.
     *
     * \exec_safe() is the framework's CAPTURED-output wrapper and is the wrong tool for a
     * restore: it buffers to EOF, so a multi-minute stream would print nothing until it
     * finished. passthru() is the streaming counterpart, wrapped in an EXPLICIT `bash -c`
     * because it otherwise hands the line to /bin/sh, which is dash here (project policy).
     *
     * The MySQL password rides in the ENVIRONMENT for the duration of the call, never in
     * argv, where `ps` would show it to every user on the box.
     *
     * NO TIMEOUT: how long a restore takes is a function of the dump's size.
     */
    protected function stream_shell_pipeline(string $pipeline, string $mysql_password, string $context): void
    {
        $had_password = $mysql_password !== '';
        $previous = getenv('MYSQL_PWD');

        if ($had_password) {
            putenv('MYSQL_PWD=' . $mysql_password);
        }

        try {
            $exit_code = 0;
            \passthru('bash -c ' . escapeshellarg($pipeline), $exit_code);
        } finally {
            if ($had_password) {
                if ($previous === false) {
                    putenv('MYSQL_PWD');
                } else {
                    putenv('MYSQL_PWD=' . $previous);
                }
            }
        }

        if ($exit_code !== 0) {
            throw new \RuntimeException('Failed while ' . $context . ' (exit ' . $exit_code . ').');
        }
    }

    /**
     * Ensure the migrations table exists
     */
    protected function ensure_migrations_table_exists(): void
    {
        try {
            $table = config('database.migrations', 'migrations');
            DB::select("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception $e) {
            $table = config('database.migrations', 'migrations');
            $this->info('Creating migrations table...');

            DB::statement("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                    batch BIGINT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->info('[OK] Migrations table created');
        }
    }

    /**
     * Run migrations one-by-one with normalization after each
     */
    protected function run_migrations_with_normalization($migrator, array $migrationPaths, bool $step, array $requiredColumnsArgs): void
    {
        // Discover all migration files (recursively) and order them by timestamp
        // basename across all base paths. Ordering by basename (not full path) is
        // what lets a migration in a nested subdirectory (e.g.
        // database/migrations/rspade/) run AFTER the tables it references in a
        // sibling base path, resolving cross-directory foreign-key dependencies.
        $files = [];
        foreach ($migrationPaths as $path) {
            foreach (MigrationPaths::_scan_migration_files($path) as $file) {
                $files[] = $file;
            }
        }

        MigrationPaths::_sort_by_basename($files);

        $repository = $migrator->getRepository();
        $ran = $repository->getRan();

        $pending = [];
        foreach ($files as $file) {
            $migrationName = $migrator->getMigrationName($file);
            if (!in_array($migrationName, $ran)) {
                $pending[] = $file;
            }
        }

        if (empty($pending)) {
            $this->info('   INFO  Nothing to migrate.');
            return;
        }

        $totalMigrations = count($pending);
        $currentMigration = 1;

        foreach ($pending as $file) {
            $migrationName = $migrator->getMigrationName($file);

            $this->info("\n[$currentMigration/$totalMigrations] Running: $migrationName");
            $this->newLine();

            $migrator->runPending([$file], [
                'pretend' => false,
                'step' => $step
            ]);

            if ($currentMigration < $totalMigrations) {
                $this->info("\n Normalizing schema after migration...\n");

                AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_DESTRUCTIVE_STDOUT);

                $normalizeExitCode = $this->call('migrate:normalize_schema', $requiredColumnsArgs);

                AppServiceProvider::set_query_log_mode(AppServiceProvider::QUERY_LOG_ALL_STDOUT);

                if ($normalizeExitCode !== 0) {
                    throw new \Exception("Normalization failed after migration: $migrationName");
                }
            }

            $currentMigration++;
        }

        $this->newLine();
        $this->info("[OK] All $totalMigrations migration" . ($totalMigrations > 1 ? 's' : '') . " completed successfully");
    }
}
