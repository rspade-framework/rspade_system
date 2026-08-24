<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Exception;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Rendition_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Document_Render_Service - THE background document render pipeline (framework core).
 *
 * ONE worker owns every heavy document operation: the soffice->PDF rendition (which feeds both
 * the Document_Preview viewer AND the Office-document thumbnail) and the text extraction that
 * makes the document searchable. Nothing renders inside a web request any more.
 *
 * WHY ONE WORKER. Before this, a single Word document was converted THREE times - once for the
 * thumbnail, once again for the viewer's rendition, and once more (to text) for search - by three
 * callers competing for a two-slot semaphore inside web requests. Renditions and thumbnails are
 * both content-addressed on the deduplicated blob hash, so all three were producing artifacts of
 * the same bytes. Collapsing them into one #[Exclusive] worker makes soffice single-threaded by
 * construction: this class is now the ONLY caller of the binary for renditions, so the semaphore
 * that bounded concurrent invocations has nothing left to bound and is gone.
 *
 * THE QUEUE IS TWO COLUMNS, no side table:
 *   _file_storage.render_status_id = PENDING  -> a rendition is owed.
 *   _file_storage.is_indexed = 0              -> text extraction is owed.
 * A blob may owe either, or both; render_storage() settles both in one pass, and every attempt
 * moves the row OUT of the queue (RENDERED or FAILED, is_indexed=1), which is what terminates the
 * drain loop.
 *
 * FAILED IS TERMINAL. A document that soffice cannot convert will not convert on the next sweep
 * either, and a queue that retries forever is a queue that hides its own failures. The reason is
 * recorded in render_error, surfaced by rsx:documents:failed, and re-queued only by an explicit
 * operator action (rsx:documents:rerender).
 *
 * Kicked promptly on upload (File_Storage_Model::find_or_create and
 * File_Attachment_Model::request_render's dispatch) and swept every 10 minutes so a killed worker
 * resumes without anyone noticing.
 */
class Document_Render_Service extends Rsx_Service_Abstract
{
    /**
     * THE prompt kick: ask for a render pass now rather than waiting for the 10-minute sweep.
     *
     * One choke point with one guard, called from the two places new work appears (a new blob in
     * File_Storage_Model::find_or_create, and a blob entering PENDING in request_render).
     * rsx.search.enabled is the kick switch - when it is off no worker is spawned on upload, and
     * the state written to the row is still the truth, so the sweeper picks the work up whenever
     * the switch comes back. #[Exclusive] coalesces concurrent kicks into one pass.
     *
     * @return void
     */
    public static function kick(): void
    {
        if (!config('rsx.search.enabled', true)) {
            return;
        }

        Task::dispatch('Document_Render_Service', 'render_pending');
    }

    /**
     * Drain the render queue: one blob per iteration until nothing is owed.
     *
     * MASTER SWITCHES - the two halves of the pipeline are governed separately, and this is the
     * subtle part. rsx.libreoffice.enabled governs RENDERING (there is no other way to make a
     * rendition), while rsx.search.enabled governs EXTRACTION. A box with LibreOffice switched off
     * must still extract PDFs and plain text, so the extraction half of the queue keeps draining;
     * what it must NOT do is pick up PENDING rows, because with no soffice they cannot leave
     * PENDING and the loop would spin forever on the same lowest-id row. So when LibreOffice is
     * disabled the queue narrows to "is_indexed = 0" and PENDING rows simply wait - they are still
     * queued, and the day soffice is installed the sweeper renders them.
     *
     * @param Task_Instance $task
     * @param array $params
     * @return array
     */
    #[Task('Render documents: PDF rendition + text extraction, one blob at a time')]
    #[Exclusive]
    #[Schedule('every 10 minutes')]
    public static function render_pending(Task_Instance $task, array $params = []): array
    {
        $render_enabled = (bool) config('rsx.libreoffice.enabled', true);
        $extract_enabled = (bool) config('rsx.search.enabled', true);

        if (!$render_enabled && !$extract_enabled) {
            $task->info('Document render pipeline disabled (rsx.libreoffice.enabled=false, rsx.search.enabled=false) - nothing to do');
            return ['processed' => 0];
        }

        $processed = 0;

        while (true) {
            $storage = static::__next_queued($render_enabled, $extract_enabled);
            if (!$storage) {
                break;
            }

            $task->heartbeat();

            static::render_storage($storage);
            $processed++;

            $task->info("Rendered storage #{$storage->id} [{$storage->render_status_id__label}]");
        }

        if ($processed > 0) {
            $task->info("Render pass complete: {$processed} blob(s) processed");
        }

        return ['processed' => $processed];
    }

    /**
     * The lowest-id blob still owing work, or null when the queue is empty.
     *
     * @param bool $render_enabled Include PENDING renditions in the queue.
     * @param bool $extract_enabled Include un-indexed blobs in the queue.
     * @return File_Storage_Model|null
     */
    protected static function __next_queued(bool $render_enabled, bool $extract_enabled): ?File_Storage_Model
    {
        $query = File_Storage_Model::query();

        if ($render_enabled && $extract_enabled) {
            $query->where(function ($sub) {
                $sub->where('render_status_id', File_Storage_Model::RENDER_STATUS_PENDING)
                    ->orWhere('is_indexed', 0);
            });
        } elseif ($render_enabled) {
            $query->where('render_status_id', File_Storage_Model::RENDER_STATUS_PENDING);
        } else {
            $query->where('is_indexed', 0);
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Render ONE blob: produce its PDF rendition if one is owed, extract its text if that is owed,
     * settle its render state, and tell every screen showing it to look again.
     *
     * This is the unit of work - callable directly from a test, from rsx:documents:rerender, and
     * from the drain loop. It NEVER throws: a per-blob \Throwable becomes FAILED with the message
     * recorded, because one bad document must not stop the queue behind it.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    public static function render_storage(File_Storage_Model $storage): void
    {
        $needs_render = (int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_PENDING
            && config('rsx.libreoffice.enabled', true);
        $needs_extract = (int) $storage->is_indexed === 0;

        $rendition_path = null;

        try {
            $source_path = $storage->get_full_path();
            if (!file_exists($source_path)) {
                // The blob should always be resident (content-addressed local cache); a missing
                // file is a genuine fault. Let the extractor record its own FAILED index row
                // first - that is the row rsx:search:reindex --failed and the audit read - then
                // throw so the render state settles FAILED too.
                if ($needs_extract) {
                    Search_Index_Service::extract_storage($storage, null);
                }

                throw new Exception('source blob missing on disk: ' . $source_path);
            }

            if ($needs_render) {
                $rendition_path = File_Preview_Controller::rendition_cache_path($storage);

                // Short-circuit: the rendition already exists. Real, and not an edge case - every
                // blob converted by the old synchronous path is backfilled to PENDING with its PDF
                // already on disk, and a re-queued blob may race the LRU cleanup. No soffice run.
                if (!file_exists($rendition_path)) {
                    static::__convert_to_pdf($source_path, static::representative_extension($storage), $rendition_path);
                }
            } elseif ((int) $storage->render_status_id === File_Storage_Model::RENDER_STATUS_RENDERED) {
                // Already rendered, but extraction may still be owed: hand the extractor the
                // rendition when it is genuinely on disk.
                $existing = File_Preview_Controller::rendition_cache_path($storage);
                $rendition_path = file_exists($existing) ? $existing : null;
            }

            if ($needs_extract) {
                Search_Index_Service::extract_storage($storage, $rendition_path);
            }
        } catch (\Throwable $e) {
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
            $storage->render_error = $e->getMessage();
            $storage->rendered_at = null;
            $storage->save();

            // Extraction is still OWED when the failure happened before it ran (the
            // conversion threw first). Attempt it now rather than settling is_indexed by
            // hand: extract_storage() never throws - it records its own FAILED/UNSUPPORTED
            // row and sets is_indexed itself - and a flag flipped without a row would leave
            // get_extraction_status() reporting "pending" forever while the queue believed
            // the work was done. A corrupt document fails extraction the same way it failed
            // conversion, and that failure is then RECORDED where reindex --failed can see it.
            if ((int) $storage->is_indexed === 0) {
                Search_Index_Service::extract_storage($storage, null);
            }

            static::notify_attachments($storage);

            return;
        }

        if ($needs_render) {
            $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
            $storage->rendered_at = Rsx_Time::now_iso();
            $storage->render_error = null;
            $storage->save();

            // The thumbnail cache for these bytes is now WRONG. Every entry in it was rasterized
            // before a rendition existed, which means it is the generic extension icon written
            // under the real cache key - poisoned, and never replaced by a later success because
            // a cache hit is a cache hit. Purge them so the next request rasterizes the rendition.
            static::purge_thumbnail_cache($storage);
        }

        static::notify_attachments($storage);
    }

    /**
     * Publish a realtime frame for every attachment referencing this blob, so open pages swap
     * their placeholder for the real thumbnail without a reload.
     *
     * The frame goes on the ATTACHMENTS, not the blob: a realtime frame from a CLI task carries
     * the row's site_id (Realtime_Emissions::_resolve_site_id) and _file_storage has none, while
     * an attachment does. Subscribers therefore watch by attachment id, which is also the identity
     * <Attachment_Thumbnail> is built around.
     *
     * PUBLIC because it is not only the worker's business: rsx:documents:rerender flips a single
     * blob back to PENDING with a bulk UPDATE (which emits nothing - _file_storage is not a
     * realtime model), and the open pages showing that document must fall back to the placeholder
     * immediately. One notification, spelled once.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    public static function notify_attachments(File_Storage_Model $storage): void
    {
        // One deduplicated blob may back attachments across several sites, and this task carries
        // no site context at all, so read without the site scope - otherwise the frames that
        // matter most (another tenant's open page) would never be sent.
        File_Attachment_Model::without_site_scope(function () use ($storage) {
            $attachments = File_Attachment_Model::where('file_storage_id', $storage->id)->result_set();

            foreach ($attachments as $attachment) {
                $attachment->realtime_emit();
            }
        });
    }

    /**
     * Delete every cached thumbnail derived from this blob's bytes.
     *
     * Cache filenames embed the blob hash ({preset}_{hash}_{ext}.webp and
     * {type}_{w}x{h}_{hash}_{ext}.webp), so one glob per cache directory catches all of them
     * regardless of preset, size or the extension of the attachment that requested them.
     *
     * @param File_Storage_Model $storage
     * @return int Number of cache files deleted.
     */
    public static function purge_thumbnail_cache(File_Storage_Model $storage): int
    {
        $deleted = 0;
        $root = Rsx_File_Paths::thumbnails_root();

        foreach (['preset', 'dynamic'] as $cache_type) {
            foreach (glob($root . '/' . $cache_type . '/*_' . $storage->hash . '_*.webp') as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * The file extension to stage this blob under for soffice, borrowed from an attachment that
     * references it (a content-addressed blob has no extension of its own, and soffice detects the
     * input format from the filename). Prefers an attachment whose extension is a known document
     * type - a blob shared by a .docx and a .zip upload converts as the docx - then any extension.
     *
     * @param File_Storage_Model $storage
     * @return string|null null for an orphan blob (no referencing attachment).
     */
    public static function representative_extension(File_Storage_Model $storage): ?string
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));

        // Cross-site blob, no site context in a task: see notify_attachments().
        return File_Attachment_Model::without_site_scope(function () use ($storage, $document_extensions) {
            // withTrashed(): the render is keyed on the deduplicated blob, so a representative
            // extension is valid regardless of any one attachment's soft-delete state.
            $preferred = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->value('file_extension');
            if ($preferred !== null && $preferred !== '') {
                return $preferred;
            }

            $any = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNotNull('file_extension')
                ->where('file_extension', '!=', '')
                ->orderBy('id')
                ->value('file_extension');

            return ($any === null || $any === '') ? null : $any;
        });
    }

    /**
     * A human-recognizable file name for this blob, borrowed from a referencing attachment, for
     * operator output (rsx:documents:failed). A content-addressed blob has no name of its own, so
     * this answers "which document is #4711" the only way there is: whatever an upload called it.
     *
     * Same reasoning as representative_extension(): prefer a known document type, fall back to
     * any name, and read withTrashed()/without_site_scope because the blob is shared and the
     * question is about bytes, not about any one attachment's visibility.
     *
     * @param File_Storage_Model $storage
     * @return string|null null for an orphan blob (no referencing attachment).
     */
    public static function representative_file_name(File_Storage_Model $storage): ?string
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));

        return File_Attachment_Model::without_site_scope(function () use ($storage, $document_extensions) {
            $preferred = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->value('file_name');
            if ($preferred !== null && $preferred !== '') {
                return $preferred;
            }

            $any = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNotNull('file_name')
                ->where('file_name', '!=', '')
                ->orderBy('id')
                ->value('file_name');

            return ($any === null || $any === '') ? null : $any;
        });
    }

    /**
     * Convert a source document to PDF via headless LibreOffice, publishing atomically into the
     * rendition cache. Fail loud - render_storage() turns a throw into a recorded FAILED.
     *
     * @param string $source_path Resident blob path (extensionless, content-addressed).
     * @param string|null $extension The representative attachment's real extension.
     * @param string $cache_path Destination path for the published PDF rendition.
     * @return void
     * @throws Exception
     */
    protected static function __convert_to_pdf(string $source_path, ?string $extension, string $cache_path): void
    {
        $soffice = Libreoffice::find_soffice();
        if ($soffice === null) {
            throw new Exception('LibreOffice (soffice) is not installed or not configured');
        }

        // Lazy-create the rendition cache directory.
        $dir = dirname($cache_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_pdf_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            // Stage the extensionless blob under its real extension so soffice reliably detects the
            // input format; soffice names its output after this basename.
            $safe_ext = preg_replace('/[^a-z0-9]/i', '', (string) $extension);
            if ($safe_ext === '') {
                $safe_ext = 'bin';
            }
            $staged = $work_dir . '/source.' . $safe_ext;
            if (!copy($source_path, $staged)) {
                throw new Exception('Failed to stage source document for PDF rendition');
            }

            $process = new Process([
                $soffice,
                '-env:UserInstallation=file://' . $work_dir . '/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $work_dir,
                $staged,
            ]);

            // THE one sanctioned timeout in this pipeline (rsx.libreoffice.timeout, justified in
            // full at its config key): it bounds the EXTERNAL soffice binary, which does not merely
            // run slowly on malformed input but WEDGES and never returns. Expiry degrades to a
            // working outcome - the blob is recorded FAILED and the extension icon is served.
            $process->setTimeout((int) config('rsx.libreoffice.timeout', 120));
            $process->run();

            if (!$process->isSuccessful()) {
                throw new Exception('LibreOffice PDF conversion failed: ' . trim($process->getErrorOutput()));
            }

            $pdf_path = $work_dir . '/source.pdf';
            if (!file_exists($pdf_path)) {
                throw new Exception('LibreOffice produced no PDF rendition');
            }

            // Atomic publish: copy into a temp file BESIDE the cache path (same filesystem), then
            // rename over the destination. The work dir may be on a different filesystem, so a
            // direct rename from there is not guaranteed atomic.
            $tmp = $cache_path . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($pdf_path, $tmp)) {
                throw new Exception('Failed to write rendition temp file');
            }
            if (!rename($tmp, $cache_path)) {
                @unlink($tmp);
                throw new Exception('Failed to publish rendition file');
            }
        } finally {
            static::__rmdir_recursive($work_dir);
        }
    }

    /**
     * rsx:health probe: how much render/extraction work is queued or has failed.
     *
     * A public static `#[Health_Check('label')]` (bare marker attribute - never a defined class)
     * returning a row per Health_Check_Runner's contract. Read-only: it counts the queue and the
     * terminal failures, nothing else. This is the SINGLE backlog probe for the document pipeline -
     * rendition and extraction are one worker and one queue, so two probes would be two ways of
     * saying the same thing.
     *
     * @return array
     */
    #[Health_Check('Document Render Backlog')]
    public static function render_backlog(): array
    {
        $render_enabled = (bool) config('rsx.libreoffice.enabled', true);
        $extract_enabled = (bool) config('rsx.search.enabled', true);

        if (!$render_enabled && !$extract_enabled) {
            return ['status' => 'INFO', 'detail' => 'disabled by config (rsx.libreoffice.enabled=false, rsx.search.enabled=false)'];
        }

        $pending = File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_PENDING)->count();
        $failed = File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_FAILED)->count();
        $unindexed = File_Storage_Model::where('is_indexed', 0)->count();
        $extract_failed = Search_Index_Model::where('status_id', Search_Index_Model::STATUS_FAILED)->count();

        $summary = "pending={$pending}, failed={$failed}, unindexed={$unindexed}, extract_failed={$extract_failed}";

        // A large queue means the one-at-a-time worker is not keeping up (or not running).
        if ($pending > 500 || $unindexed > 500) {
            return [
                'status' => 'WARN',
                'detail' => $summary . ' - large backlog',
                'remediation' => 'the render worker may not be running - check rsx:task:process and php artisan rsx:documents:status',
            ];
        }

        if ($failed > 0 || $extract_failed > 0) {
            return [
                'status' => 'WARN',
                'detail' => $summary,
                'remediation' => 'inspect with php artisan rsx:documents:status (and rsx:documents:failed), then re-queue with rsx:documents:rerender --failed',
            ];
        }

        return ['status' => 'OK', 'detail' => $summary];
    }

    /**
     * Counts and cache figures for rsx:documents:status.
     *
     * @return array
     */
    public static function get_statistics(): array
    {
        $render_counts = [];
        foreach (File_Storage_Model::render_status_id__enum() as $id => $definition) {
            $render_counts[$definition['label']] = File_Storage_Model::where('render_status_id', $id)->count();
        }

        $extraction_counts = [];
        foreach (Search_Index_Model::status_id__enum() as $id => $definition) {
            $extraction_counts[$definition['label']] = Search_Index_Model::where('indexable_type', 'File_Storage_Model')
                ->where('status_id', $id)
                ->count();
        }

        return [
            'render' => $render_counts,
            'extraction' => $extraction_counts,
            'unindexed' => File_Storage_Model::where('is_indexed', 0)->count(),
            'renditions' => File_Rendition_Service::get_statistics(),
        ];
    }

    /**
     * Recursively remove a directory tree (best-effort cleanup of the temp work dir).
     *
     * @param string $dir
     * @return void
     */
    protected static function __rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                static::__rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
