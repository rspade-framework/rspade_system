# Core/Ajax - the internal endpoint channel

`#[Ajax_Endpoint]` methods (each with its mandatory `#[Auth]`) called from JavaScript as
`await My_Controller.method({...})`. Contract tier: `rsx:man ajax_error_handling`.

## One core, three callers

`Ajax::execute($controller, $action, $params, $request)` is the whole of calling an
endpoint, and every caller goes through it:

1. `_resolve_endpoint_class()` - one surface-index lookup; ONE uniform refusal for any
   name that is not an endpoint (the specific reason only for a diagnostics caller). The
   refusal is a CODED `AjaxNotFoundException` (`not_found`), never a reported fault.
2. `Auth_Gates::surface_permits()` - the surface's realm first, then its `#[Auth]` gates,
   in the request's realm (the same predicate `Orm_Controller` asks). A denial answers
   `response_auth_required()` when nobody is signed in in the request's realm and
   `response_unauthorized()` when somebody is (the page channel's split); the body never
   runs. Then the portal View-as-Client read-only refusal.
3. Text envelopes (`{__TEXT, raw}`) rehydrated into their value objects.
4. As ONE UNIT - its own Turnstile latch, its own revision transaction, and `request()` /
   the `Request` facade bound to the call's own request, all restored for the calling
   scope afterwards - the controller's `pre_dispatch`, the action, and
   `rsx.post_dispatch`. The controller's `pre_dispatch` runs with NO lock, in an
   enforcement window of its own (`Site_Lock::_run_hook()`; a lock or transaction it leaks
   fails the call). Then the SITE LOCK BOUNDARY (`Site_Lock::_run_request()`): the
   surface's resolved mode (reader by default, `#[Writer]` = write lock + one database
   transaction committed after `rsx.post_dispatch`, `#[Manual_Lock]` = no lock) is taken;
   the enforcement window is the action alone (`Site_Lock::_enforce()`). A call made from
   inside another (`internal()` in an endpoint) runs under the outer call's hold. A thrown
   `Rollback_Response` (`Core/Response`) is caught OUTSIDE the boundary - which has already
   rolled the writer back - and its carried value becomes the call's result, exactly as if
   the endpoint had returned it (an HTTP response is refused as in step 5).
5. A result that is an HTTP response (Symfony `Response`, a `View`, `Responsable`,
   `Renderable` - a redirect, a page, a download) from the action or the controller's
   `pre_dispatch` THROWS: an Ajax call answers a value or a coded result, never a page.
6. A DB transaction the call left open is rolled back, and the call THROWS naming the
   endpoint - its writes inside it are gone (and a writer's whole transaction with them).
7. THE REQUEST HISTORY (`Core/Revisions/Request_History`), for a browser call to a writer
   (`#[Writer]`, or a `#[Manual_Lock]` that takes a site write lock): the call's
   `_transactions` row (minted eagerly by the boundary) records its fingerprint, redacted
   payload and timing inside its transaction, and - for a SUCCESS that carried a
   `_request_ref` - the reference and the envelope outcome `{_success: true,
   _ajax_return_value}` (status 200). A reference this actor already used on this site is
   answered from the history before the endpoint runs: a replay (`_replayed: true`, the
   original `_duration_ms` / `_completed_at`), or `request_ref_reused` /
   `request_response_expired` - thrown as `Request_History_Answer`, so nothing is written,
   and rendered by `call_envelope()`. `_request_ref` never reaches `$params`.

It returns the raw result (a value or an `Rsx_Response_Abstract`) or throws.

| Caller | Transport | Answer |
|---|---|---|
| `handle_batch_request($request)` | POST `/_ajax/_batch` - THE browser transport, every mode | `{"C_<call_id>": envelope, ...}` |
| `handle_priority_request($request)` | POST `/_ajax/_priority` - ONE call to a `#[Priority]` endpoint, which Proxority lets jump its queue | the `_batch` envelope, one key |
| `handle_browser_request($request, $c, $a)` | POST `/_ajax/<Controller>/<action>` - a hand-made request (curl, a shell test) | one bare envelope |
| `internal($c, $a, $params)` | server-side PHP, `rsx:ajax`, tests | the value as plain data, or the coded exception |

`Ajax.js` sends every call to `/_ajax/_batch`, a lone call as a batch of one, and settles
each call from its own `C_<call_id>` key - except a call to a `#[Priority]` endpoint, which
goes alone to `/_ajax/_priority`. `config('rsx.ajax.batching')` (on except in development,
exposed as `window.rsxapp.ajax_batching`) decides only how many calls share a
request: true = the calls of one synchronous turn, up to `Ajax.MAX_BATCH_SIZE` (20) each;
false = one call per request. The transport NEVER merges calls - two identical calls are
two calls (every call is a POST; it cannot know an endpoint is a read). Read coalescing
lives where reads are known: the ORM fetch (`Rsx_Js_Model`) and jqhtml's load coordinator.

The direct URL is a batch of one through the same `handle_calls()`. Its params are the
request body exactly - the JSON object or the form fields - nothing stripped, no query
string merged.

Both are the AJAX channel of `Dispatch/Rsx_Request_Channel`, reached from
`Dispatch/Dispatcher` after the realm preamble (CSRF, dev-auth, the portal's init). There
is no route row for them.

## One per-call path: `handle_calls()`

Every browser call, alone or batched, runs on `call_request()` - the REAL request
(address, headers, cookies, session) carrying that call's parameters as its input, method
POST, no query string - through, in order:

0. **`_request_ref`** is taken out of the call's params (`Request_History::take_ref()`),
   before anything else sees them.
1. **Session lifetime** for the request's own experience: `Session::enforce_timeouts(false)` /
   `Portal_Session::enforce_timeouts(false)`. Not strict - an Ajax call gets
   `rsx.sessions.ajax_grace_minutes` past the limit. A cookie carrying the token the
   browser's latest rotation replaced (`Session::_arrived_on_rotated_token()` - a sign-in or
   sign-out happened while the call was in flight) answers `session_expired` with cause
   `rotated`, and the response sets no session cookie. Once a call gets `session_expired`, every later
   call in the request answers the same envelope.
2. **Site membership**, staff realm only: `Session::enforce_enabled_membership()` ends a
   session whose membership was disabled; the call answers `auth_required`.
3. A `_request_ref` that is not a string of 1 to 128 characters refuses the call as
   `validation` (metadata keyed `_request_ref`).
4. **The realm's `Main::pre_dispatch`**, once PER CALL (`Dispatcher::ajax_main_pre_dispatch()`,
   unlocked and enforced through `Site_Lock::_run_hook()`),
   with `$request` = the call's request and `$params` = `controller`, `action`, `_route`
   `'/_ajax/:controller/:action'`, `_method` `'POST'`, `_handler` the `Ajax` class. A
   non-null answer refuses THAT call: an `Rsx_Response_Abstract` becomes its coded envelope
   with its reason (`response_unauthorized('Account suspended')`); any other value becomes
   `unauthorized` "This request was refused.". Never rendered as a page.
5. `call_envelope()`, with the call's request history input.

Every envelope then carries `_duration_ms` / `_completed_at` (ISO UTC; the recorded values
for a recorded writer, the original ones for a replay) and, whenever one was sent, the
call's `_request_ref`, echoed unchanged (`__stamp_call()`); a whole-request refusal
(`request_refusal()`) stamps them per call too.

**Site locks across a batch.** Every call takes its own lock, inside `execute()`, after its
`Main::pre_dispatch` and controller `pre_dispatch` have run with none; nothing is held
between calls. Calls do not share a read lock: a lock held across calls would be held while
the next call's pre_dispatch hooks run, and those run with no lock (a hook that opens its own
`Site_Lock::write()` section would be refused as an upgrade).

The checks are per call because a call can change who is signed in (a sign-in, a logout, a
site switch) and the next call is judged as the identity it runs under.

`Rsx_Session_Controller::page_view` (the SPA's page-view report) enforces the lifetime
strictly in its own body. A request that signed somebody in carries `_csrf` in its
envelope: the sign-in rotated the browser's tokens, and the client swaps
`window.rsxapp.csrf` for it.

## One envelope

`call_envelope()` builds exactly what both URLs send:

- success: `{_success: true, _ajax_return_value, _server_time, _user_timezone[, _csrf][, console_debug][, flash_alerts], _duration_ms, _completed_at[, _request_ref][, _replayed]}`
- coded result (`coded_envelope()`): `{_success: false, error_code, reason, metadata, ...}`
- anything thrown (`error_envelope()`): the coded exception family keeps its code
  (`AjaxUnauthorizedException` -> `unauthorized`, `AjaxNotFoundException` -> `not_found`,
  `AjaxFormErrorException` -> `validation`, `AjaxQuestionException` -> `question`,
  `AjaxAuthRequiredException` -> `auth_required`; a `Request_History_Answer` refusal ->
  `request_ref_reused` / `request_response_expired`; an `HttpException` 401/403/404 likewise, 429 `too_many_requests`,
  any other status `generic`); everything else is `error_code: fatal` with an `error`
  object - file, line, message, backtrace for a caller `Rsx_Diagnostics` admits, a generic
  sentence and an `error_id` otherwise. `Ajax::is_coded_failure($e)` is the coded/fault
  test (the ORM fetch uses it too).

Every envelope is HTTP 200. Pending flash alerts are read once, after the last call, and
ride on the LAST call's envelope; console messages ride on the call that produced them.

## Whole-request refusals answer every call

A failure that refuses the whole REQUEST - a CSRF failure (`Rsx_Csrf`), a fault outside any
call (`Ajax_Exception_Handler`) - goes through `Ajax::request_refusal()`: a batch gets the
envelope under EVERY `call_id` its body names, side channels included, so no client call
is left unsettled. A direct call gets the bare envelope.

## The batch refuses whole

A batch is refused with a 400 `{"error": ...}` - nothing in it runs - when it holds more
than `config('rsx.ajax.batch_max_calls')` calls (default 100; the client sends at most 20),
or when any call is malformed: every call needs a non-negative integer `call_id` unique in
the batch, a `controller` and an `action` that are PHP identifiers, and `params` that are
an object when present.

## The client settles every call

`Ajax.js`: an answer missing a call rejects that call with `Ajax.ERROR_SERVER`
(`server_error`); an HTTP 5xx rejects every call in the request with `server_error` (logged
via `Debugger.log_error`); status 0 is `network_error`; any other HTTP failure `generic`.
A hydration or side-channel failure for one call rejects that call only. Every Ajax error
is built by `Ajax._error()` and carries `__rsx_ajax_error = true`; the uncaught-Ajax-error
dialog keys on that marker, so it covers whatever promise chain the error travelled (a
generated stub, the ORM fetch, an app's own async wrapper).
