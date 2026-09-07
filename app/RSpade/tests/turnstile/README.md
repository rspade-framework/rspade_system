# Concern: turnstile

## Domain overview & applicability

Cloudflare Turnstile - the CAPTCHA replacement - as a framework-core feature: a widget
component, a server validator, and a completeness guard that makes forgetting the
validator impossible to ship.

Activity is decided by ONE input, `config('rsx.turnstile.enabled')`. Application mode has
no bearing on it. That single switch is what lets an endpoint call
`Rsx_Turnstile::validate()` unconditionally: the widget ALWAYS posts the fixed field
`__turnstile`, carrying the sentinel `inactive` when the feature is off and a Cloudflare
token when it is on. Enabled with either key missing is a half-configured install and
throws rather than degrading.

Three behaviours define the concern:

1. **The validator** (`Rsx_Turnstile::validate($request, $params = null)`), called at the
   TOP of a POST branch so it gates enumeration too. A failure STOPS the request rather
   than accumulating into field errors, and is shaped for the channel it arrived on:
   `AjaxFormErrorException` inside `Ajax::internal()`, the ajax json validation contract on
   `/_ajax`/`/_upload`, flash + redirect-back on a native POST. The message always rides
   the summary key `_message` (`Form_Utils._normalize_errors()` drops `_`-prefixed field
   keys). Network trouble fails CLOSED with its own distinct message.
2. **The request latch** (`_reset_request_state` / `_set_request_checked` / `_was_checked`),
   set by validate() BEFORE a verdict is reached - a validator that ran and REJECTED did
   run. `Ajax::internal()` saves, resets, and restores it per sub-call, so a batch cannot
   launder one call's validation across its siblings while an outer request's own
   validation survives a nested call it happened to make.
3. **The completeness guard** (`#[OnEvent('rsx.post_dispatch')]`), which throws
   "Turnstile implementation incomplete" when a `__turnstile` value was submitted and the
   validator never ran. It rides `rsx.post_dispatch`, an action event fired at all five
   handler-invocation seams after the handler returns and before response shaping.

## Source files

- `app/RSpade/Core/Turnstile/Rsx_Turnstile.php` - config accessors, `validate()`, the latch,
  `_guard_unvalidated_token()`, `__require_keys()`, `__reject()`
- `app/RSpade/Core/Turnstile/Turnstile_Input.jqhtml` / `.js` / `turnstile_input.scss` - the
  widget component (real hidden `__turnstile` input; not a `Form_Input_Abstract`)
- `app/RSpade/Core/Js/Turnstile.js` - the one-script loader + callback queue
- The five `rsx.post_dispatch` seams: `Core/Ajax/Ajax.php` (`internal()` and
  `handle_browser_request()`), `Core/Dispatch/Dispatcher.php`, `Core/Api/Api_Dispatcher.php`,
  `Core/Portal/Portal_Dispatcher.php`
- `app/RSpade/Core/Api/Api_Param_Validator.php` - `__turnstile` exempted from the
  undeclared-parameter 422
- `app/RSpade/Core/Bundle/Rsx_Bundle_Abstract.php` - the conditional
  `window.rsxapp.turnstile` site-key export
- `system/config/rsx.php` - the `turnstile` block

## Man page

`man/turnstile.txt` (the whole feature). `man/config_rsx.txt` carries the config block and
`man/event_hooks.txt` the `rsx.post_dispatch` catalog entry. Verified accurate against the
code during this pass; no divergence found, so there is no `issues_encountered.md`.

## Testable surface (by type)

- **php** (`Turnstile_Validate_Test`): the whole `validate()` decision matrix both sides of
  the config switch - sentinel accepted while disabled, sentinel rejected while enabled and
  vice versa, missing keys throwing, the three distinct messages, the `$params`-vs-Request
  token source, and the channel shaping (302 redirect vs the json validation contract). The
  siteverify VERDICT is substituted through `$force_verify_result_for_tests`; the outbound
  call itself is not a framework behaviour worth mocking further.
- **php** (`Turnstile_Guard_Test`): the guard's decision matrix by firing `rsx.post_dispatch`
  directly (submitted + unvalidated throws; validated, GET, and field-less POST stay quiet;
  a payload without a Request fails loud), plus the `Ajax::internal()` latch bookkeeping in
  both directions using a harmless public probe endpoint that never validates.
- **http** (`turnstile_live_verify.sh`): the enabled path over a real dispatch - only
  observable with the feature actually switched on in `.env`, so it SKIPS otherwise. Asserts
  the two network-free rejections (field absent, sentinel while enabled) and probes the
  configured secret against Cloudflare's siteverify endpoint.
- **playwright** (not implemented): the widget's own browser behaviour - the inert
  placeholder while disabled, a real iframe while enabled, the token landing in the hidden
  input, and the auto-reset on a failed submit. Deferred: it needs live Cloudflare keys in
  the environment, which is the same gate that makes the http test skip.
