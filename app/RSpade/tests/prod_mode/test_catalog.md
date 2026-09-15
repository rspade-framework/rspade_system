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
| SEAL-01 | write() records every build asset + metadata; read() round-trips | php | staged fake build root | 4 assets, build_key from disk | implemented | 2026-07-15 |
| SEAL-02 | verify() clean when untampered (only mode drift under dev test proc) | php | seal then verify | no asset/build_key drift | implemented | 2026-07-15 |
| SEAL-03 | verify() detects a deleted asset | php | unlink a sealed file | "Missing asset" finding | implemented | 2026-07-15 |
| SEAL-04 | verify() detects a tampered asset (hash mismatch) | php | rewrite a sealed file | "Hash mismatch" finding | implemented | 2026-07-15 |
| SEAL-05 | verify() detects a build_key change | php | rewrite build_key file | "Build key mismatch" finding | implemented | 2026-07-15 |
| SEAL-06 | verify() reports cleanly when no seal present | php | no write() | single "No seal present" | implemented | 2026-07-15 |
| SEAL-07 | is_sealed() reflects mode when a seal file is present (== is_production) | php | seal file, any mode | is_sealed()==is_production() | implemented | 2026-07-15 |
| SEAL-08 | is_sealed() false without a seal file | php | no seal file | is_sealed() false | implemented | 2026-07-15 |

## Prod_Guard_Test (php)

The build-tree write guard keys on the MODE and the build context, never on the presence
of a seal file. Build root redirected with `Rsx_Project_Paths::_override(['build' => ...])`,
mode via `Rsx::_testing_set_mode()`.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| GUARD-01 | development is never guarded - it rebuilds on demand | php | development mode | no throw | implemented | 2026-09-15 |
| GUARD-02 | an UNSEALED production box is guarded (no seal file anywhere) | php | production mode, no seal | RuntimeException naming `rsx:build --force` | implemented | 2026-09-15 |
| GUARD-03 | debug is guarded like production | php | debug mode | RuntimeException | implemented | 2026-09-15 |
| GUARD-04 | the build context is the one key | php | production mode + `Rsx_Build_Context::begin()` | no throw | implemented | 2026-09-15 |
| GUARD-05 | a path outside the build tree is not the guard's business | php | production mode, external path | no throw | implemented | 2026-09-15 |
| GUARD-06 | rsx:clean's refusal asks the same two questions (prod mode, not a build) | php | mode + context seams | demands --force, then does not | implemented | 2026-09-15 |

## Seal_Gate_Test (php)

What a production-like box does when it has no build to serve. The predicate is
`Manifest::__production_build_is_unusable()` - the same question `Manifest::init()` asks
before it throws - driven against a scratch build root and a scratch `Manifest_Build`,
so the developer's own index is never consulted. The END-TO-END half (a real unsealed
box answering 500, rsx:health exiting 1) is `cli/prod_lifecycle.sh`.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| GATE-01 | the refusal states the condition and both remedies | php | `UNSEALED_BUILD_MESSAGE` | names unsealed, `rsx:build --force`, `rsx:man prod` | implemented | 2026-09-15 |
| GATE-02 | no index and no seal is unusable | php | production, empty build root | true | implemented | 2026-09-15 |
| GATE-03 | an index left by an interrupted build is not a build | php | production, index only | true | implemented | 2026-09-15 |
| GATE-04 | a seal whose assets are gone is not a build | php | production, seal only | true | implemented | 2026-09-15 |
| GATE-05 | index plus seal is what rsx:build leaves behind | php | production, both | false | implemented | 2026-09-15 |
| GATE-06 | the build is never gated on the artifact it produces | php | production, nothing, build context | false | implemented | 2026-09-15 |
| GATE-07 | development never refuses | php | development, nothing | false | implemented | 2026-09-15 |
| GATE-08 | debug is gated exactly like production | php | debug, nothing | true | implemented | 2026-09-15 |
| GATE-09 | the mode and introspection commands skip the manifest - recovery works ON the broken box | php | argv per command | true for all seven | implemented | 2026-09-15 |
| GATE-10 | every other command faces the gate | php | migrate, rsx:health, rsx:test, rsx:prod:verify | false for all | implemented | 2026-09-15 |
| GATE-11 | a bare `php artisan` lists commands rather than refusing | php | argv of length 1 | true | implemented | 2026-09-15 |

## Write_Guard_Test (php)

Prod_Guard_Test pins the predicate; this pins the PLUMBING - that the three functions
everything writes the build tree through actually call it, and that a refused write
leaves the disk untouched. The guard is one line at the top of each writer, removable
by accident, and a predicate test cannot see that.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| WGUARD-01 | `file_put_contents_safe` into build/ is refused in production, and nothing lands | php | production, bundle path | throws; no file | implemented | 2026-09-15 |
| WGUARD-02 | the same write succeeds in development | php | development | file written | implemented | 2026-09-15 |
| WGUARD-03 | the same write succeeds inside a build | php | production + build context | file written | implemented | 2026-09-15 |
| WGUARD-04 | a write outside the build tree is not the guard's business | php | production, temp path | file written | implemented | 2026-09-15 |
| WGUARD-05 | `rmdir_recursive` of build/ is refused, and deletes nothing at all | php | production, bundles dir | throws; contents intact | implemented | 2026-09-15 |
| WGUARD-06 | a build may discard what it is about to rebuild | php | production + build context | directory gone | implemented | 2026-09-15 |
| WGUARD-07 | `ensure_build_tree()` never grows an empty tree in production | php | production, missing root | throws; root still missing | implemented | 2026-09-15 |
| WGUARD-08 | the build creates the four directories it needs | php | production + build context | root, bundles, laravel, views | implemented | 2026-09-15 |
| WGUARD-09 | development creates the tree without ceremony | php | development, missing root | bundles created | implemented | 2026-09-15 |

## cli/prod_lifecycle.sh (cli, bash)

The real round trip on the box the test runs on: three builds, roughly four minutes.
Run it with `bash`, not `rsx:test` (bash tests are not manifest-discovered). Ends in
development mode whatever happens - the EXIT trap runs `rsx:prod:disable`.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| LIFE-01 | rsx:prod:enable seals, and says so | cli | development box | exit 0, seal summary with a build key, seal file present | implemented | 2026-09-15 |
| LIFE-02 | the sealed build serves the page and the bundle the page names | cli | GET /login, GET the scraped /_compiled/ URL | 200, 200 | implemented | 2026-09-15 |
| LIFE-03 | serving + rsx:health + migrate write NOTHING under build/, system/, rsx/ | cli | marker file, then the three | `find -newer` empty | implemented | 2026-09-15 |
| LIFE-04 | rsx:build refuses to rebuild a sealed box without --force | cli | rsx:build | non-zero, names --force | implemented | 2026-09-15 |
| LIFE-05 | rsx:clean refuses to discard a sealed tree without --force | cli | rsx:clean | non-zero, names --force | implemented | 2026-09-15 |
| LIFE-06 | the seal describes what is on disk | cli | rsx:prod:verify | exit 0 | implemented | 2026-09-15 |
| LIFE-07 | without its seal the box STOPS rather than rebuilding itself | cli | rm the seal, GET /login, rsx:health | 500; non-zero naming "unsealed" and `rsx:build --force` | implemented | 2026-09-15 |
| LIFE-08 | rsx:build --force is the repair | cli | rsx:build --force, GET /login | seal written, 200 | implemented | 2026-09-15 |
| LIFE-09 | rsx:prod:disable returns a working development box | cli | rsx:prod:disable, rsx:debug / | development, no seal, 200 with no console errors | implemented | 2026-09-15 |

## cli/prod_readonly.sh (cli, bash)

The recommended production posture, applied to the real trees: `chmod -R a-w build
system rsx`, exercise the box, restore every recorded mode. Complete only when the test
user is unprivileged; as root it proves the bits are gone, that an unprivileged process
is refused, and that nothing was written anyway. The script says which path it took.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| RO-01 | every write bit under the three trees is actually gone | cli | chmod -R a-w, stat samples | no `w` in any mode | implemented | 2026-09-15 |
| RO-02 | an unprivileged process is refused by the OS | cli | setpriv uid 65534 writing into build/ | Permission denied | implemented (skipped when setpriv is absent) | 2026-09-15 |
| RO-03 | a read-only box still serves the page and the bundle | cli | GET /login, GET the bundle | 200, 200 | implemented | 2026-09-15 |
| RO-04 | migrate runs with nothing pending against read-only trees | cli | migrate | exit 0 | implemented | 2026-09-15 |
| RO-05 | a framework task runs against read-only trees | cli | rsx:task:run Session_Cleanup_Service cleanup_sessions | exit 0 | implemented | 2026-09-15 |
| RO-06 | rsx:health reports against read-only trees | cli | rsx:health | exit 0 | implemented | 2026-09-15 |
| RO-07 | none of it wrote into the three trees | cli | marker, then all of the above | `find -newer` empty | implemented | 2026-09-15 |
| RO-08 | the modes are restored exactly and development still serves | cli | restore, rsx:prod:disable, GET /login | writable again, development, 200 | implemented | 2026-09-15 |

## Build_Context_Test (php)

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| BCTX-01 | a web request can NEVER be a build context - the SAPI check precedes every grant | php | source order of the three grants | SAPI check first | implemented | 2026-09-15 |
| BCTX-02 | off until something declares it; an inactive context hands nothing to children | php | reset state | false, `[]` | implemented | 2026-09-15 |
| BCTX-03 | begin() declares this process and the context travels to subprocesses | php | `begin()` | active, `['--_build-context']` | implemented | 2026-09-15 |
| BCTX-04 | the internal flag is honoured (how a build's subprocess inherits) | php | `Rsx_Internal_Flags::set(FLAG)` | active | implemented | 2026-09-15 |
| BCTX-05 | the flag follows the `--_` convention (no help output, no unknown-option error) | php | the constant | `--_` prefix | implemented | 2026-09-15 |
| BCTX-06 | the CDN download policy per mode: dev yes, production only in the build phase | php | `_download_is_permitted` | true / false / true | implemented | 2026-09-15 |

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
| POL-09 | `Manifest::$_force_build` deleted - the build context is the only build authorization | php | reflection | property_exists false | implemented | 2026-09-15 |
| POL-10 | `_is_safe_command()` deleted - no command allowlist stands between a box and its manifest | php | reflection | method_exists false | implemented | 2026-09-15 |

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

## Bundle emission order (`php/Bundle_Emission_Order_Test.php`)

The bundle is resolved from the manifest - an application module bundle that has been
compiled and whose include list has at least two directories emitting JS classes - and the
classes compared are found by SHAPE, so nothing here names an application class or bundle.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| EMIT-01 | The eval chain a module-scope model reference depends on holds: Rsx_Js_Model -> model stub -> concrete alias -> a class from a LATER include directory | php | newest compiled app JS of a qualifying application bundle | the four line numbers strictly increasing | implemented | 2026-09-08 |
| EMIT-02 | An EARLIER include directory's class precedes a later one's - declared include order IS eval order | php | the same bundle's first and last emitting include directories | first < last | implemented | 2026-09-08 |

## Deferred / planned (later batches)

| ID | Purpose | Type | Status | Last updated |
|----|---------|------|--------|--------------|
| CON-02 | strict-prod bundle contains zero console_debug call sites | asset | planned (Batch 3, E2E grep) | 2026-07-14 |
| DET-01 | two-checkout build_key + bundle filename identity | harness | Batch 2 acceptance (writer-run, not a persistent test) | 2026-07-15 |
| RO-09 | the read-only posture enforced against the artisan commands THEMSELVES | cli | deferred: needs a test user that is not root, which this box does not have | 2026-09-15 |
