# manifest

The manifest BUILD: what it indexes, what an incremental rebuild re-parses, and what it
costs in memory.

Query-side behaviour (`Manifest::php_find_class()`, `get_with_attribute()`, the route
tables) is exercised by the concern that owns the feature - `dispatch` for routes,
`auth_gates` for the auth index, `class_override` for the override archive. What lives
here is the build itself.

## The seam these tests use

`App\RSpade\Core\Manifest\Manifest_Build` is the build as an object: scan roots (relative
to `base_path()`), the storage root the index is written to, the mode, and the ONE
`Source_Cache` the fixer and the code-quality driver read every file through.
`Manifest::build()` is the facade's accessor; `Manifest::_use_build_for_tests()` replaces
it in-process.

**These tests do not build in-process.** A manifest build mutates a great deal of global
state - `Manifest::$data`, the autoloader, generated stubs - and a test process is already
holding a manifest it needs. So every test here spawns `rsx:manifest:build` in a CHILD
through `Rsx_Artisan`, pointed at a scratch storage root, and asserts on the index file the
child wrote. Three framework-INTERNAL flags (the `--_` convention: no `InputOption`,
stripped from argv pre-boot, invisible to `php artisan list`) make that possible:

| Flag | Effect |
|---|---|
| `--_manifest-storage-root=<abs>` | Write the index under this root instead of `storage_path()` |
| `--_manifest-extra-scan-roots=<csv>` | ADD roots to the configured list |
| `--_manifest-scan-roots=<csv>` | Replace the list outright (test-tree additions included) |
| `--_manifest-report-peak` | Print `MANIFEST_PEAK_BYTES=<n>` after the summary |

A fixture tree lives under `app/RSpade/temp/`, because the manifest's path vocabulary is
`base_path()`-relative: an index key is a relative path and `base_path($key)` is the file,
so a tree outside `base_path()` cannot be indexed at all.

An additive fixture root cannot be the ONLY root. The framework's own support modules,
models and parent classes are resolved THROUGH the index, so a build of a fixture tree
alone dies at "Manifest support module must extend ManifestSupport_Abstract".

## Source map

| Subject | Source |
|---|---|
| The build as an object | `Core/Manifest/Manifest_Build.php` |
| The facade and the phase sequence | `Core/Manifest/Manifest.php` (`init`, `_refresh_manifest`) |
| Discovery and change detection | `Core/Manifest/_Manifest_Scanner_Helper.php` |
| Persistence, the bad-manifest flag | `Core/Manifest/_Manifest_Cache_Helper.php` |
| The code-quality pass | `CodeQuality/Manifest_Rule_Driver.php`, `CodeQuality/Support/Source_Cache.php` |

Man pages: `rsx:man manifest_build`, `rsx:man manifest_api`, `rsx:man code_quality`.
Colocated: `Core/Manifest/CLAUDE.md`.

## The memory budget

Owner ruling: **peak memory is proportional to the index plus a constant bounded by the
largest single file, never proportional to the number of files parsed.** The acceptance
numbers are a cold build of the reference tree under **128 MB** and of a synthetic tree
five times its size under **256 MB**, and `Manifest_Memory_Gate_Test` is those two numbers.

A gate that fails is a FINDING, never a number to raise. Profile the build
(`docs.dev/manifest_review/05_PROFILING.md`) and report what grew.

## What the incremental tests assert, and why it has to be an EQUALITY

`Manifest_Incremental_Modules_Test` is the module contract. Every support module now
updates its own index section from the CHANGED and REMOVED sets instead of rebuilding it
from a pass over the whole file map, and "it produces the same answer" is not something
prose can check. So each test builds the same fixture tree twice - once INCREMENTALLY
(build, edit one file, build again in the SAME storage root) and once COLD (the final
tree, in a FRESH root) - and compares the two indexes section by section and record by
record. A module that forgot to drop a stale row, or dropped one it should have kept,
cannot survive that.

The comparison is by `json_encode`, so it is sensitive to KEY ORDER as well as content -
deliberately, because key order is part of the index's bytes and therefore part of the
build key. Two determinism defects were found by exactly that sensitivity and fixed:
several modules write the same section and an incremental update appends to it (fixed by
one central ksort after the last module), and the file map itself was only sorted at save
time while the indexes derived from it are LISTS in its order (fixed by sorting it right
after the parse pass).

`Manifest_Model_Introspection_Test` counts QUERIES, because that is what the model
module's fingerprint is for: two consecutive passes over an unchanged tree must issue
zero `SHOW COLUMNS`. It drives the module directly rather than through a build, because a
build in a child process cannot be listened to. A `Schema::hasTable` probe DOES survive
for a model whose table is missing, and that is deliberate - see the test.

`Manifest_Stub_Rewrite_Test` asserts MTIMES, because that is what the damage is made of:
a stub write that changes not one byte still recompiles every bundle carrying the stub.
