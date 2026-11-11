<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ssr\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\SSR\Rsx_SSR;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_SSR::_stop_server() signals the pid in ssr-server.pid only while it is still this
 * project's server - its command line names ssr-server.js and `--socket=<this socket>`. Any
 * other process at that pid (the kernel reused it after a crash or a container restart) is
 * left running, and the stale pidfile is removed either way.
 *
 * The tmp tree is a scratch one, so the box's own SSR server is never touched. The stand-in
 * "server" is a node process carrying the same arguments _start_server() gives the real one.
 */
class Rsx_Ssr_Stop_Server_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    /** @var Process[] */
    private static array $processes = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/ssr_stop_server_' . getmypid();
        @mkdir(static::$dir, 0700, true);
        Rsx_Project_Paths::_override(['tmp' => static::$dir]);
    }

    public static function teardown()
    {
        foreach (static::$processes as $process) {
            $process->stop(0);
        }
        static::$processes = [];
        Rsx_Project_Paths::_clear_overrides();
        rmdir_recursive(static::$dir);
    }

    public static function test_an_unrelated_process_at_the_recorded_pid_survives()
    {
        $process = static::__start(['sleep', 'infinity']);

        static::__stop_server_with_pid($process->getPid());

        static::__assert_true($process->isRunning(), 'a process that is not the SSR server is never signalled');
        static::__assert_false(is_file(static::__pid_file()), 'the stale pidfile is removed');
    }

    public static function test_another_projects_server_at_the_recorded_pid_survives()
    {
        $process = static::__start(static::__server_command('/elsewhere/tmp/ssr-server.sock'));

        static::__stop_server_with_pid($process->getPid());

        static::__assert_true($process->isRunning(), 'a server listening on another project\'s socket is never signalled');
        static::__assert_false(is_file(static::__pid_file()), 'the stale pidfile is removed');
    }

    public static function test_this_projects_server_is_stopped()
    {
        $process = static::__start(static::__server_command(Rsx_Project_Paths::ssr_file('ssr-server.sock')));

        static::__stop_server_with_pid($process->getPid());

        $deadline = time() + 120;
        while ($process->isRunning() && time() < $deadline) {
            usleep(100000);
        }

        static::__assert_false($process->isRunning(), 'this project\'s server was never sent SIGTERM');
        static::__assert_equals(SIGTERM, $process->getTermSignal());
        static::__assert_false(is_file(static::__pid_file()), 'the pidfile is removed');
    }

    /** The arguments _start_server() gives the real server, on a node that only idles. */
    private static function __server_command(string $socket_path): array
    {
        return ['node', '-e', 'setInterval(() => {}, 1 << 30)', base_path('bin/ssr-server.js'), '--socket=' . $socket_path];
    }

    /** Start $command and wait until /proc shows its own command line (past the fork). */
    private static function __start(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();
        static::$processes[] = $process;

        $expected = implode("\0", $command);
        $deadline = time() + 120;
        while (time() < $deadline) {
            $cmdline = @file_get_contents('/proc/' . $process->getPid() . '/cmdline');
            if ($cmdline !== false && rtrim($cmdline, "\0") === $expected) {
                return $process;
            }
            usleep(50000);
        }

        shouldnt_happen('the fixture process never showed its own command line: ' . implode(' ', $command));
    }

    private static function __pid_file(): string
    {
        return Rsx_Project_Paths::ssr_file('ssr-server.pid');
    }

    private static function __stop_server_with_pid(int $pid): void
    {
        file_put_contents(static::__pid_file(), (string) $pid);

        $stop = new \ReflectionMethod(Rsx_SSR::class, '_stop_server');
        $stop->invoke(null);
    }
}
