---
name: ajax-error-handling
description: "Handling Ajax errors - the {_success, _ajax_return_value} envelope, the Ajax::ERROR_* codes, response_error() / response_form_error(), client-side error display, Form_Utils, Ajax.call_detailed() and Ajax.action() for request references. Use when implementing error handling for Ajax calls, returning per-field validation errors, deciding whether to try/catch or let it bubble, making a button's writer call safe to retry, handling request_ref_reused / request_response_expired, or debugging an Ajax failure from the CLI with rsx:ajax."
---

# RSX Ajax Error Handling

## Response Architecture

RSX returns **HTTP 200 for every Ajax response** (success and errors). Success/failure is encoded in the response body via `_success` field. **The one exception is an infrastructure OUTAGE** - the lock daemon or the database connection gone: it is no call's failure, so it is never a `fatal` envelope at 200. The whole request fails with **HTTP 500**, no later call of the batch runs, and the client rejects every call with ONE `server_error` worded from the 500 body ("The service is temporarily unavailable. Please try again in a moment." plus the error id). Never catch or name it (`rsx:man error_handling`, INFRASTRUCTURE OUTAGE).

```javascript
// Success response
{
    "_success": true,
    "_ajax_return_value": { /* your data */ }
}

// Error response
{
    "_success": false,
    "error_code": "validation|not_found|unauthorized|auth_required|fatal",
    "reason": "User-friendly message",
    "metadata": { /* field errors for validation */ }
}
```

**Rationale**: Batch requests need uniform status codes. Always get parseable response body. Non-200 means the request failed as a whole: PHP was not reached, the platform under it is down (500), or a malformed batch (400).

---

## The Transport

- **One browser transport, every mode.** `Ajax.js` sends every call to `POST /_ajax/_batch` (a lone call as a batch of one) and settles each from its own `C_<call_id>` key. `config('rsx.ajax.batching')` (`window.rsxapp.ajax_batching`) decides only how many calls share a request: the calls of one synchronous turn (up to 20), or - false - one per request, for reading calls one by one in the network panel. Its default follows the mode: **off in development, on in debug and production**; an app overrides it either way. `POST /_ajax/<Controller>/<action>` answers a hand-made request (curl, a shell test) with the bare envelope; its params are the request body exactly, no query string.
- **A `#[Priority]` endpoint goes alone to `POST /_ajax/_priority`** (the same envelope, exactly one call), which the Proxority proxy in front of php-fpm schedules ahead of its queue; nothing changes in the calling code. The server refuses more than one call there (400) and answers an endpoint without `#[Priority]` as not_found; outside the RSpade container the path behaves as `_batch`. `rsx:man proxority`.
- **No merging.** Two identical calls are two calls - every call is a POST, and the transport cannot know an endpoint is a read. Read coalescing lives in the ORM fetch (overlapping `fetch()` of one model+id share a lookup; each caller still gets its own instance) and jqhtml's load coordinator.
- **Every call is settled.** An answer missing the call rejects it with `server_error`; an HTTP 5xx rejects every call in the request with `server_error`, status 0 with `network_error`, anything else `generic`; a hydration failure rejects only that call. A CSRF failure or a fault outside any call is answered under every `call_id` the batch named.
- **Checked per call, in order**: session lifetime (once one call gets `session_expired`, so does every later call in the request), staff site membership (`auth_required`), then the realm's `Main::pre_dispatch` ONCE PER CALL (`$params['controller']`/`['action']`, `_route` `/_ajax/:controller/:action`) - non-null refuses that call: a coded result keeps its code and reason (`response_unauthorized('Account suspended')`), anything else is `unauthorized` "This request was refused.", never a page. Then the gates: a denial is `auth_required` when nobody is signed in in the realm, `unauthorized` when somebody is.
- **Replays.** `Ajax.call()` resolves the value alone. `Ajax.call_detailed(url, params)` resolves `{value, replayed, completed_at, duration_ms}` - `replayed` when the request history answered a resent `_request_ref` (nothing ran; the timing is the original run's). No call carries a reference unless asked: `Rsx_Form` sends its own on every submission (`rsx:man form_conventions`, RESUBMITTING); for a writer called from a button, `const act = Ajax.action()` once per control, then `await act.call(Controller.method, params)` - one reference per endpoint, kept across failed attempts, replaced on success (`act.replayed`). `Ajax.request_ref()` mints one.
- **`request()` and the `Request` facade answer the call's own request** while it runs, never the batch's.
- **An endpoint never returns an HTTP response.** `redirect()`, `view()`, a download - from the action or the controller's `pre_dispatch` - is a FAULT (`fatal`), never a success. A controller `pre_dispatch` that redirects pages returns null for an Ajax call.
- **An endpoint never leaves a DB transaction open.** It is rolled back (its writes are gone) and the call answers a fault naming the endpoint.
- **A name that is not an endpoint is a coded `not_found`**, never a reported fault.

---

## Error Codes

| Constant | Purpose |
|----------|---------|
| `Ajax::ERROR_VALIDATION` | Field validation failures |
| `Ajax::ERROR_NOT_FOUND` | Resource not found |
| `Ajax::ERROR_UNAUTHORIZED` | User lacks permission |
| `Ajax::ERROR_AUTH_REQUIRED` | User not logged in |
| `Ajax::ERROR_FATAL` | Uncaught PHP exceptions |
| `Ajax::ERROR_GENERIC` | A general failure with no more specific code (`generic`, default message "An error has occurred"): `response_error(Ajax::ERROR_GENERIC, $message)` for a refusal that is none of the above, and the code an uncaught HTTP exception whose status has no mapping (not 401/403/404/429) answers with (an empty message reads as the status text). An in-process caller sees it as a plain `Exception` |
| `Ajax::ERROR_TOO_MANY_REQUESTS` | A rate limit refused the call: any uncaught HTTP 429 exception (`Auth_Throttled_Exception`, Symfony `TooManyRequestsHttpException`). The same exception is a real 429 on a page or API call; catch it to answer your own 200 form error instead |
| `Ajax::ERROR_SESSION_EXPIRED` | The sign-in outlived its limits (`metadata.cause` `length`/`idle`), or the call carried the session token a sign-in or sign-out just rotated away (`rotated`); announced once per page to `Rsx.set_session_expired_handler()` |
| `Ajax::ERROR_REQUEST_REF_REUSED` | A writer call's `_request_ref` was already used by this actor: with different parameters (`metadata {completed_at, original}` - original is the stored `_ajax_return_value` while kept) or on another endpoint (`metadata {}`). Nothing ran. A form hands it to its resubmit handler; a direct caller (`Ajax.action()`) gets an ordinary error. `rsx:man request_history` |
| `Ajax::ERROR_REQUEST_RESPONSE_EXPIRED` | The reference's request already ran and its stored answer was pruned (`metadata {completed_at}`). Nothing ran |
| `Ajax::ERROR_QUESTION` | Not a failure: a pending question (`response_form_question($key, $question)`); the form engine answers it, a non-form caller reads `metadata.key`/`metadata.question`, an in-process `Ajax::internal()` raises `AjaxQuestionException`. `rsx:man form_conventions`, QUESTIONS |

Constants available in both PHP (`Ajax::ERROR_*`) and JavaScript (`Ajax.ERROR_*`). The client raises three of its own: `Ajax.ERROR_SERVER` (`server_error`), `Ajax.ERROR_NETWORK` (`network_error`), and `Ajax.ERROR_PHP_EXCEPTION` (`php_exception` - a fatal carrying file/line, for a developer caller).

---

## Server-Side: Returning Errors

Use `response_error()` helper - never return `_success` manually:

```php
#[Ajax_Endpoint]
#[Auth('can_manage_users')]   // MANDATORY - the manifest build fails without a gate
public static function save(Request $request, array $params = []) {
    // Validation error with field-specific messages
    if (empty($params['email'])) {
        return response_error(Ajax::ERROR_VALIDATION, [
            'email' => 'Email is required'
        ]);
    }

    // Not found error
    $user = User_Model::find($params['id']);
    if (!$user) {
        return response_error(Ajax::ERROR_NOT_FOUND, 'User not found');
    }

    // Success - just return data (framework wraps it)
    $user->name = $params['name'];
    $user->save();
    return ['id' => $user->id];
}
```

### `response_form_error()` - the form-shaped shortcut

When the endpoint backs a form, `response_form_error(string $message, array $field_errors = [])` says the same thing more directly than assembling an `ERROR_VALIDATION` payload by hand:

```php
if (empty($params['title'])) {
    return response_form_error('Validation failed', ['title' => 'Required']);
}
```

The message lands as the form-level error; each key in `$field_errors` lands inline on the input with that `$name`. `Form_Utils.apply_form_errors($form.$, response.errors)` is what places them when you handle the response manually.

### Let Exceptions Bubble

Don't wrap database/framework operations in try/catch. Let exceptions bubble to the global handler:

```php
// WRONG - Don't catch framework exceptions
try {
    $user->save();
} catch (Exception $e) {
    return ['error' => $e->getMessage()];
}

// CORRECT - Let it throw
$user->save();  // Framework catches and formats
```

**A security failure is never recoverable.** The catch block that substitutes an unsanitized value is the worst version of this mistake, because the request continues and looks successful:

```php
// WRONG - DISASTER
try { $clean = Sanitizer::sanitize($input); }
catch (Exception $e) { $clean = $input; }

// CORRECT
$clean = Sanitizer::sanitize($input);  // Let it throw
```

Only catch failures you expect and can describe (a file upload, an outbound API call, user input). An exception handler **formats** an error; it never **wraps** one, and it never provides an alternative code path.

**When try/catch IS appropriate**: File uploads, external API calls, user input parsing (expected failures).

**A write's side effect is part of the write.** A "best-effort" `catch (\Throwable $e) {}` around a notification in a `#[Writer]` tells the user the write worked while what it promised never happened. Let it throw (the writer rolls back, the user sees the error and retries) or run it after the commit (`DB::afterCommit()`, whose failure is reported while the committed write answers success).

**A sentence the user can act on**: `throw new Rsx_User_Facing_Exception('The payment provider did not answer. Please try again.', $e);` - its message replaces the generic one for EVERY caller on every channel (with the error id); `$e` is logged, never shown. Still a fault: logged, the writer rolled back (`rsx:man error_handling`, USER-SAFE MESSAGES).

---

## Client-Side: Handling Errors

Ajax.js automatically unwraps responses:
- `_success: true` → Promise resolves with `_ajax_return_value`
- `_success: false` → Promise rejects with Error object

```javascript
try {
    const user = await User_Controller.get_user(123);
    console.log(user.name);  // Already unwrapped
} catch (error) {
    console.log(error.code);     // 'validation', 'not_found', etc.
    console.log(error.message);  // User-displayable message
    console.log(error.metadata); // Field errors for validation
}
```

### Automatic Error Display

**Every failure is shown without your code doing anything**, so catch only an error you have a PURPOSE for (a not-found you recover from, a refusal you word yourself) - never one you would merely display (`Modal.alert(err.message)`), and never "something unexpected" (a bug, the lock daemon down):

- a form submission: in its `<Form_Errors />`;
- any other call nobody caught: the dialog the application registered with `Rsx.set_ajax_error_handler(fn)` from a static `on_app_modules_define()` (the template's `Modal` does), else the framework's own `Rsx_Error_Dialog` - in every bundle, Blade and `/_sys` included.

```javascript
// No try/catch - an uncaught failure is shown automatically
const user = await User_Controller.get_user(123);
```

The handler gets the rejected Error once per failure (a request that failed as a whole rejects all its calls with ONE Error, shown once); `error.code === Ajax.ERROR_PHP_EXCEPTION` (with `metadata.file/line/backtrace`) only for a developer caller. Nothing is shown during the SPA's navigation grace period (1.5 s after a navigation starts), and an uncaught Ajax error does not disable SPA navigation.

The listener recognises an Ajax error by its marker (`error.__rsx_ajax_error === true`, set on every error the Ajax layer builds), so it covers a rejection whatever promise chain carried it - a generated stub, `Model.fetch()`, your own `async` wrapper that lets it propagate.

---

## Form Error Handling

### With Rsx_Form (there is nothing to write)

The endpoint is named on the `<Rsx_Form>` tag and `submit()` runs the one
pipeline, so a failed submit renders itself and resolves `false`:

```javascript
const result = await Modal.form({
    title: 'Add User',
    component: 'Add_User_Modal_Form',
});

if (result) { /* saved */ }
```

`form.render_error(error)` is what it uses internally, and a host that caught an
error itself (an edit modal whose record fetch rejected) may call it directly:
- **validation**: inline field errors, plus the top alert, which ALWAYS renders
- **fatal/network/auth**: a single alert in the form's `<Form_Errors />`

**Never `try/catch` a submit to "handle" a validation error** - swallowing it
before the renderer sees it is how a failed save comes to look successful. Full
contract: skill `rspade:form-engine`, `rsx:man form_conventions`.

### With Form_Utils (bare-markup pages, e.g. login)

```javascript
try {
    const result = await Controller.save(form_data);
} catch (error) {
    if (error.code === Ajax.ERROR_VALIDATION) {
        Form_Utils.apply_form_errors($form, error.metadata);
    } else {
        Rsx.render_error(error, '#error_container');
    }
}
```

`Form_Utils.apply_form_errors()`:
- Matches errors to fields by `name` attribute
- Adds `.is-invalid` class and inline error text
- Shows alert ONLY for errors that couldn't match to fields

---

## Error Display Methods

### Modal.error() - Critical Errors

```javascript
await Modal.error(error, 'Operation Failed');
```

Red danger modal, can stack over other modals. The template's registered uncaught-error handler uses it (`Modal.fatal_error()` for a `php_exception`).

### Rsx.render_error() - Container Display

```javascript
Rsx.render_error(error, '#error_container');
Rsx.render_error(error, this.$sid('error'));
```

Displays error in any container element.

---

## Who Sees Fatal-Error Detail

Keyed on the CALLER, server-side (`Rsx_Diagnostics::caller_sees_detail()`): development or debug mode AND a signed-in developer (`login_users.is_developer`), a valid `rsx:debug` credential, or a loopback caller. A development site may be public, so an anonymous remote caller is redacted there too; strict production redacts for everybody.

**Developer caller**:
- Full exception message
- File path and line number
- SQL queries with parameters
- Stack trace (up to 10 frames)

**Everybody else**:
- Generic message: "An unexpected error occurred. Please try again." - or a `Rsx_User_Facing_Exception`'s own sentence, or the outage sentence
- An `error_id` (the envelope's `error.error_id`; `error.metadata.error_id` on the rejected JS Error, whose `message` ends "(error id <id>)"); the detail is logged as `[error_id=<id>] ...`
- A target that is not an endpoint answers one message, "`<Controller>::<action>` is not an Ajax endpoint" — no class-name oracle

---

## Common Patterns

### Simple Validation

```php
#[Ajax_Endpoint]
public static function save(Request $request, array $params = []) {
    $errors = [];

    if (empty($params['email'])) {
        $errors['email'] = 'Email is required';
    }
    if (empty($params['name'])) {
        $errors['name'] = 'Name is required';
    }

    if ($errors) {
        return response_error(Ajax::ERROR_VALIDATION, $errors);
    }

    // ... save logic
}
```

### Check Specific Error Type

```javascript
try {
    const data = await Controller.get_data(id);
} catch (error) {
    if (error.code === Ajax.ERROR_NOT_FOUND) {
        show_not_found_message();
    } else if (error.code === Ajax.ERROR_UNAUTHORIZED) {
        redirect_to_login();
    } else {
        // Not ours to handle: the registered error dialog shows it
        throw error;
    }
}
```

## Debugging From the CLI

`rsx:ajax` is the entry point when you need to know whether the ENDPOINT is wrong or the page is:

```bash
php artisan rsx:ajax My_Controller save --args='{"id":1}'          # raw return value
php artisan rsx:ajax My_Controller save --args='{"id":1}' --debug  # full envelope
php artisan rsx:ajax My_Controller list --user=1 --site=1          # with auth/site context
php artisan rsx:ajax My_Controller list --user=admin@example.com   # user by email
```

`--debug` prints the HTTP-like response (`{success, _ajax_return_value, console_debug}`) so you can see the envelope and any `console_debug()` output the endpoint emitted; `--verbose` prefixes the request context. All errors come back as JSON - the command never throws to stderr.

If `rsx:ajax` returns the right data and the page does not show it, the fault is client-side; if it returns the error, you have reproduced the bug without a browser.

## More Information

Details: `php artisan rsx:man ajax_error_handling`
