<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * THE PRE-BOOT SEQUENCE, AS ONE CALLABLE UNIT.
 *
 * Everything that has to happen before Composer's autoloader is required and Laravel
 * boots: the path resolver and its writability assertions, the .env and build link
 * guards, the submodule sync guard, the environment heal, the container gate, the
 * `--_` internal-flag strip, the boot-free command interceptions, and the maintenance
 * gate that defines RSPADE_MAINT_MODE.
 *
 * TWO ENTRY POINTS CALL IT, and there is exactly one implementation:
 *
 *   system/artisan      rsx_preboot()                     - the command line
 *   system/script.php   rsx_preboot(['script' => true])   - an external PHP script
 *
 * A script's argv belongs to the script, so script mode reads no command name out of
 * it: nothing is intercepted, nothing is declared from it, and the maintenance gate
 * refuses the whole process rather than classifying a command it does not have.
 *
 * Runs with NO autoloader, NO framework and NO configuration: plain filesystem calls
 * and __DIR__-relative requires only, so a half-synced vendor/ still refuses cleanly.
 * Callable from any working directory - it chdir()s to the framework tree first, as
 * every RSpade CLI process expects.
 */

if (!function_exists('rsx_preboot')) {

/**
 * Run the pre-boot sequence.
 *
 * @param array $options
 *   'script' => bool  This process is an external script, not artisan (default false).
 *   'force'  => bool  Script mode only: run even while maintenance mode is up
 *                     (default false).
 */
function rsx_preboot(array $options = []): void
{
    $script_mode = !empty($options['script']);
    $force = !empty($options['force']);

    // Change to the framework directory so every operation below - and everything
    // Laravel does afterwards - resolves relative paths the same way, whatever
    // directory the process was started from.
    chdir(__DIR__ . '/..');

    /*
    |--------------------------------------------------------------------------
    | Path Resolver (pre-boot)
    |--------------------------------------------------------------------------
    |
    | The three volatile roots - build/, tmp/, storage/ - and the state directory
    | inside storage. Resolved FIRST, before any guard, because every guard below
    | addresses one of them, and exported into the environment so every spawned
    | child resolves the same absolutes instead of re-deriving them. The export also
    | sets TMPDIR, which PHP answers sys_get_temp_dir() with and caches for the life
    | of the process, so nothing may run before it.
    |
    | Then the writability check: storage/ and tmp/ in every mode, plus build/,
    | system/ and the project root wherever something is expected to write them.
    |
    */

    require_once __DIR__ . '/rsx_paths.php';
    rsx_paths_export();
    rsx_paths_assert_trees_writable();

    /*
    |--------------------------------------------------------------------------
    | Environment Preparation
    |--------------------------------------------------------------------------
    |
    | system/.env is FRAMEWORK SPACE: always a symlink to the project-root .env,
    | which is where a developer's configuration actually lives. This guard repairs
    | that link before anything reads configuration.
    |
    | Creating the root file itself, from the tracked project-root template, is the
    | env HEAL a few blocks below - once the storage tree and the framework checkout
    | have been verified.
    |
    */

    require __DIR__ . '/rsx_env_link.php';

    /*
    |--------------------------------------------------------------------------
    | Build Link Guard (pre-boot)
    |--------------------------------------------------------------------------
    |
    | system/build is a SYMLINK to ../build, the project-root tree holding the build
    | outputs. system/ is a submodule, replaced wholesale on every update, and nothing
    | volatile can live inside it.
    |
    | The link is repaired silently; the TARGET is never created, because a production
    | box with no build must fail loud naming the build command. tmp/ and storage/ have
    | no link - they are relocatable, and code reaches them through the path owner.
    |
    | Placed before the submodule guard, which writes its own state into the state tree.
    |
    */

    require __DIR__ . '/rsx_storage_link.php';

    /*
    |--------------------------------------------------------------------------
    | Boot-free intercepted commands
    |--------------------------------------------------------------------------
    |
    | The commands that exist to REPAIR a broken tree. They are intercepted below,
    | none of them boots Laravel, and every pre-boot guard from here down exempts
    | them - because a guard standing between the operator and the lever that fixes
    | the thing it is complaining about is not a guard, it is a deadlock.
    |
    | Computed here, before the first guard, so both can consult it.
    |
    | Never true in script mode: the first argument of an external script is the
    | script's own, and reading it as a command name is exactly the confusion this
    | mode exists to end.
    |
    */

    $boot_free = !$script_mode && isset($_SERVER['argv'][1]) && in_array($_SERVER['argv'][1], [
        'rsx:framework:pull',
        'rsx:maintenance:enable',
        'rsx:maintenance:disable',
        'rsx:git',
    ], true);

    /*
    |--------------------------------------------------------------------------
    | Submodule Sync Guard (pre-boot)
    |--------------------------------------------------------------------------
    |
    | system/ is a git submodule. A plain `git pull` moves the RECORDED revision
    | without checking the submodule out, so the framework about to run may not be
    | the one this project says it uses - silently. This refuses before any of that
    | framework code is loaded, which is the only moment the refusal is meaningful.
    |
    | Silent when system/ is not a submodule (the monorepo, and any project not yet
    | migrated), and silent when it cannot tell.
    |
    | EXEMPT FOR THE BOOT-FREE COMMANDS, and rsx:git above all. `git` in a project
    | container IS `php artisan rsx:git` (bin/git-shim.sh execs it), so guarding
    | rsx:git here made the mismatch UNFIXABLE: the remedy this guard prints is
    | `git submodule update --init --recursive`, which routed straight back into the
    | guard that printed it. Every artisan command AND every git subcommand refused,
    | read-only ones included, leaving the real git binary or an unwrapped PATH -
    | defeating the guard - as the only way out. A downstream field report, 2026-08-24.
    |
    | A version mismatch is also the NORMAL state immediately after a pull that
    | carries a framework bump. The hazard it guards against is SERVING or RUNNING
    | against the wrong framework, which is why public/index.php stays unconditional
    | and every ordinary artisan command still refuses. Running git is not that
    | hazard - it is how you stop being in this state.
    |
    */

    if (!$boot_free) {
        require __DIR__ . '/rsx_submodule_sync.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Environment Heal (pre-boot)
    |--------------------------------------------------------------------------
    |
    | Creates the project-root environment file from its TRACKED template when it is
    | absent, and in development keeps it in step with the keys the template declares -
    | so a configuration key added upstream reaches this machine by itself. ONE
    | implementation, shared with `php artisan rsx:env:heal` and the container
    | entrypoint: App\RSpade\Core\Prod\Rsx_Env_Symlink::full_heal().
    |
    | Placed after the storage guard (the boot stamp lives under storage/rsx-tmp)
    | and after the submodule guard (a wrong-version framework must not get as far
    | as writing this project's configuration), and BEFORE the container gate, which
    | reads RSX_MODE out of the file this may have just created.
    |
    | The BOOT-FREE intercepted commands are exempt. rsx:framework:pull,
    | rsx:maintenance:enable/disable and rsx:git (all intercepted below) exist
    | precisely to work on a broken tree - the maintenance flag must be lowerable
    | and the pull runnable however incoherent this box's configuration layer is, and
    | none of them boot Laravel. A heal refusal (a missing template, missing
    | credentials) must never stand between the operator and those levers; the very
    | next ordinary command heals or refuses as usual.
    |
    */

    if (!$boot_free) {
        require __DIR__ . '/rsx_env_heal.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Container Gate (pre-boot, development mode only)
    |--------------------------------------------------------------------------
    |
    | In development mode artisan runs inside the RSpade container or not at all.
    | Placed HERE, ahead of every command interception below, because the refusal is
    | about the environment and not about any one command: a gate that let some
    | commands through would be a gate somebody has to memorize the exceptions to.
    |
    | It runs AFTER the environment bootstrap above, since it reads RSX_MODE from the
    | file that block may have just created. Debug and production are never gated -
    | see bootstrap/rsx_container_gate.php for the reasoning in full.
    |
    */

    require __DIR__ . '/rsx_container_gate.php';

    /*
    |--------------------------------------------------------------------------
    | State Root (pre-boot)
    |--------------------------------------------------------------------------
    |
    | storage/state holds the small facts about the environment's own lifetime -
    | the maintenance flag, the flock files, the updater's ledger. Everything below
    | runs BEFORE Laravel boots, so the answer comes from the pre-boot resolver
    | required at the top of this function, which is the same answer
    | App\RSpade\Core\Paths\Rsx_Project_Paths gives a booted process.
    |
    */

    $state = rsx_paths_state_root();

    /*
    |--------------------------------------------------------------------------
    | Framework-Internal Flags (the `--_` convention)
    |--------------------------------------------------------------------------
    |
    | Every `--_`-prefixed argv token is lifted into a process global and STRIPPED
    | from argv before Symfony parses it. Commands never declare these as options,
    | so they render in no help/list output and can never raise an unknown-option
    | error. The booted-world reader is
    | App\RSpade\Core\Console\Rsx_Internal_Flags (pre-boot has no autoloader, hence
    | a plain global here).
    |
    | Runs in SCRIPT MODE TOO: the `--_` prefix is reserved by convention in every
    | RSpade process, so a script neither receives one as an ordinary argument nor
    | has to strip it itself.
    |
    */

    $GLOBALS['__rsx_internal_flags'] = [];
    if (!empty($_SERVER['argv'])) {
        $kept_argv = [];
        foreach ($_SERVER['argv'] as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--_')) {
                $GLOBALS['__rsx_internal_flags'][] = $arg;

                continue;
            }
            $kept_argv[] = $arg;
        }
        if (count($kept_argv) !== count($_SERVER['argv'])) {
            $_SERVER['argv'] = array_values($kept_argv);
            $_SERVER['argc'] = count($_SERVER['argv']);
        }
    }

    /*
     | THIS PROCESS IS THE TEST SUITE.
     |
     | rsx:test declares --_test-run on itself so that everything it and its children do is
     | recognisably part of a test run. It has to be declared HERE, pre-boot, and not in the
     | command's handle(): Manifest::init() runs during boot, and what the manifest indexes
     | DEPENDS on the answer - the test trees (app/RSpade/tests, app/RSpade/temp, rsx/tests)
     | are scanned only under a test run, so a flag set after boot arrives one manifest too
     | late and the runner finds no test classes.
     |
     | Children get the token in argv (Rsx_Artisan forwards it), so they are already covered
     | by the strip above; this is the parent's own declaration.
     | Mirrors App\RSpade\Core\Testing\Rsx_Test_Abstract::TEST_RUN_FLAG.
     */

    if (!$script_mode
        && isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === 'rsx:test'
        && !in_array('--_test-run', $GLOBALS['__rsx_internal_flags'], true)) {
        $GLOBALS['__rsx_internal_flags'][] = '--_test-run';
    }

    // The pull's own sub-calls carry this token so they run while the gate is up.
    // Mirrors App\RSpade\Core\Framework\Framework_Maintenance::OVERRIDE_FLAG.
    $fw_update_override = in_array('--_framework-update-override', $GLOBALS['__rsx_internal_flags'], true);

    if (!$script_mode) {
        rsx_preboot_intercept_commands($state);
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance Gate (allow-most-deny-some)
    |--------------------------------------------------------------------------
    |
    | RSPADE_MAINT_MODE is the per-process SNAPSHOT of the maintenance flag - ONE
    | filesystem stat per process, taken here so nothing downstream (notably the
    | RsxLocks backend selection) can observe the flag flipping mid-process. Every
    | entrypoint defines it unconditionally: system/artisan and system/script.php
    | through this function, and system/public/index.php on its own.
    |
    | For ARTISAN the gate exists to stop AUTOMATED processes from firing into a
    | stopped environment, not to babysit humans, so it is ALLOW-MOST-DENY-SOME:
    |   BLOCKED           rsx:task:process, rsx:task:worker (the automated runners)
    |   BLOCKED w/o --force  rsx:task:run (a human can insist)
    |   ALLOWED           everything else (migrate, rsx:health, rsx:clean, builds...)
    | The internal override bypasses all classification (the pull's own sub-calls);
    | rsx:framework:pull and rsx:maintenance:* are intercepted above and therefore
    | never reach this gate.
    |
    | For a SCRIPT the whole process is refused instead. There is no command name to
    | classify, and an external script is automation by definition - the hazard is a
    | long write run landing in the middle of a migration the window was raised for.
    | The script may insist for itself with $RSX_SCRIPT_OPTIONS['force'].
    |
    | Raw + pre-autoload: a half-synced vendor/ must still refuse cleanly. Path
    | mirrors App\RSpade\Core\Framework\Framework_Maintenance::FLAG_RELATIVE.
    |
    */

    $maint_flag = $state . '/.maintenance.mode.framework.update';
    define('RSPADE_MAINT_MODE', file_exists($maint_flag));

    if (!RSPADE_MAINT_MODE || $fw_update_override) {
        return;
    }

    if ($script_mode && $force) {
        return;
    }

    // Line 1 only: the flag's second line is the "mode=" stamp the web 503 reads, and it
    // must never trail the reason in a CLI refusal. Format owned by
    // App\RSpade\Core\Framework\Framework_Maintenance (not autoloadable here).
    $maint_lines = preg_split('/\R/', (string) @file_get_contents($maint_flag));
    $maint_reason = trim($maint_lines[0] ?? '');
    if ($maint_reason === '') {
        $maint_reason = 'maintenance mode';
    }

    $exit_hint = "Exit maintenance mode: php artisan rsx:maintenance:disable\n";

    if ($script_mode) {
        fwrite(STDERR, "503 - System is in maintenance mode ({$maint_reason}); external scripts are refused.\n");
        fwrite(STDERR, "Set \$RSX_SCRIPT_OPTIONS['force'] = true before the include to run anyway.\n");
        fwrite(STDERR, $exit_hint);
        exit(75);
    }

    $command = $_SERVER['argv'][1] ?? '';

    if ($command === 'rsx:task:process' || $command === 'rsx:task:worker') {
        fwrite(STDERR, "503 - System is in maintenance mode ({$maint_reason}); background task runners are blocked.\n");
        fwrite(STDERR, $exit_hint);
        exit(75);
    }

    if ($command === 'rsx:task:run' && !in_array('--force', $_SERVER['argv'] ?? [], true)) {
        fwrite(STDERR, "503 - System is in maintenance mode ({$maint_reason}). Pass --force to run this task anyway.\n");
        fwrite(STDERR, $exit_hint);
        exit(75);
    }
}

/**
 * The three commands intercepted before Laravel loads. Each shells to its script and
 * EXITS; none of them ever returns.
 *
 * Artisan only - a script has no command name in argv[1].
 *
 * @param string $state The state root, for the manual-recovery hint below.
 */
function rsx_preboot_intercept_commands(string $state): void
{
    $argv = $_SERVER['argv'] ?? [];

    /*
    |--------------------------------------------------------------------------
    | Early Command Interception
    |--------------------------------------------------------------------------
    |
    | Intercept rsx:framework:pull BEFORE Laravel loads. This allows the
    | framework update script to run independently and replace the framework
    | code without Laravel being initialized on potentially outdated code.
    |
    */

    if (isset($argv[1]) && $argv[1] === 'rsx:framework:pull') {
        $script = __DIR__ . '/../bin/framework-pull-upstream.sh';
        $template = $script . '.dist';

        // Self-heal the live pull script from its tracked template on every run.
        //
        // The live .sh is generated/untracked and was historically materialized only
        // once at install. That made it self-perpetuating: a fix shipped in .dist
        // (e.g. the override-artifact cleanup) could never reach a downstream, because
        // the stale .sh kept running its old logic and never refreshed. Materializing
        // here -- not only at install -- guarantees the live script always matches the
        // tracked template. The .sh is generated, so any edits belong in .dist.
        if (file_exists($template)) {
            if (!file_exists($script) || md5_file($script) !== md5_file($template)) {
                copy($template, $script);
                @chmod($script, 0755);
            }
        }

        if (!file_exists($script)) {
            // Releases ship bin/framework-pull-upstream.sh DIRECTLY (publish renamed the
            // dev .dist to .sh; no .dist exists in a release). The .dist self-heal above
            // is a dev-monorepo mechanism only, so do NOT claim a .dist is "expected"
            // here. A missing .sh in a release is almost always the tracked-but-ignored
            // bootstrap failure: a stale system/.gitignore rule excluded the tracked
            // updater from the app-repo commit, so a fresh clone materialized system/
            // without it. Give the operator the real unblock - extract the tracked script
            // from the distribution. (Pre-boot code stays dumb and loud: we print the
            // command, we never auto-fetch over the network here.)
            // Mirrors App\RSpade\Core\Framework\Framework_Maintenance::upstream_cache_dir()
            // (this runs pre-boot, so the class cannot be autoloaded here).
            $cache = '/tmp/rspade_upstream.git';
            echo "ERROR: Framework update script not found: {$script}\n";
            echo "\n";
            echo "This release ships bin/framework-pull-upstream.sh as a TRACKED file, but it is\n";
            echo "missing from this checkout. Almost always this is the tracked-but-ignored\n";
            echo "bootstrap failure: a stale system/.gitignore rule excluded the updater from the\n";
            echo "commit, so a fresh clone materialized system/ without it (rsx:framework:verify\n";
            echo "would flag it MISSING). Restore the tracked script, then re-run this command:\n";
            echo "\n";
            if (is_dir($cache)) {
                echo "  git --git-dir=" . escapeshellarg($cache) . " show master:bin/framework-pull-upstream.sh \\\n";
                echo "    > " . escapeshellarg($script) . " && chmod +x " . escapeshellarg($script) . "\n";
            } else {
                echo "  # No local distribution cache yet - clone it, then extract the script:\n";
                echo "  git clone --bare <rspade_system-distribution-url> " . escapeshellarg($cache) . "\n";
                echo "  git --git-dir=" . escapeshellarg($cache) . " show master:bin/framework-pull-upstream.sh \\\n";
                echo "    > " . escapeshellarg($script) . " && chmod +x " . escapeshellarg($script) . "\n";
            }
            exit(1);
        }

        // Pass all arguments except 'artisan' and 'rsx:framework:pull'
        $args = array_slice($argv, 2);
        $cmd = 'bash ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args));
        passthru($cmd, $exit_code);
        exit($exit_code);
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance Command Interception
    |--------------------------------------------------------------------------
    |
    | rsx:maintenance:enable / rsx:maintenance:disable shell straight to
    | bin/maintenance-mode.sh, exactly as rsx:framework:pull does above: they must
    | work with a broken or half-synced tree, and they can never be refused by the
    | gate they themselves control. Commands/Rsx/Maintenance_{Enable,Disable}_Command
    | are STUBS - registration and help text only, exactly like the pull's stub above
    | and rsx:git's below; they never execute. Full treatment in
    | `php artisan rsx:man maintenance_mode`.
    |
    */

    if (isset($argv[1]) && ($argv[1] === 'rsx:maintenance:enable' || $argv[1] === 'rsx:maintenance:disable')) {
        $script = __DIR__ . '/../bin/maintenance-mode.sh';

        if (!file_exists($script)) {
            echo "ERROR: Maintenance mode script not found: {$script}\n";
            echo "\n";
            echo "It ships in the framework-owned bin/ zone. Restore it from the distribution,\n";
            echo "or remove the flag by hand to exit maintenance mode:\n";
            echo "  rm -f " . escapeshellarg($state . '/.maintenance.mode.framework.update') . "\n";
            exit(1);
        }

        $action = substr($argv[1], strlen('rsx:maintenance:'));
        $args = array_slice($argv, 2);
        $cmd = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($action);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        passthru($cmd, $exit_code);
        exit($exit_code);
    }

    /*
    |--------------------------------------------------------------------------
    | rsx:git Interception
    |--------------------------------------------------------------------------
    |
    | The transparent git proxy shells straight to bin/rsx-git.sh, for the same
    | reasons the two blocks above do: it must be boot-free (invoking it can never
    | be what triggers a manifest rebuild and re-dirties system/), it must work on
    | a broken or half-synced tree, and it must run while the maintenance flag is
    | up -- it is the thing that raises and lowers that flag, and its app-conflict
    | halt deliberately leaves maintenance ON until the operator commits the
    | resolution through it. Placed BEFORE the gate for that last reason.
    |
    | Everything after `rsx:git` is git's, verbatim; only --rsx-* flags belong to
    | the wrapper. Documented in `php artisan rsx:man rsx_git`.
    |
    */

    if (isset($argv[1]) && $argv[1] === 'rsx:git') {
        $script = __DIR__ . '/../bin/rsx-git.sh';

        if (!file_exists($script)) {
            echo "ERROR: git proxy script not found: {$script}\n";
            echo "\n";
            echo "It ships in the framework-owned bin/ zone. Restore it from the distribution,\n";
            echo "or use plain git directly until it is back.\n";
            exit(1);
        }

        $args = array_slice($argv, 2);
        $cmd = 'bash ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        passthru($cmd, $exit_code);
        exit($exit_code);
    }
}

}
