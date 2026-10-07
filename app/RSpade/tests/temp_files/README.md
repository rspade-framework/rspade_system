# temp_files

The temp file store, `Rsx_Temp_Files` (`rsx:man temp_files`): files a pipeline produces from
application data and keeps for a while - a CSV export waiting to be downloaded, a task's
attached file. Bytes live under `uploads/_temp/<2>/<random key>`, one `_temp_files` row each,
outside the content-addressed blob store.

## Source under test

- `app/RSpade/Core/Files/Rsx_Temp_Files.php` - store, find, delete, the expiry sweep.
- `app/RSpade/Core/Files/Temp_File_Model_Abstract.php` - reading the bytes, the responses.
- `app/RSpade/Core/Files/Temp_File_Cleanup_Service.php` - the hourly `#[Task]`.

## Behavior of record

- A key is random, never the content hash: identical bytes are two files, and deleting one
  asks nobody.
- Nothing enters `_file_storage`.
- Each file carries its own `expires_at` (`rsx.temp_files.retention_days`, 7, unless the
  caller chooses; at least 1). `find()` answers null for an expired file.
- The sweep deletes only expired files THIS database has rows for - never a file on disk
  with no row, which on a shared uploads mount is another environment's.

Task attachments are temp files; their behaviour is pinned in `tests/tasks`
(`Task_Attachments_Test`, `Task_Retention_Test`). The blob store's sweeps skipping
`uploads/_temp` is pinned in `tests/file_disposal` (fd-44).
