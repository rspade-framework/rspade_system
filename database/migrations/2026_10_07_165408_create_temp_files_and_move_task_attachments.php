<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The temp file store (Rsx_Temp_Files), and task attachments moved onto it.
     *
     *   _temp_files   one row per temporary file: a random key (its name on disk under
     *                 uploads/_temp/, never derived from its contents), the name and type it is
     *                 served as, its site, and the moment it expires. The store's own sweep
     *                 deletes expired files - only the rows this database holds.
     *
     * _task_attachments stops pinning content-addressed blobs: each attachment is a temp file
     * of its own, and deleting the temp file deletes the attachment row with it. The existing
     * rows are dropped rather than carried over - a task attachment is ephemeral by design
     * and expires with its run's output.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            CREATE TABLE _temp_files (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                temp_key CHAR(32) CHARACTER SET ascii NOT NULL,
                site_id BIGINT NOT NULL DEFAULT 0,
                file_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(255) NOT NULL,
                size BIGINT NOT NULL,
                expires_at TIMESTAMP(3) NOT NULL,
                created_at TIMESTAMP(3) NULL DEFAULT NULL,
                updated_at TIMESTAMP(3) NULL DEFAULT NULL,
                UNIQUE KEY uk_temp_files_key (temp_key),
                INDEX idx_temp_files_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement('DELETE FROM _task_attachments');
        DB::statement('ALTER TABLE _task_attachments DROP FOREIGN KEY fk_task_attachments_storage');
        DB::statement('ALTER TABLE _task_attachments DROP INDEX idx_task_attachments_storage');
        DB::statement('ALTER TABLE _task_attachments DROP COLUMN file_storage_id');
        DB::statement('ALTER TABLE _task_attachments ADD COLUMN temp_file_id BIGINT NOT NULL AFTER name');
        DB::statement('ALTER TABLE _task_attachments ADD INDEX idx_task_attachments_temp_file (temp_file_id)');
        DB::statement('ALTER TABLE _task_attachments ADD CONSTRAINT fk_task_attachments_temp_file FOREIGN KEY (temp_file_id) REFERENCES _temp_files(id) ON DELETE CASCADE');
    }
};
