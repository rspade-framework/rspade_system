<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session_Values_Cleanup_Service;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Setting a schedule's next run explicitly: Task::set_next_run($service, $method, $at) and,
 * for a task's own schedule, $task->run_again_in($seconds).
 *
 *   - the moment is written to the schedule's next_run_at and answered as ISO 8601 UTC;
 *   - a task that declares no #[Schedule] has no next run to set: both spellings throw;
 *   - a declaration the tick has not registered yet is registered by the call;
 *   - reconciliation (rsx:task:process) leaves an explicitly set next run alone;
 *   - ONE run is moved, not the cadence: the scheduled-pool worker that claims the run
 *     recomputes the following one from the cron expression;
 *   - run_again_in() names the task, not the run, so it works from a run of any origin.
 *
 * The subject schedule is a REAL declaration - Session_Values_Cleanup_Service (its hourly task;
 * a pass over expired session values, cheap on the test database) - because an undeclared
 * schedule is exactly what the API refuses. The tick and the worker COMMIT, so this class
 * provisions a clean baseline and opts out of transactions.
 */
class Task_Next_Run_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private const SERVICE = 'Session_Values_Cleanup_Service';
    private const SERVICE_CLASS = Session_Values_Cleanup_Service::class;

    /** @var string|null the subject's scheduled method, read from the manifest */
    private static ?string $method = null;

    /** @var int[] run rows this class inserted by hand */
    private static array $runs = [];

    public static function teardown()
    {
        DB::table('_tasks')->whereIn('id', self::$runs)->delete();
        // Put every cadence back for whatever runs next.
        DB::table('_task_schedules')->delete();
        Artisan::call('rsx:task:process');
    }

    /** The subject's declaration: ['method' => ..., 'cron_expression' => ...]. */
    private static function __declaration(): array
    {
        foreach (Task::get_scheduled_tasks() as $declaration) {
            if ($declaration['class'] === self::SERVICE_CLASS) {
                self::$method = $declaration['method'];

                return $declaration;
            }
        }

        throw new \RuntimeException(self::SERVICE . ' declares no #[Schedule]; this test needs a real one.');
    }

    private static function __method(): string
    {
        return self::$method ?? static::__declaration()['method'];
    }

    private static function __schedule(): ?object
    {
        return DB::table('_task_schedules')->where('class', self::SERVICE_CLASS)->where('method', static::__method())->first();
    }

    /** An ISO moment $seconds from now, on a whole second. */
    private static function __moment(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s', time() + $seconds) . '.000Z';
    }

    private static function __instance(int $origin_id): Task_Instance
    {
        $id = Task_Runner::insert_row(self::SERVICE_CLASS, static::__method(), [], $origin_id, Task_Runner::running_fields());
        self::$runs[] = $id;

        return Task_Instance::find($id);
    }

    // -------------------------------------------------------------------------
    // Task::set_next_run()
    // -------------------------------------------------------------------------

    public static function test_set_next_run_writes_the_moment_and_answers_it()
    {
        Artisan::call('rsx:task:process');
        $at = static::__moment(7200 + 17);

        $answer = Task::set_next_run(self::SERVICE, static::__method(), $at);

        static::__assert_equals($at, $answer, 'the moment written, as ISO 8601 UTC');
        static::__assert_equals($at, Rsx_Time::to_iso(static::__schedule()->next_run_at), 'is the schedule\'s next_run_at');
    }

    public static function test_the_moment_may_be_given_in_any_timezone()
    {
        Artisan::call('rsx:task:process');
        $utc = static::__moment(3600);
        $offset = date('Y-m-d\TH:i:s', strtotime($utc) - 6 * 3600) . '-06:00';

        static::__assert_equals($utc, Task::set_next_run(self::SERVICE, static::__method(), $offset), 'stored as the same instant, in UTC');
    }

    public static function test_a_task_without_a_schedule_throws()
    {
        $thrown = null;
        try {
            Task::set_next_run('Task_Exec_Fixture_Service', 'marker_a', static::__moment(60));
        } catch (\Exception $e) {
            $thrown = $e->getMessage();
        }

        static::__assert_equals('Task::set_next_run(): Task_Exec_Fixture_Service::marker_a declares no #[Schedule], so it has no next run to set.', $thrown);
        static::__assert_equals(0, DB::table('_task_schedules')->where('class', Task_Exec_Fixture_Service::class)->count(), 'and nothing was written');
    }

    public static function test_something_that_is_not_a_task_or_not_a_moment_throws()
    {
        static::__assert_throws(\Exception::class, fn () => Task::set_next_run(self::SERVICE, 'no_such_method', static::__moment(60)), 'not found in service');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Task::set_next_run(self::SERVICE, static::__method(), 'next tuesday'), 'unrecognized datetime format');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Task::set_next_run(self::SERVICE, static::__method(), '2031-01-01'), 'date-only');
        static::__assert_throws(\Exception::class, fn () => Task::set_next_run(self::SERVICE, static::__method(), ''), 'needs a moment');
    }

    public static function test_an_unregistered_declaration_is_registered_by_the_call()
    {
        $declaration = static::__declaration();
        DB::table('_task_schedules')->delete();
        $at = static::__moment(5400);

        Task::set_next_run(self::SERVICE, $declaration['method'], $at);

        $schedule = static::__schedule();
        static::__assert_not_null($schedule, 'the schedule row exists');
        static::__assert_equals($declaration['cron_expression'], $schedule->cron_expression, 'with the declared expression');
        static::__assert_equals($at, Rsx_Time::to_iso($schedule->next_run_at));
        static::__assert_equals(1, DB::table('_task_schedules')->where('class', self::SERVICE_CLASS)->count(), 'one row');
    }

    public static function test_reconciliation_leaves_an_explicit_next_run_alone()
    {
        Artisan::call('rsx:task:process');
        $at = static::__moment(86400 * 3 + 123);
        Task::set_next_run(self::SERVICE, static::__method(), $at);
        $id_before = (int) static::__schedule()->id;

        Artisan::call('rsx:task:process');
        Artisan::call('rsx:task:process');

        $after = static::__schedule();
        static::__assert_equals($id_before, (int) $after->id, 'the same row');
        static::__assert_equals($at, Rsx_Time::to_iso($after->next_run_at), 'two ticks later the explicit moment stands');
    }

    /**
     * One run is moved, the cadence is not: brought forward to "due now", the schedule is
     * claimed by a scheduled-pool worker, runs, and its next run is the cron expression's.
     */
    public static function test_the_cadence_resumes_after_the_run_that_was_moved()
    {
        $declaration = static::__declaration();
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_task_schedules')->delete();
        Artisan::call('rsx:task:process');
        $schedule_id = (int) static::__schedule()->id;

        Task::set_next_run(self::SERVICE, $declaration['method'], static::__moment(-30));

        $parser = new Cron_Parser($declaration['cron_expression']);
        $earliest = $parser->get_next_run_time();
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        $latest = $parser->get_next_run_time();

        $runs = Task_Run_Model::where('schedule_id', $schedule_id)->get();
        static::__assert_count(1, $runs, 'the moved run ran, once');
        static::__assert_equals(Task_Run_Model::ORIGIN_SCHEDULED, (int) $runs[0]->origin_id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $runs[0]->status_id, (string) $runs[0]->error);

        $next = strtotime((string) static::__schedule()->next_run_at);
        static::__assert_true($next >= $earliest && $next <= $latest, 'and the next run is the cron expression\'s next cadence');
        static::__assert_true($next > time(), 'in the future');
    }

    // -------------------------------------------------------------------------
    // $task->run_again_in()
    // -------------------------------------------------------------------------

    /** It names the task, not the run: a run of any origin sets its task's schedule. */
    public static function test_run_again_in_works_from_a_run_of_any_origin()
    {
        Artisan::call('rsx:task:process');

        $origins = [
            'scheduled' => [Task_Run_Model::ORIGIN_SCHEDULED, 600],
            'dispatched' => [Task_Run_Model::ORIGIN_DISPATCHED, 1200],
            'inline' => [Task_Run_Model::ORIGIN_INLINE, 1800],
        ];

        foreach ($origins as $name => [$origin_id, $seconds]) {
            $task = static::__instance($origin_id);

            $before = time();
            $answer = $task->run_again_in($seconds);
            $after = time();

            $next = strtotime((string) static::__schedule()->next_run_at);
            static::__assert_true($next >= $before + $seconds && $next <= $after + $seconds, "a {$name} run set its schedule {$seconds}s ahead");
            static::__assert_equals(Rsx_Time::to_iso(static::__schedule()->next_run_at), $answer, 'and answered the moment');
        }
    }

    public static function test_run_again_in_throws_for_a_task_without_a_schedule_and_for_a_negative_wait()
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
        self::$runs[] = $id;

        static::__assert_throws(\Exception::class, fn () => Task_Instance::find($id)->run_again_in(60), 'declares no #[Schedule]');
        static::__assert_throws(\InvalidArgumentException::class, fn () => static::__instance(Task_Run_Model::ORIGIN_INLINE)->run_again_in(-1), 'seconds from now');
    }
}
