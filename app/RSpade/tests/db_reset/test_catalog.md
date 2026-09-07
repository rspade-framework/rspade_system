# db_reset - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| DBR-01 | No flags refuses and asks for `--yes` | php | `refusal_reason(false, false, false)` | `REFUSAL_UNCONFIRMED` | implemented | 2026-08-28 |
| DBR-02 | `--force` alone is not a confirmation | php | `refusal_reason(false, true, false)` | `REFUSAL_UNCONFIRMED` | implemented | 2026-08-28 |
| DBR-03 | `--yes` alone proceeds on an unsealed box | php | `refusal_reason(true, false, false)` | `null` | implemented | 2026-08-28 |
| DBR-04 | A sealed build needs `--force` as well | php | `refusal_reason(true, false, true)` | `REFUSAL_SEALED` | implemented | 2026-08-28 |
| DBR-05 | Sealed with no flags reports the SEALED refusal, so one refusal names both flags | php | `refusal_reason(false, false, true)` | `REFUSAL_SEALED` | implemented | 2026-08-28 |
| DBR-06 | Both flags proceed under a seal | php | `refusal_reason(true, true, true)` | `null` | implemented | 2026-08-28 |
| DBR-07 | The refusal states every consequence the CR requires: tables dropped, business records and user accounts and logins, files deleted, actual uploaded bytes, no undo, backup taken beforehand, and the exact flag | php | `refusal_text(UNCONFIRMED, ...)` | all clauses present | implemented | 2026-08-28 |
| DBR-08 | The refusal names the database that would be dropped | php | `refusal_text(UNCONFIRMED, 'some_application_db', ...)` | database named | implemented | 2026-08-28 |
| DBR-09 | The sealed refusal repeats the whole consequence text and adds the production statement plus the two-flag invocation | php | `refusal_text(SEALED, ...)` | `SEALED PRODUCTION BUILD`, `--yes --force` | implemented | 2026-08-28 |
| DBR-10 | The first line is the headline, so a caller can style it as the error | php | `refusal_text(...)[0]` | `[ERROR] ... Nothing has been touched` | implemented | 2026-08-28 |
| DBR-11 | The announcement reports the blast radius: tables, files, bytes, and the fresh-install landing | php | `announcement(62, 1743, 10485760, true)` | counts + `migrated to the fresh-install state` | implemented | 2026-08-28 |
| DBR-12 | `--no-migrate` never claims the fresh-install landing | php | `announcement(0, 0, 0, false)` | `schema left dropped (--no-migrate)` | implemented | 2026-08-28 |
| DBR-13 | The announcement is singular for one | php | `announcement(1, 1, 1, true)` | `1 table,` / `1 file ` | implemented | 2026-08-28 |
| DBR-14 | A dev-container reset is snapshot-protected | php | probes (dev, container, dev container, local host) | engaged, no skipped reasons | implemented | 2026-08-28 |
| DBR-15 | A production-target container runs bare and names the condition | php | probes with `/.rspade_container_dev` absent | not engaged; reason names mysql-client only | implemented | 2026-08-28 |
| DBR-16 | An external database host runs bare and names the host | php | probes with host `db.internal.example` | not engaged; host named | implemented | 2026-08-28 |
| DBR-17 | A production-mode reset runs bare and names the mode - the operator is never left assuming an undo | php | probes with development false | not engaged; `not development` | implemented | 2026-08-28 |
| DBR-18 | The reset never classifies itself as a `--framework-only` run (it declares no such option) | php | probes (dev container) | no framework-only reason | implemented | 2026-08-28 |
| DBR-19 | `clear_directory_contents()` empties a populated root, keeps the directory, and reports files + bytes (dotfiles and nested files included) | php | sandbox with 6 files / 21 bytes / 2 levels / 1 dotfile | dir survives, empty, `{files:6, bytes:21}` | implemented | 2026-08-28 |
| DBR-20 | The root's MODE survives the wipe | php | sandbox chmod 0770 | `fileperms()` unchanged | implemented | 2026-08-28 |
| DBR-21 | A root that never existed is created empty and counts nothing | php | missing path | dir created, zero counts | implemented | 2026-08-28 |
| DBR-22 | Measuring is separable from destroying (the reset counts before it drops) | php | populated sandbox | same totals, nothing removed | implemented | 2026-08-28 |
| DBR-23 | No flags: the real command refuses, exits 1, prints every consequence and the flag, raises no maintenance window, and leaves the table count unchanged | cli | subprocess, no flags, child pointed at the test database | exit 1; all clauses; maintenance down; counts equal | implemented | 2026-08-28 |
| DBR-24 | `--force` alone refuses identically and destroys nothing | cli | subprocess `--force` | exit 1; names `--yes`; counts equal | implemented | 2026-08-28 |
| DBR-25 | The refusal is written to STDOUT and stderr stays empty | cli | subprocess with the streams kept apart | exit 1; stderr empty; stdout carries the text | implemented | 2026-08-28 |
| DBR-26 | A real end-to-end reset (snapshot, drop, wipe, migrate, announce) | cli | a disposable database + file roots | fresh-install state, counts announced | deferred - the command targets the DEFAULT connection and enters the real maintenance window; needs a target-database seam first, the same one DBC-16 needs | 2026-08-28 |
| DBR-27 | An interrupted reset (SIGINT mid-wipe) rolls the database back through the same catch | cli | a disposable database | rollback performed, flag cleared | deferred - blocked on DBR-26's seam; the signal handler is the same shape as `rsx:db:rebuild_provision_cache_snapshot`'s | 2026-08-28 |
