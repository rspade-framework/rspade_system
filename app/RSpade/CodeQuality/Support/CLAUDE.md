# Code Quality Support Classes

## FileSanitizer - the `sanitize` subsystem of the node service

### Overview
JavaScript sanitization runs over the ONE node service (`Rsx_Node_Service`, on this
process's private `storage/rsx-tmp/node-service-<random>.sock`) rather than spawning 1000+
Node processes during a check. `FileSanitizer` owns marshaling and its cache; it owns NO lifecycle.

### Components
- `FileSanitizer.php` - PHP client (marshaling + cache)
- `resource/sanitize-service.js` - the `sanitize` subsystem module, loaded by the service on
  first use

### Service Lifecycle
Owned entirely by `Rsx_Node_Service`; see `Core/JsParsers/README.md`. Two facts matter here:
1. **Lazy start:** the service starts on the first genuine cache MISS - a fully cached check
   never starts node at all - and the `sanitize` module is loaded only when first used.
2. **Startup failure is fatal and legible:** `Rpc_Startup_Diagnostics` reports what was
   OBSERVED (process exited with its stderr / no socket / stale socket / missing script)
   rather than guessing at a cause. It never suggests `npm install` inside `system/` for a
   downstream operator - that is an owned zone.

### RPC Methods
- `sanitize.sanitize` → `{results: {file: {status, sanitized, original_lines}}}` - batch
  sanitize multiple files

### PHP API
```php
FileSanitizer::sanitize_javascript($file_path);  // Cache first, then the node service
```

### Cache Integration
Cache checked before RPC call - only files with stale cache sent to server for sanitization. Cache directory: `storage/rsx-tmp/cache/js-sanitized/`

### Error Handling
Server failure → fatal error (no fallback). Server must start or code quality check fails.

### Sanitization Process
1. **Remove comments:** Uses `decomment` npm package to strip comments while preserving line numbers
2. **Replace string contents:** Parses with Acorn AST parser, replaces string literal contents with spaces
3. **Preserve structure:** Maintains line/column positions for accurate violation reporting

### Performance Impact
Before RPC: 900+ Node.js process spawns during manifest build (~30-60s overhead)
After RPC: one shared Node.js process, reused across all sanitizations (~1-2s startup
overhead) - and shared with every other build subsystem since the 2026-09-04 consolidation.

## Comment blanking (no RPC)

Two pure-PHP helpers on `FileSanitizer` for rules whose subject IS a string literal, so
the string-blanking `sanitize_javascript()` is the wrong tool:

```php
FileSanitizer::blank_template_comments($content);  // <%-- --%>, {{-- --}}, <!-- -->
FileSanitizer::blank_js_comments($content);        // // and /* */, quote-aware
```

Both replace comment bodies with spaces and keep every newline, so line and column
numbers still address the original file. `blank_js_comments()` tracks quoting, so a `//`
inside `"https://example.com"` is never read as a comment opener. Used by
URL-HARDCODE-01; `sanitize()` itself is unchanged, so no other rule's view moves.

## One service, two subsystems
Both classes in this directory are clients of the ONE node service. See
`/app/RSpade/Core/JsParsers/README.md` for the lifecycle, the private-socket model and how
to add a subsystem, and `/app/RSpade/Core/JsParsers/CLAUDE.md` for the short form.

## Js_CodeQuality_Rpc - the `quality` subsystem of the node service

### Overview
JavaScript linting and this-usage analysis run over the ONE node service, so the babel parser
and acorn stay loaded across the thousands of files a check walks, instead of spawning a Node
process per file.

### Components
- `Js_CodeQuality_Rpc.php` - PHP client (marshaling + caches)
- `resource/quality-service.js` - the `quality` subsystem module, loaded by the service on
  first use

### Service Lifecycle
Owned entirely by `Rsx_Node_Service`; see `Core/JsParsers/README.md`. The service starts on
the first lint or analyze_this that misses its cache, and the `quality` module is loaded only
when first used. A startup failure is fatal and is diagnosed by `Rpc_Startup_Diagnostics`
from what was OBSERVED.

### RPC Methods
- `quality.lint` → `{results: {file: {status, error}}}` - Check JavaScript syntax using Babel parser
- `quality.analyze_this` → `{results: {file: {status, violations}}}` - Analyze 'this' usage patterns using Acorn

### PHP API
```php
Js_CodeQuality_Rpc::lint($file_path);         // Returns error array or null
Js_CodeQuality_Rpc::analyze_this($file_path); // Returns violations array
```

Both call `Rsx_Node_Service::ensure()` FIRST, outside their own marshaling try/catch, so a
service that will not start fails LOUD instead of being reported as "no violations" - which
matters most for `analyze_this()`, whose own failure path deliberately returns an empty
violation list.

### Cache Integration
Both lint and analyze_this have their own caching layers:
- **Lint cache:** Flag files in `storage/rsx-tmp/cache/js-lint-passed/` (mtime-based)
- **This-usage cache:** JSON files in `storage/rsx-tmp/cache/code-quality/js-this/` (mtime-based)

Cache is checked before RPC call - only files with stale cache are sent to the server.

### Error Handling
Server failure → fatal error for lint, silent failure for analyze_this.

### Performance Impact
Before RPC: Thousands of Node.js process spawns during rsx:check (~20+ seconds on first run)
After RPC: one shared Node.js process, reused across all operations (~1-2s startup overhead).
