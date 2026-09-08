# auth_gates

Declarative authorization gates: `#[Auth('check', ...)]` / `@auth('check', ...)` on
dispatchable surfaces, `#[Auth_Check]` on Permission methods, and the evaluation
engine behind `Permission::can_access()`.

## Applicability

This concern covers the MACHINERY (how gates are indexed at manifest build time, how
the per-realm check registry is resolved, how named checks are executed), the five
server dispatch seams that enforce them, and the CLIENT EXPORT BUILDERS behind
`window.rsxapp.auth` / `auth_routes`. It does NOT cover the closed-by-default
validation pass, which lands in a later stage and gets its own rows here.

The client-side pieces themselves (the `@auth` decorator, the `Spa.dispatch` gate,
the generated JS Permission mirrors, `Permission.can_access`) are browser behavior:
their payload computation is proven here in php, and the browser half is verified by
`rsx:debug --eval` probes pending playwright automation (see the AG-CLIENT rows).

## Sources under test

| File | Role |
|------|------|
| `Core/Auth/Auth_ManifestSupport.php` | Builds `manifest['data']['auth']`: the per-realm `#[Auth_Check]` registry and the surface index (`build_index()`), then enforces closed-by-default (`validate()`). Owns every build-time fatal. |
| `Core/Auth/Auth_Gates.php` | Evaluation engine: realm detection, LIVE strict execution (no result cache anywhere), `gates_pass()`, `can_access()` target resolution. |
| `Core/Permission/Permission_Abstract.php` | Staff realm root. Built-ins `public()` / `closed()` / `is_logged_in()` / `is_sysadmin()` (the control panel's gate), plus `can_access()`. |
| `Core/Portal/Portal_Permission_Abstract.php` | Portal realm root. Portal twins of the same three. |
| `Core/Dispatch/Route_ManifestSupport.php` | `'auth'` on `#[Route]` rows. |
| `Core/SPA/Spa_ManifestSupport.php` | `'surface'` (the PHP bootstrap's key in `auth.surfaces`) + `'target'` (the JS action class, which is its OWN key in `auth.surfaces`) on SPA rows. |
| `Core/Portal/Portal_Route_ManifestSupport.php` | `'auth'` on `#[Portal_Route]` rows. |
| `Core/Portal/Portal_Spa_ManifestSupport.php` | `'surface'` + `'target'` on portal SPA rows. |
| `Core/Api/Api_Endpoint_ManifestSupport.php` | `'auth'` on `#[Api_Endpoint]` rows. |
| `Core/Dispatch/Dispatcher.php` | Route/SPA seam. Also owns the dev-auth identity (moved here from the app's `Main::pre_dispatch` so gates can see it). |
| `Core/Portal/Portal_Dispatcher.php` | `#[Portal_Route]` seam; portal login redirect on denial. |
| `Core/Ajax/Ajax.php` | Both Ajax entry points (`handle_browser_request`, `internal`). |
| `Core/Database/Orm_Controller.php` | Model fetch + relationship seam; anti-enumeration denial shape. |
| `Core/Api/Api_Dispatcher.php` | `#[Api_Endpoint]` seam; the API's 403. |
| `Core/Auth/Auth_Gates.php` (export) | `export_grants()` / `export_route_grants()`: the whole `window.rsxapp.auth` / `auth_routes` payload. The rsxapp assembler only picks the realm and reads the config flag. |
| `Core/Bundle/Rsx_Bundle_Abstract.php` | Ships those maps into `window.rsxapp` from the identity fork. |
| `Core/Auth/Auth_BundleIntegration.php` | Generates the JS Permission / Portal_Permission check mirrors (Phase 6). |

Behavior of record: `php artisan rsx:man auth_gates`.

## Fixtures

`Auth_Gates_Check_Fixture` supplies callable check bodies (granting, denying,
non-`true` returns, an execution counter, and one identity-dependent body that
answers from `Session::get_user_id()`). It is deliberately NOT a
`Permission_Abstract` descendant and carries no `#[Auth_Check]`, so it can never leak
into an application's real check vocabulary; the evaluation tests reach it through
`Auth_Gates::_set_index_for_testing()`.

`Auth_Gates_Surface_Fixture` is a LIVE surface with real class-level and method-level
`#[Auth]` declarations, so the merge is regression-tested against the manifest the
framework actually builds. It is not an `Rsx_Controller_Abstract` subclass, which
makes it undispatchable and keeps it out of JavaScript stub generation.

`Auth_Gates_Seam_Fixture_Controller` and `Auth_Gates_Portal_Seam_Fixture_Controller`
ARE dispatchable, deliberately: the route seams read their gate list from the manifest
ROUTE ROW, which no test seam can rewrite, so the only honest way to exercise them is a
real route. They own the `/_test/auth-gates/` path space (staff) and
`/_test/auth-gates/portal-gated` (portal), return a marker array, and touch nothing.
Every member carries a gate whose name really exists in its realm, so neither the
closed-by-default pass nor the unknown-name validation has anything to flag.

The Ajax and ORM seams resolve gates from the SURFACE INDEX instead, so their tests
hand them arbitrary lists - including a name defined in no realm - through
`Auth_Gates::_set_index_for_testing()`, and no probe name ever enters the real
registry.

`Auth_Gates_Seam_Fixture_Model` exists for the RELATIONSHIP seam alone.
`Orm_Controller::fetch_relationship()` reaches its gate check only after the model
resolves, its `fetch()` carries `#[Ajax_Endpoint_Model_Fetch]`, and the named
relationship carries both that and `#[Relationship]` - and no framework model declares a
fetchable relationship. The fetch seam itself uses `User_Model`, which is a framework
model with a record-level check of its own, so the two layers are seen composing. No
table is ever touched: the denial these tests assert lands before any model code runs.

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| Gate collection (variadic, class+method merge, dedupe, repeated attribute, bad argument) | php | Synthetic manifest metadata through `Auth_ManifestSupport::process()`. |
| Surface kinds and realms (route/spa/portal_route/ajax/api/model_fetch/model_relationship) | php | Same synthetic driver. |
| Check registry (per realm, most-derived-wins, the six build fatals) | php | Same synthetic driver. |
| Live index wiring (module registered, built-ins present, real override resolved, real merge) | php | Reads the persisted manifest. |
| Evaluation (strict `=== true`, AND semantics, live re-execution, identity-following, unknown-name message) | php | Fixture-backed index. |
| `can_access()` (route target, implied `::index`, action target, ungated, realm-agnostic, unknown, cross-realm) | php | Fixture-backed index. |
| Dispatch-seam enforcement (route/portal/ajax/orm denial + passthrough) | php | `Auth_Gates_Seam_Test`, driven per the two techniques above. |
| API-seam 403 | http | Needs a permanently gated API endpoint (arrives with the annotation passes); verified by live probe until then. |
| Client export builders (grants-only, gateless exclusion, realm scoping, live re-evaluation, config default) | php | `Auth_Client_Export_Test`, fixture-backed index. |
| `@auth` decorator + SPA gate + generated mirrors + JS `can_access` | playwright | Browser behavior; verified by `rsx:debug --eval` probes until automated. |
| Closed-by-default validation (missing gate, unknown name, cross-realm name, `any`-realm resolution, class/member `public` contradiction, batching, message contract) | php | `Auth_Validation_Test`, over a synthetic index (`validate()`) and synthetic file metadata (`build_index()`), plus one row asserting the LIVE index validates. |
