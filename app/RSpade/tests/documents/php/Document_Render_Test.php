<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The async document render pipeline: the blob render state machine, the render worker's unit of
 * work, and the side effects a completed render must produce.
 *
 * This class COMMITS (rows must be visible to the queue query the worker runs, and realtime
 * emission only flushes on commit), so it declares the $requires_db_reset pair. Created
 * attachments are removed in teardown to unlink their on-disk blobs.
 *
 * KICK SUPPRESSION: rsx.search.enabled=false for the whole class. That is the pipeline kick
 * switch (Document_Render_Service::kick), so creating an attachment records its state but never
 * spawns a detached worker - the tests drive render_storage()/render_pending() directly.
 */
class Document_Render_Test extends Rsx_Test_Abstract
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
        config(['rsx.libreoffice.enabled' => true]);
    }

    /**
     * Resolve a real seeded site id and set it as the session site, so both the site-scoped save
     * hook and the site FK on _file_attachments are satisfied in the CLI test harness.
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /**
     * Create a tracked attachment from raw bytes.
     */
    private static function __make_attachment(string $content, string $filename): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($content, $filename, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    /**
     * The blob behind an attachment, re-read from the database (never the cached instance).
     */
    private static function __storage(File_Attachment_Model $attachment): File_Storage_Model
    {
        return File_Storage_Model::find($attachment->file_storage_id);
    }

    /**
     * Unique-per-call bytes, so each test gets its OWN deduplicated blob.
     */
    private static function __unique_bytes(string $marker): string
    {
        return "RSpade render test {$marker} " . bin2hex(random_bytes(8));
    }

    private static function __task(): Task_Instance
    {
        // Immediate (non-DB-backed) instance: info() buffers in memory, heartbeat() no-ops
        // outside a worker - safe for direct in-test invocation.
        return new Task_Instance(Document_Render_Service::class, 'render_pending');
    }

    // ============================================================================================
    // STATE MACHINE
    // ============================================================================================

    // DOCUMENTS-QUEUE-ON-CREATE: a convertible attachment moves its blob NOT_REQUIRED -> PENDING;
    // a non-convertible one leaves it alone. The blob itself never makes this decision - it has
    // only bytes - so this proves the attachment-side hook fires.
    public static function test_convertible_attachment_queues_its_blob()
    {
        $doc = static::__make_attachment(static::__unique_bytes('docx'), 'queue_me.docx');
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            (int) static::__storage($doc)->render_status_id,
            'a convertible document queues its blob for rendering'
        );

        $txt = static::__make_attachment(static::__unique_bytes('txt'), 'plain.txt');
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_NOT_REQUIRED,
            (int) static::__storage($txt)->render_status_id,
            'a plain text file needs no render'
        );
    }

    // DOCUMENTS-DEDUP-LEAVES-RENDERED: a second attachment over already-RENDERED bytes must not
    // reset the blob to PENDING - that would re-render on every upload of a popular document.
    public static function test_second_attachment_leaves_rendered_blob_alone()
    {
        $bytes = static::__unique_bytes('dedup');
        $first = static::__make_attachment($bytes, 'first.docx');

        $storage = static::__storage($first);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->save();

        $second = static::__make_attachment($bytes, 'second.docx');
        static::__assert_equals(
            $storage->id,
            $second->file_storage_id,
            'identical bytes deduplicate onto one blob'
        );
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_RENDERED,
            (int) static::__storage($second)->render_status_id,
            'a RENDERED blob is left alone by a later attachment'
        );
    }

    // DOCUMENTS-REQUEST-IDEMPOTENT: request_render() only ever moves NOT_REQUIRED. FAILED is
    // terminal, so an upload of the same bytes must not silently retry it.
    public static function test_request_render_is_idempotent()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('idem'), 'idem.docx');
        $storage = static::__storage($attachment);

        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
        $storage->render_error = 'previously failed';
        $storage->save();

        $storage->request_render();

        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_FAILED,
            (int) static::__storage($attachment)->render_status_id,
            'FAILED is terminal - request_render() does not re-queue it'
        );
    }

    // ============================================================================================
    // THE UNIT OF WORK
    // ============================================================================================

    // DOCUMENTS-RENDER-MISSING-FILE: a blob whose bytes are gone is a genuine fault, recorded as
    // FAILED with the reason - never a silent skip that re-runs on every sweep.
    public static function test_missing_source_file_records_failure()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('missing'), 'missing.docx');
        $storage = static::__storage($attachment);

        @unlink($storage->get_full_path());

        Document_Render_Service::render_storage($storage);

        $reloaded = static::__storage($attachment);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_FAILED,
            (int) $reloaded->render_status_id,
            'a missing blob renders FAILED'
        );
        static::__assert_contains('missing on disk', (string) $reloaded->render_error, 'the reason is recorded');
        static::__assert_equals(1, (int) $reloaded->is_indexed, 'the extraction half of the queue is settled too');
    }

    // DOCUMENTS-RENDER-SHORTCIRCUIT: a blob whose rendition is already on disk (the backfill case,
    // and the re-queue case) completes WITHOUT a soffice run - proved by the sentinel bytes still
    // being there afterwards, which a real conversion would have overwritten.
    public static function test_existing_rendition_short_circuits_conversion()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('short'), 'short.docx');
        $storage = static::__storage($attachment);

        $rendition_path = File_Preview_Controller::rendition_cache_path($storage);
        $dir = dirname($rendition_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $sentinel = 'NOT-A-REAL-PDF-SENTINEL';
        file_put_contents($rendition_path, $sentinel);
        static::$created_files[] = $rendition_path;

        // Extraction is not the subject here and would spend a soffice run of its own.
        $storage->is_indexed = 1;
        $storage->save();

        Document_Render_Service::render_storage($storage);

        $reloaded = static::__storage($attachment);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_RENDERED,
            (int) $reloaded->render_status_id,
            'an existing rendition completes the render'
        );
        static::__assert_not_empty($reloaded->rendered_at, 'rendered_at is stamped');
        static::__assert_equals(
            $sentinel,
            file_get_contents($rendition_path),
            'no conversion ran - the existing rendition file is untouched'
        );
    }

    // DOCUMENTS-THUMBNAIL-PURGE: every cached thumbnail for the blob is deleted on RENDERED. Those
    // entries were rasterized before a rendition existed, so they hold the generic extension icon
    // under the REAL cache key - poisoned, and a cache hit would never replace them.
    public static function test_render_purges_cached_thumbnails()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('purge'), 'purge.docx');
        $storage = static::__storage($attachment);

        $rendition_path = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($rendition_path))) {
            mkdir(dirname($rendition_path), 0755, true);
        }
        file_put_contents($rendition_path, 'sentinel');
        static::$created_files[] = $rendition_path;

        $poisoned = [];
        foreach (['preset' => 'profile_', 'dynamic' => 'fit_400x400_'] as $cache_type => $prefix) {
            $dir = Rsx_File_Paths::thumbnails_root() . '/' . $cache_type;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $path = $dir . '/' . $prefix . $storage->hash . '_docx.webp';
            file_put_contents($path, 'icon-bytes');
            $poisoned[] = $path;
            static::$created_files[] = $path;
        }

        $storage->is_indexed = 1;
        $storage->save();

        Document_Render_Service::render_storage($storage);

        foreach ($poisoned as $path) {
            static::__assert_false(file_exists($path), 'the poisoned thumbnail cache entry is purged: ' . basename($path));
        }
    }

    // DOCUMENTS-REALTIME-EMIT: the worker emits on the referencing ATTACHMENTS (the blob has no
    // site_id, so a frame published from a CLI task could not be routed from the blob).
    public static function test_render_emits_realtime_for_referencing_attachments()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('realtime'), 'realtime.docx');
        $storage = static::__storage($attachment);

        $rendition_path = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($rendition_path))) {
            mkdir(dirname($rendition_path), 0755, true);
        }
        file_put_contents($rendition_path, 'sentinel');
        static::$created_files[] = $rendition_path;

        $storage->is_indexed = 1;
        $storage->save();

        config(['rsx.realtime.enabled' => true]);
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_start_capture();
        // Web context: the flush STAGES into the request outbox instead of transmitting to a real
        // relay. Intent is captured at flush either way, which is what this test asserts.
        Realtime_Emissions::_testing_set_web_context(true);

        try {
            Document_Render_Service::render_storage($storage);

            $captured = Realtime_Emissions::_testing_captured();
            $found = false;
            foreach ($captured as $emission) {
                if (($emission['model'] ?? null) === 'File_Attachment_Model' && (int) ($emission['id'] ?? 0) === (int) $attachment->id) {
                    $found = true;
                    break;
                }
            }

            static::__assert_true($found, 'a frame was emitted for the attachment referencing the rendered blob');
        } finally {
            Realtime_Emissions::_testing_set_web_context(null);
            Realtime_Emissions::_testing_reset();
            Realtime::_testing_reset();
            config(['rsx.realtime.enabled' => false]);
        }
    }

    // ============================================================================================
    // THE DRAIN LOOP
    // ============================================================================================

    // DOCUMENTS-FAILED-NOT-REQUEUED: the sweeper must never pick a FAILED blob back up. A queue
    // that retries a terminal failure forever is a queue that hides its own failures.
    public static function test_failed_blob_is_not_requeued_by_the_worker()
    {
        $attachment = static::__make_attachment(static::__unique_bytes('terminal'), 'terminal.docx');
        $storage = static::__storage($attachment);

        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
        $storage->render_error = 'soffice said no';
        $storage->is_indexed = 1;
        $storage->save();

        // LibreOffice off keeps the pass from converting any OTHER queued blob in the baseline;
        // the extraction half still drains, which is exactly the behavior being relied on.
        config(['rsx.libreoffice.enabled' => false]);

        try {
            Document_Render_Service::render_pending(static::__task());
        } finally {
            config(['rsx.libreoffice.enabled' => true]);
        }

        $reloaded = static::__storage($attachment);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_FAILED,
            (int) $reloaded->render_status_id,
            'FAILED stays FAILED across a worker pass'
        );
        static::__assert_equals('soffice said no', (string) $reloaded->render_error, 'the recorded reason survives');
    }

    // ============================================================================================
    // THE REAL BINARY
    // ============================================================================================

    // DOCUMENTS-RENDER-REAL-SOFFICE: end to end on real .docx bytes - one soffice run produces the
    // rendition, and the SAME pass extracts the text from that rendition with pdftotext.
    public static function test_real_soffice_render_and_extraction()
    {
        if (Libreoffice::find_soffice() === null) {
            static::__skip('soffice not installed in this environment');
            return;
        }

        $sample = rsx_project_file_path('rsx/resource/sample_documents/sample_memo.docx');
        if (!file_exists($sample)) {
            static::__skip('sample_memo.docx fixture not present');
            return;
        }

        $attachment = static::__make_attachment(file_get_contents($sample), 'render_me.docx');
        $storage = static::__storage($attachment);

        // These exact bytes are also imported by the template app's sample-document seed, so this
        // attachment DEDUPLICATES onto a blob the migrated baseline already carries - in whatever
        // state that baseline left it. Queue-on-create is proved elsewhere (and dedup means a
        // second attachment deliberately does NOT re-queue); what this test measures is a real
        // render of real bytes, so reset the blob to "owes everything" and clear its artifacts.
        Search_Index_Model::forModel('File_Storage_Model', $storage->id)->delete();
        $existing_rendition = File_Preview_Controller::rendition_cache_path($storage);
        if (file_exists($existing_rendition)) {
            @unlink($existing_rendition);
        }
        $storage->is_indexed = 0;
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_PENDING;
        $storage->save();

        Document_Render_Service::render_storage($storage);

        $reloaded = static::__storage($attachment);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_RENDERED,
            (int) $reloaded->render_status_id,
            'the document rendered: ' . (string) $reloaded->render_error
        );

        $rendition_path = File_Preview_Controller::rendition_cache_path($reloaded);
        static::$created_files[] = $rendition_path;
        static::__assert_true(file_exists($rendition_path), 'the PDF rendition is on disk');
        static::__assert_greater_than(0, filesize($rendition_path), 'the rendition has content');

        $index = Search_Index_Model::forModel('File_Storage_Model', $reloaded->id)->first();
        static::__assert_not_empty($index, 'an extraction row was written in the same pass');
        static::__assert_equals(
            Search_Index_Model::STATUS_EXTRACTED,
            (int) $index->status_id,
            'the text extracted: ' . (string) $index->error
        );
        static::__assert_not_empty(trim((string) $index->content), 'extracted text is non-empty');
        static::__assert_equals(
            'Pdftotext_Text_Extractor',
            (string) $index->extraction_method,
            'a Writer document is extracted from its rendition, not by a second soffice run'
        );
    }
}
