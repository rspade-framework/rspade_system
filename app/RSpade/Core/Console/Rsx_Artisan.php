<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Console;

use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;

/**
 * THE sanctioned way for PHP code to invoke `php artisan` as a SUBPROCESS.
 *
 * Nothing in the framework or an app should build that command line by hand any more.
 * `ARTISAN-SPAWN-01` (rsx:check) flags the old spellings - passthru/exec_safe/shell_exec/
 * proc_open/system/popen with `artisan` in the command - and names the method to use.
 *
 * ======================================================================================
 * WHY THIS EXISTS: LOCK INHERITANCE
 * ======================================================================================
 *
 * A cluster lock belongs to a CONNECTION, and a subprocess is a different process with a
 * different connection. So a parent holding cluster:SITE_1 that shells out to an artisan
 * command which also writes to that site produced this:
 *
 *     parent  HELD     WRITE cluster:SITE_1        <- and blocked in waitpid on the child
 *     child   WAITING  WRITE cluster:SITE_1        <- queued behind its own parent
 *
 * Neither could ever move. It is invisible to rsx-lockd's deadlock detector, because the
 * parent's half of the cycle is an OS wait, not a lock wait. It wedged a framework test
 * run for twelve hours on 2026-08-11 before this class existed, and the shape was live in
 * sixteen framework call sites.
 *
 * The synchronous methods here attach `--_lock-group=<id>` (see Lockd_Client::
 * current_group_id()), which makes the child a member of the parent's lock group: it
 * inherits what the group already holds instead of queueing behind it.
 *
 * ======================================================================================
 * SYNCHRONOUS PROPAGATES, ASYNCHRONOUS DOES NOT
 * ======================================================================================
 *
 * Inheritance is only correct when the parent is WAITING. Two processes holding one lock
 * while both believe they are exclusive is precisely the failure the lock daemon exists to
 * prevent - so:
 *
 *   - passthru() and run() are synchronous: the parent blocks until the child exits, so
 *     they ALWAYS propagate. There is no way to turn it off, because there is no case
 *     where a blocked parent wants its child to deadlock against it.
 *   - dispatch_detached() does not wait for its child, so it does NOT propagate by default. The
 *     opt-in is spelled $propagate_locks_and_i_will_wait for a reason: passing true is a
 *     PROMISE that the caller will wait for this process before continuing its own
 *     critical section (an orchestrator running several children in parallel and joining
 *     them). If you pass it and do not wait, you have silently disabled mutual exclusion.
 *
 * NOT for in-process work. `Artisan::call()` runs in THIS process on THIS connection and
 * is already reentrant - it needs nothing from this class and the lint rule ignores it.
 */
class Rsx_Artisan
{
    /**
     * Run an artisan command with its output streamed straight through to our own
     * stdout/stderr, and return its exit code. The passthru() replacement.
     *
     * Use when the child's output IS the user-facing output (a build, a migration) - the
     * caller sees progress live instead of one buffered dump at the end.
     *
     * @param string $command Artisan command name, e.g. 'rsx:manifest:build'
     * @param array<int, string> $args Whole argv tokens, e.g. ['--force', '--_no-system-reset']
     * @return int The child's exit code
     */
    public static function passthru(string $command, array $args = []): int
    {
        $exit_code = 0;
        // Explicit bash - passthru() would otherwise hand the line to /bin/sh (dash here).
        \passthru('bash -c ' . escapeshellarg(self::__command_line($command, $args, true)), $exit_code);

        return $exit_code;
    }

    /**
     * Run an artisan command and CAPTURE its output. The exec_safe() replacement.
     *
     * $env sets ENVIRONMENT facts for the child - which database, which mode - and never
     * invocation intent, which rides as an argv flag (standing owner ruling). Passing
     * `['DB_DATABASE' => 'rspade_test']` is right; passing `['RSX_FORCE' => '1']` is not.
     *
     * @param string $command Artisan command name
     * @param array<int, string> $args Whole argv tokens
     * @param array<int, string> $output Populated with the output lines, by reference
     * @param array<string, string> $env Extra environment merged OVER our own
     * @return int The child's exit code, or -1 when the process could not be started
     */
    public static function run(string $command, array $args = [], array &$output = [], array $env = []): int
    {
        $exit_code = 0;
        exec_safe(self::__command_line($command, $args, true), $output, $exit_code, $env);

        return $exit_code;
    }

    /**
     * Spawn an artisan command fully detached and return as soon as its pid is known. Output
     * is discarded and the child is reparented to init at once (its parent is a shell that
     * exits after the handshake).
     *
     * NOT WAIT-FREE, AND EXACTLY THIS MUCH: the pid comes back over a short synchronous
     * handshake - start bash, bash forks the child and prints its pid, bash exits. The
     * caller blocks for that and for nothing the child does: not its scheduling, not its
     * boot, not its work. On a lightly loaded host the handshake is a few milliseconds; on a
     * saturated one it is as slow as starting any process there. Starting many detached
     * processes is therefore never free, which is why Task::spawn_worker() refuses before
     * calling this whenever the pool is already full.
     *
     * Under the test suite the child is registered with Rsx_Test_Detached_Processes, and
     * the harness waits for it to exit at the end of the test class that started it.
     *
     * Locks do NOT propagate unless the caller promises to wait - read the class docblock
     * before passing true.
     *
     * @param string $command Artisan command name
     * @param array<int, string> $args Whole argv tokens
     * @param bool $propagate_locks_and_i_will_wait Only true when this caller genuinely
     *        blocks on the spawned process before continuing its own critical section.
     * @return int|null The child's pid, or null when the shell reported none (nothing was
     *        started). Under the test suite a missing pid throws instead.
     */
    public static function dispatch_detached(
        string $command,
        array $args = [],
        bool $propagate_locks_and_i_will_wait = false
    ): ?int {
        $command_line = self::__command_line($command, $args, $propagate_locks_and_i_will_wait);

        // Close our flock descriptors FIRST. A detached child is long-lived by definition
        // (a task worker runs as long as there is work), and a POSIX flock lives on the open
        // file description - so a lock fd inherited here would be held for that worker's
        // entire life, wedging every build on the box. Same defect class as the js-parser
        // daemons; see RsxLocks::inherited_lock_fds().
        $close_locks = \App\RSpade\Core\Locks\RsxLocks::shell_prefix_without_inherited_locks();

        // The redirect detaches the child's I/O and the trailing '&' backgrounds it; `echo $!`
        // prints the child's pid - a backgrounded simple command is forked and exec'd
        // directly, so $! IS the php process.
        //
        // WHAT THE CALLER WAITS FOR: the pid handshake and nothing else - bash starting, its
        // fork, the echo, and bash exiting (pclose() reaps the shell, never the child). The
        // pid line is read with ONE fgets() rather than to end-of-file: the forked child holds
        // the pipe from its fork until its own `> /dev/null` redirect takes effect, so a read
        // to EOF would also wait for the kernel to schedule the CHILD - on a saturated host
        // that is the wait that turns a busy box into a stalled parent.
        //
        // Explicit `bash -c`: popen() runs /bin/sh, which is dash on Debian/Ubuntu, and
        // dash rejects the multi-digit fd redirections in $close_locks (POSIX guarantees only
        // single-digit fds; `exec 11>&-` parses there as a command named 11).
        $pid = '';
        $shell = popen('bash -c ' . escapeshellarg($close_locks . $command_line . ' > /dev/null 2>&1 & echo $!'), 'r');
        if ($shell !== false) {
            stream_set_blocking($shell, true);
            $line = fgets($shell);
            pclose($shell);
            $pid = trim((string) $line);
        }

        // Under the suite the harness owns every detached child: it is registered BEFORE this
        // returns, and the class boundary waits for it to exit (Rsx_Test_Detached_Processes).
        if (Rsx_Test_Abstract::suite_is_running()) {
            if (!ctype_digit($pid)) {
                throw new \RuntimeException(
                    "dispatch_detached({$command}) under the test suite did not report the child's pid"
                );
            }

            Rsx_Test_Detached_Processes::register((int) $pid);
        }

        return ctype_digit($pid) ? (int) $pid : null;
    }

    /**
     * Build the full shell command line.
     *
     * Two things this must get right, both of which have caused field regressions:
     *
     *   - ABSOLUTE artisan path. A bare `php artisan` resolves against the CALLER's cwd,
     *     which may be inside system/ or a directory with no ./artisan at all - the
     *     command then runs the wrong artisan, or nothing.
     *   - The right PHP BINARY. PHP_BINARY is the RUNNING SAPI's executable; under
     *     php-fpm that is the fpm binary, which cannot run a script. A web-triggered
     *     spawn would silently no-op. Only trust PHP_BINARY when we are the CLI.
     *
     * @param array<int, string> $args
     */
    private static function __command_line(string $command, array $args, bool $propagate_locks): string
    {
        $php = (\PHP_SAPI === 'cli') ? PHP_BINARY : 'php';

        $parts = [
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($command),
        ];

        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }

        if ($propagate_locks) {
            $parts[] = escapeshellarg(Lockd_Client::LOCK_GROUP_FLAG . '=' . Lockd_Client::current_group_id());
        }

        // A child of the test suite is part of the test suite - synchronous or detached, a
        // process a test started is under the test. rsx:test declares the flag on itself;
        // this carries it down so a test-spawned command booting in another mode is not
        // stopped by the https guardrail meant for real deployments (Rsx_App_Url).
        if (Rsx_Test_Abstract::suite_is_running()) {
            $parts[] = escapeshellarg(Rsx_Test_Abstract::TEST_RUN_FLAG);
        }

        return implode(' ', $parts);
    }
}
