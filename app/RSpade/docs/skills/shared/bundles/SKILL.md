---
name: bundles
description: "RSX module bundles - defining a Rsx_Bundle_Abstract with its include list, the SCSS include-ordering rule, rendering the bundle into a Blade head, JIT compilation, output filenames, npm packages, auto-discovered Asset Bundles, and the 'watch' cache-invalidation key. Use when creating a new module and its bundle, adding a directory or npm package to a bundle's includes, declaring a 'watch' target for a compiled source tree, wondering why a component's JS or SCSS is not reaching the browser, when editing an SCSS partial does not change the compiled output, when a build fails with \"Bundle 'watch' target does not exist\", when writing or editing the bundle of a page that needs no sign-in (a login, signup, forgot-password, password-reset, invite, error or portal sign-in page - Login_Bundle, Errors_Bundle, Portal_Auth_Bundle), adding a component, model or script to such a page, cherry-picking includes by file, using the type-filtered include forms 'dir/*.scss' and 'dir/**/*.scss' (\"Bundle include pattern is not one of the accepted forms\", \"Bundle include pattern matches no files\"), when a page renders unstyled or a component renders empty with a clean console after its bundle was narrowed, when a response fails with \"does not include that model's class\", or when a component is missing or renders empty only in debug or production mode ('the sealed build removed it', the bundle 'keep' list, rsx:bundle:shake:report)."
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

`rsx/models` is what puts JS model stubs (enum constants, `fetch()`) in the browser. **Column lengths are not in a stub**: `Bundle::render()` emits one more tag after the app script, `<script src="/_field_lengths/<Bundle>__<app hash>.js?v=<state key>" defer>`, a framework-served script listing the lengths of exactly the models the compiled bundle carries, read from the live schema - `Model.field_length()` reads it and THROWS when it is absent, so a page that prints a bundle's `/_compiled/` tags by hand instead of calling `render()` has no field lengths. A model missing from the bundle is why `Model.fetch()` is undefined in a page's JS - and a RESPONSE carrying a record of a model the bundle lacks throws when it is unwrapped (`A 'X_Model' record arrived from the server, but the bundle that received it does not include that model's class`), rather than handing the page a plain object. Include the model, or return plain fields.

## The include forms

An entry is a module or bundle alias, a bundle class, **a file**, **a directory** (every file of every type beneath it), or **a type-filtered directory** - one directory, ONE extension, in exactly two forms:

```php
'rsx/theme/*.scss',       // that directory's own .scss files - no subdirectory's
'rsx/theme/**/*.scss',    // its .scss files and those of everything beneath it
```

Nothing else: no brace sets, no `?`, no exclusions, no `*` anywhere else - any other use of `*` fails the build naming the two forms. **A pattern that matches no file fails the build too**, naming the pattern (a renamed directory must not silently empty a bundle). Matches join the bundle at the entry's position, sorted by path; a file named earlier keeps its earlier place, so `variables.scss` listed first stays first. A new matching file is picked up by the next request, like a file added under a directory include. **A pattern covers no view** - the bundle still lists its own `__DIR__`. The recursive form cannot be written inside a `/* */` comment (the sequence ends the comment); use `//`.

A model whose JS class arrives by CLASS REFERENCE rather than by include needs no entry: the generated `Base_<Model>` stub follows the JS class that extends it into every bundle that class reaches, wherever the PHP model file lives (a class-overridden model in `rsx/models` included). So a Core component naming a framework model works in a bundle that includes no models directory at all.

## Public-facing bundles: cherry-pick, by file

**A bundle takes its audience from its LEAST privileged page.** Everything it includes is downloaded by everyone who can load any page that renders it. So the bundle behind a page that needs no sign-in - login, signup, forgot-password, invite acceptance, the error pages, a portal's sign-in pages - is published to anonymous visitors, and **it never includes a shared directory**. `'rsx/theme'`, `'rsx/lib'` or `'rsx/models'` there hands over the whole component library, every model's ORM definition (columns, enums, relationships) and every controller's route and Ajax stubs. The endpoints stay gated; what leaks is the map of the application.

**Which bundles are public is not obvious - enumerate.** For each bundle, list the routes whose layout prints it and the `#[Auth]` on each; one `public` route makes the bundle public. The three that get forgotten:

- **Sign-in pages living inside a signed-in module.** A portal's login/register/reset pages sit in the portal directory and so inherit the portal's bundle - the entire signed-in application, served to somebody with no credentials. Nobody decides this; it is inherited. **They get a bundle of their own.**
- **Error pages.** A 404 is answered for anybody.
- **A page added later** to a module whose bundle was written for another audience.

**The method:**

1. Scan the module's templates for component tags.
2. **Subtract what `Core_Bundle` already provides** - `Rsx_Form`, `Form_Errors`, `Code_Input`, `Turnstile_Input`, `Two_Factor_Challenge`, `Passkey_Sign_In`, `Sso_Buttons`, `Spinner` and the framework's own models reach every bundle. This is the step that makes the job small.
3. Search the module's own JavaScript for `*_Model` and for classes from the shared library.
4. **Include each remaining component BY FILE, never by its directory** (a component's directory usually holds its siblings): its `.jqhtml` and `.js` (and `.scss`), **and both halves of every PARENT** - the JS base class it extends, and the template its `<Define:X extends="Y">` names - plus each script and npm Asset Bundle (by class name) it calls.
5. **Find the theme's global stylesheets at EVERY depth they live at - do not assume one.** `'<dir>/*.scss'` once per directory that holds globals; never `'<theme>/**/*.scss'`, which takes every component's co-located stylesheet and republishes the component inventory as CSS class names.
6. **`__DIR__` is required**, not lax: a bundle must cover the directory of every Blade view it serves. The rule is "no directory from the SHARED trees".
7. Compile, load every route the bundle serves, **and LOOK**.

```php
'include' => [
    'rsx/theme/variables.scss',     // must be first
    'rsx/theme/responsive.scss',
    'Bootstrap5_Src_Bundle',
    'rsx/theme/*.scss',             // the theme's own stylesheets - NOT its subdirectories

    'rsx/theme/components/forms/form_field_abstract.jqhtml',   // the parent, both halves
    'rsx/theme/components/forms/form_field_abstract.js',
    'rsx/theme/components/forms/form_field.jqhtml',
    'rsx/theme/components/forms/form_field.js',

    __DIR__,
],
```

**Adding a feature to such a page REQUIRES adding its files here.** The page does not get the application's components globally: a tag whose files are not in the bundle renders an empty element.

**What is missing, and how it fails:**

| Missing from the bundle | What happens |
|---|---|
| a JS class | throws when the bundle loads, naming itself - trivial |
| an `extends=""` parent template | **silent** - the child renders without the parent's markup |
| a stylesheet | **silent** - a working page that looks wrong |
| a component's files entirely | an empty element and a console WARNING (`Creating X with defaults`), no error |
| a model class behind a `{__MODEL}` response | throws when the response is unwrapped, naming the model |
| the view's own directory | throws at render (`view_not_covered`) |

**A clean console is NOT evidence** - three of those rows print nothing an error check sees. **Screenshot every page before narrowing - each state it can show (validation error, second-factor screen, expired link, 404), light and dark - and compare the images after, pixel for pixel.** Identical is conclusive; "it looked fine" is not. Then exercise what loads lazily: submit the form with a wrong value, open the dialog the page can raise.

**The component shake is not the answer.** A sealed build drops unused jqhtml COMPONENTS and nothing else: plain JS classes, helper functions, model and controller stubs and shared stylesheets are always kept, because any class may be found by a boot-phase sweep - and it does not run in development mode, which an application may be served in. The include list is the only control.

**Internal bundles are deliberately NOT narrowed.** A bundle whose every page is behind a staff sign-in keeps its directory includes: an employee reading the application they work in is not a disclosure, and an include list edited for every new component is cost for no return. **The same goes for a portal's SIGNED-IN bundle** - its users are identified, development convenience wins, and the exposure is minor; only its sign-in pages are split out. Do not "minimise every bundle".

**Worked example** - the reference application's sign-in bundle, `system/app/RSpade/resource/reference_app/app/login/login_bundle.php` (four theme components, one script, one npm bundle, the modal library, no model), beside `system/app/RSpade/resource/reference_app/app/errors/errors_bundle.php` and `system/app/RSpade/resource/reference_app/portal/auth/portal_auth_bundle.php`, which need no application component at all. `system/app/RSpade/resource/reference_app/tests/Public_Bundle_Contents_Test.php` derives every internal name from the manifest and fails when a public bundle's compiled output carries one. Most of what a small bundle still weighs is `Core_Bundle`, which every bundle carries - a public bundle is finished when it names nothing it does not use, not when it reaches a size. `rsx:man bundle_api` (PUBLIC-FACING BUNDLES); the recurring audit is the conformance audit `public_bundle_contents`.

## Rendering it

```blade
<head>
    {!! Frontend_Bundle::render() !!}
</head>
```

`render()` emits the `<script>`/`<link>` tags for the compiled artifacts. In development the bundle compiles just-in-time on the request that needs it; in a sealed debug/production build the artifacts were compiled once by the build command and are served as-is.

## Output

Compiled artifacts are written under `build/bundles/` as:

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

**Every mode mirrors it.** The asset is downloaded into `rsx/resource/.cdn-cache/` (git-tracked - commit what a compile adds) on first compile and the tag points at `/_vendor/<md5(url)>_<name>.<ext>` - a development box exactly like a sealed build, so a `cdn_asset` contributes nothing to the CSP policy anywhere. A declared `integrity` hash is verified at download and a mismatch fails the compile; an unreachable URL fails it too. In a prod mode only the build may download (a miss on a sealed web request throws naming `rsx:build --force`); a development miss is a 404 naming `rsx:cdn_externals:refresh`.

The same localization applies to compiled CSS: a `@import url(https://fonts.googleapis.com/...)` in application SCSS, and the `@font-face` woff2 files the downloaded stylesheet names, are all mirrored and rewritten to `/_vendor/`. A webfont CDN therefore needs nothing whitelisted.

**Anything loaded ON DEMAND goes through the external-resources registry instead** - declare it in a `*.externals.php` file beside the feature and `await Rsx.load_external('identifier')`. Nothing is fetched until code asks for it, and only the registry supports a staff/portal realm split, a readiness handshake and per-entry CSP extras. **There is no `'cdn:'` include prefix** - that syntax was never implemented.

Decision rule: **needed on every page in the head -> `cdn_assets`; needed when a feature is used -> the registry.** Skill `rspade:external-resources`.

Details: `php artisan rsx:man bundle_api`.

## Sealed builds drop the components a bundle never names

In `debug` and `production` (never development) each bundle loses every jqhtml **component** nothing it serves names - the template, the class and the component's own stylesheet together. Everything that is not a component is always kept. A component stays when its name appears as a whole word in a Blade file served with the bundle (or with none), in a non-component JS file of the bundle, as a quoted string in PHP or config, or in the template or class of another kept component; and when its class has a static method or a `@route`. `$('.My_Component')` is a CSS selector and does not count.

**A name assembled at run time is invisible** (`'Select_' + kind + '_Input'`). List those in the bundle:

```php
return [
    'include' => [...],
    'keep' => ['Select_Country_Input', 'Select_State_Input'],
];
```

**A wrong removal shows only when sealed.** Reproduce it in debug mode, where creating the component fails saying "the sealed build removed it"; production carries no list of removed names and renders an empty element. `php artisan rsx:bundle:shake:report [Bundle] [--kept] [--why=Component]` shows the decision in any mode without building. Details: `rsx:man bundle_api`, THE COMPONENT SHAKE.
