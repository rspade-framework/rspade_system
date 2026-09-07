<?php

namespace App\RSpade\Tests\Logrotate\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:logrotate - the on-demand command.
 *
 * Runs against a fixture directory via --directory, which is exactly why that option
 * exists: the command's behavior is testable without the real storage/logs being in
 * the blast radius.
 */
class Log_Rotate_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The command rotates, compresses, prunes, and prints one summary line.
     */
    public static function test_command_rotates_and_summarises()
    {
        $dir = storage_path('rsx-tmp/test-logrotate/cli');

        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                unlink($file);
            }
        } else {
            ensure_directory($dir);
        }

        // Four rotations at 1 plain / 2 retained: .1 plain, .2 gz, and the third
        // generation falls off the end.
        $output = '';

        for ($i = 1; $i <= 4; $i++) {
            file_put_contents($dir . '/app.log', "run {$i}\n");

            Artisan::call('rsx:logrotate', [
                '--directory' => $dir,
                '--days-uncompressed' => 1,
                '--days-retention' => 2,
            ]);

            $output = Artisan::output();
        }

        static::__assert_contains('[OK] Rotated 1 files', $output);
        static::__assert_true(is_file($dir . '/app.log.1'), '.1 stays plain');
        static::__assert_true(is_file($dir . '/app.log.2.gz'), '.2 is compressed');
        static::__assert_false(is_file($dir . '/app.log.3.gz'), 'past retention is deleted');
        static::__assert_equals('', file_get_contents($dir . '/app.log'), 'a fresh empty log is left behind');
    }

    /**
     * --json emits the machine-readable payload and no summary line.
     */
    public static function test_json_output()
    {
        $dir = storage_path('rsx-tmp/test-logrotate/cli-json');

        if (is_dir($dir)) {
            foreach (glob($dir . '/*') as $file) {
                unlink($file);
            }
        } else {
            ensure_directory($dir);
        }

        file_put_contents($dir . '/app.log', "payload\n");

        Artisan::call('rsx:logrotate', [
            '--directory' => $dir,
            '--days-uncompressed' => 1,
            '--days-retention' => 2,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        static::__assert_not_null($payload, 'output should be JSON');
        static::__assert_equals(1, $payload['rotated']);
        static::__assert_equals($dir, $payload['directory']);
        static::__assert_array_has_key('app.log', $payload['files']);
    }
}
