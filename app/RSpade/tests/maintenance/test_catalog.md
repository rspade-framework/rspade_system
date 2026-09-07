# Test catalog: maintenance mode

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| M-01 | Maintenance grants a CLUSTER lock as a NO-OP that leaves nothing behind. REVISED 2026-08-11 (owner ruling): this row previously pinned the flock degradation, which bought exclusion against a peer maintenance had already removed and cost real php-fpm contention | php | `named_write_lock('rsxtest noop/name')` under forced maintenance | token prefixed `maint:`; NO file at `storage/flock/cluster__rsxtest_noop_name.lock` | implemented | 2026-08-11 |
| M-02 | THE CONTENTION THAT MOTIVATED THE CHANGE: another process is NOT blocked by a cluster lock under maintenance. REVISED 2026-08-11 - the same input returned `BLOCKED` under the flock degradation, which is what was costing php-fpm during rsx:debug | php | held cluster lock + a spawned `php -r` doing `flock(LOCK_EX\|LOCK_NB)` | `GOT` while held | implemented | 2026-08-11 |
| M-03 | Nesting is reentrant and unwinds one level per release (client-side, never involved a backend) | php | write lock twice, same name | same token both times; inner release reports held, outer ends it | implemented | 2026-08-11 |
| M-04 | `upgrade_lock` on a no-op READ mints the WRITE identity and carries the reentrancy count (there is no server token to upgrade and no daemon to ask); on a WRITE it is a trivial hit returning the same token | php | read lock then `upgrade_lock`; write lock then `upgrade_lock` | a NEW `maint:` token, one release ends it; same token for the WRITE case | implemented | 2026-08-11 |
| M-05 | Release frees the lock for reacquisition | php | acquire, release, acquire | second acquisition mints a new token | implemented | 2026-08-05 |
| M-06 | The canonical timeout message survives where a wait is still possible - a SYSTEM lock. REVISED 2026-08-11: a cluster lock can no longer time out under maintenance (nothing is held, so nothing waits), but the wording stays load-bearing for the updater's retry classification | php | file held directly + `system_lock(timeout 1)` | throws matching `Failed to acquire.*lock` | implemented | 2026-08-05 |
| M-07 | Semaphores go unlimited under maintenance (sentinel, no gating) | php | `acquire_semaphore(name, 1)` twice | both return `sem-unlimited-*`; usage 0 | implemented | 2026-08-05 |
| M-08 | RsxCache reads miss silently and writes are dropped with correct types | php | seeded key, then forced maintenance | `get` -> default, `exists` false, `set/delete` false, `increment` 0, seed survives | implemented | 2026-08-05 |
| M-09 | Realtime reports an EMPTY subscriber registry under maintenance | php | seeded `rsx_rt:subs` member | non-empty normally, `[]` under maintenance | implemented | 2026-08-05 |
| M-10 | `publish()` returns quietly under maintenance (never throws into `migrate`) | php | forced maintenance + publish | no exception | implemented | 2026-08-05 |
| M-11 | `Rsx_Internal_Flags` set/has/all/clear | php | set, re-set, clear | idempotent membership | implemented | 2026-08-05 |
| M-12 | An unknown `--_` token is stripped pre-boot (no unknown-option error) | php (subprocess) | `artisan --version --_rsxtest-nonexistent-flag` | exit 0, no option error | implemented | 2026-08-05 |
| M-13 | No `--_` flag renders in any help output | php (subprocess) | `list`, `help rsx:clean`, `help rsx:manifest:build` | output contains no `--_` | implemented | 2026-08-05 |
| M-14 | enable/disable round trip writes + clears the flag, reason = flag content, both idempotent | cli | `rsx:maintenance:enable --no-services --reason=...` | flag present with reason; gone after disable | implemented | 2026-08-05 |
| M-15 | Automated task runners are BLOCKED and told how to exit | cli | `rsx:task:process`, `rsx:task:worker` | exit non-zero; output names the reason + `rsx:maintenance:disable` | implemented | 2026-08-05 |
| M-16 | `rsx:task:run` is refused without `--force`, allowed with it | cli | both invocations | refusal names `--force`; forced run reaches the task | implemented | 2026-08-05 |
| M-17 | Ordinary commands are ALLOWED (allow-most) | cli | `--version`, `migrate:status`, `rsx:health` | exit 0; and none carries the gate's refusal (its exact message, its hint, exit 75) | implemented | 2026-08-10 |
| M-18 | The internal override bypasses the classification | cli | `rsx:task:process --once --_framework-update-override` | runs normally | implemented | 2026-08-05 |
| M-19 | The maintenance script rejects an unknown action | cli | `maintenance-mode.sh bogus-action` | exit non-zero, `[ERROR]` | implemented | 2026-08-05 |
| M-20 | Web answers 503 quoting the reason | cli (curl) | `curl http://localhost/` while up | HTTP 503, body contains the reason | implemented (skips without a local server) | 2026-08-05 |
| M-21 | The four supervisord units really stop and restart | http/manual | `rsx:maintenance:enable` then `disable` on a box with supervisord | units STOPPED then RUNNING; web 200 after disable | deferred (needs an isolated supervisor; covered by the documented end-to-end smoke) | 2026-08-05 |
| M-22 | A straggler process (booted pre-enable) degrades instead of fatalling | php | requires stopping redis mid-process | flock backend / cache bypass engage | deferred (cannot stop redis from inside a test run) | 2026-08-05 |
| M-23 | A full `rsx:framework:pull` inside a real window (services stopped) | cli | fixture pull with service control | update completes with redis down | deferred (fixtures run `--no-service-control`; needs an isolated supervisor) | 2026-08-05 |
| M-24 | The build-tail environment-update trigger runs in development with no window up | php | `Manifest_Build_Command::__should_run_environment_updates()`, mode seam + `$force_active_for_tests=false` | true | implemented | 2026-08-05 |
| M-25 | A SEALED build (debug or production) never runs the environment updates | php | same seam at `MODE_DEBUG` / `MODE_PRODUCTION` | false for both | implemented | 2026-08-05 |
| M-26 | Maintenance suppresses the trigger even in development (the pull's exactly-once guarantee) | php | dev mode + `$force_active_for_tests=true` (and a sealed+window combination) | false | implemented | 2026-08-05 |
| M-27 | The mode/maintenance seams restore to the real environment after use | php | `clear_mode_cache()` + null the force flag | flag null, mode development | implemented | 2026-08-05 |
| M-28 | SYSTEM locks are UNTOUCHED by maintenance - still flock, still a real file, still exclusive against another process - and the two domains stay distinct identities even though only one of them locks anything | php | `system_lock('x')` + `named_write_lock('x')` under forced maintenance | two tokens; `flock/system__x.lock` and `flock/cluster__x.lock` both exist | implemented | 2026-08-10 |
| M-29 | `force_clear_lock` on a cluster lock under maintenance is INERT - it must not go looking for (or create) the lock file a no-op grant never made | php | held no-op lock + `force_clear_lock(CLUSTER_LOCK, name)` under forced maintenance | no file created; our own bookkeeping untouched, so release still reports held | implemented | 2026-08-11 |
| M-30 | The RPC helper quiesce is IN the enable sequence and in the right place: inside the services half, after the task kill, before the first supervisor stop | cli | positions of the four markers in `bin/maintenance-mode.sh` `do_enable()` | quiesce pgrep appears after `[ "$SERVICES" = true ]` and `rsx:tasks:kill-all`, before `stop_unit '^realtime'` | implemented | 2026-08-13 |
| M-31 | The reaper escalates rather than hoping: TERM every match, one settle pass, KILL a survivor, and report the count | cli | the extracted block's text | contains `xargs -r kill`, `kill -0`, `kill -9`, and a `say` | implemented | 2026-08-13 |
| M-32 | THE BEHAVIOR: the block's own lines (extracted from the script, never retyped) reap real daemons matched only by the socket path in their argv - including a WEDGED one that ignores SIGTERM, which is what makes the KILL pass load-bearing | cli | two node processes with `--socket=<scratch>/rsx-tmp/fake.sock`, one cooperative + one trapping SIGTERM; script's block run with `storage_base()` pointed at the scratch root | "Quiesced 2 node RPC helper daemon(s)"; both processes ended by TERM/KILL; pgrep count 0 | implemented | 2026-08-13 |
| M-33 | Laravel's `down`/`up` are RESTRICTED stubs, not merely hidden: each exits 1 and names its `rsx:maintenance:*` replacement, and neither appears in the command listing | cli | `Artisan::all()`; `Artisan::call('down'/'up')`; `list --raw` | Down_Command / Up_Command; exit 1 naming the replacement; absent from the listing | implemented | 2026-08-23 |
| M-34 | THE POINT: the Laravel mechanism is gone, so a refused `down` cannot leave a convincing no-op behind - nothing writes `storage/framework/maintenance.php`, and nothing reads it (the middleware and the index.php pre-render check were deleted) | cli | `down` then `up` in-process | `storage/framework/maintenance.php` absent before and after | implemented | 2026-08-23 |
| M-35 | The schema-cache guard: a live `db_cache` dump on disk refuses `disable`, naming the backup and `rsx:db:rebuild_provision_cache_snapshot`, and leaves the window up | cli | flag raised; `live_db.sql.gz` + marker present | exit 1, refusal text, flag still on disk | implemented | 2026-08-24 |
| M-36 | The blob half alone also refuses, and is located through the marker's blob-root line (the only way a pre-boot script can know where the store is) | cli | flag raised; `<blob_root>_tmp` + marker present | exit 1 naming the blob backup | implemented | 2026-08-24 |
| M-37 | A marker with NO backups beside it does NOT refuse - the state step 7 passes through, which is why the command needs no override token | cli | flag raised; marker only | exit 0, flag cleared | implemented | 2026-08-24 |
| M-38 | `--force` overrides the schema-cache refusal, exactly as it does the merge-conflict one | cli | flag raised; dump + marker; `--force` | exit 0, flag cleared | implemented | 2026-08-24 |
| M-39 | With no marker and no backups the ordinary disable is completely unaffected | cli | flag raised only | exit 0, flag cleared | implemented | 2026-08-24 |

Related coverage living in other concerns:

- `framework_update/php/Framework_Maintenance_Test.php` - flag path, raise/clear, the snapshot
  vs on-disk distinction, reason content, the `$force_active_for_tests` seam, and the real
  subprocess gate.
- `migrate/php/Migrate_Status_Notice_Count_Test.php` - what the pending-migration notice counts
  (vendor migrations never counted; framework + app migrations both seen).
- `db_cache/` - the schema cache whose interrupted build M-35..M-39 keep the window closed over.

The build-tail environment-update INVOCATION itself (lock, passthru, non-fatal warning) is not
unit-testable in-process - it shells out to `bin/post-update.sh`, whose scripts mutate the real
environment. It is covered by the M-24..M-27 decision seam plus live verification: a build with
`git config core.fileMode` deliberately set to `true` flips it back to `false` (post-update.sh's
own universal step), and the same probe under a raised window leaves it untouched. The
`050_post_commit_env_update.sh` installer is verified by a scratch-fixture matrix (install /
idempotent-silent / commit-fires-a-detached-stub / foreign-hook-skipped / monorepo no-op) run
against a throwaway git repo with a stub `post-update.sh`; it is not a standing test because a
real one would have to write into `.git/hooks`.
