---
name: external-resources
description: "Loading an external CDN library, vendor widget or third-party script into an RSX page - declaring it in a *.externals.php file, loading it by identifier with Rsx.load_external(), the readiness contract, the rsx/resource/.cdn-cache mirror store that every mode serves from /_vendor/, and how the Content-Security-Policy derives from the declaration. Use when adding a CDN library or vendor script, using a webfont CDN or a Google Fonts @import in SCSS, wiring Google Analytics or a tag manager, running php artisan rsx:cdn_externals:refresh, committing files a compile added under .cdn-cache, diagnosing a \"Refused to load ... Content Security Policy\" or \"[RSX CSP]\" console warning, a blocked font-src, \"Failed to localize external references\", \"Integrity mismatch for\", \"Failed to download external asset\", \"Missing mirrored external asset\", \"Vendor file not found\", hitting \"Unknown external resource\", being flagged by JS-DOM-01 for document.createElement('script') or $('<script>'), triaging storage/logs/csp_violations.log, or deciding whether to enforce the policy."
---

# External Resources and CSP

**Every external URL a page loads is DECLARED.** Nothing else may inject a `<script>` or `<link>` at an external host - the Content-Security-Policy whitelist is COMPUTED from the declarations, so an undeclared resource is a blocked resource and policy can never drift from code.

Two files, no wiring:

```php
// rsx/app/frontend/reports/charts.externals.php  (NOT under resource/ or public/ - those are manifest-invisible)
<?php

return [
    'chartjs' => [
        'js'    => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'],
        'realm' => 'staff',
    ],
];
```

```javascript
async on_load() {
    await Rsx.load_external('chartjs');     // memoized: tags appended once per page
    this.state.chart = new Chart(this.$sid('canvas')[0], { ... });
}
```

A declaration is **inert** - it costs one CSP source and nothing else. Nothing is fetched until something calls `load_external()`.

---

## Spec keys

| Key | Meaning |
|---|---|
| `js` / `css` | Lists of absolute `https://` URLs. At least one must be non-empty. |
| `integrity` | `url => sha384-...`, keys must be this entry's own URLs. Applies only while still fetched externally. |
| `mirror` | Default `true`. `false` for a vendor endpoint serving DYNAMIC per-client bytes (Turnstile's `api.js`, Google's `gtag.js`) - a local copy would be stale and wrong. |
| `realm` | `staff` / `portal` / `both` (default). A portal-only widget never widens the staff policy. |
| `readiness` | `'onload'` (default) or `['callback_param' => 'onload']` - see below. |
| `csp` | Extra directives describing what the script DOES at runtime (frames it opens, hosts it calls). Applies in every mode. Asset origins derive from `js`/`css` and are never listed here. |

Identifiers are one **flat namespace**, `lowercase_with_underscores`. A duplicate is a manifest-build FATAL naming both files.

**`readiness` callback_param**: the declared URL carries `?<param>=<global_name>`; the loader reads the name out of the URL, defines `window[name]` before appending the script, and resolves when the vendor calls it. **The URL is the single source of truth for the name** - consuming code never mints one.

---

## Decision: registry vs bundle `cdn_assets`

- **`cdn_assets`** (Asset Bundle) = a HEAD asset fetched on **every** page the bundle serves. For a global library or CSS framework.
- **The registry** = an **on-demand** load, fetched only when code asks. For a feature-specific library or widget. Realm split, readiness handshake and per-entry CSP extras exist only here.

---

## Mirroring - there is NO development exception

**One declaration, one URL, in every mode.** A `mirror:true` entry (the default) resolves to `/_vendor/<md5(url)>_<name>.<ext>` on a development box exactly as in a sealed build, so its host appears in NO policy anywhere. `mirror:false` keeps its raw URL and its whitelist entry - it is the only way an asset stays external.

The store is `rsx/resource/.cdn-cache/`: URL-keyed, **git-tracked, a source artifact**. A present file is the right file, nothing self-expires, a compile only ever ADDS - **commit the files a compile adds** (`git status` shows them).

**Who downloads**: development web requests, any CLI, and the production build (`[2/5] Mirroring external assets`, then the compile). A **sealed web request never downloads** - a miss throws naming the file, the store and `rsx:prod:refresh`. A **development** miss is a 404 naming `rsx:cdn_externals:refresh`.

**Fails loud**: an unfetchable URL fails the compile naming it; a stylesheet's unreachable `@import`/`url()` fails it with a JSON failure list (`Failed to localize external references`); a declared `integrity` hash is verified at download in every mode and a mismatch throws. The `integrity` ATTRIBUTE is emitted only for `mirror:false` (a mirrored asset is same-origin).

**The one expiry** is `php artisan rsx:cdn_externals:refresh` - empties the store, clears compiled bundle caches, mirrors declared externals, then compiles **every** bundle (that last step is what makes the store complete). Refuses on a sealed host.

### Webfont CDNs just work

A Google Fonts `@import` in application SCSS, or the same URL as a `cdn_assets` css entry, needs **nothing whitelisted**: the compile mirrors the stylesheet and follows it, downloading every `@font-face` woff2 and rewriting it to `/_vendor/`. `config('rsx.cdn_externals.user_agent')` is a modern browser UA precisely so a font CDN serves woff2 and unicode-range subsets; changing it changes what a URL mirrors (refresh to re-fetch).

**The one case that still needs a declaration**: a `mirror:false` STYLESHEET whose fonts live on another host must name it in its own `'csp' => ['font-src' => ['https://...']]`. Nothing infers a font host from a stylesheet's origin.

---

## CSP

**Always enforcing.** There is one header name (`Content-Security-Policy`) and no observe-only mode: an undeclared resource is a BLOCKED resource. Every refusal is also POSTed to `/_csp-report` and lands in `storage/logs/csp_violations.log` - `tail -f` that while adding a widget. Framework inline scripts carry a **nonce** (`'unsafe-inline'` never appears in script-src); style-src keeps `'unsafe-inline'` and deliberately has **no nonce** (a nonce would make browsers ignore it and break RSX's pervasive inline styles).

**Triage a block.** Every log line has exactly one correct fix:

| Violation | Fix |
|---|---|
| A resource the page loads itself | Declare it in `*.externals.php`, load by identifier |
| An origin a DECLARED script fetches on its own (analytics, tag manager) | `rsx.csp.additional_sources` (widen-only; `object-src` refused) |
| A FONT under a MIRRORED stylesheet | Its fonts were localized, so a `font-src` refusal means the reference was **relative** (nothing resolves those) - make it root-relative |
| A FONT under a `mirror:false` stylesheet | Declare the host in that entry's `csp => ['font-src' => [...]]` |
| An inline `<script>` in app markup | Move the code into a bundled `.js` file - **never** reach for the nonce |
| `eval` / `Function` from framework code | **STOP and escalate** - `'unsafe-eval'` is a framework decision, not an app one |

---

## Pitfalls

- **Declaration file under `resource/` or `public/`** - manifest-invisible, so the identifier throws as unknown. Put it beside the feature's code.
- **`Unknown external resource '<id>'`** - thrown SYNCHRONOUSLY (a typo is a programming error); the message lists every declared identifier.
- **Per-install configuration in a declared URL** - a declared URL is a CONSTANT (the CSP origin and the mirror filename derive from it). Supply ids/keys at runtime through the vendor's own bootstrap, in bundled code.
- **`mirror:true` on a dynamic vendor endpoint** - works in development, breaks in a sealed build.
- **Hand-injecting the tag** - `document.createElement('script')` or `$('<script src=...>')` appended to the head. `rsx:check` flags both at HIGH severity (JS-DOM-01) and the fix is always this skill: declare, then `load_external()`. Two reasons, either one sufficient: an undeclared script is a BLOCKED script under an enforcing policy, and jQuery never inserts a live script node at all - `domManip` disables it and re-executes through `_evalUrl()` (a synchronous XHR plus `globalEval`), so load/error events never fire and SRI is silently bypassed. `<link rel=stylesheet>` is not special-cased; the jQuery form is fine for CSS.
- **Inventing a timeout** because a load "hangs" - the loader has none by design.

Full contract: `php artisan rsx:man external_resources` and `php artisan rsx:man csp`. Worked examples: `system/app/RSpade/Core/Js/turnstile.externals.php` (framework), and `lib/analytics/` in the reference app (`system/app/RSpade/resource/reference_app/lib/analytics/` downstream, `/rsx/lib/analytics/` in the monorepo).
