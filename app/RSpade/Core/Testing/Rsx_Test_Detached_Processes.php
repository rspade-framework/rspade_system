<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Testing;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Process\Process_Identity;

/**
 * Containment of DETACHED processes started under a test run.
 *
 * Rsx_Artisan::dispatch_detached() returns before its child has done anything - that is
 * what detached means - so a process a test started (a task worker from an opted-in Task::spawn_worker())
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
 * Identity is (pid, kernel start time) - Process_Identity - so a pid the kernel reused for an
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
        $start_time = Process_Identity::start_time($pid);

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
                while (Process_Identity::is_running($pid, $start_time)) {
                    usleep(self::POLL_INTERVAL_US);
                }
            }
        }
    }

    /**
     * Read every registered entry and empty the registry, under its lock.
     *
     * @return array<int, array{0: int, 1: int}>
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

            if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                $entries[] = [(int) $parts[0], (int) $parts[1]];
            }
        }

        return $entries;
    }
}
