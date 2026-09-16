<?php

use App\RSpade\Core\Files\File_Attachment_Model;
use Illuminate\Database\Migrations\Migration;

/**
 * The file_type_label map widened: macro-enabled and template Office formats, Apple and
 * OpenDocument formats, accounting exports, email and calendar files, archives, disk
 * images, packages, executables, camera RAW and design formats, CAD/3D/GIS, fonts,
 * certificates and data files - and the extension now answers ahead of a zip or
 * octet-stream sniff, so an .xlsm is no longer a "ZIP Archive". Every stored label is a
 * projection of that map and is stale the moment it changes, so this migration is the
 * regeneration walk and nothing else.
 *
 * @MIGRATION-MODEL-01-EXCEPTION The backfill is the model's own derivation map
 *     (File_Attachment_Model::file_type_label_for(), reached through
 *     regenerate_file_type_labels()), which resolves the pipeline mime before it maps -
 *     behaviour raw SQL cannot reproduce without restating the whole table here and
 *     freezing a second copy of it. File_Attachment_Model is a core framework model that
 *     does not retire, so a from-scratch replay stays valid.
 */
return new class extends Migration
{
    public function up()
    {
        File_Attachment_Model::regenerate_file_type_labels();
    }
};
