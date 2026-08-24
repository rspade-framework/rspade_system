<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Documents;

use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use Illuminate\Console\Command;

/**
 * rsx:documents:rerender - put documents back in the render queue.
 *
 * FAILED is terminal and RENDERED is final, so this command is the ONLY way a blob that has
 * already been through the pipeline goes through it again. Re-queuing is uniform: resolve a set of
 * _file_storage rows, DELETE each one's PDF rendition (otherwise render_storage() short-circuits on
 * the file that is already on disk and nothing is actually re-rendered), set the rows back to
 * PENDING with rendered_at/render_error cleared, then kick the worker once.
 *
 * Exactly ONE selector must be given (fail loud otherwise):
 *   --all            every blob that has ever been in the pipeline
 *   --failed         blobs whose last RENDER failed
 *   --storage=ID     one blob by _file_storage id
 *   --attachment=ID  the blob backing one attachment
 */
class Documents_Rerender_Command extends Command
{
    protected $signature = 'rsx:documents:rerender
                            {--all : Re-queue every blob that has ever been in the render pipeline (PENDING/RENDERED/FAILED)}
                            {--failed : Re-queue blobs whose RENDER failed (extraction failures are rsx:search:reindex --failed)}
                            {--storage= : Re-queue one blob by _file_storage id}
                            {--attachment= : Re-queue the blob backing one attachment id}';

    protected $description = 'Re-queue documents for PDF rendering (deletes their renditions first)';

    /** Blob rows per chunk - the ceiling on resident rows however large the match. */
    private const REQUEUE_CHUNK_SIZE = 1000;

    public function handle(): int
    {
        // Exactly one selector.
        $selectors = [
            '--all' => (bool) $this->option('all'),
            '--failed' => (bool) $this->option('failed'),
            '--storage' => $this->option('storage') !== null,
            '--attachment' => $this->option('attachment') !== null,
        ];

        $chosen = array_keys(array_filter($selectors));

        if (count($chosen) !== 1) {
            $this->error('[ERROR] Exactly one selector is required: --all | --failed | --storage=ID | --attachment=ID');
            if (count($chosen) > 1) {
                $this->line('  Given: ' . implode(', ', $chosen));
            }
            return 1;
        }

        $selector = $chosen[0];

        $target = $this->__resolve_target($selector);
        if ($target === null) {
            return 1;
        }

        $queued = $this->__requeue($target, $selector === '--all');

        if ($queued === 0) {
            $this->line('[OK] No blobs matched - nothing re-queued.');
            return 0;
        }

        // A single blob has a knowable, small set of open pages showing it, so tell them now: the
        // bulk UPDATE above emits nothing (_file_storage is not a realtime model), and without a
        // frame an open <Attachment_Thumbnail> keeps showing the render that no longer exists.
        // Doing the same for a set selector would mean one emission per referencing attachment
        // across the whole install, which is not what an operator asked for.
        if (is_array($target)) {
            foreach ($target as $storage) {
                Document_Render_Service::notify_attachments($storage);
            }
        } else {
            $this->line('  Open pages will pick the new render up on their next frame (no bulk notification sent).');
        }

        Document_Render_Service::kick();

        $this->line("[OK] Re-queued {$queued} document(s) for rendering; worker dispatched.");
        return 0;
    }

    /**
     * Resolve the selector to its target rows. Returns null on a resolution error (already
     * reported).
     *
     * The set-valued selectors return a SUB-QUERY, not a list: these can match every rendered blob
     * on the install, and plucking six figures of ids into PHP to hand straight back to MySQL as an
     * IN list is how a maintenance command becomes a memory fatal. Single-row selectors return the
     * model itself in an array, because the caller also notifies on it.
     *
     * @param string $selector
     * @return array<File_Storage_Model>|\Illuminate\Database\Eloquent\Builder|null
     */
    private function __resolve_target(string $selector)
    {
        if ($selector === '--all') {
            // Every blob that has ever been IN the pipeline. Deliberately NOT every row: a blob
            // sitting at NOT_REQUIRED is a JPEG or a text file, and pushing those to PENDING would
            // hand soffice a pile of documents it cannot convert and turn them all FAILED.
            return File_Storage_Model::where('render_status_id', '!=', File_Storage_Model::RENDER_STATUS_NOT_REQUIRED)
                ->select('id');
        }

        if ($selector === '--failed') {
            return File_Storage_Model::where('render_status_id', File_Storage_Model::RENDER_STATUS_FAILED)
                ->select('id');
        }

        if ($selector === '--storage') {
            $id = (int) $this->option('storage');
            $storage = File_Storage_Model::find($id);
            if (!$storage) {
                $this->error("[ERROR] No _file_storage row with id {$id}");
                return null;
            }
            return [$storage];
        }

        // --attachment: withTrashed() is right HERE. This is an operator command, not a fetch()
        // body - the operator is naming a row by id, and a soft-deleted attachment still points at
        // a live deduplicated blob that other attachments may share.
        $attachment_id = (int) $this->option('attachment');
        $attachment = File_Attachment_Model::withTrashed()->find($attachment_id);
        if (!$attachment) {
            $this->error("[ERROR] No file attachment with id {$attachment_id}");
            return null;
        }

        $storage = File_Storage_Model::find($attachment->file_storage_id);
        if (!$storage) {
            $this->error("[ERROR] Attachment {$attachment_id} has no stored blob to re-render");
            return null;
        }

        return [$storage];
    }

    /**
     * Delete the target renditions and set the rows back to PENDING. Returns the count queued.
     *
     * A sub-query target is walked by keyset, so no matter how many blobs match, at most CHUNK rows
     * are ever resident. Keying on id (which the update does not touch) keeps the walk terminating
     * regardless of what the update changes.
     *
     * @param array<File_Storage_Model>|\Illuminate\Database\Eloquent\Builder $target
     * @param bool $all
     * @return int
     */
    private function __requeue($target, bool $all): int
    {
        if (is_array($target)) {
            $ids = [];
            foreach ($target as $storage) {
                $this->__delete_rendition($storage);
                $ids[] = $storage->id;
            }

            return File_Storage_Model::whereIn('id', $ids)->update($this->__pending_values());
        }

        $queued = 0;
        $last_id = 0;

        while (true) {
            $rows = File_Storage_Model::whereIn('id', $target)
                ->where('id', '>', $last_id)
                ->orderBy('id')
                ->limit(self::REQUEUE_CHUNK_SIZE)
                ->get(['id', 'hash']);

            if ($rows->isEmpty()) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                // File deletion ITERATES even for --all: a rendition is one file per blob hash on
                // disk, and there is no set operation that removes them.
                $this->__delete_rendition($row);
                $ids[] = $row->id;
            }

            $last_id = (int) end($ids);

            if (!$all) {
                $queued += File_Storage_Model::whereIn('id', $ids)->update($this->__pending_values());
            } else {
                $queued += count($ids);
            }
        }

        if ($all && $queued > 0) {
            // The files are gone; the state change itself is one set UPDATE over the same rows.
            return File_Storage_Model::where('render_status_id', '!=', File_Storage_Model::RENDER_STATUS_NOT_REQUIRED)
                ->update($this->__pending_values());
        }

        return $queued;
    }

    /**
     * The queued state: PENDING with every artifact of the previous attempt cleared.
     *
     * @return array
     */
    private function __pending_values(): array
    {
        return [
            'render_status_id' => File_Storage_Model::RENDER_STATUS_PENDING,
            'rendered_at' => null,
            'render_error' => null,
        ];
    }

    /**
     * Remove a blob's cached PDF rendition, so the worker genuinely re-renders it rather than
     * short-circuiting on the file already on disk.
     *
     * The row may be a partial select (id + hash) - rendition_cache_path() reads the hash and
     * nothing else, which is the whole point of a content-addressed path.
     *
     * @param File_Storage_Model $storage
     * @return void
     */
    private function __delete_rendition(File_Storage_Model $storage): void
    {
        $path = File_Preview_Controller::rendition_cache_path($storage);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
