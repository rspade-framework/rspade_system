# The Node Service - RSpade's one node RPC daemon

## Problem Statement

RSpade's build needs a JavaScript toolchain for eight different jobs: parsing ~1,200+ JS
files for the manifest, running Babel over every one of them, compiling SCSS, compiling
jqhtml templates, concatenating bundles and merging their sourcemaps, minifying for
production, and sanitizing + linting for `rsx:check`. The original implementation spawned a
new Node.js process for each FILE:

```php
foreach ($js_files as $file) {
    $process = new Process(['node', 'js-parser.js', $file]);
    $process->run();
    // ... parse output
}
```

**Performance impact:**
- 1,200+ process spawns per clean build
- Each spawn: ~50-150ms overhead
- Total overhead: 60-180 seconds just for process management
- Plus Node.js interpreter startup time per file

That was solved by giving each concern a long-running daemon on its own unix socket. Eight
daemons later, the remaining cost was the daemons themselves.

## Solution: ONE long-running service

There is exactly one node daemon per PHP process: **`resource/node-service.js`**, on
`storage/rsx-tmp/node-service-<random>.sock`, owned by **`Rsx_Node_Service`**. Every subsystem
is a module it loads ON FIRST USE.

**Why one and not eight** (the 2026-09-04 consolidation):

- Compiles are **strictly sequential**. There is only ever one compiling /
  manifest-rebuilding process at a time, so eight processes bought no parallelism.
- On CLI they were pure waste. Symfony's `Process` destructor calls `stop(0)` at exit, so a
  single `rsx:check` or `rsx:bundle:compile` **started and then immediately killed up to
  eight node processes**.
- Eight idle V8 heaps cost real memory for nothing.
- Eight sockets meant eight ways to leave an orphan behind, and eight things for
  `rsx:clean` and the maintenance window to reap.

**What did NOT change**: the spawn is still on demand and still ATTACHED (a Symfony
`Process`, not a detached daemon), the socket still lives under `storage/rsx-tmp/`, and a
fully-cached build still never starts node at all.

**Why the socket name is random** (the 2026-09-04 private-socket redesign): see
[The socket is private to this process](#the-socket-is-private-to-this-process) below. The
short form is that a shared well-known path lets one process reap the daemon another is
mid-request on.

## Architecture

```
   PHP client                        node service                    subsystem module
   (marshaling + cache)              (dispatch only)                 (the actual work)

   Concatenator ─┐                                             ┌─ Core/Bundle/resource/concat-service.js
   Minifier ─────┤                                             ├─ Core/Bundle/resource/minify-service.js
   Js_Parser ────┤   Rsx_Node_Service::request(                ├─ Core/JsParsers/resource/parser-service.js
   Js_Transformer┼──▶   'prefix.method', $payload  ) ──socket──▶ Core/JsParsers/resource/babel-service.js
   Scss_Compiler ┤                                             ├─ Integrations/Scss/resource/scss-service.js
   Jqhtml…Compiler                                             ├─ Integrations/Jqhtml/resource/jqhtml-service.js
   FileSanitizer ┤                                             ├─ CodeQuality/Support/resource/sanitize-service.js
   Js_CodeQuality│                                             └─ CodeQuality/Support/resource/quality-service.js
```

A subsystem module lives **next to its PHP client**, not next to the entry script: the
handlers and the code that calls them are one feature and stay together.

### The registry

`resource/node-service-modules.json` is the ONE list both sides read:

- node dispatches `<prefix>.<method>` by loading the module the registry names;
- `Rsx_Node_Service::module_paths()` reads the same map, so PHP can say what is registered.

They cannot drift, because there is no second list.

### Subsystem roster

| Prefix | Module | PHP client | Methods |
|---|---|---|---|
| `parser` | `Core/JsParsers/resource/parser-service.js` | `Js_Parser` | `parse` |
| `babel` | `Core/JsParsers/resource/babel-service.js` | `Js_Transformer` | `transform` |
| `minify` | `Core/Bundle/resource/minify-service.js` | `Minifier` | `minify` (js and css) |
| `concat` | `Core/Bundle/resource/concat-service.js` | `Concatenator` | `concat` (js and css) |
| `jqhtml` | `Integrations/Jqhtml/resource/jqhtml-service.js` | `JqhtmlWebpackCompiler` | `compile` |
| `sanitize` | `CodeQuality/Support/resource/sanitize-service.js` | `FileSanitizer` | `sanitize` |
| `quality` | `CodeQuality/Support/resource/quality-service.js` | `Js_CodeQuality_Rpc` | `lint`, `analyze_this` |
| `scss` | `Integrations/Scss/resource/scss-service.js` | `Scss_Compiler` | `compile` |

### Communication protocol

Line-delimited JSON over a unix domain socket. No port conflicts, no network stack.

**Request:**
```json
{"id": 1, "method": "parser.parse", "files": ["path1.js", "path2.js"]}
```

**Response** - the subsystem's own shape, beside the id:
```json
{"id": 1, "results": {"path1.js": {...}, "path2.js": {...}}}
```

**Top-level methods** (the service's own, not a subsystem's):
- `ping` -> `{id, result: "pong"}` - health check, and it must stay CHEAP
- `introspect` -> `{id, result: {pid, socket, loaded[], registered[]}}` - which subsystems
  this process has actually loaded; the lazy-loading proof and the future health-probe hook
- `shutdown` -> `{id, result: "shutting down"}` - unlink the socket and exit

### LAZY LOADING IS MANDATORY

A subsystem module is `import`ed on FIRST USE and never before. The toolchains are enormous:
babel, sass, terser, postcss and `@jqhtml/parser` each cost real time and real memory to
load. **A ping must not load any of them, and a concat-only session must never load sass.**
The dispatch registry holds PATHS, not modules; `introspect` is how that is asserted
(`Rpc_Lifecycle_Test`).

A module may be CommonJS or an ES module - `jqhtml-service.js` is ESM because
`@jqhtml/parser` is - because every one is reached through dynamic `import()`. Its handler
table is `module.exports` (CJS) or the DEFAULT export (ESM).

## Service lifecycle

The lifecycle is written ONCE, in `Rsx_Node_Service`. No client owns any part of it.

### 1. Lazy start

`Rsx_Node_Service::request()` calls `ensure()` itself, so a client just makes its request.
A cache hit never reaches `request()`, and therefore never starts node.

### 2. What ensure() does

1. **Mint the socket name**, once per PHP process: `node-service-<random>.sock`. There is no
   reuse branch and nothing to collect first - a name nobody else knows cannot already be
   held.
2. **Spawn**, wrapped in `RsxLocks::command_without_inherited_locks()` so no flock descriptor
   is inherited by a process that may idle for hours.
3. **Ready-wait**: ping every 25ms up to `rsx.javascript.rpc_server_ready_wait_ms` (20s).
   The process handle is published ONLY after the service answers.
4. **On expiry**: build a `Rpc_Startup_Diagnostics::failure_message()` from what was
   OBSERVED, stop what was spawned, leave no handle and no socket behind, and throw.

### 3. The socket is private to this process

The random component in the name is the design. Nobody else can learn it, so nobody else can
kill, rebind or reuse this daemon - which removes a race a shared well-known socket has by
construction: process B's `ensure()` reaping the daemon process A is mid-request on, and two
cold starts fighting over one path.

**There is no freshness check, because freshness is not in question.** A daemon is always
spawned from the source and toolchain on disk at spawn time, by its own parent, and its
lifetime is a subset of that parent's. Nothing is ever inherited, so edited source, a fresh
framework pull and a bumped npm library are all picked up simply because the next process
spawns from current disk.

The socket still lives under `storage/rsx-tmp/` deliberately: `quiesce_all()`, `rsx:clean` and
`bin/maintenance-mode.sh` sweep that DIRECTORY by argv match (`--socket=<dir>/`), so every
daemon is reachable by the operational sweeps without any of them knowing a name. **Several
`node-service-*.sock` files at once is normal** - it means several PHP processes are live.

### 4. Idle exit (orphan insurance), and the respawn that hides it

The daemon exits cleanly - close the server, unlink the socket, exit 0 - after **120 idle
seconds**. Idle means: no open connection, nothing in flight, and nothing completed within the
window.

It exists for one case: a daemon whose parent PHP process was SIGKILLed. Its socket name was
private to that dead parent, so nothing will ever speak to it again and nothing but itself can
end it. Expiry degrades to nothing at all.

**It must never fire during a long-running request, and it cannot.** A three-minute sass
compile with no other traffic is WORKING, not idle - killing it would be exactly the failure
the no-timeout mandate exists to prevent. The counters are raised the moment a request line
starts arriving and lowered only when its response has been produced, and a connected client
counts as activity on its own, so the window is armed only when there is genuinely nothing
happening. `--idle-exit-ms=<n>` on argv is a **test seam only**; the PHP side never passes it.

The parent-side half is in `request()`. A long artisan run whose two service uses are more
than the window apart legitimately finds its daemon gone; when the socket cannot be reached,
`request()` collects what is left of the old daemon, mints a NEW socket name, spawns, and
retries the request **exactly once**. A second failure throws the original legible error. One
~40ms respawn, and the idle exit is invisible.

### 5. Shutdown

`stop($force = false)` sends the socket `shutdown` message and then reaps by pid; `$force`
skips the message. Both act on THIS process's daemon only. There is no registered shutdown
function: on CLI, Symfony's `Process` destructor already stops what this process spawned.

A service spawned inside a php-fpm request SURVIVES the request and keeps serving that
worker's later requests - still privately, since the socket name lives in the worker's own
static state. `rsx:clean` (`Rsx_Node_Service::quiesce_all()`) and
`bin/maintenance-mode.sh enable` (its bash twin) take down every node daemon under
`storage/rsx-tmp/` - every process's node service, the SSR server, and anything added later -
by matching on the socket DIRECTORY in argv rather than on a name.

## Error handling

### Fatal error philosophy

Service failure is a **fatal error** - there is no single-file alternative path. This is
intentional:

1. A startup failure indicates a serious system issue (Node.js missing, permissions, a
   corrupt tree).
2. Failing loudly during development catches problems immediately.
3. Simpler code - no alternative path to keep correct.
4. Performance: the alternative would still be slow, defeating the purpose.

`Rpc_Startup_Diagnostics` reports what was OBSERVED (the process exited with its stderr / no
socket / a stale socket / a missing script) rather than guessing at a cause. It never
prescribes `npm install` inside `system/` for a downstream operator - that is an owned zone.

## Adding a subsystem

Three things, and nothing about lifecycle:

1. **A module** beside its PHP client, exporting a handler table
   (`module.exports = { method(request) { ... } }`, or `export default` if it must be ESM).
   Each handler takes the decoded request and returns (or resolves to) the object merged
   into the response beside its id.
2. **A registry row** in `resource/node-service-modules.json`. That is what makes it
   dispatchable.
3. **A thin client method** that builds the payload, calls
   `Rsx_Node_Service::request('prefix.method', $payload)`, and interprets the response with
   its own error vocabulary.

The socket, the spawn, the ready-wait, the reaping and the quiesce sweeps are already done.

### Worked example: bundle concatenation, and the argv ceiling

`Core/Bundle/Concatenator.php` + `Core/Bundle/resource/concat-service.js`.

Read this one when your payload is a LIST that grows with the size of the application.
Concatenation was a shell command until 2026-09 - `node concat-js.js <output> <file> ...`,
assembled into one string - and Linux caps a single argument at MAX_ARG_STRLEN (32 pages =
131072 bytes). A downstream app crossed that ceiling at 1,310 bundled JS files and every
route in it returned 500, blaming the innocent file whose addition tipped it over. A socket
payload has no such ceiling, so the conversion did not raise the limit, it removed it. The
same reasoning applies to any new subsystem: **if the request can grow with the app, it does
not belong on a command line.** `exec_safe()` now refuses an over-limit command with a
legible message rather than a bare `posix_spawn()` error, but that guard is a diagnosis aid,
not a license to build long argument lists.

### Worked example: SCSS compilation

`Integrations/Scss/Scss_Compiler.php` + `Integrations/Scss/resource/scss-service.js`.

Read this one for the plainest possible client: one method, one request, one response. It is
also the cautionary tale - until 2026-09 it generated a `compile.js` into a temp directory on
every call, ran `bash -c 'cd <base> && node <script>'`, recovered the exit code by parsing it
off the last line of captured output, and deleted the script again. If a new call site is
tempted to write a script and shell out to it, that is the shape this pattern exists to
replace.

### Key implementation files

- **PHP lifecycle (the one implementation):**
  `/system/app/RSpade/Core/JsParsers/Rsx_Node_Service.php`
  - `ensure()`, `request()`, `ping()`, `introspect()`, `stop()`, `force_restart()`,
    `quiesce_all()`, `socket_path()`, `module_paths()`
- **Node entry (dispatch, lazy loading, signal handling):**
  `/system/app/RSpade/Core/JsParsers/resource/node-service.js`
- **PHP client example (marshaling + cache only):**
  `/system/app/RSpade/Core/JsParsers/Js_Parser.php`
- **Diagnostics:** `/system/app/RSpade/Core/JsParsers/Rpc_Startup_Diagnostics.php`

## Performance characteristics

### Clean build (no cache)
- **Before RPC:** 1,200 process spawns = 60-180s overhead
- **After RPC:** 1 process spawn + RPC calls = 1-2s overhead
- **Speedup:** ~30-90x for process management alone

### Every artisan run (the consolidation's own saving)
- **Before:** up to 8 node spawns, each killed again by the `Process` destructor at exit
- **After:** 1 spawn, and only if something actually missed a cache

### Incremental build (with cache)
- Most files cached, few need work
- RPC overhead minimal (the service is already running, or is never started at all)

### Memory usage
- One node process, and only the toolchains actually used are resident
- CLI: dies with the artisan process (Symfony's `Process` destructor)
- php-fpm: outlives the request and keeps serving that worker, until it goes 120 seconds idle
  or a quiesce reaps it

## Debugging

### Check service status
```bash
# Is it running? (this pattern catches every node daemon, not just this one)
pgrep -af -- '--socket='

# Which sockets exist? One per live PHP process is normal.
ls -lh storage/rsx-tmp/node-service-*.sock

# What has it loaded?
php artisan rsx:test --framework Rpc_Lifecycle_Test   # the introspect assertions
```

### Manual service test
```bash
node system/app/RSpade/Core/JsParsers/resource/node-service.js --socket=/tmp/test.sock

# Watch the idle exit (test seam - the PHP side never passes this):
node system/app/RSpade/Core/JsParsers/resource/node-service.js \
    --socket=/tmp/test.sock --idle-exit-ms=1000

# From another terminal, any unix-socket client:
#   {"id":1,"method":"ping"}        -> {"id":1,"result":"pong"}
#   {"id":2,"method":"introspect"}  -> {"id":2,"result":{...,"loaded":[],...}}
```

### Common issues

**Service will not start:**
- Node.js installed? `node --version`
- Socket directory writable? `ls -ld storage/rsx-tmp`
- Read the thrown message - `Rpc_Startup_Diagnostics` reports what was observed

**Timeout waiting for ping:**
- The service crashed during startup (its stderr is quoted in the failure message)
- Socket permissions
- Node.js not in PATH

**Stale socket after a crash:**
- Handled automatically: the daemon retires itself after 120 idle seconds and unlinks its
  socket, and the next `request()` that finds a dead socket respawns on a fresh name
- The manual equivalent: `pgrep -af -- '--socket=<path>'`, then kill

## Security considerations

**Socket permissions:** a unix socket in `storage/rsx-tmp`, owned by the PHP process user.
Not a network socket; no external access; cleaned up automatically.

**Input validation:** the service validates JSON requests. File paths are the caller's to
validate before sending. There is no arbitrary-code-execution surface - a method name must
match a registered prefix and an exported handler, and both lists are static.

**Resource limits:** limited by the OS. No explicit memory cap, and no timeout anywhere -
slowness is a function of how much work was asked for, never evidence of a hang.
