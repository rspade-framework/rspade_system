# rsx-companiond - agent notes

The process manager for companion applications (`rsx:man companion_apps`). **Zero npm
dependencies**, node core only, like `rsx-lockd`: it must start on a box where nothing is
installed, and it carries its own RESP client for that reason. Do not add one.

## File map

| File | Owns | Side effects |
|---|---|---|
| `companiond.js` | `Daemon`: the tick, the flags, descriptors, the epoch, the socket verbs, shutdown | Only inside `main()`, only under `require.main === module` |
| `lib/companion.js` | `Companion`: one companion's start decision (`step()`), spawn, reports, stop sequence, exit handling, code-change policy | Spawns and signals |
| `lib/backoff.js` | The restart delay and the crash streak | **None. Pure.** |
| `lib/wire.js` | HMACs, canonical strings, the scope token, `EXIT_CONTRACT_MISMATCH`. Shared with the SDK | **None. Pure.** |
| `lib/redis_client.js` | Minimal RESP2 client, FIFO correlation, reconnect | Socket |
| `lib/store.js` | Every Redis key the daemon writes, built once; the realtime frames (`publish_events()`, the relay's format, per watching site) | Redis |
| `lib/lease.js` | `single_instance()` election: one rsx-lockd connection per companion, held for its life | Socket to rsx-lockd |
| `lib/app_client.js` | The two daemon-signed calls: `ready/<name>`, `event` | HTTP |
| `lib/output_log.js` | Bounded output queue, the log file, rotation, compression, retention | Files |
| `lib/fingerprint.js` | `source_hash`, Companion_ManifestSupport's recipe, cached by size + mtime | Reads files |
| `lib/children_file.js` | The state file of process groups and the orphan sweep | `/proc`, signals |
| `lib/socket_server.js` | The control socket: hello, framing, stale-socket probe | Binds the socket |
| `lib/config.js` | Everything read from the environment (`REALTIME_ENABLED` once, at start); the child's allowlisted environment; who children run as | Reads `.env` |
| `sdk/rsx-companion-sdk.js` | The companion side: `ready()`, reports, control events, signed `call()` with retry | fds 3 and 4 |
| `companiond-run.sh` | Supervisor entry: wait for the file, then `exec node` | Invoke as `bash companiond-run.sh` |

The wire contract (keys, socket verbs, fd messages, Redis layout) is the man page's; the PHP
twins are `Core/Companion/Companion_Verifier`, `Companion_Store`, `Companion_Daemon_Client`,
`Companion_Flags`.

## Invariants

1. **No timeout but the ones written down.** A companion's own grace (its declaration, capped at
   build) is the only bound on its work. Every other interval here is a poll or a pacing, and
   says so where it is declared. A call to the application that never answers leaves one
   companion WAITING with a reason, harming nothing.
2. **The pipes are always read.** Output goes into a bounded queue; a slow disk drops lines with
   a marker, never blocks a child (`output_log.js`).
3. **Waiting never counts.** `restart_count` moves only when a restart actually spawns.
4. **Readiness gates a START, never a running child.** An outage is ridden out by the SDK.
5. **Every exit kills the child's process group**, so nothing a crashed child started survives
   into its next run. A daemon killed outright leaves SDK children to exit on fd 3's EOF and
   anything else to the next daemon's sweep (pid + start time from `/proc`).
6. **The socket is won before the sweep.** The bind probe is what proves no daemon is alive;
   only then are the recorded groups orphans.
7. **A missing descriptor is not an undeclared companion.** `rsx:clean` removes `build/`; the
   last good copy stays in memory until a build key says the directory is whole again.
8. **A child never sees `APP_KEY`.** `config.js` captures the boot environment before `.env` is
   read, and a child gets an allowlist of it.
9. **Never die from a stray bug**: a daemon crash is a mass stop of every companion.

## Testing

`app/RSpade/tests/companions/php/Companion_Daemon_Process_Test.php` starts its own daemons against
a scratch tree (`--project-root`, `--build-root`, `--host`, `RSX_STORAGE_PATH`, a scratch
`DB_DATABASE` for a private Redis prefix) with a fake application
(`tests/companions/resource/fake_app.js`) and a scriptable child (`fixture_child.js`). The
supervised daemon is never touched by a test. `Companion_Sdk_Wire_Test` compares `wire.js`,
`fingerprint.js`, `backoff.js` and the RESP codec with PHP.
