<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * _file_attachments:
     *   - idx_files_fileable duplicated idx_fileable column for column; idx_fileable widens to
     *     (fileable_type, fileable_id, created_at) so the unclaimed-upload sweeps
     *     (fileable NULL, created_at < cutoff) read only the stale rows.
     *   - file_type_label is the documented Type-column sort, and every attachment list is
     *     site-scoped, so its index becomes (site_id, file_type_label).
     *   - handler_class (a residual IS NULL only), fileable_type_meta and file_type_id (a CLI
     *     listing only) are never the access path.
     *   - idx_file_attachments_deleted_at stays: the retention pass reads deleted_at < cutoff.
     *
     * _file_storage.size and _search_indexes.indexed_at / extractor_version are never a
     * predicate on their own; _search_indexes.idx_indexable is a non-unique copy of
     * unique_indexable.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _file_attachments
                DROP INDEX idx_files_fileable,
                DROP INDEX idx_fileable,
                DROP INDEX idx_file_attachments_handler_class,
                DROP INDEX idx_file_attachments_type_meta,
                DROP INDEX idx_files_file_type_id,
                DROP INDEX idx_file_attachments_file_type_label,
                ADD INDEX idx_fileable (fileable_type, fileable_id, created_at),
                ADD INDEX idx_file_attachments_site_type_label (site_id, file_type_label)
        ");

        DB::statement("
            ALTER TABLE _file_storage DROP INDEX idx_file_hashes_size
        ");

        DB::statement("
            ALTER TABLE _search_indexes
                DROP INDEX idx_indexable,
                DROP INDEX idx_indexed_at,
                DROP INDEX idx_extractor_version
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
