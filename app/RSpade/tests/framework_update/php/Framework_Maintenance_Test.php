<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FrameworkUpdate\Php;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - artisan IS the subject under test here. These spawns exercise
// the PRE-BOOT 503 gate in system/artisan, which can only be observed from outside the process.
// Routing them through Rsx_Artisan would put the framework's own command-line construction
// (including an injected --_lock-group token) between the test and the thing it is testing.
// The gate runs before any lock exists, so there is no lock-inheritance hazard to guard against.

/**
 * The framework-update maintenance gate: a flag file under storage/rsx-framework that makes every
 * `php artisan` command exit 503 - EXCEPT commands bearing the internal override the pull passes
 * to its own sub-calls (which is stripped from argv pre-boot). Enforcement is inline in
 * system/artisan; these tests exercise the helper API + the real subprocess gate.
 */
class Framework_Maintenance_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_flag_path_is_under_storage_rsx_framework()
    {
        $path = Framework_Maintenance::flag_path();
        static::__assert_true(
            str_ends_with($path, 'storage/rsx-framework/.maintenance.mode.framework.update'),
            "unexpected flag path: {$path}"
        );
    }

    // The distribution cache is re-derivable data outside the app tree; both PHP
    // consumers and the pull script must agree on this one location.
    public static function test_upstream_cache_dir_is_the_shared_tmp_clone()
    {
        static::__assert_equals('/tmp/rspade_upstream.git', Framework_Maintenance::upstream_cache_dir());
    }

    /**
     * raise()/clear() move the flag ON DISK. is_active() deliberately does NOT follow it
     * mid-process: it answers from the RSPADE_MAINT_MODE snapshot both entrypoints take at
     * boot, so a consumer (the lock backend above all) can never observe the flag flipping
     * underneath it. is_active_on_disk() is the live view.
     */
    public static function test_raise_and_clear()
    {
        Framework_Maintenance::clear();
        static::__assert_false(Framework_Maintenance::is_active_on_disk());

        try {
            Framework_Maintenance::raise();
            static::__assert_true(Framework_Maintenance::is_active_on_disk());
            static::__assert_true(file_exists(Framework_Maintenance::flag_path()));

            // This process booted with no flag, so the snapshot still says "no maintenance".
            static::__assert_false(Framework_Maintenance::is_active(), 'is_active() must honor the boot snapshot');
        } finally {
            Framework_Maintenance::clear();
        }

        static::__assert_false(Framework_Maintenance::is_active_on_disk());
    }

    /** The flag's CONTENT is the operator reason every refusal message quotes. */
    public static function test_reason_comes_from_the_flag_content()
    {
        Framework_Maintenance::clear();

        try {
            Framework_Maintenance::raise(null, 'writer test reason');
            static::__assert_equals('writer test reason', Framework_Maintenance::reason());

            Framework_Maintenance::raise();
            static::__assert_equals('framework update in progress', Framework_Maintenance::reason());
        } finally {
            Framework_Maintenance::clear();
        }
    }

    /**
     * The flag carries a SECOND line, "mode=<application mode>", stamped at raise time.
     *
     * It exists because the web 503 is emitted pre-autoload and cannot boot config to ask
     * what mode it is in - the writer records what only the writer knows. reason() must
     * therefore return LINE 1 ONLY, or every refusal message would grow a "mode=..." tail.
     */
    public static function test_flag_stamps_the_mode_without_polluting_the_reason()
    {
        Framework_Maintenance::clear();

        try {
            Framework_Maintenance::raise(null, 'stamp test reason');

            $raw = (string) file_get_contents(Framework_Maintenance::flag_path());
            static::__assert_contains("stamp test reason\n", $raw, 'line 1 is the reason');
            static::__assert_contains(Framework_Maintenance::MODE_PREFIX, $raw, 'line 2 carries the mode stamp');

            static::__assert_equals('stamp test reason', Framework_Maintenance::reason(), 'the reason must not include the stamp');
            static::__assert_equals(Rsx::get_mode(), Framework_Maintenance::stamped_mode());
        } finally {
            Framework_Maintenance::clear();
        }
    }

    /**
     * A flag with no stamp - written by hand, or by a release predating the stamp - reads
     * as null, which every consumer treats as production. Absence resolves to the direction
     * that discloses nothing.
     */
    public static function test_a_stampless_flag_reports_no_mode()
    {
        Framework_Maintenance::clear();
        $path = Framework_Maintenance::flag_path();

        try {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, "hand written reason\n");

            static::__assert_equals('hand written reason', Framework_Maintenance::reason());
            static::__assert_null(Framework_Maintenance::stamped_mode());
        } finally {
            Framework_Maintenance::clear();
        }
    }

    /** The PHP test seam forces the answer without touching the real flag. */
    public static function test_force_active_for_tests_overrides_the_snapshot()
    {
        try {
            Framework_Maintenance::$force_active_for_tests = true;
            static::__assert_true(Framework_Maintenance::is_active());

            Framework_Maintenance::$force_active_for_tests = false;
            static::__assert_false(Framework_Maintenance::is_active());
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }
    }

    public static function test_artisan_gate_blocks_unless_override()
    {
        $artisan = base_path('artisan');
        Framework_Maintenance::clear();

        try {
            Framework_Maintenance::raise();

            // The gate is allow-most-deny-some: an AUTOMATED task runner is refused with the
            // 503 gate message. exec_safe captures both output and exit code (it appends 2>&1).
            $out1 = [];
            $rc1 = 0;
            exec_safe('php ' . escapeshellarg($artisan) . ' rsx:task:process --once', $out1, $rc1);
            static::__assert_true($rc1 !== 0, 'blocked command must exit non-zero');
            static::__assert_true(
                str_contains(implode("\n", $out1), '503'),
                'expected the 503 gate message, got: ' . implode("\n", $out1)
            );

            // ...while an ordinary command runs (the window stops automation, not humans).
            $out_ok = [];
            $rc_ok = 0;
            exec_safe('php ' . escapeshellarg($artisan) . ' --version', $out_ok, $rc_ok);
            static::__assert_equals(0, $rc_ok, 'ordinary commands stay allowed: ' . implode("\n", $out_ok));

            // The internal override bypasses (and is stripped, so no unknown-option error).
            $out2 = [];
            $rc2 = 0;
            exec_safe('php ' . escapeshellarg($artisan) . ' rsx:task:process --once --_framework-update-override', $out2, $rc2);
            static::__assert_equals(0, $rc2, 'override must bypass the gate: ' . implode("\n", $out2));
        } finally {
            Framework_Maintenance::clear();
        }

        // Restored once the flag is gone.
        $out3 = [];
        $rc3 = 0;
        exec_safe('php ' . escapeshellarg($artisan) . ' rsx:task:process --once', $out3, $rc3);
        static::__assert_equals(0, $rc3);
    }
}
