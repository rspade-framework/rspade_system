<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Send every rendered spreadsheet back through the render queue.
     *
     * A spreadsheet's preview is an HTML rendition produced once per stored file. The
     * rendition now shows each formula's stored result instead of its text, draws grid
     * lines and keeps the workbook's column widths and row heights, so the ones already
     * on disk are out of date. Setting a rendered workbook back to PENDING (2) is what the
     * render worker reads as "produce it again"; it overwrites the old file in place.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            UPDATE _file_storage
            SET render_status_id = 2, rendered_at = NULL, render_error = NULL
            WHERE render_status_id = 3
              AND id IN (
                  SELECT file_storage_id FROM _file_attachments
                  WHERE file_storage_id IS NOT NULL
                    AND (mime_type LIKE 'application/vnd.ms-excel%'
                      OR mime_type LIKE 'application/vnd.openxmlformats-officedocument.spreadsheetml.%'
                      OR mime_type LIKE 'application/vnd.oasis.opendocument.spreadsheet%')
              )
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
