# js_transform concern

The JavaScript transform pipeline (Babel) that turns RSX app JS into browser-compatible
code for bundle concatenation. This concern covers the DECORATOR class-binding contract
specifically -- the defect that produced a white-screen incident and the vendored fork that
fixes it.

## Applicability

RSpade transforms every app JS file through
`app/RSpade/Core/JsParsers/resource/babel-service.js` (the `babel` subsystem of the node
service, wrapping `@babel/preset-env` + the decorator transform). Output is concatenated into a shared,
non-module bundle scope, so a decorated class MUST retain its module-scope bare-name binding
or downstream files / jqhtml templates / the SPA registry throw a ReferenceError at runtime.

Upstream Babel's decorator transform drops that binding for a decorated class DECLARATION
with static members (it renames the class to a uid and replaces the statement with a
`new (...)()` expression). The framework now restores the binding at the producer via the
vendored, patched fork `app/RSpade/Core/JsParsers/resource/babel-plugin-decorators` (see its
README.md). A former output-shape matcher in the transformer was retired because
`@babel/compat-data` 7.29.3 moved the destructuring baseline to Safari 14.1 and made
preset-env rewrite the matched shape before the matcher could see it (silent miss -> white
screen; the es5 target was always broken this way).

## Source files under test

- `app/RSpade/Core/JsParsers/resource/babel-service.js` -- transform pipeline,
  exporting `transformFileContent` / `createPrefixPlugin` / `targetPresets` /
  `preprocessDecorators` beside its RPC handler, and carrying the fail-closed contract
  assertion (pre/post) that fails the build if any decorated class loses its binding.
- `app/RSpade/Core/JsParsers/resource/babel-plugin-decorators/` -- the vendored decorator
  fork (esbuild bundle + patch + regeneration docs).
- `app/RSpade/Core/JsParsers/Js_Transformer.php` -- cache keying, including the toolchain
  fingerprint that invalidates cached transforms when the fork / @babel/core / server script
  changes.
- `app/RSpade/Core/JsParsers/Rsx_Node_Service.php` -- the ONE lifecycle (private socket name /
  ensure / request with its transparent respawn / ping / introspect / ready-wait / stop /
  self-heal / quiesce_all) for the node service every build subsystem is served by. Covered
  here because the transformer is one of its clients and this concern already owns the
  daemon-startup rows.
- `app/RSpade/Core/JsParsers/resource/node-service.js` and
  `resource/node-service-modules.json` -- the dispatch entry point and the shared subsystem
  registry both sides read.

## Man page

`php artisan rsx:man js_decorators` (JS decorator system; the TRANSFORM PIPELINE section
documents the fork architecture and the binding contract).

## Testable surface

- Generated-name prefixing by PROVENANCE: `pre()` records every top-level name the author
  declared; `post()` hash-prefixes only the `_`-named top-level bindings that are NOT in that
  set. This is what lets a framework-application name (the reserved SINGLE leading underscore
  - see `App\RSpade\Core\Naming\Rsx_Identifier`) exist in JS, while Babel's own helpers and
  uids still get the file hash. Covered by `php` (JT-06..JT-09).
- The producer-side binding fix: every decorated class keeps a reachable bare-name binding
  at modern / es6 / es5, and executes correctly (static fields, methods, prototype chain,
  decorator execution, replacement decorators). Covered by `php`.
- The fail-closed contract assertion: a dropped binding fails the build loudly. Covered by
  `php` (runs the real assertion against a fork-less config).
- The node service lifecycle: two PHP processes getting two PRIVATE daemons that cannot touch
  each other (asserted both by clearing the per-process state and with a real child artisan
  run), the daemon's idle exit and the proof it never fires while a request is still arriving,
  the transparent respawn when a daemon has vanished, a stop that actually stops, and the
  rsx:clean-time sweep. Covered by `php` (`Rpc_Lifecycle_Test`, real processes).
- The shared subsystem registry is honest: every path it names exists on disk, and so does the
  entry script. Covered by `php`.
- Lazy loading: a ping loads no subsystem; a concat-only session loads only concat; two
  subsystems are served by the SAME process. Covered by `php` via the `introspect` method.
- The cache toolchain fingerprint (Js_Transformer.php): changing the fork / babel module /
  babel version invalidates cached transforms. Not yet a dedicated automated test -- see
  catalog (deferred); exercised indirectly by the real-app rebuild verification during
  implementation. It keys cached BYTES ON DISK; the service itself needs no equivalent,
  because each PHP process spawns its own daemon from current disk.

## Test tooling

- `resource/harness.js` -- node harness driving the REAL transform path (the babel module's
  exported internals), evaluating output in a `vm` sandbox with decorator stubs, emitting
  runtime facts as JSON; `emit` mode prints the raw transformed source instead, and
  `assert_negative` runs the negative (assertion-trips) check. Lives under `resource/` so the
  manifest does not scan it as framework JS.
- `resource/fixtures/*.js` -- the decorated/undecorated class fixtures (cases a-e), plus the
  framework-application-prefix fixtures (`_Fixture_Sys_Sidebar`, `_Fixture_Sys_Action`) and
  the authored-underscore non-class fixture (`Fixture_Authored_Underscores`).
