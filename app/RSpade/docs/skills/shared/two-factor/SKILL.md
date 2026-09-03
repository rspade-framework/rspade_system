---
name: two-factor
description: "Wiring RSpade's second factor into an application - Rsx_Two_Factor (is_enabled / begin_challenge / verify_challenge), the two-stage login with RsxAuth::attempt(record: false, touch_last_login: false), <Two_Factor_Challenge $controller $method>, <Totp_Enrollment> / <Passkey_Register>, the rsx:users:2fa:setup / :dump / :remove operator commands, and a forced-enrollment interstitial driven from pre_dispatch. Use when adding 2FA, TOTP or passkeys to a login flow, building an enrollment or Security settings screen, requiring a factor per user (is_2fa_required), recording STATUS_FAILED_2FA, or when hitting 'That code is not valid.', 'Your verification window has expired. Please sign in again.', 'Two_Factor_Challenge requires $controller and $method', or a passkey refused after moving hosts."
---

# Two-factor authentication

`Rsx_Two_Factor` is the whole subsystem's front door. `Totp`, `Passkeys`, `Recovery_Codes` and `Two_Factor_Credential_Model` are implementation - never touch them from application code.

Three kinds: **TOTP** (RFC 6238, six digits), **PASSKEY** (WebAuthn), **RECOVERY_CODE** (ten single-use codes, minted automatically alongside the first real factor). Credentials belong to the **login identity** (`login_users`), like the password. The client portal (`Portal_User_Model`) is not covered.

**No global enable switch.** 2FA is per identity: `Rsx_Two_Factor::is_enabled($login_user)`. A requirement policy is the application's (see the forced-2FA recipe).

**Prefer passkeys.** The template ships both modes as a worked example; passkeys are phishing-resistant (the signature is origin-bound) where a TOTP code can be phished and replayed inside its window. Removing the TOTP option and offering `<Passkey_Register />` alone is encouraged unless the user base cannot use platform authenticators. Full rationale: `rsx:man two_factor`.

---

## The login-challenge recipe

**Stage 1 - password only.** Suppress BOTH halves of "this was a login", so a recorded SUCCESS always means full authentication:

```php
try {
    $authenticated = RsxAuth::attempt($credentials, record: false, touch_last_login: false);

    if (!$authenticated) {
        // record: false means attempt() wrote NOTHING - you record it, and this is
        // what feeds Login_Throttle.
        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
    }
} catch (Auth_Throttled_Exception $e) {
    $error = $e->getMessage();          // never report a lockout as a wrong password
}

if ($authenticated) {
    $login_user = Session::get_login_user();

    if (Rsx_Two_Factor::is_enabled($login_user)) {
        Rsx_Two_Factor::begin_challenge($login_user);   // parks + LOGS THE SESSION OUT
        return redirect(Rsx::Route('Login_Controller::verify'));
    }

    RsxAuth::login($login_user);                        // no factor: this IS the login
    Login_History::record_success((int) $login_user->id, $email);
    return redirect(static::__post_login_destination(...));
}
```

**Stage 2 - the challenge screen.** A public GET route that bounces when nothing is pending, hosting the component:

```php
#[Route('/login/verify', methods: ['GET'])]
public static function verify(Request $request, array $params = [])
{
    if (Rsx_Two_Factor::challenge_pending() === null) {
        return redirect(Rsx::Route('Login_Controller::index'));
    }

    return rsx_view('Login_Verify');
}
```

```html
<Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa" />
```

**Stage 3 - YOUR verification endpoint.** The framework deliberately does not own it: where a signed-in user lands is application logic.

```php
#[Ajax_Endpoint]                        // class is #[Auth('public')] - the session is NOT signed in
public static function verify_2fa(Request $request, array $params = [])
{
    try {
        $login_user = Rsx_Two_Factor::verify_challenge($params);   // {code} or {assertion}
    } catch (Auth_Throttled_Exception $e) {
        return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
    } catch (Two_Factor_Failed_Exception $e) {
        return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
    }

    return ['redirect' => static::__post_login_destination((int) $login_user->id)];
}
```

`verify_challenge()` signs the identity in, stamps `last_login`, writes the success row, and records `STATUS_FAILED_2FA` on a wrong answer. **You record nothing.** Hand `$params` through untouched.

**One destination function, two callers** - the password path redirects to it, the endpoint returns it as a string. A destination computed twice drifts.

Worked example: `system/app/RSpade/resource/reference_app/app/login/login_controller.php`.

---

## The enrollment recipe

Anywhere a signed-in user can reach (the template uses Settings > Password & Security):

```javascript
async on_load() {
    // ONE call - credentials, the unspent code count and the enabled flag together,
    // so the screen never paints three states that disagree while they land.
    this.data.two_factor = await Rsx_Two_Factor_Controller.credentials_list();
}
```

Host a ceremony in a modal and repaint from the server when it finishes:

```javascript
await Modal.show({ title: 'Set Up Authenticator App', component: 'Totp_Enrollment', ... });
// on the component's 'enrolled' (or 'registered') event: close, then this.reload()
```

Removal is `Rsx_Two_Factor_Controller.credential_remove({ id })`; a new code sheet is `recovery_regenerate()`. Both answer the refreshed state.

**Enrollment always acts on the signed-in identity** - there is no spelling that names another account, and every path throws while impersonating.

---

## The forced-2FA recipe (application policy)

The framework decides only whether an identity HAS a factor. A requirement belongs in `Main::pre_dispatch()`, ahead of every screen - not on a page the user can decline to visit:

```php
if (str_starts_with($handler, 'Rsx\App\Frontend')) {
    // ...
    if (
        $user->is_2fa_required
        && !Rsx_Two_Factor::is_enabled((int) $login_user_id)
        && !Session::is_impersonating()
        && Session::get_session()->type_id !== Session::TYPE_PLAYWRIGHT
    ) {
        return redirect(Rsx::Route('Login_Controller::two_factor_setup'));
    }
}
```

- **The interstitial route must sit OUTSIDE the intercepted handler prefix**, or the redirect loops.
- **Impersonation is exempt**: the impersonator authenticated as themselves, and cannot enroll for their victim anyway.
- **`Session::TYPE_PLAYWRIGHT` is exempt**: `rsx:debug` dev-auth logs in with no password and no challenge, so a harness run would bounce and every page would become untestable for a flagged user.
- `pre_dispatch()` runs on DOCUMENT requests and an SPA makes none - push a realtime user refresh when the flag changes, or it takes effect only at the next full load.

---

## Component contracts

| Component | Args | Posts / Events |
|---|---|---|
| `<Totp_Enrollment />` | none | fires `enrolled` when the user acknowledges the code sheet (the factor is already live) |
| `<Passkey_Register />` | none | fires `registered`; renders a plain notice instead of a button when WebAuthn is absent |
| `<Two_Factor_Challenge $controller $method />` | both REQUIRED | posts `{code}` or `{assertion}`; expects `{redirect}` and follows it with `window.location`; fires `no_challenge` when nothing is pending |

All three are **layout-neutral by contract** - no card, no heading, no width. The host page owns the box. One input takes both an authenticator code and a recovery code; the server tries both.

JS helpers: `Rsx_Two_Factor.is_supported()`, `register_passkey(label)`, `authenticate_passkey()` (returns the assertion; it does not post it).

---

## Gotchas

- **Do not double-count the throttle.** `Login_History::record_failure(..., STATUS_FAILED_2FA, ...)` already feeds `Login_Throttle`. Calling `Login_Throttle::record_failure()` beside it halves the real budget, and the halving is only ever discovered by a user locked out early.
- **Park before you log out.** Anything the challenge must carry across (an invite code, a redirect) is written with `Session::put_value($key, $value, Rsx_Two_Factor::challenge_expires_at())` **BEFORE** `begin_challenge()`. `put_value()` establishes the session row; the logout clears the identity, not the row, and `_session_values` survive by FK. Written afterwards, it lands on a session the caller abandoned.
- **A checkbox absent from a POST means OFF.** A policy flag like `is_2fa_required` uses `!empty($params['is_2fa_required']) ? 1 : 0`, or it can never be turned back off.
- **A dismissed browser prompt is not an error.** `NotAllowedError` is caught and answered as `null`; say nothing and leave the button available.
- **No Turnstile on the verification endpoint.** `<Two_Factor_Challenge>` renders no widget, so there is no `__turnstile` field and the completeness guard stays silent. The surface is still guarded: it answers only from the caller's own pending challenge, and `verify_challenge()` spends the brute-force budget first.
- **`credential_key`, not `credential_id`** - it is an opaque string handle from an authenticator, and `SCHEMA-TYPE-01` reserves `_id` for integers.
- **A passkey is bound to the hostname it was enrolled on** (the relying party id is the bare host). Moving environments invalidates it; that is the spec, not a bug.
- **Recovery-code plaintext exists exactly once.** A lost sheet is regenerated, never reprinted.
- **`challenge_state` returning `null` is not an error** - expired, already signed in, or a direct visit all read the same, and all three send the visitor back to `/login`.
- **A `RuntimeException` out of the facade is a wiring mistake** (nobody signed in, or impersonating). Do not catch it; only `Two_Factor_Failed_Exception` and `Auth_Throttled_Exception` are user-facing.

---

## Operator commands

`--user=<id|email>` is required on all three; `--json` uses the standard envelope.

```
php artisan rsx:users:2fa:setup  --user=alice@example.com    # bootstrap/recovery: prints seed + codes ONCE, refuses a second seed
php artisan rsx:users:2fa:dump   --user=1                    # factors WITH decrypted TOTP seeds (codes are a count - they are hashed)
php artisan rsx:users:2fa:remove --user=1 [--id=7] [--force] # prompts; --json requires --force
```

They run as whoever holds shell access, which is a higher privilege than any identity in the app - which is why the impersonation rule that governs the web paths does not reach them. **Never call `cli_setup_totp()` / `cli_dump_credentials()` from a request path.**

Full contract: `rsx:man two_factor`. Related: `rsx:man session` (throttle, login history), `rspade:session-auth`, `rspade:turnstile`.
