# Laravel upgrade: 10 -> 11 -> 12 -> 13

Working record for the upgrade. Baseline captured 2026-08-24 at monorepo commit
`c92621e60`. **Delete this whole directory when the upgrade is finished** - it is
scaffolding, not documentation. (`resource/` is framework-ignored, so nothing here
is scanned, bundled or published into the reference app.)

Baseline: **laravel/framework v10.48.29**, PHP `^8.1` declared, PHP 8.4 on the box.

---

## 1. What we hand-altered in vendor

Method: a pristine `composer install` from our own `composer.lock` into a temp
directory, then `diff -rq` against `system/vendor`. That compares every package at
its exact locked version, so it finds edits anywhere in the tree - not just in
`laravel/framework`.

Generated artifacts were excluded from the comparison (`vendor/composer/autoload_*`,
`installed.json`, `installed.php`, `InstalledVersions.php`, `vendor/autoload.php`);
they legitimately differ because they carry absolute paths.

**The entire result was 4 differing files and 4 present-in-one-tree-only entries.**
The vendor tree is far cleaner than expected.

### 1.1 REAL EDITS (3)

| # | File | Patch | What / why |
|---|------|-------|------------|
| 1 | `laravel/framework/src/Illuminate/Filesystem/Filesystem.php` | `baseline_l10/01_illuminate_filesystem.patch` | `chmod()` -> `@chmod()` in `replace()`. Suppresses chmod failures in Docker where the operation is not permitted (this box's idmapped mount). |
| 2 | `spatie/ignition/src/Ignition.php` | `baseline_l10/02_spatie_ignition_viewpath.patch` | `viewPath()` checks `resources/views/vendor/ignition/{view}.php` first, so the error page can be themed. |
| 3 | `vendor/bin/sail` | `baseline_l10/03_vendor_bin_sail.patch` | `realpath "$selfArg"` -> `realpath $selfArg` (quotes removed). |

**Do not reapply #3.** Every other composer bin proxy in the tree is byte-identical
to a freshly generated one, so this is a hand edit, not a composer-version artifact -
and removing the quotes makes the script wrong for any path containing a space.
`laravel/sail` is a dev dependency this project does not use (the Docker setup is
our own `rspade/rspade-server-dev` image), so the edit is inert. Let it be
regenerated and leave it alone.

### 1.2 NOT edits (4) - recorded so nobody re-investigates them

- `vendor/_laravel_ide/` - present only in ours. Artifact of `barryvdh/laravel-ide-helper`.
- `vendor/spatie/ignition/node_modules/` - present only in ours; ships with the fork.
- `vendor/symfony/string/Resources/bin/.placeholder` - a 0-byte file we add because
  that directory is EMPTY upstream and git cannot track an empty directory. Vendor is
  committed here, so it is needed. Keep it.
- `vendor/hamcrest/hamcrest-php/.gitattributes` - CRLF vs LF only; identical after
  stripping CR. Line-ending noise from the committed-vendor arrangement.

### 1.3 How to reimplement each edit cleanly (the point of the exercise)

**#1 Filesystem `@chmod`.** Do NOT re-patch vendor. Laravel binds the filesystem as
`files` in the container. The clean form is an `Rsx_Filesystem extends Filesystem`
overriding `replace()`, bound in a service provider - RSpade already class-overrides
framework code this way everywhere else. Worth confirming first whether it is still
needed: the root cause was chmod on a bind mount, and the container now runs as the
host user (PUID/PGID, release `76dee3355`), which may have removed the condition
entirely. **Test without it before reimplementing anything.**

**#2 Ignition `viewPath`.** This is why `spatie/ignition` and `spatie/laravel-ignition`
are PATH packages in the lockfile (see §2) - the package was forked to make one method
overridable.

**Check what the fork actually buys before preserving it.** The custom view at
`system/resources/views/vendor/ignition/errorPage.php` differs from the stock file by
**8 lines**, and every one of them wraps an inline `<script>` body in an IIFE:

```
+    (function() {
         document.documentElement.classList.add('...');
+    })();
```

That is scope hygiene on a DEVELOPMENT-ONLY error page. Nothing renders differently.
For that, we carry a fork of two packages and a lockfile that cannot be installed (§2).

**Recommendation: drop the fork.** Take both ignition packages from packagist, delete
`system/resources/views/vendor/ignition/`, and let the stock error page render. This
resolves §2 at the same time and removes the largest obstacle to `composer update`.
If the IIFE wrapping turns out to be load-bearing for something, the honest fix is a
PR upstream making `viewPath()` overridable - not a private fork.

**#3 sail.** Drop it. See above.

---

## 2. BLOCKER: `composer.lock` is not installable as committed

Both ignition packages are recorded with `"dist": {"type": "path", "url": "./packages/ignition"}`
(and `./packages/laravel-ignition`). **Those directories do not exist in the repo.**
A clean `composer install` therefore dies:

```
Source path "./packages/ignition" is not found for package spatie/ignition
```

This is invisible day to day because `system/vendor` is committed and downstream
never runs composer. It stops mattering the moment we run `composer update` for the
upgrade, so it has to be resolved FIRST. To produce the baseline above, the two
entries were temporarily repointed at their real packagist dists.

The fix is whatever §1.3 #2 decides: if the fork goes away, both packages come from
packagist normally and the problem dissolves.

---

## 3. `artisan` and the other skeleton files

`baseline_l10/10_artisan.patch` and the `11_*.patch` files diff our copies against
the stock `laravel/laravel` 10.x skeleton.

These are **authored RSpade files, not vendor hacks**, and the diffs are large:

| File | Ours | Skeleton |
|---|---|---|
| `artisan` | 376 lines | 10 |
| `bootstrap/app.php` | 267 | 55 |
| `public/index.php` | 260 | 55 |
| `app/Http/Kernel.php` | 238 | 68 |
| `app/Providers/AppServiceProvider.php` | 215 | 24 |
| `app/Console/Kernel.php` | 27 | 27 (22 lines differ) |
| `app/Exceptions/Handler.php` | absent | present |

They are captured for reference only. Nothing here needs merging on upgrade,
**because we are keeping the Laravel 10 application structure** - see §4.

---

## 4. Strategy: keep the L10 structure

Laravel 11's slim skeleton is optional, and the upgrade guide is explicit:

> we do **not recommend** that Laravel 10 applications upgrading to Laravel 11
> attempt to migrate their application structure, as Laravel 11 has been carefully
> tuned to also support the Laravel 10 application structure.

That settles it for us and it is the right call regardless: `bootstrap/app.php`,
`public/index.php` and `Http/Kernel.php` are heavily authored RSpade files carrying
pre-boot guards, the container gate and the middleware stack. Adopting
`withMiddleware()`/`withRouting()` would be a rewrite of framework-owned code for no
functional gain, and it is separable work if ever wanted.

So each step is: bump constraints, `composer update`, fix what breaks, verify, commit.

---

## 5. What each hop actually costs us

Only items that plausibly touch this codebase are listed. Full guides:
`laravel.com/docs/{11,12,13}.x/upgrade`.

### 10 -> 11 (PHP 8.2+)

| Item | Impact here | Action |
|---|---|---|
| PHP 8.2 required | none - box is 8.4 | bump `composer.json` `php` from `^8.1` |
| `laravel/sanctum` 3.x unsupported | must bump to `^4.0` | bump; check `config/sanctum.php` middleware keys |
| `doctrine/dbal` removed from Laravel | **none** - verified: the only reference is a DEAD `use Doctrine\DBAL\Types\Type;` import in `Migrate_Normalize_Schema_Command.php`, and no removed DBAL API is called anywhere | delete the import, drop the dependency |
| Column modifiers must be restated on `change()` | **none** - the Schema builder is PROHIBITED here; migrations are raw SQL | nothing |
| Floating-point / spatial column types | **none** - same reason | nothing |
| SQLite 3.26+ | none - MySQL | nothing |
| Carbon 3 (optional in 11) | Carbon is internal to `Rsx_Time`/`Rsx_Date`; `diffIn*` return floats and can go negative | defer to the 11->12 hop where it becomes mandatory |
| Password rehashing on login | we have custom auth (`RsxAuth`) | verify our user provider / `Authenticatable` implementations; add `rehashPasswordIfRequired` + `getAuthPasswordName` if we implement those contracts ourselves |
| Per-second rate limiting (`Limit`, `ThrottlesExceptions` take seconds) | we use our own `Rsx_Throttle` | grep for `new Limit(`/`ThrottlesExceptions` before assuming clear |
| Cache key prefix loses its `:` suffix | cache keys change once | harmless (cache is regenerable); note it |
| Eloquent base `casts()` method | `Rsx_Model_Abstract` applies casts automatically | check for a `casts` name collision on our models |
| New skeleton | not adopted | see §4 |

### 11 -> 12 (no PHP bump)

| Item | Impact here | Action |
|---|---|---|
| Carbon 2 support REMOVED | Carbon 3 now mandatory | audit `Rsx_Time`/`Rsx_Date` for `diffIn*` (floats, signed) |
| `phpunit` `^11` | dev dep | bump |
| `Schema::getTables()` spans all schemas; `getTableListing()` returns schema-qualified names | **likely real** - the schema-audit / normalize path inspects tables | grep and pin `schema:`/`schemaQualified:` explicitly |
| `Blueprint`/`Grammar` now need a `Connection`; `Grammar::setConnection()` removed | only if we instantiate these directly | grep |
| `HasUuids` becomes UUIDv7 | do we use it? | grep |
| Container respects nullable class defaults | we avoid DI | low |
| local disk root -> `storage/app/private` | we use `Rsx_File_Paths`, not the `local` disk | verify |
| `image` validation drops SVG | uploads validate their own way | check |

### 12 -> 13 (PHP 8.3+)

| Item | Impact here | Action |
|---|---|---|
| **`symfony/polyfill-php85` defines global `array_first()` / `array_last()`** | **HARD BREAK - see §6** | decision needed |
| CSRF middleware renamed `VerifyCsrfToken` -> `PreventRequestForgery`, plus `Sec-Fetch-Site` origin checking | we have `system/app/Http/Middleware/VerifyCsrfToken.php` extending the Illuminate class (currently commented out of the Kernel stack), and framework-wide CSRF of our own | rename to the new parent; verify our CSRF path against the new origin check |
| `laravel/tinker` `^3.0`, `phpunit` `^12` | dev deps | bump |
| `cache.serializable_classes` defaults false | do we cache objects? `RsxCache` | audit |
| `upsert` throws when `uniqueBy` empty | we have a throttle upsert | grep |
| MySQL `DELETE ... JOIN` now emits `ORDER BY`/`LIMIT` and may throw | grep for joined deletes | check |
| Session `serialization` -> `json` in the skeleton | we have no `config/session.php` and Laravel's session is unused (custom handler; `StartSession` is commented out of the Kernel) | nothing |
| `QueueBusy::$connection` -> `$connectionName`; `JobAttempted::$exceptionOccurred` -> `$exception` | we use our own Task system, not Laravel queues | verify |
| Pagination Bootstrap-3 view names | we do not use Laravel pagination views | nothing |

---

## 6. DECISION NEEDED: `array_first()` / `array_last()` collide at Laravel 13

Laravel 13 depends on `symfony/polyfill-php85`, which **defines global
`array_first()` and `array_last()`** on PHP below 8.5.

RSpade defines both, unguarded, as global helpers:

- `system/app/RSpade/helpers.php:935` - `array_first($array, $callback = null, $default = null)`
- `system/app/RSpade/helpers.php:964` - `array_last($array, $callback = null, $default = null)`

Composer loads a dependency's `files` autoload entries BEFORE the root package's, so
the polyfill defines them first and our declarations become a **fatal redeclare at
boot**. They are also documented RSpade stdlib and used by downstream app code.

Guarding with `function_exists()` is the wrong fix: it stops the fatal but silently
swaps our callback-taking implementation for the polyfill's one-argument version, so
every `array_first($rows, fn ($r) => ...)` call site breaks with an argument error
instead - a worse failure, further from the cause.

Real options:

1. **Rename** to non-colliding names (`array_first_where()` / `array_last_where()`,
   or drop them in favour of `Arr::first()` / `Arr::last()`, which take the same
   callback). Correct, and a downstream contract change: needs an `breaking_changes`
   document and a `shared/12-stdlib-and-time.md` edit.
2. **Keep the names and guarantee our file loads first.** Fragile - it depends on
   composer's autoload ordering staying as it is.
3. **Drop them entirely** and point everyone at `Arr::first()`/`Arr::last()`.

Recommendation: option 1 or 3, decided before the 12->13 hop. Both are one
`breaking_changes` document.

---

## 7. OUTCOME - all three hops complete

Final: **laravel/framework v13.26.1**, PHP constraint `^8.3`, on PHP 8.4.

| Hop | Version | Suite | Code fixes needed |
|---|---|---|---|
| baseline | v10.48.29 | 1778 pass / 1 skip | - |
| 10 -> 11 | v11.56.0 | 1778 / 1 | none |
| 11 -> 12 | v12.67.0 | 1778 / 1 | custom Grammars need `$connection` |
| 12 -> 13 | v13.26.1 | 1778 / 1 | `configure(): void` x3; dead CSRF subclass deleted |

**Not one test regressed at any hop.**

### The three open decisions, resolved

1. **Ignition fork - DROPPED.** Both packages come from packagist. The lockfile
   installs cleanly now, which it did not before.
2. **`array_first`/`array_last` - DELETED**, and the L13 hop proved it was
   load-bearing rather than precautionary: `symfony/polyfill-php85` is installed,
   `array_first()` now exists from it, and reflection confirms it takes exactly
   ONE required parameter against our old three. Left in place, a fatal redeclare
   at boot.
3. **`@chmod` - NO LONGER OURS TO CARRY.** Laravel adopted the same suppression
   upstream somewhere between 10.48.29 and 13.26.1: `Filesystem::replace()` now
   ships `@chmod()` with our explanatory comment absent. Nothing to reimplement,
   no `Rsx_Filesystem` override needed. Verified by calling `replace()` directly.

### The vendor tree is now PRISTINE

The same pristine-install diff that opened this document, re-run against
Laravel 13, reports exactly one entry:

```
Only in vendor: _laravel_ide
```

An IDE-helper artifact, not a hand edit. **Zero vendor patches remain.** That was
the point of the exercise, and it means the next Laravel upgrade is a
`composer update` with nothing to re-merge.

The `symfony/string/Resources/bin/.placeholder` from §1.2 is also gone and does
not need recreating: that directory is empty AND untracked (git tracks only
`Resources/data/` and `functions.php`), so nothing depends on it existing.

### Container

No changes required - see `CONTAINER_CHANGES.md`. PHP 8.4 and composer 2.10.2
both already clear Laravel 13.

### Downstream

`breaking_changes/laravel_13_upgrade_08_24.txt` (PHP 8.3 floor, plus the four
things that broke in OUR code and could plausibly break in an app's) and
`breaking_changes/array_first_last_removed_08_24.txt`.

---

## 8. Before deleting this directory

This is scaffolding. Once the upgrade is signed off, delete
`app/RSpade/resource/laravel_upgrade/` entirely - the durable knowledge has
already been moved into the two `breaking_changes` documents. The baseline
patches in `baseline_l10/` are only of historical interest and are recoverable
from git history at commit `14f35e20f`.
