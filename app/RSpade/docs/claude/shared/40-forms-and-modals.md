<!-- single-source: never duplicate into another fragment. -->

## FORMS AND MODALS

**One engine, in framework core.** `<Rsx_Form $controller $method [$data]>` owns value state, dirty tracking, submission and error rendering; `Form_Field` is presentation around one input; `Form_Input_Abstract` subclasses own one named value. **An application never carries a copy of any of the three.**

**The tag is the ONLY place an endpoint is named** — `$controller` + `$method` on `<Rsx_Form>`; modals do not repeat it, buttons do not repeat it, JS does not repeat it. **`form.submit()` is the only submission path** (a `type="submit"` button inside the form is auto-wired), resolving with the server result or `false` on any failure. `$data` present = edit form, absent = create; values bind by matching `$name`.

**Validation lives on the SERVER, once — no client-side required/format/length checks anywhere**, because a client check that duplicates a server rule MASKS the absence of the server rule, and the gap then surfaces only when a script or API caller hits the endpoint directly. `$required` on `Form_Field` is an asterisk announcing a server rule and enforces nothing. The one exception is an input's `_validate()`: an ARCHITECTURAL constraint whose invalid state cannot be expressed to the server at all.

**Blank is a value; absent means untouched.** Every input serializes on every submit, so a blank field arrives as `''` and must validate — a required field rejects it identically on create and edit. An ABSENT key means "leave it alone"; **"keep the old value when blank" is forbidden server-side** — it makes a failed clear look like a success. Endpoints answer `response_form_error($message, ['title' => 'Required'])`.

**Every form places exactly one `<Form_Errors />`** where its layout wants failure feedback — a form without one throws. **`$name` goes on the INPUT, never on `Form_Field`, and you NEVER set `$value`.** `$max_length` is REQUIRED on `Text_Input` (`Model.field_length('col')`, a number, or `-1`).

**An input implements `_get_value()`/`_set_value()` — NEVER `val()`**, calls `_mark_ready()` at the earliest moment a write would stick (buffering makes `val()` timing-indifferent), and announces user edits with `_notify_input(value)` — never a hand-triggered `'input'`+`'val'` pair. Events: the form fires `'submitted'`, `'submit_error'` and `'input'`.

**A modal is CHROME around a form**: `Modal.form({title, component, component_args, submit_label, cancel_label, max_width, before_submit, on_success})` finds the `<Rsx_Form>` in the component and drives its `submit()` — the common case needs **no JS class on the hosted component at all**. It resolves with the result, or `false` on cancel; failure keeps the dialog open with errors already rendered. **A dialog with no endpoint is `Modal.show({buttons})`, and only a literal `false` from a button callback keeps a dialog open — `null` closes it** and discards what the user entered. Basic dialogs (`alert`, `confirm`, `prompt`, `select`) take POSITIONAL arguments and overload: 1 arg = body, 2+ = TITLE first.

**Async-loaded edit forms**: `form.populate(promise)` shows the loading overlay, applies the values and clears in a `finally`; `set_loading()` is the primitive, and **clearing is always explicit** — never automatic. While loading, `submit()` refuses (blank is a value, so a submit racing the fetch would validly clear unfetched fields). **SPA re-renders park the user's dirty values** under a key frozen at `on_create()` and re-apply them to the successor instance: data fills fields the user has not touched, keystrokes always win.

Skills: `rspade:forms`, `rspade:form-input`, `rspade:modals`, `rspade:ajax-error-handling`. Details: `rsx:man form_conventions`, `rsx:man form_input`, `rsx:man modals`.
