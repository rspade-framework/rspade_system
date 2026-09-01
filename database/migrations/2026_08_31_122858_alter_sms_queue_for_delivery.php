<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The sms_queue mirror of the email_queue delivery reshape.
     *
     * SMS has no provider wired, but the two queues are deliberately the same shape:
     * the runner, the status enum, the retry policy and the admin surfaces are read as
     * one pattern, and a divergence here is a divergence a reader has to hold in their
     * head. No attachments (there is nothing to attach to a text message).
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE sms_queue
                RENAME COLUMN retry_count TO attempt_count,
                RENAME COLUMN retry_at TO next_attempt_at,
                RENAME COLUMN error TO last_error
        ");

        DB::statement("
            ALTER TABLE sms_queue
                ADD COLUMN transport VARCHAR(32) NULL DEFAULT NULL,
                ADD COLUMN transport_response TEXT NULL,
                ADD COLUMN last_attempt_at TIMESTAMP(3) NULL DEFAULT NULL,
                ADD COLUMN dedupe_key VARCHAR(191) NULL DEFAULT NULL
        ");

        DB::statement("ALTER TABLE sms_queue DROP INDEX idx_sms_queue_retry_at");
        DB::statement("ALTER TABLE sms_queue ADD KEY idx_sms_queue_next_attempt_at (next_attempt_at)");

        DB::statement("ALTER TABLE sms_queue ADD UNIQUE KEY uk_sms_queue_dedupe (site_id, dedupe_key)");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
