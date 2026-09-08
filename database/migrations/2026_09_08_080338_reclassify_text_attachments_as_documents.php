<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL, never
     * Schema::table with Blueprint). Migrations are self-contained - no Model/Service references,
     * which is why the two file_type_id values below are written as literals.
     *
     * Reclassify text/* attachments from FILE_TYPE_TEXT (5) to FILE_TYPE_DOCUMENT (6), matching
     * the classification File_Attachment_Model::determine_file_type() now assigns at upload.
     *
     * WHY THIS RUNS AT ALL, rather than letting existing rows keep their value: file_type_id is
     * what is_document() answers from, and what surfaces filter and label by. Leaving it split
     * would make a .txt uploaded before this release and one uploaded after different KINDS of
     * file to every such question - the same document answering differently depending on the week
     * it arrived. Classification is derived data, so it is corrected in place; nothing here
     * touches bytes, blobs, extraction or render state.
     *
     * MATCHED ON THE STORED MIME, NOT ON file_type_id = 5 ALONE. The reverse - "everything
     * currently classified Text" - would also sweep up any row classified 5 for some other
     * reason, and this migration has no business guessing about those. Rows whose mime_type does
     * not start with text/ are left exactly as they are.
     *
     * FILE_TYPE_TEXT (5) remains a valid enum value: nothing assigns it any more, but an enum
     * value is not removed by a migration that only moves rows off it.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            UPDATE _file_attachments
               SET file_type_id = 6
             WHERE file_type_id = 5
               AND mime_type LIKE 'text/%'
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
