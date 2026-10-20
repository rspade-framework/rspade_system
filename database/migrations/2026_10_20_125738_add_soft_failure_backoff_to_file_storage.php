<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The document worker's queue learns the difference between a document that fails and an
 * environment that cannot process it yet.
 *
 * A SOFT failure is found before an attempt, by the renderer's or extractor's soft_failure()
 * check: the tool the pass needs is not installed or not executable, the sandbox image is
 * absent, the source blob cannot be read. It is recorded like any failure but charges no
 * attempt (process_attempt_count is untouched), and the blob is checked again on a backoff that
 * doubles and never ends - so a box repaired later still processes what it could not before.
 * Every other failure is HARD and keeps the attempt rules process_attempt_count already counts.
 *
 * - process_soft_failure_count: consecutive soft failures since the blob last ran a pass (or
 *   last entered the queue). It is the exponent of the backoff - the next check is
 *   rsx.documents.retry_after_minutes * 2^count away, capped at
 *   rsx.documents.soft_retry_max_minutes - and a FAILED blob with a count above 0 is one that
 *   failed softly. A charged attempt, a blob newly owing work and a blob leaving the queue all
 *   return it to 0.
 * - process_last_attempt_at: when the last COUNTED attempt was charged (NULL = none since the
 *   blob last entered the queue). A soft failure does not touch it. It answers "how long ago did
 *   the worker last really try this document", which a page viewing a hard-failed document asks
 *   before pulling its next attempt forward.
 *
 * No backfill: every existing row has had no soft failure, and no attempt charged under this
 * accounting.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_storage ADD COLUMN process_soft_failure_count INT NOT NULL DEFAULT 0 AFTER process_attempt_count");
        DB::statement("ALTER TABLE _file_storage ADD COLUMN process_last_attempt_at TIMESTAMP(3) NULL DEFAULT NULL AFTER process_soft_failure_count");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
