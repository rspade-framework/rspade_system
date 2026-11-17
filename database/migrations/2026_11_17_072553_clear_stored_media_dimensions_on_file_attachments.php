<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An image's or video's stored width and height are now the DISPLAYED size: swapped for an
 * image whose EXIF orientation turns it a quarter turn (a phone portrait), and for a video
 * whose rotation does; a video also records its duration (read by ffprobe, empty until now).
 *
 * The sizes stored before were the raw pixel sizes, so they are cleared here for images,
 * animated images and videos (file_type_id 1, 2, 3), and each is read again on demand, by the
 * current rule, the first time a payload carries it (File_Attachment_Model::
 * ensure_media_metadata()). duration is cleared with them: an animation's is read back from
 * its frames, a video's from ffprobe.
 *
 * Every other type keeps its values, and is_animated / frame_count are not touched - neither
 * depends on orientation, and they are part of the classification decided at upload.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("UPDATE _file_attachments SET width = NULL, height = NULL, duration = NULL WHERE file_type_id IN (1, 2, 3)");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
