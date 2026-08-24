<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Async document rendering - render lifecycle columns on _file_storage.
 *
 * All LibreOffice work (the PDF rendition that feeds both the viewer and the Office-document
 * thumbnail, plus text extraction) moves out of the web request into ONE background worker,
 * Document_Render_Service. The state that worker drives off lives on the BLOB, not the
 * attachment, because both products of a render - the rendition (storage/rsx-renditions/
 * {hash}.pdf) and the thumbnail cache key - are already content-addressed on the blob hash.
 * Ten attachments sharing one deduplicated blob therefore share one render.
 *
 * - render_status_id: 1=NOT_REQUIRED / 2=PENDING / 3=RENDERED / 4=FAILED. DEFAULT 1 because
 *   store_blob() sees only bytes: a new blob is inserted NOT_REQUIRED and the ATTACHMENT -
 *   which knows the filename, extension and therefore the pipeline mime - flips it to PENDING
 *   when the mime matches rsx.preview.convertible. Dedup falls out of that: a blob already
 *   PENDING / RENDERED / FAILED is left alone by every later attachment.
 * - rendered_at: when the render completed. Doubles as the ?v= cache-buster on thumbnail URLs,
 *   which are otherwise served max-age=31536000 and would pin a placeholder for a year.
 * - render_error: the failure detail for FAILED. FAILED is TERMINAL - the sweeper never
 *   re-queues it; re-rendering is an explicit operator action (rsx:documents:rerender).
 * - idx_render_status: the worker's queue query is "the lowest-id row in PENDING", and the
 *   status command counts per status, so the column is indexed.
 *
 * BACKFILL. Blobs that already exist were never marked, so without this UPDATE they would sit
 * NOT_REQUIRED forever and never render. Every blob referenced by a LIVE attachment whose
 * extension is a convertible Office format enters PENDING here. The extension list is the
 * spelling of rsx.preview.convertible in file-extension terms - a migration must not read
 * config (config drifts, the executed transformation must not), so it is written out literally.
 * deleted_at IS NULL: an attachment inside the retention window still pins its blob, but there
 * is no screen showing it, so there is no reason to spend a soffice run on it now; if it is
 * undeleted, nothing re-queues it and an operator uses rsx:documents:rerender. The worker
 * short-circuits any blob whose rendition already exists on disk, so a backfilled blob whose
 * PDF was produced by the old synchronous path costs no conversion - just the state flip and
 * the (required) purge of its poisoned icon-thumbnail cache entries.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_storage ADD COLUMN render_status_id BIGINT NOT NULL DEFAULT 1 AFTER is_indexed");
        DB::statement("ALTER TABLE _file_storage ADD COLUMN rendered_at TIMESTAMP(3) NULL DEFAULT NULL AFTER render_status_id");
        DB::statement("ALTER TABLE _file_storage ADD COLUMN render_error TEXT NULL AFTER rendered_at");

        DB::statement("CREATE INDEX idx_render_status ON _file_storage(render_status_id)");

        // Backfill: existing convertible-mime blobs enter PENDING (2).
        DB::statement("
            UPDATE _file_storage
               SET render_status_id = 2
             WHERE id IN (
                   SELECT file_storage_id
                     FROM _file_attachments
                    WHERE deleted_at IS NULL
                      AND file_storage_id IS NOT NULL
                      AND file_extension IN ('doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','rtf')
             )
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
