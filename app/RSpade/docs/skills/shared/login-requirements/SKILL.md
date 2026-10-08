---
name: login-requirements
description: "Making a signed-in user do something before the site is theirs - Login_Requirement_Abstract (const REALM, const ORDER, is_satisfied, screen, surfaces, applies_while_impersonating) and Login_Requirements (outstanding / is_pending / destination / recheck / recheck_user), for staff and the client portal. The identity readers (Session::is_logged_in, get_user, Portal_Session::is_logged_in) answer NOT LOGGED IN everywhere but the surfaces the requirement lists. Use when requiring 2FA enrollment, accepting terms, a profile picture, connecting an OAuth account or any onboarding step after sign-in and before access, when replacing a pre_dispatch redirect interstitial, or when hitting 'Please finish signing in to continue', error_code 'requirement_pending', 'must declare const REALM', a user bounced back to a requirement screen, or the rsx:health 'Login Requirements' row."
---

# Login requirements

A **login requirement** is something a SIGNED-IN identity must do before it is ADMITTED: enroll a second factor, accept terms, set a profile picture, connect an account ("required actions" in Keycloak's vocabulary). While one is unmet, every identity reader answers **not logged in** except on the surfaces that requirement lists - so the user can do the requirement and nothing else, and a surface nobody thought about refuses them. It is a security boundary, not a redirect. Contract: `rsx:man login_requirements`.

**Not the second-factor challenge.** ANSWERING a factor is authentication - the identity is not proven yet, so the session is signed OUT (`rsx:man two_factor`). ENROLLING one, accepting terms, a profile picture - those happen after the identity is proven, and use features that need it. That is this.

---

## Declaring one

One class, anywhere under `rsx/` (beside its screen). The manifest finds it.

```php
class Terms_Requirement extends Login_Requirement_Abstract
{
    const REALM = 'staff';          // or 'portal' - REQUIRED, no default
    const ORDER = 10;               // sequence; default 100

    // THE TRUTH. No "mark done" exists - satisfying it is completing it.
    // $user: the site User_Model (staff, so per-site), the Portal_User_Model (portal).
    public static function is_satisfied(Rsx_Model_Abstract $user): bool
    {
        return $user->terms_accepted_at !== null;
    }

    // Where the user is sent: a #[Route] (staff) / #[Portal_Route] (portal) target.
    public static function screen(): string { return 'Terms_Controller::index'; }

    // EVERYTHING else the screen needs - routes and Ajax endpoints, framework ones too.
    public static function surfaces(): array { return ['Terms_Controller::accept']; }
}
```

The endpoint does the work and asks where next:

```php
$user->terms_accepted_at = Rsx_Time::now_iso();
$user->save();

return redirect(Login_Requirements::recheck() ?? Rsx::Route('Dashboard_Index_Action'));
```

---

## The rules that matter

- **A listed surface sees the user; its own `#[Auth]` gates still run.** An unlisted one sees an anonymous caller.
- **Completion is automatic.** A pending identity asking for anything unlisted is re-evaluated once per request first - so after doing the requirement, the next request for any page admits them. A screen that finishes client-side can just navigate to `/`.
- **Steering is automatic.** A refused page redirects to the first outstanding screen (never the login page); a refused Ajax call answers `requirement_pending` with `metadata.destination` and the JS Ajax layer navigates there itself.
- **Use a Blade route for `screen()`.** A SPA bootstrap would serve a whole module's client side to a user who can call none of its endpoints.
- **List every endpoint the screen calls** - including framework ones: `'File_Attachment_Controller::upload'` for a picture, `'Rsx_Two_Factor_Controller::totp_begin'` etc. for enrollment, the OAuth callback route for a connection (it must see the user to store tokens on them). Model fetch is not listable - give the screen its own endpoint.
- **Logout**: a `#[Auth('public')]` logout runs for the anonymous-looking caller and needs no listing. A logout gated `is_logged_in` must be listed by every requirement.
- **Keep `is_satisfied()` cheap and read `$user`, not `Session`.** It runs at sign-in, on a staff site switch, on `recheck()`, and on every refused request of a pending user.
- **Impersonation is exempt** unless `applies_while_impersonating()` answers true. **Bearer-key API calls and the CLI are never subject.**
- **Policy changed mid-session** ("all admins must now enroll a factor"): `Login_Requirements::recheck_user($user)` re-evaluates every live session they hold.

---

## Recipes

| Requirement | `screen()` | `surfaces()` | `is_satisfied()` |
|---|---|---|---|
| Enroll a second factor | a page mounting `<Totp_Enrollment>` / `<Passkey_Register>` | `Rsx_Two_Factor_Controller::totp_begin`, `totp_confirm`, `passkey_register_begin`, `passkey_register_confirm` | `Rsx_Two_Factor::is_enabled($user->login_user_id)` (portal: `Rsx_Portal_Two_Factor`) |
| Profile picture | an upload page | `File_Attachment_Controller::upload`, your claim endpoint | the user has one |
| Connect an account | a page starting the OAuth trip | the begin route, the callback route | a connection row exists |
| Accept terms | the terms page | the accept endpoint | `terms_accepted_at` is set (and current) |

Worked example: `system/app/RSpade/resource/reference_app/app/login/two_factor_enrollment_requirement.php` (administrator-required 2FA on `users.is_2fa_required`) with its screen `Login_Controller::two_factor_setup`.

---

## Replacing a `pre_dispatch()` interstitial

A `Main::pre_dispatch()` redirect stops page loads only - Ajax and model fetches keep answering, and it must exempt its own interstitial route by hand. Move the predicate into `is_satisfied()`, the interstitial route into `screen()`, the endpoints it calls into `surfaces()`, and delete the redirect.

---

## Diagnosing

- **Bounced back to the screen after completing it** - `is_satisfied()` still answers false; it is the only signal.
- **"Please finish signing in to continue" from a step on the screen** - that endpoint is missing from `surfaces()`.
- **"Login requirement X must declare const REALM"** - add `const REALM = 'staff'` or `'portal'`.
- **`rsx:health` "Login Requirements" FAIL** - a `screen()` that is not a route in its realm, or a `surfaces()` entry naming nothing.
- In code, `Login_Requirements::outstanding()` / `is_pending()` / `destination()` show the current state; `_sessions.login_requirements` holds the stored list.
