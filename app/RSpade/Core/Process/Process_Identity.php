<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Process;

/**
 * Process_Identity - which process a recorded pid names, read from /proc on this host.
 *
 * A pid alone does not name a process: the kernel reuses pids, and a pid namespace (a
 * container) starts again from 1 after a restart, so a pid written to a file or a row can
 * outlive its process and come to name an unrelated one. A process is identified by its pid
 * AND its kernel start time (field 22 of /proc/<pid>/stat, clock ticks since boot): record
 * both while the process is known to be the right one, and compare both before trusting or
 * signalling it.
 *
 * An exited process nobody has reaped yet (a zombie) has no start time here: it is gone.
 * A process can exit between two reads, and that race IS the answer "gone".
 */
class Process_Identity
{
    /**
     * The kernel start time of a running process, or null when there is no such process (or
     * it has exited and only its reaping is outstanding).
     */
    public static function start_time(int $pid): ?int
    {
        if ($pid <= 0) {
            return null;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false || $stat === '') {
            return null;
        }

        // Field 2 (comm) is parenthesised and may itself contain spaces and parentheses: the
        // single-space separated fields proper start after its LAST ')'. Index 0 there is the
        // state (field 3), index 19 the start time (field 22).
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }

        $fields = explode(' ', trim(substr($stat, $close + 2)));
        if (count($fields) <= 19 || $fields[0] === 'Z' || $fields[0] === 'X') {
            return null;
        }

        return (int) $fields[19];
    }

    /**
     * Whether $pid is still the process that was recorded with $start_time. A null start time
     * (nothing was recorded) matches no process.
     */
    public static function is_running(int $pid, ?int $start_time): bool
    {
        return $start_time !== null && static::start_time($pid) === $start_time;
    }
}
