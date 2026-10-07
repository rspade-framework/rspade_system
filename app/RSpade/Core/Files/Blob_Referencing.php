<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\File_Blob_Locks;

/**
 * Blob_Referencing - the write side of a #[Blob_Reference] declaration.
 *
 * A write that points a row at a blob is a REFERENCE being recorded. save() holds the blob's
 * read lock until the row commits, so File_Disposal_Service cannot release the bytes between
 * the caller finding the storage row and this row pinning it; it throws if the storage row
 * was released first. Inside File_Storage_Model::store_blob() / store_bytes() the lock is
 * already held and this is a reentrant hold. See File_Blob_Locks.
 *
 * The using model declares #[Blob_Reference('file_storage_id')] beside it (File_Blob_References
 * is the disposal side). The column is file_storage_id.
 */
trait Blob_Referencing
{
    /**
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        if ($this->file_storage_id !== null && $this->isDirty('file_storage_id')) {
            return File_Blob_Locks::referencing_storage(
                (int) $this->file_storage_id,
                fn () => parent::save($options)
            );
        }

        return parent::save($options);
    }
}
