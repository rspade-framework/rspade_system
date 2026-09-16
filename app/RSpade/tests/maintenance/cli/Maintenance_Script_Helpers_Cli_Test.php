<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Every helper maintenance-mode.sh calls is a function it, or the path library it sources,
 * defines.
 *
 * The script runs pre-boot from the framework updater's exit trap, and a helper it names
 * but nobody defines is not a fatal there: bash prints "command not found" to a stderr the
 * trap swallows, the substitution yields an empty string, and the wait that follows loops
 * with no deadline against an address that cannot exist. This test is the guard for that
 * class of failure: a helper spelling that drifts from its definition fails here, in the
 * suite, instead of on a downstream box mid-update.
 */
class Maintenance_Script_Helpers_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SCRIPT = 'bin/maintenance-mode.sh';
    private const LIB = 'bin/lib/rsx_paths.sh';

    public static function test_every_helper_the_script_calls_is_defined()
    {
        $script = (string) file_get_contents(base_path(self::SCRIPT));
        $lib = (string) file_get_contents(base_path(self::LIB));

        $defined = [];
        foreach ([$script, $lib] as $source) {
            preg_match_all('/^([a-z_][a-z0-9_]*)\(\)\s*\{/m', $source, $matches);
            foreach ($matches[1] as $name) {
                $defined[$name] = true;
            }
        }

        // A helper is an underscore-bearing lowercase identifier in command position: the
        // head of a command substitution, or the head of a line (optionally behind a
        // negation or a control keyword). External commands in this script carry no
        // underscore, which is what keeps the two sets apart.
        $called = [];
        foreach (explode("\n", $script) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (preg_match_all('/\$\(\s*([a-z]+(?:_[a-z0-9]+)+)\b/', $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $called[$name] = true;
                }
            }
            // Followed by whitespace or the end of the line: an assignment (name=...) is
            // a variable, not a call.
            if (preg_match('/^(?:(?:if|while|until|elif)\s+)?(?:!\s+)?([a-z]+(?:_[a-z0-9]+)+)(?=\s|$)/', $trimmed, $match)) {
                $called[$match[1]] = true;
            }
        }

        $undefined = array_diff(array_keys($called), array_keys($defined));

        static::__assert_true(count($called) > 5, 'the scan found the script\'s helper calls');
        static::__assert_equals(
            [],
            array_values($undefined),
            'every helper the script calls is defined by the script or the path library'
        );
    }

    /**
     * The lockd wait refuses an empty address instead of looping against it.
     */
    public static function test_the_lockd_wait_refuses_an_incomplete_address()
    {
        $script = (string) file_get_contents(base_path(self::SCRIPT));

        static::__assert_true(
            str_contains($script, 'rsx-lockd address is incomplete'),
            'wait_for_lockd refuses an empty host or port before its first attempt'
        );
    }
}
