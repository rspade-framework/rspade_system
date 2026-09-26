<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Testing;

use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Containment of DETACHED processes started under a test run.
 *
 * Rsx_Artisan::dispatch_detached() returns before its child has done anything - that is
 * what detached means - so a process a test started (a task worker Task::dispatch() spawned)
 * can outlive the class that started it and act during a LATER class: rebuild the manifest,
 * claim a queue row, write a file. The later class then fails for a reason that is not its
 * own, and only under the orderings where the two overlap.
 *
 * So the harness owns every such process:
 *
 *   - register(): dispatch_detached() records each child it spawns under the suite (any
 *     process carrying --_test-run - the runner, a synchronous child of a test, a detached
 *     child of a detached child) in ONE per-run registry file, before it returns. A
 *     registration therefore always precedes the end of the class that caused it.
 *   - contain(): at every class boundary (Rsx_Test_Abstract::run()) and at the end of the
 *     run (Rsx_Test_Command), the harness WAITS until every registered process has exited,
 *     then empties the registry. It repeats until the registry stays empty, so a detached
 *     process that itself spawned one is waited for too.
 *
 * NO DEADLINE, BY MANDATE. The only detached command the framework spawns, rsx:task:worker,
 * ends on its own - it exits as soon as no pending task remains - so waiting is bounded by
 * the work it was given, and the boundary costs exactly that work. A detached command that
 * never ends by design would hold the boundary open for good: that is a visible hang to
 * diagnose, and the answer is to give that command an end (or to terminate it here,
 * deliberately and for that command), never to cap the wait.
 *
 * Identity is (pid, kernel start time) read from /proc, so a pid the kernel reused for an
 * unrelated process after ours exited is never mistaken for ours; an exited process that
 * nobody has reaped yet (a zombie) counts as exited.
 */
class Rsx_Test_Detached_Processes
{
    /**
     * Interval between two looks at a registered process that is still running. A process
     * that is not our child cannot be waitpid()'d, so the wait is a poll of the condition
     * with no deadline: it returns the moment the process is gone.
     */
    private const POLL_INTERVAL_US = 50000;

    /**
     * Record a detached process a test run started.
     *
     * A process already gone by the time it is recorded needs no containment and is not
     * written.
     */
    public static function register(int $pid): void
    {
        $start_time = self::__start_time($pid);

        if ($start_time === null) {
            return;
        }

        $path = Rsx_Project_Paths::test_detached_registry_file();
        ensure_directory(dirname($path));

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Could not open the detached-process registry {$path}");
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $pid . ' ' . $start_time . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Wait until every registered detached process has exited, then leave the registry empty.
     */
    public static function contain(): void
    {
        while (true) {
            $entries = self::__take_entries();

            if (empty($entries)) {
                return;
            }

            foreach ($entries as [$pid, $start_time]) {
                while (self::__is_running($pid, $start_time)) {
                    usleep(self::POLL_INTERVAL_US);
                }
            }
        }
    }

    /**
     * Read every registered entry and empty the registry, under its lock.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private static function __take_entries(): array
    {
        $path = Rsx_Project_Paths::test_detached_registry_file();

        if (!file_exists($path)) {
            return [];
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException("Could not open the detached-process registry {$path}");
        }

        try {
            flock($handle, LOCK_EX);
            $contents = stream_get_contents($handle);
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $entries = [];

        foreach (explode("\n", (string) $contents) as $line) {
            $parts = explode(' ', trim($line));

            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $entries[] = [(int) $parts[0], $parts[1]];
            }
        }

        return $entries;
    }

    /**
     * Is the process with this pid AND this start time still running?
     */
    private static function __is_running(int $pid, string $start_time): bool
    {
        $stat = self::__stat_fields($pid);

        if ($stat === null) {
            return false;
        }

        // A zombie (Z) or a dead entry (X) has exited; only its reaping is outstanding.
        if ($stat[0] === 'Z' || $stat[0] === 'X') {
            return false;
        }

        return $stat[19] === $start_time;
    }

    /**
     * The kernel start time of a process, or null when there is no such process.
     */
    private static function __start_time(int $pid): ?string
    {
        $stat = self::__stat_fields($pid);

        return $stat === null ? null : $stat[19];
    }

    /**
     * The fields of /proc/<pid>/stat AFTER the command name: index 0 is the state (field 3),
     * index 19 the start time (field 22). The command name is parenthesised and may itself
     * contain spaces and parentheses, so the split starts after its LAST closing parenthesis.
     *
     * @return array<int, string>|null Null when the process does not exist
     */
    private static function __stat_fields(int $pid): ?array
    {
        $raw = @file_get_contents('/proc/' . $pid . '/stat');

        if ($raw === false || $raw === '') {
            return null;
        }

        $close = strrpos($raw, ')');
        if ($close === false) {
            return null;
        }

        $fields = explode(' ', trim(substr($raw, $close + 2)));

        return count($fields) > 19 ? $fields : null;
    }
}
