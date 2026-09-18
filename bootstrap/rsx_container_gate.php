<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT CONTAINER GATE (CLI)
 *
 * In DEVELOPMENT mode, artisan runs inside the RSpade container or it does not
 * run at all.
 *
 * WHY A REFUSAL AND NOT A WARNING. Development mode is not a flag that merely
 * relaxes error reporting - it is a promise about the machine underneath. The
 * framework regenerates the manifest and recompiles bundles on demand, migrations
 * are snapshot-protected by stopping a supervised MySQL and copying its data
 * directory, and the task ticker, realtime relay and lock daemon are expected to
 * be running under supervisor with the names the framework drives. The container
 * is where all of that is true. On a developer's host it is true by coincidence at
 * best, and every one of those operations degrades differently: some fail loudly,
 * some half-work, and the snapshot one can leave a database it cannot put back.
 *
 * The alternative - let each command discover its own missing prerequisite - is
 * what this replaces. It produced errors that described a symptom several layers
 * from the cause ("mysql service not found", "permission denied writing storage"),
 * and each one had to be diagnosed on its own. One refusal, at the entrypoint,
 * naming the actual condition, is the whole of the problem.
 *
 * SCOPE. CLI only, and DEVELOPMENT only. A deployed application - debug or
 * production, container or not - migrates, builds and runs its tasks here with no
 * gate at all, because none of the above applies to a sealed build. The web
 * entrypoint is deliberately untouched: a request arriving at a misconfigured
 * host has its own diagnostics, and turning every page into this message would
 * bury them.
 *
 * The marker checked is /.rspade_container ONLY. Whether it is the dev or the
 * prod image does not matter here - the question is "is this the environment the
 * tooling was written for", and both answer yes. (migrate asks the narrower
 * dev-versus-prod question separately, to decide the fate of its snapshot.)
 *
 * Required by the pre-boot sequence (bootstrap/rsx_preboot.php, shared by
 * system/artisan and system/script.php) before anything reads configuration, so it
 * runs with no autoloader, no framework and no config: plain filesystem calls only.
 */

require_once __DIR__ . '/rsx_mode.php';

(static function (): void {
    // The overwhelmingly common path in the environment this framework ships:
    // one stat, and out.
    if (is_file('/.rspade_container')) {
        return;
    }

    // ONE pre-boot mode reader, shared with the rsx:test refusal in
    // bootstrap/rsx_preboot.php: it mirrors Rsx::get_mode() including its
    // absent-means-development default and its environment-beats-file precedence
    // (bootstrap/rsx_mode.php carries the reasoning).
    if (rsx_preboot_mode() !== 'development') {
        return;
    }

    rsx_container_gate_refuse();
})();

/**
 * Print the refusal and exit.
 *
 * Prints the invocation rather than describing it: an error that says only "wrong
 * environment" leaves somebody guessing at a command line, and guessing at a
 * docker invocation is how people end up running the thing on their host anyway.
 */
function rsx_container_gate_refuse(): void
{
    $argv = $_SERVER['argv'] ?? [];

    // Under RSX_SCRIPT_MODE (system/script.php) there is no artisan command line to
    // echo: the invocation is the script's own path and arguments, starting at argv[0].
    $is_script = defined('RSX_SCRIPT_MODE');
    $args = array_slice($argv, $is_script ? 0 : 1);

    // Echo back what they actually typed, so the suggested line is the command
    // they wanted and not a generic example they have to adapt.
    $command = $is_script ? 'php' : 'php artisan';
    foreach ($args as $arg) {
        $command .= ' ' . (preg_match('/[\s"\'$`\\\\]/', (string) $arg)
            ? escapeshellarg((string) $arg)
            : (string) $arg);
    }

    if (!$is_script && $args === []) {
        $command = 'php artisan list';
    }

    $out = STDERR;

    fwrite($out, "\n");
    fwrite($out, "[ERROR] RSpade runs inside its container in development mode.\n");
    fwrite($out, "\n");
    fwrite($out, "  This shell is not the RSpade container (/.rspade_container is absent), and\n");
    fwrite($out, "  RSX_MODE is development. Development mode expects the container's supervisor,\n");
    fwrite($out, "  its service names and its data-directory layout: the manifest and bundles are\n");
    fwrite($out, "  rebuilt on demand, migrations are snapshot-protected by stopping the database\n");
    fwrite($out, "  and copying its data directory, and the task runner, realtime relay and lock\n");
    fwrite($out, "  daemon are expected to be up. None of that is true here, so commands would\n");
    fwrite($out, "  half-work rather than fail cleanly.\n");
    fwrite($out, "\n");
    fwrite($out, "  Run it in the container instead:\n");
    fwrite($out, "\n");
    fwrite($out, "      docker compose exec app " . $command . "\n");
    fwrite($out, "\n");
    fwrite($out, "  Or open a shell there and work as normal:\n");
    fwrite($out, "\n");
    fwrite($out, "      docker compose exec app bash\n");
    fwrite($out, "      " . $command . "\n");
    fwrite($out, "\n");
    fwrite($out, "  Start the container first if it is not running:\n");
    fwrite($out, "\n");
    fwrite($out, "      docker compose up -d\n");
    fwrite($out, "\n");
    fwrite($out, "  See README.md in the project root for the full workflow, and the RSpade\n");
    fwrite($out, "  documentation at https://docs.rspade.org/ for everything else.\n");
    fwrite($out, "\n");
    fwrite($out, "  A DEPLOYED application is not affected: debug and production modes run\n");
    fwrite($out, "  outside a container normally. This refusal applies only to development mode.\n");
    fwrite($out, "\n");

    exit(1);
}
