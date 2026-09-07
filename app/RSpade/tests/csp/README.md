# csp

The Content-Security-Policy the framework composes for every RSX page, the nonce that makes
it possible without `'unsafe-inline'`, and the violation collector its `report-uri` names.

## Domain

One policy per realm (staff, portal), composed in ONE place - `Rsx_Csp::compose()` - out of
four inputs and nothing else:

1. **Framework defaults**: `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`,
   `frame-ancestors 'self'`, `img-src`/`font-src` with `data:`.
2. **The nonce** - request-scoped and memoized, stamped on every inline `<script>` the
   framework emits (`Rsx_Bundle_Abstract`'s `window.rsxapp` block, `Debugger`'s shutdown-time
   console echo) and exported as `window.rsxapp.csp_nonce` for the client loader. Because the
   Debugger echo runs at shutdown with no Response object left to read, the value MUST come
   from a static, not from the header.
3. **Declared externals** - `Rsx_Externals::csp_hosts_for_realm()`: the asset origins of the
   `mirror:false` entries plus every entry's own `csp` extras. A MIRRORED asset contributes
   nothing in any mode - bundle `cdn_assets`, `mirror:true` externals and the remote
   references inside a compiled stylesheet are all served same-origin from `/_vendor/`. The
   whitelist DERIVES from declarations; it is never hand-written.
4. **Config** - `csp.enabled` (an emergency kill-switch) and `csp.additional_sources` for
   TRANSITIVE externals only (a declared script that loads further scripts of its own).

Invariants the tests exist to hold:

- **`script-src` never carries `'unsafe-inline'`.** The nonce is the whole point.
- **`style-src` never carries a NONCE.** A nonce present makes browsers ignore
  `'unsafe-inline'`, and RSX depends on inline styles pervasively - adding one would break
  rendering everywhere.
- **`additional_sources` is WIDEN-ONLY**, and `object-src` is refused outright: appending to
  `'none'` would loosen hardening while reading like an addition.
- **A directive created by a merge is seeded with `'self'`** - it was previously falling back
  to `default-src 'self'`, so creating it bare would silently narrow the page.
- **There is no `style-src` -> `font-src` heuristic.** A mirrored stylesheet's fonts are
  mirrored with it and are same-origin; a `mirror:false` stylesheet whose fonts live on
  another host declares those hosts itself in its `csp => ['font-src' => [...]]` extras.
- **The header is HTML-only.** A policy governs a document; a JSON envelope, a download or a
  rendition gains nothing and pays bytes. A response already carrying a policy (AssetHandler's
  static HTML) is left alone.
- **The policy ALWAYS ENFORCES.** One header name, `Content-Security-Policy`; there is no
  observe-only mode, and a config left carrying the retired `report_only` key is inert (the
  rsx config is a plain deep merge with no schema).
- **The collector never errors, and it runs under enforcement.** `report-uri` means blocked
  AND reported. No session, no CSRF token, any body: 204 every time, one JSON line appended
  per report.

## Source under test

| File | Role |
|------|------|
| `Core/Csp/Rsx_Csp.php` | Nonce, composer, response stamping |
| `Core/Csp/Csp_Report_Controller.php` | `POST /_csp-report` collector |
| `Core/Dispatch/Dispatcher.php` | Staff header seam (`__transform_response`) |
| `Core/Portal/Portal_Dispatcher.php` | Portal header seam (`dispatch()` funnel) |
| `Core/Bundle/Rsx_Bundle_Abstract.php` | Nonce on the inline script, `csp_nonce` export, `/_vendor/` asset emission |
| `Core/Debug/Debugger.php` | Nonce on the shutdown console echo |
| `Core/Session/Rsx_Csrf.php` | The collector's CSRF exemption |
| `config/rsx.php` | The `csp` block |

Behavior of record: `php artisan rsx:man csp`.

## Testable surface

- **php** - composition: every directive of the base policy, realm isolation, the nonce's
  request scope, the enforcing header name and the kill-switch, the widen-only merge and its
  refusal, and the HTML-only stamping rule. Driven through `Rsx_Externals::$_testing_entries` and `config()`,
  never through whatever the tree happens to declare.
- **http** - the two things only a real response shows: that a dispatched page CARRIES the
  policy with the SAME nonce its inline script was stamped with, and that the collector
  accepts a browser-shaped report (no session, no token, vendor content type) with a 204 and
  one appended log line.
- **playwright** - the one thing curl cannot see now that the policy enforces: a real
  browser refusing an undeclared script. Not implemented yet (CSP-22).
