<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * File_Embed_Reference_Model - one file embedded in one text column of one record
 * (_file_embed_references).
 *
 * A system table: application code reads and writes it only through File_Embed_References.
 * The owner is a type-ref pair (owner_type + owner_id); a row goes with its attachment
 * (ON DELETE CASCADE).
 *
 * Not site-scoped. The rows are keyed by owner and by attachment, both of which the caller
 * already holds, and the unclaimed-upload sweep reads them for every site at once. site_id
 * is the owner's, recorded for reporting.
 */

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _file_embed_references
 *
 * @property string $column_name
 * @property string $created_at
 * @property int $created_by_id
 * @property string $created_by_type type ref: a model class name, stored as a type-ref id
 * @property int $file_attachment_id
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type type ref: a model class name, stored as a type-ref id
 * @property int $site_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property string $updated_by_type type ref: a model class name, stored as a type-ref id
 *
 * @mixin \Eloquent
 */
abstract class File_Embed_Reference_Model_Abstract extends Rsx_Model_Abstract
{
    public static $realtime_silent = true;

    /**
     * UNBOUNDED: one row per file embedded in rich text, across every record.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_file_embed_references';

    protected $fillable = [];

    public static $columns = [
        'owner_type' => ['type_ref' => true],
    ];
}
