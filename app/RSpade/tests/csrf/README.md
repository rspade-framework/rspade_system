# Concern: csrf

## Domain overview & applicability

CSRF protection for state-changing (POST) requests. `App\RSpade\Core\Session\Rsx_Csrf::enforce()`
is invoked at the staff and portal dispatcher POST seams (`Dispatcher.php:118`,
`Portal_Dispatcher.php:89`), after the cookie-less external-API branch and before route handling,
so one seam covers `/_ajax/:ctrl/:action`, `/_ajax/_batch`, `/_upload`, and native `#[Route(POST)]`.

Two complementary layers:
1. **Origin/Referer** (all POSTs): a present Origin (or Referer) whose host is not this host is
   rejected - closes login-CSRF, which SameSite=Lax cannot. A header-less POST (curl /
   server-to-server) is allowed.
2. **Session-gated synchronizer token**: when a session exists, a token is required and
   constant-time-verified (`X-CSRF-Token` header or `_csrf_token` body field) against the token
   minted once on the `_sessions` row. No session -> allowed (no victim session to forge against).

The mechanism is fully automatic (client attaches the header at the `$.ajax` chokepoint and injects
the hidden field via a global submit listener); application developers do nothing. See `man csrf`.

## Source files

- `app/RSpade/Core/Session/Rsx_Csrf.php` - the enforcement (`enforce`, `__origin_ok`, `__reject`)
- `app/RSpade/Core/Dispatch/Dispatcher.php:117-119`, `app/RSpade/Core/Portal/Portal_Dispatcher.php:89` - call sites
- `app/RSpade/Core/Session/Session.php` / `Portal/Portal_Session.php` - token mint + `verify_csrf_token`
- `app/RSpade/Core/Js/Rsx_Csrf.js`, `Core/Js/Rsx_Jq_Helpers.js` - client transport (native form + ajax)
- `app/RSpade/Core/Providers/Rsx_Bundle_Provider.php` - the realm-aware `@csrf` blade directive
  (portal request -> `Portal_Session` token, else `Session`); a staff token on a portal form is
  both wrong and a leak
- `app/RSpade/Core/Exceptions/Rsx_Exception_Handler.php` - honours the rejection's
  `HttpResponseException` BEFORE the handler chain, so no error handler can turn the documented
  contract into a 500

## Man page

`man/csrf.txt` (the mechanism + zero-end-dev-work contract). Verified accurate against the code
during this pass.

## Testable surface (by type)

- **php** (`Csrf_Enforce_Test`): the `enforce()` decision matrix that CLI can express - the
  Origin/Referer gate, the no-session allow, and the session-present reject branches (missing/bad
  token), plus the two reject shapes (ajax json 200 vs native 419), plus the portal facade path.
- **http** (`csrf_roundtrip.sh`): the ACCEPT path + full dispatcher seam - not expressible in CLI
  because `verify_csrf_token()` needs a real `_sessions` row/cookie. Proves a session-less login
  still works token-less, a token-less session POST is rejected, and a valid token (header AND body
  field) is accepted.
