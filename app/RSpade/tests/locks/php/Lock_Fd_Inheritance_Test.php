<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A flock must NOT survive into a spawned helper that outlives us.
 *
 * THE DEFECT (field report, 2026-08-10, reproduced twice hours apart). PHP's fopen() does not set
 * FD_CLOEXEC and PHP exposes no way to set it on a stream, so every child spawned while a
 * lock is held inherits the descriptor. A POSIX flock lives on the OPEN FILE DESCRIPTION,
 * not the process - so when the child is a long-lived daemon (the js-parser and
 * js-transformer RPC servers, or a detached task worker) the lock stays held by an idle
 * process forever, long after the PHP process that took it has exited.
 *
 * Measured signature on the live box: rsx:manifest:build wedged 8 minutes at 0m00s CPU in
 * locks_lock_inode_wait, four php-fpm workers queued behind it identically, and the two node
 * daemons at ep_poll holding the fd. Nobody holding-and-working. Killing the two node
 * processes drained the queue instantly. Because Manifest::init() takes MANIFEST_BUILD at
 * BOOT - before command resolution - a leak makes the whole CLI unusable, not just builds.
 *
 * The fix closes our lock descriptors in the child. These tests assert the mechanism
 * end-to-end against a real spawned process, because the unit under test IS fd inheritance -
 * asserting on the wrapper string alone would prove nothing about what the kernel does.
 */
class Lock_Fd_Inheritance_Test extends Rsx_Test_Abstract
{
    // Real locks + real subprocesses; no database involvement at all.
    protected static $use_database_transactions = false;

    private const PROBE_LOCK = 'FD_INHERIT_PROBE';

    /**
     * The lock we are holding is visible as an fd, and the wrapper names exactly it.
     */
    public static function test_held_lock_is_discoverable_as_an_fd()
    {
        if (!is_dir('/proc/self/fd')) {
            static::__skip('/proc/self/fd is unavailable (non-Linux); fd inheritance is Linux-specific here');

            return;
        }

        $before = RsxLocks::inherited_lock_fds();
        $token = RsxLocks::system_lock(self::PROBE_LOCK);

        try {
            $during = RsxLocks::inherited_lock_fds();
            static::__assert_greater_than(
                count($before),
                count($during),
                'holding a lock must expose at least one more lock fd'
            );

            // The wrapper closes each discovered fd and leaves the command intact.
            $wrapped = RsxLocks::command_without_inherited_locks(['node', 'server.js']);
            static::__assert_equals('bash', $wrapped[0], 'a command is wrapped through bash when fds must be closed');
            static::__assert_contains('>&-', $wrapped[2], 'the wrapper must close descriptors');
            static::__assert_contains('exec "$@"', $wrapped[2], 'the wrapper must exec the real command');
            static::__assert_equals('node', $wrapped[4], 'the original command must survive the wrapping');
            static::__assert_equals('server.js', $wrapped[5], 'the original arguments must survive the wrapping');
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    /**
     * THE ONE THAT MATTERS: a child spawned through the wrapper does not hold our lock.
     */
    public static function test_spawned_child_does_not_inherit_the_lock()
    {
        if (!is_dir('/proc/self/fd')) {
            static::__skip('/proc/self/fd is unavailable (non-Linux)');

            return;
        }
        if (trim((string) shell_exec('bash -c ' . escapeshellarg('command -v fuser 2>/dev/null'))) === '') {
            static::__skip('fuser is unavailable; holder identity cannot be proven without it');

            return;
        }

        $token = RsxLocks::system_lock(self::PROBE_LOCK);
        // The lock file carries the per-database scope now (RsxLocks::__lock_scope_prefix), so
        // fuser MUST inspect the file the lock is actually on - built from the same scope
        // token the lock uses. (The old unscoped spelling is a stale, unheld file; checking it
        // would let this test pass without ever seeing the inherited-fd defect it guards.)
        $lock_path = storage_path('flock/system__' . Rsx_Connection_Scope::token() . '__' . self::PROBE_LOCK . '.lock');
        $process = null;

        try {
            // A child that OUTLIVES this method, exactly like an RPC daemon.
            $process = new Process(RsxLocks::command_without_inherited_locks(['sleep', '30']));
            $process->start();
            $child_pid = $process->getPid();
            static::__assert_not_empty($child_pid, 'the probe child must have started');

            usleep(300000);

            $holders = (string) shell_exec('bash -c ' . escapeshellarg('fuser ' . escapeshellarg($lock_path) . ' 2>/dev/null'));
            $holder_pids = preg_split('/\s+/', trim($holders), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            static::__assert_false(
                in_array((string) $child_pid, $holder_pids, true),
                'a spawned child MUST NOT hold the lock file - an inherited flock outlives us '
                . 'and wedges every future build (holders seen: ' . implode(',', $holder_pids) . ')'
            );
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0);
            }
            RsxLocks::release_lock($token);
        }
    }

    /**
     * The wrapper's shell must survive a MULTI-DIGIT fd close.
     *
     * THE DEFECT (downstream box, 2026-08-13): POSIX guarantees only single-digit fds in a
     * redirection. dash - which is /bin/sh on Debian/Ubuntu - enforces that, so it parses
     * `exec 11>&-` as a command named 11. Once a lock fd landed at 10 or above, every helper
     * spawned under a held lock died with `sh: 1: exec: 11: not found`. A lock fd number is
     * whatever the process happens to have free, so this was luck, not configuration.
     *
     * The shell is read back out of the wrapper rather than hardcoded: the assertion is about
     * what the framework ACTUALLY spawns, not about a name written twice.
     */
    public static function test_wrapper_shell_closes_a_multi_digit_fd()
    {
        if (!is_dir('/proc/self/fd')) {
            static::__skip('/proc/self/fd is unavailable (non-Linux)');

            return;
        }

        $token = RsxLocks::system_lock(self::PROBE_LOCK);
        try {
            $wrapped = RsxLocks::command_without_inherited_locks(['echo', 'OK']);
            $shell = $wrapped[0];
        } finally {
            RsxLocks::release_lock($token);
        }

        static::__assert_equals('bash', $shell, 'the wrapper shell must be bash');

        // The exact shape the wrapper emits, with a two-digit fd forced in.
        $probe = new Process([$shell, '-c', 'exec 11>&-; exec "$@"', $shell, 'echo', 'OK']);
        $probe->run();

        static::__assert_equals(
            0,
            $probe->getExitCode(),
            'the wrapper shell must accept a two-digit fd close (stderr: ' . trim($probe->getErrorOutput()) . ')'
        );
        static::__assert_contains('OK', trim($probe->getOutput()), 'the wrapped command must still run');

        // Counter-proof: the spelling this replaced. Only meaningful where /bin/sh is not bash.
        $identify = new Process(['sh', '-c', 'echo "${BASH_VERSION:-}"']);
        $identify->run();
        if (trim($identify->getOutput()) !== '') {
            static::__pass('/bin/sh is bash on this host; the dash counter-proof is not observable here');

            return;
        }

        $posix = new Process(['sh', '-c', 'exec 11>&-; exec "$@"', 'sh', 'echo', 'OK']);
        $posix->run();

        static::__assert_false(
            $posix->getExitCode() === 0,
            'POSIX sh must be rejected as the wrapper shell - it cannot close a two-digit fd, '
            . 'which is exactly the field failure this wrapper caused'
        );
    }

    /**
     * With no lock held there is nothing to close, and the command is passed through untouched -
     * the wrapper must not impose a shell on every spawn in the framework.
     */
    public static function test_command_is_untouched_when_no_lock_is_held()
    {
        $held = RsxLocks::inherited_lock_fds();
        if ($held) {
            static::__skip('a lock is already held by this process; the passthrough case is not observable here');

            return;
        }

        $command = ['node', 'server.js', '--socket=/tmp/x.sock'];
        static::__assert_equals(
            $command,
            RsxLocks::command_without_inherited_locks($command),
            'with no locks held the command must be returned byte-identical'
        );
        static::__assert_equals(
            '',
            RsxLocks::shell_prefix_without_inherited_locks(),
            'with no locks held the shell prefix must be empty'
        );
    }
}
