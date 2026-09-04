# JavaScript Core Systems - the node service

## Overview
Every JavaScript toolchain the build needs runs in ONE long-running Node.js process on ONE
unix socket, PRIVATE to the PHP process that spawned it. It exists so a manifest build does
not spawn 1,200+ node processes - and, since the 2026-09-04 consolidation, so an artisan run
does not spawn (and immediately kill) eight daemons to do it.

Architecture, protocol, the roster, the worked examples and how to add a subsystem live in
`README.md` in this directory. This file is the short form.

## Rsx_Node_Service - THE lifecycle

`Rsx_Node_Service` (this directory) owns the whole lifecycle. Nothing extends it, nothing
duplicates it, and no client knows a socket path.

```php
Rsx_Node_Service::request('parser.parse', ['files' => [$file]]);  // the ONE door; ensures first
Rsx_Node_Service::ensure();                 // idempotent per process (request() calls it)
Rsx_Node_Service::ping(): bool;             // is it answering
Rsx_Node_Service::introspect(): array;      // {pid, socket, loaded[], registered[]}
Rsx_Node_Service::stop($force = false);     // shutdown message, THEN reap by pid ($force skips the message)
Rsx_Node_Service::force_restart();          // stop(force) + ensure
Rsx_Node_Service::get_process(): ?Process;  // the handle THIS process published, or null
Rsx_Node_Service::quiesce_all(): int;       // kill every node daemon under rsx-tmp; returns the count
Rsx_Node_Service::socket_path(): string;    // THIS process's private socket, minted on first use
Rsx_Node_Service::module_paths(): array;    // prefix => module path, from the shared registry
```

A client builds a payload, calls `request()`, and interprets the response with its own error
vocabulary. **That is the entire contract.** Eight clients do exactly this: `Js_Parser`,
`Js_Transformer`, `Minifier`, `Concatenator`, `Scss_Compiler`, `JqhtmlWebpackCompiler`,
`FileSanitizer`, `Js_CodeQuality_Rpc`.

Spawn always goes through `RsxLocks::command_without_inherited_locks()` - a daemon that
inherits an flock descriptor holds that lock for its whole idle life, and `Manifest::init()`
locks at boot, so the whole CLI wedges. The process handle is published **only after** the
service answers; publishing before the ready-wait left a non-null handle after a failed
start, so every later caller skipped the spawn and died at "socket not found" instead (field
report, 2026-08-11: one slow boot, six failed bundles).

## Method naming
`<prefix>.<method>` where the prefix is a registered subsystem. Three top-level methods
belong to the service itself: `ping`, `introspect`, `shutdown`.

## Lazy loading (mandatory)
A subsystem module is loaded on FIRST USE. A ping loads nothing; a concat-only session never
loads sass. `introspect()` reports what is actually loaded and is how this is asserted.

## The socket is PRIVATE to this process
`socket_path()` mints `storage/rsx-tmp/node-service-<random>.sock` ONCE per PHP process,
lazily. Nobody else can learn that name, so nobody else can kill, rebind or reuse the daemon
behind it. That is what removes a real race: a shared well-known socket lets process B's
`ensure()` reap the daemon process A is mid-request on, and lets two cold starts fight over
one path.

**Freshness is true by construction, and nothing validates a running daemon.** A daemon
is always spawned from the source and toolchain on disk at spawn time, by its own parent, and
its lifetime is a subset of that parent's. Nothing is ever inherited, so there is nothing to
validate - edited source, a fresh framework pull and a bumped npm library are all picked up
because the next process spawns from current disk.

The socket still lives under `storage/rsx-tmp/` deliberately: `quiesce_all()`, `rsx:clean` and
`bin/maintenance-mode.sh` all sweep that DIRECTORY by argv match, so every daemon stays
reachable by the operational sweeps without any of them knowing a name. Several
`node-service-*.sock` files at once is normal - it means several PHP processes are live.

## Idle exit, and the respawn that makes it invisible
The daemon exits cleanly (close, unlink, exit 0) after **120 idle seconds**: no open
connection, nothing in flight, nothing completed within the window. It is orphan insurance -
a daemon whose parent was SIGKILLed holds a name nobody will ever learn, so the only thing
that can end it is itself - and it is armed ONLY when idle. **A three-minute sass compile
with no other traffic is WORKING, not idle**; the counters are raised the moment a request
line starts arriving and lowered only when its response has been produced, so the timer never
bounds anybody's work. `--idle-exit-ms=<n>` on argv is a TEST SEAM only; the PHP side never
passes it.

The parent-side half is in `request()`: when the socket cannot be reached (a long artisan run
whose two service uses are more than the window apart legitimately finds its daemon gone), it
collects what is left of the old daemon, mints a NEW socket name, spawns, and retries the
request **exactly once**. One ~40ms respawn, never a failure mode.

## Self-heal, and why a socket message is not teardown
`stop()` and the respawn path both end in the same reaper: `pgrep -f -- '--socket=<abs
path>'`, SIGTERM, one settle pass, SIGKILL, then remove the socket. It matches only THIS
process's own socket path, so no other process's daemon can be touched. A daemon whose socket
file was unlinked under it (by `rsx:clean`, by a storage relocation) keeps a listener on the
dead inode forever and can NEVER receive another socket message - but its argv still names the
path, so pgrep still finds it.

`quiesce_all()` is the same reaper widened to the whole `storage/rsx-tmp/` socket directory:
it matches on argv, not on identity, so it takes down every process's node service,
`ssr-server`, a stray left over from an older framework release, and anything added later
without knowing they exist. `rsx:clean` calls it immediately before wiping `rsx-tmp`;
`bin/maintenance-mode.sh enable` runs the bash twin. See `bin/CLAUDE.md` for the normative
rule.

## Process lifetime (MEASURED 2026-08-13, not assumed)
- **CLI**: Symfony's `Process::__destruct` calls `stop(0)`, so a service spawned by an artisan
  run dies with that process. Verified directly. (This is also why eight daemons were pure
  waste on CLI: eight spawns, eight kills, per run.)
- **php-fpm**: the worker outlives the request, and the service it spawned keeps serving that
  worker's later requests - still privately, since the socket name lives in the worker's own
  static state.

Orphans come from the abrupt deaths neither path covers - a SIGKILLed worker, an interrupted
build - which is what the ten-deep live pile on this box turned out to be. The daemon's own
idle exit is what ends those now. Nothing registers a shutdown function: on CLI the
destructor already reaps what this process spawned.

## JS Parser
- `Js_Parser.php` - PHP client (cache + marshaling + `Js_Exception` vocabulary)
- `resource/parser-service.js` - the `parser` subsystem
- Cache: `storage/rsx-tmp/persistent/js_parser/`, keyed by file hash. Checked FIRST - only a
  miss reaches the service, so a fully cached manifest build never starts node.
- `Js_Parser::parse($file_path)` / `Js_Parser::extract_metadata($file_path)`

## JS Transformer (Babel)
- `Js_Transformer.php` - PHP client (cache + toolchain fingerprint + error vocabulary)
- `resource/babel-service.js` - the `babel` subsystem
- Cache: `storage/rsx-tmp/babel_cache/`, keyed by file hash + target + toolchain fingerprint
- `Js_Transformer::transform($path, $target)` / `transform_string($code, $path, $target)`

### Transformation details
- Preprocesses `@decorator` on standalone functions
- Applies Babel: decorators, class properties, optional chaining, nullish coalescing
- Prefixes generated helper functions with a file hash to prevent namespace collisions in the
  shared bundle scope
- Target presets: `modern`, `es6`, `es5`
- Generates inline source maps for debugging

### Decorator class-binding (vendored fork)
Output is concatenated into a shared, non-module bundle scope, so every decorated class must
keep its module-scope bare-name binding. Upstream Babel drops that binding for a decorated
class DECLARATION with static members. The decorator transform is therefore vendored and
patched at `resource/babel-plugin-decorators/` (a self-contained esbuild bundle,
`@babel/core` externalized) which emits `var <Name> = <uid>;` at the producer. See that
directory's `README.md`. This replaced a fragile output-shape matcher (retired) that broke
when `@babel/compat-data` 7.29.3 changed the destructuring baseline.

`babel-service.js` does not rewrite decorator output; its prefix plugin only (1) prefixes
generated helpers and (2) carries a **fail-closed contract assertion** - it records decorated
class declarations pre-transform and throws (naming class + file) if any lost its
program-scope binding post-transform. The module also exports `transformFileContent` /
`createPrefixPlugin` / `targetPresets` / `preprocessDecorators` so the `js_transform` test
concern can drive the real path directly.

`Js_Transformer.php` folds a toolchain FINGERPRINT (fork bundle + `babel-service.js` +
`@babel/core` version) into every cache key, so swapping the toolchain invalidates cached
transforms automatically. That keys the cache on DISK; the service itself needs no equivalent,
since each PHP process spawns its own daemon from current disk.

## Common patterns

### Force parameter
`stop($force = false)`:
- `false` (default): send the socket `shutdown` message first, then reap by pid
- `true`: skip the message and go straight to the reap (the message cannot reach an orphan
  whose socket was already unlinked, which is why the reap always runs)

### Cache integration
Every client checks its own cache before calling `request()`. Only a genuine miss reaches the
service - which is also what makes the service's start lazy.

### Error handling
Service failure -> fatal error, no alternative path. The service must start or the
build/compilation fails.

## Adding a subsystem
A module beside its PHP client, a row in `resource/node-service-modules.json`, and a thin
client method calling `Rsx_Node_Service::request('prefix.method', $payload)`. The registry row
is what makes it dispatchable. Full guidance and the
two worked examples: `README.md` in this directory.
