<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the migrate snapshot's waits on MySQL: no deadline, ended by evidence.
 *
 * wait_for_mysql_ready() waits for as long as the mysqld supervisor started is alive and
 * still connecting, and fails at once when that mysqld exits, when supervisor stops reporting
 * it RUNNING, or when MySQL refuses the application's login. wait_for_mysql_stopped() waits
 * for as long as any mysqld remains. Both report MySQL's newest log line every
 * MYSQL_WAIT_PROGRESS_SECONDS.
 *
 * Each test drives the REAL wait through a subclass whose probes (supervisor pid, process
 * identity, the connection probe, the error log, the clock and the pause) are scripted. No
 * process is spawned, nothing sleeps, and no database is touched.
 */
class Mysql_Wait_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * @param array $script  per wait step: ['probe' => [state, detail], 'alive' => bool,
     *                       'supervised' => ?int, 'mysqld' => int[] (for the stop wait)].
     *                       The last step repeats once the script runs out.
     */
    private static function __harness(array $script, ?int $first_supervised = 4242): Maint_Migrate
    {
        return new class($script, $first_supervised) extends Maint_Migrate {
            public array $__script;
            public ?int $__first_supervised;
            public int $__step = 0;
            public int $__clock = 1000;
            public int $__pauses = 0;
            public bool $__first_pid_read = true;
            public array $__said = [];

            public function __construct(array $script, ?int $first_supervised)
            {
                $this->__script = $script;
                $this->__first_supervised = $first_supervised;
            }

            public function __ready(): void
            {
                $this->wait_for_mysql_ready();
            }

            public function __stopped(): void
            {
                $this->wait_for_mysql_stopped();
            }

            private function __current(): array
            {
                return $this->__script[min($this->__step, count($this->__script) - 1)];
            }

            protected function _supervised_mysqld_pid(): ?int
            {
                if ($this->__first_pid_read) {
                    $this->__first_pid_read = false;
                    return $this->__first_supervised;
                }
                return array_key_exists('supervised', $this->__current()) ? $this->__current()['supervised'] : 4242;
            }

            protected function _process_start_time(int $pid): ?int
            {
                return 777;
            }

            protected function _mysqld_is_running(int $pid, ?int $start_time): bool
            {
                return $this->__current()['alive'] ?? true;
            }

            protected function _probe_mysql(): array
            {
                return $this->__current()['probe'] ?? ['starting', 'ERROR 2002 (HY000): Can\'t connect'];
            }

            protected function _running_mysqld_pids(): array
            {
                return $this->__current()['mysqld'] ?? [];
            }

            protected function _mysql_error_log_tail(int $lines): string
            {
                return $lines === 1
                    ? "InnoDB: Apply batch completed, step {$this->__step}"
                    : "InnoDB: Starting crash recovery\n[ERROR] [MY-010119] Aborting";
            }

            protected function shell_exec_privileged(string $command): ?string
            {
                return 'mysql   FATAL   Exited too quickly';
            }

            protected function _pause(): void
            {
                $this->__pauses++;
                $this->__step++;
                $this->__clock += 1;
            }

            protected function _now(): int
            {
                return $this->__clock;
            }

            protected function _say(string $line): void
            {
                $this->__said[] = $line;
            }
        };
    }

    /** A start far longer than any fixed cap succeeds, reporting progress on the way. */
    public static function test_a_slow_start_is_waited_for_however_long_it_takes()
    {
        $script = array_fill(0, 400, ['probe' => ['starting', 'ERROR 2002 (HY000): no socket']]);
        $script[] = ['probe' => ['ready', '']];
        $harness = self::__harness($script);

        $harness->__ready();

        static::__assert_equals(400, $harness->__pauses, 'it waited through every step until MySQL answered');
        static::__assert_true(count($harness->__said) >= 13, 'it reported progress about every 30 seconds over 400 seconds');
        static::__assert_true(str_contains($harness->__said[0], 'InnoDB: Apply batch completed'), 'the progress line carries MySQL\'s newest log line');
    }

    /** The mysqld supervisor started exits: fail at once, with the error log. */
    public static function test_a_mysqld_that_exits_while_starting_fails_at_once()
    {
        $harness = self::__harness([
            ['probe' => ['starting', 'ERROR 2002']],
            ['probe' => ['starting', 'ERROR 2002'], 'alive' => false],
        ]);

        $message = static::__expect_exception(fn () => $harness->__ready());

        static::__assert_equals(1, $harness->__pauses, 'it stopped at the first sign the process was gone');
        static::__assert_true(str_contains($message, 'MySQL exited while starting'), 'it names the startup failure');
        static::__assert_true(str_contains($message, 'Aborting'), 'it carries the tail of the error log');
    }

    /** Supervisor now reports a different pid (it restarted mysqld): fail at once. */
    public static function test_a_restarted_mysqld_is_a_startup_failure()
    {
        $harness = self::__harness([
            ['probe' => ['starting', 'ERROR 2002'], 'supervised' => 5151],
        ]);

        $message = static::__expect_exception(fn () => $harness->__ready());

        static::__assert_equals(0, $harness->__pauses, 'no wait once supervisor is running a different mysqld');
        static::__assert_true(str_contains($message, 'MySQL exited while starting'), 'it names the startup failure');
    }

    /** Supervisor never reached RUNNING: fail at once, naming its status. */
    public static function test_a_mysql_supervisor_never_ran_fails_at_once()
    {
        $harness = self::__harness([['probe' => ['starting', 'ERROR 2002']]], first_supervised: null);

        $message = static::__expect_exception(fn () => $harness->__ready());

        static::__assert_true(str_contains($message, 'supervisor does not report it RUNNING'), 'it names the supervisor state');
        static::__assert_true(str_contains($message, 'FATAL'), 'it carries supervisor\'s status line');
    }

    /** MySQL answers but rejects the login: fail at once - waiting cannot fix it. */
    public static function test_a_refused_login_fails_at_once()
    {
        $harness = self::__harness([
            ['probe' => ['refused', "ERROR 1045 (28000): Access denied for user 'rspade'@'localhost'"]],
        ]);

        $message = static::__expect_exception(fn () => $harness->__ready());

        static::__assert_equals(0, $harness->__pauses, 'no wait on a refusal');
        static::__assert_true(str_contains($message, 'Access denied'), 'it names MySQL\'s answer');
    }

    /** The probe's error text is classified: login refusals versus still-connecting. */
    public static function test_probe_errors_are_classified()
    {
        $harness = self::__harness([['probe' => ['ready', '']]]);
        $classify = \Closure::bind(fn (string $t) => static::_classify_mysql_probe_error($t), $harness, get_class($harness));

        static::__assert_equals('refused', $classify("ERROR 1045 (28000): Access denied for user 'x'@'localhost' (using password: YES)"), '1045');
        static::__assert_equals('refused', $classify("ERROR 1698 (28000): Access denied for user 'root'@'localhost'"), '1698');
        static::__assert_equals('starting', $classify("ERROR 2002 (HY000): Can't connect to local MySQL server through socket"), '2002');
        static::__assert_equals('starting', $classify("ERROR 2003 (HY000): Can't connect to MySQL server on '127.0.0.1:3306' (111)"), '2003');
        static::__assert_equals('starting', $classify('ERROR 2013 (HY000): Lost connection to MySQL server at \'reading initial communication packet\''), '2013');
    }

    /** A long shutdown is waited for, with progress, and ends when no mysqld remains. */
    public static function test_a_slow_stop_is_waited_for_with_progress()
    {
        $script = array_fill(0, 900, ['mysqld' => [4242]]);
        $script[] = ['mysqld' => []];
        $harness = self::__harness($script);

        $harness->__stopped();

        static::__assert_equals(900, $harness->__pauses, 'it waited through a fifteen-minute shutdown');
        static::__assert_true(count($harness->__said) >= 29, 'it reported progress about every 30 seconds');
        static::__assert_true(str_contains($harness->__said[0], 'finish shutting down'), 'the progress line says what it waits for');
    }

    private static function __expect_exception(callable $fn): string
    {
        try {
            $fn();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        static::__assert_true(false, 'an exception was expected');

        return '';
    }
}
