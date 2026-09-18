<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ProdMode\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The shared body of the two prod_mode lifecycle wrappers: run one bash script to
 * completion, and read its verdict.
 *
 * WHAT A WRAPPER IS FOR. The scripts are the real contract - a genuine sealed build on
 * this box, the real read-only posture on the real trees - and they are written in bash
 * because what they assert is the behaviour of commands, permissions and a web server,
 * not of PHP objects. The wrapper exists so that `rsx:test` is the ONE way to run a test:
 * the class is discovered, reported and counted like every other, and the script keeps
 * being runnable by hand.
 *
 * WHY THESE CLASSES ARE $explicit_group_only. They switch the box's RSX_MODE and rebuild
 * the real build tree - the one shared thing every other test depends on - and each takes
 * minutes. They run when the operator names them, and the runner says so when it passes
 * them over.
 *
 * Declared abstract so the runner's discovery skips it; the two concrete classes below it
 * are the tests.
 */
abstract class Prod_Script_Cli_Test_Abstract extends Rsx_Test_Abstract
{
    /**
     * The script commits - to the .env mode line, to the build tree, to the real
     * filesystem - and none of it is a database write to roll back.
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * How many lines of the script's own output the failure message carries.
     */
    const FAILURE_TAIL_LINES = 40;

    /**
     * Run one prod_mode script and assert its verdict.
     *
     * The script cds to the project root itself (it derives it from its own location), so
     * the working directory here only has to be somewhere artisan resolves from.
     *
     * @param string $script_name File name under this directory
     * @param string $log_glob The pattern the script writes its log to, for the failure message
     * @return void
     */
    protected static function __run_script(string $script_name, string $log_glob): void
    {
        $script = __DIR__ . '/' . $script_name;

        static::__assert_true(is_file($script), "the script must exist: {$script}");

        $output = [];
        $rc = 0;

        // RSX_MODE IS UNSET FOR THE CHILD, and this is load-bearing.
        //
        // phpdotenv's putenv adapter puts every key of the environment file into the
        // PROCESS environment at boot, so this test process carries RSX_MODE=development
        // as a snapshot taken before any of this ran. Every reader on both sides -
        // bin/lib/rsx_paths.sh's rsx_env_value, the pre-boot rsx_preboot_mode() - honours a
        // real environment variable ahead of the file, deliberately. Inherited, that
        // snapshot would make the script and every artisan command it spawns believe the
        // box is still in development no matter what rsx:prod:enable wrote, the script's
        // own mode assertions would fail on a build that actually succeeded, and its EXIT
        // trap would decline to restore a mode it could not see had changed.
        //
        // Removing it is not an invocation prefix and sets nothing: it hands the script the
        // environment a shell would, so it reads the mode from the file, live, as the run
        // changes it.
        //
        // No deadline of any kind: three full builds take as long as this box takes.
        exec_safe(
            'unset RSX_MODE; cd ' . escapeshellarg(base_path()) . ' && bash ' . escapeshellarg($script),
            $output,
            $rc
        );

        $text = implode("\n", $output);
        $context = static::__failure_context($text, $log_glob);

        static::__assert_equals(0, $rc, "{$script_name} exited {$rc}" . $context);

        // The verdict line, not a substring anywhere in the output: the script prints
        // every command it runs, and any one of them is free to say "PASS".
        $verdict = '';
        foreach ($output as $line) {
            if (str_starts_with(trim((string) $line), 'PASS:')) {
                $verdict = trim((string) $line);
            }
        }

        static::__assert_true(
            $verdict !== '',
            "{$script_name} printed no PASS: verdict line" . $context
        );

        static::__pass($verdict);
    }

    /**
     * The failure tail: where the script's own log is, and the last lines of what it
     * printed (its fail() already dumps the last commands' output into that stream).
     *
     * The log file name carries the script's pid, which this process does not know, so the
     * newest file matching the pattern is named when one survives - a passing script
     * deletes its log, a failing one leaves it.
     *
     * @param string $text
     * @param string $log_glob
     * @return string
     */
    protected static function __failure_context(string $text, string $log_glob): string
    {
        $logs = glob($log_glob) ?: [];
        usort($logs, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
        $log = $logs === [] ? $log_glob . ' (no log survived)' : end($logs);

        $lines = preg_split('/\R/', $text);
        $tail = array_slice($lines, -self::FAILURE_TAIL_LINES);

        return "\nScript log: {$log}\nLast " . self::FAILURE_TAIL_LINES . " lines:\n" . implode("\n", $tail);
    }
}
