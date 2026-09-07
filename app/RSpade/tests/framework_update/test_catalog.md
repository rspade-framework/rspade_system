# Test catalog: framework_update

The framework update under the SUBMODULE model. system/ is a git submodule and ALL of it
is framework property, overwritten on every update - so the tests that used to live here
(the tamper gate, the mutation ledger, three-way reconciliation, owned-zone convergence,
release-inventory assertions, foreign-path untracking, `--force`/`--resync` repair,
conversion to a vendored tree) were deleted with the machinery they described.

Fixture: `cli/_lib_fixture`. `fx_build_downstream` builds a project whose system/ is a
submodule of a fake distribution; `fx_build_downstream_vendored` builds the pre-submodule
shape, for conversion. The fake distribution ships a FLAG-ONLY `bin/maintenance-mode.sh`
so no test can reach supervisorctl and stop the host's real services.

| ID | Purpose (what it proves) | Type | Expected |
|----|--------------------------|------|----------|
| PULL-01 | Clean update v1->v2: the submodule moves, the gitlink is committed, the history .dat records from/to | cli | exit 0, submodule at v2, `from=<v1> to=<v2>` in the log |
| PULL-05 | An up-to-date install exits 0 and changes nothing | cli | "up to date", no commit |
| PULL-16 | The commit moves ONE gitlink (mode 160000) plus the history file, and nothing else | cli | exactly `system` + the .dat in the commit |
| PULL-17 | The three refusal gates: framework-developer, forked, sealed RSX_MODE (the separate APP_ENV=production gate was removed 2026-08-23 - APP_ENV is no longer read anywhere) | cli | non-zero exit, the gate's own message, system/ untouched |
| PULL-19 | The maintenance flag is never committed; it lives outside the submodule | cli | flag absent from every commit |
| PULL-20 | The gitlink is committed BEFORE the rebuild, so a failed rebuild costs only the build | cli | rebuild fails loudly, release still in history, a second pull finds nothing to do |
| PULL-23 | A locked git index is RETRIED (3 attempts, 1s apart); a permanently locked one fails loudly and names the recovery. A zero-byte orphan lock is cleared | cli | "attempt 1/3".."2/3", then "stayed LOCKED" |
| PULL-24 | The commit carries the full changelog and both machine-readable trailers | cli | subject shape + `Framework-Update-Range` |
| PULL-30 | The history .dat is a LOG, not state: lying, garbled or absent, the update is unaffected | cli | same revision, same exit, log appended not consulted |
| PULL-33 | A stale window from a previous updater run is ADOPTED and lifted; an OPERATOR's window is left exactly as raised | cli | adoption message; the operator's reason survives, disable never called on it |
| PULL-35 | The update range starts at the RECORDED gitlink, never the checkout: a pull interrupted after the checkout and before the pointer commit is COMPLETED by the next pull, with the whole A..C changelog | cli | one commit, subject `A -> C (2 upstream commits)`, both releases' subjects + bodies, `Framework-Update-Range: A..C`, `from=A to=C`, gitlink at C |
| PULL-36 | The recorded revision missing from a SHALLOW system/ is deepened and the full changelog recovered; a revision upstream cannot supply is reported LOUDLY, never as an empty changelog | cli | full range after deepening; `[CHANGELOG UNAVAILABLE]` naming the sha otherwise, pointer still recorded |
| PULL-34 | The vendored -> submodule CONVERSION records `ignore = dirty` in .gitmodules and COMMITS it, so the framework pointer stays visible while the build's churn does not | cli | ignore=dirty in HEAD:.gitmodules; a dirty submodule absent from status |
| GUARD-01 | The pre-boot index parser agrees with `git rev-parse HEAD:system` at index v2 | php | equal shas |
| GUARD-02 | ...and at index v4, where paths are prefix-compressed and the padding is gone | php | equal shas, fixture proven to be v4 |
| GUARD-03 | The submodule's HEAD is read from files, without spawning git | php | equals `git rev-parse HEAD` |
| GUARD-04 | A stale submodule (record and checkout disagree) is detected | php | both readable, and different |
| GUARD-05 | Unparseable input returns null - "cannot tell" is not failure | php | null for bad signature, unknown version, missing file |
| GUARD-06 | Git's offset varint (the v4 path-strip count) - NOT LEB128 | php | 0, 127, 128 decode correctly; truncated returns null |
| MAINT-* | The pre-boot 503 gate in system/artisan | php | see `php/Framework_Maintenance_Test.php` |
| STUB-* | The framework command stubs never execute (artisan intercepts first) | php | see `php/Framework_Stub_Guard_Test.php` |
