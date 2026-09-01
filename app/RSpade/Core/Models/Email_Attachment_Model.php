<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Email_Queue_Model;

/**
 * Email_Attachment_Model - one file (or inline image) belonging to one queued email.
 *
 * The bytes are NOT here: they live in the content-addressed blob store, so mailing
 * the same 4MB PDF to a thousand recipients stores it once. This row is the logical
 * attachment - the filename and mime the RECIPIENT sees, whether it rides as a
 * download or as a `cid:` image, and where it sits in the message.
 *
 * A row here is a LIVE reference to its blob: File_Disposal_Service counts this table
 * before releasing bytes, so a queued email can never lose the file it is about to
 * send. The FK to email_queue is ON DELETE CASCADE - the retention sweep deletes queue
 * rows and these follow.
 *
 * No site_id: an attachment is reached through its email, which carries the tenant.
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: email_attachments
 *
 * @property int $id
 * @property int $email_queue_id
 * @property int $file_storage_id
 * @property string $file_name
 * @property string $mime_type
 * @property int $disposition_id
 * @property string $cid
 * @property int $sort_order
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $disposition_id__label
 * @property-read string $disposition_id__constant
 *
 * @method static array disposition_id__enum() Get all enum definitions with full metadata
 * @method static array disposition_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array disposition_id__enum_labels() Get simple id => label map
 * @method static array disposition_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Email_Attachment_Model extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const DISPOSITION_ATTACHMENT = 1;
    const DISPOSITION_INLINE = 2;
    // Infrastructure table: nothing in a UI subscribes to these rows, so writes here
    // must not kick the emitter engine.
    public static $realtime_silent = true;

    /**
     * UNBOUNDED: grows with the mail an application sends, not with the codebase.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = 'email_attachments';
    protected $fillable = [];

    public static $enums = [
        'disposition_id' => [
            1 => ['constant' => 'DISPOSITION_ATTACHMENT', 'label' => 'Attachment', 'badge' => 'bg-secondary'],
            2 => ['constant' => 'DISPOSITION_INLINE', 'label' => 'Inline', 'badge' => 'bg-info'],
        ],
    ];

    /**
     * The email this file is attached to.
     */
    #[Relationship]
    public function email_queue()
    {
        return $this->belongsTo(Email_Queue_Model::class, 'email_queue_id');
    }

    /**
     * The deduplicated blob holding the actual bytes.
     */
    #[Relationship]
    public function file_storage()
    {
        return $this->belongsTo(File_Storage_Model::class, 'file_storage_id');
    }
}
