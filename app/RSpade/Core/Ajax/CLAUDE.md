# Core/Ajax - the internal endpoint channel

`#[Ajax_Endpoint]` methods (each with its mandatory `#[Auth]`) called from JavaScript as
`await My_Controller.method({...})`. Contract tier: `rsx:man ajax_error_handling`.

## One core, three callers

`Ajax::execute($controller, $action, $params, $request)` is the whole of calling an
endpoint, and every caller goes through it:

1. `_resolve_endpoint_class()` - one surface-index lookup; ONE uniform refusal for any
   name that is not an endpoint (the specific reason only for a diagnostics caller).
2. `_endpoint_gates_pass()` - the surface's realm first, then its `#[Auth]` gates, in the
   request's realm. A denial answers `response_unauthorized()`; the body never runs.
3. Text envelopes (`{__TEXT, raw}`) rehydrated into their value objects.
4. As ONE UNIT - its own Turnstile latch and its own revision transaction, both restored
   for the calling scope afterwards - the controller's `pre_dispatch`, the action, and
   `rsx.post_dispatch`.

It returns the raw result (a value or an `Rsx_Response_Abstract`) or throws.

| Caller | Transport | Answer |
|---|---|---|
| `handle_browser_request($request, $c, $a)` | POST `/_ajax/<Controller>/<action>` (development) | one envelope |
| `handle_batch_request($request)` | POST `/_ajax/_batch` (debug, production) | `{"C_<call_id>": envelope, ...}` |
| `internal($c, $a, $params)` | server-side PHP, `rsx:ajax`, tests | the value as plain data, or the coded exception |

Both browser transports are the AJAX channel of `Dispatch/Rsx_Request_Channel` and are
reached from `Dispatch/Dispatcher` after the realm preamble (CSRF, dev-auth, the portal's
init). There is no route row for them.

A batched call receives `call_request()`: the REAL request (address, headers, cookies,
session) carrying that call's parameters as its input - never a synthetic request.

## One envelope

`call_envelope()` builds exactly what both transports send, so the same endpoint outcome
is the same wire shape batched or not:

- success: `{_success: true, _ajax_return_value, _server_time, _user_timezone[, console_debug][, flash_alerts]}`
- coded result (`coded_envelope()`): `{_success: false, error_code, reason, metadata, ...}`
- anything thrown (`error_envelope()`): the coded exception family keeps its code
  (`AjaxUnauthorizedException` -> `unauthorized`, `AjaxNotFoundException` -> `not_found`,
  `AjaxFormErrorException` -> `validation`, `AjaxQuestionException` -> `question`,
  `AjaxAuthRequiredException` -> `auth_required`; an `HttpException` 401/403/404 likewise,
  any other status `generic`); everything else is `error_code: fatal` with an `error`
  object - file, line, message, backtrace for a caller `Rsx_Diagnostics` admits, a generic
  sentence and an `error_id` otherwise.

`Ajax_Exception_Handler` (the AJAX channel's error policy) answers a failure outside any
call with the same `error_envelope()`. Every envelope is HTTP 200.

Two batch differences, both deliberate: pending flash alerts are read once, after the
last call, and ride on the LAST call's envelope; console messages ride on the call that
produced them.

## The batch refuses whole

A batch is refused with a 400 `{"error": ...}` - nothing in it runs - when it holds more
than `config('rsx.ajax.batch_max_calls')` calls (default 100; the client flushes at 20), or
when any call is malformed: every call needs a non-negative integer `call_id` unique in the
batch, a `controller`, an `action`, and `params` that are an object when present.

## Site membership

Staff realm only, once per Ajax request: `Session::enforce_enabled_membership()` ends a
session whose membership was disabled, and every call answers `auth_required`.
