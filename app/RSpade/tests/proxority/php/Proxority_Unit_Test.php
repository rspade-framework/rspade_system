<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Proxority's pure modules - the scheduler, the classifier, the event ring and the pool-file
 * parser - through the node harness resource/unit_tests.js, which prints one line per test
 * and exits 0 only when all of them passed.
 */
class Proxority_Unit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_pure_modules_pass_their_node_unit_tests()
    {
        $process = new Process(['node', base_path('app/RSpade/tests/proxority/resource/unit_tests.js')]);
        $process->setTimeout(null);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        static::__assert_equals(0, $process->getExitCode(), "the node unit tests failed:\n" . $output);
        static::__assert_false(str_contains($output, '[ERROR]'), "a node unit test failed:\n" . $output);
        static::__assert_true((bool) preg_match('/^(\d+) of \1 passed$/m', $output), "the harness reports every test passed:\n" . $output);
    }
}
