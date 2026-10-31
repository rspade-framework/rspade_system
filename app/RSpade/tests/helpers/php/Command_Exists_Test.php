<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Helpers\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * command_exists() - an executable of that name on the PATH, or at a given path, answered
 * without a shell: a name carrying shell syntax is only ever a file name that does not exist.
 */
class Command_Exists_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_binary_on_the_path_exists()
    {
        static::__assert_true(command_exists('bash'));
        static::__assert_true(command_exists('php'));
        static::__assert_false(command_exists('no-such-command-rsx-test'));
        static::__assert_false(command_exists(''));
    }

    public static function test_a_path_is_checked_as_a_file()
    {
        static::__assert_true(command_exists(PHP_BINARY));
        static::__assert_false(command_exists('/etc/hostname'), 'a file that is not executable');
        static::__assert_false(command_exists('/tmp'), 'a directory');
    }

    public static function test_shell_syntax_runs_nothing()
    {
        $marker = Rsx_Project_Paths::tmp_path('command_exists_test_' . getmypid());
        @unlink($marker);

        foreach (["bash; touch {$marker}", "bash && touch {$marker}", "bash | touch {$marker}", "\$(touch {$marker})", "`touch {$marker}`", "bash\ntouch {$marker}"] as $name) {
            static::__assert_false(command_exists($name), 'not a command: ' . json_encode($name));
        }

        static::__assert_false(file_exists($marker), 'nothing in the name was executed');
    }
}
