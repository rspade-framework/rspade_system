# paths - the volatile-tree path owner

## Domain

RSpade keeps three volatile trees at the project root, and which tree a file belongs
in is a statement about its lifetime and its production posture:

| Tree | Holds | Posture |
|---|---|---|
| `build/` | the manifest index, compiled bundles, the five Laravel cache files, compiled Blade, the seal | build OUTPUTS; read-only to the web user on a correctly configured production box |
| `tmp/` | per-file transform caches, the generated JS stubs a bundle compile reads, node sockets, scratch files, the test runner's working directories, the database dump cache, the mail catcher, thumbnails and document renditions; also Laravel's storage path and what `TMPDIR` names | DERIVED; deletable at any moment |
| `storage/` | uploads, logs, `storage/app`, the IDE bridge, plus `storage/state` (maintenance flag, flock files, updater ledger, environment-update fingerprint) | USER data and process state |

`tmp/` and `storage/` are relocatable with `RSX_TMP_PATH` and `RSX_STORAGE_PATH`,
each taking an absolute path or one relative to the project root. `build/` is FIXED
at `<project>/build`, beside `system/` and `rsx/`, so a production box deploys and
freezes the three together. `storage/state` is deliberately NOT independently
relocatable, because a lock file only excludes when a parent process and its children
open the same one.

Two links live inside `tmp/` - `tmp/logs` and `tmp/app`, onto the persistent
directories of the same names - because Laravel's storage path IS the tmp tree: they
are what keeps a lazy `storage_path('logs')` out of a directory `rsx:clean` wipes.

**Why this concern exists.** A literal volatile path that survives a relocation does
not error. It addresses a directory that exists, is writable, and that nothing else
reads - so the writer succeeds, the reader finds nothing, and the defect surfaces as
missing data somewhere unrelated. Everything here is about the two guarantees that
close that hole: one owner names every location, and the pre-boot reader and the
booted reader answer identically.

## Source under test

- `system/app/RSpade/Core/Paths/Rsx_Project_Paths.php` - the owner. Roots, joiners,
  the named children, `key_for()`/`absolute_for()`, the predicates, the three
  `ensure_*_tree()` verbs, `child_env()`/`child_flags()`, and the `_override()` seam.
- `system/bootstrap/rsx_paths.php` - the pre-boot resolver the owner delegates to.
  Plain functions, no autoloader, no config.
- `system/bin/lib/rsx_paths.sh` and `system/bin/lib/rsx_paths.js` - the bash and node
  twins, for scripts and supervisor-started daemons.
- `system/app/RSpade/Core/Laravel/Rsx_Application.php` - Laravel's container taught
  that its five cached artifacts are build outputs.
- `system/bootstrap/rsx_storage_link.php` - the guard over `system/{storage,build,tmp}`.
- `system/bin/environment_updates/110_relocate_build_tmp.sh` - the one-time move
  (covered by `environment_updates/cli/t9_relocate_build_tmp.sh`).
- `system/app/RSpade/CodeQuality/Rules/Convention/PathOwner_CodeQualityRule.php` -
  `PATH-OWNER-01` (covered by `code_quality/php/Path_Owner_Rule_Test.php`).

## Man pages

`rsx:man storage_directories` - the three trees and what lives in each.

## Testable surface

| Area | Type | Notes |
|---|---|---|
| Default roots, state-follows-storage | php | The whole layout in one assertion each. |
| `key_for()`/`absolute_for()` round trip | php | The determinism the seal rests on: two boxes with different roots produce one index. |
| Predicates in both spellings | php | Every caller holds one spelling or the other and must not have to know which. |
| Override precedence and child propagation | php | In-process override, then argv flag, then resolver. |
| `ensure_build_tree()` is the only creator | php | Boot must never grow an empty build tree on a production box. |
| `build/laravel` at boot in DEVELOPMENT | php | The one directory boot does create, and only in development: Laravel's PackageManifest writes packages.php during boot and throws when the directory is absent, which on a fresh checkout would refuse the build that creates the tree. |
| Pre-boot resolution semantics | php (child processes) | First `.env` occurrence wins, environment beats file, empty means default, relative is refused. |
| Pre-boot / booted parity | php | The single assertion that catches a divergence between the two readers. |
| Laravel's five cache getters + compiled views | php | All five moved, `bootstrapPath()` itself did not. |
| The three `system/` tree links | php | Both spellings reach one directory. |
| The one-time relocation | cli (bash) | Lives in `environment_updates/`, beside its siblings - this concern has no `cli/` of its own. |

**Not covered here, deliberately**: the tree guard's REFUSAL branches exit the
process, and the shapes they refuse (a real directory holding somebody's only copy of
their uploads) are not something a test may fabricate against the live tree. The
environment-update test drives the repair side against a synthetic tree instead.
