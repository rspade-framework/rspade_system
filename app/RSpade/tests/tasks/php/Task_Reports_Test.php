<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Run_Outcome;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * What a running task REPORTS through its Task_Instance, and how outside code reads it back
 * off Task_Run_Model.
 *
 * Reports are COALESCED: the first report after a quiet spell is written at once, and a report
 * within FLUSH_INTERVAL of the last write waits for the next write - a later report,
 * is_stop_requested(), flush() or the settle. That decision is made against the instance's
 * last write time, which these tests pin through reflection (far in the future = "just
 * written", zero = "never written") so the outcome never depends on how fast this box runs.
 *
 * Each test drives an instance of a RUNNING inline row; rows roll back with the per-test
 * transaction.
 */
class Task_Reports_Test extends Rsx_Test_Abstract
{
    private static function __instance(): Task_Instance
    {
        return Task_Instance::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields()));
    }

    private static function __run(Task_Instance $task): Task_Run_Model
    {
        return Task_Run_Model::find($task->get_id());
    }

    /** Pin the instance's last write: true = just written (reports are held), false = never. */
    private static function __just_written(Task_Instance $task, bool $just_written): void
    {
        (new \ReflectionProperty(Task_Instance::class, 'last_flush'))->setValue($task, $just_written ? microtime(true) + 3600 : 0.0);
    }

    // -------------------------------------------------------------------------
    // Coalescing
    // -------------------------------------------------------------------------

    public static function test_the_first_report_after_a_quiet_spell_is_written_at_once()
    {
        $task = static::__instance();
        static::__just_written($task, false);

        $task->status('starting');

        $run = static::__run($task);
        static::__assert_equals('starting', $run->status_text(), 'written by the reporting call itself');
        static::__assert_not_null($run->last_report_at, 'and the write stamps last_report_at');
    }

    public static function test_a_report_within_the_interval_is_held_until_flush()
    {
        $task = static::__instance();
        static::__just_written($task, true);

        $task->progress(40);
        $task->state(['step' => 2]);
        $task->stdout('held line');

        $run = static::__run($task);
        static::__assert_null($run->progress_percent, 'progress is held');
        static::__assert_null($run->state(), 'state is held');
        static::__assert_equals([], $run->output_after(), 'output is held');

        $task->flush();

        $run = static::__run($task);
        static::__assert_equals(40.0, $run->progress_percent());
        static::__assert_equals(['step' => 2], $run->state());
        static::__assert_equals(['held line'], array_column($run->output_after(), 'line'));
    }

    public static function test_is_stop_requested_writes_held_reports_at_the_rate()
    {
        $task = static::__instance();
        static::__just_written($task, true);

        $task->summary('half done');
        static::__assert_null(static::__run($task)->summary());

        static::__assert_false($task->is_stop_requested());
        static::__assert_null(static::__run($task)->summary(), 'within the interval the stop check reads but does not write');

        static::__just_written($task, false);
        static::__assert_false($task->is_stop_requested());
        static::__assert_equals('half done', static::__run($task)->summary(), 'after the interval the stop check wrote it');
    }

    public static function test_the_settle_writes_held_reports()
    {
        $task = static::__instance();
        static::__just_written($task, true);

        $task->state(['final' => true]);
        Task_Runner::settle($task, Task_Run_Outcome::from_return(null));

        $run = static::__run($task);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(['final' => true], $run->state(), 'nothing the task reported is lost at the end');
    }

    // -------------------------------------------------------------------------
    // Each report, persisted
    // -------------------------------------------------------------------------

    public static function test_each_report_persists_after_flush()
    {
        $task = static::__instance();
        static::__just_written($task, true);

        $before = time();
        $task->heartbeat();
        $task->status('working');
        $task->progress(12.345);
        $task->progress_count(3, 257);
        $task->eta(60);
        $task->state((object) ['a' => 1, 'list' => [1, 2]]);
        $task->state_list(['first', ['n' => 2]]);
        $task->summary('Did the work.');
        $task->message('one');
        $task->message('two');
        $task->flush();

        $run = static::__run($task);
        static::__assert_not_null($run->last_heartbeat_at);
        static::__assert_equals('working', $run->status_text());
        static::__assert_equals(12.35, $run->progress_percent(), 'two decimals');
        static::__assert_equals(['done' => 3, 'total' => 257], $run->progress_count());
        $eta = strtotime($run->eta_at());
        static::__assert_true($eta >= $before + 60 && $eta <= time() + 60, 'the ETA is stored as the moment it names: ' . $run->eta_at());
        static::__assert_equals(['a' => 1, 'list' => [1, 2]], $run->state());
        static::__assert_equals(['first', ['n' => 2]], $run->state_list());
        static::__assert_equals('Did the work.', $run->summary());
        static::__assert_equals(['one', 'two'], array_column($run->messages_after(), 'body'), 'messages kept, in order');

        $first = $run->messages_after()[0]['id'];
        static::__assert_equals(['two'], array_column($run->messages_after($first), 'body'), 'read on from a cursor');
    }

    public static function test_a_report_replaces_the_last()
    {
        $task = static::__instance();
        $task->state(['v' => 1]);
        $task->state(['v' => 2]);
        $task->summary('first');
        $task->summary('second');
        $task->flush();

        $run = static::__run($task);
        static::__assert_equals(['v' => 2], $run->state());
        static::__assert_equals('second', $run->summary());
        static::__assert_equals(2, DB::table('_task_reports')->where('task_id', $task->get_id())->count(), 'one row per kind');
    }

    public static function test_a_queue_report_exists_once_it_held_an_item()
    {
        $task = static::__instance();
        $task->state_list([]);
        $task->flush();
        static::__assert_false(in_array('state_list', static::__run($task)->available_reports(), true), 'an empty list before any item records nothing');

        $task->state_list(['a']);
        $task->flush();
        $task->state_list([]);
        $task->flush();
        $run = static::__run($task);
        static::__assert_true(in_array('state_list', $run->available_reports(), true), 'a queue that held an item stays reported');
        static::__assert_equals([], $run->state_list(), 'the emptied queue is recorded as the empty list');
    }

    public static function test_progress_is_clamped()
    {
        $task = static::__instance();

        $task->progress(150);
        $task->flush();
        static::__assert_equals(100.0, static::__run($task)->progress_percent());

        $task->progress(-5);
        $task->flush();
        static::__assert_equals(0.0, static::__run($task)->progress_percent());
    }

    public static function test_a_report_refuses_what_it_cannot_record()
    {
        $task = static::__instance();

        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->progress_count(-1, 5), 'non-negative counts');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->eta(-1), 'seconds from now');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->state_list(['a' => 1]), 'takes a list');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $task->state(['bad' => NAN]), 'cannot be encoded as JSON');
    }

    // -------------------------------------------------------------------------
    // status(): a stderr line only when it changes
    // -------------------------------------------------------------------------

    public static function test_status_writes_a_stderr_line_only_when_it_changes()
    {
        $task = static::__instance();

        $task->status('alpha');
        $task->status('alpha');
        $task->status("beta\nsecond line");
        $task->status('alpha');
        $task->flush();

        $run = static::__run($task);
        static::__assert_equals('alpha', $run->status_text());
        static::__assert_equals(['alpha', 'beta second line', 'alpha'], array_column($run->output_after(null, ['stderr']), 'line'), 'a repeat writes nothing; a newline is folded');
    }

    public static function test_a_long_status_is_cut_to_fit()
    {
        $task = static::__instance();
        $task->status(str_repeat('x', 1500));
        $task->flush();

        $text = static::__run($task)->status_text();
        static::__assert_equals(Task_Instance::STATUS_TEXT_MAX, mb_strlen($text));
        static::__assert_true(str_ends_with($text, '...'), 'marked as cut');
    }

    // -------------------------------------------------------------------------
    // Readers
    // -------------------------------------------------------------------------

    public static function test_progress_percent_is_derived_from_a_count()
    {
        $task = static::__instance();
        $task->progress_count(1, 4);
        $task->flush();
        static::__assert_equals(25.0, static::__run($task)->progress_percent(), 'derived from 1 of 4');

        $task->progress_count(0, 0);
        $task->flush();
        static::__assert_null(static::__run($task)->progress_percent(), 'a total of 0 has no percentage');

        $task->progress(10);
        $task->progress_count(3, 4);
        $task->flush();
        static::__assert_equals(10.0, static::__run($task)->progress_percent(), 'a reported percentage wins over the count');
    }

    public static function test_available_reports_lists_what_was_set_in_a_stable_order()
    {
        $task = static::__instance();
        static::__assert_equals([], static::__run($task)->available_reports(), 'a fresh run has set nothing');

        $task->summary('s');
        $task->message('m');
        $task->heartbeat();
        $task->eta(5);
        $task->progress_count(1, 2);
        $task->progress(50);
        $task->status('t');
        $task->state_list(['x']);
        $task->state(['k' => 'v']);
        Task_Runner::settle($task, Task_Run_Outcome::from_return(null));

        static::__assert_equals(
            ['state_json', 'state_list', 'status_text', 'progress', 'progress_count', 'eta', 'heartbeat', 'messages', 'summary', 'return_code'],
            static::__run($task)->available_reports()
        );
    }

    public static function test_output_after_reads_by_cursor_and_stream()
    {
        $task = static::__instance();
        $task->stdout("one\ntwo\n");
        $task->stderr("three\r\nfour");
        $task->flush();
        Task_Instance::record_operator_line($task->get_id(), 'five');

        $run = static::__run($task);
        $all = $run->output_after();
        static::__assert_equals(['one', 'two', 'three', 'four', 'five'], array_column($all, 'line'), 'one row per line, in order');
        static::__assert_equals(['stdout', 'stdout', 'stderr', 'stderr', 'operator'], array_column($all, 'stream'));
        static::__assert_equals(['three', 'four'], array_column($run->output_after(null, ['stderr']), 'line'));
        static::__assert_equals(['two', 'three', 'four', 'five'], array_column($run->output_after($all[0]['id']), 'line'), 'read on from a cursor');
        static::__assert_equals(['one'], array_column($run->output_after(null, ['stdout'], 1), 'line'), 'one page of at most $limit');
        static::__assert_throws(\InvalidArgumentException::class, fn () => $run->output_after(null, ['console']), "Unknown task output stream 'console'");
    }

    public static function test_to_status_array_shape()
    {
        $task = static::__instance();
        $task->status('busy');
        $task->progress_count(2, 8);
        $task->flush();

        $status = static::__run($task)->to_status_array();

        static::__assert_equals(
            ['id', 'class', 'method', 'name', 'params', 'origin', 'status_id', 'status', 'is_live', 'status_reason', 'error', 'return_code',
                'scheduled_for', 'started_at', 'completed_at', 'stop_requested_at', 'status_text', 'progress_percent', 'progress_count',
                'eta_at', 'last_heartbeat_at', 'last_report_at', 'reports'],
            array_keys($status)
        );
        static::__assert_equals($task->get_id(), $status['id']);
        static::__assert_equals('Task_Exec_Fixture_Service', $status['class'], 'the simple class name');
        static::__assert_equals('Task_Exec_Fixture_Service::marker_a', $status['name']);
        static::__assert_equals('Inline', $status['origin']);
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, $status['status_id']);
        static::__assert_equals('Running', $status['status']);
        static::__assert_true($status['is_live']);
        static::__assert_null($status['return_code']);
        static::__assert_null($status['completed_at']);
        static::__assert_true(is_string($status['started_at']) && str_contains($status['started_at'], 'T'), 'moments are ISO strings: ' . $status['started_at']);
        static::__assert_equals('busy', $status['status_text']);
        static::__assert_equals(25.0, $status['progress_percent']);
        static::__assert_equals(['done' => 2, 'total' => 8], $status['progress_count']);
        static::__assert_equals(['status_text', 'progress_count'], $status['reports']);
    }
}
