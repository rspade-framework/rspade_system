<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use RuntimeException;

/**
 * Test helper: a REAL process standing in for the process a RUNNING run names in worker_pid.
 *
 *   start_worker()  a process that READS AS A TASK WORKER of this project - its command line
 *                   names this project's artisan and rsx:task:worker, which is the evidence
 *                   Task::is_worker_process() holds a pool run's pid to. The reaper leaves a
 *                   run under it alone; a kill worker signals it.
 *   start_plain()   `sleep`: any unrelated process - what a pid looks like once the kernel
 *                   has handed it to something else, and what an inline run's php-fpm worker
 *                   looks like to a kill worker (not an artisan process).
 *
 * A worker victim is a php child blocked reading a pipe this process holds, so it lives
 * exactly until it is reaped (or until this process ends). Each start returns once the child
 * has exec'd: until then its command line is still this process's, not the one under test.
 * That wait is a bounded poll on a process this test does not schedule (the house bound,
 * tests/CLAUDE.md), and its failure names the condition never reached.
 */
class Task_Worker_Victim
{
    /** 100 ms polls: 120 s, the house bound. */
    private const POLLS = 1200;

    /** @var array<int, array{process: resource, pipes: array}> pid => its proc_open handle and pipes */
    private static array $victims = [];

    /** A process whose command line is a task worker's. Returns its pid once it reads as one. */
    public static function start_worker(): int
    {
        return static::__start(
            [PHP_BINARY, '-r', 'fgets(STDIN);', '--', base_path('artisan'), 'rsx:task:worker'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            fn (string $cmdline) => str_contains($cmdline, "\0rsx:task:worker\0"),
            'never started reading as a task worker'
        );
    }

    /** An unrelated process (`sleep`). Returns its pid once it is running as sleep. */
    public static function start_plain(): int
    {
        return static::__start(
            ['sleep', '300'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            fn (string $cmdline) => str_starts_with($cmdline, "sleep\0"),
            'never started running sleep'
        );
    }

    /** Running, by its /proc entry (a zombie has an empty command line and is not). */
    public static function running(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && $cmdline !== '';
    }

    /** Kill (if still running) and reap one victim. */
    public static function reap(int $pid): void
    {
        if (!isset(self::$victims[$pid])) {
            return;
        }

        $victim = self::$victims[$pid];
        if (proc_get_status($victim['process'])['running']) {
            posix_kill($pid, SIGKILL);
        }
        foreach ($victim['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($victim['process']);
        unset(self::$victims[$pid]);
    }

    /** Reap every victim still held - a test class's teardown. */
    public static function reap_all(): void
    {
        foreach (array_keys(self::$victims) as $pid) {
            static::reap($pid);
        }
    }

    private static function __start(array $command, array $descriptors, callable $is_ready, string $never): int
    {
        $process = proc_open($command, $descriptors, $pipes);
        $pid = (int) proc_get_status($process)['pid'];
        self::$victims[$pid] = ['process' => $process, 'pipes' => $pipes];

        for ($poll = 0; $poll < self::POLLS; $poll++) {
            if ($is_ready((string) @file_get_contents('/proc/' . $pid . '/cmdline'))) {
                return $pid;
            }
            usleep(100000);
        }

        throw new RuntimeException("the victim process {$pid} {$never}");
    }
}
