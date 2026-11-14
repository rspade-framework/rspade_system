---
name: rsx-stdlib
description: "The shared utility library in PHP and JS - type checks, escape_html / sanitize_rich_text_html / nl2brhtml / html_to_text, debounce, rwlock, deep_equal, Rsx_Storage, Rsx.url_hash_*, the response_* and array_* helpers, Arr::first, Manifest reflection, Rsx_Money for amounts, and the guaranteed third-party tier (brick/money, league/csv, commonmark, libphonenumber, playwright PDFs). Use before hand-writing any utility or installing a package for money, CSV, markdown, phone numbers or PDFs, or for an exact signature."
---

# RSpade standard library

**Always use these built-in functions** — never reimplement debounce, type checking, string escaping, or similar utilities. Consistent use of shared tooling is what keeps this codebase maintainable and predictable.

Before hand-rolling anything, search first:

| Where | What |
|---|---|
| `php artisan rsx:man helpers` | **PHP** globals (`helpers.php`): `response_*`, `array_*`, string/HTML, filesystem/paths, `exec_safe`, formatting, `random_hash`, debug and `shouldnt_happen`. |
| `php artisan rsx:man js_functions` | **JS** globals (`functions.js`, `async.js`, `hash.js`, `error.js`, `browser.js`): type checks and conversion, `escape_html`/`sanitize_rich_text_html`/`nl2brhtml`/`html_to_text`, collections, `sleep`/`debounce`/`rwlock`, `hash`/`deep_equal`, browser helpers. |
| `Manifest.php` (below) | reflection - **check for an existing manifest function before hand-rolling any of it.** |

Those two man pages carry the full rosters with exact signatures. The judgment worth repeating here:

- **`escape_html()` vs `sanitize_rich_text_html()` is a security decision, not a style one.** Escape by default; sanitize only content that is SUPPOSED to be markup (WYSIWYG output). Same names, same rule in PHP. `nl2br()` does NOT escape - plain text with line breaks is `nl2brhtml($text)` (both languages: escape, then `<br />` per line). One line of text in JS needs no markup at all: `.text(value)`. Reading the text OUT of an HTML string in JS is `html_to_text(html)` (inert DOMParser) - never `$('<div>').html(x).text()`, which builds live elements and fetches their images. The sanitizer returns a STRING: markup bound for a declared text column goes through `Type::from_untrusted_encoded()`, never a sanitized string (a string is plain text there).
- **`debounce(fn, delay)` - always use it, never a hand-rolled timer.** The behavioural gotcha: the delay timer starts AFTER your callback completes, so a slow callback cannot stack up behind itself. Decorator form `@debounce(250)` (with the `/** @decorator */` comment). **250 ms is the recommended delay for almost everything** - pick another only for a compelling, stated reason. It runs the first call at once, coalesces every call made during a run or the delay after it into ONE follow-up, and never overlaps runs - exactly what a realtime refetch, a search box or a resize handler wants. Never an in-flight or "run again" flag beside it.
- **`clone()` is SHALLOW** and **`empty(0)` is true** - these globals follow PHP semantics, not JavaScript ones.
- **`hash()` ignores the `$` key by default**, because jqhtml component data carries a jQuery reference that would otherwise hash the DOM. Supplying your own `ignored_keys` REPLACES that default.
- **`deep_equal(a, b)`** is the right "did this actually change" test before a repaint.
- **`rsxrealpath()`, not `realpath()`** - `/rsx` and `system/.env` are symlinks whose logical path is the meaningful one.
- **`random_hash()`** for tokens and keys; never `rand()`/`uniqid()`.
- **`exec_safe()`** is the one subprocess wrapper (explicit bash, merged stderr, real exit status) - but an artisan subprocess goes through `Rsx_Artisan`.
- **`bytes_to_human()`** renders an ACTUAL size. The configured upload ceiling has its own label function.
- JS `rwlock`/`rwlock_read` are IN-PAGE locks; server/cluster locking is `RsxLocks`, a different system.
- **`Arr::first()` / `Arr::last()`, never a global `array_first()`/`array_last()` with a callback** - PHP 8.5 owns those names natively (array only, no callback, no default), so the framework's helpers of that name were removed (`rsx:man helpers`).
- **`$(input).rsx_numeric({decimals, commas, prefix, time})` is the ONE numeric filter** - `.val()` answers the raw number both ways, `time: true` accepts H:MM and answers decimal hours; `Time_Entry_Input` is this filter. An amount of money is NOT a numeric field: it is `Money_Input` (skill `rspade:money`). Hand-rolled digit filtering in a component is never the answer (skill `rspade:jquery-extensions`).
- **QR codes are `Rsx_Qr_Code::generate($text)`** - never a QR package (skill `rspade:qr-codes`).

## The third-party tier

Declared in `rsx.dependencies.exposed_composer` / `exposed_npm` and guaranteed present - never install a duplicate, never hand-roll one of these. Full roster and the "reach for X when" lines: `rsx:man standard_library`.

| Need | Use |
|---|---|
| Money | **`Rsx_Money`** (framework core, PHP + JS, the `money` column kind) - **a float NEVER holds an amount**; skill `rspade:money`. `brick/money` is present for multi-currency work only (a figure carrying its own currency code, conversion) |
| Phone numbers | **`giggsey/libphonenumber-for-php`** + JS `google-libphonenumber` - store E.164; `isPossibleNumber()`, not `isValidNumber()` |
| Spreadsheets (xlsx) | `phpoffice/phpspreadsheet` |
| CSV, read and write | **`league/csv`, for ALL CSV** - never hand-roll one; a grid export is `DataGrid_Abstract::build_csv()` (`rsx:man csv_exports`) |
| Markdown | **`league/commonmark`** - never hand-roll a converter; `Markdown_Rendition::render()` is the framework's sanitised call and what UPLOADED markdown goes through |
| HTML sanitising | `ezyang/htmlpurifier` (behind `sanitize_rich_text_html()`) |
| Outbound HTTP | `guzzlehttp/guzzle` |
| PDF generation | headless Chromium via the shipped `playwright` package (`page.pdf()`; `<a href>` kept as PDF links) - **in BOTH container images, production included**; never strip its libraries from the prod target, never add a second browser or a PDF package (`system/app/RSpade/resource/docker/README.md` lists every image dependency and why) |

PHP extensions are the same kind of promise: imagick, gd, gmp, zip, zlib, zstd, redis, ldap, imap, sodium, intl, bcmath, soap, pdo_mysql. **pcntl is CLI-ONLY** - never reach for it in a web request.

---

## Rsx_Storage - scoped browser storage

Scoped sessionStorage/localStorage with automatic fallback. Keys are auto-scoped by session/user/site/build, so one user's cached view never leaks into another's and a new build invalidates cleanly.

```javascript
Rsx_Storage.session_set(key, value);   // cleared on tab close
Rsx_Storage.session_get(key);
Rsx_Storage.session_remove(key);

Rsx_Storage.local_set(key, value);     // persists across sessions
Rsx_Storage.local_get(key);
Rsx_Storage.local_remove(key);
```

**Non-critical data only** (cached data, UI state). **Graceful degradation in private browsing** - storage may silently be unavailable, so nothing correctness-bearing may live here. Details: `rsx:man storage`.

---

## URL hash state

Persist UI-only view state (filters, tab selection, dropdown values) to the URL hash so a refresh preserves the view. Format `#key=value&key2=value2`, URL-encoded. Uses `history.replaceState`, so no history entry is added per change.

| Function | Purpose |
|---|---|
| `Rsx.url_hash_get(key)` | single value or `null` |
| `Rsx.url_hash_get_all()` | all params as an object |
| `Rsx.url_hash_set_single(key, value)` | write one key (`null`/`''` removes it) |
| `Rsx.url_hash_set({k1: v1, k2: null})` | bulk write/remove |
| `Rsx.Route(action, params, {k: v})` / `Rsx::Route($action, $params, ['k' => $v])` | a LINK landing on hash state, same encoder (never append `'#...'` by hand) |

**Convention — only persist deviations from defaults** so the URL stays clean:

```javascript
// Write: omit when the value matches the default
Rsx.url_hash_set_single('status', val !== DEFAULT_STATUS ? val : null);

// Read: fall back to the default when absent
this.args.status = Rsx.url_hash_get('status') || DEFAULT_STATUS;
```

**Use for**: filter/tab/view state that should survive refresh.
**Don't use for**: data scoping (use `$extra_params`), default values (apply on init, don't write), required state (use route params).

A write that CHANGES the hash in answer to a person's input (a fresh user activation) counts as a page view for the idle sign-out; a hash code rewrites on its own does not.

Details: `rsx:man url_hash` (IDLE TIME).

---

## Manifest reflection (PHP)

The manifest is a JIT index of all application code, so reflection questions are answered from an index rather than by loading classes. **Always check `Manifest.php` first for any reflection operation** - a function usually already exists.

### PHP class queries

| Function | Returns |
|---|---|
| `Manifest::php_is_subclass_of(string $sub, string $super): bool` | traverses the FULL chain, not just the direct parent |
| `Manifest::php_get_extending(string $parentclass): array` | concrete classes extending that parent |
| `Manifest::php_get_subclasses_of(string $class, bool $concrete_only = true): array` | direct subclasses |
| `Manifest::php_get_lineage(string $class): array` | the full ancestry chain |
| `Manifest::php_is_abstract(string $class): bool` | |
| `Manifest::php_find_class(string $name): string` | the class's file path |
| `Manifest::is_php_model_class(string $name): bool` | |

### JavaScript class queries

`Manifest::js_is_subclass_of()` · `js_get_extending()` · `js_get_subclasses_of()` · `js_get_lineage()` · `js_find_class()` - same intent as the PHP twins.

Two asymmetries worth knowing (verified against `Core/Manifest/Manifest.php`): `js_get_subclasses_of(string $class)` takes **no `$concrete_only` argument**, and there is **no `js_is_abstract()`**.

### File lookup

`Manifest::get_path_by_filename(string $filename): string` - quick lookup by filename; the filename must be unique within `/rsx/`.
