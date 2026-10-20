<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RETRY ON VIEW: Document_Render_Service::attend_view(), which get_preview_info() runs for every
 * <Document_Preview> load after its gate.
 *
 *   - a RENDERED blob whose rendition was evicted from the cache is requeued;
 *   - a SOFT-failed blob is soft-checked again in the request: still failing, nothing moves;
 *     passing, it is made due now and the worker kicked;
 *   - a HARD-failed blob is made due now only once its last counted attempt is a retry interval
 *     old, and never once its attempts are spent;
 *   - a view never charges an attempt and never runs anything.
 *
 * Nothing here runs the worker: under the suite Task::dispatch() enqueues a run row and spawns
 * nothing, so "the worker was kicked" is observed as the pending Document_Render_Service
 * render_pending row the kick wrote (each test clears the rows its own upload's kick wrote
 * first, since the drain is #[Exclusive] and coalesces into one pending row). Every test runs in
 * the default rolled-back transaction; the only files it writes (scratch tools, a rendition)
 * are removed in teardown. teardown() runs once per CLASS, so a test that switches a setting
 * off restores it itself.
 */
class Document_Attend_View_Test extends Rsx_Test_Abstract
{
    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function teardown()
    {
        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        config([
            'rsx.search.enabled' => true,
            'rsx.libreoffice.enabled' => true,
            'rsx.libreoffice.sandbox' => 'none',
            'rsx.libreoffice.binary_path' => null,
            'rsx.documents.retry_after_minutes' => 30,
        ]);
    }

    // ============================================================================================
    // HELPERS
    // ============================================================================================

    /**
     * A fresh blob behind an attachment in user 1's site, re-read from the database.
     */
    private static function __make_blob(string $filename): File_Storage_Model
    {
        // User 1's site: the preview payload test reads the attachment back as that user.
        $site_id = (int) DB::selectOne('SELECT site_id FROM users WHERE id = 1')->site_id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_string(
            'RSpade attend view test ' . bin2hex(random_bytes(8)),
            $filename,
            ['site_id' => $site_id]
        );

        return File_Storage_Model::find($attachment->file_storage_id);
    }

    private static function __fresh(File_Storage_Model $storage): File_Storage_Model
    {
        return File_Storage_Model::find($storage->id);
    }

    /**
     * Write queue and outcome columns directly; process_due_at / process_last_attempt_at take a
     * SQL expression string (the database's clock).
     */
    private static function __force(File_Storage_Model $storage, array $values): File_Storage_Model
    {
        foreach (['process_due_at', 'process_last_attempt_at'] as $time_column) {
            if (array_key_exists($time_column, $values) && $values[$time_column] !== null) {
                $values[$time_column] = DB::raw($values[$time_column]);
            }
        }

        File_Storage_Model::where('id', $storage->id)->update($values);

        return static::__fresh($storage);
    }

    /**
     * A script standing in for soffice: executable (the tool is back) or not (still missing).
     */
    private static function __scratch_tool(bool $executable): string
    {
        $path = sys_get_temp_dir() . '/rsx_attend_view_tool_' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/bin/bash\nexit 0\n");
        chmod($path, $executable ? 0755 : 0644);
        static::$created_files[] = $path;

        return $path;
    }

    /**
     * Point the unsandboxed soffice lookup at a scratch tool for the rest of the test.
     */
    private static function __soffice(bool $working): void
    {
        config([
            'rsx.libreoffice.sandbox' => 'none',
            'rsx.libreoffice.binary_path' => static::__scratch_tool($working),
        ]);
    }

    /**
     * Forget every drain run queued so far, so the next kick is visible as a new row.
     */
    private static function __clear_kicks(): void
    {
        DB::table('_tasks')->where('class', Document_Render_Service::class)->delete();
    }

    private static function __kicked(): bool
    {
        return DB::table('_tasks')
            ->where('class', Document_Render_Service::class)
            ->where('method', 'render_pending')
            ->exists();
    }

    /**
     * Whether the row is due now (at or before the database's clock).
     */
    private static function __is_due(File_Storage_Model $storage): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM _file_storage WHERE id = ? AND process_due_at IS NOT NULL AND process_due_at <= NOW(3)',
            [$storage->id]
        )->c === 1;
    }

    /**
     * A .docx blob whose render FAILED SOFTLY: in the queue 30 minutes out, one soft failure on
     * record, no attempt charged. Its text is settled, so only the render is in question.
     */
    private static function __soft_failed_docx(string $filename): File_Storage_Model
    {
        return static::__force(static::__make_blob($filename), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_FAILED,
            'render_error' => 'soffice is not installed',
            'is_indexed' => 1,
            'process_due_at' => 'NOW(3) + INTERVAL 30 MINUTE',
            'process_attempt_count' => 0,
            'process_soft_failure_count' => 1,
            'process_last_attempt_at' => null,
        ]);
    }

    /**
     * A .docx blob whose render FAILED HARD $minutes_ago: one attempt charged then, the next due
     * one retry interval after it.
     */
    private static function __hard_failed_docx(string $filename, int $minutes_ago): File_Storage_Model
    {
        $due_in = 30 - $minutes_ago;

        return static::__force(static::__make_blob($filename), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_FAILED,
            'render_error' => 'the conversion failed',
            'is_indexed' => 1,
            'process_due_at' => "NOW(3) + INTERVAL {$due_in} MINUTE",
            'process_attempt_count' => 1,
            'process_soft_failure_count' => 0,
            'process_last_attempt_at' => "NOW(3) - INTERVAL {$minutes_ago} MINUTE",
        ]);
    }

    /**
     * The preview payload for the attachment behind a blob, as the signed-in user 1.
     */
    private static function __preview_info(File_Storage_Model $storage): array
    {
        static::__acting_as_user(1);

        $attachment_id = (int) File_Attachment_Model::where('file_storage_id', $storage->id)->orderBy('id')->value('id');

        $info = File_Preview_Controller::get_preview_info(Request::create('/x', 'POST'), ['attachment_id' => $attachment_id]);
        if (!is_array($info)) {
            static::__fail('get_preview_info() refused attachment ' . $attachment_id . ': ' . json_encode($info));
        }

        return $info;
    }

    // ============================================================================================
    // AN EVICTED RENDITION
    // ============================================================================================

    // DOCUMENTS-ATTEND-EVICTED-RENDITION: RENDERED, but the rendition file is gone from the cache.
    // The view requeues it - PENDING, owed, a full set of attempts - and kicks the worker.
    public static function test_evicted_rendition_is_requeued()
    {
        $storage = static::__force(static::__make_blob('attend_evicted.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_RENDERED,
            'is_indexed' => 1,
            'process_due_at' => null,
            'process_attempt_count' => 0,
        ]);
        static::__assert_false(file_exists(File_Preview_Controller::rendition_cache_path($storage)), 'precondition: no rendition on disk');
        static::__clear_kicks();

        static::__assert_true(Document_Render_Service::attend_view($storage), 'a retry is under way');

        $reloaded = static::__fresh($storage);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $reloaded->render_status_id, 'the rendition is owed again');
        static::__assert_true(static::__is_due($reloaded), 'and due now');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'with a full set of attempts');
        static::__assert_true(static::__kicked(), 'the worker was kicked');
    }

    // DOCUMENTS-ATTEND-RENDITION-PRESENT: RENDERED with its rendition on disk - nothing to do.
    public static function test_present_rendition_is_left_alone()
    {
        $storage = static::__force(static::__make_blob('attend_present.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_RENDERED,
            'is_indexed' => 1,
            'process_due_at' => null,
        ]);

        $rendition = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($rendition))) {
            mkdir(dirname($rendition), 0755, true);
        }
        file_put_contents($rendition, "%PDF-1.4\n% attend view test\n");
        static::$created_files[] = $rendition;
        static::__clear_kicks();

        static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_RENDERED, (int) static::__fresh($storage)->render_status_id, 'still RENDERED');
        static::__assert_null(static::__fresh($storage)->process_due_at, 'and out of the queue');
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // ============================================================================================
    // A SOFT FAILURE
    // ============================================================================================

    // DOCUMENTS-ATTEND-SOFT-STILL-BROKEN: soft-failed, and the tool is still missing. The check
    // runs again and fails again; nothing moves - not the due time, not either count.
    public static function test_soft_failed_and_still_broken_changes_nothing()
    {
        $storage = static::__soft_failed_docx('attend_soft_broken.docx');
        $due_before = (string) $storage->process_due_at;
        static::__soffice(false);
        static::__clear_kicks();

        static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');

        $reloaded = static::__fresh($storage);
        static::__assert_equals($due_before, (string) $reloaded->process_due_at, 'the backoff\'s due time stands');
        static::__assert_equals(1, (int) $reloaded->process_soft_failure_count, 'the soft count stands - a view records nothing');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'nothing charged');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, (int) $reloaded->render_status_id);
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // DOCUMENTS-ATTEND-SOFT-FIXED: soft-failed, and the tool is back. The blob is made due now and
    // the worker kicked; nothing is charged by the view.
    public static function test_soft_failed_and_fixed_is_retried_now()
    {
        $storage = static::__soft_failed_docx('attend_soft_fixed.docx');
        static::__soffice(true);
        static::__clear_kicks();

        static::__assert_true(Document_Render_Service::attend_view($storage), 'a retry is under way');

        $reloaded = static::__fresh($storage);
        static::__assert_true(static::__is_due($reloaded), 'due now');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'the view charged nothing - the worker charges its attempt');
        static::__assert_null($reloaded->process_last_attempt_at, 'and no attempt time was recorded');
        static::__assert_true(static::__kicked(), 'the worker was kicked');
    }

    // DOCUMENTS-ATTEND-SWITCHED-OFF: a renderer switched off is never retried from a view - the
    // worker would only defer it, and the page would show "Preparing" for nothing.
    public static function test_switched_off_renderer_is_not_retried()
    {
        $storage = static::__soft_failed_docx('attend_switched_off.docx');
        static::__soffice(true);
        static::__clear_kicks();

        config(['rsx.libreoffice.enabled' => false]);
        try {
            static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');
        } finally {
            config(['rsx.libreoffice.enabled' => true]);
        }
        static::__assert_false(static::__is_due(static::__fresh($storage)), 'still waiting');
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // ============================================================================================
    // A HARD FAILURE
    // ============================================================================================

    // DOCUMENTS-ATTEND-HARD-RECENT: hard-failed with the last counted attempt 10 minutes ago - a
    // view never shortens the interval between two counted attempts.
    public static function test_hard_failed_recently_is_left_alone()
    {
        $storage = static::__hard_failed_docx('attend_hard_recent.docx', 10);
        static::__clear_kicks();

        static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');
        static::__assert_false(static::__is_due(static::__fresh($storage)), 'still waiting out its interval');
        static::__assert_equals(1, (int) static::__fresh($storage)->process_attempt_count);
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // DOCUMENTS-ATTEND-HARD-OLD: hard-failed with the last counted attempt a retry interval ago
    // (or longer). The view makes it due now; the attempt count is the worker's to move.
    public static function test_hard_failed_an_interval_ago_is_retried_now()
    {
        // 31 minutes ago, with the next attempt still a minute out (the sweep has not run).
        $storage = static::__force(static::__hard_failed_docx('attend_hard_old.docx', 31), [
            'process_due_at' => 'NOW(3) + INTERVAL 5 MINUTE',
        ]);
        static::__clear_kicks();

        static::__assert_true(Document_Render_Service::attend_view($storage), 'a retry is under way');

        $reloaded = static::__fresh($storage);
        static::__assert_true(static::__is_due($reloaded), 'due now');
        static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'the view charged nothing');
        static::__assert_true(static::__kicked(), 'the worker was kicked');

        // The interval is the config's.
        $later = static::__force(static::__hard_failed_docx('attend_hard_interval.docx', 31), [
            'process_due_at' => 'NOW(3) + INTERVAL 29 MINUTE',
        ]);
        config(['rsx.documents.retry_after_minutes' => 60]);
        try {
            static::__assert_false(Document_Render_Service::attend_view($later), '31 minutes is not an interval of 60');
        } finally {
            config(['rsx.documents.retry_after_minutes' => 30]);
        }
    }

    // DOCUMENTS-ATTEND-HARD-ALREADY-DUE: due already (the drain is simply not running) - the view
    // still kicks it, and reports the retry under way.
    public static function test_hard_failed_already_due_is_kicked()
    {
        $storage = static::__force(static::__hard_failed_docx('attend_hard_due.docx', 45), [
            'process_due_at' => 'NOW(3) - INTERVAL 15 MINUTE',
        ]);
        static::__clear_kicks();

        static::__assert_true(Document_Render_Service::attend_view($storage), 'a retry is under way');
        static::__assert_true(static::__is_due(static::__fresh($storage)), 'still due');
        static::__assert_true(static::__kicked(), 'the worker was kicked');
    }

    // DOCUMENTS-ATTEND-HARD-NO-ATTEMPT-ON-RECORD: a FAILED blob in the queue with no attempt time
    // on record is treated as an old one.
    public static function test_hard_failed_without_an_attempt_time_is_retried()
    {
        $storage = static::__force(static::__hard_failed_docx('attend_hard_null.docx', 10), [
            'process_last_attempt_at' => null,
        ]);
        static::__clear_kicks();

        static::__assert_true(Document_Render_Service::attend_view($storage), 'a retry is under way');
        static::__assert_true(static::__is_due(static::__fresh($storage)), 'due now');
    }

    // DOCUMENTS-ATTEND-SPENT: attempts spent - out of the queue, FAILED for good. A view never
    // puts it back, however long ago it last ran.
    public static function test_spent_attempts_are_never_retried()
    {
        $storage = static::__force(static::__make_blob('attend_spent.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_FAILED,
            'render_error' => 'the conversion failed',
            'is_indexed' => 1,
            'process_due_at' => null,
            'process_attempt_count' => 3,
            'process_last_attempt_at' => 'NOW(3) - INTERVAL 2 DAY',
        ]);
        static::__clear_kicks();

        static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');

        $reloaded = static::__fresh($storage);
        static::__assert_null($reloaded->process_due_at, 'still out of the queue');
        static::__assert_equals(3, (int) $reloaded->process_attempt_count, 'with its attempts spent');
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // ============================================================================================
    // NOTHING TO DO
    // ============================================================================================

    // DOCUMENTS-ATTEND-PENDING: PENDING is already queued; a view changes nothing.
    public static function test_pending_is_left_alone()
    {
        $storage = static::__force(static::__make_blob('attend_pending.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_PENDING,
            'process_due_at' => 'NOW(3) + INTERVAL 10 MINUTE',
        ]);
        static::__clear_kicks();

        static::__assert_false(Document_Render_Service::attend_view($storage), 'nothing is under way');
        static::__assert_false(static::__is_due(static::__fresh($storage)), 'its due time stands');
        static::__assert_false(static::__kicked(), 'no kick');
    }

    // DOCUMENTS-ATTEND-NEVER-CHARGES: however many times a retryable blob is viewed, no attempt is
    // charged by the views - the count moves only when the worker takes it.
    public static function test_repeated_views_never_charge()
    {
        $storage = static::__hard_failed_docx('attend_repeat.docx', 40);

        for ($i = 0; $i < 5; $i++) {
            Document_Render_Service::attend_view(static::__fresh($storage));
        }

        $reloaded = static::__fresh($storage);
        static::__assert_equals(1, (int) $reloaded->process_attempt_count, 'five views, no attempt charged');
        static::__assert_equals(0, (int) $reloaded->process_soft_failure_count);
        static::__assert_true(static::__is_due($reloaded), 'due now');
    }

    // ============================================================================================
    // THE PREVIEW PAYLOAD
    // ============================================================================================

    // DOCUMENTS-ATTEND-PAYLOAD: get_preview_info() attends the view and says so: an evicted
    // rendition is answered as the PENDING it now is; a soft failure still broken is FAILED with
    // no retry; once fixed, FAILED with render_retrying (the widget shows "Preparing preview...").
    public static function test_preview_info_carries_the_retry()
    {
        $evicted = static::__force(static::__make_blob('attend_payload_evicted.docx'), [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_RENDERED,
            'is_indexed' => 1,
            'process_due_at' => null,
        ]);
        $info = static::__preview_info($evicted);
        static::__assert_true($info['render_retrying'], 'the evicted rendition is being retried');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, $info['render_status_id'], 'answered as the PENDING it now is');
        static::__assert_null($info['urls']['rendition'], 'with no rendition url');

        $soft = static::__soft_failed_docx('attend_payload_soft.docx');
        static::__soffice(false);
        $info = static::__preview_info($soft);
        static::__assert_false($info['render_retrying'], 'still broken: no retry');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, $info['render_status_id']);

        static::__soffice(true);
        $info = static::__preview_info($soft);
        static::__assert_true($info['render_retrying'], 'fixed: the retry is under way');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_FAILED, $info['render_status_id'], 'the status is still the last outcome - render_retrying is what changes the widget');
    }
}
