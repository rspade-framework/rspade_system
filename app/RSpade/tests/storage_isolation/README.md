# storage_isolation - file-subsystem test isolation (B-38)

## Domain

The content-addressed blob store, thumbnail cache, and rendition cache are single on-disk
directories shared by every database on the box. `rsx:test` swaps the DATABASE to
`rspade_test` but historically left those directories shared, so a model-layer attachment
delete on the TEST database could unlink a disk file whose bytes also backed a DEVELOPER-database
blob - destroying the dev file (proven 2026-07-21; backlog B-38).

The fix routes EVERY file-subsystem disk path through `App\RSpade\Core\Files\Rsx_File_Paths`,
whose `storage_root()` returns `config('rsx.files.storage_root')` when set, else `storage_path()`.
The test runner sets that key to `storage/rsx-tmp/test-storage` for the whole run (mirroring the
DB swap) and passes it to the migrate-provisioning subprocess via `migrate --rsx-storage-root`,
so seed migrations that write blobs (e.g. the template app's `import_sample_documents`) also stay
inside the isolated root.

## Source under test

- `app/RSpade/Core/Files/Rsx_File_Paths.php` - the choke point: `storage_root()`, `blob_root()`,
  `thumbnails_root()`, `renditions_root()`.
- `app/RSpade/Core/Files/File_Storage_Model.php` - `get_full_path()` + the `find_or_create()`
  write path resolve via `blob_root()`; `get_storage_path()` stays a relative display path.
- `app/RSpade/Core/Files/File_Attachment_Model.php` - the `deleted()` hook that unlinks the
  last-reference blob (the B-38 destruction path).
- `app/RSpade/Core/Files/File_Attachment_Controller.php`, `File_Thumbnail_Service.php`,
  `File_Rendition_Service.php`, `File_Preview_Controller.php` - thumbnail/rendition cache paths.
- `app/RSpade/Commands/Rsx/Rsx_Test_Command.php` - sets the override + passes the subprocess flag.
- `app/RSpade/Commands/Migrate/Maint_Migrate.php` - the `--rsx-storage-root` test-isolation seam.
- Config `rsx.files.storage_root` (INTERNAL, runner-set, default null).

## Behavior defined by

Backlog `docs.dev/backlog/BACKLOG.md` B-38; `system/app/RSpade/tests/CLAUDE.md`
("File-storage isolation"); `breaking_changes/test_storage_isolation_07_21.txt`.

## Testable surface

- Default-mode roots equal the `storage_path('...')` layout exactly - the blob store is
  `storage_path('uploads')`, the thumbnail and rendition caches `storage_path('rsx-thumbnails'
  | 'rsx-renditions')` (php).
- The run's override relocates every root under `storage/rsx-tmp/test-storage` (php).
- A blob authored during a run is written under the test root, never the real store, and a
  delete unlinks the test-root file only - the real store is untouched (php; the decisive
  B-38 proof).
- Thumbnail + rendition cache-path seams honor the override (php).
- Whole-suite guarantee (verified out-of-band, not a discrete test): a full
  `rsx:test --framework` run leaves the real blob store's file inventory unchanged.
