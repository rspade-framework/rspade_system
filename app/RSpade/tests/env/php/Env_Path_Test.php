<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use App\RSpade\Commands\Rsx\Env_Decrypt_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Where Laravel believes the environment file lives.
 *
 * base_path() is system/ - a git submodule the framework pull resets and cleans -
 * so anything anchored there is anchored on sand. bootstrap/app.php calls
 * useEnvironmentPath(<project root>), which moves environmentFilePath(), env:encrypt
 * and key:generate to the root in one stroke; Env_Decrypt_Command is the remaining
 * half, because stock env:decrypt defaults its OUTPUT directory to base_path()
 * independently of where it read the ciphertext.
 *
 * Nothing here reads, writes or names a real .env file: the assertions are about
 * PATHS and about which class a command name resolves to.
 *
 * Pure logic, no DB.
 */
class Env_Path_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The project root: one level above the Laravel base path. */
    private static function _project_root(): string
    {
        return dirname(base_path());
    }

    // -------------------------------------------------------------------------
    // The application's own environment paths
    // -------------------------------------------------------------------------

    public static function test_environment_path_is_the_project_root()
    {
        static::__assert_equals(
            self::_project_root(),
            rtrim(app()->environmentPath(), '/'),
            'environmentPath() is the project root, not base_path()'
        );

        static::__assert_true(
            app()->environmentPath() !== base_path(),
            'and it is deliberately NOT system/, which the framework pull cleans'
        );
    }

    public static function test_environment_file_path_is_the_root_env()
    {
        static::__assert_equals(
            self::_project_root() . '/.env',
            app()->environmentFilePath(),
            'the loaded environment file is the project-root one'
        );
    }

    // -------------------------------------------------------------------------
    // env:decrypt resolves to the RSpade override
    // -------------------------------------------------------------------------

    public static function test_env_decrypt_resolves_to_the_rspade_command()
    {
        $commands = Artisan::all();

        static::__assert_true(isset($commands['env:decrypt']), 'env:decrypt is registered');
        static::__assert_instance_of(
            Env_Decrypt_Command::class,
            $commands['env:decrypt'],
            'the framework override wins by name over the vendor command'
        );
        static::__assert_false($commands['env:decrypt']->isHidden(), 'it is a normal, listed command');
    }

    // -------------------------------------------------------------------------
    // Its default output path, and the --path escape hatch
    // -------------------------------------------------------------------------

    /** Invoke the protected outputFilePath() on a command with the given options applied. */
    private static function _output_file_path(array $options): string
    {
        $command = Artisan::all()['env:decrypt'];

        $definition = $command->getDefinition();
        $input = new \Symfony\Component\Console\Input\ArrayInput($options, $definition);
        $command->setInput($input);

        $method = new ReflectionMethod($command, 'outputFilePath');
        $method->setAccessible(true);

        return (string) $method->invoke($command);
    }

    public static function test_output_file_path_defaults_to_the_root_env()
    {
        static::__assert_equals(
            self::_project_root() . '/.env',
            self::_output_file_path([]),
            'a decrypt with no options writes the project-root .env'
        );
    }

    public static function test_path_option_still_overrides()
    {
        $elsewhere = sys_get_temp_dir() . '/rsx_env_path_' . bin2hex(random_bytes(4));

        static::__assert_equals(
            $elsewhere . '/.env',
            self::_output_file_path(['--path' => $elsewhere]),
            '--path keeps its stock meaning'
        );
    }

    public static function test_filename_option_still_overrides()
    {
        static::__assert_equals(
            self::_project_root() . '/.env.recovered',
            self::_output_file_path(['--filename' => '.env.recovered']),
            '--filename keeps its stock meaning, relative to the new default directory'
        );
    }
}
