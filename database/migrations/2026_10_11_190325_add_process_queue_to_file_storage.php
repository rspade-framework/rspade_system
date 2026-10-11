<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The document worker's queue becomes ONE column on _file_storage.
 *
 * Document_Render_Service takes one blob at a time and produces what it owes: a preview
 * rendition (render_status_id) and its extracted text (is_indexed). Which blob is next, and
 * when, is answered by two columns:
 *
 * - process_due_at: NULL = the worker has nothing to do for this blob (nothing is owed, or
 *   every attempt it is allowed has been spent). A time = the blob is owed work and may be
 *   attempted at or after that moment. A new row is born due, because a new blob owes its
 *   text extraction (is_indexed defaults to 0), so the column defaults to the insert time.
 *   A failed attempt pushes the time forward, which is what lets the queue behind a
 *   document that cannot be processed keep moving.
 * - process_attempt_count: attempts charged since the blob last entered the queue. Charged
 *   BEFORE an attempt starts, so an attempt that dies with its process is still counted.
 * - idx_process_due (process_due_at, id): the worker's question is "the earliest due row",
 *   WHERE process_due_at <= NOW(3) ORDER BY process_due_at, id LIMIT 1 - one range read
 *   from the front of this index however many rows the table holds, and NULL rows (the
 *   overwhelming majority) are never visited.
 *
 * BACKFILL. Every blob owing work today is due now with no attempts charged: a rendition
 * that is PENDING (2), or text that is not extracted yet (is_indexed = 0). A blob whose
 * render FAILED (4) and whose text is settled has already had its outcome recorded; it
 * keeps it, with every attempt spent (3, the attempt limit at the time of this migration)
 * and no due time. Rows that owe nothing get no due time either.
 *
 * idx_render_status and idx_is_indexed are kept: the per-state counts of
 * rsx:documents:status and the rsx:health backlog row, and the FAILED listing of
 * rsx:documents:failed, still read them.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_storage ADD COLUMN process_due_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER render_error");
        DB::statement("ALTER TABLE _file_storage ADD COLUMN process_attempt_count INT NOT NULL DEFAULT 0 AFTER process_due_at");

        // Adding the column stamped every existing row with the default; only the rows that
        // owe work keep a due time.
        DB::statement("UPDATE _file_storage SET process_due_at = NULL WHERE render_status_id <> 2 AND is_indexed = 1");
        DB::statement("UPDATE _file_storage SET process_attempt_count = 3 WHERE render_status_id = 4 AND is_indexed = 1");

        DB::statement("CREATE INDEX idx_process_due ON _file_storage(process_due_at, id)");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
