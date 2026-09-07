# js_transform test catalog

All rows implemented in `php/Js_Decorator_Transform_Test.php` unless noted. The PHP class
shells to `resource/harness.js` (real transform path, vm runtime eval) and to the server CLI.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| JT-01 | Every fixture keeps a reachable bare-name binding at each target | php | all class fixtures x {modern,es6,es5} | `bare_name_defined` true for every pair | implemented | 2026-09-07 |
| JT-02 | The transform EMITS the fork's `var <Name> =` binding for a decorated-static class - asserted on the raw output TEXT, which is what gets concatenated, rather than on runtime reachability | php | Fixture_Static_Action via `node harness.js emit <fixture> <target>` (the babel module required out-of-process) | stdout contains `var Fixture_Static_Action =` at every target | implemented | 2026-09-04 |
| JT-03a | Decorated class decl + static field executes correctly | php | Fixture_Static_Action, all targets | static field, method-reads-static, instanceof base, route/layout/spa decorators ran | implemented | 2026-07-24 |
| JT-03b | Class decorator + member decorator + static field | php | Fixture_Member_Decs, all targets | binding present, method present, route + debounce decorators ran | implemented | 2026-07-24 |
| JT-03c | Class decorator returning a REPLACEMENT binds the replacement | php | Fixture_Replacement (static field), all targets | `bound_is_replacement` true, original reachable up chain | implemented | 2026-07-24 |
| JT-03d | Control: decorated static-METHOD-only class (no static fields) | php | Fixture_Method_Only, all targets | binding present, `make()` returns instance | implemented | 2026-07-24 |
| JT-03e | Non-decorated class left untouched, executes normally | php | Fixture_Plain, all targets | binding present, static field + method work | implemented | 2026-07-24 |
| JT-03f | Class-decorator METADATA survives: `@title` lands as `_spa_title` on the bound class (what the SPA title ladder reads) | php | Fixture_Title_Action, all targets | binding present, `_spa_title === 'Fixture Title'` | implemented | 2026-08-18 |
| JT-04 | Fail-closed contract assertion trips when binding is dropped | php | real `createPrefixPlugin` against a fork-less (stock decorator plugin) config | transform THROWS naming the class + "module-scope binding" | implemented | 2026-07-24 |
| JT-06 | A framework-application name (SINGLE leading underscore) survives the transform undecorated: the name is intact in the emitted source and the class executes | php | `_Fixture_Sys_Sidebar`, all targets | binding present, static field + method work, `_Fixture_Sys_Sidebar` present in emitted text | implemented | 2026-09-07 |
| JT-07 | The same name DECORATED keeps the fork's bare `var <Name> =` binding - the head-on collision between the framework prefix and the generated-name prefixer | php | `_Fixture_Sys_Action` (@title + static field), all targets | emitted text contains `var _Fixture_Sys_Action =`; binding, static field and `_spa_title` all present | implemented | 2026-09-07 |
| JT-08 | Provenance did not widen to everything: a genuine Babel-generated helper is STILL hash-prefixed, so concatenated files cannot collide | php | `Fixture_Static_Action` emitted at es5 | matches `_<8hex>_applyDecs`; no bare `function _applyDecs(` | implemented | 2026-09-07 |
| JT-09 | An AUTHORED top-level `_helper()` / `_CONST` in a non-class file is left exactly as written | php | `Fixture_Authored_Underscores`, all targets | `function _helper(` and `_CONST` present, neither hash-prefixed | implemented | 2026-09-07 |
| JT-05 | Toolchain fingerprint invalidates the transform cache when the fork / server / @babel/core changes | php | change fork bundle hash / server hash / babel version | `Js_Transformer::transform` produces a new cache key; old entry orphaned | deferred (fingerprint is a private method; covered by code review + the real-app rebuild verification during implementation) | 2026-07-24 |
| RPC-01 | An RPC startup failure never prescribes the harmful remedy: no `cd ... && npm install` recipe for a DOWNSTREAM operator (node_modules is an owned zone - writing into it makes the tamper gate refuse every future update), and never names `Core/JavaScript/resource`, a directory that does not exist | php | `Rpc_Startup_Diagnostics::failure_message()` over three stderr shapes, framework_developer pinned false; Rpc_Startup_Diagnostics_Test | no `&& npm install`, no `Core/JavaScript/resource` | implemented | 2026-08-11 |
| RPC-02 | A genuine module failure is diagnosed from the daemon's OWN stderr and routed to the updater downstream (an absence in an owned zone is a SYNC DEFECT), while the monorepo - the one place npm install inside system/ is correct - gets that answer instead | php | MODULE_NOT_FOUND stderr, both audiences pinned; Rpc_Startup_Diagnostics_Test | downstream: EXITED + quoted stderr + `rsx:framework:pull` + explicit "Do NOT run npm install inside system/"; monorepo: `npm install`, no `rsx:framework:pull` | implemented | 2026-08-11 |
| RPC-03 | Absence of evidence never becomes a dependency diagnosis - with no module error observed, the message names the likeliest real cause (slow boot) and the config key that raises the budget | php | empty stderr; Rpc_Startup_Diagnostics_Test | no "Cannot find module", contains "slow to boot", `rpc_server_ready_wait_ms`, and the ms actually waited | implemented | 2026-08-11 |
| RPC-04 | The three conditions that used to collapse into one message stay distinct: a present-but-unserved socket is the STALE case (with a pgrep recipe to find the holder), and a missing server script is an incomplete tree (the updater's job) | php | planted socket file / bogus script path; Rpc_Startup_Diagnostics_Test | EXISTS+stale+pgrep; MISSING+`rsx:framework:pull` | implemented | 2026-08-11 |
| RPC-05 | THE CASCADE: a failed start leaves NO process handle, so the next use is a clean retry rather than "already started" -> "socket not found". This is what turned one slow boot into 6 failed bundles and a failed framework-update rebuild | php | `rpc_server_ready_wait_ms` forced to 0 (deterministic, timing-independent) against `Rsx_Node_Service`; Rpc_Startup_Diagnostics_Test | throws, message names the 0ms budget, and `get_process()` is NULL afterwards | implemented | 2026-09-04 |
| RPC-06 | THE PRIVATE SOCKET: two PHP processes get two DIFFERENT socket names and two daemons, and neither can touch the other's. This is what removes the kill race a shared well-known path has - one process's ensure() reaping the daemon another is mid-request on | php | ensure -> clear the per-process socket name + latch (what a fresh process has) -> ensure again; Rpc_Lifecycle_Test | different socket paths, different pids, and the FIRST daemon still alive and still answering after the second starts and stops | implemented | 2026-09-05 |
| RPC-07 | The same property with a REAL second PHP process: a child artisan run does a full service lifecycle of its own and leaves ours exactly where it was | php | ensure -> `Rsx_Artisan::run('rsx:js:transform', <a file the babel cache has never seen>)`; Rpc_Lifecycle_Test | child exits 0; our daemon has the SAME pid afterwards and still answers | implemented | 2026-09-05 |
| RPC-08 | IDLE SUICIDE: an orphaned daemon retires itself and unlinks its socket. Its private name means nothing else can ever reach it, so the only thing that can end it is itself | php | start the entry directly with `--idle-exit-ms=300` (test seam), ping, wait 1.2s; Rpc_Lifecycle_Test | pgrep count 0, socket file gone | implemented | 2026-09-05 |
| RPC-08b | AND IT NEVER FIRES MID-REQUEST: a request still ARRIVING keeps the daemon alive. A three-minute sass compile with no other traffic is WORK, not idle | php | `--idle-exit-ms=300`, write half a request line, wait 1.2s (four windows), then complete it; Rpc_Lifecycle_Test | daemon alive throughout, the completed line answers `pong` | implemented | 2026-09-05 |
| RPC-08c | TRANSPARENT RESPAWN, the safety half of RPC-08: a request whose daemon has vanished mints a fresh socket, respawns and retries ONCE rather than failing | php | ensure -> SIGKILL the daemon -> `Rsx_Node_Service::request('ping')`; Rpc_Lifecycle_Test | `pong`, a DIFFERENT socket path, one daemon on it, and the abandoned socket file collected | implemented | 2026-09-05 |
| RPC-09 | `Rsx_Node_Service::stop()` means STOPPED - process gone, socket removed - rather than a message sent over a socket that an orphan can never receive | php | ensure -> stop(); Rpc_Lifecycle_Test | pgrep count 0, no .sock | implemented | 2026-09-04 |
| RPC-10 | quiesce_all() (what rsx:clean calls before wiping rsx-tmp) takes down EVERY node daemon in the socket directory at once and reports the count - it matches on the socket directory in argv, so it knows nothing about identity and catches every process's private service, the SSR server and any future daemon for free | php | the node service + a second daemon planted on its own socket in the same directory -> `Rsx_Node_Service::quiesce_all()`; Rpc_Lifecycle_Test | count >= 2 reported, both gone | implemented | 2026-09-04 |
| RPC-12 | RETIRED 2026-09-05 (provenance covers every subsystem module). The property is now TRUE BY CONSTRUCTION: a daemon is always spawned from current disk by its own parent, on a socket name nobody else can learn, and its lifetime is a subset of that parent's. Nothing inherits a daemon, so there is nothing to validate and no `.meta` to write. Its replacement is RPC-06 | php | - | - | retired | 2026-09-05 |
| RPC-13 | RETIRED 2026-09-05 (provenance covers the toolchain). Same reason as RPC-12: a bumped npm library is picked up because the NEXT process spawns a fresh daemon, not because a hash moved. The disk-cache half of the jqhtml 2.3.61 case still stands and is JT-05 / `Js_Transformer`'s toolchain fingerprint | php | - | - | retired | 2026-09-05 |
| RPC-17 | The readiness budget is the DECLARED default (20s), pinned so moving it is a deliberate edit in both places it appears | php | `config('rsx.javascript.rpc_server_ready_wait_ms')`; Rpc_Lifecycle_Test | 20000 | implemented | 2026-09-05 |
| RPC-14 | LAZY LOADING: a freshly started, pinged-only service has loaded NO subsystem at all. babel, sass, terser, postcss and @jqhtml/parser each cost real time and memory to require, and a ping must pay for none of them | php | ensure -> `introspect()`; Rpc_Lifecycle_Test | `loaded` is empty, and `registered` is exactly what the shared registry names | implemented | 2026-09-04 |
| RPC-15 | ONE PROCESS SERVES EVERY SUBSYSTEM, each loaded on first use - the whole point of the consolidation. A concat-only session must never load sass | php | ensure -> concat -> introspect -> minify -> introspect; Rpc_Lifecycle_Test | `loaded` is `[concat]` then `[concat, minify]`, the pid is unchanged, and pgrep still shows exactly one daemon | implemented | 2026-09-04 |
| RPC-16 | The shared subsystem registry is honest: every path `node-service-modules.json` names exists on disk, and so does the entry script. A typo here fails at whatever moment that subsystem is first used, which can be days after the commit | php | `Rsx_Node_Service::module_paths()`; Rpc_Lifecycle_Test | every registered module file exists; the entry script exists | implemented | 2026-09-04 |
| RPC-11 | A daemon that EXITED and printed stderr is diagnosed as a hard failure, never as a slow boot (the 2026-08-12 `sh: 1: exec: 11: not found` case, where the true cause was quoted and the remedy talked about slowness) | php | non-empty stderr on a dead process; Rpc_Startup_Diagnostics_Test | contains "EXITED with an error" + the quoted stderr; no "slow to boot", no `rpc_server_ready_wait_ms` | implemented | 2026-08-17 |

## Notes

- JT-06..JT-09 cover the PROVENANCE rule in `createPrefixPlugin`: `pre()` records every
  top-level name the author declared and `post()` prefixes only the `_`-named top-level
  bindings that are NOT in that set. Before this, every `_`-prefixed top-level binding was
  claimed as Babel-generated, which made a framework-application name (the reserved single
  leading underscore - `App\RSpade\Core\Naming\Rsx_Identifier`) unusable in JS. JT-08 is the
  guard in the other direction.
- The bash TWIN of RPC-10 - the same reap performed by `bin/maintenance-mode.sh enable`, which
  no PHP path can reach - is covered in the `maintenance` concern (M-30..M-32). Nothing from
  the earlier Daemons epic is left deferred here: RPC-01..RPC-05 (diagnostics, the failed-start
  cascade) and RPC-06..RPC-10 (lifecycle, freshness, orphan collection, quiesce) are all
  implemented.
- RPC-06..RPC-17 exercise the REAL lifecycle: real node daemons, real sockets, real pgrep,
  and (RPC-07) a real second PHP process. The only thing simulated is the EVENT - SIGKILLing a
  daemon is literally what an interrupted build does to one, and `--idle-exit-ms` only moves
  the daemon's own window into a range a test can watch. RPC-06 clears
  `Rsx_Node_Service`'s private per-process state to produce what a fresh process has, and
  holds the first daemon's Symfony handle in a local while it does: dropping the last
  reference to a Process destructs it and kills the daemon under test.
- JT-02 used to drive the transformer's own CLI mode. There is no CLI any more: the transform
  lives in the node service's `babel` module, which has one entry point. The harness's `emit`
  mode requires that module out-of-process and prints what it produced, so the assertion is
  unchanged - only the door it comes through moved.
- JT-03c uses a fixture WITH a static field on purpose: that routes through the statics
  branch the fork patches, where a replacement decorator correctly wins. A decorated class
  WITHOUT static members takes upstream's surviving-declaration path, where the public name
  binds the ORIGINAL (upstream behavior, unrelated to the fork). RSX's real decorators
  (`@route`/`@layout`/`@spa`) return the value unchanged, so this distinction never bites the
  framework; the replacement case is tested only to prove the bound name holds whatever the
  decorator produced.
- The negative test (JT-04) exercises the REAL assertion (the exported `createPrefixPlugin`)
  rather than a copy: it swaps the fork out for the stock upstream plugin so the binding is
  genuinely dropped, then asserts the post() assertion throws.
