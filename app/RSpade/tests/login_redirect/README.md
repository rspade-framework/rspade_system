# Concern: login_redirect

## Domain overview & applicability

`Login_Redirect` is the framework-core helper that threads a validated intended-URL
(`?redirect=`) through a multi-hop login flow, so a user who hit a protected deep URL
while logged out returns to it after authenticating. It is the SINGLE redirect
sanitizer in the codebase (logout uses it too), with a PHP static class and an
identical-API JS mirror. All validation is centralized in one private validator; a
hostile/invalid value degrades SILENTLY to `[]`/the default (a CR-mandated exception
to fail-loud, scoped to this class's read/validate paths).

This concern matters for security (open-redirect prevention) and for the wiring
contract that the prelaunch checklist audits.

## Source files

- `app/RSpade/Core/Login/Login_Redirect.php` - PHP class: `capture`/`params`/
  `hidden_input`/`consume` + the private validator, request classification, and the
  portal context-aware page-path / exclusion rules
- `app/RSpade/Core/Js/Login_Redirect.js` - identical-API JS mirror (rides Core_Bundle;
  the routability gate is server-only, so the JS mirror has the no-op-root rule but
  NOT the routability rule)
- `app/RSpade/Core/Dispatch/Dispatcher.php` (`resolve_url_to_route`) and
  `app/RSpade/Core/Portal/Portal_Dispatcher.php` (`resolve_url_to_route`) - the
  route-registration lookups the routability gate calls (staff / portal context)
- `config/rsx.php` - `login_redirect.excluded_prefixes` (staff loop-prevention) and
  `login_redirect.portal_excluded_prefixes` (portal loop-prevention, namespace-relative)
- `rsx/portal_main.php`, `rsx/portal/auth/Portal_Login_Controller.php`,
  `Portal_Register_Controller.php`, `portal_login_index.blade.php`,
  `portal_register_index.blade.php` - the shipped portal wiring (capture / hidden_input
  / consume)
- `rsx/app/login/login_controller.php` - `logout()` routes its redirect through
  `Login_Redirect::consume()` (the one-sanitizer generalization)
- `app/RSpade/Commands/Rsx/Prod_Enable_Command.php` - prints the prelaunch hint

## Test fixtures

- `php/Login_Redirect_Route_Fixture_Controller.php` - three routable GET surfaces
  (`/test-login-redirect/page`, `/test-login-redirect/other`,
  `/test-login-redirect/item/:id`). The routability gate rejects every `/_`-prefixed
  path, and every framework route is `/_`-prefixed, so the accept half of the matrix
  can only be driven against routes this concern registers. They carry no underscore
  ON PURPOSE, and are indexed only while the suite is running.
- `php/Login_Redirect_Portal_Route_Fixture_Controller.php` - the portal twins, declared
  with `#[Portal_Route]` in portal-namespace terms, because in portal context the gate
  resolves against the PORTAL route table.

## Man page(s)

- `man/login_redirect.txt`
- `man/prelaunch_checklist.txt` (entry #1 audits the wiring contract)

## Testable surface

- Validator accept: plain local path; path + query preserved verbatim. (php - no DB)
- Validator reject: protocol-relative (`//`), absolute URL, `javascript:`/other
  scheme, backslash, control chars/newlines, fragment (`#`), login-flow prefixes
  (`/login`, `/logout`, `/login/2fa`), over-length (>2000), empty, absent. (php - no DB)
- No-op bare root: a target reducing to namespace path `/` with NO query string
  (staff `/` or the portal prefix root) is dropped in both `params()` and
  `capture()`; a bare root WITH a query is kept. (php - no DB)
- Routability gate: the target must resolve to a REGISTERED GET route in the
  active context (staff `Dispatcher::resolve_url_to_route`, portal
  `Portal_Dispatcher::resolve_url_to_route`); an unroutable path is dropped. This
  is route-registration only - NOT a record-existence (404) probe and NOT an
  authorization check; GET only. The portal rejection branch is un-triggerable in
  this template (the app registers a portal `/*` catch-all); the portal ACCEPT
  branch and the staff reject branch are both covered. (php - no DB)
- `capture()` classification: GET page yes (with query preserved); POST no; XHR no;
  `/_ajax/...` no; `/api/vN/...` no; `/login` no. (php - no DB)
- `params()` / `consume()` ambient-request read paths (valid -> value; hostile /
  absent -> default). (php - no DB)
- `hidden_input()`: empty when absent; rendered input for a valid value; HTML
  escaping of a value carrying quotes/ampersand/angle brackets. (php - no DB)
- Portal context (`Login_Redirect_Portal_Test`): prefix-mode namespace rule
  (`<prefix>/...` accepted, prefix stripped; non-prefix path rejected); portal
  exclusion list (login/register/password-reset/impersonate); portal non-page
  remainder (`<prefix>/_ajax`, `<prefix>/api`) rejected; domain-mode unprefixed
  targets; cross-context isolation both ways (staff rejects portal-prefix target,
  portal rejects non-prefix); closed capture/validate asymmetry (validator rejects
  non-page paths); `portal_excluded_prefixes` config override. (php - no DB)
- JS mirror: no house unit-test channel for Core/Js classes; verified by presence in
  the compiled Core bundle (a page render loads it) - see test_catalog.md.
- Logout equivalence over live HTTP: a valid local `?redirect=` is honored; a hostile
  absolute URL degrades to the login default. (http - live server; deferred)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).
