<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The container's startup record and the commands around it:
 *
 *   - resource/docker/startup-state.sh (rspade-startup): begin / step / warning / started /
 *     failed write the record; `wait` (rspade-wait-started) blocks while the start runs,
 *     exits 0 when it finished, 1 with the error when it failed, 2 when PID 1 is not the
 *     RSpade entrypoint, and ignores a record from an earlier start (pid1_start);
 *   - bootstrap/rsx_container_starting.php: rsx:build and rsx:clean refuse while the start
 *     runs, except for the start's own processes (descendants of PID 1).
 *
 * Every record is a scratch file (--state-file) and PID 1 a scratch /proc (--proc-root), so
 * the outcome does not depend on what PID 1 of the box running the suite happens to be. The
 * one wait is for an event in another process (a waiter exiting), bounded at 100 times the
 * ~2 s it takes idle; reaching the bound fails naming the event. Nothing asserts a duration.
 */
class Startup_State_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    /** @var int[] waiters this test started, ended at teardown */
    private static array $pids = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/startup_state_' . getmypid();
        @mkdir(static::$dir, 0700, true);
        require_once base_path('bootstrap/rsx_container_starting.php');
    }

    public static function teardown()
    {
        foreach (static::$pids as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            if (str_contains($cmdline, static::$dir)) {
                posix_kill($pid, SIGKILL);
            }
        }
        rmdir_recursive(static::$dir);
    }

    private static function __script(): string
    {
        return base_path('app/RSpade/resource/docker/startup-state.sh');
    }

    /**
     * A scratch /proc: $tree maps pid => [ppid, start time, cmdline]. Fields follow proc(5);
     * the command name holds a space and a parenthesis, as real ones can.
     */
    private static function __proc(string $name, array $tree): string
    {
        $root = static::$dir . "/proc_{$name}";
        foreach ($tree as $pid => [$ppid, $start, $cmdline]) {
            @mkdir("{$root}/{$pid}", 0700, true);
            // Fields 3..21 before the start time (field 22); field 4 is the parent.
            $fields = array_merge(['S', (string) $ppid], array_fill(0, 17, '0'), [(string) $start], ['0', '0']);
            file_put_contents("{$root}/{$pid}/stat", "{$pid} (odd name) x) " . implode(' ', $fields) . "\n");
            file_put_contents("{$root}/{$pid}/cmdline", str_replace(' ', "\0", $cmdline) . "\0");
        }

        return $root;
    }

    /** Run rspade-startup with $args against $record and $proc; answers [exit code, output]. */
    private static function __startup(string $record, string $proc, string $args): array
    {
        $output = [];
        $code = 0;
        // The options AFTER the action, as rspade-wait-started passes them; the background
        // waiter below passes them before it.
        exec_safe(
            'bash ' . escapeshellarg(static::__script())
                . ' ' . $args
                . ' --state-file=' . escapeshellarg($record)
                . ' --proc-root=' . escapeshellarg($proc) . ' 2>&1',
            $output,
            $code
        );

        return [$code, implode("\n", $output)];
    }

    private static function __record(string $record): array
    {
        $values = [];
        foreach (explode("\n", trim((string) @file_get_contents($record))) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[$key] = $value;
        }

        return $values;
    }

    /** An event in another process. Bounded at 100 times its ~2 s idle time; the bound fails naming it. */
    private static function __await(callable $condition, string $what): void
    {
        $deadline = time() + 200;
        while (time() < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(50000);
        }
        static::__fail("{$what} never happened");
    }

    public static function test_the_record_follows_the_start()
    {
        $proc = static::__proc('entry', [1 => [0, 4242, 'bash /usr/local/bin/rspade-entrypoint']]);
        $record = static::$dir . '/record_follows';

        [$code, $output] = static::__startup($record, $proc, 'begin');
        static::__assert_equals(0, $code, $output);
        $values = static::__record($record);
        static::__assert_equals('starting', $values['status']);
        static::__assert_equals('4242', $values['pid1_start'], 'pid1_start is field 22 of PID 1, counted after the last parenthesis');
        static::__assert_true((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $values['began_at']), 'began_at is a UTC time');
        static::__assert_equals('', $values['finished_at']);

        static::__startup($record, $proc, 'step ' . escapeshellarg('waiting for the database'));
        static::__startup($record, $proc, 'warning ' . escapeshellarg("an image\nsentence"));
        $values = static::__record($record);
        static::__assert_equals('waiting for the database', $values['step']);
        static::__assert_equals('an image sentence', $values['warning'], 'a warning is one line');
        static::__assert_equals('starting', $values['status']);

        static::__startup($record, $proc, 'started');
        $values = static::__record($record);
        static::__assert_equals('started', $values['status']);
        static::__assert_equals('waiting for the database', $values['step'], 'the last step is kept');
        static::__assert_not_equals('', $values['finished_at']);
        static::__assert_equals('4242', $values['pid1_start']);
    }

    public static function test_the_waiter_blocks_while_starting_and_returns_on_started()
    {
        $proc = static::__proc('wait', [1 => [0, 5151, 'bash /usr/local/bin/rspade-entrypoint']]);
        $record = static::$dir . '/record_wait';
        $rc_file = static::$dir . '/wait.rc';
        static::__startup($record, $proc, 'begin');
        static::__startup($record, $proc, 'step ' . escapeshellarg('running migrations'));

        $output = [];
        exec_safe(
            'bash -c ' . escapeshellarg(
                'bash ' . escapeshellarg(static::__script()) . ' --state-file=' . escapeshellarg($record)
                    . ' --proc-root=' . escapeshellarg($proc) . ' wait > ' . escapeshellarg(static::$dir . '/wait.log')
                    . ' 2>&1; echo $? > ' . escapeshellarg($rc_file) . '.tmp; mv ' . escapeshellarg($rc_file . '.tmp') . ' ' . escapeshellarg($rc_file)
            ) . ' > /dev/null 2>&1 & echo $!',
            $output
        );
        static::$pids[] = (int) trim(implode('', $output));

        // While starting, the waiter says what the start is doing, and has not returned.
        static::__await(fn () => str_contains((string) @file_get_contents(static::$dir . '/wait.log'), '[rspade] still starting: running migrations'), 'the waiter announcing the step');
        static::__assert_false(file_exists($rc_file), 'the waiter is still waiting while the start runs');

        static::__startup($record, $proc, 'started');

        static::__await(fn () => is_file($rc_file), 'the waiter returning once the start finished');
        static::__assert_equals('0', trim((string) file_get_contents($rc_file)), (string) file_get_contents(static::$dir . '/wait.log'));
        static::__assert_equals(1, substr_count((string) file_get_contents(static::$dir . '/wait.log'), 'still starting'), 'one line per step, not per poll');
    }

    public static function test_a_failed_start_is_exit_1_with_its_error()
    {
        $proc = static::__proc('failed', [1 => [0, 6161, 'bash /usr/local/bin/rspade-entrypoint']]);
        $record = static::$dir . '/record_failed';
        static::__startup($record, $proc, 'begin');
        static::__startup($record, $proc, 'failed ' . escapeshellarg('Database provisioning failed.'));

        static::__assert_equals('failed', static::__record($record)['status']);

        [$code, $output] = static::__startup($record, $proc, 'wait');
        static::__assert_equals(1, $code, $output);
        static::__assert_contains('Database provisioning failed.', $output);
    }

    public static function test_no_rspade_entrypoint_is_exit_2_and_a_stale_record_is_ignored()
    {
        $other = static::__proc('other', [1 => [0, 7171, '/sbin/init']]);
        $record = static::$dir . '/record_other';

        // No record and PID 1 is something else: nothing to wait for.
        [$code, $output] = static::__startup($record, $other, 'wait');
        static::__assert_equals(2, $code, $output);
        static::__assert_equals('This container was not started by the RSpade entrypoint; there is no startup to wait for.', $output);

        // A `started` record from an earlier start (another PID 1 start time) is not this start.
        file_put_contents($record, "status=started\nstep=\npid1_start=1\nbegan_at=\nfinished_at=\nerror=\nwarning=\n");
        [$code, $output] = static::__startup($record, $other, 'wait');
        static::__assert_equals(2, $code, "a stale record is ignored: {$output}");

        // The same record under a container whose start time matches it is current.
        $matching = static::__proc('matching', [1 => [0, 1, '/sbin/init']]);
        [$code, $output] = static::__startup($record, $matching, 'wait');
        static::__assert_equals(0, $code, $output);
    }

    public static function test_build_and_clean_refuse_only_outside_the_start_while_it_runs()
    {
        // PID 1 started at 9090. 40 is a child of PID 1 (the entrypoint's migrate), 50 a
        // grandchild (something supervisor runs); 60 was entered with docker exec (its parent
        // is outside the namespace, 0); 70's chain cannot be read past 71.
        $proc = static::__proc('tree', [
            1 => [0, 9090, 'bash /usr/local/bin/rspade-entrypoint'],
            40 => [1, 100, 'php artisan migrate'],
            50 => [40, 200, 'php artisan rsx:build'],
            60 => [0, 300, 'php artisan rsx:build'],
            70 => [71, 400, 'php artisan rsx:clean'],
        ]);
        $marker = static::$dir . '/marker';
        file_put_contents($marker, "target=prod\n");
        $record = static::$dir . '/record_refuse';
        file_put_contents($record, "status=starting\nstep=running migrations\npid1_start=9090\nbegan_at=2026-11-16T10:00:00Z\nfinished_at=\nerror=\nwarning=\n");

        static::__assert_equals(
            ['step' => 'running migrations', 'began_at' => '2026-11-16T10:00:00Z'],
            rsx_container_starting($record, $marker, $proc, 60),
            'a docker exec process is refused while the container starts'
        );
        static::__assert_not_null(rsx_container_starting($record, $marker, $proc, 70), 'an unreadable chain is not a descent from PID 1');
        static::__assert_null(rsx_container_starting($record, $marker, $proc, 40), 'the entrypoint\'s own child is part of the start');
        static::__assert_null(rsx_container_starting($record, $marker, $proc, 50), 'a grandchild of PID 1 is part of the start');
        static::__assert_null(rsx_container_starting($record, $marker, $proc, 1), 'PID 1 itself is the start');

        // Not refused: another status, an earlier start's record, no record, no marker.
        file_put_contents($record . '_started', str_replace('status=starting', 'status=started', (string) file_get_contents($record)));
        static::__assert_null(rsx_container_starting($record . '_started', $marker, $proc, 60), 'a finished start refuses nothing');
        file_put_contents($record . '_stale', str_replace('pid1_start=9090', 'pid1_start=8080', (string) file_get_contents($record)));
        static::__assert_null(rsx_container_starting($record . '_stale', $marker, $proc, 60), 'an earlier start\'s record refuses nothing');
        static::__assert_null(rsx_container_starting(static::$dir . '/no_record', $marker, $proc, 60), 'no record refuses nothing');
        static::__assert_null(rsx_container_starting($record, static::$dir . '/no_marker', $proc, 60), 'outside the RSpade container nothing is refused');
    }

    public static function test_the_preboot_hook_and_the_image_install()
    {
        $preboot = (string) file_get_contents(base_path('bootstrap/rsx_preboot.php'));
        static::__assert_contains("require_once __DIR__ . '/rsx_container_starting.php';", $preboot);
        static::__assert_contains("in_array(\$_SERVER['argv'][1], ['rsx:build', 'rsx:clean'], true)", $preboot);

        $dockerfile = (string) file_get_contents(base_path('app/RSpade/resource/docker/Dockerfile'));
        $base_stage = substr($dockerfile, 0, (int) strpos($dockerfile, 'FROM base AS dev'));
        static::__assert_contains('COPY startup-state.sh  /usr/local/bin/rspade-startup', $base_stage);
        static::__assert_contains('COPY wait-started.sh   /usr/local/bin/rspade-wait-started', $base_stage);

        $entrypoint = (string) file_get_contents(base_path('app/RSpade/resource/docker/entrypoint.sh'));
        $first_statement = '';
        foreach (explode("\n", $entrypoint) as $line) {
            if (str_starts_with($line, 'startup_record ')) {
                $first_statement = $line;
                break;
            }
        }
        static::__assert_equals('startup_record begin', $first_statement, 'the entrypoint begins the record before its first step');
        static::__assert_true(
            strpos($entrypoint, 'startup_record started') < strpos($entrypoint, 'say "RSpade is up.'),
            'started is recorded before "RSpade is up"'
        );
        static::__assert_true(
            strpos($entrypoint, 'startup_record started') < strpos($entrypoint, '"$@"' . "\n    status=\$?"),
            'started is recorded before a passed command runs'
        );
        static::__assert_contains('die()  { echo "[rspade] ERROR: $*" >&2; startup_record failed "$*"; exit 1; }', $entrypoint);
    }
}
