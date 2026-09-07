<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Commands\Restricted\Down_Command;
use App\RSpade\Commands\Restricted\Up_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Laravel's `down` and `up` are REFUSED, and the mechanism they drove is gone.
 *
 * RSpade has one maintenance mode. The danger these tests guard against is not that
 * `down` is untidy - it is that a stock `down` would look like it worked: it writes
 * storage/framework/maintenance.php, prints nothing alarming, exits 0, and gates
 * absolutely nothing, because neither the middleware nor the pre-render check that
 * read that file exists any more. An operator who typed it during real downtime would
 * be serving traffic and believe otherwise.
 *
 * These run IN PROCESS (Artisan::call): the stubs are ordinary commands that refuse
 * before doing anything, so there is no pre-boot behavior to observe from a subprocess
 * and nothing that can take this box down.
 *
 * Pure command behavior, no DB.
 */
class Laravel_Down_Up_Refused_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The file stock Laravel's `down` writes. Nothing in RSpade writes or reads it. */
    private static function _laravel_maintenance_file(): string
    {
        return storage_path('framework/maintenance.php');
    }

    /**
     * Run one artisan command in-process.
     *
     * @return array{0:int,1:string} [exit_code, output]
     */
    private static function _artisan(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    // -------------------------------------------------------------------------
    // The names resolve to the RSpade stubs, not to Laravel's commands
    // -------------------------------------------------------------------------

    public static function test_down_and_up_resolve_to_the_restricted_stubs()
    {
        $commands = Artisan::all();

        static::__assert_true(isset($commands['down']), 'down is registered');
        static::__assert_true(isset($commands['up']), 'up is registered');
        static::__assert_instance_of(Down_Command::class, $commands['down'], 'the RSpade stub wins by name');
        static::__assert_instance_of(Up_Command::class, $commands['up'], 'the RSpade stub wins by name');
    }

    // -------------------------------------------------------------------------
    // Neither appears in the command listing
    // -------------------------------------------------------------------------

    public static function test_neither_command_appears_in_the_listing()
    {
        [, $output] = self::_artisan('list', ['--raw' => true]);

        foreach (explode("\n", $output) as $line) {
            $name = trim(strtok($line, " \t"));
            static::__assert_true($name !== 'down', 'down is hidden from the listing');
            static::__assert_true($name !== 'up', 'up is hidden from the listing');
        }

        // The one that IS offered.
        static::__assert_contains('rsx:maintenance', $output, 'the RSpade command is listed');
    }

    // -------------------------------------------------------------------------
    // Each refuses, exits 1, and names its replacement
    // -------------------------------------------------------------------------

    public static function test_down_refuses_and_names_the_rsx_command()
    {
        [$code, $output] = self::_artisan('down');

        static::__assert_equals(1, $code, 'down exits 1');
        static::__assert_contains('restricted in RSX', $output, 'it says it is restricted');
        static::__assert_contains('php artisan rsx:maintenance:enable', $output, 'it names the replacement');
    }

    public static function test_up_refuses_and_names_the_rsx_command()
    {
        [$code, $output] = self::_artisan('up');

        static::__assert_equals(1, $code, 'up exits 1');
        static::__assert_contains('restricted in RSX', $output, 'it says it is restricted');
        static::__assert_contains('php artisan rsx:maintenance:disable', $output, 'it names the replacement');
    }

    // -------------------------------------------------------------------------
    // The Laravel mechanism is not merely unused - it is never created
    // -------------------------------------------------------------------------

    public static function test_no_laravel_maintenance_file_is_ever_created()
    {
        $path = self::_laravel_maintenance_file();

        static::__assert_false(file_exists($path), 'no maintenance.php before');

        self::_artisan('down');
        self::_artisan('up');

        static::__assert_false(
            file_exists($path),
            'the refused commands wrote nothing to ' . $path
        );
    }
}
