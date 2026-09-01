<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Attachments and inline (cid:) images belonging to one queued email.
     *
     * The bytes live in the content-addressed blob store (_file_storage), so an
     * identical PDF mailed to a thousand recipients is stored once. A row here is a
     * live reference to its blob: File_Disposal_Service counts this table when it
     * decides whether a blob may be released, so a queued email can never lose the
     * file it is about to send.
     *
     * The FK to email_queue is ON DELETE CASCADE - the retention sweep deletes whole
     * queue rows and these go with them. The FK to _file_storage is RESTRICT (the
     * default): nothing may pull the blob out from under a pending send.
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE email_attachments (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email_queue_id BIGINT NOT NULL,
                file_storage_id BIGINT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(255) NOT NULL,
                disposition_id BIGINT NOT NULL DEFAULT 1,
                cid VARCHAR(255) NULL DEFAULT NULL,
                sort_order BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
                updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),

                KEY idx_email_attachments_email_queue_id (email_queue_id),
                KEY idx_email_attachments_file_storage_id (file_storage_id),
                FOREIGN KEY (email_queue_id) REFERENCES email_queue(id) ON DELETE CASCADE,
                FOREIGN KEY (file_storage_id) REFERENCES _file_storage(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
