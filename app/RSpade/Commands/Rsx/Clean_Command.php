<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Exception;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Clean_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:clean {--silent : Suppress all output except errors} {--force : Accepted for compatibility; the system/ reset is unconditional and needs no override}';

    /**
     * Framework-internal flag (the `--_` convention - argv-stripped pre-boot, declared as no
     * InputOption, so it appears in no help output): clear caches only, skipping the
     * downstream system/ reset and its integrity check. EVERY caller is programmatic - the
     * updater and the build tooling that shells to rsx:clean want cache invalidation, never
     * git-state changes. A human (or the pre-commit hook) runs plain rsx:clean and gets the
     * reset, which is the point of the reset.
     */
    public const FLAG_NO_SYSTEM_RESET = '--_no-system-reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean RSX caches, build artifacts, and temp files';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Prevent being called via $this->call() - must use passthru for fresh process
        $this->prevent_call_from_another_command();

        // Refuse to wipe a sealed prod build. rsx-build is an immutable deployment
        // artifact while sealed - clearing it would leave the app unable to serve.
        if (\App\RSpade\Core\Prod\Rsx_Prod_Seal::is_sealed()) {
            $this->error('System is in prod mode (sealed build).');
            $this->line('  Run rsx:prod:refresh to rebuild the assets, or rsx:prod:disable first.');

            return 1;
        }

        $silent = $this->option('silent');

        // Restore the .env symlink invariant before anything else. A drifted
        // system/.env (materialized into a real file by a deploy/clone) would make
        // config edits inert; heal it here on the normal (unsealed) clean path.
        $env_report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if (!$silent && $env_report['status'] !== 'already_healthy') {
            $this->line('  .env symlink invariant restored (status: ' . $env_report['status'] . ').');
            foreach ($env_report['overridden_keys'] as $row) {
                $this->line('    ! ' . $row['key'] . ': kept root value, discarded system value.');
            }
            if (!empty($env_report['backup_path'])) {
                $this->line('    Backup: ' . $env_report['backup_path'] . ' (0600)');
            }
            $this->newLine();
        }

        // Show warning if not in framework developer mode
        $is_framework_dev = config('rsx.code_quality.is_framework_developer', false);
        if (!$is_framework_dev && !$silent) {
            $this->warn('[WARNING]  Running rsx:clean is rarely necessary');
            $this->line('');
            $this->line('   The manifest system automatically detects and rebuilds when files change.');
            $this->line('   Manual cache clearing only adds 30-60 seconds to the next request.');
            $this->line('');
            $this->line('   Only run this command if:');
            $this->line('   - Catastrophic errors require a fresh start');
            $this->line('   - Framework code itself was updated outside normal workflows');
            $this->line('   - Explicitly instructed by framework documentation');
            $this->line('');
            $this->line('   Consider removing rsx:clean from your development workflow.');
            $this->newLine();
        }

        if (!$silent) {
            $this->info('Cleaning RSX caches...');
            $this->newLine();
        }

        $cleaned_items = [];

        // 1. Clear rsx-build directory recursively - EVERYTHING
        $build_path = storage_path('rsx-build');
        if (is_dir($build_path)) {
            $this->clear_directory($build_path);
            $cleaned_items[] = '[OK] Build storage cleaned';
        }

        // 2. Clear rsx-tmp directory recursively - EVERYTHING
        //
        //    REAP BEFORE THE WIPE. Every node RPC helper lives on a unix socket in this
        //    directory, and each one took that path as an argv argument at spawn time and
        //    never reconsiders it. Unlinking the sockets under a running daemon strands it
        //    permanently: it keeps serving an inode nobody can reach again, and no socket
        //    message - not even a "force stop" - can ever reach it. That is what produced
        //    the ten-deep orphan pile this command was quietly creating, and it violated
        //    the framework's own rule in bin/CLAUDE.md (any operation that changes a socket
        //    or state directory must reap the helpers bound to the previous one).
        //
        //    Killing them costs nothing: they hold no state and the next process that needs
        //    one spawns it on demand. Doing it BEFORE the unlink also lets each daemon's own
        //    SIGTERM handler remove its socket file on the way out.
        $quiesced = \App\RSpade\Core\JsParsers\Rpc_Client_Abstract::quiesce_all();
        if ($quiesced > 0) {
            $cleaned_items[] = '[OK] RPC helper daemons quiesced (' . $quiesced . ')';
        }

        $tmp_path = storage_path('rsx-tmp');
        if (is_dir($tmp_path)) {
            $this->clear_directory($tmp_path);
            $cleaned_items[] = '[OK] Temp storage cleaned';
        }

        // 3. Sweep orphaned cross-filesystem staging directories (.tmp_<n>)
        //    left behind by file_put_contents_safe() if a process died mid-write.
        $roots = array_unique(array_filter([
            base_path(),
            realpath(base_path('rsx')) ?: null,
        ]));
        $orphan_count = $this->remove_orphan_temp_dirs($roots);
        if ($orphan_count > 0) {
            $cleaned_items[] = "[OK] Removed {$orphan_count} orphaned temp director" . ($orphan_count === 1 ? 'y' : 'ies');
        }

        // 4. Clear Redis cache directly without loading framework
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $redis->flushdb();
            $cleaned_items[] = '[OK] Redis cache cleared';
        } catch (Exception $e) {
            $cleaned_items[] = '[WARNING] Redis cache skipped (not configured or unreachable)';
        }

        // Note: We never clear rsx-locks directory as it contains active lock files

        // 5. Downstream apps only: reset the framework submodule to its own HEAD.
        //    system/ is a git submodule - a checkout of the framework's repository. ALL of
        //    it is framework property, so "clean" for an app developer means discarding
        //    every local change there without asking: there is no such thing as work worth
        //    keeping inside somebody else's checkout.
        //    The framework monorepo is EXEMPT: there system/ IS the work being done.
        //    --_no-system-reset skips this step entirely (caches only): build tooling that
        //    shells to rsx:clean (rsx:manifest:build, rsx:bundle:compile, the updater, ...)
        //    wants cache invalidation, never git-state changes - the reset is for a HUMAN
        //    (or agent) running rsx:clean directly.
        if (!$is_framework_dev && !\App\RSpade\Core\Console\Rsx_Internal_Flags::has(self::FLAG_NO_SYSTEM_RESET)) {
            $note = $this->reset_system_tree();
            if ($note !== null) {
                $cleaned_items[] = $note;
            }
        }

        // Display results
        if (!$silent) {
            if (empty($cleaned_items)) {
                $this->info('Nothing to clean - all caches already empty');
            } else {
                foreach ($cleaned_items as $item) {
                    $this->line("  $item");
                }
                $this->newLine();
                $this->info('[OK] RSX caches cleaned successfully');
            }
        }


        return 0;
    }

    /**
     * Reset the framework submodule to its own HEAD, discarding everything local.
     *
     * system/ is a git submodule - a checkout of the framework's repository, replaced
     * wholesale by every update. ALL of it is framework property, so this is a plain
     * `git reset --hard` + `git clean -fdx` INSIDE the submodule. There is no integrity
     * gate and no --force: a modification under system/ is not work to protect, it is a
     * checkout that has drifted from the commit it claims to be, and the supported way to
     * customize a framework class is a class override in rsx/.
     *
     * WHY THIS IS NOT `git checkout -- system` FROM THE PARENT. That treats system/ as
     * ordinary tracked files, which is what it was under the vendored model. Against a
     * submodule it would restore the GITLINK (the recorded revision) and say nothing about
     * the submodule's own working tree, leaving every dirty file exactly where it was. The
     * two repositories have to be addressed separately.
     *
     * -x on the clean is deliberate: ignored files under system/ are framework build
     * residue, not developer content. The one thing that survives is what lives OUTSIDE
     * the submodule - storage/ is one level up, reached through the system/storage symlink
     * that the reset restores rather than removes.
     *
     * Git environment variables are stripped from the subprocesses: rsx:clean may be
     * invoked from inside a git hook, where git exports GIT_DIR / GIT_INDEX_FILE and would
     * otherwise re-target these calls at the in-progress commit's index.
     *
     * @return string|null Line for the cleaned-items report, or null when there is
     *                     nothing to say.
     */
    protected function reset_system_tree()
    {
        $skip_reason = $this->framework_update_in_progress();
        if ($skip_reason !== null) {
            return '[WARNING] system/ reset skipped (' . $skip_reason . ')';
        }

        $system_dir = base_path();

        // Not a submodule: the monorepo never reaches here (the caller gates on
        // is_framework_developer), so this is a project that has not been converted yet.
        // Nothing to reset in the submodule sense, and reaching into a tree whose shape
        // we do not recognise is not an improvement.
        if (!file_exists($system_dir . '/.git')) {
            return '[WARNING] system/ reset skipped (system/ is not a git submodule)';
        }

        // env -u strips the inherited git context (see docblock); -C targets the SUBMODULE.
        $git = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C ' . escapeshellarg($system_dir);

        $output = [];
        $rc = 0;

        exec_safe($git . ' reset --hard -q HEAD', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset skipped (git reset --hard failed: ' . trim(implode(' ', $output)) . ')';
        }

        exec_safe($git . ' clean -qfdx', $output, $rc);
        if ($rc !== 0) {
            return '[WARNING] system/ reset incomplete (git clean failed: ' . trim(implode(' ', $output)) . ')';
        }

        return '[OK] system/ reset to its checked-out revision';
    }

    /**
     * Detect a framework update in flight, in which case system/ must NOT be reset.
     *
     * rsx:framework:pull runs `rsx:clean` as part of its rebuild, AFTER it has synced the
     * new framework tree but BEFORE it commits it. Resetting system/ at that moment would
     * silently revert the whole update to the previous release. The updater cannot be
     * relied on to announce itself (an older downstream updater ships no such signal), so
     * detection is ambient and layered:
     *
     *   1. RSPADE_FRAMEWORK_COMMIT / RSPADE_FRAMEWORK_UPDATE in the environment.
     *   2. The framework-update maintenance flag is raised.
     *   3. An ancestor process is the updater script itself (Linux /proc walk).
     *
     * @return string|null Reason string when an update is in flight, null otherwise.
     */
    protected function framework_update_in_progress()
    {
        if (getenv('RSPADE_FRAMEWORK_COMMIT') === '1' || getenv('RSPADE_FRAMEWORK_UPDATE') === '1') {
            return 'framework update in progress';
        }

        if (\App\RSpade\Core\Framework\Framework_Maintenance::is_active()) {
            return 'framework update maintenance window is up';
        }

        if ($this->has_updater_ancestor()) {
            return 'running inside rsx:framework:pull';
        }

        return null;
    }

    /**
     * Walk the process ancestry looking for the framework updater script.
     *
     * Linux-only (/proc); returns false where /proc is unavailable, in which case the
     * other signals in framework_update_in_progress() carry the detection.
     *
     * @return bool
     */
    protected function has_updater_ancestor()
    {
        if (!is_dir('/proc')) {
            return false;
        }

        $pid = getmypid();
        for ($depth = 0; $depth < 12 && $pid > 1; $depth++) {
            $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
            if ($cmdline !== false && str_contains($cmdline, 'framework-pull-upstream')) {
                return true;
            }

            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) {
                return false;
            }

            // Field 2 (comm) is parenthesized and may contain spaces; everything after the
            // LAST ')' is single-space separated, making ppid the second field there.
            $close = strrpos($stat, ')');
            if ($close === false) {
                return false;
            }
            $fields = explode(' ', trim(substr($stat, $close + 1)));
            $pid = isset($fields[1]) ? (int) $fields[1] : 0;
        }

        return false;
    }

    /**
     * Clean a directory recursively
     *
     * @param string $path
     * @return void
     */
    protected function clear_directory($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }


    /**
     * Remove orphaned ".tmp_<digits>" staging directories from the code tree.
     *
     * file_put_contents_safe() creates these alongside a destination only when
     * rsx-tmp is on a different filesystem, and removes them immediately. This
     * sweep cleans up any left behind by a process that died mid-write. Heavy
     * and symlinked directories are pruned to keep the scan bounded.
     *
     * @param array $roots Root directories to scan
     * @return int Number of directories removed
     */
    protected function remove_orphan_temp_dirs(array $roots)
    {
        $prune = ['vendor', 'node_modules', '.git'];
        $removed = 0;

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $filter = new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) use ($prune) {
                    // Never follow symlinks (avoids loops and escaping the tree)
                    if ($current->isLink()) {
                        return false;
                    }

                    // Prune heavy directories we never stage into
                    if ($current->isDir() && in_array($current->getFilename(), $prune, true)) {
                        return false;
                    }

                    return true;
                }
            );

            $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

            $to_remove = [];
            foreach ($iterator as $item) {
                if ($item->isDir() && preg_match('/^\.tmp_\d+$/', $item->getFilename())) {
                    $to_remove[] = $item->getPathname();
                }
            }

            // Remove after iteration so we don't mutate the tree we're walking
            foreach ($to_remove as $dir) {
                if (rmdir_recursive($dir)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Count files in a directory recursively
     *
     * @param string $path
     * @return int
     */
    protected function count_files_in_directory($path)
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Prevent this command from being called via $this->call() from another command
     *
     * This command MUST run in a fresh process to ensure in-memory caches are cleared.
     * Use Rsx_Artisan::passthru() instead of $this->call() when calling from other commands.
     *
     * @return void
     */
    protected function prevent_call_from_another_command()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            // Check if we're being called from Artisan::call() or Command::call()
            if (isset($frame['class']) && isset($frame['function'])) {
                $class = $frame['class'];
                $function = $frame['function'];

                // Detect $this->call() from another command
                if ($function === 'call' && str_contains($class, 'Command')) {
                    $this->error('');
                    $this->error('[ERROR] FATAL ERROR: rsx:clean cannot be called via $this->call()');
                    $this->error('');
                    $this->error('This command MUST run in a fresh process to properly clear in-memory caches.');
                    $this->error('');
                    $this->error('FIX: Use Rsx_Artisan::passthru() instead of $this->call():');
                    $this->error('');
                    $this->line('  // [ERROR] WRONG - runs in same process, caches remain in memory');
                    $this->line('  $this->call(\'rsx:clean\');');
                    $this->error('');
                    $this->line('  // [OK] CORRECT - fresh process, all caches cleared');
                    $this->line('  Rsx_Artisan::passthru(\'rsx:clean\');');
                    $this->error('');

                    exit(1);
                }
            }
        }
    }
}
