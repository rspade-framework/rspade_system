<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Embed_Reference_Model;

/**
 * File_Embed_References - the record of which record's text embeds which file.
 *
 * A text type whose encoding embeds files (a rich-text token naming an attachment) keeps
 * this record true from its after_model_saved() hook: sync() with the ids its new value
 * names. The record serves two readers:
 *
 *   - the unclaimed-upload sweep, which never sweeps a file a reference names - defence in
 *     depth behind File_Attachment_Model::claim_for_embed(), which is what owns the file;
 *   - an application asking which records embed a file (is_referenced(), owners_of()).
 *
 * Removing a token removes its reference and NOTHING ELSE. The file keeps its owner and its
 * bytes, because a revision of the old text can be restored and its embed must still work.
 *
 * See: php artisan rsx:man file_upload (FILES EMBEDDED IN TEXT)
 */
class File_Embed_References
{
    /**
     * Make the references for one record's column exactly $file_attachment_ids: add the
     * missing rows, delete the rows for ids no longer named. Ids are not checked against
     * _file_attachments here; a caller passes the ids its value names, and an id with no
     * attachment is skipped.
     *
     * @param Rsx_Model_Abstract $owner The saved record.
     * @param string $column Its text column.
     * @param array<int> $file_attachment_ids Every attachment id the column's value names now.
     * @return void
     */
    public static function sync(Rsx_Model_Abstract $owner, string $column, array $file_attachment_ids): void
    {
        $wanted = array_values(array_unique(array_map('intval', $file_attachment_ids)));
        $owner_type = class_basename($owner);

        // One record's one column: bounded by the tokens a single text holds.
        // @DB-UNBOUNDED-01-EXCEPTION - narrowed to one owner record and column
        $existing = File_Embed_Reference_Model::where('owner_type', $owner_type)
            ->where('owner_id', (int) $owner->getKey())
            ->where('column_name', $column)
            ->get();

        $have = [];
        foreach ($existing as $row) {
            if (in_array((int) $row->file_attachment_id, $wanted, true)) {
                $have[] = (int) $row->file_attachment_id;
                continue;
            }
            $row->delete();
        }

        $missing = array_diff($wanted, $have);
        if (!$missing) {
            return;
        }

        // Only ids that name an attachment row: the column is user-authored text and may name
        // anything, and the foreign key would refuse the rest.
        // @DB-UNBOUNDED-01-EXCEPTION - narrowed to the ids one text names
        $present = File_Attachment_Model::without_site_scope(
            fn () => File_Attachment_Model::withTrashed()->whereIn('id', $missing)->pluck('id')->all()
        );

        foreach ($present as $file_attachment_id) {
            $row = new File_Embed_Reference_Model();
            $row->site_id = $owner->site_id ?? null;
            $row->owner_type = $owner_type;
            $row->owner_id = (int) $owner->getKey();
            $row->column_name = $column;
            $row->file_attachment_id = (int) $file_attachment_id;
            $row->save();
        }
    }

    /**
     * Whether any record's text embeds this attachment.
     *
     * @param int $file_attachment_id
     * @return bool
     */
    public static function is_referenced(int $file_attachment_id): bool
    {
        return File_Embed_Reference_Model::where('file_attachment_id', $file_attachment_id)->exists();
    }

    /**
     * Every {owner_type, owner_id, column_name} that embeds this attachment, oldest first.
     *
     * @param int $file_attachment_id
     * @return array<int, array{owner_type: string, owner_id: int, column_name: string}>
     */
    public static function owners_of(int $file_attachment_id): array
    {
        $owners = [];
        foreach (File_Embed_Reference_Model::where('file_attachment_id', $file_attachment_id)->result_set() as $row) {
            $owners[] = [
                'owner_type' => (string) $row->owner_type,
                'owner_id' => (int) $row->owner_id,
                'column_name' => (string) $row->column_name,
            ];
        }

        return $owners;
    }

    /**
     * The query fragment the unclaimed-upload sweep excludes: attachment ids some reference
     * names. Framework-internal.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function _referenced_ids_query()
    {
        return \Illuminate\Support\Facades\DB::table('_file_embed_references')->select('file_attachment_id');
    }
}
