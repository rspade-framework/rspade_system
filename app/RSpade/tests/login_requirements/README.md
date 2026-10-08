# Concern: login_requirements

## Domain overview & applicability

A login requirement is something a SIGNED-IN identity must do before it is ADMITTED - enroll a
second factor, accept terms, upload a picture. An application declares each one as a
`Login_Requirement_Abstract` class; while one is unmet, every identity reader
(`Session::is_logged_in()`, `get_user()`, `get_login_user()`, `get_login_user_id()` and the
`Portal_Session` twins) answers NOT LOGGED IN except while the request is dispatched to a
surface that requirement lists. The concealment is in the readers and fails CLOSED: a surface
nobody listed sees an anonymous caller.

The outstanding list is computed at sign-in and on a staff site switch, stored on the session
row (`_sessions.login_requirements`), re-evaluated once per request when a pending identity is
refused, and cleared at sign-out. A refused page redirects to the first requirement's screen;
a refused Ajax call answers `requirement_pending` with the destination.

Driven in process. The CLI is never subject to requirements in production, so the tests switch
on the CLI enforcement seam (`Login_Requirements::$_enforce_in_cli_for_testing`) and reset it.
The fixture requirements are INERT until a test names an id in their `$unsatisfied` list: the
test trees are in the manifest for the whole run, and a live fixture would conceal every other
test's identity.

## Source files

- `app/RSpade/Core/Login/Login_Requirement_Abstract.php` - the class an application extends
- `app/RSpade/Core/Login/Login_Requirements.php` - the engine: the list, the concealment
  decision, re-evaluation, steering, `recheck()` / `recheck_user()`
- `app/RSpade/Core/Login/Login_Requirements_Health_Checks.php` - the rsx:health row
- `app/RSpade/Core/Session/Session.php` / `Core/Portal/Portal_Session.php` - the readers'
  concealment, the compute/clear calls on sign-in, site switch and sign-out
- `app/RSpade/Core/Dispatch/Dispatcher.php` / `Core/Ajax/Ajax.php` - surface binding and
  steering; `Core/Js/Ajax.js` - navigation on `requirement_pending`
- `database/migrations/2026_10_07_210411_add_login_requirements_to_sessions.php`

The reference application's requirement (`rsx/app/login/two_factor_enrollment_requirement.php`)
is pinned in the application suite (`rsx/tests/Two_Factor_Enrollment_Requirement_Test.php`).

## Man page(s)

- `man/login_requirements.txt`

## Testable surface

- php: the concealment and its exceptions, re-evaluation, order and destination, both
  dispatch seams, sign-out, impersonation, `recheck_user()`, the portal realm, the health row.
- playwright: a pending user in a real browser landing on the screen from an SPA page
  (planned).
