<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Cli;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The three operator commands over the document render pipeline: rsx:documents:status,
 * rsx:documents:failed and rsx:documents:rerender - and the registration of the worker's hidden
 * child command, rsx:documents:process_one.
 *
 * Run in-process through Artisan::call() - these commands read and write ordinary rows, so a
 * subprocess would buy nothing but a second boot. The class COMMITS (the commands run their own
 * queries and must see the seeded rows), hence the $requires_db_reset pair.
 *
 * KICK SUPPRESSION: rsx.search.enabled=false for the whole class. That is the guard inside
 * Document_Render_Service::kick(), which both attachment creation and rsx:documents:rerender call -
 * so no detached render worker is ever spawned by this test.
 */
class Documents_Commands_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        config(['rsx.search.enabled' => false]);
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

        config(['rsx.search.enabled' => true]);
    }

    /**
     * Run an artisan command in-process, returning [exit_code, output].
     *
     * @param string $command
     * @param array $args
     * @return array
     */
    private static function __artisan(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    private static function __make_attachment(string $content, string $filename): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($content, $filename, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    private static function __storage(File_Attachment_Model $attachment): File_Storage_Model
    {
        return File_Storage_Model::find($attachment->file_storage_id);
    }

    private static function __unique_bytes(string $marker): string
    {
        return "RSpade documents command test {$marker} " . bin2hex(random_bytes(8));
    }

    /**
     * Force a blob into a render state, and put a sentinel rendition file on disk for it so the
     * deletion half of rsx:documents:rerender is observable.
     *
     * @param File_Storage_Model $storage
     * @param int $status_id
     * @param string|null $error
     * @return string The sentinel rendition path.
     */
    private static function __force_state(File_Storage_Model $storage, int $status_id, ?string $error = null): string
    {
        $storage->render_status_id = $status_id;
        $storage->render_error = $error;
        $storage->rendered_at = $status_id === File_Storage_Model::RENDER_STATUS_RENDERED ? Rsx_Time::now_iso() : null;
        $storage->is_indexed = 1;
        // Out of the queue: a settled blob, and for FAILED one whose attempts are spent.
        $storage->process_due_at = null;
        $storage->process_attempt_count = $status_id === File_Storage_Model::RENDER_STATUS_FAILED ? File_Storage_Model::work_max_attempts() : 0;
        $storage->save();

        $path = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'SENTINEL-RENDITION');
        static::$created_files[] = $path;

        return $path;
    }

    // ============================================================================================
    // rsx:documents:rerender - SELECTOR VALIDATION
    // ============================================================================================

    // Zero selectors and two selectors are the same mistake, and both must fail loud rather than
    // guessing which document the operator meant.
    public static function test_rerender_requires_exactly_one_selector()
    {
        [$code, $output] = static::__artisan('rsx:documents:rerender');
        static::__assert_equals(1, $code, 'no selector exits 1');
        static::__assert_contains('[ERROR] Exactly one selector', $output, 'the refusal names the problem');

        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--all' => true, '--failed' => true]);
        static::__assert_equals(1, $code, 'two selectors exit 1');
        static::__assert_contains('[ERROR] Exactly one selector', $output);
        static::__assert_contains('--all, --failed', $output, 'the refusal echoes what was given');
    }

    // An id that resolves to nothing is an operator typo, not an empty set.
    public static function test_rerender_unknown_storage_id_fails()
    {
        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--storage' => 999999999]);
        static::__assert_equals(1, $code, 'an unknown blob id exits 1');
        static::__assert_contains('[ERROR] No _file_storage row with id 999999999', $output);

        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--attachment' => 999999999]);
        static::__assert_equals(1, $code, 'an unknown attachment id exits 1');
        static::__assert_contains('[ERROR] No file attachment with id 999999999', $output);
    }

    // ============================================================================================
    // rsx:documents:rerender - SELECTION
    // ============================================================================================

    // --failed touches FAILED blobs and nothing else: a RENDERED document is not re-converted on a
    // whim, and a NOT_REQUIRED blob (an image, a text file) must never be handed to soffice.
    public static function test_rerender_failed_requeues_only_failed_blobs()
    {
        $failed = static::__storage(static::__make_attachment(static::__unique_bytes('failed'), 'broken.docx'));
        $rendition = static::__force_state($failed, File_Storage_Model::RENDER_STATUS_FAILED, 'soffice said no');

        $rendered = static::__storage(static::__make_attachment(static::__unique_bytes('rendered'), 'fine.docx'));
        static::__force_state($rendered, File_Storage_Model::RENDER_STATUS_RENDERED);

        $not_required = static::__storage(static::__make_attachment(static::__unique_bytes('plain'), 'plain.txt'));
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_NOT_REQUIRED,
            (int) $not_required->render_status_id,
            'precondition: a text file never enters the render pipeline'
        );

        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--failed' => true]);
        static::__assert_equals(0, $code, 'the re-queue succeeds: ' . $output);
        static::__assert_contains('[OK] Re-queued', $output);

        $reloaded = File_Storage_Model::find($failed->id);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            (int) $reloaded->render_status_id,
            'the FAILED blob is back in the queue'
        );
        static::__assert_true($reloaded->render_error === null, 'the previous reason is cleared');
        static::__assert_true($reloaded->rendered_at === null, 'rendered_at is cleared');
        static::__assert_false(file_exists($rendition), 'the rendition file is deleted so the render really re-runs');
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'the operator reset: attempts back to 0');
        static::__assert_equals(
            1,
            File_Storage_Model::work_due_query()->where('id', $failed->id)->count(),
            'and due now - the blob that was out of attempts is back in the queue'
        );

        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_RENDERED,
            (int) File_Storage_Model::find($rendered->id)->render_status_id,
            'a RENDERED blob is untouched by --failed'
        );
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_NOT_REQUIRED,
            (int) File_Storage_Model::find($not_required->id)->render_status_id,
            'a NOT_REQUIRED blob is untouched by --failed'
        );
    }

    // --attachment names a document the way an operator sees it (the attachment), and re-queues the
    // deduplicated blob behind it.
    public static function test_rerender_attachment_requeues_its_blob()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('byattachment'), 'by_attachment.docx');
        $storage = static::__storage($attachment);
        $rendition = static::__force_state($storage, File_Storage_Model::RENDER_STATUS_RENDERED);

        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--attachment' => $attachment->id]);
        static::__assert_equals(0, $code, 'the re-queue succeeds: ' . $output);
        static::__assert_contains('[OK] Re-queued 1 document(s)', $output);

        $reloaded = File_Storage_Model::find($storage->id);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            (int) $reloaded->render_status_id,
            "the attachment's blob is queued"
        );
        static::__assert_false(file_exists($rendition), 'its rendition is deleted');
        static::__assert_equals(
            1,
            File_Storage_Model::work_due_query()->where('id', $storage->id)->count(),
            'and it is due now'
        );
    }

    // --failed is the reset for a blob the worker is STILL retrying too: mid-cycle, with attempts
    // charged and a retry scheduled, it goes back to a full set of attempts, due now.
    public static function test_rerender_resets_a_blob_waiting_for_a_retry()
    {
        $storage = static::__storage(static::__make_attachment(static::__unique_bytes('midcycle'), 'mid_cycle.docx'));
        static::__force_state($storage, File_Storage_Model::RENDER_STATUS_FAILED, 'render exceeded 20 seconds');
        File_Storage_Model::where('id', $storage->id)->update([
            'process_attempt_count' => 2,
            'process_due_at' => DB::raw('NOW(3) + INTERVAL 25 MINUTE'),
        ]);

        [$code, $output] = static::__artisan('rsx:documents:rerender', ['--storage' => $storage->id]);
        static::__assert_equals(0, $code, 'the re-queue succeeds: ' . $output);

        $reloaded = File_Storage_Model::find($storage->id);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $reloaded->render_status_id);
        static::__assert_equals(0, (int) $reloaded->process_attempt_count, 'attempts back to 0');
        static::__assert_equals(
            1,
            File_Storage_Model::work_due_query()->where('id', $storage->id)->count(),
            'due now, not in 25 minutes'
        );
    }

    // The worker's child command is not an operator command: it is registered (the worker spawns
    // it by name) and absent from `php artisan list`.
    public static function test_child_command_is_hidden_from_list()
    {
        [$code, $output] = static::__artisan('list');
        static::__assert_equals(0, $code);
        static::__assert_contains('rsx:documents:rerender', $output, 'precondition: the operator commands are listed');
        static::__assert_false(str_contains($output, 'rsx:documents:process_one'), 'the child command is not listed');

        [$code, $output] = static::__artisan('rsx:documents:process_one', ['storage_id' => 999999999]);
        static::__assert_equals(1, $code, 'it is registered all the same, and exits 1 for a blob that does not exist');
    }

    // ============================================================================================
    // rsx:documents:status
    // ============================================================================================

    // The status screen must name every state that exists, including the ones currently holding no
    // rows - a state missing from the table reads as "not possible here".
    public static function test_status_names_every_state()
    {
        [$code, $output] = static::__artisan('rsx:documents:status');
        static::__assert_equals(0, $code, 'status always exits 0');

        foreach (File_Storage_Model::render_status_id__enum() as $definition) {
            static::__assert_contains($definition['label'], $output, 'the render state is listed: ' . $definition['label']);
        }

        foreach (Search_Index_Model::status_id__enum() as $definition) {
            static::__assert_contains($definition['label'], $output, 'the extraction state is listed: ' . $definition['label']);
        }

        static::__assert_contains('Owed (is_indexed=0)', $output, 'the text still owed is a row');
        static::__assert_contains('Work queue', $output, 'the queue section is present');
        foreach (['Due now', 'Waiting for a retry', 'Could not run: tool or source unavailable', 'Deferred', 'Failed, out of attempts'] as $queue_state) {
            static::__assert_contains($queue_state, $output, 'the queue state is listed: ' . $queue_state);
        }
        static::__assert_contains('Rendition cache (tmp/renditions', $output, 'the rendition cache section is present');
        static::__assert_contains('Worker schedule:', $output, 'the closing worker line is present');
    }

    // ============================================================================================
    // rsx:documents:failed
    // ============================================================================================

    // Nothing failed is the good case and says so; a failure is listed by the name a human uploaded
    // it under, with the recorded reason; and --limit truncates the TABLE while the header keeps
    // stating the full count.
    public static function test_failed_lists_render_failures_and_honors_limit()
    {
        static::__assert_equals(
            0,
            File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_FAILED)->count(),
            'precondition: the migrated baseline carries no failed renders'
        );

        [$code, $output] = static::__artisan('rsx:documents:failed');
        static::__assert_equals(0, $code, 'failed always exits 0');
        static::__assert_contains('[OK] No failed documents.', $output, 'the good case says so plainly');

        $first = static::__storage(static::__make_attachment(static::__unique_bytes('f1'), 'quarterly_report.docx'));
        static::__force_state($first, File_Storage_Model::RENDER_STATUS_FAILED, 'LibreOffice PDF conversion failed: source format not recognised');

        [$code, $output] = static::__artisan('rsx:documents:failed');
        static::__assert_equals(0, $code);
        static::__assert_contains('1 failed document(s)', $output, 'the header states the count');
        static::__assert_contains('quarterly_report.docx', $output, 'the blob is named by a referencing attachment');
        static::__assert_contains('LibreOffice PDF conversion failed', $output, 'the recorded reason is shown');
        static::__assert_contains('rsx:documents:rerender --failed', $output, 'the remedy is named');
        static::__assert_contains('0 waiting for another attempt, 1 out of attempts', $output, 'the header says where the failures stand');
        static::__assert_contains('3 of 3', $output, 'the row shows its attempts');
        static::__assert_contains('none - out of attempts', $output, 'and that no further attempt is coming');

        // The same blob mid-cycle: one attempt charged, the next one scheduled.
        File_Storage_Model::where('id', $first->id)->update([
            'process_attempt_count' => 1,
            'process_due_at' => DB::raw('NOW(3) + INTERVAL 30 MINUTE'),
        ]);

        [$code, $output] = static::__artisan('rsx:documents:failed');
        static::__assert_contains('1 waiting for another attempt, 0 out of attempts', $output, 'a failure the worker will retry is counted as waiting');
        static::__assert_contains('1 of 3', $output, 'with the attempts charged so far');

        File_Storage_Model::where('id', $first->id)->update(['process_attempt_count' => 3, 'process_due_at' => null]);

        $second = static::__storage(static::__make_attachment(static::__unique_bytes('f2'), 'agenda.docx'));
        static::__force_state($second, File_Storage_Model::RENDER_STATUS_FAILED, 'LibreOffice produced no PDF rendition');

        [$code, $output] = static::__artisan('rsx:documents:failed', ['--limit' => 1]);
        static::__assert_equals(0, $code);
        static::__assert_contains('2 failed document(s)', $output, 'the header states the FULL count even when truncated');
        static::__assert_contains('showing 1 of 2', $output, 'the truncation is stated');

        foreach ([$first, $second] as $storage) {
            File_Storage_Model::where('id', $storage->id)->update(['render_status_id' => File_Storage_Model::RENDER_STATUS_NOT_REQUIRED, 'render_error' => null]);
        }
    }

    // A SOFT failure - the last pass could not run because a tool or the source is unavailable -
    // is listed once, in a table of its own with its reason, how many checks have failed and
    // when the next one is; it is not in the hard-failure tables (no attempt was charged, and the
    // remedy is the environment, not a re-queue). rsx:documents:status counts it in its own row.
    public static function test_failed_lists_soft_failures_separately()
    {
        [$code, $output] = static::__artisan('rsx:documents:failed');
        static::__assert_contains('[OK] No documents waiting on a tool or source file.', $output, 'no soft failure: the good case says so');

        $storage = static::__storage(static::__make_attachment(static::__unique_bytes('soft'), 'board_minutes.docx'));
        static::__force_state($storage, File_Storage_Model::RENDER_STATUS_FAILED, 'LibreOffice (soffice) is not installed or not executable');
        File_Storage_Model::where('id', $storage->id)->update([
            'process_attempt_count' => 0,
            'process_soft_failure_count' => 2,
            'process_due_at' => DB::raw('NOW(3) + INTERVAL 60 MINUTE'),
        ]);

        // A soft failure that owed only text: the reason is on the index row.
        $text_only = static::__storage(static::__make_attachment(static::__unique_bytes('soft-text'), 'scan_notes.pdf'));
        File_Storage_Model::where('id', $text_only->id)->update([
            'render_status_id' => File_Storage_Model::RENDER_STATUS_NOT_REQUIRED,
            'is_indexed' => 0,
            'process_attempt_count' => 0,
            'process_soft_failure_count' => 1,
            'process_due_at' => DB::raw('NOW(3) + INTERVAL 30 MINUTE'),
        ]);
        $index = Search_Index_Model::find_or_create_for_model('File_Storage_Model', $text_only->id);
        $index->status_id = Search_Index_Model::STATUS_FAILED;
        $index->error = 'pdftotext (poppler-utils) is not installed or not executable';
        $index->extraction_method = 'none';
        $index->save();

        [$code, $output] = static::__artisan('rsx:documents:failed');
        static::__assert_equals(0, $code);
        static::__assert_contains('2 document(s) could not be processed - tool or source unavailable', $output, 'the soft table states its count');
        static::__assert_contains('board_minutes.docx', $output, 'the blob is named');
        static::__assert_contains('LibreOffice (soffice) is not installed', $output, 'with the render reason');
        static::__assert_contains('scan_notes.pdf', $output, 'a text-only soft failure is listed too');
        static::__assert_contains('pdftotext (poppler-utils) is not installed', $output, 'with the index row\'s reason');
        static::__assert_contains('Checks failed', $output, 'the soft count is a column');
        static::__assert_contains('Next check', $output, 'and so is the next check');
        static::__assert_equals(1, substr_count($output, 'board_minutes.docx'), 'listed once: the hard render table does not list a soft failure');
        static::__assert_equals(1, substr_count($output, 'scan_notes.pdf'), 'listed once: nor does the hard extraction table');

        [$code, $output] = static::__artisan('rsx:documents:status');
        static::__assert_true(
            (bool) preg_match('/Could not run: tool or source unavailable \(nothing charged\)\s*\|\s*2\s*\|/', $output),
            'rsx:documents:status counts both soft failures in their own row'
        );

        foreach ([$storage, $text_only] as $blob) {
            File_Storage_Model::where('id', $blob->id)->update([
                'render_status_id' => File_Storage_Model::RENDER_STATUS_NOT_REQUIRED,
                'render_error' => null,
                'is_indexed' => 1,
                'process_soft_failure_count' => 0,
                'process_due_at' => null,
            ]);
        }
        Search_Index_Model::forModel('File_Storage_Model', $text_only->id)->delete();
    }
}
