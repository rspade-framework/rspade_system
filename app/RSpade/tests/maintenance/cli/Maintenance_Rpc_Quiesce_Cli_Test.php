<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The node-daemon quiesce that `rsx:maintenance:enable` performs.
 *
 * WHY THIS TEST IS SHAPED THIS WAY. Running the real `maintenance-mode.sh enable` WITH
 * services on a development box stops php-fpm, redis and the lock daemon - it takes the
 * machine down. The quiesce deliberately lives INSIDE that services branch (see
 * test_the_quiesce_block_sits_in_the_services_half), so `--no-services`, which every other
 * maintenance CLI test uses, does not exercise it at all.
 *
 * So this test does two things instead, and between them they cover the same ground:
 *
 *   1. STRUCTURAL - the block exists and sits where the sequence requires: after the task
 *      kill, before the first supervisor stop, inside the services region.
 *   2. BEHAVIORAL - the block's own lines are EXTRACTED FROM THE SCRIPT and run verbatim
 *      against planted fake daemons. Nothing is retyped here, so the assertion cannot drift
 *      away from what the script actually does: if someone rewrites the reaper, this test
 *      runs the rewrite.
 *
 * The fakes are real node processes whose argv carries a socket path, which is the ONLY
 * handle the reaper uses (these daemons are PID-1 orphans; the socket path in their command
 * line is what identifies them). One exits on SIGTERM, one ignores it - so the test proves
 * both halves of TERM -> settle -> KILL.
 */
class Maintenance_Rpc_Quiesce_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The pgrep the script must be doing, verbatim. */
    const QUIESCE_PGREP = 'pgrep -f -- "--socket=$(storage_base)/rsx-tmp/"';

    /** Scratch root for this run; the fakes are socketed under <root>/rsx-tmp/. */
    protected static $scratch_root = null;

    /** @var Process[] */
    protected static $fakes = [];

    public static function teardown()
    {
        // Belt: nothing this test planted may outlive it, whatever failed.
        foreach (static::$fakes as $fake) {
            $fake->stop(0);
        }
        static::$fakes = [];

        if (static::$scratch_root !== null && is_dir(static::$scratch_root)) {
            $out = [];
            $rc = 0;
            exec_safe('rm -rf ' . escapeshellarg(static::$scratch_root), $out, $rc);
        }

        static::$scratch_root = null;
    }

    protected static function __script_source(): string
    {
        $path = base_path('bin/maintenance-mode.sh');
        static::__assert_true(file_exists($path), 'bin/maintenance-mode.sh must exist');

        return (string) file_get_contents($path);
    }

    /**
     * The quiesce block, lifted out of do_enable() exactly as written.
     *
     * Boundaries: the one `command -v pgrep` guard in the file, through the `fi` that closes
     * it. Deliberately NOT a copy - see the class docblock.
     */
    protected static function __extract_quiesce_block(string $source): string
    {
        $open = "    if command -v pgrep >/dev/null 2>&1; then";
        $close = "\n    fi\n";

        $start = strpos($source, $open);
        static::__assert_true($start !== false, 'the quiesce block must open with a pgrep availability guard');

        $end = strpos($source, $close, $start);
        static::__assert_true($end !== false, 'the quiesce block must be closed');

        $block = substr($source, $start, ($end - $start) + strlen($close));

        static::__assert_contains(
            self::QUIESCE_PGREP,
            $block,
            'the extracted block must be the RPC quiesce, not some other pgrep'
        );

        return $block;
    }

    /**
     * The block runs where the enable sequence requires: inside the services half, after the
     * background tasks are killed, and before anything is handed to supervisorctl.
     *
     * Ordering is the whole point. A daemon spawned by an in-flight FPM request while this
     * runs is caught moments later by the php-fpm stop, so the pair leaves nothing behind.
     */
    public static function test_the_quiesce_block_sits_in_the_services_half()
    {
        $source = static::__script_source();

        $do_enable = strpos($source, 'do_enable() {');
        static::__assert_true($do_enable !== false, 'do_enable() must exist');

        $services_gate = strpos($source, '[ "$SERVICES" = true ] || return 0', $do_enable);
        $kill_all = strpos($source, 'rsx:tasks:kill-all', $do_enable);
        $quiesce = strpos($source, self::QUIESCE_PGREP, $do_enable);
        $first_stop = strpos($source, "stop_unit '^realtime'", $do_enable);

        static::__assert_true($quiesce !== false, 'do_enable() must quiesce the node daemons');
        static::__assert_true($services_gate !== false, 'do_enable() must gate the services half');
        static::__assert_true($kill_all !== false, 'do_enable() must kill running tasks');
        static::__assert_true($first_stop !== false, 'do_enable() must stop the realtime unit');

        static::__assert_true(
            $services_gate < $quiesce,
            'the quiesce belongs INSIDE the services half - --no-services must not touch daemons'
        );
        static::__assert_true(
            $kill_all < $quiesce,
            'the quiesce runs after the task kill'
        );
        static::__assert_true(
            $quiesce < $first_stop,
            'the quiesce runs before the first supervisor stop'
        );
    }

    /** The reaper escalates: TERM every match, one settle pass, KILL whatever survived. */
    public static function test_the_quiesce_block_terms_then_kills()
    {
        $block = static::__extract_quiesce_block(static::__script_source());

        static::__assert_contains('xargs -r kill', $block, 'every match is TERMed first');
        static::__assert_contains('kill -9', $block, 'a survivor is KILLed');
        static::__assert_contains('kill -0', $block, 'the KILL pass only signals what is still alive');
        static::__assert_contains('say "', $block, 'the reap is reported');
    }

    /**
     * THE BEHAVIOR. Two fake daemons on the pattern - one cooperative, one wedged - and the
     * script's own extracted lines take both down.
     *
     * The wedged one is what makes the escalation load-bearing: `server.close()` on SIGTERM
     * drains connections, so a real daemon in a bad state does not exit on TERM either.
     */
    public static function test_the_extracted_block_reaps_a_planted_daemon()
    {
        $probe = [];
        $probe_rc = 0;
        exec_safe('command -v pgrep', $probe, $probe_rc);
        if ($probe_rc !== 0) {
            static::__skip('pgrep is unavailable in this environment');

            return;
        }

        $root = sys_get_temp_dir() . '/rsxtest_rpc_quiesce_' . getmypid();
        static::$scratch_root = $root;

        // The socket directory is never actually bound - the reaper matches on ARGV, which is
        // the point: it is the one handle that still works after a socket file is unlinked.
        ensure_directory($root . '/rsx-tmp');

        $cooperative = $root . '/fake-cooperative.js';
        $wedged = $root . '/fake-wedged.js';

        file_put_contents_safe($cooperative, "setInterval(() => {}, 1000000);\n");
        file_put_contents_safe(
            $wedged,
            "process.on('SIGTERM', () => {});\nsetInterval(() => {}, 1000000);\n"
        );

        foreach ([$cooperative, $wedged] as $script) {
            $fake = new Process(['node', $script, '--socket=' . $root . '/rsx-tmp/fake.sock']);
            $fake->setTimeout(null);
            $fake->start();
            static::$fakes[] = $fake;
        }

        // Give both a moment to be visible to pgrep before asserting on them.
        usleep(500000);

        foreach (static::$fakes as $index => $fake) {
            static::__assert_true($fake->isRunning(), "fake daemon {$index} must be running before the quiesce");
        }
        static::__assert_equals(2, static::__matching_daemon_count($root), 'both fakes must match the pattern');

        // The harness supplies exactly what do_enable() supplies - a say() and a storage_base()
        // pointing at the scratch root - then runs the script's own lines against it.
        //
        // It has to be a FILE, not `bash -c '<text>'`: with -c the block's text is the shell's
        // own command line, so the pattern would match the shell running it and the reaper
        // would take itself down. Under the real script the pattern is computed at runtime and
        // appears in nobody's argv.
        $harness = $root . '/run-quiesce.sh';
        file_put_contents_safe($harness, implode("\n", [
            'set -u',
            'say() { echo "$*"; }',
            "storage_base() { printf '%s' " . escapeshellarg($root) . '; }',
            '',
            static::__extract_quiesce_block(static::__script_source()),
        ]));

        $output = [];
        $rc = 0;
        exec_safe('bash ' . escapeshellarg($harness), $output, $rc);
        $text = implode("\n", $output);

        static::__assert_equals(0, $rc, 'the quiesce block must succeed: ' . $text);
        static::__assert_contains('Quiesced 2 node daemon(s)', $text, 'the reap reports its count');

        // Wait for each fake to actually exit. A SIGKILLed task is torn down when the kernel
        // next schedules it, so an assertion taken the instant xargs returns can observe a
        // process that is already dead - which is a race in the OBSERVER, not in the reaper.
        //
        // This wait is deliberately unbounded (no deadline anywhere in this codebase): these
        // are our own children and they have been signalled, so it returns immediately. If a
        // future change to the block stops killing them, this is where it shows - as the fault
        // it is, rather than as a flaky assertion that sometimes passes on a fast box.
        foreach (static::$fakes as $index => $fake) {
            try {
                $fake->wait();
            } catch (ProcessSignaledException $e) {
                // Being ended by a signal is the WHOLE POINT here - Symfony reports it as an
                // exception, so this catch is how the expected outcome is spelled.
                static::__assert_true(
                    in_array($e->getSignal(), [SIGTERM, SIGKILL], true),
                    "fake daemon {$index} must have been ended by TERM or KILL, got signal " . $e->getSignal()
                );
            }

            static::__assert_false($fake->isRunning(), "fake daemon {$index} must be gone after the quiesce");
        }

        static::__assert_equals(0, static::__matching_daemon_count($root), 'nothing may still match the pattern');
    }

    /**
     * How many processes carry a socket under this scratch root in their command line.
     *
     * `-a` so the command line comes back too: PHP runs this through `bash -c`, and THAT shell's
     * own argv necessarily contains the pattern being searched for, so it matches itself. The
     * script does not have this problem (bash builds the pattern at runtime and a command
     * substitution keeps the parent's argv), which is why the exclusion lives here and not in
     * the block. Same technique as Rsx_Node_Service's pgrep helper.
     */
    protected static function __matching_daemon_count(string $root): int
    {
        $output = [];
        $rc = 0;

        // pgrep exits 1 when nothing matched, which is the answer 0 rather than an error.
        exec_safe('pgrep -a -f -- ' . escapeshellarg('--socket=' . $root . '/rsx-tmp/'), $output, $rc);

        $count = 0;
        foreach ($output as $line) {
            $line = trim($line);

            if ($line === '' || str_contains($line, 'pgrep')) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
