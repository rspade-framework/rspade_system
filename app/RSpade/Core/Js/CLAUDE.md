# Core/Js — the always-present browser runtime

**Using these from application code? Skill `rspade:rsx-stdlib` and `rsx:man js_functions`.**
This file says what is in this DIRECTORY, how the browser boots, and what must stay true when
editing it.

## Every class here is guaranteed present

Every file in this directory is compiled into EVERY bundle, so `Rsx`, `Ajax`, `Manifest`,
`Rsx_Storage`, `Permission` and the rest exist on every page. **Never write a defensive
existence check** (`if (typeof Rsx_Storage !== 'undefined')`): the build guarantees them, and a
check would only hide a broken bundle. Non-class files (`functions.js`, `async.js`,
`error.js`, `browser.js`, `hash.js`, `datetime.js`, `decorator.js`) define globals and follow
the non-class-file rule (functions, static `const`s and decorators only).

## The boot, in order

The bundle's generated runner (`Core/Bundle/BundleCompiler::_create_javascript_runner`)
calls `Rsx._rsx_core_boot()` once the document's deferred scripts have run. It installs the
exception handlers, hydrates `window.rsxapp.user` / `.site` into ORM instances, then calls a
static method of the phase's name on EVERY registered class, awaiting each phase before the
next, and triggers the phase's event:

    _on_framework_core_define -> _on_framework_modules_define -> _on_framework_core_init
    -> on_app_modules_define -> on_app_define -> _on_framework_modules_init
    -> on_app_modules_init -> on_app_init -> _on_spa_init -> on_app_ready

`_`-prefixed phases are the framework's. **A registration the first page needs goes in
`on_app_modules_define()`** - the spinner (`Rsx.set_default_spinner()`), the form question
handler, the error-screen components - because the first SPA page draws before `app_ready`.
`on_app_ready()` is the Blade page hook (with its page guard).

## What is here

- `Rsx.js` — the core: the boot above, `Rsx.Route()`, `url_hash_get()` and friends,
  `load_external()`, `set_default_spinner()`, `set_session_expired_handler()`, the
  navigation guard, unhandled-exception routing to `Exception_Handler.js`.
- `Manifest.js` — the browser class registry (names, inheritance) the boot walks.
- `Ajax.js` — the ONE transport: every `Controller.method()` call goes to `/_ajax/_batch`
  (batched per synchronous turn when `rsx.ajax.batching` is on; a `#[Priority]` call goes
  alone to `/_ajax/_priority`), resolving or rejecting on the `{_success,
  _ajax_return_value}` envelope. `$.ajax()` to a local URL throws. `Ajax.upload()` is the
  multipart path. `Form_Utils.js` renders per-field errors.
- `Rsx_Js_Model.js` — the base of every generated model stub (`Base_<Model>.js`, written by
  `Core/Database/Model_Stub_ManifestSupport`): `fetch()`, `fetch_or_null()`, lazy
  relationships, enum helpers, `field_length()` (fed by the bundle's field-length script).
- `Permission.js`, `Portal_Permission.js` — client mirrors of `can_access()` and the role /
  permission checks, from data the server resolved; display only, never enforcement.
- `Rsx_Session.js`, `Rsx_Csrf.js` — page-view reporting for the idle limit, the lost-session
  announcement, the CSRF token on every internal POST.
- `Rsx_Storage.js` — scoped session/local storage, non-critical data only.
- `Rsx_Time.js`, `Rsx_Date.js`, `Rsx_Timezone_Cookie.js` — the JS twins of the PHP time
  classes (identical API); `.expect` files are their test expectations.
- `Rsx_Realtime.js` — websocket subscriptions (`this.subscribe()` on components).
- `Mutex.js` (`@mutex`), `ReadWriteLock.js` (`rwlock`), `decorator.js` (the `@decorator`
  marker) — decorator and locking primitives.
- `Rsx_External_Resources.js` — `Rsx.load_external()`'s registry, baked from `*.externals.php`.
- `Rsx_Jq_Helpers.js`, `Width_Group.js`, `Rsx_Droppable.js`, `Rsx_Behaviors.js`,
  `Responsive.js`, `Rsx_View_Transitions.js`, `Rsx_Dark_Mode.js`, `Rsx_Portal.js`,
  `Login_Redirect.js`, `Rsx_Blade_Autocomplete.js`, `Turnstile.js`, `Rsx_Text_Abstract.js`, `Debugger.js` — the jQuery
  extensions, responsive tiers, dark mode, portal routing, login redirect sanitizer, the Blade
  `autocomplete="off"` default (jqhtml's `autocomplete_off`, applied to Blade-written fields), Turnstile
  widget, text-type registration and `console_debug` transport.

## Invariants

- **One implementation per algorithm.** Where PHP owns a rule (a text type's encoding, a
  permission's resolution), the JS side reads the server's answer instead of re-implementing
  it; where both languages carry a twin (`Rsx_Time`, `Login_Redirect`), the API is identical.
- Static methods never use naked `this`; jQuery callbacks alias `$(this)` (`JS-THIS-01`).
- No `setTimeout` debounce or timeout of your own: `debounce()` in `async.js` is the one.

## References

`rsx:man js_functions`, `rsx:man jquery`, `rsx:man storage`, `rsx:man js_decorators`,
`rsx:man ajax_error_handling`, `rsx:man spa` · skills `rspade:rsx-stdlib`,
`rspade:jquery-extensions`, `rspade:js-decorators`, `rspade:model-fetch` · siblings
`Core/Bundle/` (the runner), `Core/SPA/`, `Core/Ajax/`, `Core/Database/` (model stubs).
