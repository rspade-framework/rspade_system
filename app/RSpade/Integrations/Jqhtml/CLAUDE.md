# JQHTML Integration - the `jqhtml` subsystem of the node service

## Overview
JQHTML template compilation runs over the ONE node service (`Rsx_Node_Service`, on this
process's private `storage/rsx-tmp/node-service-<random>.sock`) rather than spawning a
Node.js process for each `.jqhtml` file during bundle builds. `JqhtmlWebpackCompiler` owns marshaling and its
mtime cache; it owns NO lifecycle.

## Components
- `JqhtmlWebpackCompiler.php` - PHP client (marshaling + cache)
- `resource/jqhtml-service.js` - the `jqhtml` subsystem module, loaded by the service on
  first use. It is an ES MODULE, because `@jqhtml/parser` is one; the service loads every
  subsystem through dynamic `import()`, so CJS and ESM modules are both fine, and an ESM
  module's handler table is its DEFAULT export.
- `jqhtml-compile` - CLI from the @jqhtml/parser npm package (never called; its presence is
  checked in the constructor as a package-installed assertion)

## Service Lifecycle
Owned entirely by `Rsx_Node_Service`; see `Core/JsParsers/README.md`. The service starts on
the first template that misses its cache - a fully cached bundle compile never starts node at
all - and the `jqhtml` module is loaded only when first used. A startup failure is fatal and
is diagnosed by `Rpc_Startup_Diagnostics` from what was OBSERVED, never from a guess.

## RPC Methods
- `jqhtml.compile` → `{results: {file: {status, result}}}` - Batch compile multiple templates

## PHP API
```php
$compiler = new JqhtmlWebpackCompiler();
$compiler->compile_file($path);    // Cache first, then the node service
$compiler->compile_files($paths);
```

## Compilation Details
- Uses `compileTemplate` from `@jqhtml/parser` directly (not CLI)
- Always compiles in IIFE format with sourcemap support
- Two derived-cache namespaces, one per layer: `jqhtml-parsed` (the parser's raw output,
  written by `JqhtmlWebpackCompiler`) and `jqhtml-compiled` (that output wrapped for the
  bundle, written by `Jqhtml_BundleProcessor` - and it IS the bundle's input file). The
  parser VERSION is the variant on both.
- Throws `Jqhtml_Exception_ViewException` for template errors with line/column info

## Cache Integration
Cache checked before RPC call - only uncached or stale templates sent to server for compilation.
Cache location: `storage/rsx-tmp/derived/jqhtml-parsed/` and
`storage/rsx-tmp/derived/jqhtml-compiled/`, both through
`App\RSpade\Core\Cache\File_Content_Cache`. There is no private cleanup pass: the manifest
build's Phase 7 sweep removes entries whose template (or parser version) is gone.

## Error Handling
Server failure → fatal error (no fallback). Server must start or bundle compilation fails.
Template errors include line/column information for precise error reporting.

## Performance Impact
**Before RPC:** N Node.js process spawns (one per .jqhtml file needing compilation)
**After RPC:** one shared persistent Node.js process, reused across all compilations and
shared with every other build subsystem since the 2026-09-04 consolidation (~1-2s startup
overhead, paid once per build at most).

## ES Module Support
`resource/jqhtml-service.js` is an ES module (uses `import` syntax) so it can use
@jqhtml/parser's ES module exports directly, without a CLI wrapper. The node service loads
every subsystem through dynamic `import()` precisely so a module can be written in whichever
flavour its dependencies require.
