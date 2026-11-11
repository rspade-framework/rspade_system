<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Process\Process_Identity;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx-companiond's read_build_marker(): a manifest build is "in progress" only while the
 * marker (`<pid> <start time>`, written by Manifest::__mark_build_in_progress()) names a
 * process that is still the builder - the same pid AND the same kernel start time. A builder
 * killed outright leaves its marker behind, and once its pid is reused by an unrelated
 * process the start time no longer matches, so a pause still ends.
 *
 * Every marker is a scratch file; the box's own marker (state/.manifest_build_in_progress),
 * which the supervised daemon reads, is never written.
 */
class Companion_Build_Marker_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    private static string $dir = '';

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/companion_build_marker_' . getmypid();
        @mkdir(static::$dir, 0700, true);
    }

    public static function teardown()
    {
        rmdir_recursive(static::$dir);
    }

    public static function test_a_marker_naming_the_live_builder_is_in_progress()
    {
        $pid = getmypid();

        static::__assert_true(static::__in_progress($pid . ' ' . Process_Identity::start_time($pid)), 'the live builder\'s marker');
    }

    public static function test_a_marker_naming_a_reused_pid_is_not_in_progress()
    {
        $pid = getmypid();

        static::__assert_false(static::__in_progress($pid . ' ' . (Process_Identity::start_time($pid) + 1)), 'a live pid with another start time is not the builder');
    }

    public static function test_a_marker_naming_a_dead_pid_is_not_in_progress()
    {
        static::__assert_false(static::__in_progress(self::DEAD_PID . ' 12345'), 'no process at the pid');
    }

    public static function test_a_marker_without_a_start_time_is_not_in_progress()
    {
        static::__assert_false(static::__in_progress((string) getmypid()), 'a bare pid names no process');
    }

    public static function test_no_marker_is_not_in_progress()
    {
        static::__assert_false(static::__in_progress(null), 'no marker file');
    }

    /** read_build_marker() of a scratch marker holding $contents (null = no file). */
    private static function __in_progress(?string $contents): bool
    {
        $file = static::$dir . '/marker';
        @unlink($file);
        if ($contents !== null) {
            file_put_contents($file, $contents);
        }

        $script = 'require(process.argv[1]).read_build_marker(process.argv[2])'
            . '.then((active) => process.stdout.write(active ? "true" : "false"));';
        $process = new Process(['node', '-e', $script, base_path('bin/rsx-companiond/companiond.js'), $file]);
        $process->setTimeout(null);
        $process->run();

        static::__assert_equals(0, $process->getExitCode(), 'node ran: ' . $process->getErrorOutput());

        return $process->getOutput() === 'true';
    }
}
