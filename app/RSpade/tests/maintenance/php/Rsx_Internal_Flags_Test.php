<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Php;

use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - artisan's ARGV is the subject under test, and Rsx_Artisan
// appends a --_lock-group token to every synchronous spawn. Using it here would inject an
// extra internal flag into the very argv these tests make assertions about, so the helper is
// not merely unnecessary - it would invalidate the test.

/**
 * The `--_` framework-internal flag convention: system/artisan lifts every `--_`-prefixed argv
 * token into a process global and strips it before Symfony parses, and this class is the
 * booted-world reader. Proves both halves - the in-process API and the real pre-boot strip
 * (a subprocess, since the strip happens before any test can run).
 */
class Rsx_Internal_Flags_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_set_has_all_and_clear()
    {
        $flag = '--_rsxtest-internal-flag';

        static::__assert_false(Rsx_Internal_Flags::has($flag));

        Rsx_Internal_Flags::set($flag);
        static::__assert_true(Rsx_Internal_Flags::has($flag));
        static::__assert_true(in_array($flag, Rsx_Internal_Flags::all(), true));

        // Idempotent.
        Rsx_Internal_Flags::set($flag);
        static::__assert_count(1, array_filter(Rsx_Internal_Flags::all(), static fn ($f) => $f === $flag));

        Rsx_Internal_Flags::clear($flag);
        static::__assert_false(Rsx_Internal_Flags::has($flag));
    }

    /**
     * An UNKNOWN `--_` token must be stripped from argv rather than reaching Symfony: no
     * "unknown option" error, no non-zero exit.
     */
    public static function test_unknown_internal_flag_is_stripped_pre_boot()
    {
        $out = [];
        $rc = 0;
        exec_safe('php ' . escapeshellarg(base_path('artisan')) . ' --version --_rsxtest-nonexistent-flag', $out, $rc);

        static::__assert_equals(0, $rc, 'an internal flag must never produce an error: ' . implode("\n", $out));
        $text = strtolower(implode("\n", $out));
        static::__assert_true(
            !str_contains($text, 'not defined') && !str_contains($text, 'unknown option'),
            'expected no option error, got: ' . $text
        );
    }

    /** Internal flags are declared as no InputOption, so they can appear in no help output. */
    public static function test_internal_flags_never_appear_in_help_output()
    {
        foreach (['list', 'help rsx:clean', 'help rsx:manifest:build'] as $invocation) {
            $out = [];
            $rc = 0;
            exec_safe('php ' . escapeshellarg(base_path('artisan')) . ' ' . $invocation, $out, $rc);
            static::__assert_true(
                !str_contains(implode("\n", $out), '--_'),
                "'{$invocation}' must not render any --_ flag"
            );
        }
    }

    /** The maintenance override is the reference implementation of the convention. */
    public static function test_override_flag_uses_the_convention()
    {
        static::__assert_true(
            str_starts_with(\App\RSpade\Core\Framework\Framework_Maintenance::OVERRIDE_FLAG, '--_'),
            'the override token must use the --_ prefix'
        );
    }
}
