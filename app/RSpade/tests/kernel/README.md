# kernel

`system/app/Http/Kernel.php` - the HTTP kernel, its framework ownership, and the ONE
sanctioned seam by which an application adds middleware: `config('rsx.middleware')`.

## Domain

The kernel is framework wiring. It declares the maintenance/migration/Playwright
middlewares and, just as deliberately, REMOVES Laravel's session, CSRF and
ConvertEmptyStringsToNull entries (RSX has its own session handler, its own CSRF
transport, and treats an empty string as an empty string). A framework release
therefore has to edit that file on every app's behalf - which is why the file is a
framework-OWNED zone (`OWNED_FILES` in the updater, `Framework_Mutations::OWNED_ZONE_FILES`):
hard-synced by every pull, tamper-gated against local edits.

Ownership without a seam would be a dead end, so the kernel folds
`config('rsx.middleware')` into itself at bootstrap:

```php
'middleware' => [
    'global'  => [My_Middleware::class],   // appended to the global stack
    'web'     => [],                       // appended to a declared GROUP
    'api'     => [],
    'aliases' => ['x' => My_Middleware::class],
],
```

Invariants the tests exist to hold:

- **APPEND-ONLY.** App middleware lands at the END of the framework stack / group /
  alias map. No config spelling reorders or removes framework middleware (the
  `csp.additional_sources` philosophy: widen, never narrow).
- **Loud validation.** A class that does not exist, an unknown group key, and an alias
  already bound to a different class each throw a `RuntimeException` naming the
  offender - a typo must never silently do nothing.
- **Idempotent.** Re-declaring a class or an alias already present is a silent no-op,
  so the merge survives being run twice.
- **Timing.** The rsx config merge happens INSIDE `parent::bootstrap()`
  (`Rsx_Framework_Provider::register()` under the `RegisterProviders` bootstrapper),
  so `config('rsx.middleware')` is only readable after the parent call.

## Source under test

| File | Role |
|------|------|
| `app/Http/Kernel.php` | `bootstrap()` override + `__merge_configured_middleware()` |
| `config/rsx.php` | The `'middleware'` block (ships empty) |
| `bin/framework-pull-upstream.sh.dist` | `OWNED_FILES` - the ownership half of the pair |
| `app/RSpade/Core/Framework/Framework_Mutations.php` | `OWNED_ZONE_FILES` - its mandatory twin |

## Man pages

`rsx:man config_rsx` (the MIDDLEWARE section is the contract), `rsx:man dispatch`.

## Testable surface

- **php** - the merge helper, driven directly over a Kernel built on a throwaway
  Router: append order, group append, aliases, every refusal, dedupe, empty config.
- **cli** - the ownership pair is covered by `framework_update/cli t25` (hard sync of
  `app/Http/Kernel.php`, tamper gate, `--force` restore); not duplicated here.
- **http** - not applicable: proving a declared middleware runs over the wire would
  require permanently shipping a no-op middleware, which the merge test covers honestly
  without polluting the request stack.
