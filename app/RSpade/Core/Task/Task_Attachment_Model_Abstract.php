<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\Blob_Referencing;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Task\Task_Run_Model;
/**
 * Task_Attachment_Model - a named file a task produced (_task_attachments).
 *
 * A task attaches a file with $task->attach_file() / attach_bytes(); the bytes go into the
 * central blob store (File_Storage_Model, deduplicated) and this row pins them for as long as
 * it exists - it is a declared blob reference, so File_Disposal_Service never releases bytes a
 * task attachment still points at. It is NOT a File_Attachment_Model: it has no site, no
 * fileable owner, no retention window and no file gates. Who may read it is the task's own
 * view gate (Task_Gates), and Task_Retention_Service deletes the row - releasing the blob when
 * nothing else references it - once the task's output is truncated.
 *
 * Read the bytes through the blob API: read_bytes(), read_stream(), download_response().
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _task_attachments
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $file_name
 * @property int $file_storage_id
 * @property int $id
 * @property string $mime_type
 * @property string $name
 * @property int $size
 * @property int $task_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
#[Blob_Reference('file_storage_id')]
abstract class Task_Attachment_Model_Abstract extends Rsx_Model_Abstract
{
    // The write side of the #[Blob_Reference] above: save() holds the blob's read lock while
    // the reference commits.
    use Blob_Referencing;

    public static $realtime_silent = true;

    /**
     * UNBOUNDED: grows with the files tasks produce.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_task_attachments';
    protected $fillable = [];

    public static $enums = [];

    /**
     * The run that produced the file.
     */
    #[Relationship]
    public function task()
    {
        return $this->belongsTo(Task_Run_Model::class, 'task_id');
    }

    /**
     * The blob holding the bytes.
     */
    #[Relationship]
    public function file_storage()
    {
        return $this->belongsTo(File_Storage_Model::class, 'file_storage_id');
    }

    /** The file's whole contents. */
    public function read_bytes(): string
    {
        return $this->__storage()->read_bytes();
    }

    /**
     * An open read stream over the file's bytes. The caller closes it.
     *
     * @return resource
     */
    public function read_stream()
    {
        return $this->__storage()->read_stream();
    }

    /**
     * A download response for the file under its file_name. Authorizing the caller is the
     * caller's job (Task_Gates::can_view()).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download_response()
    {
        return $this->__storage()->download_response($this->file_name, $this->mime_type);
    }

    /**
     * JSON-ready description: {name, file_name, mime_type, size}.
     */
    public function to_listing_array(): array
    {
        return [
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
        ];
    }

    private function __storage(): File_Storage_Model
    {
        $storage = File_Storage_Model::find($this->file_storage_id);
        if ($storage === null) {
            shouldnt_happen("Task attachment {$this->id} points at file storage #{$this->file_storage_id}, which does not exist");
        }

        return $storage;
    }
}
