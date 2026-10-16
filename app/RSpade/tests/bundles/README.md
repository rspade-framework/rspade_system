# Bundles

## Domain

Bundle definition and compilation: `Rsx_Bundle_Abstract` / `Rsx_Module_Bundle_Abstract` /
`Rsx_Asset_Bundle_Abstract` and the `BundleCompiler` that turns a definition into the
`vendor` / `app` JS+CSS artifacts under `build/bundles`.

Five concerns live here.

The first is CACHE CORRECTNESS: a compiled artifact must be
invalidated by every input that can change its bytes. The compiler keys each bucket
on the content hashes of `bundle_files[$bucket] + watch_files[$bucket]`, so a
declared input that lands in no bucket - or in the WRONG bucket - is silent: the
build reports success and serves a stale artifact.

The second is MODEL-STUB REACH: a JS model class must carry its generated
`Base_<Model>` stub into every bundle it reaches. The stub used to be emitted only at the
PHP model file's include position, so a bundle that named the JS class without including a
models directory evaluated `class X_Model extends Base_X_Model` against an undefined base -
an uncaught ReferenceError at bundle-evaluation time, which aborts the script and boots NO
client component in that bundle. Bundles that worked did so by side effect of an unrelated
include, which is the actual defect; a class-overridden model (PHP file moved to
`rsx/models`) removed even that luck from every framework bundle.

The third is EXTERNAL REACH: a compiled stylesheet must name no host but our own.
`_localize_css_externals()` runs every bundle CSS output through the mirror store, so a
remote `@import` is spliced in and an absolute `url()` becomes `/_vendor/<name>` backed by
a real file. A stylesheet that still names `fonts.gstatic.com` is a CSP violation the page
cannot whitelist, and a dependency on somebody else's uptime.

The fourth is the INCLUDE GRAMMAR: an `include` entry is a file, a directory, or a
type-filtered directory in exactly two forms (`<dir>/*.<ext>`, `<dir>/**/*.<ext>`). The
pattern is what lets a bundle served to visitors who have not signed in take a theme's
global stylesheets without the component library beneath them, so its failure modes are the
ones that matter there: a pattern that silently matched nothing would compile a bundle
without its stylesheets and report no error, and a pattern that silently matched more than
its form says would publish files nobody listed.

The fifth is OVERRIDE SUBSTITUTION: a framework PHP class the application overrides stays
on disk under its own name, and a bundle walks the disk. `_add_file()` - the one step a
directory, a pattern and an explicit file all reach - compiles the overriding file in the
shadowed file's place, once, so a bundle whose includes reach the framework file carries the
override's model stub even when no include names the application directory. A shadowed file
that leaked through would carry the framework class's stub instead of the override's. Only
PHP classes are overridable: a framework JS class or template is compiled as found, and
stylesheets are never shadowed - a framework stylesheet compiles beside an application
stylesheet wrapped in the same class. An overriding MODEL brings the JS class
the application declares for it, added right after it, so a bundle naming no application path
(Core_Bundle, a `/_sys` bundle) still satisfies the model check that refuses a model without
its own JS class.

## The argv ceiling

Concatenation is an RPC service rather than a shell command because a shell command could
not carry the file list. `node concat-js.js <output> <file> <file> ...` was assembled into
ONE argv element, and Linux caps a single argument at MAX_ARG_STRLEN (32 pages = 131072
bytes) - a limit that is neither the `getconf ARG_MAX` total nor raised by `ulimit`, and
that behaves as a cliff: one byte under builds, one byte over fails every build. A
downstream application crossed it at 1,310 bundled JS files and every route in that app
returned 500, blaming the innocent file whose addition tipped it over. This monorepo's
largest bundle carries 82 JS files, so no fixture drawn from the template app would ever
reproduce it - CONCAT-04 builds the oversized list deliberately.

## Source under test

- `system/app/RSpade/Core/Bundle/BundleCompiler.php`
  - `_resolve_bundle()` - iterates `include` / `watch` with the declaring bundle in hand
  - `_add_watch_target()` - registers a watch DIRECTORY (recursive scan) or FILE
  - `_bucket_for_path()` - the ONE vendor/app decision
  - `_split_vendor_app()` - buckets `bundle_files`; watch entries are already bucketed
  - `_get_cache_key()` - per-bucket content hash over bundle files + watch files
  - `_localize_css_externals()` - runs each compiled CSS output through the mirror
  - `_prepare_cdn_assets()` - mirrors every `cdn_assets` entry and names its `/_vendor/` file
  - `_get_model_stubs_for_js_classes()` - resolves each JS model class's generated stub from
    the stub registry it extends, wherever the PHP model file lives
  - `_get_js_stubs()` - the one stub-collection path (positional stubs, the extends-resolved
    model stubs, and the four always-included framework controller stubs)
  - `_process_include_item()` - classifies one `include` entry; a pattern expands here, in
    its own position
  - `_add_scanned_files()` - the per-file step a directory scan and a pattern share
  - `_add_file()` / `_effective_path()` - the one seam every include reaches; a shadowed
    framework file is replaced by its override there, and an overriding model brings its
    application JS class (`_override_companion_file()`)
- `system/app/RSpade/Core/Bundle/Bundle_Include_Pattern.php` - the ONE definition of the
  pattern grammar (`is_pattern()`, `parse()`, `expand()`)
- `system/app/RSpade/Core/Bundle/Cdn_Cache.php` - the mirror store and `_localize_css()`
- `system/app/RSpade/Core/Bundle/resource/localize-css-externals.js` - the postcss localizer
- `system/app/RSpade/Core/Bundle/Rsx_Asset_Bundle_Abstract.php` - the `watch` contract docblock
- `system/app/RSpade/Core/Bundle/Concatenator.php` - the concat RPC client (`concat_js`, `concat_css`)
- `system/app/RSpade/Core/Bundle/resource/concat-service.js` - the `concat` subsystem of the
  node service, and the Mozilla source-map merging (banners, the jqhtml 2-line offset,
  identity maps, inline base64 maps, the malformed-map assertion)
- `system/app/RSpade/helpers.php` - `exec_safe()`'s single-argument ceiling guard
- `system/app/RSpade/Integrations/Scss/Scss_Compiler.php` - the SCSS RPC client (the
  framework's ONE sass invocation)
- `system/app/RSpade/Integrations/Scss/resource/scss-service.js` - the `scss` subsystem of the
  node service (sass, the sourcemap path cleanup, and the production autoprefixer/cssnano
  pass)
- `system/app/RSpade/Core/Bundle/Rsx_Bundle_Abstract.php` - the `window.rsxapp` payload (`mode`)
- `system/app/RSpade/Core/Js/Rsx.js` - `Rsx.is_dev()` / `is_debug()` / `is_prod()`
- `system/app/RSpade/Core/JsParsers/Rsx_Node_Service.php` - the ONE node daemon both clients
  reach through (`request()`, `ping()`); its lifecycle is the `js_transform` concern

## Man pages

- `rsx:man bundle_api`

## Testable surface

| Surface | Type |
|---|---|
| A JS model class pulls its generated stub in with no PHP model file in the bundle | php |
| A stub is resolved once however many classes extend it | php |
| A class extending something that is not a registered stub pulls nothing | php |
| A registered stub missing from disk fails the compile loudly | php |
| A compiled bundle's model list: exactly the stubs in the output, rewritten when it and the app script come apart, sealed | php |
| A watch FILE target invalidates the bucket of the bundle that declared it | asset |
| A watch DIRECTORY target invalidates the same bucket (no regression) | asset |
| Invalidation round-trips (revert restores the previous artifact + hash) | asset |
| A path that is a watch target of one bucket and an include of another invalidates BOTH | asset |
| A watch target that does not exist fails the build naming the bundle class and the path | asset |
| An absolute `url()` in a compiled stylesheet becomes a `/_vendor/` mirror name | asset |
| A remote `@import` is spliced in, localized against its own url | asset |
| SCSS include ordering / master assembly | asset (covered indirectly) |
| JS/CSS files are joined in order, bannered, with a decodable inline sourcemap | asset |
| A babel-transformed file is attributed to the developer's own source, not the temp file | asset |
| A file list PAST the 131072-byte single-argument ceiling still concatenates | asset |
| A sourcemap overrunning its file is refused, naming the file | asset |
| A missing input file, and an empty file list, fail loudly | asset |
| The concat daemon spawns on demand and answers a ping | asset |
| `exec_safe()` refuses an over-limit command legibly and passes everything under it | php |
| SCSS compiles expanded with a sourcemap in dev, compressed + postcss'd in production | asset |
| SCSS `@import` resolution (the bundler's master-file mechanism) | asset |
| Invalid SCSS fails loudly and leaves no half-written artifact | asset |
| `<dir>/*.<ext>` takes the directory's own files of one type and no subdirectory's | asset |
| `<dir>/**/*.<ext>` takes every depth, sorted by path, skipping excluded directory names | asset |
| A pattern expands at its own position; an earlier-named file keeps its place | asset |
| A pattern matching nothing, and a malformed pattern, fail the build naming it | asset |
| A new file matching a pattern reaches the next compile with nothing else done | asset |
| A scanned asset bundle may not declare a pattern | asset |
| A shadowed framework file compiles as its override, once, in its place - by directory, pattern and explicit include | php (child build + compile) |
| No module bundle of the running build resolves a shadowed file | php |
| An overridden model brings its application JS class into a bundle naming no application path, once, after its stub | php (child build + compile) |
| `window.rsxapp.mode` names the mode; `Rsx.is_dev()` / `is_debug()` / `is_prod()` answer from it | playwright |
| A pattern covers no view in the render-time coverage check | php (planned) |
| Module bundle cannot include a module bundle | php (planned) |
| Asset bundle discovered via scan cannot declare directory includes | php (planned) |

## Fixture policy

Bundle-cache behavior cannot be observed against the live app's bundles: a real
project's layout can make the broken heuristic accidentally correct (a watched path
that happens to sit under a `/vendor/` directory). Every test here builds a SYNTHETIC
bundle set in a scratch directory under `asset/resource/` (framework-ignored, so the
manifest never indexes the fixture), compiles it through `BundleCompiler`, and removes
it afterwards. The live `rsx/` tree is never touched.

## Negative control (2026-08-24)

The fixture was verified to isolate the defect before the fix was accepted: the
`BundleCompiler.php` change alone was stashed (`git stash push -- <path>`) and the
class re-run against the pre-fix compiler. ALL FOUR tests failed:

```
[FAIL] test_file_watch_target_invalidates_vendor_output_and_round_trips
       editing a watched FILE changes the vendor bundle content hash (and therefore its filename)
[FAIL] test_directory_watch_target_still_invalidates
       editing a file inside a watched DIRECTORY changes the vendor bundle content hash
[FAIL] test_dual_membership_invalidates_both_buckets
       the shared file is a WATCH target of the vendor bundle, so the vendor artifact is invalidated
[FAIL] test_nonexistent_watch_target_fails_the_build
       Expected exception of type 'RuntimeException' but none was thrown
```

Note the DIRECTORY case failing too. That is Defect 2 on its own: pre-fix, a watched
directory living outside any `/vendor/` path could not invalidate a VENDOR bundle
either, no matter that directories were the one target kind that registered at all.
The file/directory distinction was never the whole bug.
