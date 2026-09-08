# prod_mode

Framework tests for the RSpade "Prod Mode" epic: sealed builds, build-key
determinism, guardrails, and console policy. This concern is being built out
batch-by-batch alongside the epic; Batch 1 covers the determinism core, Batch 2
adds the seal + immutability guard units.

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
- `app/RSpade/Core/Manifest/_Manifest_Cache_Helper.php`
  - `_compute_hash($manifest_body)` - the build key: sorted per-file hashes + the derived sections.
  - `_save()` - writes the two index halves; no `generated` timestamp in any mode.

Consumers of the file hash (context): `BundleCompiler::_get_cache_key`,
`Js_Parser`, `Js_Transformer`. Consumer of the manifest hash: the sibling
`storage/rsx-build/build_key` file -> FPC keys, bundle URLs, SSR/cache keys.

Batch 2 (seal + guards):

- `app/RSpade/Core/Prod/Rsx_Prod_Seal.php` - the seal (write/read/verify/
  is_sealed/is_authorized/assert_mutable), with a `_testing_set_root` /
  `_testing_set_sealed` seam so tests never touch the real build root.
- `app/RSpade/Core/Prod/Rsx_Prod_Env.php` - shared .env mode rewrite + cache
  clearing used by the enable/refresh/disable commands.
- Guard chokepoints: `file_put_contents_safe()` + `rmdir_recursive()`
  (helpers.php), `Rsx_Storage_Helper::clear()`, and the `rsx:clean` command call
  `Rsx_Prod_Seal::assert_mutable()` / `is_sealed()`.
- Commands: `rsx:prod:enable|refresh|disable|verify` (Commands/Rsx), the
  delegating `rsx:mode:set`, and `rsx:prod:build`'s sealed-guard + loud
  optimize:cache + composer autoloader step.

## Man pages that define behavior

- `man/app_mode.txt` (rewritten in the epic's docs batch).
- `man/storage_directories.txt`.
- Root `CLAUDE.md` "Application Modes" section.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| File hash determinism (content path) | php | Batch 1 - implemented |
| Manifest hash normalization | php | Batch 1 - implemented |
| Seal write/read/verify + drift | php | Batch 2 |
| assert_mutable gating (sealed/authorized) | php | Batch 2 |
| Clean_Command sealed refusal | php/cli | Batch 2 |
| console_debug strip (pure_funcs in strict prod) | asset | Batch 3 (unit on option builder); full strip proven by E2E grep |
| enable/refresh/disable/verify lifecycle | cli | Batch 2 (writer-verified E2E, not a persistent test - box must end in dev mode) |
| Two-checkout determinism | (harness) | Batch 2 acceptance harness, not a discovered test |

## Notes

- All Batch 1 tests are pure logic (`$use_database_transactions = false`); no DB.
- The file-hash tests exercise the PROD content branch WITHOUT flipping the
  process-global `RSX_MODE`, by calling the extracted pure helpers directly.
