<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\Temp_File_Model;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Attachment_Model - a named file a task produced (_task_attachments).
 *
 * A task attaches a file with $task->attach_file() / attach_bytes(); the bytes become a temp
 * file of their own (Rsx_Temp_Files - uploads/_temp/, a random key, never shared), and this row
 * names it under the run. Deleting the temp file deletes this row (the foreign key cascades).
 * It is NOT a File_Attachment_Model: no fileable owner, no retention window, no file gates. Who
 * may read it is the task's own view gate (Task_Gates); Task_Retention_Service deletes it once
 * the task's output is truncated, and the temp file's own expiry is the backstop.
 *
 * Read the bytes through read_bytes(), read_stream(), download_response().
 */

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _task_attachments
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $file_name
 * @property int $id
 * @property string $mime_type
 * @property string $name
 * @property int $size
 * @property int $task_id
 * @property int $temp_file_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Task_Attachment_Model_Abstract extends Rsx_Model_Abstract
{
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
     * The temp file holding the bytes.
     */
    #[Relationship]
    public function temp_file()
    {
        return $this->belongsTo(Temp_File_Model::class, 'temp_file_id');
    }

    /** The file's whole contents. */
    public function read_bytes(): string
    {
        return $this->__temp_file()->read_bytes();
    }

    /**
     * An open read stream over the file's bytes. The caller closes it.
     *
     * @return resource
     */
    public function read_stream()
    {
        return $this->__temp_file()->read_stream();
    }

    /**
     * A download response for the file under its file_name. Authorizing the caller is the
     * caller's job (Task_Gates::can_view()).
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download_response()
    {
        return $this->__temp_file()->download_response($this->file_name);
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

    private function __temp_file(): Temp_File_Model
    {
        $temp_file = Temp_File_Model::find($this->temp_file_id);
        if ($temp_file === null) {
            shouldnt_happen("Task attachment {$this->id} points at temp file #{$this->temp_file_id}, which does not exist");
        }

        return $temp_file;
    }
}
