# Bundle System

## Two-Tier Bundle Architecture

**Module Bundles** (`Rsx_Module_Bundle_Abstract`)
- Top-level entry point bundles rendered on pages
- Can scan directories via `include` paths
- Auto-discovers Asset Bundles in scanned directories
- Cannot include other Module Bundles (fatal error)
- Built via `rsx:bundle:build`

**Asset Bundles** (`Rsx_Asset_Bundle_Abstract`)
- Dependency declaration bundles co-located with components
- NO directory scanning - only CDN assets, NPM modules, direct file paths
- Auto-discovered when Module Bundles scan directories containing them
- Never built standalone - metadata consumed by Module Bundles
- Can use `watch` directories for cache invalidation (e.g., Bootstrap SCSS)

**Render-Time Path Coverage** (`Rsx_Bundle_Abstract::__validate_path_coverage`)
- A rendering bundle must cover the VIEW's directory and the dispatching CONTROLLER's directory
- Waiver 1: a view under `app/RSpade/` never has to be covered
- Waiver 2: when the view is a framework view, the CONTROLLER check is waived too - the app
  controller then contributes only the route, and the bundle is framework-owned end to end
- Waiver 2 is what lets a framework feature on an app route ship its own bundle
  (`Rsx_Api_Docs::page()` -> `_Apidocs_App.blade.php` -> `_Apidocs_Bundle`)

**Include Patterns** (`Bundle_Include_Pattern`)
- A type-filtered directory include, exactly two forms: `<dir>/*.<ext>`, `<dir>/**/*.<ext>`
- The ONE definition of the grammar: `BundleCompiler::_process_include_item()` expands with
  it, and every other reader of an include list (`__validate_path_coverage`,
  `validate_no_directory_scanning`, `rsx:bundle:show`) asks `is_pattern()` - never test
  for `*` by hand
- Malformed, or matching nothing, THROWS naming the pattern; a pattern covers no view
- Matches go through `_add_scanned_files()`, the same per-file step a directory scan uses

**Overridden Files Resolve To Their Override** (`BundleCompiler::_add_file()` / `_effective_path()`)
- `_add_file()` is the ONE seam every include reaches - directory walk, pattern, explicit file.
  A path `Manifest::overrides()` records as SHADOWED (`rsx:man class_override`) is replaced by
  the `rsx/` file that overrides it, in the shadowed file's place in the include order (a
  script sorts, a template registers, where the framework's would have); de-dup then keys on
  the override, so a bundle that also includes the application file gets it once, at its first
  appearance. Every bundle, `Core_Bundle` and `/_sys` bundles included
- A stylesheet is never shadowed (the override map has no scss kind), so every framework
  stylesheet a bundle includes is compiled; an application stylesheet wrapped in the same
  class is compiled alongside it, where its own include puts it
- An overriding MODEL brings its companion (`_override_companion_file()`): when the substituted
  file is a JS-exposed model (`_is_js_exposed_model()`) whose JS class the application declares
  (`_user_js_model_class_file()`, the JS class map), `_add_file()` adds that JS file right after
  it - on every substitution, a de-duplicated one included. These are the two helpers the model
  check in `_generate_concrete_model_classes()` uses, so the substitution supplies exactly the
  file the check would otherwise refuse the bundle for. Consequence: Core_Bundle includes
  `Core/Models`, so an override of a core model reaches EVERY bundle, public ones (login,
  portal sign-in, errors, `/_sys`, `/apidocs`) included - its stub (the ORM definition) and its
  JS class together. A narrowed public bundle still ships them; the application keeps what it
  must not publish out of the override and its JS class (`rsx:man class_override`, BUNDLES)
- What a bundle DECLARES is unchanged, so CONV-BUNDLE-02/04 (which read `define()`) never see
  it; everything downstream of the file list (processors, JS ordering, stubs, the shake, routes)
  receives the override like any application file. A watched shadowed file also watches its
  override. The shadowed file is never compiled and never edited
- `tests/bundles/php/Bundle_Override_Substitution_Test` is the contract as an assertion

**Auto-Discovery Rules:**
- Asset Bundles discovered via directory scan cannot have directory paths in `include`
- If Asset Bundle needs directory scanning, include it explicitly by class name
- Discovery uses `Manifest::php_is_subclass_of()` (NOT filename patterns)

**Model List And Field-Length Script** (`BundleCompiler::read_model_list`, `Core/Database/Field_Length_Script`)
- The app compile writes `build/bundle_models/<Bundle>.json` beside the app script, in every
  mode: `{bundle, app_hash, models}` - the PHP model names whose generated JS stubs are in
  the compiled app script (post-shake), `app_hash` being the hash in `<Bundle>__app.<hash>.js`
- Same staleness gate as the compiled files: a list that is missing or names another app
  script makes the app type stale (`_check_bundle_caches`); a sealed build with no list is
  "not compiled" (`_check_production_cache`); the lists are sealed assets (`Rsx_Prod_Seal`)
- A stub carries NO column lengths. `compile()` answers `field_length_script`
  (`<Bundle>__<app hash>.js`, null when the list is empty) and `__generate_html()` emits
  `<script src="/_field_lengths/<that>?v=<state key>" defer>` AFTER the app script; the route
  (`Field_Length_Controller`, both realms, public) answers only that exact name, with
  `Rsx.__field_length_data = {Model: {column: length}}` built from the list and the live
  schema, cached under the state key
- So a compiled bundle is a function of code alone: a migration changes no bundle

# Bundle Processor System

## Architecture
- **Extension-agnostic BundleCompiler**: Only handles .js and .css files directly
- **Global processor configuration**: Processors configured in `config/rsx.php` under `bundle_processors`
- **Processors receive ALL files**: Each processor examines all collected files and decides what to process
- **No per-bundle processor config**: Processors are globally enabled, not per-bundle

## How It Works
1. BundleCompiler collects ALL files from bundle includes (not just JS/CSS)
2. Each configured processor receives the full list of collected files
3. Processors decide which files to process based on extension or other criteria
4. Processed files replace or augment original files in the bundle
5. Final bundle contains JS and CSS files only

## Creating a Processor
```php
class MyProcessor extends AbstractBundleProcessor {
    public static function get_name(): string { return 'myprocessor'; }
    public static function get_extensions(): array { return ['myext']; }
    public static function process(string $file_path, array $options = []): ?array {
        // Transform file and return result or null to exclude
    }
}
```

## Registering Processors
Add to `config/rsx.php`:
```php
'bundle_processors' => [
    \App\RSpade\Processors\MyProcessor::class,
]
```

## SCSS @import Validation
- **Non-vendor files**: Cannot use @import directives (throws error)
- **Vendor files**: Files in directories named 'vendor' CAN use @import
- **Rationale**: @import bypasses bundle dependency management
- **Solution**: Include dependencies directly in bundle definition