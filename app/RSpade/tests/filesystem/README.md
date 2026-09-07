# Concern: filesystem

## Domain overview & applicability

Safe filesystem primitives used framework-wide. Currently: `file_put_contents_safe()`
- the atomic-write replacement for `file_put_contents()` that prevents concurrent
readers from observing a half-written file (it writes to a temp file then renames
over the destination). Used by the manifest cache write, bundle/stub output, and
every full-file write under `app/RSpade`, so a regression here can corrupt build
artifacts read by concurrent requests.

## Source files

- `app/RSpade/helpers.php` - `file_put_contents_safe()`, `_paths_on_same_filesystem()`,
  `rmdir_recursive()`, `ensure_directory()`
- `app/RSpade/Commands/Rsx/Clean_Command.php` - sweeps orphaned `.tmp_*` staging dirs

## Man page(s)

- None (helper functions; covered by the Developer API reference in CLAUDE.md).

## Testable surface

- Writes full content and returns the byte count (file_put_contents contract). (php)
- Overwrite preserves the destination's existing permissions. (php)
- Same-filesystem detection. (php)
- Cross-filesystem write uses the `.tmp_<n>` staging dir and leaves none behind
  (exercised against /dev/shm tmpfs). (php)

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md.)
