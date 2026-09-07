# db_cache - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| DBC-01 | Nothing backed up and nothing destroyed plans no restore | php | state (0,0,0,0) | plan is `[delete_live_dump]` | implemented | 2026-08-24 |
| DBC-02 | A completed dump with nothing destroyed only discards the dump | php | state (1,0,0,0) | plan is `[delete_live_dump]` | implemented | 2026-08-24 |
| DBC-03 | A blob store moved aside is moved back | php | state (1,1,0,1) | clear, move back, delete dump | implemented | 2026-08-24 |
| DBC-04 | Full build state plans the complete restore in reverse order | php | state (1,1,1,1) | recreate, restore, clear, move back, delete dump | implemented | 2026-08-24 |
| DBC-05 | The live dump is released LAST, exactly once, for every state | php | all 16 states | `delete_live_dump` is last and unique | implemented | 2026-08-24 |
| DBC-06 | A missing dump never yields a restore action | php | state (0,1,1,1) | recreate without restore | implemented | 2026-08-24 |
| DBC-07 | A clean tree takes both backups | php | (no dump, no blob backup, store present) | take dump + move store | implemented | 2026-08-24 |
| DBC-08 | Existing backups are never overwritten | php | (dump, blob backup, store) | take neither; reuse flagged | implemented | 2026-08-24 |
| DBC-09 | Only the missing blob half is taken | php | (dump, no blob backup, store) | move store only | implemented | 2026-08-24 |
| DBC-10 | Only the missing dump half is taken; residue is not moved over the backup | php | (no dump, blob backup, store) | take dump only | implemented | 2026-08-24 |
| DBC-11 | No blob store on disk moves nothing, and is not a failure | php | (no dump, no backup, no store) | take dump only | implemented | 2026-08-24 |
| DBC-12 | An empty database restores the cache and applies nothing further | cli | test DB dumped to a sandbox cache, then dropped | restore fires; tables/migrations/users round-trip; no `Migrating:` | implemented | 2026-08-24 |
| DBC-12b | The restore is a PRE-MIGRATE step: both normalization passes appear AFTER the restore lines, so the ordinary run continued underneath it | cli | same run as DBC-12 | `Post-migration normalization` occurs after `Cached schema restored` | implemented | 2026-08-24 |
| DBC-13 | A non-empty database never restores the cache | cli | migrated test DB + a present cache | no restore line | implemented | 2026-08-24 |
| DBC-14 | A missing cache is silent, not an error | cli | empty sandbox cache dir | exit 0, no restore line | implemented | 2026-08-24 |
| DBC-14b | The mysqlpv pipe segment is optional and well-formed: a pipe stage invoking python3 on bin/mysqlpv in -l mode, or empty on a box with no interpreter | php | `mysqlpv_pipe_segment()` | ` \| python3 <path> -l` or `''` | implemented | 2026-08-24 |
| DBC-15 | The command refuses outside development mode, and the refusal touches nothing (no maintenance window, no marker, no blob backup, no database change) | cli | subprocess with `RSX_MODE=debug`; this box stays in development | exit 1 naming DEVELOPMENT and the mode it ran in; flag/marker/backup absent; table + migration counts unchanged | implemented | 2026-08-24 |
| DBC-16 | A real end-to-end build (backup, wipe, migrate, dump, restore) against a scratch database | cli | a disposable database + blob root | live data byte-identical afterwards | deferred - the command targets the DEFAULT connection and enters the real maintenance window; needs a target-database seam first | 2026-08-24 |
