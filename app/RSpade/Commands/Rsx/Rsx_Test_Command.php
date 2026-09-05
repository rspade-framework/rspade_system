<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Commands\Database\Db_Rebuild_Provision_Cache_Snapshot_Command;
use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\Console\Commands\FrameworkDeveloperCommand;
use App\RSpade\Core\Database\MigrationPaths;
use App\RSpade\Core\Manifest\Manifest;
use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Env\Rsx_Initial_User;
use App\RSpade\Core\Models\User_Model;
use ReflectionClass;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Rsx;
use Symfony\Component\Process\Process;

/**
 * The RSX test runner.
 *
 * ONE process runs the suite by default: every selected class in this PHP process, in name
 * order, against the test database. That is the path every subset takes - a --group, a
 * --filter, a named class - and it is the path every environment takes that is not a
 * docker-capable RSpade development container.
 *
 * THE FULL FRAMEWORK SUITE ON A DEV BOX RUNS IN PARALLEL DOCKER CONTAINERS. When this is a
 * development container, the docker daemon answers, --framework was asked for with no
 * narrowing selector, and --sequential was not passed, the run is handed to the node
 * orchestrator (system/bin/rsx-testd/orchestrator.js): it builds the test image, serves a
 * unix-socket work queue, and starts N sibling containers that each run THIS SAME COMMAND in
 * worker mode. A container is a complete isolated environment - its own mysqld on a RAM
 * datadir, its own redis, its own rsx-lockd, its own filesystem and flag files - so the
 * isolation hazards of an in-process parallel runner (shared database, shared locks, the
 * box-global maintenance flag) do not exist by construction.
 *
 * PHP owns discovery, the singleton flock, running tests and the printed output; node owns
 * the docker lifecycle and the queue. Nothing is implemented twice: the worker sends the
 * same per-class record the sequential loop would have printed, and merge_and_report()
 * prints it through the SAME helpers the sequential loop prints through.
 *
 * --sequential forces the single-process path anywhere. --workers overrides the container
 * count. Mechanics of the docker side: system/bin/rsx-testd/CLAUDE.md.
 */
class Rsx_Test_Command extends FrameworkDeveloperCommand
{
    /**
     * Cache format version. Bump this whenever a change to the provisioning
     * logic (schema normalization, seed data, etc.) would make the resulting
     * test-database schema differ from an existing cached dump even though the
     * migration files themselves are unchanged. Bumping invalidates all caches.
     */
    const CACHE_VERSION = 3;

    /**
     * Ceiling on the container count regardless of how big the box is. A container is a
     * whole environment (mysqld + redis + rsx-lockd + php), so the useful ceiling is set by
     * what the docker daemon and the host page cache tolerate, not by core count alone.
     */
    const WORKER_MAX = 8;

    /**
     * Megabytes of RAM budgeted per container. Each one runs its datadir on tmpfs plus a
     * mysqld, a redis and a PHP process, so the worker count is floored by memory as well
     * as by cores: min(WORKER_MAX, cores, floor(MemTotal_MB / WORKER_MEMORY_MB)).
     */
    const WORKER_MEMORY_MB = 1000;

    /**
     * The image the workers run, built by the orchestrator from Dockerfile.test.
     */
    const TEST_IMAGE = 'rspade-test:latest';

    /**
     * The shipped development image the test image is FROM. The orchestrator refuses (naming
     * build.sh) when it is absent; it never builds it itself.
     */
    const DEV_IMAGE = 'rspade/rspade-server-dev:latest';

    /**
     * Node entry point of the docker orchestrator, relative to base_path().
     */
    const ORCHESTRATOR_SCRIPT = 'bin/rsx-testd/orchestrator.js';

    /**
     * How many of the orchestrator's last output lines are repeated under the failure
     * message when it exits non-zero. Its output was already streamed live; this only saves
     * the operator scrolling back past a long build to find what actually broke.
     */
    const ORCHESTRATOR_TAIL_LINES = 20;

    /**
     * A worker with no timing history for a $requires_db_reset class scores this many
     * seconds when ordering the work queue longest-first. It is an ORDERING PROXY, never
     * a deadline - a reset class re-provisions its database (~25s measured) before its own
     * tests run, so it belongs at the head of the queue. Real durations replace it after
     * the first run (see write_timings()).
     */
    const RESET_CLASS_ORDER_PROXY_SECONDS = 25.0;

    /**
     * The single user the migrated TEST baseline carries.
     *
     * WHY THE BASELINE CARRIES A USER AT ALL. A freshly migrated database has nobody in
     * it: the RSPADE_DEFAULT_* post-migrate seed creates an account only when those keys
     * are configured (a framework must not invent a credential), and the test runner
     * suppresses even that with --_no-initial-user. Tests, however, are written against
     * an application that HAS an identity: __acting_as_user(1) is how a test says
     * "somebody is signed in", and the audit-stamp, actor and auth-gate suites all assume
     * that user resolves. So the runner provisions it - in the test database only, never
     * in a real one - as its own step after migrate, which is also what a developer
     * cloning an application experiences.
     *
     * WHY ID 1. Because the framework guarantees it: Rsx_Initial_User::create() ASSIGNS
     * id 1 to both halves of the initial account rather than leaving it to
     * AUTO_INCREMENT, and refuses when id 1 is already taken. Every test that hardcodes
     * user 1 is relying on that contract, not on an empty table.
     */
    const BASELINE_USER_ID = 1;

    const BASELINE_SITE_ID = 1;

    const BASELINE_USER_EMAIL = 'test-user-1@rspade.test';

    /**
     * The baseline account's password. A fixture credential in a throwaway database,
     * named here because a test that signs in by password needs to know it.
     */
    const BASELINE_USER_PASSWORD = 'rspade-test-user-password';

    /**
     * The open file handle of the runner singleton flock, held for the life of the process
     * and released by the shutdown function acquire_runner_singleton() registers.
     */
    protected static $singleton_lock_fp = null;

    /**
     * Request id counter for the worker's queue RPC (see queue_request()).
     */
    protected int $queue_request_id = 0;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:test
                            {test?* : Test class(es) to run - substring match, repeat for a set}
                            {--filter=* : Run only tests whose class or method name matches - repeat for a set}
                            {--group=* : Run only the named test group(s) (the concern directory under tests/, e.g. locks)}
                            {--framework : Run framework tests (under app/RSpade) instead of application tests}
                            {--fresh : Drop and recreate the test database, then run all migrations}
                            {--sequential : Force the single-process runner even when the docker gate would pass}
                            {--workers= : Override the container count for a docker run (default: min(8, cores, RAM_GB))}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run RSX framework tests';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // This process IS the test suite, and so is everything it spawns: Rsx_Artisan forwards
        // the flag to every child, which is how a test-run migrate in another mode gets the
        // http-APP_URL allowance a real deployment in that mode does not.
        Rsx_Internal_Flags::set(\App\RSpade\Core\Testing\Rsx_Test_Abstract::TEST_RUN_FLAG);

        // ONE test run per box at a time, whichever path it takes. Two concurrent runs share
        // the test database, the dump cache and (in docker mode) the container names, so the
        // second one waits here - for as long as it takes, no deadline.
        $this->acquire_runner_singleton();

        // All three selectors accept a SET. Within one selector the members are
        // OR'd (run this class OR that one); across selectors they are AND'd
        // (this group AND matching this filter), so each one narrows further.
        $specific_tests = array_filter((array) $this->argument('test'));
        $filters = array_filter((array) $this->option('filter'));
        $groups = array_filter((array) $this->option('group'));
        $framework_only = (bool) $this->option('framework');

        $this->info('RSX Test Runner');
        $this->info('================');
        $this->line('Suite: ' . ($framework_only ? 'framework (app/RSpade)' : 'application (rsx)'));
        if ($groups) {
            $this->line('Groups: ' . implode(', ', $groups));
        }
        if ($specific_tests) {
            $this->line('Tests: ' . implode(', ', $specific_tests));
        }
        if ($filters) {
            $this->line('Filters: ' . implode(', ', $filters));
        }
        $this->newLine();

        // WORKER SUB-MODE. Inside a test container the orchestrator's CMD passes
        // --_worker-socket (and --_worker-id): this process pulls whole classes from the
        // orchestrator's queue and sends each class's outcome back over the same socket. The
        // container is the isolation - the worker uses the container's own plain test
        // database and needs no per-worker naming of anything.
        if (Rsx_Internal_Flags::get('--_worker-socket') !== null) {
            return $this->worker_run();
        }

        // IMAGE-BUILD SUB-MODE. The docker test image bakes a fully provisioned test
        // database into its MySQL data directory: this brings the test schema to the
        // migrated baseline (schema + the baseline user) and guarantees the cache dump
        // for the current migration hash exists, so a container that resets its database
        // between classes restores from that dump instead of re-migrating. It runs no
        // tests - the image build has none to run.
        if (Rsx_Internal_Flags::has('--_provision-only')) {
            if (!$this->prepare_test_database(false)) {
                return 1;
            }

            if (!$this->ensure_baseline_cache()) {
                return 1;
            }

            $this->info('[OK] test database provisioned');

            return 0;
        }

        // DOCKER MODE. The full framework suite on a docker-capable development box runs as
        // N sibling containers instead of one process. It is decided BEFORE the test database
        // is touched: every test runs inside a container against that container's own
        // database, so provisioning this box's test database would be pure cost.
        if ($this->docker_mode_gate_passes($specific_tests, $filters, $groups, $framework_only)) {
            $selected = $this->discover_selected_classes($specific_tests, $filters, $groups, $framework_only);
            if ($selected === null) {
                return 0;
            }

            return $this->run_docker($selected, $filters);
        }

        // Swap to the test database for the whole run (airtight - dev DB never
        // touched) and make sure its schema is up to date.
        if (!$this->prepare_test_database((bool)$this->option('fresh'))) {
            return 1;
        }

        // The set of classes this invocation will actually run - every class-level
        // selector (framework partition, --group, specific-class args, --filter,
        // abstract skip) already applied, in stable name order. The docker path and the
        // sequential loop iterate THIS SAME set, so which classes run is decided in
        // exactly one place.
        $selected = $this->discover_selected_classes($specific_tests, $filters, $groups, $framework_only);
        if ($selected === null) {
            return 0;
        }

        $totals = self::__empty_totals();

        // Tracks whether a prior $requires_db_reset class may have committed
        // data, so we can restore the clean baseline before the next class.
        $db_dirty = false;

        foreach ($selected as $selected_class) {
            $class_name = $selected_class['fqcn'];
            $short_name = $selected_class['short'];
            $class_matches = $selected_class['class_matches'];

            // Per-class blank-slate reset. The database is only ever left dirty
            // by a prior $requires_db_reset class that may have committed; a
            // transaction-based class always rolls back. So we restore the clean
            // baseline whenever it is dirty - which gives a reset class its blank
            // slate and protects the next transaction class from leftover data.
            $needs_reset = $class_name::requires_db_reset();
            if ($db_dirty) {
                $this->line("Resetting test database for {$short_name}...");
                if (!$this->reset_test_db()) {
                    $this->error('  Error: failed to reset test database before ' . $short_name);
                    $totals['failed']++;

                    continue;
                }
                $db_dirty = false;
            }

            $this->info("Running: {$short_name}");

            try {
                // Run the tests
                $results = $class_name::run();

                // A reset class may have committed data - flag the DB as dirty
                // so the next class restores the clean baseline first.
                if ($needs_reset) {
                    $db_dirty = true;
                }

                $this->print_class_results($results, $class_matches, $filters, $totals);
            } catch (Exception $e) {
                $this->error('  Error running test class: ' . $e->getMessage());
                $totals['failed']++;
            }

            $this->newLine();
        }

        // The run is over: drop what it cached under the test suffix so the next run
        // starts from the database, not from a previous run's answers.
        \App\RSpade\Core\Cache\RsxCache::clear();

        return $this->print_summary($totals);
    }

    /**
     * Build the class set this invocation runs: manifest rebuild, discovery, name order,
     * then every class-level selector. Null means the manifest holds no test classes at all
     * (already reported) and the caller should exit 0.
     *
     * @param array $specific_tests
     * @param array $filters
     * @param array $groups
     * @param bool $framework_only
     * @return array|null select_test_classes() output
     */
    protected function discover_selected_classes(
        array $specific_tests,
        array $filters,
        array $groups,
        bool $framework_only
    ): ?array {
        // Rebuild manifest to ensure we have latest test classes
        $this->line('Building manifest...');
        Manifest::init();

        // Get all test classes extending Rsx_Test_Abstract
        $test_classes = Manifest::php_get_extending('Rsx_Test_Abstract');

        if (empty($test_classes)) {
            $this->warn('No test classes found.');
            $this->line('Test classes should extend App\\RSpade\\Core\\Testing\\Rsx_Test_Abstract');

            return null;
        }

        // Deterministic, reproducible run order (by class name).
        ksort($test_classes);

        return $this->select_test_classes($test_classes, $specific_tests, $filters, $groups, $framework_only);
    }

    /**
     * The running tally both printers keep.
     *
     * @return array{tests:int,passed:int,failed:int,skipped:int}
     */
    private static function __empty_totals(): array
    {
        return ['tests' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
    }

    /**
     * Print ONE class's per-method lines and fold them into the totals. THE one
     * implementation - the sequential loop calls it with what $class::run() just returned,
     * merge_and_report() calls it with what a container sent back, so the two paths cannot
     * drift a single character apart.
     *
     * @param array $results Rsx_Test_Abstract::run() output (test_name => result)
     * @param bool $class_matches Whether --filter matched the class name (report every method)
     * @param array $filters
     * @param array $totals
     * @return void
     */
    protected function print_class_results(array $results, bool $class_matches, array $filters, array &$totals): void
    {
        foreach ($results as $test_name => $result) {
            // Select this method when there's no filter, the class name
            // matched the filter (report every method), or this specific
            // method name matches (case-insensitive on both sides).
            if (!(!$filters || $class_matches || self::__matches_any($test_name, $filters))) {
                continue;
            }

            $totals['tests']++;

            switch ($result['status']) {
                case 'passed':
                    $totals['passed']++;
                    $this->line("  [OK] {$test_name}");
                    break;

                case 'failed':
                    $totals['failed']++;
                    $this->error("  [FAIL] {$test_name}");
                    $this->line("    {$result['message']}", 'fg=red');
                    if (isset($result['file']) && isset($result['line'])) {
                        $this->line("    at {$result['file']}:{$result['line']}", 'fg=gray');
                    }
                    break;

                case 'skipped':
                    $totals['skipped']++;
                    $this->line("  - {$test_name} (skipped)", 'fg=yellow');
                    $this->line("    {$result['message']}", 'fg=gray');
                    break;
            }
        }
    }

    /**
     * Print the run summary and return the process exit code. THE one implementation, shared
     * by the sequential path and the docker merge.
     *
     * @param array $totals
     * @return int
     */
    protected function print_summary(array $totals): int
    {
        $this->info('Test Summary');
        $this->info('============');
        $this->line("Total:   {$totals['tests']}");

        if ($totals['passed'] > 0) {
            $this->line("Passed:  {$totals['passed']}", 'fg=green');
        }
        if ($totals['failed'] > 0) {
            $this->line("Failed:  {$totals['failed']}", 'fg=red');
        }
        if ($totals['skipped'] > 0) {
            $this->line("Skipped: {$totals['skipped']}", 'fg=yellow');
        }

        $this->newLine();

        if ($totals['failed'] > 0) {
            $this->error('Tests failed!');

            return 1;
        }
        if ($totals['tests'] === 0) {
            $this->warn('No tests were run.');

            return 0;
        }
        $this->info('All tests passed!');

        return 0;
    }

    /**
     * Take the test runner's singleton lock, blocking until it is ours.
     *
     * A RAW flock, deliberately not RsxLocks: this must hold across a maintenance window
     * (where cluster locks are granted as no-ops) and it must be taken before any service
     * this process depends on is consulted. Mirrors Rsx_Preboot_Service::__acquire_file_lock().
     *
     * NO TIMEOUT. A second rsx:test waits for the first one to finish, however long that is -
     * a test run's length is a function of the suite, and giving up on the wait would run two
     * runs over one test database.
     *
     * @return void
     */
    protected function acquire_runner_singleton(): void
    {
        $lock_file = storage_path('flock/rsx_test_runner.lock');

        $dir = dirname($lock_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 'c' opens-or-creates WITHOUT truncating: the pid inside belongs to the holder
        // until we actually own the lock.
        $fp = fopen($lock_file, 'c');
        if (!$fp) {
            throw new \RuntimeException('Could not open the test runner lock file: ' . $lock_file);
        }

        // Announce the wait only when there is one - a free lock stays silent.
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $this->line('Waiting for another test run to finish...');
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Could not acquire the test runner lock: ' . $lock_file);
            }
        }

        ftruncate($fp, 0);
        fwrite($fp, getmypid() . "\n");
        fflush($fp);

        self::$singleton_lock_fp = $fp;

        register_shutdown_function(static function () {
            if (self::$singleton_lock_fp) {
                flock(self::$singleton_lock_fp, LOCK_UN);
                fclose(self::$singleton_lock_fp);
                self::$singleton_lock_fp = null;
            }
        });
    }

    /**
     * Case-insensitive substring match against ANY member of a set. An empty set
     * never matches - callers test the set separately when "no selector given"
     * should mean "everything".
     *
     * @param string $haystack
     * @param array $needles
     * @return bool
     */
    private static function __matches_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains(strtolower($haystack), strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a test file belongs to any of the named groups.
     *
     * A GROUP is the concern directory a test lives in - the thing that owns a
     * README.md and a test_catalog.md: `app/RSpade/tests/<group>/php/X_Test.php`
     * for framework tests, `rsx/tests/<group>/...` for application ones. An
     * application test sitting directly in `rsx/tests/` has no group and is
     * therefore never selected by --group.
     *
     * Matching is exact on the directory name (not a substring), so --group=locks
     * cannot accidentally drag in an unrelated concern whose name contains it.
     *
     * @param string $file Manifest-relative path of the test file
     * @param array $groups
     * @return bool
     */
    private static function __file_in_groups(string $file, array $groups): bool
    {
        if ($file === '' || !preg_match('#(?:^|/)tests/([^/]+)/#', $file, $m)) {
            return false;
        }

        foreach ($groups as $group) {
            if (strcasecmp($m[1], (string) $group) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify the test database is safe to use, sync its schema, and swap it in
     * as the default connection for the rest of this run.
     *
     * @param bool $fresh Drop and recreate the test database before migrating
     * @return bool True if the test database is ready, false to abort the run
     */
    protected function prepare_test_database(bool $fresh): bool
    {
        $dev_db = config('database.connections.mysql.database');
        $test_db = config('database.connections.test.database');

        // SAFETY: the test database must be distinct from the developer
        // database. If they match, refuse to run rather than risk wiping the
        // developer's data during a schema sync.
        if (empty($test_db)) {
            $this->error('[ERROR] No test database configured (database.connections.test.database is empty).');

            return false;
        }

        if ($test_db === $dev_db) {
            $this->error('[ERROR] Test database matches the developer database (' . $test_db . ').');
            $this->line('Set DB_TEST_DATABASE in .env to a separate database (e.g. rspade_test).');

            return false;
        }

        $this->line("Test database: {$test_db} (connection: test)");

        // Redirect the ENTIRE file subsystem (blob store, thumbnail cache, rendition
        // cache) to a test-scoped directory for the whole run, mirroring the DB swap.
        // Without this, a model-layer attachment delete on the TEST database unlinks a
        // shared disk file - destroying a developer-database blob whose bytes exist in
        // both (backlog B-38). Every file path resolves through Rsx_File_Paths, which
        // reads this key. Set BEFORE any provisioning so in-process work is covered,
        // and passed to the migrate subprocess below via --rsx-storage-root.
        //
        // No DB-match-style refusal guard here: unlike DB_TEST_DATABASE (user config
        // that could collide with the dev DB), this root is hard-coded by the runner
        // under storage/rsx-tmp - it can never point at the live store.
        $storage_root = storage_path('rsx-tmp/test-storage');
        ensure_directory($storage_root);
        config(['rsx.files.storage_root' => $storage_root]);

        // Sync schema when forced (--fresh) or when the test database has no
        // migrated schema yet. Migrations are forward-only and cannot reconcile
        // a partial/dirty database, so a sync always drops and recreates first.
        if ($fresh || !$this->test_db_has_schema($test_db)) {
            if (!$this->sync_test_schema($test_db)) {
                return false;
            }
        } else {
            // Existing schema: apply any PENDING migrations (framework + app) before the
            // run, so a plain rsx:test never executes against a stale test schema (a new
            // migration used to require --fresh; tests failed on missing columns).
            $pending = $this->test_db_pending_migrations();
            if (!empty($pending)) {
                $this->line('Applying ' . count($pending) . ' pending migration(s) to the test database...');
                if (!$this->run_migrate_subprocess($test_db)) {
                    return false;
                }
                // Deliberately NOT cached: the dump cache must hold only the pristine
                // freshly-provisioned baseline. This database may carry committed residue
                // from prior non-transactional test classes - caching it would poison
                // every future $requires_db_reset re-provision. The next reset/--fresh
                // builds + caches the new baseline under the new migration hash.
                $this->line('[OK] Test database schema is up to date.');
            }
        }

        // Every path into this method lands here with a migrated test schema - freshly
        // provisioned, restored from cache, or pre-existing with pending migrations just
        // applied. The baseline user is provisioned once for all of them (the seed is
        // idempotent, so the paths that already produced it are a no-op).
        if (!$this->seed_test_baseline_user()) {
            return false;
        }

        // Swap the default connection to the test database for the whole run.
        config(['database.default' => 'test']);
        DB::purge('test');
        DB::setDefaultConnection('test');

        // The cache and every database-derived process static were filled from the
        // development connection during boot. A cached answer is cheap to lose and
        // expensive to trust across a connection swap, so both are dropped here and the
        // run rebuilds them from the test database on first use.
        \App\RSpade\Core\Database\Lifecycle\Transaction_Rollback_Cache_Reset::reset();

        $this->newLine();

        return true;
    }

    /**
     * Re-provision the test database to a clean, freshly-migrated baseline
     * (used between $requires_db_reset classes). Reuses the migration-hash dump
     * cache, so this is fast when migrations are unchanged.
     *
     * @return bool
     */
    protected function reset_test_db(): bool
    {
        $test_db = config('database.connections.test.database');

        if (!$this->sync_test_schema($test_db)) {
            return false;
        }

        // The 'test' connection's PDO points at the dropped database - drop it
        // so the next query reconnects to the freshly created one.
        DB::purge('test');
        DB::setDefaultConnection('test');

        // The database was dropped and restored, so every cached view of it is stale - and
        // no rollback event fired to say so. Same two calls, for the same reason, as
        // prepare_test_database() (see the comment there).
        \App\RSpade\Core\Database\Lifecycle\Transaction_Rollback_Cache_Reset::reset();

        return true;
    }

    /**
     * Check whether the test database already has a migrated schema.
     *
     * @param string $test_db
     * @return bool
     */
    protected function test_db_has_schema(string $test_db): bool
    {
        $migrations_table = config('database.migrations', 'migrations');

        try {
            return DB::connection('test')->table($migrations_table)->count() > 0;
        } catch (\Throwable $e) {
            // Database or migrations table doesn't exist yet
            return false;
        }
    }

    /**
     * Drop, recreate and provision the test database.
     *
     * Provisioning is forward-only: the database is dropped and recreated, then
     * either restored from a cached mysqldump (when the migration set is
     * unchanged) or fully migrated and re-cached. Migrations run in a subprocess that
     * passes --_no-snapshot, so the development-mode datadir snapshot - which operates on
     * the whole MySQL instance - never runs for a test schema sync.
     *
     * @param string $test_db
     * @return bool
     */
    protected function sync_test_schema(string $test_db): bool
    {
        // Issue DROP/CREATE via the developer connection - it is never bound to
        // the test database, so dropping the test database here is safe.
        $admin = DB::connection('mysql');

        $this->line("Recreating test database {$test_db}...");
        $admin->statement("DROP DATABASE IF EXISTS `{$test_db}`");
        $admin->statement("CREATE DATABASE `{$test_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Fast path: if a cached dump matches the current migration set, restore
        // it instead of re-running every migration.
        $hash = $this->compute_migration_hash();
        $cache_file = $this->test_cache_dir() . "/{$hash}.sql";

        if (is_file($cache_file)) {
            $this->line('Restoring test database from cache (migrations unchanged)...');
            if ($this->restore_test_db_from_cache($test_db, $cache_file)) {
                $this->line('[OK] Test database restored from cache.');

                // The connection still points at the dropped database.
                DB::purge('test');

                // The cached dump already carries the baseline user; this is the
                // re-provision guarantee, not a second insert (the seed is idempotent).
                return $this->seed_test_baseline_user();
            }

            // Corrupt/unusable cache - discard it, recreate the database, and
            // fall through to a full migration.
            $this->warn('[WARNING] Cached dump could not be restored; running a full migration.');
            @unlink($cache_file);
            $admin->statement("DROP DATABASE IF EXISTS `{$test_db}`");
            $admin->statement("CREATE DATABASE `{$test_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $this->line('Running migrations against test database...');

        if (!$this->run_migrate_subprocess($test_db)) {
            return false;
        }

        // THE ORDER HERE IS THE POINT: migrations -> normalize (both inside the migrate
        // subprocess) -> the baseline user -> the dump. Creating the account as its own
        // step AFTER migrate finishes is what a developer cloning an application actually
        // experiences, and it means the event fires - and the application's handlers run -
        // against the converged tip schema, exactly as they will on a real install. The
        // migrate subprocess is told not to seed an account of its own (--_no-initial-user).
        //
        // Before the dump, so the cached dump - which every later re-provision restores
        // instead of re-migrating - carries the account and its handler rows too.
        // The 'test' connection may still hold a PDO for the database we just dropped.
        DB::purge('test');
        if (!$this->seed_test_baseline_user()) {
            return false;
        }

        // Cache the freshly migrated schema for next time (best effort).
        $this->dump_test_db_to_cache($test_db, $cache_file);

        $this->line('[OK] Test database schema is up to date.');

        return true;
    }

    /**
     * Run `migrate --force` in a fresh subprocess against the test database. Shared by
     * the full provisioning path and the incremental pending-migration apply.
     *
     * THE SNAPSHOT IS SUPPRESSED BY A FLAG, NOT BY A MODE. The development-mode datadir
     * snapshot stops the whole MySQL instance and copies /var/lib/mysql, which must never
     * happen for a test schema sync - the database being migrated was just dropped and
     * recreated empty, so there is nothing to protect and everything to pay for. That
     * statement is exactly what Maint_Migrate's --_no-snapshot means, so this passes it.
     * (It used to export RSX_MODE=debug instead, which suppressed the snapshot as a side
     * effect of changing the child's whole application mode - per-invocation intent riding
     * as an environment variable, against the owner ruling in Core/CLAUDE.md, and it made
     * the runner unusable on any dev box with an http:// APP_URL, which debug mode refuses.)
     *
     * DB_DATABASE stays an environment variable because it IS an environment fact - which
     * database this process talks to. Laravel's immutable dotenv will not override it, so
     * the subprocess targets the test database.
     *
     * Spawned through Rsx_Artisan so the child JOINS THIS PROCESS'S LOCK GROUP. Without
     * that it deadlocks against its own parent: the runner takes cluster:SITE_n on its
     * first site-scoped write and holds it until shutdown, then blocks here in waitpid
     * while the child queues for the same lock. Observed live, twice, for 10-12 hours.
     *
     * The subprocess does NOT inherit our in-process rsx.files.storage_root config, so a
     * data-seed migration that writes blobs (the template app's import_sample_documents
     * runs create_from_disk) would land in the REAL blob store. Per-invocation intent
     * rides as a --flag (owner ruling: NO env prefixes for invocation intent): the
     * migrate command reads --rsx-storage-root and sets the config before migrating, so
     * provisioning stays inside the isolated test root too (B-38).
     *
     * @param string $test_db
     * @return bool
     */
    protected function run_migrate_subprocess(string $test_db): bool
    {
        $storage_root = config('rsx.files.storage_root');

        // --_no-initial-user: the migrate post-step must NOT seed an account from
        // RSPADE_DEFAULT_* here. This database's ONE identity is the baseline user, seeded
        // as the very next step by seed_test_baseline_user() - and it needs id 1, which an
        // env-seeded account would already have taken. A framework-internal flag rather
        // than relying on those keys being blank: whether the developer running the suite
        // happens to have configured credentials is not something the baseline may depend
        // on. (The `--_` convention: no InputOption, stripped pre-boot from argv.)
        $args = ['--force', '--_no-initial-user', Maint_Migrate::NO_SNAPSHOT_FLAG];
        if (!empty($storage_root)) {
            $args[] = '--rsx-storage-root=' . (string) $storage_root;
        }

        $output = [];
        $exit_code = Rsx_Artisan::run('migrate', $args, $output, [
            'DB_DATABASE' => $test_db,
        ]);

        if ($exit_code !== 0) {
            $this->error('[ERROR] Test database migration failed:');
            $this->line(implode("\n", $output), 'fg=red');

            return false;
        }

        return true;
    }

    /**
     * Provision the ONE user the test baseline carries (see BASELINE_USER_ID).
     *
     * Created through Rsx_Initial_User - the one implementation of "this application's
     * initial user" - so the baseline account is structurally identical to what a real
     * first run produces: an activated, verified, active login_users credential with id
     * 1, plus an enabled users site profile with id 1 in site 1. A test signing in as it
     * therefore exercises the same shape a real first account has, and the
     * user.initial.created handlers an application registers have run against the
     * baseline exactly as they will have run on a real install.
     *
     * The role is the highest one there is (ROLE_DEVELOPER), because a baseline identity
     * that fails an auth gate would make every gated surface untestable by default; a
     * test that needs a WEAKER identity creates its own.
     *
     * Idempotent: a baseline that already carries the user (restored from the dump cache,
     * or created by the initial-user migration when credentials are configured) is left
     * exactly as it is.
     *
     * The write goes to the 'test' CONNECTION explicitly: this runs while the default
     * connection may still be the developer database, and an unqualified model save would
     * target that instead.
     *
     * @return bool
     */
    protected function seed_test_baseline_user(): bool
    {
        $connection_name = 'test';
        $test_db = (string) config('database.connections.test.database');

        // SAFETY: this writes rows, so it proves WHERE it is writing before it does.
        // Both halves are checked - the connection we are about to use, and the database
        // that connection is actually bound to - because either alone could be made to
        // agree with a mistake. Anything else is an impossible condition, not a case to
        // handle: there is no correct way to seed a test fixture into a live database.
        $connection = DB::connection($connection_name);
        $bound_database = (string) $connection->getDatabaseName();

        if ($test_db === '' || $bound_database !== $test_db) {
            shouldnt_happen(
                'Refusing to seed the test baseline user: connection "' . $connection_name
                . '" is bound to database "' . $bound_database . '", not the configured test database "'
                . $test_db . '".'
            );
        }

        if ($connection->table('users')->where('id', self::BASELINE_USER_ID)->exists()) {
            return true;
        }

        Rsx_Initial_User::create(self::BASELINE_USER_EMAIL, self::BASELINE_USER_PASSWORD, [
            'connection' => $connection_name,
            'site_id' => self::BASELINE_SITE_ID,
            'role_id' => User_Model::ROLE_DEVELOPER,
            'first_name' => 'Test',
            'last_name' => 'User',
            'source' => Rsx_Initial_User::SOURCE_TEST_BASELINE,
        ]);

        return true;
    }

    /**
     * Migration files (framework + app, the same canonical set the migration hash uses)
     * that have no row in the test database's migrations table - i.e. pending there.
     *
     * @return array<int, string> pending migration names
     */
    protected function test_db_pending_migrations(): array
    {
        $migrations_table = config('database.migrations', 'migrations');

        try {
            $applied = DB::connection('test')->table($migrations_table)->pluck('migration')->all();
        } catch (\Throwable $e) {
            // No migrations table -> treated as no-schema by the caller's earlier check;
            // nothing sensible to report here.
            return [];
        }
        $applied = array_flip($applied);

        $pending = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $name = basename($file, '.php');
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Directory holding cached test-database dumps. Lives under storage so it
     * survives rsx:clean (which only wipes rsx-build and rsx-tmp).
     *
     * @return string
     */
    protected function test_cache_dir(): string
    {
        $dir = storage_path('db_backups/test-db-cache');
        ensure_directory($dir);

        return $dir;
    }

    /**
     * Content hash of the full migration set plus the MySQL version and cache
     * format version. A cached dump is only reused when this hash matches, so
     * any change to a migration file (or the server version) forces a rebuild.
     *
     * THE SHIPPED SCHEMA CACHE IS PART OF THE INPUT. Test provisioning drops the test
     * database and migrates it from empty, which is exactly the fresh-clone state that
     * restores rsx/resource/db/schema_cache.sql.gz before migrating - deliberately, so
     * the suite exercises what a real install does. That makes the cache an INPUT to the
     * provisioned schema, and an input that is not in this hash is a stale dump waiting
     * to happen: rebuilding the cache with rsx:db:rebuild_provision_cache_snapshot would otherwise leave the
     * previous dump matching. Absent (no cache shipped) hashes as the literal 'none'.
     *
     * @return string
     */
    protected function compute_migration_hash(): string
    {
        $version = DB::connection('mysql')->selectOne('SELECT VERSION() AS v')->v;

        $entries = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $entries[] = relative_path($file) . ':' . md5_file($file);
        }
        sort($entries);

        array_unshift(
            $entries,
            'cache_version:' . self::CACHE_VERSION,
            'mysql:' . $version,
            'schema_cache:' . $this->schema_cache_fingerprint()
        );

        return md5(implode("\n", $entries));
    }

    /**
     * Content hash of the two shipped schema-cache artifacts, or 'none' when the
     * application ships no cache. See compute_migration_hash().
     *
     * @return string
     */
    protected function schema_cache_fingerprint(): string
    {
        $cache_dir = rsx_project_file_path(Db_Rebuild_Provision_Cache_Snapshot_Command::CACHE_DIR_RELATIVE);

        $parts = [];
        foreach ([Db_Rebuild_Provision_Cache_Snapshot_Command::SCHEMA_CACHE_FILE, Db_Rebuild_Provision_Cache_Snapshot_Command::UPLOADS_CACHE_FILE] as $file) {
            $path = $cache_dir . '/' . $file;
            if (is_file($path)) {
                $parts[] = $file . ':' . md5_file($path);
            }
        }

        return empty($parts) ? 'none' : md5(implode("\n", $parts));
    }

    /**
     * Restore a cached dump into the (freshly created, empty) test database.
     *
     * @param string $test_db
     * @param string $cache_file
     * @return bool
     */
    protected function restore_test_db_from_cache(string $test_db, string $cache_file): bool
    {
        $conn = config('database.connections.test');

        // Password rides in the child's environment, never on the command line, where
        // `ps` would expose it to every user on the box.
        $command = sprintf(
            'mysql -h%s -P%s -u%s %s < %s 2>&1',
            escapeshellarg((string) $conn['host']),
            escapeshellarg((string) $conn['port']),
            escapeshellarg((string) $conn['username']),
            escapeshellarg($test_db),
            escapeshellarg($cache_file)
        );

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, ['MYSQL_PWD' => (string) $conn['password']]);

        if ($exit_code !== 0) {
            $this->line(implode("\n", $output), 'fg=red');

            return false;
        }

        return true;
    }

    /**
     * Dump the migrated test database to the cache (best effort - failure here
     * never fails the test run). Written to a .partial file then renamed so a
     * crash mid-dump can never leave a truncated cache that would be restored.
     *
     * @param string $test_db
     * @param string $cache_file
     * @return void
     */
    protected function dump_test_db_to_cache(string $test_db, string $cache_file): void
    {
        // Keep only the current cache - stale dumps for other migration sets
        // are never reused (their hash won't match).
        $this->prune_test_cache();

        $conn = config('database.connections.test');
        $partial = $cache_file . '.partial';

        // Password rides in the child's environment, never on the command line, where
        // `ps` would expose it to every user on the box.
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s --no-tablespaces --single-transaction --quick --lock-tables=false %s > %s 2>/dev/null',
            escapeshellarg((string) $conn['host']),
            escapeshellarg((string) $conn['port']),
            escapeshellarg((string) $conn['username']),
            escapeshellarg($test_db),
            escapeshellarg($partial)
        );

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, ['MYSQL_PWD' => (string) $conn['password']]);

        if ($exit_code !== 0 || !is_file($partial) || filesize($partial) === 0) {
            @unlink($partial);
            $this->warn('[WARNING] Could not cache test database dump (continuing without cache).');

            return;
        }

        if (@rename($partial, $cache_file)) {
            $this->line('[OK] Cached test database schema for reuse.');
        } else {
            @unlink($partial);
        }
    }

    /**
     * Remove all cached dumps. The current run writes a fresh one keyed by the
     * current migration hash.
     *
     * @return void
     */
    protected function prune_test_cache(): void
    {
        foreach (glob($this->test_cache_dir() . '/*.sql*') as $file) {
            @unlink($file);
        }
    }

    // =========================================================================
    // DOCKER PARALLEL RUNNER
    //
    // The full framework suite is spread across N sibling containers, each a complete
    // isolated environment running THIS command in worker mode and pulling WHOLE classes
    // from the orchestrator's socket queue (longest-first). The atomic unit of parallel
    // work is one class: a class's tests share state and must run in order, but the suite
    // has no cross-class ordering dependency (the sequential runner alpha-sorts and
    // nothing relies on that order).
    // =========================================================================

    /**
     * The class-level selection - which classes this invocation runs - applied in exactly
     * one place so the sequential loop and the parallel orchestrator can never disagree.
     * Every decision the historical loop made inline is made here: framework/app partition,
     * --group, specific-class args, abstract skip, and the --filter "selects nothing in this
     * class" skip. Order is the caller's (name-sorted) order.
     *
     * @param array $test_classes Manifest entries (fqcn + file), already name-sorted
     * @param array $specific_tests
     * @param array $filters
     * @param array $groups
     * @param bool $framework_only
     * @return array<int, array{fqcn:string,file:string,short:string,class_matches:bool}>
     */
    protected function select_test_classes(
        array $test_classes,
        array $specific_tests,
        array $filters,
        array $groups,
        bool $framework_only
    ): array {
        $selected = [];

        foreach ($test_classes as $test_class_info) {
            if (!isset($test_class_info['fqcn'])) {
                continue;
            }

            $class_name = $test_class_info['fqcn'];
            $file = $test_class_info['file'] ?? '';

            // Partition framework vs application tests by file location.
            $is_framework_test = str_starts_with($file, 'app/RSpade/');
            if ($is_framework_test !== $framework_only) {
                continue;
            }

            // Group = the concern directory a test lives in.
            if ($groups && !self::__file_in_groups($file, $groups)) {
                continue;
            }

            // Specific-class args: keep only classes whose FQCN contains a requested token.
            if ($specific_tests) {
                $named = false;
                foreach ($specific_tests as $wanted) {
                    if (str_contains($class_name, $wanted)) {
                        $named = true;
                        break;
                    }
                }
                if (!$named) {
                    continue;
                }
            }

            $reflection = new ReflectionClass($class_name);
            // @PHP-REFLECT-01-EXCEPTION - Test runner needs ReflectionClass for filtering
            if ($reflection->isAbstract()) {
                continue;
            }

            $short_name = basename(str_replace('\\', '/', $class_name));

            // --filter: a class is selected when the filter matches its (short) class name
            // (class_matches -> every method reports) OR any of its test_* method names (only
            // the matching methods report). A class the filter selects nothing in is skipped.
            $class_matches = $filters && self::__matches_any($short_name, $filters);
            if ($filters && !$class_matches) {
                $any_method_matches = false;
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (strpos($method->getName(), 'test_') !== 0) {
                        continue;
                    }
                    if (self::__matches_any($method->getName(), $filters)) {
                        $any_method_matches = true;
                        break;
                    }
                }
                if (!$any_method_matches) {
                    continue;
                }
            }

            $selected[] = [
                'fqcn' => $class_name,
                'file' => $file,
                'short' => $short_name,
                'class_matches' => (bool) $class_matches,
            ];
        }

        return $selected;
    }

    /**
     * The gate for the docker parallel runner. TRUE only when every condition holds; any
     * "no" falls the whole invocation back to the single-process path, unchanged.
     *
     *   - --framework with NO narrowing selector (the WHOLE framework suite - a subset is
     *     never worth an image build and N container boots, and the containers only carry
     *     the framework's own environment),
     *   - not --sequential,
     *   - an RSpade DEVELOPMENT container (/.rspade_container_dev) - the only place the
     *     nested docker daemon and the shipped dev image exist,
     *   - a docker daemon that answers.
     *
     * @param array $specific_tests
     * @param array $filters
     * @param array $groups
     * @param bool $framework_only
     * @return bool
     */
    protected function docker_mode_gate_passes(
        array $specific_tests,
        array $filters,
        array $groups,
        bool $framework_only
    ): bool {
        if (!$framework_only) {
            return false;
        }

        if ($this->option('sequential')) {
            return false;
        }

        if ($specific_tests || $filters || $groups) {
            return false;
        }

        if (!Rsx::is_rspade_dev_container()) {
            return false;
        }

        return $this->docker_is_usable();
    }

    /**
     * Does the docker daemon answer? `docker info` is the whole probe - it fails when the
     * binary is absent, when the daemon is down and when this process cannot reach the
     * socket, which are the three ways docker mode can be unavailable. Quiet: its output is
     * a diagnostic for a run we are choosing not to make.
     *
     * @return bool
     */
    protected function docker_is_usable(): bool
    {
        $output = [];
        $exit_code = 0;
        exec_safe('docker info > /dev/null 2>&1', $output, $exit_code);

        return $exit_code === 0;
    }

    /**
     * How many containers to run: min(WORKER_MAX, cores, floor(RAM_MB / WORKER_MEMORY_MB)),
     * floor 1, never more containers than classes. --workers=N overrides the formula (an
     * experiment knob; the floors of 1 and the class count still apply).
     *
     * @param int $class_count
     * @return int
     */
    protected function worker_count(int $class_count): int
    {
        $override = $this->option('workers');
        if ($override !== null && $override !== '' && (int) $override > 0) {
            return max(1, min((int) $override, max(1, $class_count)));
        }

        $by_memory = (int) floor($this->__memory_mb() / self::WORKER_MEMORY_MB);
        $n = min(self::WORKER_MAX, $this->__cpu_cores(), $by_memory);

        return max(1, min($n, max(1, $class_count)));
    }

    /**
     * CPU cores visible to this box, counted from /proc/cpuinfo (no shell).
     *
     * @return int
     */
    private function __cpu_cores(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        $count = ($cpuinfo === false) ? 0 : (int) preg_match_all('/^processor\s*:/mi', $cpuinfo);

        return max(1, $count);
    }

    /**
     * Total system memory in megabytes, from /proc/meminfo's MemTotal (kB).
     *
     * @return int
     */
    private function __memory_mb(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false || !preg_match('/^MemTotal:\s*(\d+)\s*kB/mi', $meminfo, $m)) {
            return 0;
        }

        return (int) ((int) $m[1] / 1024);
    }

    /**
     * Run the selected classes across N docker containers driven by the node orchestrator.
     *
     * PHP's half of the contract is three things: the run directory (classes.json + an ipc/
     * directory the containers bind-mount), the spawn, and the merge of the results.jsonl
     * node leaves behind. Everything about docker itself - the image build, the zombie
     * sweep, the queue server, the container lifecycle, the pruning - belongs to node.
     *
     * @param array $selected select_test_classes() output
     * @param array $filters --filter set (applied per-method when merging, exactly as the
     *                        sequential loop does at print time)
     * @return int Exit code (0 all passed, 1 any failure or an infrastructure failure)
     */
    protected function run_docker(array $selected, array $filters): int
    {
        $run_id = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $run_dir = storage_path('rsx-tmp/test-run-' . $run_id);
        ensure_directory($run_dir);

        // The containers bind-mount this directory at /rsx-test-ipc and reach the queue
        // through the socket node binds inside it.
        ensure_directory($run_dir . '/ipc');

        $worker_count = $this->worker_count(count($selected));

        // Longest-first, so the slowest classes are handed out before the fast ones and no
        // container is left holding a straggler at the end. requires_db_reset rides along:
        // the worker acts on it, and the queue never has to load a PHP class to answer.
        $ordered = $this->order_classes_longest_first($selected);
        $rows = [];
        foreach ($ordered as $sel) {
            $fqcn = $sel['fqcn'];
            $rows[] = [
                'fqcn' => $fqcn,
                'short' => $sel['short'],
                'requires_db_reset' => (bool) $fqcn::requires_db_reset(),
                'class_matches' => (bool) $sel['class_matches'],
            ];
        }
        file_put_contents($run_dir . '/classes.json', json_encode($rows));

        $this->line('Parallel run: ' . $worker_count . ' containers (docker) - ' . $run_dir);
        $this->newLine();

        // command_without_inherited_locks: node outlives nothing of ours, but it spawns
        // long-lived docker clients, and an inherited flock descriptor lives on the open
        // file description - it would keep this box's locks held for the whole run.
        $command = RsxLocks::command_without_inherited_locks([
            'node',
            base_path(self::ORCHESTRATOR_SCRIPT),
            '--run-dir=' . $run_dir,
            '--workers=' . $worker_count,
            '--image=' . self::TEST_IMAGE,
            '--dev-image=' . self::DEV_IMAGE,
            // The docker BUILD CONTEXT is the project root, one level above base_path().
            '--project-root=' . dirname(base_path()),
        ]);

        $process = new Process($command);
        // NO TIMEOUT (framework mandate): the run takes as long as the suite takes.
        $process->setTimeout(null);
        $process->setWorkingDirectory(base_path());

        // Streamed, not captured: the operator watches the build and the containers live.
        // The tail is kept only to repeat the last lines if node fails.
        $tail = [];
        $process->run(function ($type, $buffer) use (&$tail) {
            $this->output->write($buffer);

            foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                $tail[] = $line;
            }
            if (count($tail) > self::ORCHESTRATOR_TAIL_LINES) {
                $tail = array_slice($tail, -self::ORCHESTRATOR_TAIL_LINES);
            }
        });

        if ($process->getExitCode() !== 0) {
            // Node's non-zero exit means the INFRASTRUCTURE failed (image build, zombie
            // sweep, a dead container) - test failures are decided by the merge below, which
            // is why an infrastructure failure never reaches it.
            $this->newLine();
            $this->error('[ERROR] parallel test infrastructure failed (see ' . $run_dir . '/worker-*.log)');
            foreach ($tail as $line) {
                $this->line($line, 'fg=red');
            }

            return 1;
        }

        return $this->merge_and_report($run_dir . '/results.jsonl', $selected, $filters);
    }

    /**
     * Guarantee the cached baseline dump exists for the current migration hash. When it does
     * not, provision the test database once - sync_test_schema() migrates, seeds the baseline
     * user, and dumps the cache.
     *
     * Called by --_provision-only during the test IMAGE BUILD: the image bakes both the
     * migrated database and this dump, so a container resetting its database between classes
     * restores from the dump instead of re-migrating.
     *
     * @return bool
     */
    protected function ensure_baseline_cache(): bool
    {
        $test_db = (string) config('database.connections.test.database');
        $hash = $this->compute_migration_hash();
        $cache_file = $this->test_cache_dir() . "/{$hash}.sql";

        if (is_file($cache_file)) {
            return true;
        }

        $this->line('Building the baseline cache dump...');

        return $this->sync_test_schema($test_db);
    }

    /**
     * Order the selected classes longest-first for the work queue, so the slowest classes are
     * dispatched before the fast ones and no worker is left holding a straggler at the end.
     *
     * Ordering hint precedence: a persisted per-class measured duration (test-timings.json,
     * self-improving after each parallel run); else the $requires_db_reset proxy (those carry
     * the ~25s re-provision cost); else a small proxy from source-file size. This is a cache,
     * never a deadline - a wrong guess only costs a slightly worse pack, never a failure.
     *
     * @param array $selected
     * @return array
     */
    protected function order_classes_longest_first(array $selected): array
    {
        $timings = $this->read_timings();

        $scored = [];
        foreach ($selected as $i => $sel) {
            $fqcn = $sel['fqcn'];

            if (isset($timings[$fqcn])) {
                $score = (float) $timings[$fqcn];
            } elseif ($fqcn::requires_db_reset()) {
                $score = self::RESET_CLASS_ORDER_PROXY_SECONDS;
            } else {
                $abs = ($sel['file'] !== '') ? rsx_project_file_path($sel['file']) : '';
                $bytes = ($abs !== '' && is_file($abs)) ? (int) filesize($abs) : 0;
                // Source size / 100k keeps a large non-reset class ahead of a tiny one while
                // staying well below the reset proxy.
                $score = $bytes / 100000.0;
            }

            $scored[] = ['sel' => $sel, 'score' => $score, 'i' => $i];
        }

        usort($scored, static function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['i'] <=> $b['i'];
            }

            return $b['score'] <=> $a['score'];
        });

        return array_map(static fn ($x) => $x['sel'], $scored);
    }

    /**
     * Merge the orchestrator's results.jsonl into the unified totals and per-class output,
     * in the EXACT format the sequential path prints (both go through print_class_results()
     * and print_summary(), so there is one printer), then persist the measured durations as
     * next run's ordering hint.
     *
     * A SELECTED CLASS WITH NO RECORD IS A FAILURE, never a silent drop: it means the
     * container holding it died before it finished. A record carrying an 'error' is a throw
     * from $class::run() itself, reported exactly as the sequential loop's catch reports it.
     *
     * @param string $results_path <run_dir>/results.jsonl, one JSON record per class
     * @param array $selected select_test_classes() output - the classes that were dispatched
     * @param array $filters
     * @return int
     */
    protected function merge_and_report(string $results_path, array $selected, array $filters): int
    {
        // fqcn => {short, results, duration, error?}
        $by_class = [];
        if (is_file($results_path)) {
            foreach (file($results_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $rec = json_decode($line, true);
                if (!is_array($rec) || !isset($rec['class'])) {
                    continue;
                }
                $by_class[$rec['class']] = $rec;
            }
        }

        $totals = self::__empty_totals();
        $timings = [];

        foreach ($selected as $sel) {
            $fqcn = $sel['fqcn'];
            $short_name = $sel['short'];
            $class_matches = $sel['class_matches'];

            $this->info("Running: {$short_name}");

            if (!isset($by_class[$fqcn])) {
                $totals['failed']++;
                $this->error("  [FAIL] {$short_name}");
                $this->line('    class produced no result (worker terminated before it finished)', 'fg=red');
                $this->newLine();
                continue;
            }

            $rec = $by_class[$fqcn];
            if (isset($rec['duration'])) {
                $timings[$fqcn] = (float) $rec['duration'];
            }

            if (!empty($rec['error'])) {
                $totals['failed']++;
                $this->error('  Error running test class: ' . $rec['error']);
                $this->newLine();
                continue;
            }

            $this->print_class_results((array) ($rec['results'] ?? []), (bool) $class_matches, $filters, $totals);

            $this->newLine();
        }

        // Self-improving queue ordering: record what each class actually took this run.
        $this->write_timings($timings);

        // Drop what the run cached under the test suffix (mirrors the sequential tail).
        \App\RSpade\Core\Cache\RsxCache::clear();

        return $this->print_summary($totals);
    }

    /**
     * Path of the persisted per-class timing hints ({fqcn: seconds}). Under rsx-tmp - a cache,
     * never a correctness input.
     *
     * @return string
     */
    protected function timings_path(): string
    {
        $dir = storage_path('rsx-tmp');
        ensure_directory($dir);

        return $dir . '/test-timings.json';
    }

    /**
     * Read the persisted timing hints, or an empty map when absent/unreadable.
     *
     * @return array<string, float>
     */
    protected function read_timings(): array
    {
        $path = $this->timings_path();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Merge freshly measured durations over the persisted hints so ordering self-improves.
     * Best-effort - a failed write only costs a slightly worse pack next time.
     *
     * @param array<string, float> $new
     * @return void
     */
    protected function write_timings(array $new): void
    {
        if (empty($new)) {
            return;
        }

        $merged = array_merge($this->read_timings(), $new);
        @file_put_contents($this->timings_path(), json_encode($merged));
    }

    // =========================================================================
    // WORKER SUB-MODE
    // Same command, detected by --_worker-socket, running inside a test container.
    // The container IS the isolation - its own mysqld, redis, rsx-lockd, filesystem
    // and flag files - so a worker provisions nothing special: it prepares the
    // container's own plain test database and then pulls whole classes from the
    // orchestrator's queue until it is drained.
    // =========================================================================

    /**
     * Worker entry point. Prepares this container's test database (the image already carries
     * the migrated schema, so this takes the no-subprocess path), then consumes the queue
     * until the orchestrator says it is drained.
     *
     * @return int
     */
    protected function worker_run(): int
    {
        $worker_id = (int) Rsx_Internal_Flags::get('--_worker-id');
        $socket_path = (string) Rsx_Internal_Flags::get('--_worker-socket');

        if ($socket_path === '') {
            shouldnt_happen('Test worker spawned without --_worker-socket; the queue socket is how a worker gets work.');
        }

        if (!$this->prepare_test_database(false)) {
            return 1;
        }

        // Rebuild manifest to ensure we have latest test classes (mirrors the sequential path).
        $this->line('Building manifest...');
        Manifest::init();

        $this->worker_consume_queue($worker_id, $socket_path);

        return 0;
    }

    /**
     * Pull whole classes from the orchestrator's queue and run each, until it answers that
     * the queue is drained. The $requires_db_reset / db_dirty semantics are IDENTICAL to the
     * sequential loop, applied to this container's own database.
     *
     * @param int $worker_id
     * @param string $socket_path
     * @return void
     */
    protected function worker_consume_queue(int $worker_id, string $socket_path): void
    {
        $db_dirty = false;

        while (true) {
            $next = $this->queue_request($socket_path, 'queue.next', ['worker_id' => $worker_id]);

            $class_name = $next['class'] ?? null;
            if (!is_string($class_name) || $class_name === '') {
                return; // queue drained
            }

            $short_name = (string) ($next['short'] ?? basename(str_replace('\\', '/', $class_name)));
            $needs_reset = (bool) ($next['requires_db_reset'] ?? $class_name::requires_db_reset());

            // Blank-slate reset whenever the database is dirty - the same rule the sequential
            // loop applies, and for the same two reasons: it gives a reset class its blank
            // slate, and it protects the next transaction class from a prior one's leftovers.
            if ($db_dirty) {
                if (!$this->reset_test_db()) {
                    $this->send_class_result(
                        $socket_path,
                        $worker_id,
                        $class_name,
                        $short_name,
                        [],
                        0.0,
                        'failed to reset test database before ' . $short_name
                    );

                    // The class is accounted for; the reset failure is reported as its error.
                    continue;
                }
                $db_dirty = false;
            }

            $started = microtime(true);

            try {
                $results = $class_name::run();
                if ($needs_reset) {
                    $db_dirty = true;
                }
                $this->send_class_result(
                    $socket_path,
                    $worker_id,
                    $class_name,
                    $short_name,
                    $results,
                    microtime(true) - $started,
                    null
                );
            } catch (\Throwable $e) {
                // Mirrors the sequential loop's catch: a throw from ::run() is one class error.
                if ($needs_reset) {
                    $db_dirty = true;
                }
                $this->send_class_result(
                    $socket_path,
                    $worker_id,
                    $class_name,
                    $short_name,
                    [],
                    microtime(true) - $started,
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Send one class's outcome back to the orchestrator. The record is exactly what the
     * orchestrator writes into results.jsonl and what merge_and_report() reads.
     *
     * @param string $socket_path
     * @param int $worker_id
     * @param string $class_name
     * @param string $short_name
     * @param array $results Rsx_Test_Abstract::run() output (test_name => result)
     * @param float $duration Seconds the class took (ordering hint for next time)
     * @param string|null $error Set when $class::run() itself threw, or the reset failed
     * @return void
     */
    protected function send_class_result(
        string $socket_path,
        int $worker_id,
        string $class_name,
        string $short_name,
        array $results,
        float $duration,
        ?string $error
    ): void {
        $payload = [
            'worker_id' => $worker_id,
            'class' => $class_name,
            'short' => $short_name,
            'results' => $results,
            'duration' => $duration,
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        $this->queue_request($socket_path, 'queue.result', $payload);
    }

    /**
     * ONE request to the orchestrator's queue over its unix socket: connect, write one line
     * of JSON, read ONE line back, close. The house RPC shape, copied from
     * Rsx_Node_Service::request() - one request per connection, no read timeout.
     *
     * EVERY FAILURE HERE IS FATAL. A worker that cannot reach the queue, gets no answer, or
     * gets something it cannot parse has no way to report what it did and no way to know what
     * to do next; it throws, the container exits non-zero, and the orchestrator reports every
     * class that worker was holding as terminated. Silently retrying would hide a broken
     * orchestrator behind a run that looks slow.
     *
     * @param string $socket_path
     * @param string $method
     * @param array $payload
     * @return array Decoded response
     */
    protected function queue_request(string $socket_path, string $method, array $payload = []): array
    {
        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, null);
        if (!$socket) {
            throw new \RuntimeException(
                'Test worker could not connect to the queue socket ' . $socket_path . ': ' . $errstr
            );
        }

        stream_set_blocking($socket, true);

        $this->queue_request_id++;
        $request = json_encode(array_merge($payload, [
            'id' => $this->queue_request_id,
            'method' => $method,
        ])) . "\n";

        fwrite($socket, $request);

        $response = fgets($socket);
        fclose($socket);

        if ($response === false || $response === '') {
            throw new \RuntimeException(
                'Test worker got no response to ' . $method . ' on ' . $socket_path
            );
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'Test worker got an invalid response to ' . $method . ' on ' . $socket_path
                . ': ' . substr((string) $response, 0, 500)
            );
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException(
                'Test queue refused ' . $method . ': ' . (string) $decoded['error']
            );
        }

        return $decoded;
    }
}
