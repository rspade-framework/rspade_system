<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Blob_References;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;

/**
 * File_Blob_Audit - the blobs in the store that nothing appears to reference. Read-only:
 * it lists, it never removes. The engine behind `rsx:files:unreferenced_blobs`.
 *
 * Two kinds, in this order:
 *
 *   1. A _file_storage row no declared reference holds (File_Blob_References): nothing in this
 *      database points at the bytes. A soft-deleted attachment still inside its retention
 *      window counts as a reference, exactly as it does for the disposal service.
 *   2. A file in the blob tree with no _file_storage row at all. The tree is walked the way
 *      the disposal sweep walks it - every `_` directory at its root (uploads/_temp) skipped.
 *
 * "Apparently": a blob store shared by several environments holds blobs another environment's
 * database references, and this database cannot see that.
 */
class File_Blob_Audit
{
    private const BATCH = 1000;

    /**
     * Report every apparently unreferenced blob to $emit(relative_path, bytes), streaming.
     *
     * @param callable(string, int): void $emit
     * @return array{count: int, bytes: int}
     */
    public static function each_unreferenced(callable $emit): array
    {
        $count = 0;
        $bytes = 0;
        $report = function (string $relative_path, int $size) use ($emit, &$count, &$bytes): void {
            $emit($relative_path, $size);
            $count++;
            $bytes += $size;
        };

        // 1. Rows nothing references, a keyset page at a time.
        $last_id = 0;
        while (true) {
            $rows = File_Blob_References::where_unreferenced(DB::table('_file_storage as s')->where('s.id', '>', $last_id), 's')
                ->orderBy('s.id')
                ->limit(self::BATCH)
                ->get(['s.id', 's.hash', 's.size']);
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $last_id = (int) $row->id;
                $relative_path = File_Storage_Model::relative_blob_path((string) $row->hash);
                $path = Rsx_File_Paths::blob_root() . '/' . $relative_path;
                $report($relative_path, is_file($path) ? (int) filesize($path) : (int) $row->size);
            }
        }

        // 2. Files no row describes.
        $blob_root = Rsx_File_Paths::blob_root();
        if (!is_dir($blob_root)) {
            return ['count' => $count, 'bytes' => $bytes];
        }

        $pending = [];
        $flush = function () use (&$pending, $blob_root, $report): void {
            if ($pending === []) {
                return;
            }
            $known = array_flip(DB::table('_file_storage')->whereIn('hash', array_keys($pending))->pluck('hash')->all());
            foreach ($pending as $hash => $path) {
                if (!isset($known[$hash])) {
                    $report(substr($path, strlen($blob_root) + 1), (int) filesize($path));
                }
            }
            $pending = [];
        };

        foreach (new \RecursiveIteratorIterator(File_Disposal_Service::blob_tree_iterator($blob_root)) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $pending[$file->getFilename()] = $file->getPathname();
            if (count($pending) >= self::BATCH) {
                $flush();
            }
        }
        $flush();

        return ['count' => $count, 'bytes' => $bytes];
    }
}
