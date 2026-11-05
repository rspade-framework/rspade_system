<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rspade-taskd, the container's task scheduler (resource/docker/rspade-taskd/), run for real
 * against a FAKE tick (tests/tasks/resource/fake_tick.js) the test steers through a mode file:
 * a good answer naming the next wake, the maintenance refusal (exit 75), a failure, and a tick
 * held open until the test releases it. Each test starts its own daemon on a scratch socket
 * with short intervals (the test-only `--_*-ms` flags).
 *
 * Every wait is a bounded poll of the condition (the contention principle, tests/CLAUDE.md);
 * the one fixed wait proves a negative over a window the daemon itself defines (its debounce).
 */
class Task_Daemon_Process_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The test-only intervals the daemon runs with. */
    private const DEBOUNCE_MS = 200;

    /** @var array<int,Process> */
    private static array $daemons = [];

    private static function __daemon_js(): string
    {
        return base_path('app/RSpade/resource/docker/rspade-taskd/rspade-taskd.js');
    }

    private static function __in_scratch(callable $test): void
    {
        $root = Rsx_Project_Paths::tmp_path('rspade-taskd-test-' . getmypid());
        rmdir_recursive($root);
        ensure_directory($root);

        $s = ['root' => $root, 'socket' => $root . '/taskd.sock'];
        static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);

        try {
            $test($s);
        } finally {
            foreach (self::$daemons as $daemon) {
                if ($daemon->isRunning()) {
                    @touch($root . '/release');
                    $daemon->signal(SIGTERM);
                    $daemon->wait();
                }
            }
            self::$daemons = [];
            rmdir_recursive($root);
        }
    }

    private static function __mode(array $s, array $mode): void
    {
        file_put_contents($s['root'] . '/mode.json.tmp', json_encode($mode));
        rename($s['root'] . '/mode.json.tmp', $s['root'] . '/mode.json');
    }

    private static function __start(array $s, array $flags = []): Process
    {
        $flags += [
            '_debounce-ms' => (string) self::DEBOUNCE_MS,
            '_startup-retry-ms' => '100',
            '_error-retry-ms' => '300',
            '_maintenance-retry-ms' => '300',
            '_ceiling-ms' => '600000',
        ];

        $command = ['node', static::__daemon_js(),
            '--socket=' . $s['socket'],
            '--tick-command=' . json_encode(['node', base_path('app/RSpade/tests/tasks/resource/fake_tick.js'), $s['root']]),
        ];
        foreach ($flags as $name => $value) {
            $command[] = '--' . $name . '=' . $value;
        }

        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();
        self::$daemons[] = $process;

        static::__poll_until(fn () => static::__status_or_null($s) !== null, 'the daemon answers on its socket (' . $process->getErrorOutput() . ')');

        return $process;
    }

    private static function __ask(array $s, string $line): ?string
    {
        $socket = @stream_socket_client('unix://' . $s['socket']);
        if ($socket === false) {
            return null;
        }
        stream_set_blocking($socket, true);
        fwrite($socket, $line . "\n");
        $answer = fgets($socket);
        fclose($socket);

        return $answer === false ? null : trim($answer);
    }

    private static function __status_or_null(array $s): ?array
    {
        $answer = static::__ask($s, 'status');

        return $answer === null ? null : json_decode($answer, true);
    }

    private static function __status(array $s): array
    {
        $status = static::__status_or_null($s);
        static::__assert_true(is_array($status), 'the daemon answered status');

        return $status;
    }

    private static function __poke(array $s): void
    {
        static::__assert_equals('ok', static::__ask($s, 'poke'), 'the daemon acknowledged the poke');
    }

    private static function __tick_count(array $s): int
    {
        $file = $s['root'] . '/ticks.log';

        return file_exists($file) ? count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    private static function __wait_ticks(array $s, int $count): void
    {
        static::__poll_until(fn () => static::__tick_count($s) >= $count, "the fake tick ran {$count} time(s)");
    }

    /**
     * Poll a condition every 50 ms. The 120 s bound is the house bound on a wait for an external
     * process (tests/CLAUDE.md): it truncates no work, and expiry fails the test naming the
     * condition that was never reached.
     */
    private static function __poll_until(callable $condition, string $condition_description): void
    {
        $deadline = microtime(true) + 120;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                static::__fail('never reached: ' . $condition_description);
            }
            usleep(50000);
        }
    }

    // ------------------------------------------------------------------------------------

    public static function test_it_ticks_at_start_and_again_when_the_tick_says()
    {
        static::__in_scratch(function (array $s) {
            static::__mode($s, ['mode' => 'ok', 'next' => 300, 'hold' => false]);
            $daemon = static::__start($s);

            static::__wait_ticks($s, 3);
            // Polled, not sampled: while a tick runs no wake is armed yet.
            static::__poll_until(fn () => static::__status($s)['next_tick_reason'] === 'due', 'the next wake is the one the tick named');
            static::__assert_contains('ok', (string) static::__status($s)['last_outcome']);
            static::__assert_contains('[rspade-taskd] tick: [WORKER SPAWN] fake narration line', $daemon->getOutput(), "the tick's narration is logged");

            // "none" - nothing waiting: the next wake is the ceiling, and a poke runs a tick.
            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);
            static::__poll_until(fn () => static::__status($s)['next_tick_reason'] === 'ceiling', 'with nothing waiting the daemon sleeps to its ceiling');
            $before = static::__tick_count($s);
            static::__poke($s);
            static::__wait_ticks($s, $before + 1);
        });
    }

    public static function test_pokes_during_a_tick_become_one_follow_up()
    {
        static::__in_scratch(function (array $s) {
            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => true]);
            static::__start($s);

            static::__wait_ticks($s, 1);
            static::__assert_true(static::__status($s)['ticking'], 'the first tick is held open');

            for ($i = 0; $i < 10; $i++) {
                static::__poke($s);
            }
            static::__assert_equals(1, static::__tick_count($s), 'no second tick starts while one runs');

            touch($s['root'] . '/release');
            static::__wait_ticks($s, 2);
            static::__poll_until(fn () => !static::__status($s)['ticking'], 'the follow-up tick finished');

            // The negative, over a window the daemon defines: ten times its debounce, and no
            // third tick - the ten pokes were ONE follow-up.
            usleep(self::DEBOUNCE_MS * 10 * 1000);
            static::__assert_equals(2, static::__tick_count($s), 'ten pokes during a tick are one follow-up tick');
            static::__assert_equals(10, static::__status($s)['poke_count']);
        });
    }

    public static function test_maintenance_waits_and_a_poke_resumes()
    {
        static::__in_scratch(function (array $s) {
            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);
            $daemon = static::__start($s);
            static::__wait_ticks($s, 1);

            static::__mode($s, ['mode' => 'maintenance', 'next' => 'none', 'hold' => false]);
            static::__poke($s);
            static::__poll_until(fn () => static::__status($s)['in_maintenance'] === true, 'exit 75 is read as maintenance');
            static::__assert_equals('maintenance', static::__status($s)['next_tick_reason'], 'maintenance asks again on its own interval');

            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);
            static::__poke($s);
            static::__poll_until(fn () => static::__status($s)['in_maintenance'] === false, 'the tick works again after the window');
            static::__assert_contains('maintenance is over', $daemon->getOutput());
        });
    }

    public static function test_a_failing_tick_is_retried_until_it_works()
    {
        static::__in_scratch(function (array $s) {
            // Before the first good tick a failure is the container still starting: quiet.
            static::__mode($s, ['mode' => 'error', 'next' => 'none', 'hold' => false]);
            $daemon = static::__start($s);
            static::__wait_ticks($s, 3);
            static::__assert_null(static::__status($s)['failing_since'], 'a failure before the first success is not reported');
            static::__assert_false(str_contains($daemon->getOutput(), '[ERROR]'), 'nor logged');

            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);
            static::__poll_until(fn () => str_contains((string) static::__status($s)['last_outcome'], 'ok'), 'the startup retry reaches a good tick');

            // After a success a failure is reported once, and retried on its interval.
            static::__mode($s, ['mode' => 'error', 'next' => 'none', 'hold' => false]);
            static::__poke($s);
            static::__poll_until(fn () => static::__status($s)['failing_since'] !== null, 'a failure after a success is reported');
            static::__assert_contains('[ERROR] the tick failed (exit 1)', $daemon->getOutput());
            static::__assert_contains('the fake tick failed on purpose', $daemon->getOutput(), "the tick's own error is logged");
            static::__assert_equals('error', static::__status($s)['next_tick_reason']);

            static::__mode($s, ['mode' => 'ok', 'next' => 'none', 'hold' => false]);
            static::__poll_until(fn () => static::__status($s)['failing_since'] === null, 'the error retry reaches a good tick');
            static::__assert_contains('the tick is working again', $daemon->getOutput());
        });
    }

    public static function test_the_cli_and_the_socket_lifecycle()
    {
        static::__in_scratch(function (array $s) {
            $daemon = static::__start($s);
            static::__wait_ticks($s, 1);

            $status = new Process(['node', static::__daemon_js(), 'status', '--socket=' . $s['socket']]);
            $status->setTimeout(null);
            $status->run();
            static::__assert_equals(0, $status->getExitCode(), $status->getErrorOutput());
            static::__assert_contains('rspade-taskd - pid ' . $daemon->getPid(), $status->getOutput());
            static::__assert_contains('Next tick:', $status->getOutput());

            $poke = new Process(['node', static::__daemon_js(), 'poke', '--socket=' . $s['socket']]);
            $poke->setTimeout(null);
            $poke->run();
            static::__assert_contains('[OK] poked', $poke->getOutput());

            // A second daemon never steals a live socket.
            $second = new Process(['node', static::__daemon_js(), '--socket=' . $s['socket'], '--tick-command=true']);
            $second->setTimeout(null);
            $second->run();
            static::__assert_equals(1, $second->getExitCode());
            static::__assert_contains('rspade-taskd is already running on ' . $s['socket'], $second->getErrorOutput());

            // SIGTERM: the socket goes with the daemon.
            $daemon->signal(SIGTERM);
            $daemon->wait();
            static::__assert_equals(0, $daemon->getExitCode());
            static::__assert_false(file_exists($s['socket']), 'the socket is removed on stop');

            $missing = new Process(['node', static::__daemon_js(), 'status', '--socket=' . $s['socket']]);
            $missing->setTimeout(null);
            $missing->run();
            static::__assert_equals(1, $missing->getExitCode());
            static::__assert_contains('rspade-taskd is not running', $missing->getErrorOutput());
        });
    }
}
