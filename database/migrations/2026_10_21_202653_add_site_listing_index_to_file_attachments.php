<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A site's attachment listing - `site_id = ? AND deleted_at IS NULL ORDER BY created_at
     * DESC, id DESC`, plus the COUNT beside it - had no index combining the tenant scope with
     * the sort. MySQL either index-merged the two single-column indexes on site_id and
     * deleted_at, or walked created_at backwards discarding other sites' rows, which costs a
     * whole-table scan once a second site exists.
     *
     * (site_id, deleted_at, created_at) serves the scope, the live-row filter and the sort as
     * one range; InnoDB appends the primary key, so `created_at DESC, id DESC` needs no
     * filesort. Narrower predicates a listing adds (fileable_category, fileable_id) are left
     * to filter the range: putting a column compared with != or IS NOT NULL before created_at
     * would turn it into a range and give up the indexed sort.
     *
     * idx_files_site_id (site_id) is the new index's leading prefix and is dropped as pure
     * write cost.
     *
     * Reported by a downstream field report (2026-10-21): 53 ms for the first page and 12 ms
     * for its count at 26,084 rows on one site.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _file_attachments
                ADD INDEX idx_file_attachments_site_listing (site_id, deleted_at, created_at),
                DROP INDEX idx_files_site_id
        ");
    }
};
