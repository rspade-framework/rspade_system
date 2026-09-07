# Test catalog: turnstile

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-08-12.

## Turnstile_Validate_Test (php, no-db isolation) - the validate() decision matrix

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| turnstile-01 | disabled + the sentinel is accepted | php | enabled=false, `__turnstile=inactive` | returns; latch set | implemented |
| turnstile-02 | disabled + a real token is rejected (stale page) | php | enabled=false, token string, native URI | HttpResponseException, 302 back to the same URL | implemented |
| turnstile-03 | disabled + no field at all is rejected | php | enabled=false, field omitted, native URI | HttpResponseException, 302 | implemented |
| turnstile-04 | the ajax channel gets the json validation contract | php | enabled=false, token, `/_ajax/...` URI | 200 + `_success:false` + `error_code:validation` + `_message` + MESSAGE_FAILED | implemented |
| turnstile-05 | enabled + both keys missing throws, naming them | php | enabled=true, keys null | RuntimeException naming TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY | implemented |
| turnstile-06 | enabled + one key missing names only that one | php | enabled=true, site key set, secret null | RuntimeException naming only TURNSTILE_SECRET_KEY | implemented |
| turnstile-07 | enabled + no token stops with MESSAGE_MISSING | php | enabled=true, keys set, field omitted | rejection carrying MESSAGE_MISSING | implemented |
| turnstile-08 | enabled + the sentinel stops with MESSAGE_MISSING | php | enabled=true, `__turnstile=inactive` | rejection carrying MESSAGE_MISSING | implemented |
| turnstile-09 | enabled + a verified token passes | php | enabled=true, seam=true, token | returns; latch set | implemented |
| turnstile-10 | enabled + a rejected token stops with MESSAGE_FAILED | php | enabled=true, seam=false, token | rejection carrying MESSAGE_FAILED, never MESSAGE_UNAVAILABLE | implemented |
| turnstile-11 | the token may arrive in $params (Ajax shape) | php | seam=true, field only in `$params` | returns; latch set | implemented |
| turnstile-12 | $params outranks the Request body | php | disabled, Request carries a token, `$params` carries the sentinel | returns (the `$params` value decided) | implemented |
| turnstile-13 | reset/set/was_checked latch semantics | php | validate, reset, set | true -> false -> true | implemented |
| turnstile-14 | a REJECTED validation still sets the latch | php | disabled, field omitted | rejection thrown AND `_was_checked()` true | implemented |
| turnstile-17 | a testing SITE key on a strictly-production build throws | php | mode forced to production, dummy pair configured | RuntimeException from site_key() | implemented |
| turnstile-18 | a testing SECRET alone on a production build throws | php | real-shaped site key + dummy secret, mode production | RuntimeException | implemented |
| turnstile-19a | testing keys are exempt in debug mode; real-shaped keys pass in production | php | mode debug + dummy pair / mode production + real-shaped pair | site_key() returns | implemented |
| turnstile-15 | siteverify HTTP failure yields MESSAGE_UNAVAILABLE | php | enabled, unreachable endpoint | rejection carrying MESSAGE_UNAVAILABLE | deferred (needs an injectable HTTP client; the seam substitutes the verdict, not the transport - the fail-closed branch is proven by the code path being the only other exit) |
| turnstile-16 | hostname mismatch is rejected; a testing-key verdict is exempt | php | siteverify body with a foreign hostname / with `metadata.result_with_testing_key` | rejection / pass | deferred (same reason - the verdict seam short-circuits before the body is parsed) |

## Turnstile_Guard_Test (php, no-db isolation) - the completeness guard + rsx.post_dispatch

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| turnstile-20 | rsx.post_dispatch has a registered handler | php | `Event_Registry::has_handlers()` | true (the guard's `#[OnEvent]` was discovered) | implemented |
| turnstile-21 | submitted token + no validation throws | php | fire the event, POST carrying the field, latch clear | RuntimeException naming the incompleteness, `validate()`, and the path | implemented |
| turnstile-22 | the field in $params is equally caught | php | fire the event, field only in `$params` | RuntimeException | implemented |
| turnstile-23 | a validated request passes, twice (idempotent) | php | latch set, fire the event twice | no throw (nested seams fire more than once) | implemented |
| turnstile-24 | a GET is never the guard's business | php | fire the event with a GET carrying the field in the query | no throw | implemented |
| turnstile-25 | a POST without the field passes | php | fire the event, ordinary POST | no throw | implemented |
| turnstile-26 | a payload without a Request fails loud | php | fire the event with no `request` key | RuntimeException (`shouldnt_happen`) | implemented |
| turnstile-27 | an internal call carrying the field hits the guard | php | outer latch SET, `Ajax::internal()` on a non-validating probe with the field | RuntimeException (the sub-call's own fresh latch; no laundering) | implemented |
| turnstile-28 | an internal call restores the caller's latch | php | outer latch SET, `Ajax::internal()` without the field | no throw AND `_was_checked()` still true | implemented |
| turnstile-29 | an internal call does not grant a latch to its caller | php | outer latch CLEAR, `Ajax::internal()` | `_was_checked()` still false | implemented |
| turnstile-30 | each of the five seams fires the event | php | dispatch through Dispatcher / Portal_Dispatcher / Api_Dispatcher / both Ajax entry points | one firing per handler invocation | deferred (each seam needs a full dispatch with routing, session and realm set up; the http tier proves the Dispatcher seam end to end and the internal() seam is covered by turnstile-27) |

## turnstile_live_verify.sh (http) - the enabled path over a real dispatch

Skips unless `TURNSTILE_ENABLED=true` is set in `.env` (a stock install has the feature off).

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| turnstile-http-01 | an enabled install rejects a field-less login POST | http | POST /login, no `__turnstile`, cookie-less jar | 302 back to /login + MESSAGE_MISSING delivered to the page | implemented |
| turnstile-http-02 | the sentinel is rejected while enabled | http | POST /login, `__turnstile=inactive` | 302 back to /login + MESSAGE_MISSING | implemented |
| turnstile-http-03 | the configured secret reaches siteverify | http | POST the secret + a probe token to siteverify | a JSON body carrying `success` (no verdict asserted - it is a property of which dummy secret is configured) | implemented |
| turnstile-http-04 | a solved widget logs in end to end | http | dummy always-pass pair + a real browser token | login succeeds | deferred (needs a browser to solve the challenge - playwright tier) |

## Playwright (not implemented)

| ID | Purpose | Type | Input | Expected | Status |
|----|---------|------|-------|----------|--------|
| turnstile-pw-01 | disabled renders the inert placeholder + `inactive` hidden value | playwright | /login with the feature off | placeholder visible, hidden field = `inactive` | planned |
| turnstile-pw-02 | enabled renders the Cloudflare iframe and fills the hidden field | playwright | /login with the dummy pair | widget iframe present, hidden field carries a token | planned |
| turnstile-pw-03 | a failed submit resets the widget | playwright | Ajax signup form, forced error | the `error` event resets the widget and clears the token | planned |
| turnstile-pw-04 | only one Cloudflare script tag however many widgets render | playwright | a page with two `<Turnstile_Input />` | exactly one api.js script element | planned |
