---
name: bundles
description: "RSX module bundles - defining a Rsx_Bundle_Abstract with its include list, the SCSS include-ordering rule, rendering the bundle into a Blade head, JIT compilation, output filenames, npm packages, auto-discovered Asset Bundles, and the 'watch' cache-invalidation key. Use when creating a new module and its bundle, adding a directory or npm package to a bundle's includes, declaring a 'watch' target for a compiled source tree, wondering why a component's JS or SCSS is not reaching the browser, when editing an SCSS partial does not change the compiled output, or when a build fails with \"Bundle 'watch' target does not exist\"."
---

# Bundle System

**One bundle per module** (`rsx/app/(module)`). Bundles compile JS and CSS automatically on web request - there is **no manual build step** and no rebuild command to run after editing a file.

## Defining a bundle

```php
class Frontend_Bundle extends Rsx_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => [
                'jquery',                    // Required
                'lodash',                    // Required
                'rsx/theme/variables.scss',  // Order matters
                'rsx/theme',                 // Everything else from theme - variables.scss stays first
                'rsx/app/frontend',          // Directory - the module's own code
                'rsx/models',                // For JS model stubs
            ],
        ];
    }
}
```

**Include ordering matters for SCSS.** `rsx/theme/variables.scss` must be listed BEFORE the directory includes that consume its variables; a directory include compiles in discovery order, so a variable referenced before it is defined is a compile error. Listing the file explicitly first and then the directory is the idiom - the file is not compiled twice.

`rsx/models` is what puts JS model stubs (enum constants, `field_length()`, `fetch()`) in the browser. A model missing from the bundle is why `Model.fetch()` is undefined in a page's JS.

## Rendering it

```blade
<head>
    {!! Frontend_Bundle::render() !!}
</head>
```

`render()` emits the `<script>`/`<link>` tags for the compiled artifacts. In development the bundle compiles just-in-time on the request that needs it; in a sealed debug/production build the artifacts were compiled once by the build command and are served as-is.

## Output

Compiled artifacts are written under `storage/rsx-build/bundles/` as:

- `{Bundle}__app.{hash}.js` - the module's own code
- `{Bundle}__vendor.{hash}.js` - third-party dependencies
- matching `.css` siblings

The vendor/app split is permanent - there is no single-file merge mode. The hash segment is content-derived, which is what makes the filenames cache-safe and identical across two identical checkouts.

## Asset Bundles and npm packages

Distinct from module bundles: **Asset Bundles are co-located dependencies, auto-discovered** - a component that ships its own asset dependency does not need a module-bundle entry. It is included only when a module bundle scans that directory, which keeps unused components out of every other bundle.

An npm package reaches the browser through an Asset Bundle's `'npm'` map (global variable name -> ES module import statement), never by an import inside a component file:

```php
// /rsx/theme/components/charts/Chart_JS_Bundle.php
class Chart_JS_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return ['npm' => ['Chart' => "import { Chart } from 'chart.js/auto'"]];
    }
}
```

Each entry creates one global accessible from your JavaScript. Details: `php artisan rsx:man npm`.

## `watch`: inputs that are not includes

A file can change a compiled artifact without being compiled into it - the SCSS partial that a vendored entry point `@import`s, the config a generator reads. The compiler keys each artifact on the content of its inputs, so such a file must be declared or its edits produce no rebuild. That declaration is **`watch`**:

```php
class Bootstrap5_Src_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return [
            'include' => ['rsx/theme/vendor/bootstrap_custom.scss'],
            'watch' => [
                'rsx/theme/vendor/bootstrap5/scss',  // Directory - scanned recursively
                'rsx/theme/variables.scss',          // File - watched directly
            ],
        ];
    }
}
```

**`watch` takes the same kinds of path as `include`**: a file or a directory, relative to `base_path()`. **Every target must exist** - one that is neither fails the build naming the declaring bundle class and the path, because a watch list that silently watches nothing is indistinguishable from a correct one until it serves a stale artifact.

**Bucket membership follows the DECLARING BUNDLE, never the watched path.** A `watch` target of a vendor-bucket bundle invalidates the vendor artifact wherever the file lives - which is exactly what makes "compile a vendored source tree, parameterised by a first-party variables file" work. Registration is additive: the same path can be a `watch` target of one bucket and an `include` of the other, and one edit invalidates both.

Note the asymmetry, which is deliberate: an `include` entry IS the bytes of one artifact, so its bucket is a fact about the file's own path; a `watch` entry only feeds an artifact it is not part of, so its bucket is a fact about the bundle that declared it.

## External URLs: `cdn_assets` vs the externals registry

An Asset Bundle's **`cdn_assets`** declares external URLs the bundle **renders into the head** of every page it serves - a CSS framework, a library present everywhere:

```php
class Bootstrap_Icons_Bundle extends Rsx_Asset_Bundle_Abstract
{
    public static function define(): array
    {
        return ['cdn_assets' => ['css' => [['url' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css']]]];
    }
}
```

In development the tag points at the remote URL and the emitter declares its origin to the CSP composer. In both sealed modes the asset is mirrored into `rsx/resource/.cdn-cache/` at build time and served from `/_vendor/` (same-origin) - **a sealed build never downloads at request time; a missing mirror file throws.**

**Anything loaded ON DEMAND goes through the external-resources registry instead** - declare it in a `*.externals.php` file beside the feature and `await Rsx.load_external('identifier')`. Nothing is fetched until code asks for it, and only the registry supports a staff/portal realm split, a readiness handshake and per-entry CSP extras. **There is no `'cdn:'` include prefix** - that syntax was never implemented.

Decision rule: **needed on every page in the head -> `cdn_assets`; needed when a feature is used -> the registry.** Skill `rspade:external-resources`.

Details: `php artisan rsx:man bundle_api`.
