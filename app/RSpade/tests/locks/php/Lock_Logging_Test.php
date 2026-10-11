<?php

namespace App\RSpade\Tests\Locks\Php;

use RuntimeException;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RsxLocks::set_lock_logging() / lock_logging_status() against the running rsx-lockd.
 *
 * The daemon side - what each event line says, the ordering rules, the op's validation and
 * its refusal before hello - is proven on a scratch daemon by ../http/lockd_logging.sh. This
 * class is the PHP half: the frames the two calls send, the shape they answer, a refusal
 * surfacing as an exception for a change and never for a query, and the answer during a
 * maintenance window.
 *
 * THE DAEMON IS SHARED, so every test that switches logging puts it back the way it found
 * it. No destination is ever named here: the daemon keeps the log file it has, or opens its
 * own default, which is the behaviour an application gets.
 */
class Lock_Logging_Test extends Rsx_Test_Abstract
{
    // Pure lock behavior - no database needed.
    protected static $use_database_transactions = false;

    private const STATE_KEYS = ['available', 'events', 'verbose', 'path', 'writable', 'write_errors', 'last_error'];

    /**
     * Run $fn, then return the daemon's event switches to what they were.
     */
    private static function __restoring_logging(callable $fn): void
    {
        $before = RsxLocks::lock_logging_status();

        try {
            $fn();
        } finally {
            if ($before['events']) {
                RsxLocks::set_lock_logging(true, null, $before['verbose']);
            } else {
                RsxLocks::set_lock_logging(false);
            }
        }
    }

    /**
     * Whether the daemon's log file comes to contain $needle. The daemon writes through a
     * stream, so a line lands a moment after the call that caused it returns.
     */
    private static function __log_comes_to_contain(string $path, string $needle): bool
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            clearstatcache(true, $path);

            if (is_file($path) && str_contains((string) file_get_contents($path), $needle)) {
                return true;
            }

            usleep(20000);
        }

        return false;
    }

    public static function test_the_status_query_answers_the_whole_state_and_changes_nothing()
    {
        $first = RsxLocks::lock_logging_status();

        static::__assert_equals(self::STATE_KEYS, array_keys($first));
        static::__assert_true($first['available'], 'a running daemon is available');
        static::__assert_true(is_bool($first['events']) && is_bool($first['verbose']) && is_bool($first['writable']));
        static::__assert_true(is_int($first['write_errors']));

        static::__assert_equals($first, RsxLocks::lock_logging_status(), 'a query changes nothing');
    }

    public static function test_logging_is_switched_on_and_off_in_the_running_daemon()
    {
        static::__restoring_logging(function () {
            $on = RsxLocks::set_lock_logging(true);

            static::__assert_true($on['available']);
            static::__assert_true($on['events'], 'recording');
            static::__assert_false($on['verbose'], 'contention only unless asked');
            static::__assert_true(is_string($on['path']) && str_starts_with($on['path'], '/'), 'the daemon names the file it writes');
            static::__assert_true($on['writable']);
            static::__assert_equals($on, RsxLocks::lock_logging_status(), 'the query reads back what the switch answered');

            $off = RsxLocks::set_lock_logging(false);

            static::__assert_false($off['events']);
            static::__assert_false($off['verbose']);
            static::__assert_equals($on['path'], $off['path'], 'switching off keeps the destination');
        });
    }

    public static function test_verbose_records_this_process_taking_and_releasing_a_lock()
    {
        static::__restoring_logging(function () {
            $state = RsxLocks::set_lock_logging(true, null, true);
            static::__assert_true($state['verbose']);

            $name = 'rsxtest_logged_' . getmypid();
            $token = RsxLocks::named_write_lock($name);
            RsxLocks::release_lock($token);

            $me = '[' . gethostname() . ':' . getmypid() . ']';

            static::__assert_true(
                static::__log_comes_to_contain($state['path'], $name . ' by '),
                'the acquire is in the daemon log at ' . $state['path']
            );

            $lines = array_values(array_filter(
                explode("\n", (string) file_get_contents($state['path'])),
                static fn ($line) => str_contains($line, $name)
            ));

            static::__assert_contains('ACQUIRE WRITE cluster:', $lines[0]);
            static::__assert_contains($me, $lines[0], 'the holder is named by host and pid');
            static::__assert_true(
                static::__log_comes_to_contain($state['path'], 'RELEASE WRITE cluster:'),
                'and so is the release'
            );
        });
    }

    public static function test_a_refused_change_throws_and_changes_nothing()
    {
        static::__restoring_logging(function () {
            RsxLocks::set_lock_logging(false);

            static::__assert_throws(
                RuntimeException::class,
                fn () => RsxLocks::set_lock_logging(true, 'relative/lockd.log'),
                'absolute path'
            );

            static::__assert_throws(
                RuntimeException::class,
                fn () => RsxLocks::set_lock_logging(true, '/nonexistent-rsxtest-directory/lockd.log'),
                'Cannot open the log destination'
            );

            static::__assert_false(RsxLocks::lock_logging_status()['events'], 'a refused switch-on left logging off');
        });
    }

    public static function test_switching_off_takes_no_path_and_no_verbosity()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => RsxLocks::set_lock_logging(false, '/tmp/x.log'),
            'takes no path and no verbosity'
        );

        static::__assert_throws(
            RuntimeException::class,
            fn () => RsxLocks::set_lock_logging(false, null, true),
            'takes no path and no verbosity'
        );
    }

    public static function test_a_quiesced_fleet_answers_unavailable_instead_of_throwing()
    {
        Framework_Maintenance::$force_active_for_tests = true;

        try {
            $status = RsxLocks::lock_logging_status();
            $switched = RsxLocks::set_lock_logging(true);
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_equals(['available', 'reason'], array_keys($status));
        static::__assert_false($status['available']);
        static::__assert_contains('maintenance', $status['reason']);
        static::__assert_false($switched['available'], 'a switch during the window is a no-op answer, not an exception');
    }
}
