<?php

use App\RSpade\Core\Files\File_Attachment_Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The human-facing, sortable file-type label on _file_attachments.
 *
 * file_type_id is a seven-value engine enum, so a Type column built from
 * file_type_id__label reads "Document" for a PDF, a Word file, a workbook and a CSV
 * alike. file_type_label carries the format name a person recognises - "PDF Document",
 * "Excel Spreadsheet", "ZIP Archive" - and is indexed so a file listing can ORDER BY
 * and WHERE on it. It is derived and cosmetic; nothing branches on it.
 *
 * @MIGRATION-MODEL-01-EXCEPTION The backfill is the model's own derivation map
 *     (File_Attachment_Model::file_type_label_for(), reached through
 *     regenerate_file_type_labels()), which resolves the pipeline mime before it maps -
 *     behaviour raw SQL cannot reproduce without restating the whole table here and
 *     freezing a second copy of it. This is also the documented obligation whenever that
 *     map changes, so the call IS the worked example. File_Attachment_Model is a core
 *     framework model that does not retire, so a from-scratch replay stays valid.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement(
            "ALTER TABLE _file_attachments ADD COLUMN file_type_label VARCHAR(64) NULL"
        );

        DB::statement(
            "ALTER TABLE _file_attachments ADD INDEX idx_file_attachments_file_type_label (file_type_label)"
        );

        File_Attachment_Model::regenerate_file_type_labels();
    }
};
