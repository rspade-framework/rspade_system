<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The PHP console_debug() gate is driven by RSX_MODE (build-mode policy), the single
 * mode switch. Debugger::_console_debug_enabled_for_mode() is the single named predicate
 * the gate consults: true in development and debug, false in strict production only.
 * This mirrors the JS-side gate and Manifest::_should_include_debug_info().
 *
 * Mode driven through the Rsx::_testing_set_mode() seam; restored via
 * clear_mode_cache(). Pure logic, no DB.
 */
class Console_Debug_Gate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function _with_mode(string $mode, callable $fn): void
    {
        Rsx::_testing_set_mode($mode);
        try {
            $fn();
        } finally {
            Rsx::clear_mode_cache();
        }
    }

    public static function test_gate_enabled_in_development()
    {
        self::_with_mode(Rsx::MODE_DEVELOPMENT, function () {
            static::__assert_true(
                Debugger::_console_debug_enabled_for_mode(),
                'console_debug emits in development'
            );
        });
    }

    public static function test_gate_enabled_in_debug()
    {
        self::_with_mode(Rsx::MODE_DEBUG, function () {
            static::__assert_true(
                Debugger::_console_debug_enabled_for_mode(),
                'console_debug emits in debug mode (the fixed CLAUDE.md claim)'
            );
        });
    }

    public static function test_gate_disabled_in_strict_production()
    {
        self::_with_mode(Rsx::MODE_PRODUCTION, function () {
            static::__assert_false(
                Debugger::_console_debug_enabled_for_mode(),
                'console_debug is suppressed in strict production'
            );
        });
    }
}
