# events - manifest lifecycle events & the trigger primitives

## Domain

The RSX event system (`#[OnEvent]` handlers discovered by the manifest, dispatched via
`Rsx::trigger_filter/gate/action/resolve`) plus the framework-fired **manifest lifecycle
events**. This concern owns the lifecycle family; the resolve-primitive semantics are
also exercised from the `search` concern (where `document.extract_text` lives).

The lifecycle events fire from `Manifest::init()` at the very end of initialization
(after the autoloader is registered and classless PHP loaded), once per process:

- `rsx.rebuilt` (action) - only when this process (re)scanned the manifest (an incremental
  update WITH changed files, or a no-cache full build). Payload `{files: array}` = flat
  list of the changed relative paths (new + modified combined; no added/removed split, no
  removal tracking - the honest minimum the scanner exposes).
- `rsx.rebuilt.dev` / `rsx.rebuilt.prod` (action) - same condition, immediately after,
  selected by `Rsx::is_production()` (`.prod` for ANY prod-like mode incl. debug).
- `rsx.ready` (action) - ALWAYS, at every init completion (warm boot included). Payload
  `{rebuilt: bool}`, mirroring `Manifest::rebuild_occurred()`.

Dev semantics: a rebuild fires on the first request after a source change. Prod semantics:
a rebuild fires ONCE, inside the authorized `rsx:prod:build` (enable/refresh). Handlers run
INLINE on the boot path - heavy work must go to `Task::dispatch()`.

## Source under test

- `app/RSpade/Core/Manifest/Manifest.php` - `$_rebuild_occurred` flag (set at the single
  point both rebuild paths pass through, just before `_refresh_manifest()`),
  `rebuild_occurred()` accessor, and `__fire_lifecycle_events()` (the firing seam, called
  after `post_init()` at each of init()'s three exit paths).
- `app/RSpade/Core/Rsx.php` - `trigger_action()` (the fire-and-forget primitive used).
- `app/RSpade/Core/Events/Event_Registry.php` - manifest-driven handler lookup.

## Man page

`php artisan rsx:man event_hooks` - the complete event catalog (all 13 framework events:
6 file, 3 document-pipeline resolve, 4 lifecycle) plus the four trigger primitives.

## Testable surface

- **Lifecycle firing set + order + payloads** (php, implemented): driven through the real
  firing seam `Manifest::__fire_lifecycle_events()` with a controlled `$_rebuild_occurred`
  / `$_changed_files`, observed via a guarded recording fixture. Asserts: warm boot fires
  ONLY `rsx.ready` (rebuilt=false); a rebuild fires `rsx.rebuilt` -> `rsx.rebuilt.dev`
  (dev runner) -> `rsx.ready` (rebuilt=true) in that order, with the file list threaded to
  the rebuilt* payloads.
- **`rebuild_occurred()` accessor** (php, implemented): tracks the public flag.
- **Live end-to-end firing** (manual, verified during implementation, not a standing test):
  an mtime-only `touch` of an rsx file dirties the scan so the next request fires
  `rsx.rebuilt`+`.dev`+`rsx.ready(true)`; the following warm request fires only
  `rsx.ready(false)`. Not automated in-process because the test runner boots once (warm or
  cold by cache state), so a deterministic real-rescan assertion is not available without a
  subprocess; the seam test covers the logic and the accessor covers the flag.

## Fixture safety

`Rsx_Lifecycle_Events_Fixture_Handler` listens on the REAL lifecycle event names, which fire
on every boot. It records NOTHING unless a test flips `$recording` on, so on every real
dev/prod boot each handler is a pure no-op - being manifest-discovered and live is harmless.
This is the "side-effect-free marker/recording" pattern; it must never grow a side effect
that runs with `$recording` off.
