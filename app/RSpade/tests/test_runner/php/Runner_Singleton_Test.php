<?php

namespace App\RSpade\Tests\TestRunner\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ONE test run per box at a time, whichever path it takes.
 *
 * Two concurrent runs share the test database, the migration dump cache, the relocated
 * file-storage root and (in docker mode) the container names, so the second one has to
 * wait rather than interleave. The guard is a RAW flock on
 * storage/flock/rsx_test_runner.lock, taken as the first statement of handle() - before
 * any service is consulted, and deliberately NOT through RsxLocks, which is granted as a
 * no-op inside a maintenance window.
 *
 * The first assertion is the real proof and it needs no fixture at all: THIS process is a
 * test run, so the runner already holds that lock, and a subprocess must be refused it.
 * The rest proves the mechanism's release half on a scratch file, because releasing the
 * live one would unlock the run that is currently executing.
 */
class Runner_Singleton_Test extends Rsx_Test_Abstract
{
    /**
     * Files and subprocesses - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * Ask a separate process whether it can take an exclusive, non-blocking flock on a
     * path. LOCK_NB is essential: a blocking attempt against a held lock would hang with
     * no deadline, which is the correct production behaviour and a useless assertion.
     *
     * @param string $path
     * @return string 'GOT' or 'BLOCKED'
     */
    protected static function __subprocess_can_lock(string $path): string
    {
        $script = '$h = fopen(' . var_export($path, true) . ', "c");'
            . ' echo flock($h, LOCK_EX | LOCK_NB) ? "GOT" : "BLOCKED";';

        $output = [];
        $exit_code = 0;
        exec_safe('php -r ' . escapeshellarg($script), $output, $exit_code);

        return trim(implode('', $output));
    }

    /**
     * @return string
     */
    protected static function __singleton_lock_path(): string
    {
        return storage_path('flock/rsx_test_runner.lock');
    }

    /**
     * The lock file exists and is HELD right now - because the runner executing this very
     * test took it. A second rsx:test would park on exactly this.
     */
    public static function test_a_second_runner_is_refused_while_this_run_holds_the_lock()
    {
        $path = self::__singleton_lock_path();

        static::__assert_true(is_file($path), 'the runner singleton lock file exists during a run');

        static::__assert_equals(
            'BLOCKED',
            self::__subprocess_can_lock($path),
            'a second process cannot take the runner lock while this run holds it'
        );
    }

    /**
     * The holder writes its pid, so an operator looking at a run that is waiting can see
     * WHICH process it is waiting for.
     */
    public static function test_the_lock_file_names_its_holder()
    {
        $contents = trim((string) file_get_contents(self::__singleton_lock_path()));

        static::__assert_true(
            $contents !== '' && ctype_digit($contents),
            'the lock file holds the holder pid, got: ' . var_export($contents, true)
        );
    }

    /**
     * The release half, on a scratch file with the same open mode the runner uses ('c' -
     * open-or-create WITHOUT truncating, so the pid inside stays the holder's until the
     * lock actually changes hands). Held: refused. Released: granted.
     */
    public static function test_the_lock_is_granted_again_once_released()
    {
        $path = storage_path('flock/rsx_test_runner_fixture_' . getmypid() . '.lock');

        $handle = fopen($path, 'c');
        static::__assert_true($handle !== false, 'the fixture lock file opened');

        try {
            static::__assert_true(flock($handle, LOCK_EX | LOCK_NB), 'this process took the fixture lock');

            static::__assert_equals(
                'BLOCKED',
                self::__subprocess_can_lock($path),
                'a second process is refused while the fixture lock is held'
            );

            flock($handle, LOCK_UN);

            static::__assert_equals(
                'GOT',
                self::__subprocess_can_lock($path),
                'and is granted the moment it is released'
            );
        } finally {
            fclose($handle);
            @unlink($path);
        }
    }
}
