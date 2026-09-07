<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\JsTransform\Php;

use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A node RPC helper that will not start must say what was OBSERVED - and one that
 * fails must leave no trace, so the next caller retries instead of inheriting a
 * corpse.
 *
 * THE DEFECT (field report, 2026-08-11). rsx:framework:pull synced and committed correctly,
 * then failed its rebuild with 6 of 6 bundles broken:
 *
 *     JS Transformer RPC server failed to start within 10000ms.
 *     Check that Node.js and Babel dependencies are installed.
 *     Fix: cd system/app/RSpade/Core/JavaScript/resource && npm install
 *   ... 5 more, all "socket not found"
 *
 * Every part of that was wrong.
 *
 *   1. The dependencies were PRESENT and resolving (33,139 node_modules files in the
 *      release inventory, @babel/core resolving from the transformer's own directory).
 *      The daemon was simply slow on a box running nightly backups.
 *   2. The named directory DOES NOT EXIST - the transformer lives under
 *      Core/JsParsers/resource - so following the instruction gives "cd: no such file
 *      or directory" and reads as deeper breakage.
 *   3. The remedy is HARMFUL downstream: node_modules is a framework-owned zone,
 *      hard-synced with rsync --delete, so npm install there writes unauthorized
 *      content into an owned zone and the tamper gate then refuses every future
 *      framework update.
 *   4. ONE slow start failed SIX bundles, because the process handle was published
 *      BEFORE the readiness wait. A failed start left it non-null, so every later
 *      caller saw "already started", skipped the spawn, and died at "socket not
 *      found". Nothing ever retried.
 */
class Rpc_Startup_Diagnostics_Test extends Rsx_Test_Abstract
{
    // Real processes and real sockets; no database involvement at all.
    protected static $use_database_transactions = false;

    private static function __message(
        string $stderr = '',
        ?string $socket = null,
        ?string $script = null,
        ?bool $framework_developer = null
    ): string {
        // Pin the audience rather than inheriting it. Running in the monorepo the ambient
        // flag is TRUE, so a test that branched on it would silently skip every DOWNSTREAM
        // assertion - which is exactly the audience the harmful advice was aimed at.
        $original = config('rsx.code_quality.is_framework_developer', false);
        if ($framework_developer !== null) {
            config(['rsx.code_quality.is_framework_developer' => $framework_developer]);
        }
        try {
        // A dead process carrying $stderr, produced without depending on Symfony internals:
        // `false` is a command that always exits 1, and `bash -c` lets us plant the stderr.
        $process = new \Symfony\Component\Process\Process(['bash', '-c', 'echo ' . escapeshellarg($stderr) . ' >&2; exit 1']);
        $process->run();

            return Rpc_Startup_Diagnostics::failure_message(
                Rsx_Node_Service::LABEL,
                $socket ?? storage_path('rsx-tmp/does-not-exist.sock'),
                $script ?? Rsx_Node_Service::entry_script_path(),
                10000,
                $process
            );
        } finally {
            config(['rsx.code_quality.is_framework_developer' => $original]);
        }
    }

    /**
     * THE ONE THAT COST A NIGHT: the message must never send a downstream operator to
     * npm install inside system/, and must never name the directory that does not exist.
     */
    public static function test_never_prescribes_npm_install_inside_system()
    {
        foreach (['', 'Error: Cannot find module \'@babel/core\'', 'some unrelated crash'] as $stderr) {
            $message = self::__message($stderr, null, null, framework_developer: false);

            static::__assert_false(
                str_contains($message, 'Core/JavaScript/resource'),
                'the message must not name a directory that does not exist (stderr: ' . $stderr . ')'
            );

            // In a downstream app node_modules is an owned zone. Writing into it is what
            // makes the tamper gate refuse every future update. The test is for a RECIPE
            // ("cd ... && npm install"), not for the words - the downstream text
            // deliberately NAMES npm install in order to forbid it, which is the point.
            static::__assert_false(
                str_contains($message, '&& npm install'),
                'must never hand a downstream operator an npm install recipe (stderr: ' . $stderr . ')'
            );
        }
    }

    /**
     * A genuine module failure is diagnosed from the daemon's OWN stderr, and routed to
     * the updater - an absence in an owned zone is a sync defect, not an install job.
     */
    public static function test_module_failure_is_evidence_based_and_routes_to_the_updater()
    {
        $stderr = "Error: Cannot find module '@babel/core'\n    at Module._resolveFilename";

        $downstream = self::__message($stderr, null, null, framework_developer: false);
        static::__assert_contains('EXITED', $downstream, 'a dead process must be reported as dead');
        static::__assert_contains('Cannot find module', $downstream, "the daemon's own words must be quoted");
        static::__assert_contains('rsx:framework:pull', $downstream, 'the remedy is the updater');
        static::__assert_contains('SYNC DEFECT', $downstream, 'must explain why installing is wrong');
        static::__assert_contains(
            'Do NOT run npm install inside system/',
            $downstream,
            'the module-missing case must explicitly forbid the harmful remedy'
        );

        // The monorepo is the ONE place npm install inside system/ is correct - the
        // framework developer owns that tree.
        $monorepo = self::__message($stderr, null, null, framework_developer: true);
        static::__assert_contains('npm install', $monorepo, 'a framework developer installs it themselves');
        static::__assert_false(
            str_contains($monorepo, 'rsx:framework:pull'),
            'the monorepo has no upstream to pull from'
        );
    }

    /**
     * With no module error in evidence, the message must NOT invent one. The commonest
     * real cause is a slow box, and that is what it should say.
     */
    public static function test_a_slow_start_is_not_reported_as_a_missing_dependency()
    {
        $message = self::__message('', null, null, framework_developer: false);

        static::__assert_false(
            str_contains($message, 'Cannot find module') || str_contains($message, 'failed to resolve'),
            'absence of evidence must not become a dependency diagnosis'
        );
        static::__assert_contains('slow to boot', $message, 'the likeliest cause should be named');
        static::__assert_contains('rpc_server_ready_wait_ms', $message, 'the budget must be discoverable');
        static::__assert_contains('10000ms', $message, 'the message must state how long it actually waited');
    }

    /**
     * An existing socket that nobody serves is a THIRD condition, and must not be
     * confused with either of the other two.
     */
    public static function test_a_stale_socket_is_diagnosed_as_a_stale_socket()
    {
        $socket = storage_path('rsx-tmp/rpc-diagnostics-probe.sock');
        @mkdir(dirname($socket), 0755, true);
        file_put_contents($socket, '');

        try {
            $message = self::__message('', $socket, null, framework_developer: false);
            static::__assert_contains('EXISTS', $message, 'the socket file must be reported as present');
            static::__assert_contains('stale', $message, 'a present-but-unserved socket is the stale case');
            static::__assert_contains('pgrep', $message, 'the operator needs a way to find the holder');
        } finally {
            @unlink($socket);
        }
    }

    /**
     * A daemon that EXITED and printed something did not have a slow boot - it failed.
     * Telling that operator to retry or raise the budget is advice that cannot work, and
     * it is exactly what happened to the 'sh: 1: exec: 11: not found' case (2026-08-12):
     * the true cause was quoted in the message and the remedy line talked about slowness.
     */
    public static function test_a_dead_daemon_with_stderr_is_not_reported_as_a_slow_boot()
    {
        $message = self::__message('sh: 1: exec: 11: not found', null, null, framework_developer: false);

        static::__assert_contains('EXITED with an error', $message, 'an exit with output is a hard failure');
        static::__assert_contains('sh: 1: exec: 11: not found', $message, "the daemon's own words must be quoted");
        static::__assert_false(
            str_contains($message, 'slow to boot'),
            'a dead-with-stderr daemon must never be diagnosed as a slow boot'
        );
        static::__assert_false(
            str_contains($message, 'rpc_server_ready_wait_ms'),
            'raising the ready-wait budget cannot fix a daemon that exited with an error'
        );
    }

    /**
     * A missing entry script means the framework tree is incomplete - the updater's job,
     * not npm's.
     */
    public static function test_missing_server_script_points_at_the_updater()
    {
        $message = self::__message('', null, base_path('app/RSpade/Core/JsParsers/resource/no-such-server.js'), framework_developer: false);

        static::__assert_contains('MISSING', $message, 'an absent script must be named as absent');
        static::__assert_contains('rsx:framework:pull', $message, 'an incomplete tree is repaired by the updater');
    }

    /**
     * THE CASCADE: a failed start must leave NO process handle behind, so the next
     * caller spawns again instead of dying at "socket not found".
     *
     * Forced deterministically by setting the readiness budget to zero - the loop runs no
     * iterations, so the start path fails exactly as it does on a slow box, with no
     * dependence on machine timing.
     *
     * CONTRACT NOTE (2026-09-04): the eight per-concern daemons were consolidated into ONE
     * service, so the entry point is Rsx_Node_Service::ensure() and the published handle is
     * read through get_process(). What is being asserted is unchanged and is the whole point
     * of that accessor: after a failed start there must be NO published handle. There is one
     * service to check now instead of two representative families - and one is the whole
     * population, so the coverage is total rather than sampled.
     */
    public static function test_a_failed_start_leaves_no_handle_so_the_next_use_retries()
    {
        $original = config('rsx.javascript.rpc_server_ready_wait_ms');

        // Start from a known-clean state, and make sure a live service (from the build
        // that just ran) cannot let the start path short-circuit as "already serving".
        Rsx_Node_Service::stop(force: true);

        config(['rsx.javascript.rpc_server_ready_wait_ms' => 0]);

        try {
            $threw = false;
            try {
                Rsx_Node_Service::ensure();
            } catch (\Throwable $e) {
                $threw = true;
                static::__assert_contains(
                    'did not answer within 0ms',
                    $e->getMessage(),
                    'the failure must report the budget it actually used'
                );
            }

            static::__assert_true($threw, 'the node service must fail loud when it does not answer');
            static::__assert_null(
                Rsx_Node_Service::get_process(),
                'the node service must leave NO process handle after a failed start - a stale handle is '
                . 'what turned one slow boot into 6 failed bundles'
            );
        } finally {
            config(['rsx.javascript.rpc_server_ready_wait_ms' => $original]);
            Rsx_Node_Service::stop(force: true);
        }
    }
}
