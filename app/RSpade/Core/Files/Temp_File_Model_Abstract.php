<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\Rsx_File_Paths;

/**
 * Temp_File_Model - one file in the temp file store (_temp_files; Rsx_Temp_Files).
 *
 * Its bytes live at uploads/_temp/<first two characters of temp_key>/<temp_key>. The key is
 * random, never derived from the contents: two temp files never share bytes, so deleting one
 * never needs to ask who else holds it. It expires at expires_at, and the store's sweep
 * deletes it then.
 *
 * Read the bytes through read_bytes(), read_stream() or the two response builders - never a
 * path. Who may read a temp file is decided by the endpoint of the feature that made it.
 */

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _temp_files
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $expires_at
 * @property string $file_name
 * @property int $id
 * @property string $mime_type
 * @property int $site_id
 * @property int $size
 * @property string $temp_key
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Temp_File_Model_Abstract extends Rsx_Model_Abstract
{
    public static $realtime_silent = true;

    /**
     * UNBOUNDED: grows with the files features store, until each expires.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_temp_files';

    protected $fillable = [];

    public static $enums = [];

    /** The file's whole contents. */
    public function read_bytes(): string
    {
        $bytes = @file_get_contents($this->__existing_path());
        if ($bytes === false) {
            throw new \RuntimeException("Temp file {$this->temp_key} could not be read.");
        }

        return $bytes;
    }

    /**
     * An open read stream over the file's bytes. The caller closes it.
     *
     * @return resource
     */
    public function read_stream()
    {
        $stream = @fopen($this->__existing_path(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Temp file {$this->temp_key} could not be opened.");
        }

        return $stream;
    }

    /**
     * A download (attachment disposition) response, hardened the way every file response is
     * (File_Attachment_Controller::harden_file_response()). Authorizing the caller is the
     * endpoint's job.
     *
     * @param string|null $file_name the name the browser saves it under (default: file_name)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download_response(?string $file_name = null)
    {
        return File_Attachment_Controller::harden_file_response(
            \Illuminate\Support\Facades\Response::download($this->__existing_path(), $file_name ?? $this->file_name, ['Content-Type' => $this->mime_type])
        );
    }

    /**
     * An inline-disposition response, hardened like download_response().
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function inline_response(?string $file_name = null)
    {
        $headers = ['Content-Type' => $this->mime_type];
        $headers['Content-Disposition'] = \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('inline', $file_name ?? $this->file_name, 'file');

        return File_Attachment_Controller::harden_file_response(
            \Illuminate\Support\Facades\Response::file($this->__existing_path(), $headers)
        );
    }

    /** Has its moment passed? An expired file is gone as far as a reader is concerned. */
    public function is_expired(): bool
    {
        return strtotime((string) $this->expires_at) <= time();
    }

    /**
     * Where the bytes live. Rsx_Temp_Files writes and deletes them; nothing else needs it.
     */
    public function storage_path(): string
    {
        return Rsx_File_Paths::temp_root() . '/' . substr((string) $this->temp_key, 0, 2) . '/' . $this->temp_key;
    }

    private function __existing_path(): string
    {
        $path = $this->storage_path();
        if (!is_file($path)) {
            throw new \RuntimeException("Temp file {$this->temp_key} has no bytes on disk.");
        }

        return $path;
    }
}
