<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - artisan IS the subject under test. The gate being asserted on
// is pre-boot, so it can only be observed from a subprocess, and the test needs the exact argv
// it constructs. Rsx_Artisan would insert its own command-line building (and a --_lock-group
// token) between the test and the gate. No lock is held at that point, so nothing to inherit.

/**
 * The allow-most-deny-some CLI gate, exercised against the REAL artisan in subprocesses (the
 * gate is pre-boot, so it cannot be observed in-process).
 *
 * These tests raise the REAL flag - there is no other way to test the real gate - but ALWAYS
 * with --no-services (no supervisord units are touched) and always through try/finally, and
 * teardown() clears it again unconditionally so an aborted run cannot leave the box in 503.
 */
class Maintenance_Gate_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const REASON = 'framework test window';

    /**
     * THE refusal signature, exactly as system/artisan emits it (the gate is pre-boot, so
     * this text is written by raw fwrite before anything is autoloaded).
     *
     * Asserting on the refusal ITSELF rather than on the phrase "maintenance mode" appearing
     * anywhere in the output is deliberate. An allowed command is free to talk about
     * maintenance mode - rsx:health has a lock-server row that says so - and a test that
     * treats any mention as proof of refusal turns every future output change into a
     * mysterious failure, and quietly pressures unrelated code into avoiding the words.
     */
    const REFUSAL_MARKER = '503 - System is in maintenance mode';
    const REFUSAL_HINT = 'Exit maintenance mode: php artisan rsx:maintenance:disable';
    const REFUSAL_EXIT_CODE = 75;

    /** The gate refused this invocation: its exact message, its hint, and its exit code. */
    protected static function __assert_gate_refused(int $rc, string $output, string $context): void
    {
        static::__assert_contains(self::REFUSAL_MARKER, $output, "{$context}: the gate must refuse it");
        static::__assert_contains(self::REFUSAL_HINT, $output, "{$context}: the refusal must name the exit command");
        static::__assert_equals(self::REFUSAL_EXIT_CODE, $rc, "{$context}: the refusal exits {$rc}");
    }

    /** The gate let this invocation through - whatever it then did on its own merits. */
    protected static function __assert_not_gate_refused(int $rc, string $output, string $context): void
    {
        static::__assert_true(
            !str_contains($output, self::REFUSAL_MARKER),
            "{$context}: the gate must not refuse it: " . $output
        );
        static::__assert_true(
            !str_contains($output, self::REFUSAL_HINT),
            "{$context}: no refusal hint should be printed: " . $output
        );
        static::__assert_true(
            $rc !== self::REFUSAL_EXIT_CODE,
            "{$context}: exit " . self::REFUSAL_EXIT_CODE . ' is the gate refusal code: ' . $output
        );
    }

    public static function teardown()
    {
        // Belt: whatever happened above, the box must not stay in maintenance mode.
        Framework_Maintenance::clear();
    }

    /** php <artisan> <args>, returning [rc, output]. */
    protected static function __artisan(string $args): array
    {
        $out = [];
        $rc = 0;
        exec_safe('php ' . escapeshellarg(base_path('artisan')) . ' ' . $args, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    /** bash bin/maintenance-mode.sh <args>, returning [rc, output]. */
    protected static function __script(string $args): array
    {
        $out = [];
        $rc = 0;
        exec_safe('bash ' . escapeshellarg(base_path('bin/maintenance-mode.sh')) . ' ' . $args, $out, $rc);

        return [$rc, implode("\n", $out)];
    }

    protected static function __enable(): void
    {
        [$rc, $output] = static::__artisan('rsx:maintenance:enable --no-services --reason=' . escapeshellarg(self::REASON));
        static::__assert_equals(0, $rc, 'enable must succeed: ' . $output);
    }

    protected static function __disable(): void
    {
        static::__artisan('rsx:maintenance:disable --no-services');
    }

    /**
     * The commands are intercepted PRE-BOOT (like rsx:framework:pull), so they are immune to the
     * gate they control; the flag's CONTENT is the operator reason.
     */
    public static function test_enable_disable_round_trip_writes_the_reason()
    {
        try {
            static::__enable();

            static::__assert_true(Framework_Maintenance::is_active_on_disk(), 'the flag must exist after enable');
            static::__assert_equals(self::REASON, Framework_Maintenance::reason());

            // Idempotent.
            static::__enable();
            static::__assert_true(Framework_Maintenance::is_active_on_disk());
        } finally {
            static::__disable();
        }

        static::__assert_false(Framework_Maintenance::is_active_on_disk(), 'the flag must be gone after disable');

        // Idempotent on the way out too.
        static::__disable();
        static::__assert_false(Framework_Maintenance::is_active_on_disk());
    }

    public static function test_automated_task_runners_are_blocked()
    {
        try {
            static::__enable();

            foreach (['rsx:task:process --once', 'rsx:task:worker'] as $command) {
                [$rc, $output] = static::__artisan($command);
                static::__assert_gate_refused($rc, $output, $command);
                static::__assert_contains(self::REASON, $output, 'the refusal must name the reason');
            }
        } finally {
            static::__disable();
        }
    }

    public static function test_task_run_is_refused_without_force_and_allowed_with_it()
    {
        try {
            static::__enable();

            [$rc, $output] = static::__artisan('rsx:task:run Rsxtest_Nonexistent_Service noop');
            static::__assert_gate_refused($rc, $output, 'rsx:task:run without --force');
            static::__assert_contains('--force', $output, 'the refusal must name the escape hatch');

            // With --force the gate lets it THROUGH (it then fails on its own merits - the
            // service does not exist - which is exactly the point: the gate is out of the way).
            [$forced_rc, $forced] = static::__artisan('rsx:task:run Rsxtest_Nonexistent_Service noop --force');
            static::__assert_not_gate_refused($forced_rc, $forced, 'rsx:task:run --force');
            static::__assert_contains('Service class not found', $forced, 'the task itself must have been attempted');
        } finally {
            static::__disable();
        }
    }

    public static function test_ordinary_commands_are_allowed()
    {
        try {
            static::__enable();

            foreach (['--version', 'migrate:status'] as $command) {
                [$rc, $output] = static::__artisan($command);
                static::__assert_equals(0, $rc, "{$command} must be allowed under maintenance: " . $output);
                static::__assert_not_gate_refused($rc, $output, $command);
            }

            // rsx:health reports its own findings (it may legitimately exit 1 with a FAIL row,
            // and its rows may legitimately DISCUSS maintenance mode - the lock-server check
            // reports that rsx-lockd is stopped on purpose while the flag is up). What matters
            // is that the GATE did not refuse it, so assert on the refusal itself.
            [$health_rc, $health] = static::__artisan('rsx:health');
            static::__assert_not_gate_refused($health_rc, $health, 'rsx:health');
        } finally {
            static::__disable();
        }
    }

    public static function test_override_token_bypasses_the_classification()
    {
        try {
            static::__enable();

            [$rc, $output] = static::__artisan('rsx:task:process --once ' . Framework_Maintenance::OVERRIDE_FLAG);
            static::__assert_not_gate_refused($rc, $output, 'the internal override');
            static::__assert_equals(0, $rc, 'the overridden command must run normally: ' . $output);
        } finally {
            static::__disable();
        }
    }

    /** The script is the ONE implementation; artisan just shells to it. */
    public static function test_script_rejects_an_unknown_action()
    {
        [$rc, $output] = static::__script('bogus-action');
        static::__assert_true($rc !== 0, 'an unknown action must fail');
        static::__assert_contains('[ERROR]', $output);
    }

    /** Web requests return 503 with the reason while the window is up. */
    public static function test_web_returns_503_with_the_reason()
    {
        $probe = [];
        $probe_rc = 0;
        exec_safe('command -v curl', $probe, $probe_rc);
        if ($probe_rc !== 0) {
            static::__skip('curl is unavailable in this environment');

            return;
        }

        try {
            static::__enable();

            $out = [];
            $rc = 0;
            exec_safe("curl -s -o /dev/null -w '%{http_code}' http://localhost/", $out, $rc);
            $code = trim(implode('', $out));

            if ($code === '000' || $code === '') {
                static::__skip('no local web server is answering on http://localhost/');

                return;
            }

            static::__assert_equals('503', $code, 'the web gate must answer 503 while maintenance is up');

            $body = [];
            exec_safe('curl -s http://localhost/', $body, $rc);
            $text = implode("\n", $body);
            static::__assert_contains(self::REASON, $text, 'the 503 body must quote the reason');

            // "Retry shortly" is wrong advice for a stale flag, so a non-production box
            // also gets the one command that clears it. This box is development, hence
            // the hint must be present here.
            static::__assert_contains(
                'rsx:maintenance:disable',
                $text,
                'a dev 503 must name the recovery command'
            );

            // The mode stamp is machinery, not a message - it must never leak into the body.
            static::__assert_true(
                !str_contains($text, Framework_Maintenance::MODE_PREFIX),
                'the 503 body must not print the raw mode stamp: ' . $text
            );
        } finally {
            static::__disable();
        }
    }

    /**
     * The SHELL writer produces the same two-line flag the PHP writer does. Both feed the
     * same autoload-free readers, so a divergence here would show up as a 503 body quoting
     * "mode=development" as its reason, or as a missing recovery hint.
     */
    public static function test_the_shell_writer_stamps_the_mode()
    {
        try {
            static::__enable();

            $raw = (string) @file_get_contents(Framework_Maintenance::flag_path());
            $lines = preg_split('/\R/', trim($raw));

            static::__assert_equals(self::REASON, trim($lines[0] ?? ''), 'line 1 is the reason');
            static::__assert_equals(
                Framework_Maintenance::MODE_PREFIX . Rsx::get_mode(),
                trim($lines[1] ?? ''),
                'line 2 is the mode stamp'
            );

            // And the parsed views agree with the raw file.
            static::__assert_equals(self::REASON, Framework_Maintenance::reason());
            static::__assert_equals(Rsx::get_mode(), Framework_Maintenance::stamped_mode());
        } finally {
            static::__disable();
        }
    }
}
