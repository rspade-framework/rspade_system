---
name: two-factor
description: "Wiring RSpade's second factor and passkey sign-in into an application - Rsx_Two_Factor (is_enabled / begin_challenge with $accepts / issue_code / pending_identity / verify_challenge / begin_passkey_login / verify_passkey_login, the ANSWER_* kinds) and its client-portal twin Rsx_Portal_Two_Factor, the two-stage login with RsxAuth::verify_credentials(record: false) then begin_challenge, Two_Factor_Failed_Exception::reason() and the two_factor.challenge.spent event, passwordless 'Sign in with a passkey' with <Passkey_Sign_In $controller $method>, <Two_Factor_Challenge $controller $method>, <Totp_Enrollment> / <Passkey_Register>, the rsx:users:2fa:setup / :dump / :remove / :unlock operator commands, the attempt caps (is_locked / clear_failures, rsx.two_factor.challenge_max_failures / identity_max_failures), emailed six-digit sign-in codes (issued codes, a SECURITY-category email, <Two_Factor_Challenge $send_controller $send_method>, sso.two_factor.accepts), and requiring a factor as the application's own sign-in step. Use when adding 2FA, TOTP, passkeys or an emailed code to a staff or portal login flow, offering passwordless passkey sign-in, building an enrollment or Security settings screen, requiring a factor before a user may continue, recording STATUS_FAILED_2FA or STATUS_FAILED_PASSKEY, or when hitting 'That passkey could not sign you in.', 'operates on Portal_User_Model',  'That code is not valid.', 'Too many incorrect codes. Please sign in again.', 'Too many incorrect codes have been entered for this account. Please try again later.', 'Your verification window has expired. Please sign in again.', 'Two_Factor_Challenge requires $controller and $method', 'does not accept an issued code', 'holds none of them - nobody could answer this challenge', 'This sign-in cannot be completed with a passkey.', 'That security key request has expired. Please try again.', a passkey that will not save after a QR scan (publicKey.timeout, passkey_enroll_abandoned), or a passkey refused after moving hosts."
---

# Two-factor authentication

Two front doors, one engine: **`Rsx_Two_Factor`** for staff (credentials of the login identity, `login_users`, like the password) and **`Rsx_Portal_Two_Factor`** for the client portal (credentials of a `Portal_User_Model`) - the identical API over separate tables. `Totp`, `Passkeys`, `Recovery_Codes` and the credential models are implementation - never touch them from application code.

Three kinds: **TOTP** (RFC 6238, six digits), **PASSKEY** (WebAuthn), **RECOVERY_CODE** (ten single-use codes, minted automatically alongside the first real factor). A passkey is also a **first factor**: passwordless sign-in (below).

**No global enable switch.** 2FA is per identity: `is_enabled($identity)`. A requirement policy is the application's (see the forced-2FA recipe).

**Prefer passkeys.** The template ships both modes as a worked example; passkeys are phishing-resistant (the signature is origin-bound) where a TOTP code can be phished and replayed inside its window. Removing the TOTP option and offering `<Passkey_Register />` alone is encouraged unless the user base cannot use platform authenticators. Full rationale: `rsx:man two_factor`.

---

## The login-challenge recipe

**Stage 1 - password only, and NOBODY is signed in.** `verify_credentials()` answers the identity and touches no session; `RsxAuth::login()` and a passed challenge are the only things that sign anybody in. So the visitor is signed out for the whole challenge, and a request that dies part-way leaves them out. `record: false`, so a recorded SUCCESS always means full authentication:

```php
$login_user = null;

try {
    $login_user = RsxAuth::verify_credentials($credentials, record: false);

    if ($login_user === null) {
        // record: false means NOTHING was written - you record it, and this is
        // what feeds Login_Throttle.
        Login_History::record_failure($email, Login_History::STATUS_FAILED_PASSWORD);
    }
} catch (Auth_Throttled_Exception $e) {
    $error = $e->getMessage();          // never report a lockout as a wrong password
}

if ($login_user !== null) {
    // Your own account-state questions go HERE: nobody is signed in, so a refusal
    // is just an error on the form - there is no logout() to remember.

    if (Rsx_Two_Factor::is_enabled($login_user)) {
        Rsx_Two_Factor::begin_challenge($login_user);   // parks the pending identity
        return redirect(Rsx::Route('Login_Controller::verify'));
    }

    RsxAuth::login($login_user);                        // no factor: this IS the login
    Login_History::record_success((int) $login_user->id, $email);
    return redirect(static::post_login_destination(...));
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
<Two_Factor_Challenge $controller="Login_Controller" $method="verify_2fa"
                      $cancel_url="{{ Rsx::Route('Login_Controller::index') }}" />
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

    return ['redirect' => static::post_login_destination((int) $login_user->id)];
}
```

`verify_challenge()` signs the identity in, stamps `last_login`, writes the success row, and records `STATUS_FAILED_2FA` on a wrong answer. **You record nothing.** Hand `$params` through untouched.

**Never show how many attempts remain** - "That code is not valid" and the form again, every time. A count tells an attacker how much budget is left and when to stop before the identity locks. Which failure it was is `$e->reason()`, for code only: `REASON_WINDOW_EXPIRED`, `REASON_WRONG_ANSWER`, `REASON_CHALLENGE_SPENT` (the wrong answer that destroyed the challenge), `REASON_IDENTITY_LOCKED`, `REASON_REFUSED`.

**Tell the account owner when a challenge is exhausted.** Reaching a challenge means the password was right; the caps make guessing slow and only being noticed makes it hopeless. The framework fires an action - `two_factor.challenge.spent` (staff), `portal.two_factor.challenge.spent` (portal), payload `{identity, email, failures}` - and the application decides what to send:

```php
#[OnEvent('two_factor.challenge.spent', priority: 10)]
public static function notify($data): void
{
    (new Two_Factor_Challenge_Spent_Email($data['email']))->to($data['email'])->send();   // CATEGORY = SECURITY
}
```

Worked example: `reference_app/handlers/Two_Factor_Notice_Handlers.php`.

**One destination function, two callers** - the password path redirects to it, the endpoint returns it as a string. A destination computed twice drifts.

Worked example: `system/app/RSpade/resource/reference_app/app/login/login_controller.php`.

---

## The passwordless recipe - "Sign in with a passkey"

Any registered passkey can sign its owner in with no password (every passkey is discoverable). The app owns the endpoint, because the destination is app logic:

```php
#[Ajax_Endpoint]                        // class is #[Auth('public')]
public static function passkey_login(Request $request, array $params = [])
{
    if (!is_array($params['assertion'] ?? null)) {
        return response_error(Ajax::ERROR_VALIDATION, 'That passkey could not sign you in.');
    }

    try {
        $login_user = Rsx_Two_Factor::verify_passkey_login($params['assertion']);
    } catch (Auth_Throttled_Exception | Two_Factor_Failed_Exception $e) {
        return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
    }

    return ['redirect' => static::post_login_destination((int) $login_user->id, null)];
}
```

```html
<div class="login-alternatives">
    <div class="login-divider"><span>or</span></div>
    <Passkey_Sign_In $controller="Login_Controller" $method="passkey_login" />
</div>
```

In a browser without WebAuthn the control renders nothing and marks its root `Passkey_Sign_In--unsupported`; hide the whole block then, or the "or" rule hangs over nothing: `.login-alternatives:not(:has(.Passkey_Sign_In:not(.Passkey_Sign_In--unsupported), .Sso_Buttons)) { display: none; }`.

- **It is a complete sign-in; no second factor follows** (framework ruling). User verification is REQUIRED on this ceremony, so the passkey is two factors on its own; an assertion without the UV flag is refused.
- **`is_enabled()` keeps its meaning** - "a PASSWORD or FEDERATED sign-in owes a challenge" - and SSO still reads it: a Google sign-in by an identity with a passkey still faces the challenge, and can answer it with that passkey.
- `verify_passkey_login()` does the whole sign-in: throttle first, one recorded failure (`STATUS_FAILED_PASSKEY`) and one sentence whatever went wrong, the membership refusal (`STATUS_FAILED_DISABLED`), `last_login`, the success row, and it forgets any pending password-stage challenge.
- No Turnstile on the endpoint - the component posts only `{assertion}`, and the throttle is spent first.

Worked examples: `reference_app/app/login/login_controller.php` (`passkey_login`) and `reference_app/portal/auth/Portal_Login_Controller.php`.

---

## The client portal

`Rsx_Portal_Two_Factor` is the same API for portal users - TOTP, passkeys, recovery codes, the challenge, passwordless sign-in. What differs: credentials in `_portal_two_factor_credentials`; admission is `Portal_User_Model::can_login()` AND the site the app declared (`Portal_Session::set_site_id`) - a passkey of another tenant's portal user is refused; failures feed `Login_Throttle::record_failure()` directly (the portal has no login history); the rpId is the portal's HOST when `PORTAL_URL` gives it one of its own (the application host otherwise; a prefix never enters it), so moving the portal to another host strands passkeys enrolled under the old one; "View as Client" refuses enrollment and removal.

**The realms never cross.** Separate tables, separate session keys, `'portal-<id>'` user handles: a staff passkey signs nobody in on the portal and the reverse. Handing a `Login_User_Model` to `Rsx_Portal_Two_Factor` (or the reverse) throws.

**The components pick the realm from the page.** `Rsx_Two_Factor.controller()` answers `Rsx_Portal_Two_Factor_Controller` on a portal page, and every shipped component uses it - drop `<Passkey_Sign_In>`, `<Two_Factor_Challenge>`, `<Passkey_Register>` or `<Totp_Enrollment>` on a portal page with no realm argument.

The portal password login becomes two-stage the same way: after `check_password()` + `can_login()`, when `Rsx_Portal_Two_Factor::is_enabled($portal_user)`, park the destination with `Portal_Session::put_value()`, `begin_challenge($portal_user)`, and redirect to a verify page hosting `<Two_Factor_Challenge>` instead of calling `set_portal_user_id()`. Worked example: `reference_app/portal/auth/Portal_Login_Controller.php` and `reference_app/portal/settings/Portal_Settings_Security.jqhtml`.

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

## Emailed codes - the issued-code recipe

A six-digit code the framework MINTS and the application DELIVERS, for a site that wants a second step from people who enrolled nothing (every portal client, say). Full recipe: `rsx:man two_factor_codes`; worked example behind `rsx.portal.emailed_sign_in_codes` (off): `reference_app/portal/auth/Portal_Login_Controller.php` (`challenge_accepts()`, `send_code()`).

```php
// The login function: this sign-in owes a code - or any factor the user holds
Rsx_Two_Factor::begin_challenge($login_user, [
    Rsx_Two_Factor::ANSWER_ISSUED_CODE, Rsx_Two_Factor::ANSWER_TOTP,
    Rsx_Two_Factor::ANSWER_PASSKEY, Rsx_Two_Factor::ANSWER_RECOVERY_CODE,
]);

// The send endpoint (#[Auth('public')] - the session is signed out)
$code = Rsx_Two_Factor::issue_code();     // six digits; only a keyed hash is kept
(new Sign_In_Code_Email($code))->to(Rsx_Two_Factor::pending_identity()->email)->send();
```

- **`$accepts` is ENFORCED.** `verify_challenge()` tries only the listed kinds; a correct answer of an unlisted kind is a wrong answer. A list the identity cannot answer throws before the sign-out. Null = what the identity holds (today's behaviour).
- **Everything about the code is the framework's; everything about the policy is yours** - who owes one, what else is accepted, the email, the resend cap (`challenge_pending()['codes_issued']`), remember-this-device. A later code replaces an earlier one; it lives as long as the challenge (`rsx.two_factor.challenge_window_minutes`, 15); wrong codes spend the same attempt caps as TOTP.
- **The email is `CATEGORY = SECURITY`** - it reaches a block-listed address and ignores the opt-out. **Delivery must be `live`**: in the default `suppressed` mode nobody can sign in.
- `<Two_Factor_Challenge ... $send_controller $send_method>` sends the first code itself and offers "Send a new code".
- **SSO**: the framework drives that ceremony, so it asks `sso.two_factor.accepts` / `portal.sso.two_factor.accepts` - answer from the same function the password login uses.
- A passwordless passkey sign-in owes nothing, so a user who registers a passkey skips the email.

---

## Requiring a factor - the application's own step

The framework decides only whether an identity HAS a factor. It reads no "required" setting and ships no screen that holds a signed-in user until they enroll; the reference application requires nobody to. An application that wants the rule writes it as its own sign-in step:

- the login function works out once, at sign-in, what this person still owes (your predicate and `!Rsx_Two_Factor::is_enabled($login_user)`) and parks it with `Session::put_value()`;
- `Main::pre_dispatch()` redirects a page request to your enrollment screen while that value is set, leaving the login, enrollment and logout routes alone;
- the screen hosts `<Totp_Enrollment />` / `<Passkey_Register />` and clears the value on `enrolled` / `registered`.

A user past the password is signed in - what they may do is the gates' business as on any request; an outstanding step is not an access level. A rule switched on today reaches each person at their next sign-in. Enrollment is refused while impersonating, so skip the step there. A step skipped for good is a preference variable (`$login_user->set_variable()`, `rsx:man user_preference_variables`).

---

## Component contracts

| Component | Args | Posts / Events |
|---|---|---|
| `<Totp_Enrollment />` | none | fires `enrolled` when the user acknowledges the code sheet (the factor is already live) |
| `<Passkey_Register />` | none | fires `registered`; renders a plain notice instead of a button when WebAuthn is absent |
| `<Two_Factor_Challenge $controller $method [$cancel_url] [$placeholder] [$send_controller $send_method] />` | `$controller`/`$method` REQUIRED | posts `{code}` or `{assertion}`; expects `{redirect}` and follows it with `window.location`; fires `no_challenge` when nothing is pending. `$cancel_url` adds Cancel (`challenge_abandon`, then navigate; Cancel + Verify become a centred row beneath the box). The box's placeholder is derived from `has_issued_code` / `has_totp` / `has_recovery_codes` (no box at all for a passkey-only challenge); `$placeholder` replaces it. `$send_controller`/`$send_method`: the app's issue-and-deliver endpoint (posts `{}`) - sends the first code itself, offers "Send a new code". Full address instead of the masked one: `rsx.two_factor.challenge_shows_full_email` |
| `<Passkey_Sign_In $controller $method [$label] />` | endpoint REQUIRED | runs the passwordless ceremony, posts `{assertion}`, expects `{redirect}`; fires `signed_in`; renders nothing without WebAuthn |

All four are **layout-neutral by contract** - no card, no heading, no width. The host page owns the box. One input takes every typed answer - an issued code, an authenticator code, a recovery code; the server tries each accepted kind. All four pick the realm from the page.

JS helpers: `Rsx_Two_Factor.is_supported()`, `controller()` (the page realm's controller), `register_passkey(label)`, `authenticate_passkey()` and `sign_in_with_passkey()` (each returns the assertion; neither posts it).

---

## Gotchas

- **Do not double-count the throttle.** `Login_History::record_failure(..., STATUS_FAILED_2FA, ...)` already feeds `Login_Throttle`. Calling `Login_Throttle::record_failure()` beside it halves the real budget, and the halving is only ever discovered by a user locked out early.
- **Two attempt caps sit beside the throttle, independent of IP.** Every wrong answer counts against the CHALLENGE (`challenge_max_failures`, 5: the answer that reaches it destroys the challenge, so the user signs in again) and against the IDENTITY (`identity_max_failures`, 10, inside the 60-minute `identity_failure_window_minutes` security window: once reached, `is_locked()` is true and verification is refused - a correct code included - until the window closes). A correct answer clears the count. Release a locked user with `rsx:users:2fa:unlock --user=` (staff) or `Rsx_Portal_Two_Factor::clear_failures($portal_user)`; both messages are user-safe `Two_Factor_Failed_Exception`s, rendered as-is.
- **Park before you log out.** Anything the challenge must carry across (an invite code, a redirect) is written with `Session::put_value($key, $value, Rsx_Two_Factor::challenge_expires_at())` **BEFORE** `begin_challenge()`. `put_value()` establishes the session row; the logout clears the identity, not the row, and `_session_values` survive by FK. Written afterwards, it lands on a session the caller abandoned.
- **A dismissed browser prompt is not an error.** `NotAllowedError` is caught and answered as `null`; say nothing and leave the button available.
- **The WebAuthn ceremony timeout is the framework's: 300 seconds** (`Passkeys::CEREMONY_TIMEOUT_SECONDS`, sent as `publicKey.timeout` = 300000 on registration, the second-factor assertion and passwordless sign-in, both realms) - a browser UI hint sized for the cross-device QR flow, which a 20-second prompt cannot hold. The browser may clamp it. The server's challenge window is derived from it (300 + 60 s margin, `Passkeys::challenge_window_seconds()`); `challenge_window_minutes` does not govern a WebAuthn challenge.
- **"My passkey would not save" - read the identity's `_login_history`** (the /_sys panel's Sign-ins tab). Staff enrollments record `passkey_enroll_begun` / `passkey_enrolled` / `passkey_enroll_failed` / `passkey_enroll_abandoned`; the portal logs `Portal passkey enrollment` lines instead. Begun then abandoned with no failed row = no confirmation ever reached the server: the browser or the phone's credential provider declined (the usual cause of a generic browser error right after a QR scan - some authenticator apps are not general passkey providers for arbitrary sites). A failed row is the server refusing a response it received; its `failure_reason` says why. Abandonment is recorded by the next begin in that browser or by the hourly `Session_Values_Cleanup_Service` sweep.
- **No Turnstile on the verification endpoint.** `<Two_Factor_Challenge>` renders no widget, so there is no `__turnstile` field and the completeness guard stays silent. The surface is still guarded: it answers only from the caller's own pending challenge, and `verify_challenge()` spends the brute-force budget first.
- **`credential_key`, not `credential_id`** - it is an opaque string handle from an authenticator, and `SCHEMA-TYPE-01` reserves `_id` for integers.
- **Staff and portal passkeys are separate.** A person who uses both enrolls one in each; the realm that did not issue a credential simply does not know it.
- **A passkey is bound to the hostname it was enrolled on** (the relying party id is the bare host). Moving environments invalidates it; that is the spec, not a bug.
- **Recovery-code plaintext exists exactly once.** A lost sheet is regenerated, never reprinted.
- **`challenge_state` returning `null` is not an error** - expired, already signed in, or a direct visit all read the same, and all three send the visitor back to `/login`.
- **A `RuntimeException` out of the facade is a wiring mistake** (nobody signed in, or impersonating). Do not catch it; only `Two_Factor_Failed_Exception` and `Auth_Throttled_Exception` are user-facing.

---

## Operator commands

`--user=<id|email>` is required on all four; `--json` uses the standard envelope. They act on STAFF login identities only.

```
php artisan rsx:users:2fa:setup  --user=alice@example.com    # bootstrap/recovery: prints seed + codes ONCE, refuses a second seed
php artisan rsx:users:2fa:dump   --user=1                    # factors WITH decrypted TOTP seeds (codes are a count - they are hashed)
php artisan rsx:users:2fa:remove --user=1 [--id=7] [--force] # prompts; --json requires --force
php artisan rsx:users:2fa:unlock --user=1                    # clears the failure count (lifts an attempt-cap lock); factors untouched
```

They run as whoever holds shell access, which is a higher privilege than any identity in the app - which is why the impersonation rule that governs the web paths does not reach them. **Never call `cli_setup_totp()` / `cli_dump_credentials()` from a request path.**

Full contract: `rsx:man two_factor`. Related: `rsx:man session` (throttle, login history), `rspade:session-auth`, `rspade:turnstile`.
