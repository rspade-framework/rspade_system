<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Health_Checks;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Run_Outcome;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Support\Process\Process_Identity;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Worker_Victim;

/**
 * THE VANISHED HOST: a RUNNING run on another host that rsx-lockd cannot answer for (claimed
 * under an older generation, or run inline) is settled ABANDONED by any host's rsx:task:process
 * once its latest evidence of life - started_at, last_heartbeat_at, last_report_at, and the
 * worker_seen_at vouch its own host's tick stamps - is older than
 * rsx.tasks.vanished_host_timeout. A scheduled or inline run is FAILED (a schedule counts the
 * failure and runs again at its cadence); a dispatched run returns to PENDING with its paced
 * retry. A run inside the bound, one that reports, and one its host vouches for are untouched -
 * whatever their age, their explicit timeout, or the silence limit.
 *
 * THE VOUCH: a tick stamps worker_seen_at on every run it found alive on its own host, and on
 * nothing else.
 *
 * LATE WRITES: a process whose run was settled as abandoned writes nothing more onto it - the
 * settle and the reports are guarded on the claim - and says so once (a log error and an
 * operator line); is_stop_requested() answers true for it.
 *
 * Also: the Task Worker Pools health row, and rspade-taskd's idle ceiling of one hour.
 *
 * Every age is a STORED timestamp ("seconds ago") against a bound set by config (600 s), with
 * margins of hundreds of seconds: nothing here sleeps or measures elapsed time.
 *
 * Commits real rows, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Vanished_Host_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    const OTHER_HOST = 'task-vanished-host-test.other-host.invalid';

    /** rsx.tasks.vanished_host_timeout for every test here. */
    const BOUND = 600;

    /** @var string[] Laravel log messages captured since the listener was registered. */
    private static array $logged = [];
    private static bool $listening = false;

    public static function teardown()
    {
        Task_Worker_Victim::reap_all();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function __reset(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_tasks')->where('class', self::FIX)->delete();
    }

    /** A stored moment $seconds ago. */
    private static function __ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() - $seconds) . '.000';
    }

    /** A generation that is not the daemon's own. */
    private static function __older_generation(): int
    {
        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];

        return $current === 1 ? 2 : $current - 1;
    }

    /**
     * A RUNNING run on OTHER_HOST that rsx-lockd cannot answer for: a pool run of an older
     * generation, or ($inline) a run with no worker_id. $evidence: started_at /
     * last_heartbeat_at / last_report_at / worker_seen_at as seconds ago.
     */
    private static function __foreign_run(array $evidence, array $fields = [], bool $inline = false): int
    {
        $row = array_merge(Task_Runner::running_fields(), [
            'worker_host' => self::OTHER_HOST,
            'worker_pid' => self::DEAD_PID,
            'worker_pid_start_time' => 12345,
            'worker_id' => $inline ? null : 41,
            'worker_generation' => $inline ? null : static::__older_generation(),
            'pool_id' => $inline ? null : Task_Run_Model::POOL_ON_DEMAND,
        ]);
        foreach ($evidence as $column => $seconds_ago) {
            $row[$column] = static::__ago($seconds_ago);
        }
        $origin_id = $fields['origin_id'] ?? ($inline ? Task_Run_Model::ORIGIN_INLINE : Task_Run_Model::ORIGIN_DISPATCHED);
        unset($fields['origin_id']);

        return Task_Runner::insert_row(self::FIX, 'marker_a', [], $origin_id, array_merge($row, $fields));
    }

    /** Run $test with rsx.tasks.<$key> set to $value. */
    private static function __with_config(string $key, $value, callable $test): void
    {
        $original = config("rsx.tasks.{$key}");
        config(["rsx.tasks.{$key}" => $value]);
        try {
            $test();
        } finally {
            config(["rsx.tasks.{$key}" => $original]);
        }
    }

    private static function __with_bound(callable $test): void
    {
        static::__with_config('vanished_host_timeout', self::BOUND, $test);
    }

    private static function __tick(): string
    {
        Artisan::call('rsx:task:process');

        return Artisan::output();
    }

    private static function __row(int $id): object
    {
        return DB::table('_tasks')->where('id', $id)->first();
    }

    private static function __operator_lines(int $id): array
    {
        return DB::table('_task_output')->where('task_id', $id)->where('stream_id', Task_Run_Model::STREAM_OPERATOR)->orderBy('id')->pluck('line')->all();
    }

    /** Start capturing Laravel log messages (once per process) and forget what was captured. */
    private static function __capture_log(): void
    {
        if (!self::$listening) {
            Event::listen(MessageLogged::class, function (MessageLogged $event) {
                self::$logged[] = $event->level . ': ' . $event->message;
            });
            self::$listening = true;
        }
        self::$logged = [];
    }

    // -------------------------------------------------------------------------
    // The rule
    // -------------------------------------------------------------------------

    /**
     * A scheduled run whose host vanished is FAILED by another host's tick, counted on its
     * schedule, and the schedule keeps its next run: it runs again at its cadence. Uses a real
     * manifest #[Schedule], which the same tick's reconciliation keeps.
     */
    public static function test_a_scheduled_run_on_a_vanished_host_is_failed_and_counted()
    {
        static::__reset();

        $scheduled = Task::get_scheduled_tasks();
        if (empty($scheduled)) {
            static::__skip('No manifest #[Schedule] task available to stage a scheduled run');
        }
        $declaration = $scheduled[0];

        static::__tick();
        $schedule = DB::table('_task_schedules')->where('class', $declaration['class'])->where('method', $declaration['method'])->first();
        DB::table('_task_schedules')->where('id', $schedule->id)->update(['consecutive_failures' => 0]);
        $next_run_at = DB::table('_task_schedules')->where('id', $schedule->id)->value('next_run_at');

        static::__with_bound(function () use ($schedule, $next_run_at) {
            $id = static::__foreign_run(['started_at' => 700], [
                'origin_id' => Task_Run_Model::ORIGIN_SCHEDULED,
                'schedule_id' => $schedule->id,
                'pool_id' => Task_Run_Model::POOL_SCHEDULED,
            ]);

            $output = static::__tick();

            $row = static::__row($id);
            static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'a scheduled run is failed, never retried');
            static::__assert_contains('from its host ' . self::OTHER_HOST, (string) $row->status_reason);
            static::__assert_contains('rsx.tasks.vanished_host_timeout ' . self::BOUND . 's', (string) $row->status_reason);
            static::__assert_contains('[ABANDONED TASK]', $output);

            $after = DB::table('_task_schedules')->where('id', $schedule->id)->first();
            static::__assert_equals(1, (int) $after->consecutive_failures, 'the failure is counted on the schedule');
            static::__assert_equals($next_run_at, $after->next_run_at, 'the schedule keeps its next run: it runs again at its cadence');
        });

        static::__reset();
    }

    /** A dispatched run whose host vanished goes back to PENDING with its paced retry. */
    public static function test_a_dispatched_run_on_a_vanished_host_is_retried()
    {
        static::__reset();

        static::__with_bound(function () {
            $id = static::__foreign_run(['started_at' => 900, 'last_report_at' => 700, 'worker_seen_at' => 750]);

            static::__tick();

            $row = static::__row($id);
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id, 'a dispatched run is retried');
            static::__assert_equals(1, (int) $row->abandon_count);
            static::__assert_true(strtotime($row->scheduled_for) > time(), 'the retry is paced into the future');
            static::__assert_contains('retry as attempt 2', (string) $row->status_reason);
            foreach (['worker_host', 'worker_pid', 'worker_pid_start_time', 'worker_id', 'worker_generation', 'worker_seen_at', 'started_at'] as $column) {
                static::__assert_null($row->{$column}, "{$column} is cleared with the claim");
            }
        });

        static::__reset();
    }

    /**
     * Evidence inside the bound keeps a run RUNNING, however old the run: silent for less than
     * the bound, a long run that heartbeats, a long run that reports, and a long run whose own
     * stamps are old but whose host vouched for it recently.
     */
    public static function test_evidence_inside_the_bound_keeps_a_run()
    {
        static::__reset();

        static::__with_bound(function () {
            $kept = [
                'silent for less than the bound' => static::__foreign_run(['started_at' => 300]),
                'a long run that heartbeats' => static::__foreign_run(['started_at' => 10000, 'last_heartbeat_at' => 30]),
                'a long run that reports' => static::__foreign_run(['started_at' => 10000, 'last_report_at' => 30]),
                'a long run its host vouches for' => static::__foreign_run(['started_at' => 10000, 'last_heartbeat_at' => 9000, 'last_report_at' => 9000, 'worker_seen_at' => 30]),
            ];

            static::__tick();

            foreach ($kept as $label => $id) {
                static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($id)->status_id, "{$label}: untouched");
            }
        });

        static::__reset();
    }

    /** An inline run on another host: FAILED once silent past the bound, untouched while vouched for. */
    public static function test_an_inline_run_on_a_vanished_host()
    {
        static::__reset();

        static::__with_bound(function () {
            $stale = static::__foreign_run(['started_at' => 5000, 'worker_seen_at' => 700], [], true);
            $vouched = static::__foreign_run(['started_at' => 5000, 'worker_seen_at' => 30], [], true);

            static::__tick();

            $row = static::__row($stale);
            static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'a stale inline run is failed: its caller is gone');
            static::__assert_contains('rsx.tasks.vanished_host_timeout', (string) $row->error);
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($vouched)->status_id, 'a vouched-for inline run is untouched');
        });

        static::__reset();
    }

    /**
     * A run long past its own explicit timeout is its host's to kill while that host vouches
     * for it, and settled by the vanished-host rule once it does not.
     */
    public static function test_an_explicit_timeout_run_is_judged_by_its_hosts_vouch()
    {
        static::__reset();

        static::__with_bound(function () {
            $vouched = static::__foreign_run(['started_at' => 10000, 'worker_seen_at' => 30], ['timeout' => 60]);
            $stale = static::__foreign_run(['started_at' => 10000, 'worker_seen_at' => 700], ['timeout' => 60]);

            static::__tick();

            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($vouched)->status_id, 'vouched for: left to its own host');
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) static::__row($stale)->status_id, 'not vouched for: abandoned and retried');
        });

        static::__reset();
    }

    /** With no silence limit (silent_timeout = 0) the vanished-host rule reads the same. */
    public static function test_no_silence_limit_changes_nothing()
    {
        static::__reset();

        static::__with_config('silent_timeout', 0, function () {
            static::__with_bound(function () {
                $vouched = static::__foreign_run(['started_at' => 10000, 'worker_seen_at' => 30]);
                $stale = static::__foreign_run(['started_at' => 10000, 'worker_seen_at' => 700]);

                static::__tick();

                static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($vouched)->status_id);
                static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) static::__row($stale)->status_id);
            });
        });

        static::__reset();
    }

    /** The bound has no "off": anything but an integer >= 1 makes the tick throw. */
    public static function test_an_invalid_bound_throws()
    {
        static::__reset();

        foreach ([0, -1, '600', null] as $bad) {
            static::__with_config('vanished_host_timeout', $bad, function () use ($bad) {
                $thrown = null;
                try {
                    static::__tick();
                } catch (\RuntimeException $e) {
                    $thrown = $e->getMessage();
                }

                static::__assert_contains('rsx.tasks.vanished_host_timeout must be an integer >= 1', (string) $thrown, 'refused: ' . var_export($bad, true));
            });
        }
    }

    // -------------------------------------------------------------------------
    // The vouch
    // -------------------------------------------------------------------------

    /**
     * A tick stamps worker_seen_at on the runs it found alive on its own host - an
     * older-generation pool run whose worker is still a task worker, an inline run of a live
     * process - and on nothing else.
     */
    public static function test_a_tick_vouches_only_for_its_own_live_runs()
    {
        static::__reset();

        $pid = Task_Worker_Victim::start_worker();
        try {
            static::__with_bound(function () use ($pid) {
                $local_pool = Task_Runner::insert_row(self::FIX, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
                    'worker_pid' => $pid,
                    'worker_pid_start_time' => Process_Identity::start_time($pid),
                    'worker_id' => 42,
                    'worker_generation' => static::__older_generation(),
                    'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
                ]));
                $local_inline = Task_Runner::insert_row(self::FIX, 'marker_b', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
                $foreign = static::__foreign_run(['started_at' => 30]);

                static::__tick();

                static::__assert_not_null(static::__row($local_pool)->worker_seen_at, 'this host vouches for its live pool run');
                static::__assert_not_null(static::__row($local_inline)->worker_seen_at, 'this host vouches for its live inline run');
                static::__assert_null(static::__row($foreign)->worker_seen_at, 'never for another host\'s run');
                static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($foreign)->status_id);
            });
        } finally {
            Task_Worker_Victim::reap($pid);
            static::__reset();
        }
    }

    // -------------------------------------------------------------------------
    // Late writes from the process a run was taken from
    // -------------------------------------------------------------------------

    /**
     * The process a dispatched run was taken from finishes after the retry was claimed by
     * another worker: its settle matches nothing, the retry's row is as the new worker left it,
     * and the refusal is logged and noted on the run. The same for a run the reaper FAILED,
     * whose row still names the old process.
     */
    public static function test_a_late_settle_never_overwrites_the_reapers_verdict()
    {
        static::__reset();
        static::__capture_log();

        static::__with_bound(function () {
            // Dispatched: re-pended by the tick, then re-claimed by another worker.
            $id = static::__foreign_run(['started_at' => 700]);
            $late = Task_Instance::find($id);

            static::__tick();
            DB::table('_tasks')->where('id', $id)->update(array_merge(Task_Runner::running_fields(), [
                'worker_host' => 'task-vanished-host-test.retry-host.invalid',
                'worker_pid' => self::DEAD_PID - 1,
                'worker_pid_start_time' => 999,
            ]));
            $before = static::__row($id);

            static::__assert_false(Task_Runner::settle($late, Task_Run_Outcome::from_return(null)), 'the late settle is refused');

            $after = static::__row($id);
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) $after->status_id, 'the retry keeps running');
            static::__assert_equals($before->worker_pid, $after->worker_pid, 'under its new worker');
            static::__assert_null($after->completed_at);
            static::__assert_true($late->is_superseded());
            static::__assert_contains('its outcome was not recorded (it completed)', implode("\n", static::__operator_lines($id)));
            static::__assert_contains('the run was settled as abandoned while its process', implode("\n", self::$logged));
            static::__assert_contains("[TASK #{$id}]", implode("\n", self::$logged));

            // Inline: FAILED by the tick, its row still naming the old process.
            $failed = static::__foreign_run(['started_at' => 700], [], true);
            $late = Task_Instance::find($failed);
            static::__tick();
            $error = static::__row($failed)->error;

            static::__assert_false(Task_Runner::settle($late, Task_Run_Outcome::from_return(null)), 'refused on a FAILED row too');
            static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) static::__row($failed)->status_id, 'the reaper\'s verdict stands');
            static::__assert_equals($error, static::__row($failed)->error);
            static::__assert_contains('its outcome was not recorded', implode("\n", static::__operator_lines($failed)));
        });

        static::__reset();
    }

    /**
     * Reports from the process a run was taken from are not written: the first refused write
     * leaves one operator line, later ones are dropped silently, and is_stop_requested()
     * answers true so a cooperative task stops.
     */
    public static function test_late_reports_are_dropped_and_the_task_is_told_to_stop()
    {
        static::__reset();

        static::__with_bound(function () {
            $id = static::__foreign_run(['started_at' => 700]);
            $late = Task_Instance::find($id);
            static::__assert_false($late->is_stop_requested(), 'not before the run is taken');

            static::__tick();
            static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) static::__row($id)->status_id);

            $late->status('still going');
            $late->progress_count(5, 10);
            $late->flush();
            $late->status('and again');
            $late->flush();

            $row = static::__row($id);
            static::__assert_null($row->status_text, 'no status reached the row');
            static::__assert_null($row->progress_done, 'no progress reached the row');
            static::__assert_null($row->last_report_at, 'no sign of life was stamped');
            $lines = static::__operator_lines($id);
            $refusals = array_filter($lines, fn ($line) => str_contains($line, 'its reports are no longer recorded'));
            static::__assert_count(1, $refusals, 'one operator line: ' . json_encode($lines));
            static::__assert_equals(0, DB::table('_task_output')->where('task_id', $id)->where('stream_id', Task_Run_Model::STREAM_STDERR)->count(), 'no output line was written');
            static::__assert_true($late->is_stop_requested(), 'the task is told to stop');
        });

        static::__reset();
    }

    // -------------------------------------------------------------------------
    // The health row and the daemon
    // -------------------------------------------------------------------------

    /**
     * The Task Worker Pools row names the hosts of runs rsx-lockd cannot answer for (pool and
     * inline) and when they will be settled, asking nothing of the operator; once settled the
     * row is OK.
     */
    public static function test_the_health_row_says_when_a_stranded_run_settles()
    {
        static::__reset();

        static::__with_bound(function () {
            $pool = static::__foreign_run(['started_at' => 100]);
            $inline = static::__foreign_run(['started_at' => 200], ['worker_host' => 'b-' . self::OTHER_HOST], true);

            $health = Task_Health_Checks::task_worker_pool();
            static::__assert_equals('WARN', $health['status'], json_encode($health));
            static::__assert_contains('2 running task(s) on host(s) b-' . self::OTHER_HOST . ', ' . self::OTHER_HOST, $health['detail']);
            static::__assert_contains('settled automatically once it and its host have been silent for ' . self::BOUND . 's', $health['detail']);
            static::__assert_contains('next in about 7 min', $health['detail'], 'the older run is 400 s from the bound: ' . $health['detail']);
            static::__assert_contains('none needed', $health['remediation']);

            DB::table('_tasks')->whereIn('id', [$pool, $inline])->update(['started_at' => static::__ago(700)]);
            static::__tick();

            static::__assert_equals('OK', Task_Health_Checks::task_worker_pool()['status'], 'nothing stranded is left');
        });

        static::__reset();
    }

    /** rspade-taskd asks for a tick at least hourly when idle. */
    public static function test_the_daemon_ticks_at_least_hourly()
    {
        $process = new Process([
            'node', '-e', 'process.stdout.write(String(require(process.argv[1]).CEILING_MS))',
            base_path('app/RSpade/resource/docker/rspade-taskd/rspade-taskd.js'),
        ]);
        $process->setTimeout(null);
        $process->run();

        static::__assert_true($process->isSuccessful(), $process->getErrorOutput());
        static::__assert_equals('3600000', $process->getOutput());
    }
}
