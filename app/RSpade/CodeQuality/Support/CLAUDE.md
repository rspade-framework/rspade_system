# Code Quality Support Classes

## Source_Cache - the ONE reader, tokenizer and parser of a pass

`Source_Cache.php` is where a code-quality pass reads a file, tokenizes it and parses it.
Nothing else does: a rule that called `file_get_contents()`, `token_get_all()`,
`PhpToken::tokenize()` or `new ParserFactory` kept the result in a static array for the life
of the process, and those arrays were **1.12 GB of a 1,237 MB cold build** - memory
proportional to FILES PARSED rather than to the index being produced.

```php
$this->source()->content($path)                  // raw bytes, '' when unreadable
$this->source()->tokens($path)                   // PhpToken::tokenize() output
$this->source()->ast($path)                      // one nikic parse, null on a syntax error
$this->source()->class_node($path, $class)       // that class's own AST node, memoized by name
$this->source()->declared_members($path, $class) // an AST-FREE summary of the class
$this->source()->token_array($code)              // token_get_all() over a string in hand
$this->source()->php_tokens_of($code)            // PhpToken::tokenize() over a string in hand
$this->source()->forget($path)                   // after a write, before the next read
```

Each of the path-taking calls accepts the file's manifest HASH as a trailing argument; pass it
when you have the metadata, because the hash is what says the bytes moved.

### `declared_members()` - the one bucket the LRU does not evict

`declared_members($path, $class)` returns a flat summary holding **no nikic nodes**: per method
`name, line, abstract, static, visibility, attributes, has_body, parent_calls, exceptions`, and
per property the same plus `has_default` and the LITERAL `default`.

**Not evicting it is the point, not an oversight.** The four ancestry rules
(PHP-PARENT-CHAIN-01, SEALED-01, REVISION-01, POLY-01) each iterate the whole index and ask
structural questions about a class and its ancestors. A 16-entry LRU cannot hold 700 files, so
each rule paid for its own full parse of the tree: four passes, **5,212 ms of a 10.3 s cold
build**. Sharing the AST could not fix that - the AST is exactly what the budget forbids
retaining. A summary is proportional to the INDEX, which is what the budget permits to grow,
so the file is parsed ONCE for the whole pass and every later question is answered from the
summary. **5,212 ms -> 1,532 ms, cold build 10.3 s -> ~6.5 s, for +4.1 MB of peak.**

Per rule afterwards: SEALED-01 and PHP-PARENT-CHAIN-01 hold no AST at all; REVISION-01 bails on
the summary before parsing (3 ms); POLY-01 bails on a `str_contains($contents, 'morph')` content
test and parses only the files that do make morph calls (158 ms). PHP-PARENT-CHAIN-01 is the one
that still parses, because it runs first and its 1.37 s IS the single tree-wide parse the other
three then ride on for free.

One measured refinement worth keeping: computing `parent_calls` with a `NodeFinder` traversal
per method made PHP-PARENT-CHAIN-01 *worse* (3,153 ms), because every method paid for a walk
where only methods with a declarer used to. Two string tests decide it now - does the FILE
contain `parent::`, then does the method's own line range - and the AST stays authoritative for
the ones that pass.

**The budget it implements** (owner ruling): peak memory is proportional to the index plus a
constant bounded by the LARGEST SINGLE FILE, never to the number of files parsed. So it is an
LRU with a hard entry bound per bucket - a pass may read ten thousand files and hold a handful
at once - and `release()` empties it at the end of the pass. Nothing in it is static.

**Capacity is measured, not chosen.** `Manifest_Build::BUILD_SOURCE_CACHE_CAPACITY` is 16, and
the class default is 64. On this reference tree a cold build peaks at 124.8 MB with 64,
110.1 MB with 32 and 105.9 MB with 16, and the wall time does NOT get worse as it shrinks
(12.0 s / 11.4 s / 11.2 s) - the allocation an eviction avoids costs more than the re-parse it
causes. Below 16 it turns: 8 thrashes to 15.4 s. Re-swept on a cold build after the member
summary landed and 16 is still the answer (16: 99.1 MB / 11 s; 32: 105.4 MB / 12 s; 64:
120.1 MB / 16 s).

**The build shares one instance.** `Manifest::build()->source_cache()` is the manifest build's,
and both `Php_Fixer` (phase 2) and `Manifest_Rule_Driver` (phase 7) read through it, so a file
read for one is not read again for the other. `rsx:check` has no build in flight and gets one
of its own.

**Failure posture**: an unreadable file yields `''`/`[]`/`null` rather than throwing (a build
routinely names files that vanished between the scan and the check), and so does a file with a
syntax error - the lint stage is what reports syntax, and a rule must not turn an unparseable
file into its own kind of failure.

## RuleDiscovery - one discovery per process

`RuleDiscovery::rule_files()` returns `[fqcn => absolute path]` for every rule class, memoized
for the process; `discover_rules()` instantiates them. The walk is a
`RecursiveIteratorIterator`, and that matters: the predecessor was
`glob('Rules/**' . '/*.php')`, **PHP's `glob()` has no `**`**, and the pattern matched exactly
one directory level by accident. A rule filed two levels deep would have been silently never
discovered and never run. `Rule_Discovery_Depth_Test` writes one and proves it is found.

`file_for($fqcn)` is what the driver fingerprints a rule by.

## Validation_Ledger - the ONE store of "already passed"

See `rsx:man code_quality`, THE VALIDATION LEDGER, for the contract. What is worth repeating
here: **the DRIVER writes it, not the rule.** Every entry is filed under
`"<RULE ID>@<md5 of the rule's file . fingerprint_extra()>"`, so editing a rule retires every
verdict it recorded.

**A CROSS-FILE rule lives in the DERIVED bucket, which the prune does not govern** (shape
version 2): one slot per rule holding the current key rather than a set - `deps`, the
fingerprint of its `depends_on()` inputs taken over the tree WITHOUT the test trees, plus
`tdeps` under a test run, the same fingerprint over both halves. Both must match for the rule
to be skipped. Filing that premise among the FILE HASHES is what had
`__prune_against_manifest()` delete it on every flush: the cross-file skip had never once
fired across processes, so every build re-ran every cross-file rule.

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
- `sanitize.sanitize` -> `{results: {file: {status, sanitized, original_lines}}}` - batch
  sanitize multiple files

### PHP API
```php
FileSanitizer::sanitize_javascript($file_path);  // Cache first, then the node service
```

### Cache Integration
Cache checked before RPC call - only files with stale cache sent to server for sanitization.
Cache location: the shared derived cache, namespace `js-sanitized`
(`storage/rsx-tmp/derived/js-sanitized/`), through
`App\RSpade\Core\Cache\File_Content_Cache` - keyed by the file's build hash, with the
source-mtime guard kept on top of it.

### Error Handling
Server failure -> fatal error (no fallback). Server must start or code quality check fails.

### Sanitization Process
1. **Remove comments:** Uses `decomment` npm package to strip comments while preserving line numbers
2. **Replace string contents:** Parses with Acorn AST parser, replaces string literal contents with spaces
3. **Preserve structure:** Maintains line/column positions for accurate violation reporting

### The sanitized copy is what `rsx:check` hands a rule as `$contents`
The manifest-time pass hands the RAW bytes instead. A rule that needs the other one asks for
it by name through `$this->source()`.

### Performance Impact
Before RPC: 900+ Node.js process spawns during manifest build (~30-60s overhead)
After RPC: one shared Node.js process, reused across all sanitizations (~1-2s startup
overhead) - and shared with every other build subsystem since the 2026-09-04 consolidation.

## Comment blanking - ONE stripper per language family

`FileSanitizer` owns every comment stripper the framework has, and there is exactly one per
language family. Nothing else may hand-roll one:

```php
FileSanitizer::sanitize_php($content);             // whole PHP file, via the tokenizer;
                                                   // returns the LINES, not a string
FileSanitizer::blank_php_comments($fragment);      // a PHP FRAGMENT - one line - where the
                                                   // whole-file tokenizer cannot be used;
                                                   // `#` counts as a line comment here
FileSanitizer::blank_template_comments($content);  // <%-- --%>, {{-- --}}, <!-- -->
FileSanitizer::blank_js_comments($content);        // // and /* */, quote-aware
FileSanitizer::blank_scss_comments($content);      // // and /* */, url()- and quote-aware
```

All of them BLANK rather than delete: comment bodies become spaces and every newline survives,
so line and column numbers still address the original file. The last three share one
line-preserving scanner.

**Eight hand-rolled implementations became calls to these**, and two defects came out with
them. Every hand-rolled SCSS stripper was `preg_replace('#//.*$#m', '', ...)`, which truncates
a line at the `//` of an unquoted `url(https://fonts.googleapis.com/...)` - the shared scanner
steps over an unquoted `url()` and tracks quoting. And the four template/blade strippers
DELETED their comments where the sanitizer blanks them, so every line number computed
downstream was off by the height of each multi-line comment above it.

`sanitize_javascript()` (the RPC path, which additionally blanks string CONTENTS) is unchanged
and is still what `rsx:check` hands a JS rule as `$contents`. A rule whose subject IS a string
literal - URL-HARDCODE-01 - reads the raw bytes and blanks comments itself with the helpers
above.

## One service, two subsystems
Both node-service clients in this directory are clients of the ONE node service. See
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
- `quality.lint` -> `{results: {file: {status, error}}}` - Check JavaScript syntax using Babel parser
- `quality.analyze_this` -> `{results: {file: {status, violations}}}` - Analyze 'this' usage patterns using Acorn

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
- **Lint cache:** the shared `Validation_Ledger` (`storage/rsx-tmp/persistent/validation_ledger.php`),
  under the rule id `JS-LINT`, keyed by the file's sha1 - the same hash the manifest keys a
  file by, so a verdict survives a manifest clear. (`PHP-LINT` is the PHP stage's key.)
- **This-usage cache:** none. `analyze_this()` returns a VIOLATION LIST, which is not worth
  storing - the driver banks the CLEAN verdict in the shared `Validation_Ledger` under
  `JS-THIS-01@<the rule's fingerprint>`, and the few files that are not clean are re-analyzed.

Cache is checked before RPC call - only files with stale cache are sent to the server.

### Error Handling
Server failure -> fatal error for lint, silent failure for analyze_this.

### Performance Impact
Before RPC: Thousands of Node.js process spawns during rsx:check (~20+ seconds on first run)
After RPC: one shared Node.js process, reused across all operations (~1-2s startup overhead).
