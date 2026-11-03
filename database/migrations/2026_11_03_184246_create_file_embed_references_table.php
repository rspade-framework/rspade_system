<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which record's text column embeds which file: one row per (owner record, column, file).
 * Maintained by a text type's after_model_saved() hook through File_Embed_References, and
 * read by the unclaimed-upload sweep, which never sweeps a file a row names. The owner is a
 * type-ref pair; a row goes with its file (ON DELETE CASCADE on the attachment).
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE _file_embed_references (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NULL DEFAULT NULL,
                owner_type BIGINT NOT NULL,
                owner_id BIGINT NOT NULL,
                column_name VARCHAR(64) NOT NULL,
                file_attachment_id BIGINT NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uniq_file_embed_references_owner_column_file (owner_type, owner_id, column_name, file_attachment_id),
                KEY idx_file_embed_references_file (file_attachment_id),
                CONSTRAINT fk_file_embed_references_file FOREIGN KEY (file_attachment_id)
                    REFERENCES _file_attachments (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
