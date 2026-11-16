<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * maintenance-mode.sh opens enable and disable with the image-contract warning and then
 * proceeds exactly as it otherwise would.
 *
 * Running the real script with services would stop this box's services, and the image
 * paths it reads are the real ones, so the test is two halves: STRUCTURAL - the warning is
 * the first statement of do_enable() and do_disable(), ahead of the flag write, the disable
 * guards and every start_unit; BEHAVIORAL - the script's own warn() and
 * warn_if_image_is_older() are EXTRACTED VERBATIM and run against a stubbed comparison, so
 * the assertion follows whatever the script actually does. The comparison itself is
 * Image_Contract_Test's subject.
 */
class Maintenance_Image_Contract_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SCRIPT = 'bin/maintenance-mode.sh';

    /** The body of shell function $name in $script, from its opening line to its closing brace. */
    private static function __function_body(string $script, string $name): string
    {
        $found = preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n(.*?)^\}$/ms', $script, $match);
        static::__assert_equals(1, $found, "the script defines {$name}()");

        return $match[1];
    }

    /** The first line of $body that is neither blank nor a comment. */
    private static function __first_statement(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                return $line;
            }
        }

        return '';
    }

    public static function test_the_warning_opens_enable_and_disable()
    {
        $script = (string) file_get_contents(base_path(self::SCRIPT));

        $enable = static::__function_body($script, 'do_enable');
        static::__assert_equals('warn_if_image_is_older', static::__first_statement($enable), 'do_enable() opens with the warning');
        static::__assert_true(
            strpos($enable, 'warn_if_image_is_older') < strpos($enable, '> "$FLAG"'),
            'enable warns before it writes the flag'
        );

        $disable = static::__function_body($script, 'do_disable');
        static::__assert_equals('warn_if_image_is_older', static::__first_statement($disable), 'do_disable() opens with the warning');
        foreach (['db_cache_in_progress', 'MIGRATE_DUMP_MARKER', "start_unit '^redis'", 'rm -f "$FLAG"'] as $later) {
            static::__assert_true(
                strpos($disable, 'warn_if_image_is_older') < strpos($disable, $later),
                "disable warns before {$later}"
            );
        }
    }

    public static function test_the_warning_never_stops_the_transition()
    {
        $script = (string) file_get_contents(base_path(self::SCRIPT));

        $helper = static::__function_body($script, 'warn_if_image_is_older');
        static::__assert_false(str_contains($helper, 'exit'), 'the helper never exits the script');

        preg_match('/^warn\(\) \{.*\}$/m', $script, $warn);
        static::__assert_count(1, $warn, 'the script defines warn() on one line');

        $prelude = $warn[0] . "\nIMAGE_CONTRACT_CONF=/nonexistent\n"
            . "warn_if_image_is_older() {\n{$helper}}\n";

        $cases = [
            // An older image: the sentence, as a [WARNING], and success.
            'echo "the sentence"; return 0' => ['[WARNING] the sentence', 0],
            // Fine or not applicable: nothing.
            'return 1' => ['', 0],
            // Malformed (the library printed its [ERROR] on stderr): no warning, success.
            'return 2' => ['', 0],
        ];

        foreach ($cases as $stub => [$expected_output, $expected_rc]) {
            $output = [];
            $rc = 0;
            exec_safe(
                'bash -c ' . escapeshellarg(
                    $prelude . "rsx_image_contract_problem() { {$stub}; }\nwarn_if_image_is_older\necho \"rc=\$?\""
                ),
                $output,
                $rc
            );

            static::__assert_equals(0, $rc, "the stubbed run completes ({$stub})");
            static::__assert_equals(
                array_values(array_filter([$expected_output, "rc={$expected_rc}"])),
                $output,
                "warn_if_image_is_older for a comparison that does `{$stub}`"
            );
        }
    }
}
