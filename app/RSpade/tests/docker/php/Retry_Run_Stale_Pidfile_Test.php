<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The supervisor runner (resource/docker/retry-run.sh) removes a STALE pid or lock file
 * before every start of a service: one whose pid is not running, or is running but is not
 * that service - after a host reboot the old pid usually belongs to some other process. A
 * file naming a live copy of the service is kept; it is the only thing that may block a
 * start.
 *
 * Driven through --clear-stale-pidfile (the check for one file) against files in a scratch
 * directory - never a live service's file - and --exec (check, then become the service). The
 * wiring is asserted from the shipped files: the services that keep such files have table
 * entries, and mysql and dockerd start through --exec.
 */
class Retry_Run_Stale_Pidfile_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    private static function __runner(): string
    {
        return base_path('app/RSpade/resource/docker/retry-run.sh');
    }

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/retry_run_pidfile_' . getmypid();
        @mkdir(static::$dir, 0700, true);
    }

    public static function teardown()
    {
        rmdir_recursive(static::$dir);
    }

    /** Run the runner with $args; answers [exit code, output]. */
    private static function __run(string $args): array
    {
        $output = [];
        $code = 0;
        exec_safe('bash ' . escapeshellarg(static::__runner()) . ' ' . $args . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    /** A live process that is not any service: a sleep, killed by the caller. */
    private static function __live_bystander(): int
    {
        $output = [];
        exec_safe('sleep 300 > /dev/null 2>&1 & echo $!', $output);

        return (int) trim(implode('', $output));
    }

    private static function __pidfile(string $name, string $contents): string
    {
        $path = static::$dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private static function __clear(string $file, string $pattern): array
    {
        return static::__run('--clear-stale-pidfile ' . escapeshellarg($file) . ' ' . escapeshellarg($pattern));
    }

    public static function test_a_pidfile_naming_a_live_process_of_another_program_is_removed()
    {
        $pid = static::__live_bystander();

        try {
            $file = static::__pidfile('other.pid', "{$pid}\n");
            [$code, $output] = static::__clear($file, '(^|/)mysqld( |$)');

            static::__assert_equals(0, $code);
            static::__assert_false(file_exists($file), 'removed: the pid is alive but is not the service');
            static::__assert_true(str_contains($output, "pid {$pid} is not this service") && str_contains($output, 'sleep'), "says what the pid is: {$output}");
            static::__assert_true(posix_kill($pid, 0), 'the bystander is left alone');
        } finally {
            posix_kill($pid, SIGTERM);
        }
    }

    public static function test_a_pidfile_naming_a_live_copy_of_the_service_is_kept()
    {
        $pid = static::__live_bystander();

        try {
            $file = static::__pidfile('mine.pid', "{$pid}\n");
            [$code, $output] = static::__clear($file, '(^|/)sleep( |$)');

            static::__assert_equals(0, $code);
            static::__assert_true(file_exists($file), 'kept: the service is running');
            static::__assert_equals('', $output, 'silent when nothing is stale');
        } finally {
            posix_kill($pid, SIGTERM);
        }
    }

    public static function test_a_dead_pid_and_a_file_with_no_pid_are_removed()
    {
        foreach (['dead.pid' => "999999999\n", 'junk.pid' => "not a pid\n", 'empty.pid' => ''] as $name => $contents) {
            $file = static::__pidfile($name, $contents);
            static::__clear($file, 'anything');

            static::__assert_false(file_exists($file), "{$name} is removed");
        }
    }

    public static function test_a_lock_file_holding_a_bare_pid_is_read_like_a_pidfile()
    {
        // mysqld's socket lock files hold the pid with no newline.
        $pid = static::__live_bystander();

        try {
            $file = static::__pidfile('mysqld.sock.lock', (string) $pid);
            static::__clear($file, '(^|/)mysqld( |$)');

            static::__assert_false(file_exists($file), 'a lock naming another program is removed');
        } finally {
            posix_kill($pid, SIGTERM);
        }
    }

    public static function test_the_runners_own_pid_is_never_the_service()
    {
        // The pidfile names the very process doing the check, whose command line matches the
        // pattern - as a reboot can hand the wrapper the old service's pid.
        $file = static::$dir . '/self.pid';
        $output = [];
        exec_safe(
            'bash -c ' . escapeshellarg('echo $$ > ' . escapeshellarg($file) . '; exec bash ' . escapeshellarg(static::__runner())
                . ' --clear-stale-pidfile ' . escapeshellarg($file) . ' retry-run') . ' 2>&1',
            $output
        );

        static::__assert_false(file_exists($file), 'removed: ' . implode(' ', $output));
        static::__assert_true(str_contains(implode(' ', $output), 'is this wrapper'), 'says why');
    }

    public static function test_exec_mode_becomes_the_command()
    {
        [$code, $output] = static::__run('--exec no-such-label bash -c ' . escapeshellarg('echo ran; exit 7'));

        static::__assert_equals(7, $code, 'the command is the process: its exit code is the exit code');
        static::__assert_equals('ran', $output);
    }

    public static function test_the_services_that_keep_pid_files_are_covered_and_mysql_and_dockerd_start_through_exec()
    {
        $runner = (string) file_get_contents(static::__runner());

        foreach (['rsx-lockd' => '/var/run/rsx-lockd.pid', 'nginx' => '/run/nginx.pid', 'mysql' => '/var/run/mysqld/mysqld.sock.lock', 'dockerd' => '/var/run/docker.pid'] as $label => $file) {
            static::__assert_true((bool) preg_match('/^\s+' . preg_quote($label, '/') . '\)\s*$/m', $runner), "the runner has an entry for {$label}");
            static::__assert_true(str_contains($runner, "'{$file}|"), "{$label}'s {$file} is checked");
        }

        $nginx_conf = (string) file_get_contents(base_path('app/RSpade/resource/docker/nginx/nginx.conf'));
        static::__assert_true(str_contains($nginx_conf, 'pid /run/nginx.pid;'), 'the nginx entry is the path nginx.conf declares');

        $mysql = (string) file_get_contents(base_path('app/RSpade/resource/docker/supervisor/conf.d/mysql.conf'));
        static::__assert_true(str_contains($mysql, 'command=bash /usr/local/bin/rspade-retry-run --exec mysql /usr/sbin/mysqld'), 'mysql starts through --exec');

        $dockerd = (string) file_get_contents(base_path('app/RSpade/resource/docker/dockerd-run.sh'));
        static::__assert_true(str_contains($dockerd, 'exec bash /usr/local/bin/rspade-retry-run --exec dockerd dockerd'), 'dockerd starts through --exec');
    }
}
