<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where the face is in a stored image, for face-centred cover thumbnails.
 *
 * - face_x, face_y: the centre of the most prominent face, as fractions (0-1) of the UPRIGHT
 *   image's width and height (EXIF orientation applied), recorded by Face_Detection the first
 *   time a face-centred thumbnail of the blob is asked for.
 *
 *     NULL, NULL   not checked yet (or the detector was unavailable when it was asked)
 *     -1, -1       checked by a working detector: no face, or the image could not be read
 *     x, y         the face centre
 *
 * The position lives on the content-addressed blob, so every upload of the same bytes shares
 * one answer. Setting both back to NULL makes the next face-centred request detect again.
 *
 * No backfill: every existing blob is unchecked.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _file_storage ADD COLUMN face_x DECIMAL(5,4) NULL DEFAULT NULL AFTER rendered_at");
        DB::statement("ALTER TABLE _file_storage ADD COLUMN face_y DECIMAL(5,4) NULL DEFAULT NULL AFTER face_x");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
