# logrotate - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| LOGROT-01 | The first rotation creates `.1` and leaves a fresh empty log wearing the original mode | php | one non-empty `app.log` at mode 0640 | `.1` holds the bytes, `app.log` is empty and still 0640 | implemented | 2026-08-31 |
| LOGROT-02 | A second rotation shifts `.1` to `.2` before claiming `.1` | php | two rotations, distinct contents | `.1` = newest, `.2` = previous | implemented | 2026-08-31 |
| LOGROT-03 | The three bands: plain, gzipped, deleted | php | 5 rotations at `days_uncompressed=1`, `days_retention=3` | `.1` plain, `.2.gz`/`.3.gz` compressed and readable, nothing at `.4` | implemented | 2026-08-31 |
| LOGROT-04 | A 0-byte log is not rotated | php | empty `app.log` | `rotated` false, `skipped` = 'empty', no `.1` created | implemented | 2026-08-31 |
| LOGROT-05 | Only top-level `*.log` is touched | php | `app.log`, `notes.txt`, `app.log.old`, `nested/inner.log` | only `app.log` in the report; the other three byte-identical afterwards | implemented | 2026-08-31 |
| LOGROT-06 | The report names every log and carries its six keys | php | one rotating log, one empty log | both keyed; `rotated`/`skipped`/`renumbered`/`shifted`/`compressed`/`deleted` present | implemented | 2026-09-01 |
| LOGROT-07 | Incoherent settings are refused, not coerced | php | `(0,21)`, `(3,0)`, `(21,3)` | RuntimeException naming the setting / the `>=` rule | implemented | 2026-08-31 |
| LOGROT-08 | A missing directory is loud | php | a path that does not exist | RuntimeException containing 'not a directory' | implemented | 2026-08-31 |
| LOGROT-09 | The scheduled task is a no-op while rotation is disabled | php | `rsx.logging.rotation.enabled` false | returns `['skipped' => 'disabled']`; storage/logs listing unchanged | implemented | 2026-08-31 |
| LOGROT-10 | `rsx:logrotate` rotates a named directory and prints one summary line | cli | `--directory`, `--days-uncompressed=1`, `--days-retention=2`, 4 runs | `[OK] Rotated 1 files`; `.1` plain, `.2.gz`, nothing at `.3` | implemented | 2026-08-31 |
| LOGROT-11 | `--json` emits the machine-readable payload | cli | `--json` on a one-log directory | parseable JSON with `rotated`, `directory`, `files['app.log']` | implemented | 2026-08-31 |
| LOGROT-12 | The plain generation survives a gzip that fails midway | php | an unwritable `.gz` target | throws; the plain file is still on disk | planned - needs a filesystem the test user cannot write, which the container does not offer (runs as root) | 2026-08-31 |
| LOGROT-13 | A long-lived open handle keeps writing into `.1` after a rotation | php | fopen, rotate, fwrite, fclose | the bytes land in `.1`, not in the fresh log | planned - documented behavior, low value to pin as a test | 2026-08-31 |
| LOGROT-14 | The schedule registers as `daily at 12:00am` | cli | `rsx:task:process` reconcile | tracker row with next run at the next midnight | deferred - the tasks concern owns scheduler reconciliation; duplicating it here would test Cron_Parser twice | 2026-08-31 |
| LOGROT-15 | A number carrying both a plain and a gz generation is repaired, not refused | php | `.1..5` plain (newer) plus `.4.gz .5.gz .6.gz` (older), rotate (3,21) | no throw; contiguous 1..9, one form each, every original content present exactly once, `renumbered` non-empty | implemented | 2026-09-01 |
| LOGROT-16 | Gaps in the numbering are healed in age order | php | `.1 .3 .7` with descending mtimes | `.3`->`.2`, `.7`->`.3`; contiguous afterwards | implemented | 2026-09-01 |
| LOGROT-17 | The shift moves a compressed generation like a plain one | php | `.1 .2` plain, `.3.gz .4.gz`, `days_uncompressed=2` | `.3.gz`->`.4.gz`, `.4.gz`->`.5.gz`; no renumbering needed | implemented | 2026-09-01 |
| LOGROT-18 | Shrinking days_retention prunes exactly the oldest | php | 10 generations, then rotate with `days_retention=5` | the five newest runs survive, by content, newest first | implemented | 2026-09-01 |
| LOGROT-19 | Rotating twice with nothing new written is stable | php | rotate, then rotate again | second pass `skipped` = 'empty'; layout byte-identical and still contiguous | implemented | 2026-09-01 |
