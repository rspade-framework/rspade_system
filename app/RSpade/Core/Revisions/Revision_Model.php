<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Revisions\Revision_Codec;
use App\RSpade\Core\Revisions\Transaction_Model;

/**
 * Revision_Model - one row per recorded write to a revisioned record.
 *
 * The document itself lives in `changes`, a COMPRESSED blob produced by Revision_Codec
 * from the JSON `{"field":[before,after], ...}`. It is never exposed raw: $neverExport
 * strips it from every payload, and diff() is the only reader.
 *
 * TWO POLYMORPHIC PAIRS, and they answer different questions:
 *
 *   record_type/record_id  WHICH ROW changed. Always this revision's own subject.
 *   root_type/root_id      WHICH ENTITY the change belongs to. Equal to the record pair
 *                          for a top-level model; for a model declaring
 *                          #[Revision_Parent] on a belongsTo, the parent's pair.
 *
 * The root pair is what makes "show me everything that happened to this client" one
 * indexed query instead of a union over every child table - see
 * Rsx_Model_Abstract::revisions_including_children().
 *
 * `sequence` is the 1-based position of this revision within its transaction, so a
 * history screen can replay one action's writes in the order they were made.
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _revisions
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $site_id
 * @property int $record_type
 * @property int $record_id
 * @property int $root_type
 * @property int $root_id
 * @property int $operation_id
 * @property int $sequence
 * @property mixed $changes
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $operation_id__label
 * @property-read string $operation_id__constant
 *
 * @method static array operation_id__enum() Get all enum definitions with full metadata
 * @method static array operation_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array operation_id__enum_labels() Get simple id => label map
 * @method static array operation_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Revision_Model extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const OPERATION_CREATE = 1;
    const OPERATION_UPDATE = 2;
    const OPERATION_DELETE = 3;
    const OPERATION_UNDELETE = 4;
    /**
     * _AUTO_GENERATED_ Enum constants
     */

    /**
     * UNBOUNDED: one row per recorded write. The row count grows with customer activity;
     * no reader may assume the set is small. Consumed by DB-UNBOUNDED-01.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_revisions';

    protected $fillable = []; // No mass assignment - always explicit

    /**
     * Both polymorphic halves: BIGINT type-ref ids in the database, class basenames in PHP.
     */
    protected static $type_ref_columns = ['record_type', 'root_type'];

    /**
     * NEVER exported. `changes` is a compressed binary blob - serializing it would put
     * unreadable bytes in a JSON payload and hand the storage format to the client.
     * A consumer wants diff(), which is the decoded document.
     *
     * @var array
     */
    protected $neverExport = ['changes'];

    /**
     * What the write DID to the record.
     *
     * `delete` covers both a hard delete and a soft delete: from the record's point of
     * view the row left, and the diff is empty either way. `undelete` is the restore of a
     * soft-deleted row, and its diff carries the deleted_at / deleted_by columns going
     * back to null.
     *
     * @var array
     */
    public static $enums = [
        'operation_id' => [
            1 => [
                'constant' => 'OPERATION_CREATE',
                'label' => 'Created',
                'order' => 1,
            ],
            2 => [
                'constant' => 'OPERATION_UPDATE',
                'label' => 'Updated',
                'order' => 2,
            ],
            3 => [
                'constant' => 'OPERATION_DELETE',
                'label' => 'Deleted',
                'order' => 3,
            ],
            4 => [
                'constant' => 'OPERATION_UNDELETE',
                'label' => 'Restored',
                'order' => 4,
            ],
        ],
    ];

    /**
     * The transaction this revision was recorded under.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    #[Relationship]
    public function transaction()
    {
        return $this->belongsTo(Transaction_Model::class, 'transaction_id');
    }

    /**
     * The record that changed. Read it as a property ($revision->record); null when the
     * row has since been hard-deleted.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function record()
    {
        return $this->morphTo('record', 'record_type', 'record_id');
    }

    /**
     * The entity the change belongs to - the same row as record() unless this model
     * declared a #[Revision_Parent].
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function root()
    {
        return $this->morphTo('root', 'root_type', 'root_id');
    }

    /**
     * The decoded change document: `['field' => [before, after], ...]`.
     *
     * Both values are the FULL stored value, never a delta - a revision says what the
     * field was and what it became, and answering "what changed" must not require
     * replaying every earlier revision. A delete carries an empty document.
     *
     * Throws (from Revision_Codec) when the stored payload names a codec or a dictionary
     * this build cannot honour: an unreadable revision is a fact about the data, and
     * saying so is the only correct answer.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function diff(): array
    {
        $json = Revision_Codec::decode((string) $this->getRawOriginal('changes'));

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
