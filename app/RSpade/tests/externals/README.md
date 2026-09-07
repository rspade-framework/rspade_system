# externals

The declarative external-resources registry: `*.externals.php` declaration files, their
consolidation into the manifest, and the PHP read model every consumer (bundle map, CSP
composer, sealed-build mirror step) resolves through.

## Domain

Every external URL a page loads - a CDN library, a vendor widget - is DECLARED beside the
feature that needs it, never injected ad hoc. Three pieces:

1. **Declaration file** (`{name}.externals.php`) - a bare `return` array of
   identifier => spec (`js`, `css`, `integrity`, `mirror`, `realm`, `readiness`, `csp`).
   The manifest scanner gives it its own file kind, `externals.php`, so it is never
   class-scanned, reflected over, or touched by Php_Fixer.
2. **Consolidation** (`Externals_ManifestSupport`) - walks the manifest's files of that
   kind, reads each, validates the shape FAIL LOUD, and writes one ksorted table into
   `data.external_resources`, each entry normalized (defaults applied) and annotated with
   its source file. Identifiers are one flat namespace: a collision names both files and
   breaks the build.
3. **Read model** (`Rsx_Externals`) - `all()`, `get()`, `all_for_realm()`,
   `resolve_url()`, `resolved_map_for_realm()`, `csp_hosts_for_realm()`.

Two invariants govern the whole thing:

- **The CSP whitelist DERIVES from the declarations.** An undeclared external resource is
  a blocked one; policy cannot drift from code.
- **Where the asset is fetched from and what the CSP must permit are separate questions.**
  There is NO development exception: a `mirror:true` entry is served from `/_vendor/` in
  every mode (same filenames as bundle CDN assets), so its origin is in no whitelist
  anywhere, and only `mirror:false` origins stay. An entry's declared `csp` extras describe
  RUNTIME behavior (frames opened, hosts called, the font hosts a `mirror:false` stylesheet
  names) and apply in every mode.

`resolve_url()` is PURE - it names the mirrored file, it never downloads one. Populating
the mirror is a build activity: a development web request, any CLI, or the production
build. Only a SEALED web request is forbidden to download, which is what the download
predicate tests pin.

## The one expiry

The store never expires on its own: it is URL-keyed and content-addressed, so a present
file is by definition the right file, the compile only ever ADDS, and no code path
deletes. `php artisan rsx:cdn_externals:refresh` is the ONE way to move it forward -
EMPTY IT AND RE-RUN EVERY PRODUCER (clear the store, clear the compiled bundle caches so
the CSS localize pass re-runs, re-mirror every declaration, recompile every bundle). It
refuses on a sealed host, where the mirror is the copy that shipped and `rsx:prod:refresh`
is the command. There is no partial `--url` mode: nothing records which stylesheet pulled
in which font, so a partial refresh could not follow the nested files a localized
stylesheet owns.

## The mirror store

`Cdn_Cache` is the store: one flat directory of FILES (`rsx/resource/.cdn-cache/`,
git-tracked), named by THE NAMING RULE - `md5(url) . '_' . <safe basename> . '.' . <ext>`.
Bytes are stored VERBATIM, so an integrity hash means what it says.

CSS is the exception, and the reason the store holds fonts and images at all: a stylesheet
is rewritten on the way in by the node localizer
(`Core/Bundle/resource/localize-css-externals.js`), which splices every remote `@import`
into place and mirrors every remote `url()` into this same store, rewriting it to
`/_vendor/<name>`. A mirrored stylesheet therefore reaches NO external host at render time
- which is what makes a `fonts.googleapis.com` stylesheet's `fonts.gstatic.com` font files
survive the CSP.

The naming rule is implemented TWICE - PHP names what it serves, node names what it writes
- so EXT-49 pins the two implementations against each other. Drift there means a stylesheet
pointing at a `/_vendor/` name PHP will never serve.

## Source under test

| File | Role |
|------|------|
| `Core/Externals/Externals_ManifestSupport.php` | Discovery, validation, consolidation |
| `Core/Externals/Rsx_Externals.php` | Read model / resolver |
| `Core/Manifest/_Manifest_Scanner_Helper.php` | The `externals.php` compound file kind |
| `Core/Js/turnstile.externals.php` | The framework's own declaration (worked example) |
| `config/rsx.php` | `manifest_support` registration |
| `Commands/Rsx/Prod_Build_Command.php` | The build's mirror step (a wrapper over `Cdn_Cache::mirror_externals()`) |
| `Commands/Rsx/Cdn_Externals_Refresh_Command.php` | `rsx:cdn_externals:refresh` - the ONE expiry: empty the store, clear the compiled caches, re-mirror, recompile |
| `Core/Bundle/Cdn_Cache.php` | The mirror store: naming rule, `ensure()`, integrity, the download guard |
| `Core/Bundle/resource/localize-css-externals.js` | The CSS localizer (postcss): `@import` splicing + `url()` mirroring |
| `config/rsx.php` | `cdn_externals.user_agent` - what every mirror download identifies as |
| `Core/Bundle/BundleCompiler.php` | `_create_javascript_externals()` (which mirrors first) / `_detect_bundle_realm()` / `_prepare_cdn_assets()` |
| `Core/Dispatch/AssetHandler.php` | The `/_vendor/` route: `Cdn_Cache::FILENAME_PATTERN` admission and the MIME map |
| `Core/Js/Rsx_External_Resources.js` | Client loader (`load()`, readiness, dev CSP violation catcher) |
| `Core/Js/Rsx.js` | `Rsx.load_external()` facade |

Behavior of record: `php artisan rsx:man external_resources`.

## Testable surface

- **php** - the whole of it: every validation refusal, the applied defaults, realm
  filtering, URL resolution (asserted in development AND in a sealed build, with the SAME
  answer in both - there is no mode exception left), the client map, the CSP directive map,
  the `/_vendor/` route itself (`Vendor_Route_Test`: types, headers, refused names, and the
  mode-appropriate remedy a miss names), and the turnstile entry discovered end to end from
  the real manifest. Plus the compiler's two
  seams into the store (EXT-52/EXT-53), pinned in DEVELOPMENT mode on purpose: both used to
  be gated on `Rsx::is_production()`, so a resource that worked all through development
  could 404 the first time it was sealed. There is ONE code path now, and those two tests
  are what stop the gate coming back.
- **asset** - two things. The CSS localizer, driven end to end over `file://` fixtures in
  a scratch directory (import splicing, what is left alone, store hits, `--no-download`,
  fragment ids, and the PHP/node naming agreement) - the localizer reads `file://` from
  disk precisely so the suite never touches the network. And the baked identifier map
  inside a compiled bundle: BundleCompiler's
  `_create_javascript_externals()` emits `Rsx_External_Resources._define(...)` into every
  bundle tail with the map resolved for THAT bundle's realm. A bundle carries no realm
  flag, so the realm is derived from its include set - a bundle including
  `#[Portal_Route]` controllers is the portal one.
- **http** - one script, `vendor_links_in_development.sh`: a DEVELOPMENT box serves the page
  a sealed box serves. Every external link on `/login` is a `/_vendor/` store name, no CDN
  host survives in the document, the policy names neither a CDN nor a font host, and the
  route actually serves an emitted stylesheet and the localized bootstrap-icons woff2 with
  the right content types. The composed policy itself is the `csp` concern.

## Residual untested surface

The NETWORK half is not exercised: tests never reach the internet, and proving the
request-time refusal end to end would mean sealing this box. What IS pinned is everything
that can silently disagree - the filename both halves compute, the store's write/hit
behaviour through the `$_testing_fetcher` seam, and the guard's pure decision function
(`Cdn_Cache::_download_is_permitted`). The AssetHandler branch that turns a missing
`/_vendor` file into a loud error in a sealed mode is verified by reading (EXT-38).
