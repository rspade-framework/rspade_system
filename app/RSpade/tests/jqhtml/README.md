# JQHTML

## Domain

The jqhtml template pipeline: the vendored `@jqhtml/parser` compiler that turns a
`.jqhtml` file into a registered template, the framework wrapper that caches and
concatenates that output into a bundle, and the `@jqhtml/core` runtime that turns the
resulting DOM into live components.

Two concerns live here, both of them about SILENT wrongness:

1. **Compiled output must be valid JavaScript.** The compiler builds a template's
   sourcemap from the SOURCE line count, but the bundle concatenator materializes every
   generated line the map names. A map that overruns its file therefore injects bare
   `undefined` identifiers into the bundle - top-level JS that throws on execution, with
   nothing in the compile step reporting a problem.
2. **A component mounted from inside a render cascade must be live.** Dynamic
   `.component()` creation on a hand-appended node is a real pattern (the form loading
   overlay is the framework's own use of it). If the runtime ever returned something
   inert there, nothing would say so - the element would simply stay empty.

## Source under test

- `system/node_modules/@jqhtml/parser/dist/compiler.js`
  - `generateSourcemapForWrappedCode()` - the mapping list, bounded by the OUTPUT
- `system/app/RSpade/Core/Bundle/resource/concat-service.js`
  - `assert_sourcemap_line_count()` - refuses a map that overruns its file
  - `concatenateFiles()` - `SourceNode.fromStringWithSourceMap` is what materializes phantoms
- `system/app/RSpade/Integrations/Jqhtml/JqhtmlWebpackCompiler.php` - RPC compile + cache
- `system/app/RSpade/Integrations/Jqhtml/Jqhtml_BundleProcessor.php` - compiled file into the bundle
- `system/node_modules/@jqhtml/core/dist/jqhtml-core.esm.js` - `jQuery.fn.component`, the render cascade
- `system/app/RSpade/Core/Forms/Rsx_Form.js` - `_sync_loading_overlay()`, the canonical cascade mount

The probe drives the framework control panel at `/_sys` and registers its components in
the browser at runtime, so it names no application screen, modal or bundle. A page under
the test tree could not serve it: the test trees enter the manifest only while `rsx:test`
is running, and the probe drives the ordinary web server.

## Man pages

- `rsx:man jqhtml`
- `rsx:man bundles`

## Testable surface

| Surface | Type |
|---|---|
| An empty-body Define with a long comment header compiles to a bounded sourcemap | asset |
| That compiled template concatenates without phantom `undefined` identifiers | asset |
| A sourcemap that overruns its file is REFUSED by the concatenator, naming the file | asset |
| A component mounted from inside a render cascade paints its template | playwright |
| The form loading overlay paints its spinner with no deferral | playwright |
| An unregistered component name resolves to the base component class | playwright |
| Template inheritance (`extends=`) codegen | asset (planned) |
| Slot data (`content('row', record)`) codegen | asset (planned) |
