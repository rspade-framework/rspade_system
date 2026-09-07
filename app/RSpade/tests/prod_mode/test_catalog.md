# prod_mode - test catalog

One row per test worth having (implemented AND not). Status is one of
`implemented`, `deferred`, `blocked`, `planned`.

## File_Hash_Determinism_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| FHD-01 | content hash stable for identical (rel path, content) | php | same rel+content twice | equal | implemented | 2026-07-14 |
| FHD-02 | content hash reacts to a content change | php | same rel, differing content | not equal | implemented | 2026-07-14 |
| FHD-03 | content hash reacts to a relative-path change | php | differing rel, same content | not equal | implemented | 2026-07-14 |
| FHD-04 | checkout independence: same rel+content at two absolute roots | php | file staged under two tmp roots | equal hashes | implemented | 2026-07-14 |
| FHD-05 | content file hash blind to mtime-only change | php | touch() after hashing | unchanged | implemented | 2026-07-14 |
| FHD-06 | content file hash reacts to byte change | php | rewrite file contents | changed | implemented | 2026-07-14 |
| FHD-07 | dev fast hash IS mtime-sensitive (by design) | php | touch() after hashing | changed | implemented | 2026-07-14 |
| FHD-08 | relative path strips base_path() | php | base_path()/app/... | app/... | implemented | 2026-07-14 |
| FHD-09 | relative path resolves the /rsx symlink route | php | base_path()/rsx/... | rsx/... | implemented | 2026-07-14 |
| FHD-10 | symlink and real /rsx mounts converge | php | base_path()/rsx vs root/rsx | equal, rsx/... | implemented | 2026-07-14 |
| FHD-11 | external path left unchanged | php | unrelated absolute path | unchanged | implemented | 2026-07-14 |

## Manifest_Hash_Normalization_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MHN-01 | build key blind to mtime mutation | php | fixture with mtime changed | equal hash | implemented | 2026-07-14 |
| MHN-02 | build key blind to size mutation | php | fixture with size changed | equal hash | implemented | 2026-07-14 |
| MHN-03 | build key reacts to a per-file sha1 change | php | fixture with hash changed | different hash | implemented | 2026-07-14 |
| MHN-04 | absolute paths stripped from embedded metadata | php | method 'file' under base_path() | project-relative | implemented | 2026-07-14 |
| MHN-05 | normalization drops mtime/size, keeps sha1+semantics | php | fixture | mtime/size gone, hash/class kept | implemented | 2026-07-14 |
| MHN-06 | normalization does not mutate the live input | php | fixture | mtime/size retained on input | implemented | 2026-07-14 |
| MHN-07 | build key stable regardless of file key order | php | reversed files array | equal hash | implemented | 2026-07-14 |

## Prod_Seal_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| SEAL-01 | write() records every build asset + metadata; read() round-trips | php | staged fake build root | 3 assets, build_key from disk | implemented | 2026-07-15 |
| SEAL-02 | verify() clean when untampered (only mode drift under dev test proc) | php | seal then verify | no asset/build_key drift | implemented | 2026-07-15 |
| SEAL-03 | verify() detects a deleted asset | php | unlink a sealed file | "Missing asset" finding | implemented | 2026-07-15 |
| SEAL-04 | verify() detects a tampered asset (hash mismatch) | php | rewrite a sealed file | "Hash mismatch" finding | implemented | 2026-07-15 |
| SEAL-05 | verify() detects a build_key change | php | rewrite build_key file | "Build key mismatch" finding | implemented | 2026-07-15 |
| SEAL-06 | verify() reports cleanly when no seal present | php | no write() | single "No seal present" | implemented | 2026-07-15 |
| SEAL-07 | is_sealed() reflects mode when a seal file is present (== is_production) | php | seal file, any mode | is_sealed()==is_production() | implemented | 2026-07-15 |
| SEAL-08 | is_sealed() false without a seal file | php | no seal file | is_sealed() false | implemented | 2026-07-15 |

## Prod_Guard_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| GUARD-01 | assert_mutable passes when not sealed (cheap common path) | php | _testing_sealed=false | no throw | implemented | 2026-07-15 |
| GUARD-02 | assert_mutable throws when sealed + unauthorized (path under root) | php | sealed, no auth flag | RuntimeException | implemented | 2026-07-15 |
| GUARD-03 | assert_mutable passes when sealed + authorized (rebuild path) | php | sealed, auth flag set | no throw | implemented | 2026-07-15 |
| GUARD-04 | assert_mutable ignores paths outside the build root | php | sealed, external path | no throw | implemented | 2026-07-15 |
| GUARD-05 | is_authorized requires the env flag | php | flag unset then set | false then true | implemented | 2026-07-15 |
| GUARD-06 | rsx:clean guard decision follows is_sealed() | php | seam flip | true then false | implemented | 2026-07-15 |

## Env_Symlink_Test (php)

Covers Rsx_Env_Symlink (the .env symlink healer). Path seam injects a throwaway
temp layout mirroring the real one (<tmp>/system/.env + <tmp>/.env); the real
.env files are never touched.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| ENV-01 | already-healthy symlink is a no-op | php | system/.env -> ../.env | status already_healthy, no mutation | implemented | 2026-07-15 |
| ENV-02 | wrong-target symlink is repointed to root | php | system/.env -> other file | resolves to root, target ../.env | implemented | 2026-07-15 |
| ENV-03 | regular-file merge: root wins, unique appended, comments preserved, backup 0600 | php | both real files, 1 conflict + 1 unique | root value kept + reported, unique appended under marker, root prefix byte-identical, symlink installed, backup 0600 | implemented | 2026-07-15 |
| ENV-04 | root missing -> move content then symlink | php | only system/.env exists | root created from content, symlink installed, backup 0600 | implemented | 2026-07-15 |
| ENV-05 | system missing (root exists) -> create symlink | php | only root .env exists | symlink created, no backup | implemented | 2026-07-15 |
| ENV-06 | neither exists -> fail loud | php | no .env at all | RuntimeException (unbootable) | implemented | 2026-07-15 |
| ENV-07 | dry-run reports plan but mutates nothing | php | drifted real files | drift report, files + no backup unchanged | implemented | 2026-07-15 |

## Policy_Helpers_Test (php)

The Manifest _should_* helpers are the single source of truth for every mode-gated
build decision. Mode driven via the Rsx::_testing_set_mode() seam (RSX_MODE untouched).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| POL-01 | _should_auto_rebuild: dev only | php | each mode | dev T, debug F, prod F | implemented | 2026-07-15 |
| POL-02 | _should_minify: strict prod only | php | each mode | dev F, debug F, prod T | implemented | 2026-07-15 |
| POL-03 | _should_inline_sourcemaps: dev+debug | php | each mode | dev T, debug T, prod F | implemented | 2026-07-15 |
| POL-04 | _should_inline_sourcemaps == !_should_minify | php | each mode | inverse holds | implemented | 2026-07-15 |
| POL-05 | _should_cache_cdn is GONE - mirroring is no longer mode-gated (every mode serves /_vendor/) | php | `method_exists(Manifest, '_should_cache_cdn')` | false | implemented | 2026-09-01 |
| POL-06 | _should_strip_console_debug: strict prod only | php | each mode | dev F, debug F, prod T | implemented | 2026-07-15 |
| POL-07 | _should_include_debug_info: dev+debug | php | each mode | dev T, debug T, prod F | implemented | 2026-07-15 |
| POL-08 | _should_merge_bundles deleted (merging is backlog) | php | reflection | method_exists false | implemented | 2026-07-15 |

## Console_Debug_Gate_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CDG-01 | PHP console_debug gate emits in development | php | dev mode seam | enabled true | implemented | 2026-07-15 |
| CDG-02 | PHP console_debug gate emits in debug mode | php | debug mode seam | enabled true | implemented | 2026-07-15 |
| CDG-03 | PHP console_debug gate suppressed in strict prod | php | prod mode seam | enabled false | implemented | 2026-07-15 |

## Minify_Strip_Test (asset)

Real node/Terser RPC round-trip; server force-restarted before, stopped after.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CON-01 | strip flag adds pure_funcs -> console_debug( call site removed | asset | minify with strip=true | call site gone, side-effect code kept | implemented | 2026-07-15 |
| CON-01b | no strip flag (debug builds) keeps console_debug( | asset | minify with strip=false | call site retained | implemented | 2026-07-15 |

## Deferred / planned (later batches)

| ID | Purpose | Type | Status | Last updated |
|----|---------|------|--------|--------------|
| CON-02 | strict-prod bundle contains zero console_debug call sites | asset | planned (Batch 3, E2E grep) | 2026-07-14 |
| DET-01 | two-checkout build_key + bundle filename identity | harness | Batch 2 acceptance (writer-run, not a persistent test) | 2026-07-15 |
