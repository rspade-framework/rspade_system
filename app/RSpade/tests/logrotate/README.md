# logrotate

The framework's own rotation of `storage/logs`: generations, compression, retention,
the nightly task that drives it, and the on-demand command.

## Domain

Nothing in RSpade assumes an OS `logrotate`. Laravel's `daily` channel rotates only the
dated files Monolog itself writes - it never compresses, never prunes anything it did not
create, and never sees `csp_violations.log` or `ide-formatter.log`. So the framework
rotates its own log directory.

`Rsx_Logrotate::rotate($directory, $days_uncompressed, $days_retention)` is pure and
static. It takes a directory and two numbers, touches only `*.log` files at the TOP level
of that directory, and returns a per-file report. It reads no config and resolves no
paths; the task and the command supply the arguments.

The generations are logrotate-style numeric suffixes:

```
name.log -> name.log.1 -> name.log.2 -> ... -> name.log.N[.gz]

  1 .. days_uncompressed                    plain
  days_uncompressed+1 .. days_retention     gzipped (.gz)
  past days_retention                       deleted
```

Because the scheduled sweep runs once a day, a generation number is a day count.

A NUMBER IS ONE SLOT whatever the form: the shift moves `.N.gz` to `.N+1.gz` exactly as it
moves `.N` to `.N+1`. And before shifting, the generations on disk are RENUMBERED
contiguously from 1 in age order (mtime descending, plain before gz on a tie, then the
existing number), through temporary names so no rename can clobber a file. That repair is
not a courtesy for hand-edited directories - the rotation itself produces the states it
fixes: raising `days_uncompressed` between runs leaves a plain generation on a number an
older gz chain already holds, and an interrupted run leaves gaps.

Invariants the tests exist to hold:

- **Rename-based, never copytruncate.** The current log is RENAMED to `.1` and a fresh
  empty `name.log` is created wearing the ORIGINAL FILE'S MODE, so every per-call appender
  keeps writing. A long-lived process holding an open handle keeps writing into the renamed
  `.1` through its inode until it reopens - benign, and the same thing OS logrotate does
  without a postrotate reopen.
- **Top-level `*.log` only.** No recursion, no other extension. `notes.txt`,
  `app.log.old` and `nested/inner.log` are invisible.
- **A 0-byte log is not rotated.** It has nothing worth a generation, and rotating it
  would push a real generation one step closer to deletion for nothing.
- **Failures are loud.** A failed rename, a failed gzip, a missing directory - each throws
  naming the path. Nothing is suppressed with `@`. A numbering the rotation itself can
  produce - a gap, or one number carrying both forms - is NOT a failure and is repaired,
  never refused: both files are real generations and deleting either loses log data.
- **No generation is lost by the repair.** The renumbering moves files and nothing else;
  the tests assert by CONTENT, gunzipping as needed, that every generation survives exactly
  once.
- **A .gz carries its source's modification time**, because the repair reads mtime as the
  generation's age - stamped at compression time it would make an old generation look
  newer than the plain ones in front of it.
- **The plain file survives a failed compression.** It is unlinked only after the `.gz`
  exists and is non-empty.
- **The settings are asserted, not coerced.** Non-positive numbers, or a
  `days_retention` below `days_uncompressed`, are `shouldnt_happen`.
- **The task's config gate is silent.** `rsx.logging.rotation.enabled` false means the
  scheduled sweep returns without touching anything; the COMMAND runs regardless (an
  explicit invocation is its own consent) and says so in one line.

## Source under test

- `system/app/RSpade/Core/Logging/Rsx_Logrotate.php`
- `system/app/RSpade/Core/Logging/Log_Maintenance_Service.php`
- `system/app/RSpade/Commands/Rsx/Log_Rotate_Command.php`
- `system/config/rsx.php` -> `rsx.logging.rotation`

## Man page

`php artisan rsx:man logrotate`

## Testable surface

| Surface | Type | Notes |
|---|---|---|
| Generation shift, fresh log, mode preservation | php | fixture directory under `storage/rsx-tmp/test-logrotate` |
| Renumbering repair: a shared slot, and gaps | php | mtimes set with `touch()`; asserted by content |
| The shift moves compressed generations too | php | `.N.gz` -> `.N+1.gz` |
| A retention shrink prunes the oldest | php | 10 generations, then `days_retention=5` |
| Rotating twice with nothing new is stable | php | the second pass hits the 0-byte skip |
| Compression band and retention band | php | `days_uncompressed=1`, `days_retention=3` |
| 0-byte log skip | php | |
| Scope: extension, recursion, non-log files | php | |
| Report shape | php | |
| Setting validation | php | `shouldnt_happen` -> RuntimeException |
| Task config gate (disabled = no-op) | php | only the DISABLED path; the enabled path would rotate the real logs |
| `rsx:logrotate` summary line and `--json` | cli | `--directory` keeps the real logs out of the blast radius |
| Schedule registration (`daily at 12:00am`) | - | covered by the tasks concern's scheduler tests; not duplicated here |

**The real `storage/logs` is never rotated by a test.** Every fixture lives under
`storage/rsx-tmp/test-logrotate/`, and the one test that names the real directory only
reads a listing of it to prove the disabled task left it alone.
