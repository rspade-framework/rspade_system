# prod_mode

Framework tests for the RSpade "Prod Mode" epic: sealed builds, build-key
determinism, guardrails, and console policy. This concern is being built out
batch-by-batch alongside the epic; Batch 1 covers the determinism core, Batch 2
adds the seal + immutability guard units, and the cli/ pair is the lifecycle itself -
a real sealed build on the box the tests run on.

## Applicability

RSpade supports three modes (`development`, `debug`, `production`) selected by
`RSX_MODE`. Production/debug are "sealed builds": manifest + bundle assets are
compiled once by an explicit command, then treated as immutable. A hard
requirement is DETERMINISM - two byte-identical codebases put into prod mode at
different absolute checkout paths must produce identical asset filenames and an
identical build key (so a cluster / CI can share cache artifacts). These tests
protect that contract at the unit level.

## Source under test

Batch 1 (determinism core):

- `app/RSpade/helpers.php`
  - `_rsx_file_hash_for_build($file_path)` - dispatches on `Rsx::is_production()`.
  - `_rsx_file_hash_fast($file_path)` - dev metadata path (abs path + size + mtime).
  - `_rsx_file_hash_content_based($file_path)` - prod/debug content path.
  - `_rsx_content_hash($relative_path, $content)` - the pure hashing core.
  - `_rsx_relative_build_path($path)` - two-mount-convergent relative path.
- `app/RSpade/Core/Manifest/Manifest_Store.php`
  - `_compute_hash($manifest_body)` - the build key: sorted per-file hashes + the derived sections.
  - `_save()` - writes the two index halves; no `generated` timestamp in any mode.

Consumers of the file hash (context): `BundleCompiler::_get_cache_key`,
`Js_Parser`, `Js_Transformer`. Consumer of the manifest hash: the sibling
`build/build_key` file -> FPC keys, bundle URLs, SSR/cache keys.

Batch 2 (seal + guards):

- `app/RSpade/Core/Prod/Rsx_Prod_Seal.php` - the seal (write/read/verify/
  is_sealed), a STATE RECORD of a completed build. Tests point it at a throwaway
  build root with `Rsx_Project_Paths::_override(['build' => ...])`.
- `app/RSpade/Core/Prod/Rsx_Build_Context.php` - the one answer to "is this process
  producing the build tree?": `rsx:build` (recognised from argv), the `--_build-context`
  flag it hands its subprocesses, and `begin()`. Never a web request.
- `app/RSpade/Core/Paths/Rsx_Project_Paths::assert_build_writable()` - the write guard,
  keyed on the mode and that context. Chokepoints: `file_put_contents_safe()` and
  `rmdir_recursive()` (helpers.php), the manifest index writer, the bundle compiler's
  mkdir/unlink, `ensure_build_tree()`.
- `app/RSpade/Core/Prod/Rsx_Prod_Env.php` - the .env mode writer, and nothing else.
- `app/RSpade/Core/Manifest/Manifest.php` - the SEAL GATE: `init()` refuses in a
  production mode when the manifest index or the seal is absent and the process is not
  the build (`UNSEALED_BUILD_MESSAGE`), the same question
  `__production_build_is_unusable()` answers for pre-boot code, and the
  `__cli_skips_manifest_boot()` list that keeps the recovery commands reachable on a box
  with no build.
- Commands: `rsx:build` (THE build: clean, manifest, externals, bundles, composer,
  Laravel caches, seal), `rsx:clean` (THE wipe), `rsx:prod:enable|disable|verify`, and
  the delegating `rsx:mode:set`.

## Man pages that define behavior

- `man/prod.txt` and `man/app_mode.txt` (written in the epic's docs batch).
- `man/storage_directories.txt`.
- Root `CLAUDE.md` "Application Modes" section.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| File hash determinism (content path) | php | Batch 1 - implemented |
| Manifest hash normalization | php | Batch 1 - implemented |
| Seal write/read/verify + drift | php | Batch 2 |
| Build-tree write guard (mode + build context) | php | Batch 2 |
| Build context grants and the SAPI floor | php | Batch 2 |
| Clean_Command production refusal without --force | php/cli | Batch 2 |
| Seal gate: which boxes refuse, and who is exempt | php | implemented (Seal_Gate_Test) |
| The guard as its three writers call it | php | implemented (Write_Guard_Test) |
| console_debug strip (pure_funcs in strict prod) | asset | Batch 3 (unit on option builder); full strip proven by E2E grep |
| enable/disable/verify lifecycle, unseal and repair | cli | implemented (cli/prod_lifecycle.sh) - a REAL round trip on this box; the EXIT trap returns it to development |
| Read-only build/, system/, rsx/ while serving | cli | implemented (cli/prod_readonly.sh) - complete only when the test user is unprivileged; see the script header |
| Two-checkout determinism | (harness) | Batch 2 acceptance harness, not a discovered test |

## Notes

- The two `cli/` scripts are BASH and are therefore not discovered by
  `rsx:test --framework` (which runs the PHP-runnable types). They are run by
  `tests/run_all_tests.sh` alongside the `http/` scripts, or directly:
  `bash system/app/RSpade/tests/prod_mode/cli/prod_lifecycle.sh`. Each takes minutes -
  they build the box three times - and each restores development mode in an EXIT trap.
- All Batch 1 tests are pure logic (`$use_database_transactions = false`); no DB.
- The file-hash tests exercise the PROD content branch WITHOUT flipping the
  process-global `RSX_MODE`, by calling the extracted pure helpers directly.
