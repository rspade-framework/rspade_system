<!-- single-source: never duplicate into another fragment. -->

## ENGINEERING MANDATES

### NEVER add a timeout

**Do not put a timeout in code. Not one. Not ever — unless the user ASKED for it, or you PROPOSED one and they explicitly approved it.** No `setTimeout()`, no `--timeout` flag, no `max_execution_time`, no lock lease or TTL, no watchdog deadline, no "safety" cap on how long a loop, copy, query or wait may run. This is a data-integrity rule: **a timeout converts a working operation into a failed one at the worst possible moment, and hands that failure to code that never expected it** - the `cp -r` that does not finish, followed by a line that deletes the source directory. It has bitten this framework twice (narratives in skills `rspade:migrations` and `rspade:locks-and-subprocesses`).

```php
$process->setTimeout(60);    // [NO] a large copy now "exceeds the timeout" and FAILS
$process->setTimeout(null);  // [OK] the operation takes as long as the data requires
```

**THE 599/601 TEST — apply it to every timeout, existing or proposed**: why is 599 seconds acceptable here but 601 seconds is not? If you cannot give a reason grounded in what the operation actually does — not "it felt generous", not "just in case" — **the number is arbitrary and the timeout must not exist.** "Give it room" is self-refuting (it concedes slowness is normal here, the argument for no cap at all); a copied number is always arbitrary.

**A sanctioned timeout is WRITTEN DOWN where it lives** - house example `rsx.javascript.rpc_server_ready_wait_ms` in `system/config/rsx.php`: it names the mandate, the external party it waits on, caps the READINESS WAIT and never the work, and expiry degrades to a loud evidence-carrying error. **Meet that bar or remove the timeout.** **Slowness is normal and is never evidence of a hang**; a genuine hang is a fault to SEE and diagnose. **The one narrow legitimate case is still a PROPOSAL, never your decision**: bounding a wait on an EXTERNAL party that may never answer, where expiry degrades to a working outcome - say what, why, and what happens on expiry, then wait. **Bounding your own work is never legitimate.** **Your own tool calls follow the same rule**: the only timeout you set unprompted is the standard ~2-minute backgrounding of a long-running command; never shorten it to "check on" something, never cap an operation you were told to run to completion.

### Fail loud — no silent fallbacks

**ALWAYS fail visibly.** No fallback systems, no silent failures, no alternative code paths. **NO BLANKET TRY/CATCH** — let exceptions bubble to the global handler and catch only expected failures (file uploads, outbound API calls, user input). An exception handler **formats** an error; it never **wraps** one and never substitutes a value, and you **never continue after a security failure**. `shouldnt_happen("msg")` is the impossible-condition assertion in both languages — for broken assumptions, never for expected input. Skill `rspade:ajax-error-handling`.

### Silent success — unix philosophy

"No news is good news" — silent when working, speak for problems and primary results.

### Do the whole job

**A function does exactly what its name says, in its entirety, and nothing else** — `get_sessions_for_user()` returns ALL of a user's sessions. Never process a partial set when asked for a full set; memory never wins by dropping data — ITERATE, never truncate: a function whose job is "these records" returns `->result_set()`. **A bare `LIMIT` that silently truncates is the bug** — right only when it IS the mechanism or the caller asked for N; otherwise say why at the call site. Scope is DATABASE result sets. Skill `rspade:bounded-result-sets`.

### No defensive coding for core classes

Core framework classes ALWAYS exist (`Manifest`, `Rsx_Storage`, `Rsx`, everything in `Core/Js/`) — never check existence (no `if (typeof Rsx !== 'undefined')`, no `class_exists()`). The build system guarantees it.

### Always bash — never sh, never an implicit shell

**bash is the ONLY shell this project's tooling invokes, and it is invoked EXPLICITLY**: scripts run with a `bash ` prefix, never the exec bit (except a `.git/hooks/<name>` file, which git execs directly); never `sh`, never `sh -c`. PHP `shell_exec()`/`passthru()`/string `proc_open()` and node `child_process.exec()` all run `/bin/sh` = **dash** here, which once killed every helper spawned under a held lock (`sh: 1: exec: 11: not found`). Skill `rspade:shell-invocation`.
