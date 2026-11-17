<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A video's metadata is read by ffprobe. Before that, an uploaded video was handed to
 * ImageMagick, which has no video coder, so the read failed and the upload was demoted the way
 * an unparseable image is: file_type_id OTHER (7) with preview_unavailable set, shown as a
 * generic file whose preview is unavailable.
 *
 * Those rows are exactly the ones whose sniffed mime_type is video/* yet carry that demotion -
 * an upload with a video mime type is otherwise always classified VIDEO (3). They are restored
 * to VIDEO with the flag cleared, and their width, height and duration are left empty, to be
 * read on demand the first time a payload carries them (File_Attachment_Model::
 * ensure_media_metadata()). A video ffprobe cannot read keeps empty metadata and is logged
 * there; it is not demoted again.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("UPDATE _file_attachments
            SET file_type_id = 3, preview_unavailable = 0, width = NULL, height = NULL, duration = NULL
            WHERE mime_type LIKE 'video/%' AND file_type_id = 7 AND preview_unavailable = 1");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
