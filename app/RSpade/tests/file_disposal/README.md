# Concern: file_disposal

The retention and destruction half of the file subsystem. `delete()` on an attachment does
NOT erase anything - it ENTERS a recoverable retention window (soft-delete, blob preserved).
Permanent erasure happens later, on a schedule, and only when no live-or-retained attachment
still pins the deduplicated blob. `force_destroy()` is the one immediate path.

The distinction is the whole point of the concern: an app that treats `delete()` as erasure
will hand a user a "permanently deleted" message about a file that is still on disk and
restorable, and an app that treats it as reversible forever will keep listing a destroyed
file as recoverable. The tests pin both halves of that contract.

## Source under test

- `system/app/RSpade/Core/Files/File_Disposal_Service.php` - the SOLE blob-release
  authority: `release_blob_if_orphaned()` (the retention-aware refcount), the daily
  destroy+release task, the monthly deep sweep, the 6-hourly unclaimed-upload sweep
- `system/app/RSpade/Core/Files/File_Attachment_Model.php` - `delete()` (SoftDeletes),
  `undelete()`, `force_destroy()`, `get_deleted_files()`, `get_deleted_attachments()`
- `system/app/RSpade/Core/Files/File_Storage_Model.php` - the deduplicated blob row that
  the refcount protects

## Behavior defined by

`php artisan rsx:man file_disposal` (the full contract), plus
`system/app/RSpade/Core/Files/CLAUDE.md` for the surrounding attachment API.

Config: `rsx.files.deleted_retention_days` (30), `rsx.files.disposal_lookback_days` (60),
`rsx.files.disk_orphan_min_age_days` (14).

Hooks: `file.attachment.destroy.hold` (GATE - framework convention, `true` PERMITS) and
`file.attachment.destroyed` (ACTION).

## Applicability note

These tests COMMIT. A blob unlink is a filesystem operation, not a transactional one, so a
rolled-back transaction would leave the disk and the database disagreeing about what the
test just proved. `File_Disposal_Test` therefore declares `$requires_db_reset = true` +
`$use_database_transactions = false` and clears `_file_attachments` / `_file_storage`
itself around every method.

That is safe because the runner relocates the ENTIRE file subsystem to
`storage/rsx-tmp/test-storage` (see `tests/CLAUDE.md`, File-storage isolation). Without
that relocation a test-DB destroy would unlink a blob whose bytes match a developer file
and take the real one with it - the deduplication is content-addressed and does not care
which database pointed at it.

The hooks are observed through a fixture listener (`File_Disposal_Test_Listener`) with
`$hold` / `$throw_on_destroyed` / `$destroyed_ids` levers, reset between methods.

## Testable surface

| Area | Type | Status |
|---|---|---|
| `delete()` enters retention (hidden, recoverable, blob alive, enumerable) | php | implemented (`File_Disposal_Test`) |
| `undelete()` restores; throws once destroyed | php | implemented (`File_Disposal_Test`) |
| Retention-aware refcount across a shared (deduplicated) blob | php | implemented (`File_Disposal_Test`) |
| Daily destroy pass: stamps `destroyed_at`, fires the action, releases the blob | php | implemented (`File_Disposal_Test`) |
| `destroy.hold` gate defers, then releases on a later run | php | implemented (`File_Disposal_Test`) |
| `force_destroy()` is immediate, announces itself, ignores a hold, survives a throwing listener | php | implemented (`File_Disposal_Test`) |
| Monthly deep sweep: disk/refcount reconciliation + `disk_orphan_min_age_days` guard | php | not implemented - the sweep walks the real storage tree; needs a seeded orphan-on-disk fixture |
| 6-hourly `sweep_unclaimed_uploads` claim window (`rsx.attachments` claim hours) | php | not implemented - covered indirectly by the attachments concern's ownership tests, not by a clock-advanced sweep |
| Retention window boundary honors `deleted_retention_days` config rather than the hardcoded 30 | php | not implemented - every test backdates 40 days, so an off-by-one at the boundary would pass |
| Staff "Recently Deleted" recovery screen | playwright | not implemented |

## Related concerns

`attachments/` (upload, ownership, the claim guard), `preview/` (renditions keyed on the
same blob), `search/` (extraction keyed on the same blob). A blob released here is a blob
those three lose.
