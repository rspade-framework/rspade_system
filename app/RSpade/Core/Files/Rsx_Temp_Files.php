<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Files\Temp_File_Model;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Temp_Files - the temp file store: files a pipeline produces from application data and
 * keeps for a while - a CSV export waiting to be downloaded, a file a task attached for whoever
 * started it - which are neither attachments nor scratch.
 *
 * WHY NOT THE ATTACHMENT STORE. That store is content-addressed and deduplicated: one blob
 * may be held by many owners, perhaps on other hosts sharing the same uploads mount, so freeing
 * one takes a reference check under a lock - and is switched off entirely by
 * rsx.files.deleted_retention_days = 0. A temp file has one producer, one consumer and a short
 * life. It gets a RANDOM key, its own namespace (uploads/_temp/, which no blob shard can
 * collide with), and nothing else ever holds its bytes, so deleting it asks nobody.
 *
 * WHY NOT tmp/. The tmp tree holds what the application can regenerate - build artifacts,
 * caches, runtime state - and may be discarded on any deployment. A temp file is application
 * DATA that somebody is waiting for, so it lives in uploads/, the tree that persists across
 * deployments.
 *
 * EXPIRY. Each file carries its own expires_at - rsx.temp_files.retention_days (7) unless the
 * caller chooses - and Temp_File_Cleanup_Service deletes expired files. The sweep deletes only
 * files THIS database has rows for, never "every old file on disk": an uploads mount shared by
 * several environments holds other environments' temp files too, and a sweep by age would
 * delete them.
 *
 * NO DOWNLOAD ENDPOINT. Reading one is the business of the feature that made it: its own
 * endpoint, its own gate, then $file->download_response(). The random key is not a capability -
 * nothing is ever served on knowledge of the key alone.
 */
class Rsx_Temp_Files
{
    /**
     * Store bytes as a temp file.
     *
     * @param string $file_name     the name it is served as
     * @param string|null $mime_type sniffed from the bytes when null
     * @param int|null $retention_days days until it expires (null: rsx.temp_files.retention_days)
     */
    public static function store_bytes(string $bytes, string $file_name, ?string $mime_type = null, ?int $retention_days = null): Temp_File_Model
    {
        return static::__store(function (string $path) use ($bytes): void {
            if (file_put_contents($path, $bytes) === false) {
                throw new \RuntimeException('Could not write a temp file to ' . $path);
            }
        }, $file_name, $mime_type, $retention_days);
    }

    /**
     * Store a copy of a file on disk as a temp file. The source is left where it is.
     *
     * @param int|null $retention_days days until it expires (null: rsx.temp_files.retention_days)
     */
    public static function store_file(string $source_path, string $file_name, ?string $mime_type = null, ?int $retention_days = null): Temp_File_Model
    {
        if (!is_file($source_path)) {
            throw new \RuntimeException('No file to store at ' . $source_path);
        }

        return static::__store(function (string $path) use ($source_path): void {
            if (!copy($source_path, $path)) {
                throw new \RuntimeException('Could not copy ' . $source_path . ' into the temp file store');
            }
        }, $file_name, $mime_type, $retention_days);
    }

    /** A live temp file by its key, or null when there is none or it has expired. */
    public static function find(string $temp_key): ?Temp_File_Model
    {
        $file = Temp_File_Model::where('temp_key', $temp_key)->first();

        return $file !== null && !$file->is_expired() ? $file : null;
    }

    /** Delete a temp file now: its bytes, then its row. */
    public static function delete(Temp_File_Model $file): void
    {
        $path = $file->storage_path();
        if (is_file($path)) {
            unlink($path);
        }

        $file->delete();
    }

    /**
     * Delete every expired temp file this database holds. Returns how many.
     * With $task, it beats and answers a graceful stop between files.
     */
    public static function delete_expired(?Task_Instance $task = null): int
    {
        $deleted = 0;

        foreach (Temp_File_Model::where('expires_at', '<=', Rsx_Time::now_iso())->result_set() as $file) {
            if ($task?->is_stop_requested()) {
                break;
            }
            $task?->heartbeat();

            static::delete($file);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * config('rsx.temp_files.retention_days'), validated: a whole number of days, at least 1.
     */
    public static function retention_days(): int
    {
        $value = config('rsx.temp_files.retention_days', 7);
        if (!is_int($value) || $value < 1) {
            throw new \RuntimeException("config('rsx.temp_files.retention_days') must be a whole number of days >= 1, got " . var_export($value, true));
        }

        return $value;
    }

    /**
     * Write the bytes under a fresh random key, then record the row. A failed write leaves no
     * row; a row is never written for bytes that are not there.
     */
    private static function __store(callable $write, string $file_name, ?string $mime_type, ?int $retention_days): Temp_File_Model
    {
        $days = $retention_days ?? static::retention_days();
        if ($days < 1) {
            throw new \InvalidArgumentException('A temp file must live at least 1 day, got ' . $days);
        }

        $file = new Temp_File_Model();
        $file->temp_key = random_hash(16);
        $path = $file->storage_path();
        ensure_directory(dirname($path));

        $partial = $path . '.partial';
        try {
            $write($partial);
            rename($partial, $path);
        } catch (\Throwable $e) {
            if (is_file($partial)) {
                unlink($partial);
            }

            throw $e;
        }

        $file->site_id = Rsx_Site_Model_Abstract::get_current_site_id();
        $file->file_name = $file_name;
        $file->mime_type = $mime_type ?? (mime_content_type($path) ?: 'application/octet-stream');
        $file->size = (int) filesize($path);
        $file->expires_at = Rsx_Time::add(Rsx_Time::now_iso(), $days * 86400);
        $file->save();

        return $file;
    }
}
