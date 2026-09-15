<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Health\Playwright_Health_Checks;
use App\RSpade\Core\Health\Playwright_Stack;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The node -> playwright -> chromium chain, its health row and the refusal rsx:debug
 * prints when a link is absent.
 *
 * ONE STRING, TWO CONSUMERS is the property under test: the command the health row tells
 * an operator to run and the command the refusal tells them to run are the same literal,
 * because Playwright_Stack owns both. A working development box is never in any of the
 * missing states, so each link is forced absent through the class's own seam.
 *
 * No database access - skip the per-test transaction.
 */
class Playwright_Stack_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __reset(): void
    {
        Playwright_Stack::_testing_set_absent_link(null);
    }

    // -------------------------------------------------------------------------
    // The probe
    // -------------------------------------------------------------------------

    public static function test_this_box_has_the_whole_stack()
    {
        try {
            $probe = Playwright_Stack::probe();

            static::__assert_true($probe['ok'], 'the development container ships the playwright stack');
            static::__assert_null($probe['missing'], 'nothing is missing');
            static::__assert_not_null($probe['chromium_path'], 'the chromium binary resolves to a path');
        } finally {
            self::__reset();
        }
    }

    public static function test_each_absent_link_is_named_with_its_own_install_command()
    {
        $expected = [
            'node' => Playwright_Stack::NODE_INSTALL,
            'package' => Playwright_Stack::PACKAGE_INSTALL,
            'chromium' => Playwright_Stack::CHROMIUM_INSTALL,
        ];

        try {
            foreach ($expected as $link => $command) {
                Playwright_Stack::_testing_set_absent_link($link);

                $probe = Playwright_Stack::probe();

                static::__assert_false($probe['ok'], "an absent {$link} is not ok");
                static::__assert_equals($link, $probe['missing'], "the probe names {$link}");
                static::__assert_equals($command, $probe['remediation'], "the remediation is the {$link} install command");
            }
        } finally {
            self::__reset();
        }
    }

    public static function test_the_probe_stops_at_the_first_absent_link()
    {
        try {
            // Without node nothing else can be asked, so the later links are not probed
            // and are not reported as missing on their own account.
            Playwright_Stack::_testing_set_absent_link('node');

            static::__assert_equals('node', Playwright_Stack::probe()['missing'], 'node is the first link');
        } finally {
            self::__reset();
        }
    }

    // -------------------------------------------------------------------------
    // The health row
    // -------------------------------------------------------------------------

    public static function test_the_row_is_a_warn_and_never_a_fail()
    {
        try {
            foreach (['node', 'package', 'chromium'] as $link) {
                Playwright_Stack::_testing_set_absent_link($link);

                $row = Playwright_Health_Checks::_row(Playwright_Stack::probe());

                static::__assert_equals('WARN', $row['status'], "an absent {$link} costs a command, not the site");
                static::__assert_not_null($row['remediation'], 'every WARN names its remedy');
            }

            self::__reset();

            static::__assert_equals(
                'OK',
                Playwright_Health_Checks::_row(Playwright_Stack::probe())['status'],
                'a complete stack is OK'
            );
        } finally {
            self::__reset();
        }
    }

    public static function test_the_check_is_declared_development_only()
    {
        $modes = null;

        foreach (Health_Check_Runner::discover() as $check) {
            if ($check['label'] === 'Playwright / Chromium') {
                $modes = $check['modes'];
            }
        }

        static::__assert_equals(
            ['development'],
            $modes,
            'a development tool is not reported as missing on a box that is right not to have it'
        );
    }

    // -------------------------------------------------------------------------
    // The refusal rsx:debug prints
    // -------------------------------------------------------------------------

    public static function test_the_refusal_names_the_tool_what_is_missing_and_the_same_install_command()
    {
        try {
            Playwright_Stack::_testing_set_absent_link('chromium');

            $probe = Playwright_Stack::probe();
            $message = Playwright_Stack::refusal_message($probe, 'rsx:debug');
            $row = Playwright_Health_Checks::_row($probe);

            static::__assert_contains('rsx:debug', $message, 'the refusal names the tool that refused');
            static::__assert_contains('chromium', $message, 'the refusal names the missing link');
            static::__assert_contains(
                Playwright_Stack::CHROMIUM_INSTALL,
                $message,
                'the refusal names the literal install command'
            );
            static::__assert_contains(
                $row['remediation'],
                $message,
                'the refusal and the health row name the SAME command - one string, two consumers'
            );
        } finally {
            self::__reset();
        }
    }
}
