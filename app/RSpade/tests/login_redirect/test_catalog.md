# Test Catalog: login_redirect

Full catalog of tests worth having for `Login_Redirect` (implemented and deferred).
Implemented tests live in `php/Login_Redirect_Test.php` (staff-context validator +
wiring calls) and `php/Login_Redirect_Portal_Test.php` (portal-context behavior,
prefix + domain modes, cross-context isolation, config override). No database.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| LR-01 | Accepts a plain local path | php | `/dashboard` | `['redirect'=>'/dashboard']` | implemented | 2026-07-23 |
| LR-02 | Preserves the query string verbatim | php | `/frontend/settings/profile_edit?tab=history&x=1` | value unchanged | implemented | 2026-08-03 |
| LR-03 | Rejects protocol-relative | php | `//evil.example` | `[]` | implemented | 2026-07-23 |
| LR-04 | Rejects absolute URL | php | `https://evil.example` | `[]` | implemented | 2026-07-23 |
| LR-05 | Rejects javascript: scheme | php | `javascript:alert(1)` | `[]` | implemented | 2026-07-23 |
| LR-06 | Rejects other scheme | php | `ftp://host/x` | `[]` | implemented | 2026-07-23 |
| LR-07 | Rejects backslash | php | `/path\to\evil` | `[]` | implemented | 2026-07-23 |
| LR-08 | Rejects control chars/newlines | php | `/foo\nbar` | `[]` | implemented | 2026-07-23 |
| LR-09 | Rejects fragment (whole value) | php | `/dashboard#section` | `[]` | implemented | 2026-07-23 |
| LR-10 | Rejects login-flow prefix | php | `/login` | `[]` | implemented | 2026-07-23 |
| LR-11 | Rejects login-flow subpath | php | `/login/2fa` | `[]` | implemented | 2026-07-23 |
| LR-12 | Rejects logout prefix | php | `/logout` | `[]` | implemented | 2026-07-23 |
| LR-13 | Rejects over-length (>2000) | php | 2002-char path | `[]` | implemented | 2026-07-23 |
| LR-14 | Rejects empty string | php | `''` | `[]` | implemented | 2026-07-23 |
| LR-15 | Rejects absent param | php | (no redirect) | `[]` | implemented | 2026-07-23 |
| LR-16 | consume() returns a valid target | php | `/dashboard`, default `/home` | `/dashboard` | implemented | 2026-07-23 |
| LR-17 | consume() returns default on hostile | php | `//evil.example`, default `/home` | `/home` | implemented | 2026-07-23 |
| LR-18 | consume() returns default when absent | php | (none), default `/home` | `/home` | implemented | 2026-07-23 |
| LR-19 | hidden_input() empty when absent | php | (none) | `''` | implemented | 2026-07-23 |
| LR-20 | hidden_input() renders a valid value | php | `/dashboard` | `<input ... value="/dashboard">` | implemented | 2026-07-23 |
| LR-21 | hidden_input() escapes the value | php | `/frontend/settings/profile_edit?q=a&b="x"<y>` | `&quot;`/`&amp;`/`&lt;`, no attribute break-out | implemented | 2026-08-03 |
| LR-22 | capture() returns a GET page target | php | GET `/frontend/settings/profile_edit` | `['redirect'=>'/frontend/settings/profile_edit']` | implemented | 2026-08-03 |
| LR-23 | capture() preserves query | php | GET `/frontend/settings/profile_edit?tab=x&y=2` | value w/ query | implemented | 2026-08-03 |
| LR-24 | capture() ignores POST | php | POST `/settings/onedrive` | `[]` | implemented | 2026-07-23 |
| LR-25 | capture() ignores XHR | php | GET + `X-Requested-With` | `[]` | implemented | 2026-07-23 |
| LR-26 | capture() ignores Ajax-endpoint path | php | GET `/_ajax/Foo/bar` | `[]` | implemented | 2026-07-23 |
| LR-27 | capture() ignores API path | php | GET `/api/v1/contacts` | `[]` | implemented | 2026-07-23 |
| LR-28 | capture() ignores login route | php | GET `/login` | `[]` | implemented | 2026-07-23 |
| LR-29 | JS mirror rides the compiled Core bundle | asset | rendered page JS | `class Login_Redirect` present | deferred (verified manually at CR time; no house JS-unit channel for Core/Js) | 2026-07-23 |
| LR-30 | Logout honors a valid `?redirect=` over HTTP | http | GET `/logout?redirect=/dashboard` | 302 to `/dashboard` | deferred (live-server; validator equivalence covered by LR-16/17) | 2026-07-23 |
| LR-31 | Logout degrades a hostile `?redirect=` over HTTP | http | GET `/logout?redirect=https://evil.example` | 302 to login default | deferred (live-server) | 2026-07-23 |
| LR-32 | Validator rejects Ajax-endpoint path via params() (closed asymmetry) | php | `/_ajax/Foo_Controller/bar` | `[]` | implemented | 2026-07-23 |
| LR-33 | Validator rejects API path via params() (closed asymmetry) | php | `/api/v1/contacts` | `[]` | implemented | 2026-07-23 |
| LR-34 | capture() returns a prefix-mode portal page target | php | portal ctx, GET `/_portal/workspace/5` | `['redirect'=>'/_portal/workspace/5']` | implemented | 2026-07-23 |
| LR-35 | capture() excludes the portal login route | php | portal ctx, GET `/_portal/login` | `[]` | implemented | 2026-07-23 |
| LR-36 | params() accepts a prefix-mode portal page | php | portal ctx, `/_portal/workspace/5` | value | implemented | 2026-07-23 |
| LR-37 | params() rejects a non-prefix path in portal ctx | php | portal ctx, `/login` | `[]` | implemented | 2026-07-23 |
| LR-38 | params() rejects portal login (exclusion) | php | portal ctx, `/_portal/login` | `[]` | implemented | 2026-07-23 |
| LR-39 | params() rejects portal register (exclusion) | php | portal ctx, `/_portal/register` | `[]` | implemented | 2026-07-23 |
| LR-40 | params() rejects portal password reset (exclusion) | php | portal ctx, `/_portal/password/reset` | `[]` | implemented | 2026-07-23 |
| LR-41 | params() rejects portal impersonate subpath (exclusion) | php | portal ctx, `/_portal/impersonate/claim` | `[]` | implemented | 2026-07-23 |
| LR-42 | params() rejects portal non-page remainder (/_ajax) | php | portal ctx, `/_portal/_ajax/anything` | `[]` | implemented | 2026-07-23 |
| LR-43 | params() rejects portal non-page remainder (/api) | php | portal ctx, `/_portal/api/v1/x` | `[]` | implemented | 2026-07-23 |
| LR-44 | params() accepts unprefixed page in domain mode | php | portal domain ctx, `/workspace/5` | value | implemented | 2026-07-23 |
| LR-45 | params() rejects login in domain mode (exclusion) | php | portal domain ctx, `/login` | `[]` | implemented | 2026-07-23 |
| LR-46 | params() rejects register in domain mode (exclusion) | php | portal domain ctx, `/register` | `[]` | implemented | 2026-07-23 |
| LR-47 | Staff ctx rejects a portal-prefix target (isolation) | php | staff ctx, `/_portal/workspace/5` | `[]` | implemented | 2026-07-23 |
| LR-48 | Staff ctx rejects a non-page path via params() (asymmetry) | php | staff ctx, `/_ajax/foo` | `[]` | implemented | 2026-07-23 |
| LR-49 | portal_excluded_prefixes config override honored | php | portal ctx, list `['/custom']` | `/custom` rejected, `/login` accepted | implemented | 2026-07-23 |
| LR-50 | consume() returns a valid portal target | php | portal ctx, `/_portal/workspace/5` | that target | implemented | 2026-07-23 |
| LR-51 | consume() returns default on hostile in portal ctx | php | portal ctx, `//evil.example` | default | implemented | 2026-07-23 |
| LR-52 | No-op bare root dropped via params() (hand-threaded `?redirect=/`) | php | staff ctx, `/` | `[]` | implemented | 2026-08-03 |
| LR-53 | Bare root WITH a query kept via params() | php | staff ctx, `/?tab=activity` | `['redirect'=>'/?tab=activity']` | implemented | 2026-08-03 |
| LR-54 | capture() drops the no-op bare root (no query) | php | GET `/` | `[]` | implemented | 2026-08-03 |
| LR-55 | capture() keeps the bare root WITH a query | php | GET `/?tab=activity` | `['redirect'=>'/?tab=activity']` | implemented | 2026-08-03 |
| LR-56 | Routability gate: a registered SPA target is kept | php | staff ctx, `/dashboard` | value | implemented | 2026-08-03 |
| LR-57 | Routability gate: a registered SPA :id route is kept | php | staff ctx, `/tasks/edit/5` | value | implemented | 2026-08-03 |
| LR-58 | Routability gate: a registered server-rendered (Blade) GET route is kept | php | staff ctx, `/signup` | value | implemented | 2026-08-03 |
| LR-59 | Routability gate: an unroutable target dropped via params() | php | staff ctx, `/does-not-exist-xyz` | `[]` | implemented | 2026-08-03 |
| LR-60 | Routability gate: an undeclared record-style route dropped (NOT a 404 probe) | php | staff ctx, `/clients/5` | `[]` | implemented | 2026-08-03 |
| LR-61 | capture() drops an unroutable target (parity with params) | php | GET `/does-not-exist-xyz` | `[]` | implemented | 2026-08-03 |
| LR-62 | No-op portal root dropped via params() | php | portal ctx, `/_portal` | `[]` | implemented | 2026-08-03 |
| LR-63 | No-op portal root (trailing slash) dropped via params() | php | portal ctx, `/_portal/` | `[]` | implemented | 2026-08-03 |
| LR-64 | capture() drops the no-op portal root | php | portal ctx, GET `/_portal` | `[]` | implemented | 2026-08-03 |
| LR-65 | Portal root WITH a query kept via params() | php | portal ctx, `/_portal?tab=activity` | `['redirect'=>'/_portal?tab=activity']` | implemented | 2026-08-03 |
| LR-66 | Routability gate resolves against the PORTAL table (registered target kept) | php | portal ctx, `/_portal/dashboard` | value | implemented | 2026-08-03 |
| LR-67 | Routability gate: an unroutable portal-prefix target dropped | php | portal ctx, an under-prefix path with no registered portal route | `[]` | deferred (template app registers a portal `/*` catch-all - Portal_Spa_Controller - so every under-prefix path resolves; the portal rejection branch is un-triggerable here. Staff-side rejection proven by LR-59; portal ACCEPT branch by LR-66) | 2026-08-03 |

## Notes

- The JS validator is a line-by-line port of the PHP validator; there is no
  manifest-discovered unit channel for `Core/Js` classes, so JS logic parity is
  argued by construction and the bundle-presence check (LR-29). If a JS unit
  harness for Core classes is added later, port LR-01..LR-15 to it.
- LR-30/LR-31 are the observable end of the logout generalization; the underlying
  validator behavior is proven in-process by LR-16/LR-17, so the http rows are
  deferred rather than blocking.
- 2026-08-03: the validator gained two rules (no-op bare landing root dropped;
  routability gate requiring a registered GET route in the active context). LR-02,
  LR-21, LR-22, LR-23 previously used made-up paths (`/settings/onedrive`,
  `/search`) that the routability gate now correctly rejects; those fixtures were
  re-pointed at a registered route (`/frontend/settings/profile_edit`) with the
  test intent unchanged. New coverage: LR-52..LR-67. Fixture routes were confirmed
  registered via `Dispatcher::resolve_url_to_route` / `Portal_Dispatcher::resolve_url_to_route`.
