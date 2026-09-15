<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ide\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The IDE bridge is a DEVELOPMENT-MODE FEATURE and the pre-boot gate is what makes that
 * true: Ide/Services/auth.php refuses any mode but development before it reads a token,
 * and there is no env key, config value or opt-in that reopens it.
 *
 * WHY A SUBPROCESS. auth.php runs before Laravel and ends in exit() - it cannot be
 * included in-process without taking the suite down with it, and its answer depends on
 * the mode the PROCESS was started in, which is precisely what is under test. So each
 * case is one short `php -r` that stands the gate up with a synthetic $_SERVER and
 * reports what it printed. The mode is supplied in the child's ENVIRONMENT because that
 * is the input the gate reads (getenv first, then .env) - this box's own .env and mode
 * are never touched.
 *
 * No database access - skip the per-test transaction.
 */
class Ide_Gate_Mode_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The refusal the gate prints outside development. */
    private const REFUSAL = 'IDE services are development-only';

    /**
     * Stand the gate up in a child process running in $mode and return what it printed.
     *
     * The request it is handed is a plausible bridge call from a non-loopback address
     * with no token, so in development the gate must fall through to the AUTH question -
     * which is how "the mode gate did not refuse" is observed.
     */
    private static function __run_gate(string $mode): string
    {
        $auth = base_path('app/RSpade/Ide/Services/auth.php');

        $code = '$_SERVER["REQUEST_URI"]="/_ide/service/resolve_class";'
            . '$_SERVER["REMOTE_ADDR"]="203.0.113.9";'
            . '$_SERVER["HTTP_HOST"]="example.com";'
            . 'require ' . var_export($auth, true) . ';';

        // NO TIMEOUT (null). Symfony caps at 60 seconds when you say nothing, and an
        // inherited default is still a deadline: a child that hangs here means the gate
        // is wedged, which is a fault to SEE.
        $process = new Process(['php', '-r', $code], base_path(), ['RSX_MODE' => $mode] + $_ENV);
        $process->setTimeout(null);
        $process->run();

        return $process->getOutput() . $process->getErrorOutput();
    }

    public static function test_a_sealed_mode_is_refused_before_any_token_is_read()
    {
        foreach (['debug', 'production'] as $mode) {
            $output = self::__run_gate($mode);

            static::__assert_contains(self::REFUSAL, $output, "the gate refuses in {$mode} mode");
            static::__assert_false(
                str_contains($output, 'Authentication required'),
                "the {$mode} refusal comes BEFORE the token question - there is nothing to present"
            );
        }
    }

    public static function test_development_reaches_the_authentication_question()
    {
        $output = self::__run_gate('development');

        static::__assert_false(
            str_contains($output, self::REFUSAL),
            'development is the one mode the bridge exists in'
        );
        static::__assert_contains(
            'Authentication required',
            $output,
            'and a tokenless caller is then refused on its credentials, not on the mode'
        );
    }

    public static function test_the_env_override_is_gone()
    {
        // RSX_IDE_SERVICES_ENABLED is retired: the bridge is development-only by
        // construction, so an opt-in that could reopen it elsewhere would be the one
        // setting capable of putting a pre-boot service on a production box.
        $source = file_get_contents(base_path('app/RSpade/Ide/Services/auth.php'));

        static::__assert_false(
            str_contains($source, 'RSX_IDE_SERVICES_ENABLED'),
            'the gate reads no opt-in key'
        );

        static::__assert_contains(
            self::REFUSAL,
            self::__run_gate('production'),
            'and setting one in the environment changes nothing'
        );
    }
}
