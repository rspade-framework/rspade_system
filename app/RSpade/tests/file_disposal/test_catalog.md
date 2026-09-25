# Test catalog: file_disposal

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.

## File_Disposal_Test (php, `$requires_db_reset` + no transaction) - retention lifecycle

Commits on purpose: blob unlinks are filesystem operations and would survive a rollback
that erased the matching rows. The whole file subsystem is relocated to test-storage by
the runner, so nothing here can reach a developer blob. Every method calls `__reset()`
first (truncate `_file_attachments` + `_file_storage`, clear the fixture listener).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fd-01 | `delete()` enters retention, it does not destroy | one attachment, `delete()` | hidden from the default scope, found `withTrashed()`, `deleted_at` set, `destroyed_at` NULL, blob on disk, listed by `get_deleted_files()` | implemented |
| fd-02 | `undelete()` restores a retained attachment | soft-deleted attachment | back in the default scope, `deleted_at` cleared | implemented |
| fd-03 | `undelete()` throws once the bytes are gone | force-destroyed attachment | throws, message contains "destroyed" | implemented |
| fd-04 | Deduplication is real | two attachments, identical content | one shared `file_storage_id` | implemented |
| fd-05 | Refcount is RETENTION-aware: a live sibling pins the blob | soft-delete one of the two | `release_blob_if_orphaned()` false, blob on disk | implemented |
| fd-06 | A destroyed sibling still does not release a pinned blob | force-destroy the first | blob on disk (the live one still pins it) | implemented |
| fd-07 | The last referrer releases the blob | force-destroy the second | blob unlinked, `_file_storage` row deleted | implemented |
| fd-08 | The daily pass destroys past the retention window | `deleted_at` backdated 40 days | `destroyed_at` stamped, `file.attachment.destroyed` fired, blob released, storage row gone | implemented |
| fd-09 | The `destroy.hold` gate defers destruction | listener `$hold = true`, daily pass | `destroyed_at` still NULL | implemented |
| fd-10 | A cleared hold destroys on the NEXT run (not never) | `$hold = false`, daily pass again | `destroyed_at` stamped | implemented |
| fd-11 | `force_destroy()` is immediate | live attachment | `deleted_at` AND `destroyed_at` stamped, blob released, in one call | implemented |
| fd-12 | `force_destroy()` ANNOUNCES itself | live attachment | `file.attachment.destroyed` fired (a tombstone keyed on the hook would otherwise still call the file restorable) | implemented |
| fd-13 | `force_destroy()` ignores a hold | `$hold = true`, force destroy | destroyed anyway - vetoing a forced erasure is what "force" refuses to allow | implemented |
| fd-14 | A throwing listener does not derail a forced destroy | `$throw_on_destroyed = true` | destruction completes, blob released - deliberately UNLIKE the scheduled path, whose most important caller is the rejected-upload rollback | implemented |

## File_Disposal_All_Sites_Test (php, default isolation) - every pass spans every site

The blob store is deduplicated across the install and retention is install policy, so a
worker declaring site 1 must see a second site's attachments.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fd-30 | the daily pass destroys another site's attachment past retention | site-2 attachment, `deleted_at` backdated 400 days | `destroyed_at` stamped, site_id still 2 | implemented |
| fd-31 | a blob another site still holds is never released | identical bytes attached under site 1 and site 2; site 1's force-destroyed | `release_blob_if_orphaned()` false, storage row survives | implemented |
| fd-32 | the claim-window sweep reaches another site's upload | unclaimed site-2 upload, `created_at` backdated 30 days | soft-deleted | implemented |

## File_Disposal_Retention_Config_Test (php, `$requires_db_reset` + no transaction) - `rsx.files.deleted_retention_days`

Commits for the same reason as `File_Disposal_Test`. Each method sets the key around its run
and restores it in a `finally`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fd-40 | 0 means KEEP FOREVER | retention 0, `deleted_at` backdated 4000 days, daily pass + forced monthly sweep | `destroyed_at` NULL, no destroyed action, blob on disk, listed by `get_deleted_files()`, `undelete()` restores it | implemented |
| fd-41 | Explicit destruction is unaffected by 0 | retention 0: `force_destroy()`; and a destroyed row whose blob is still present, daily pass | force-destroy immediate and releases; the blob-release pass frees the other blob | implemented |
| fd-42 | A positive value is honoured at its boundary | retention 5, one deleted 6 days ago, one 4 days ago | the first destroyed and its blob released, the second retained | implemented |
| fd-43 | A bad value is a config error | -1, `'thirty'`, 1.5 | the daily pass throws `RuntimeException` naming the key; nothing destroyed | implemented |

## Not implemented

| ID | Purpose | Why not | Status |
|----|---------|---------|--------|
| fd-20 | Monthly deep sweep reconciles refcounts against the disk | needs an orphan planted on the real storage tree plus an aged mtime; the sweep walks directories rather than taking an injectable set | planned |
| fd-21 | `disk_orphan_min_age_days` protects a FRESH orphan from the monthly sweep | same fixture problem as fd-20, and this is the half that matters (an in-flight upload must not be swept) | planned |
| fd-22 | `sweep_unclaimed_uploads` destroys past the claim window and spares one inside it | clock control - every existing test backdates by a wide margin rather than probing the boundary | planned |
| fd-24 | The staff "Recently Deleted" screen restores a file end to end | playwright, no browser coverage of this concern yet | planned |

## Fixture

`File_Disposal_Test_Listener` (`php/File_Disposal_Test_Listener.php`) - an `#[OnEvent]`
listener registering on `file.attachment.destroy.hold` (gate) and
`file.attachment.destroyed` (action). Levers: `$hold`, `$throw_on_destroyed`; observation:
`$destroyed_ids`; `reset()` clears all three.
