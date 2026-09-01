<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bring email_queue up to the delivery-capable shape.
     *
     * The queue was a record-only table (render + hold back). Real SMTP delivery needs
     * the message envelope (reply-to, cc/bcc, extra headers), both rendered parts, the
     * transport's own answer, and an attempt clock the retry policy can read.
     *
     * Renames carry the new vocabulary: `template` names a CLASS now (Rsx_Email
     * subclass, not a blade name), retry_count becomes attempt_count and retry_at becomes next_attempt_at because a
     * first send is an attempt too, and `error` becomes `last_error` because only the
     * most recent one is kept. `opened_at` is dropped - nothing ever wrote it.
     *
     * The RFC 5322 Message-ID the transport assigns is stored as `message_id_header`,
     * not `message_id`: a `_id` suffix in this schema means an integer key
     * (SCHEMA-TYPE-01), and this is a header string.
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE email_queue
                RENAME COLUMN template TO email_class,
                RENAME COLUMN retry_count TO attempt_count,
                RENAME COLUMN retry_at TO next_attempt_at,
                RENAME COLUMN error TO last_error
        ");

        DB::statement("ALTER TABLE email_queue DROP COLUMN opened_at");

        DB::statement("
            ALTER TABLE email_queue
                ADD COLUMN reply_to VARCHAR(255) NULL DEFAULT NULL,
                ADD COLUMN reply_to_name VARCHAR(255) NULL DEFAULT NULL,
                ADD COLUMN cc JSON NULL,
                ADD COLUMN bcc JSON NULL,
                ADD COLUMN headers JSON NULL,
                ADD COLUMN rendered_text LONGTEXT NULL,
                ADD COLUMN message_id_header VARCHAR(255) NULL DEFAULT NULL,
                ADD COLUMN transport VARCHAR(32) NULL DEFAULT NULL,
                ADD COLUMN transport_response TEXT NULL,
                ADD COLUMN last_attempt_at TIMESTAMP(3) NULL DEFAULT NULL,
                ADD COLUMN dedupe_key VARCHAR(191) NULL DEFAULT NULL
        ");

        // The claim query orders PENDING rows by next_attempt_at, so the index follows
        // the rename rather than keeping its old name.
        DB::statement("ALTER TABLE email_queue DROP INDEX idx_email_queue_retry_at");
        DB::statement("ALTER TABLE email_queue ADD KEY idx_email_queue_next_attempt_at (next_attempt_at)");

        // A dedupe key is unique WITHIN a tenant: two sites may legitimately mint the
        // same application-level key. NULL never collides, so unkeyed sends are free.
        DB::statement("ALTER TABLE email_queue ADD UNIQUE KEY uk_email_queue_dedupe (site_id, dedupe_key)");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
