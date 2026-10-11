<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Documents\Php\Document_Worker_Fixture_Handler;

/**
 * The document worker's queue: which blob is taken, when, how often, and what one document that
 * cannot be processed costs the documents behind it.
 *
 * The subject is the set of rules that keeps a single bad document from stopping the queue
 * (a downstream field report, 2026-10-11: one workbook that never finished was taken first by
 * every pass, each pass died having recorded nothing, and nothing behind it was ever processed):
 * the attempt is charged before the work starts, the work runs in a child process under a hard
 * kill, a failed attempt is retried a retry interval later, and the third failure is permanent.
 *
 * This class COMMITS - the worker's child is another process reading the same database - so it
 * declares the $requires_db_reset pair. Its children are pointed at the test database and the
 * test file store by process_storage() itself (it hands the child this process's database and
 * path roots).
 *
 * THE QUEUE IS EMPTIED FIRST. The migrated baseline may carry blobs that owe work (documents an
 * application's seed migrations imported), and every assertion here is about which blob the
 * queue offers. Each test starts by taking every blob out of the queue, so it sees only what it
 * queued itself.
 *
 * Tests that run a real child are marked CHILD in their comment; each costs one PHP boot per
 * attempt. Misbehaviour inside the child is produced by Document_Worker_Fixture_Handler, keyed on
 * the first bytes of the blob.
 */
class Document_Work_Queue_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        // rsx.search.enabled stays ON: it is one of the switches under test. An upload's kick
        // spawns nothing under the suite (Task::dispatch only enqueues), so no worker competes
        // with these tests for the queue.
        static::__empty_queue();
    }

    /**
     * Take every blob out of the queue. Each test starts here, so what the queue offers is
     * only what that test queued - whatever the baseline carried or an earlier test left.
     */
    private static function __empty_queue(): void
    {
        DB::statement('UPDATE _file_storage SET process_due_at = NULL WHERE process_due_at IS NOT NULL');
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        config(['rsx.libreoffice.enabled' => true]);
        config(['rsx.documents.kill_seconds' => 60]);
        config(['rsx.documents.max_attempts' => 3]);
        config(['rsx.documents.drain_yield_after_minutes' => 20, 'rsx.documents.drain_resume_after_minutes' => 10]);
        DB::table('_tasks')->where('class', Document_Render_Service::class)->delete();
        DB::table('_task_schedules')->where('class', Document_Render_Service::class)->delete();
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /**
     * A fresh blob behind a tracked attachment, re-read from the database. $prefix leads the
     * bytes (a fixture marker, or nothing); a random tail keeps every blob its own.
     */
    private static function __make_blob(string $filename, string $prefix = ''): File_Storage_Model
    {
        $attachment = File_Attachment_Model::create_from_string(
            $prefix . ' RSpade work queue test ' . bin2hex(random_bytes(8)),
            $filename,
            ['site_id' => static::__site_id()]
        );
        static::$created_attachment_ids[] = $attachment->id;

        return File_Storage_Model::find($attachment->file_storage_id);
    }

    private static function __fresh(File_Storage_Model $storage): File_Storage_Model
    {
        return File_Storage_Model::find($storage->id);
    }

    /**
     * Write queue and outcome columns directly, the database's clock for the due time.
     *
     * @param File_Storage_Model $storage
     * @param array $values Column => value; process_due_at takes a SQL expression string.
     * @return File_Storage_Model The row, re-read.
     */
    private static function __force(File_Storage_Model $storage, array $values): File_Storage_Model
    {
        if (array_key_exists('process_due_at', $values) && $values['process_due_at'] !== null) {
            $values['process_due_at'] = DB::raw($values['process_due_at']);
        }

        File_Storage_Model::where('id', $storage->id)->update($values);

        return static::__fresh($storage);
    }

    /**
     * Whether the row's due time lies inside a window, judged by the database's own clock.
     */
    private static function __due_between(File_Storage_Model $storage, string $from_sql, string $to_sql): bool
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM _file_storage WHERE id = ? AND process_due_at > {$from_sql} AND process_due_at <= {$to_sql}",
            [$storage->id]
        )->c === 1;
    }

    private static function __index(File_Storage_Model $storage): ?Search_Index_Model
    {
        return Search_Index_Model::forModel('File_Storage_Model', $storage->id)->first();
    }

    // ============================================================================================
    // THE QUEUE PREDICATE
    // ============================================================================================

    // DOCUMENTS-QUEUE-BORN-DUE: a new blob owes its text, so its row is born in the queue - due at
    // the insert, no attempts charged - without any second statement that could fail to run.
    public static function test_new_blob_is_born_due()
    {
        static::__empty_queue();

        $storage = static::__make_blob('born.txt');

        static::__assert_not_null($storage->process_due_at, 'a new blob has a due time');
        static::__assert_equals(0, (int) $storage->process_attempt_count, 'and no attempts charged');
        static::__assert_equals(0, (int) $storage->is_indexed, 'because it owes its text');
        static::__assert_equals(
            1,
            File_Storage_Model::work_due_query()->where('id', $storage->id)->count(),
            'so the worker may take it now'
        );

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-QUEUE-ORDER: the worker takes the EARLIEST DUE blob, not the lowest id; a blob due
    // in the future and a blob with no due time are not offered at all.
    public static function test_earliest_due_is_taken_first()
    {
        static::__empty_queue();

        $later = static::__force(static::__make_blob('later.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 10 SECOND']);
        $earlier = static::__force(static::__make_blob('earlier.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 20 SECOND']);
        $future = static::__force(static::__make_blob('future.txt'), ['process_due_at' => 'NOW(3) + INTERVAL 10 MINUTE']);
        $none = static::__force(static::__make_blob('none.txt'), ['process_due_at' => null]);

        static::__assert_true($later->id < $earlier->id, 'precondition: the earlier-due blob has the HIGHER id');

        $order = File_Storage_Model::work_due_query()->orderBy('process_due_at')->orderBy('id')->pluck('id')->all();
        static::__assert_equals([$earlier->id, $later->id], $order, 'due blobs come earliest first; future and NULL rows are absent');
        static::__assert_equals($earlier->id, Document_Render_Service::next_due()->id, 'the worker takes the earliest due blob');

        // Two blobs due at the same instant: the lower id first.
        DB::statement(
            'UPDATE _file_storage SET process_due_at = (SELECT d FROM (SELECT process_due_at AS d FROM _file_storage WHERE id = ?) x) WHERE id = ?',
            [$earlier->id, $later->id]
        );
        static::__assert_equals($later->id, Document_Render_Service::next_due()->id, 'a tie on the due time is broken by id');

        foreach ([$later, $earlier, $future, $none] as $storage) {
            static::__force($storage, ['process_due_at' => null]);
        }
        static::__assert_null(Document_Render_Service::next_due(), 'an empty queue offers nothing');
    }

    // DOCUMENTS-QUEUE-ONE-RANGE-READ: the worker's query is answered from idx_process_due - the
    // property that keeps it fast when the table holds millions of rows that owe nothing.
    public static function test_the_queue_query_reads_its_index()
    {
        static::__empty_queue();

        $plan = DB::select(
            'EXPLAIN SELECT * FROM _file_storage WHERE process_due_at <= NOW(3) ORDER BY process_due_at, id LIMIT 1'
        );

        static::__assert_count(1, $plan, 'one table, one access');
        static::__assert_equals('idx_process_due', $plan[0]->key, 'the queue query is read from idx_process_due');
        static::__assert_false(
            str_contains((string) $plan[0]->Extra, 'filesort'),
            'the index supplies the order - no sort: ' . $plan[0]->Extra
        );
    }

    // ============================================================================================
    // THE CHARGE
    // ============================================================================================

    // DOCUMENTS-QUEUE-CHARGE-FIRST: taking a blob counts the attempt and moves its due time one
    // retry interval ahead BEFORE anything runs. This is the whole of what a worker that then dies
    // leaves behind - and it is enough: the blob is no longer the one the next pass takes first.
    public static function test_attempt_is_charged_and_blob_leaves_the_front()
    {
        static::__empty_queue();

        $stuck = static::__force(static::__make_blob('stuck.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 30 SECOND']);
        $behind = static::__force(static::__make_blob('behind.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 5 SECOND']);

        static::__assert_equals($stuck->id, Document_Render_Service::next_due()->id, 'precondition: the stuck blob is first in line');

        static::__assert_true($stuck->charge_work_attempt(), 'the attempt is charged');

        // Nothing else happens: this is the state a worker killed mid-attempt leaves behind.
        $reloaded = static::__fresh($stuck);
        static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'the attempt was counted before any work ran');
        static::__assert_true(
            static::__due_between($stuck, 'NOW(3) + INTERVAL 29 MINUTE', 'NOW(3) + INTERVAL 30 MINUTE'),
            'its next attempt is one retry interval (30 minutes) away'
        );
        static::__assert_equals(
            $behind->id,
            Document_Render_Service::next_due()->id,
            'the next pass takes the blob BEHIND it - a blob killed mid-attempt is not taken first again'
        );

        foreach ([$stuck, $behind] as $storage) {
            static::__force($storage, ['process_due_at' => null]);
        }
    }

    // DOCUMENTS-QUEUE-CHARGE-GUARDED: the charge succeeds once per attempt. A second process
    // holding the same stale row cannot charge - and therefore cannot run - the same attempt.
    public static function test_charge_is_guarded_against_a_second_taker()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('guard.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);
        $same_row_elsewhere = static::__fresh($storage);

        static::__assert_true($storage->charge_work_attempt(), 'the first taker charges');
        static::__assert_false($same_row_elsewhere->charge_work_attempt(), 'the second taker is refused');
        static::__assert_equals(1, (int) static::__fresh($storage)->process_attempt_count, 'exactly one attempt was charged');

        $result = Document_Render_Service::process_storage($same_row_elsewhere);
        static::__assert_equals(Document_Render_Service::OUTCOME_TAKEN, $result['outcome'], 'process_storage() reports the blob as taken and runs nothing');

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // OUTCOMES THAT RUN NO CHILD
    // ============================================================================================

    // DOCUMENTS-QUEUE-ABANDONED-LAST-ATTEMPT: a blob found due with every attempt already charged
    // is one whose last attempt died with its worker. It is settled FAILED - render and text -
    // with a reason that says so, and is NOT run again.
    public static function test_abandoned_last_attempt_is_settled_without_running()
    {
        static::__empty_queue();

        $storage = static::__make_blob('abandoned.docx');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $storage->render_status_id, 'precondition: a rendition is owed');

        $storage = static::__force($storage, [
            'process_due_at' => 'NOW(3) - INTERVAL 1 SECOND',
            'process_attempt_count' => 3,
        ]);

        $result = Document_Render_Service::process_storage($storage);

        static::__assert_equals(Document_Render_Service::OUTCOME_FAILED, $result['outcome']);
        static::__assert_contains('never reported', (string) $result['reason']);

        $reloaded = static::__fresh($storage);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $reloaded->render_status_id, 'the render is FAILED');
        static::__assert_contains('attempt 3 of 3 never reported', (string) $reloaded->render_error, 'the reason says the last attempt never reported');
        static::__assert_null($reloaded->process_due_at, 'it left the queue');
        static::__assert_equals(3, (int) $reloaded->process_attempt_count, 'with its attempts spent');
        static::__assert_equals(1, (int) $reloaded->is_indexed, 'its text is no longer owed');
        static::__assert_true($reloaded->is_render_failed_for_good(), 'the worker will not attempt it again');

        $index = static::__index($storage);
        static::__assert_not_null($index, 'the owed extraction has a recorded outcome');
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id);
        static::__assert_contains('never reported', (string) $index->error);
    }

    // DOCUMENTS-QUEUE-NOTHING-OWED: a due blob that owes nothing simply leaves the queue.
    public static function test_blob_owing_nothing_leaves_the_queue()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('settled.txt'), [
            'is_indexed' => 1,
            'process_due_at' => 'NOW(3) - INTERVAL 1 SECOND',
            'process_attempt_count' => 2,
        ]);

        $result = Document_Render_Service::process_storage($storage);

        static::__assert_equals(Document_Render_Service::OUTCOME_DONE, $result['outcome']);
        $reloaded = static::__fresh($storage);
        static::__assert_null($reloaded->process_due_at, 'no due time');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'and a clean counter');
    }

    // DOCUMENTS-QUEUE-OUT-OF-QUEUE: a FAILED blob with no due time is failed for good. Nothing
    // processes it - not a direct call, not a worker pass - until an operator re-queues it.
    public static function test_failed_blob_outside_the_queue_is_never_processed()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('for_good.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_FAILED,
            'render_error' => 'soffice said no',
            'is_indexed' => 1,
            'process_due_at' => null,
            'process_attempt_count' => 3,
        ]);

        $result = Document_Render_Service::process_storage($storage);
        static::__assert_equals(Document_Render_Service::OUTCOME_DONE, $result['outcome'], 'nothing to do for a blob outside the queue');

        static::__run_task_method(Document_Render_Service::class, 'render_pending');

        $reloaded = static::__fresh($storage);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $reloaded->render_status_id, 'FAILED stays FAILED across a worker pass');
        static::__assert_equals('soffice said no', (string) $reloaded->render_error, 'the recorded reason survives');
        static::__assert_equals(3, (int) $reloaded->process_attempt_count, 'no attempt was charged');
        static::__assert_null($reloaded->process_due_at, 'and it is still outside the queue');
    }

    // DOCUMENTS-QUEUE-SWITCHED-OFF: owed work that is switched off is deferred - offered again
    // later with NO attempt charged - so it waits for the switch without holding the front of the
    // queue and without ever being marked FAILED for something nobody tried.
    public static function test_switched_off_work_is_deferred_not_charged()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('waits_for_soffice.docx'), [
            'is_indexed' => 1,
            'process_due_at' => 'NOW(3) - INTERVAL 1 SECOND',
        ]);

        config(['rsx.libreoffice.enabled' => false]);
        try {
            $result = Document_Render_Service::process_storage($storage);
        } finally {
            config(['rsx.libreoffice.enabled' => true]);
        }

        static::__assert_equals(Document_Render_Service::OUTCOME_WAITING, $result['outcome']);

        $reloaded = static::__fresh($storage);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $reloaded->render_status_id, 'still PENDING - it was never attempted');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'no attempt charged');
        static::__assert_true(
            static::__due_between($storage, 'NOW(3) + INTERVAL 29 MINUTE', 'NOW(3) + INTERVAL 30 MINUTE'),
            'it is offered again one retry interval from now'
        );

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // RE-QUEUEING
    // ============================================================================================

    // DOCUMENTS-QUEUE-REQUEUE-RESETS: every way a blob comes to owe work makes it due now with a
    // full set of attempts - here the model's two statements of it.
    public static function test_requeue_makes_the_blob_due_with_fresh_attempts()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('again.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_FAILED,
            'render_error' => 'earlier failure',
            'is_indexed' => 1,
            'process_due_at' => null,
            'process_attempt_count' => 3,
        ]);

        $storage->request_render();
        static::__assert_null(static::__fresh($storage)->process_due_at, 'request_render() (an upload of the same bytes) never re-queues a FAILED blob');

        $storage->requeue_render(false);

        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $storage->render_status_id, 'requeue_render() -> PENDING (the instance is current)');
        static::__assert_null($storage->render_error, 'the earlier reason is cleared');
        static::__assert_equals(0, (int) $storage->process_attempt_count, 'attempts back to 0');
        static::__assert_true(static::__due_between($storage, 'NOW(3) - INTERVAL 1 MINUTE', 'NOW(3)'), 'due now');

        $storage = static::__force($storage, [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_RENDERED,
            'process_due_at' => null,
            'process_attempt_count' => 2,
        ]);
        $storage->is_indexed = 0;
        $storage->save();
        $storage->mark_work_owed();

        static::__assert_equals(0, (int) $storage->process_attempt_count, 'mark_work_owed(): attempts back to 0');
        static::__assert_true(static::__due_between($storage, 'NOW(3) - INTERVAL 1 MINUTE', 'NOW(3)'), 'mark_work_owed(): due now');

        static::__force($storage, ['process_due_at' => null]);
    }

    // ============================================================================================
    // THE CHILD PROCESS
    // ============================================================================================

    // DOCUMENTS-QUEUE-BOUNDED-RUN: the bounded spawn hands back a finished child's own exit code
    // and output untouched. CHILD.
    public static function test_run_bounded_returns_exit_code_and_output()
    {
        static::__empty_queue();

        $output = [];
        $exit_code = Rsx_Artisan::run_bounded(Document_Render_Service::CHILD_COMMAND, ['999999999'], 300, $output);

        static::__assert_equals(1, $exit_code, 'the child command exits 1 for a blob that does not exist: ' . implode(' | ', $output));
        static::__assert_contains('No _file_storage row with id 999999999', implode("\n", $output), 'its output is captured');
    }

    // DOCUMENTS-QUEUE-SUCCESS: a document that processes cleanly leaves the queue with a clean
    // counter and its outcome recorded - by the child, in another process. CHILD.
    public static function test_successful_attempt_leaves_the_queue()
    {
        static::__empty_queue();

        $storage = static::__force(static::__make_blob('fine.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);

        $result = Document_Render_Service::process_storage($storage);

        static::__assert_equals(Document_Render_Service::OUTCOME_DONE, $result['outcome'], 'the attempt succeeded: ' . (string) $result['reason']);

        $reloaded = static::__fresh($storage);
        static::__assert_equals(1, (int) $reloaded->is_indexed, 'the child extracted the text');
        static::__assert_null($reloaded->process_due_at, 'the blob left the queue');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'with a clean counter');

        $index = static::__index($storage);
        static::__assert_not_null($index, 'the child wrote the index row');
        static::__assert_equals(Search_Index_Model::STATUS_EXTRACTED, (int) $index->status_id);
        static::__assert_contains('RSpade work queue test', (string) $index->content, 'the row holds this blob\'s text');
    }

    // DOCUMENTS-QUEUE-THREE-ATTEMPTS: a document that fails every time is attempted three times,
    // a retry interval apart, and is then failed for good. Between attempts it reads as a failure
    // that is still owed; after the last it owes nothing and stays out of the queue. This is also
    // the EXTRACTION retry: the failing stage here is the text extraction. CHILD x3.
    public static function test_failing_document_is_retried_then_fails_for_good()
    {
        static::__empty_queue();

        $storage = static::__force(
            static::__make_blob('always_fails.txt', Document_Worker_Fixture_Handler::MARKER_FAIL),
            ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']
        );

        foreach ([1, 2] as $attempt) {
            $result = Document_Render_Service::process_storage(static::__fresh($storage));

            static::__assert_equals(Document_Render_Service::OUTCOME_RETRY, $result['outcome'], "attempt {$attempt} failed and will be retried");
            static::__assert_equals($attempt, $result['attempt']);
            static::__assert_equals('fixture forced failure', (string) $result['reason'], 'the reason is the one the child recorded');

            $reloaded = static::__fresh($storage);
            static::__assert_equals($attempt, (int) $reloaded->process_attempt_count);
            static::__assert_equals(0, (int) $reloaded->is_indexed, 'between attempts the text is still owed');
            static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) static::__index($storage)->status_id, 'and reads as FAILED, not as pending');
            static::__assert_true(
                static::__due_between($storage, 'NOW(3) + INTERVAL 29 MINUTE', 'NOW(3) + INTERVAL 30 MINUTE'),
                'the retry is one interval (30 minutes) away'
            );
            static::__assert_null(Document_Render_Service::next_due(), 'so no pass takes it before then');

            // The interval passes.
            static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);
        }

        $result = Document_Render_Service::process_storage(static::__fresh($storage));

        static::__assert_equals(Document_Render_Service::OUTCOME_FAILED, $result['outcome'], 'the third failure is permanent');
        static::__assert_equals(3, $result['attempt']);

        $reloaded = static::__fresh($storage);
        static::__assert_null($reloaded->process_due_at, 'it left the queue');
        static::__assert_equals(3, (int) $reloaded->process_attempt_count, 'attempts spent');
        static::__assert_equals(1, (int) $reloaded->is_indexed, 'the text is no longer owed');

        $index = static::__index($storage);
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id, 'the failure stays recorded');
        static::__assert_equals('fixture forced failure', (string) $index->error, 'with the reason of the last attempt');

        // The operator's reset: back in the queue with all three attempts.
        File_Storage_Model::where('id', $storage->id)->update(array_merge(['is_indexed' => 0], File_Storage_Model::work_owed_values()));
        $requeued = static::__fresh($storage);
        static::__assert_equals(0, (int) $requeued->process_attempt_count, 're-queued: attempts back to 0');
        static::__assert_equals($storage->id, Document_Render_Service::next_due()->id, 're-queued: due now');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-QUEUE-DRAIN-MOVES-ON: the drain itself, over a queue whose FIRST document always
    // fails: one pass attempts it once, reports the failure and the retry, and processes the
    // document behind it in the same pass. CHILD x2.
    public static function test_drain_passes_a_failing_document_and_processes_the_next()
    {
        static::__empty_queue();

        $bad = static::__force(
            static::__make_blob('bad_first.txt', Document_Worker_Fixture_Handler::MARKER_FAIL),
            ['process_due_at' => 'NOW(3) - INTERVAL 30 SECOND']
        );
        $good = static::__force(static::__make_blob('good_second.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 5 SECOND']);

        $state = static::__run_task_method(Document_Render_Service::class, 'render_pending');

        static::__assert_equals(2, (int) ($state['processed'] ?? -1), 'both documents were attempted in one pass');
        static::__assert_equals(1, (int) ($state['retrying'] ?? -1), 'one is waiting for a retry');
        static::__assert_equals(0, (int) ($state['failed'] ?? -1), 'none is failed for good yet');

        static::__assert_equals(1, (int) static::__fresh($bad)->process_attempt_count, 'the failing document was attempted exactly once');
        static::__assert_equals(1, (int) static::__fresh($good)->is_indexed, 'the document behind it was processed');
        static::__assert_null(static::__fresh($good)->process_due_at, 'and left the queue');

        static::__force($bad, ['process_due_at' => null]);
    }

    // ============================================================================================
    // PACED PASSES
    // ============================================================================================

    /** The newest run row of the drain, with what it reported. */
    private static function __last_drain_run(): Task_Run_Model
    {
        return Task_Run_Model::where('class', Document_Render_Service::class)->where('method', 'render_pending')->orderByDesc('id')->first();
    }

    private static function __drain_next_run_at(): ?string
    {
        $value = DB::table('_task_schedules')->where('class', Document_Render_Service::class)->where('method', 'render_pending')->value('next_run_at');

        return $value === null ? null : (string) $value;
    }

    // DOCUMENTS-QUEUE-DRAIN-YIELDS: a pass that has drained for drain_yield_after_minutes and
    // still finds work due stops BETWEEN two documents, sets its own next run
    // drain_resume_after_minutes ahead, says how many are still waiting and returns - a
    // SUCCESSFUL run. The pacing is config, so "twenty minutes have passed" is staged by setting
    // it to zero: the pass then yields after its first document. CHILD.
    public static function test_drain_yields_with_work_remaining_and_sets_its_next_run()
    {
        static::__empty_queue();
        config(['rsx.documents.drain_yield_after_minutes' => 0, 'rsx.documents.drain_resume_after_minutes' => 10]);

        $first = static::__force(static::__make_blob('paced_first.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 30 SECOND']);
        $second = static::__force(static::__make_blob('paced_second.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 20 SECOND']);
        $third = static::__force(static::__make_blob('paced_third.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 10 SECOND']);

        $before = time();
        $state = static::__run_task_method(Document_Render_Service::class, 'render_pending');
        $after = time();

        static::__assert_true((bool) ($state['yielded'] ?? false), 'the pass yielded');
        static::__assert_equals(1, (int) ($state['processed'] ?? -1), 'after the one document it had taken');
        static::__assert_equals(2, (int) ($state['remaining'] ?? -1), 'reporting how many are still waiting');

        static::__assert_equals(1, (int) static::__fresh($first)->is_indexed, 'the first document was processed to the end');
        foreach ([$second, $third] as $waiting) {
            $row = static::__fresh($waiting);
            static::__assert_equals(0, (int) $row->process_attempt_count, 'a waiting document was not charged');
            static::__assert_equals(0, (int) $row->is_indexed, 'nor touched');
            static::__assert_equals(1, File_Storage_Model::work_due_query()->where('id', $row->id)->count(), 'and is still due: nothing was lost');
        }

        $next_run = strtotime((string) static::__drain_next_run_at());
        static::__assert_true($next_run >= $before + 600 && $next_run <= $after + 600, 'its own next run is set ten minutes from now');
        static::__assert_equals(Rsx_Time::to_iso(static::__drain_next_run_at()), $state['resume_at'] ?? null, 'the moment it reported');

        $run = static::__last_drain_run();
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id, 'a yield is a SUCCESSFUL run');
        static::__assert_equals(0, (int) $run->return_code);
        static::__assert_contains('Yielded with 2 still waiting', (string) $run->summary(), 'the summary says so');
        $output = implode("\n", array_column($run->output_after(), 'line'));
        static::__assert_contains('2 still waiting', $output, 'and so does the output');
        static::__assert_contains('The next pass is set for', $output);

        // The next pass takes up exactly where this one stopped.
        config(['rsx.documents.drain_yield_after_minutes' => 20]);
        $state = static::__run_task_method(Document_Render_Service::class, 'render_pending');
        static::__assert_equals(2, (int) ($state['processed'] ?? -1), 'the rest is drained by the next pass');
        static::__assert_false((bool) ($state['yielded'] ?? true), 'which had no reason to yield');
    }

    // DOCUMENTS-QUEUE-DRAIN-NO-YIELD-WHEN-EMPTY: the yield is for work still due. A pass that
    // empties the queue ends normally however long it took, and leaves its schedule alone. CHILD.
    public static function test_drain_does_not_yield_when_the_queue_empties()
    {
        static::__empty_queue();
        config(['rsx.documents.drain_yield_after_minutes' => 0, 'rsx.documents.drain_resume_after_minutes' => 10]);

        $sentinel = Task::set_next_run('Document_Render_Service', 'render_pending', gmdate('Y-m-d\TH:i:s', time() + 86400) . '.000Z');
        $only = static::__force(static::__make_blob('paced_only.txt'), ['process_due_at' => 'NOW(3) - INTERVAL 5 SECOND']);

        $state = static::__run_task_method(Document_Render_Service::class, 'render_pending');

        static::__assert_false((bool) ($state['yielded'] ?? true), 'nothing was left to yield for');
        static::__assert_equals(1, (int) ($state['processed'] ?? -1));
        static::__assert_equals(1, (int) static::__fresh($only)->is_indexed);
        static::__assert_equals($sentinel, Rsx_Time::to_iso(static::__drain_next_run_at()), 'its next run was not moved');
        static::__assert_false(str_contains((string) static::__last_drain_run()->summary(), 'Yielded'));
    }

    // DOCUMENTS-QUEUE-DRAIN-PACING-CONFIG: the two numbers are validated where they are read.
    public static function test_drain_pacing_is_read_from_config_and_validated()
    {
        config(['rsx.documents.drain_yield_after_minutes' => 20, 'rsx.documents.drain_resume_after_minutes' => 10]);
        static::__assert_equals([1200.0, 600], Document_Render_Service::drain_pacing(), 'minutes in config, seconds in use');

        config(['rsx.documents.drain_yield_after_minutes' => 'soon']);
        static::__assert_throws(\Exception::class, fn () => Document_Render_Service::drain_pacing(), 'rsx.documents.drain_yield_after_minutes');

        config(['rsx.documents.drain_yield_after_minutes' => 20, 'rsx.documents.drain_resume_after_minutes' => -1]);
        static::__assert_throws(\Exception::class, fn () => Document_Render_Service::drain_pacing(), 'rsx.documents.drain_resume_after_minutes');

        config(['rsx.documents.drain_resume_after_minutes' => 10]);
    }

    // DOCUMENTS-QUEUE-CHILD-CRASH: a child that dies without recording anything is a failed
    // attempt with a reason the PARENT writes, naming how the child ended. CHILD.
    public static function test_crashed_child_is_recorded_by_the_parent()
    {
        static::__empty_queue();

        $storage = static::__force(
            static::__make_blob('crashes.txt', Document_Worker_Fixture_Handler::MARKER_CRASH),
            ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']
        );

        $result = Document_Render_Service::process_storage($storage);

        static::__assert_equals(Document_Render_Service::OUTCOME_RETRY, $result['outcome']);
        static::__assert_contains('exited with status 3', (string) $result['reason']);

        $index = static::__index($storage);
        static::__assert_not_null($index, 'the parent recorded an extraction outcome for the child that recorded none');
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id);
        static::__assert_contains('exited with status 3', (string) $index->error);
        static::__assert_equals(0, (int) static::__fresh($storage)->is_indexed, 'the text is still owed for the next attempt');

        static::__force($storage, ['process_due_at' => null]);
    }

    // DOCUMENTS-QUEUE-HARD-KILL: a child wedged where no signal handler can run - blocked in one
    // native call on a GRANDCHILD process - is killed at the limit together with that grandchild;
    // the parent records "killed after N seconds" and schedules the retry. CHILD.
    //
    // The limit is the parent's (it is passed to the spawn), so this test shortens it. It must
    // still outlast the child's boot, which on a loaded box is not a number this test may assume:
    // each round doubles the limit until the child got far enough to start its grandchild, and
    // only a round in which it did is asserted on.
    public static function test_wedged_child_is_killed_with_its_grandchild()
    {
        static::__empty_queue();

        $storage = static::__make_blob('wedged.txt', Document_Worker_Fixture_Handler::MARKER_WEDGE);
        $pid_file = Document_Worker_Fixture_Handler::wedge_pid_file($storage->id);
        static::$created_files[] = $pid_file;

        // Plenty of attempts: a round that only proved the child boots slowly is not the subject.
        config(['rsx.documents.max_attempts' => 20]);

        $result = null;
        $kill_seconds = 0;

        // 8 + 16 + 32 + 64 = 120 seconds of limits at most - the house bound on waiting for a
        // process this test does not control. Expiry fails the test below, naming what never
        // happened.
        foreach ([8, 16, 32, 64] as $kill_seconds) {
            config(['rsx.documents.kill_seconds' => $kill_seconds]);
            static::__force($storage, ['process_due_at' => 'NOW(3) - INTERVAL 1 SECOND']);

            $result = Document_Render_Service::process_storage(static::__fresh($storage));

            if (file_exists($pid_file)) {
                break;
            }
        }

        static::__assert_true(
            file_exists($pid_file),
            'the child never got as far as starting its grandchild process within a 64 second limit'
        );

        $grandchild_pid = (int) file_get_contents($pid_file);
        static::__assert_greater_than(1, $grandchild_pid, 'the grandchild recorded its pid');

        static::__assert_equals(Document_Render_Service::OUTCOME_RETRY, $result['outcome'], 'the kill is a failed attempt, retried later');
        static::__assert_equals("killed after {$kill_seconds} seconds", (string) $result['reason']);

        static::__assert_true(
            static::__process_is_gone($grandchild_pid),
            "the grandchild process {$grandchild_pid} is still running after its group was killed"
        );

        $reloaded = static::__fresh($storage);
        static::__assert_equals(0, (int) $reloaded->is_indexed, 'the text is still owed');
        static::__assert_true(
            static::__due_between($storage, 'NOW(3) + INTERVAL 29 MINUTE', 'NOW(3) + INTERVAL 30 MINUTE'),
            'the next attempt is a retry interval away'
        );
        static::__assert_null(Document_Render_Service::next_due(), 'so the killed document is not what the next pass takes');

        $index = static::__index($storage);
        static::__assert_equals(Search_Index_Model::STATUS_FAILED, (int) $index->status_id, 'the parent recorded the extraction outcome the child could not');
        static::__assert_equals("killed after {$kill_seconds} seconds", (string) $index->error);

        static::__force($storage, ['process_due_at' => null]);
    }

    /**
     * Wait for a process to be gone: no /proc entry, or a zombie (dead, awaiting its reaper).
     *
     * SIGKILL is delivered asynchronously, so "killed" and "gone" are two moments. This polls the
     * condition; the 120 second bound is a wait on a process outside this test's control, expiry
     * fails the caller's assertion, and no work is cut short by it.
     */
    private static function __process_is_gone(int $pid): bool
    {
        $deadline = microtime(true) + 120;

        while (microtime(true) < $deadline) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false) {
                return true;
            }

            // The state letter follows the parenthesised command name.
            $state = substr((string) strrchr($stat, ')'), 2, 1);
            if ($state === 'Z') {
                return true;
            }

            usleep(50000);
        }

        return false;
    }
}
