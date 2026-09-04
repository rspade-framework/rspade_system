---
name: sso
description: "Wiring federated sign-in (Google, Microsoft, Facebook, Apple, X, or any Socialite provider) into an application - Rsx_Sso (enabled_providers / pending / link_pending / consume_pending_and_login / identities_list / unlink), the sso.identity.unlinked, sso.login.authorize, sso.two_factor.verify_url, sso.login.destination and sso.link.destination hooks, <Sso_Buttons /> and its $intent=\"link\" spelling, Rsx_Sso_Controller's identities_list / identity_unlink / link_begin endpoints, and rsx:users:sso:dump / :unlink. Use when adding 'Continue with Google' to a login page, building a Connected Accounts settings section, choosing an account policy (verified-email match, auto-provision, invite-only, finish registration), adding a provider through rsx.sso.custom, or when hitting 'No account is connected to this sign-in.', 'That sign-in took too long. Please try again.', 'That account is already connected to a different sign-in.', 'is enabled but SSO_GOOGLE_CLIENT_SECRET is not set', a 404 on /_sso/<key>/begin, or 'Connected accounts cannot be changed while impersonating another user.'"
---

# Federated sign-in (SSO)

`Rsx_Sso` is the whole subsystem's front door. `Socialite_Bridge`, `Sso_Identity_Model` and `Rsx_Sso_Controller` are implementation - application code never touches them and never sees a Socialite object.

**Switching a provider on is configuration, not code.** Five built-ins ship: `google`, `microsoft`, `facebook`, `apple`, `x`. An `.env` flag plus credentials makes the button appear and the ceremony work. A sixth provider is one composer package and one config block.

**The framework owns the CEREMONY; the application owns the POLICY.** State, PKCE, the token exchange, the throttle, the failure record and the normalized identity are the framework's. Whether an unknown Google account may become an account here is the application's, answered through hooks that all **fail closed**.

Full contract: `php artisan rsx:man sso`.

---

## The one decision: `sso.identity.unlinked`

A provider proved somebody owns an account and nothing local is connected to it. Return a **destination URL** to take responsibility, or `null` to decline. All declining (or no handler) = fail closed: the pending identity is discarded and the user gets **"No account is connected to this sign-in."**

The identity array every hook and screen receives:

```
provider_key       'google' | 'x' | your custom key
provider_user_key  the provider's subject, as asserted (NOT provider_user_id)
email              string or NULL
email_verified     bool - a CLAIM, never an inference
name               string or NULL
avatar_url         string or NULL
```

### Mode 1 - verified-email match (what the template ships)

```php
#[OnEvent('sso.identity.unlinked', priority: 10)]
public static function unlinked($data)
{
    $email = isset($data['email']) ? trim((string) $data['email']) : '';

    if ($email === '') {
        return null;                          // X sends none; Facebook may withhold
    }

    if (!empty($data['email_verified'])) {
        $login_user = Login_User_Model::where('email', $email)->first();

        if ($login_user !== null) {
            try {
                return Rsx_Sso::consume_pending_and_login($login_user);
            } catch (Sso_Failed_Exception $e) {
                Flash_Alert::error($e->getMessage());   // user-safe by contract

                return Rsx::Route('Login_Controller::index');
            }
        }
    }

    $invitation = self::_open_invitation_for($email);   // invite-aware second branch

    if ($invitation !== null) {
        return Rsx::Route('Accept_Invite_Controller::index', ['code' => $invitation->invite_code]);
    }

    return null;
}
```

**NEVER match an unverified email.** A provider that lets a user type any address into a profile hands over a CLAIM, not a fact - matching it means anybody who can name your address at such a provider signs in as you, with no password and no notification. `email_verified` is the only thing that makes branch 1 safe. Google and Apple assert it; **Microsoft, Facebook and X do not**, so this policy declines those three by design.

The invite branch is what makes "Continue with Google" a **sign-up button for an invitee and not for a stranger**: the identity stays PENDING and is linked inside the transaction that creates the account.

### Mode 2 - auto-provision

Create the `Login_User_Model` (password = `Hash::make(random_hash(64))`, an unusable hash, never null) plus your own site profile row, then `Rsx_Sso::consume_pending_and_login($login_user)`. **Still require `email_verified`** - auto-provisioning an unverified address lets a stranger pre-register somebody else's email.

### Mode 3 - invite-only, strict

Drop branch 1 of mode 1, keep the invite branch. Nobody signs in until an administrator invited their address.

### Mode 4 - finish registration

Return a URL of your own. That page reads `Rsx_Sso::pending()` (safe to render; carries no token), collects what the product needs, and calls `Rsx_Sso::link_pending($login_user)` **inside the transaction that creates the account** - so a refused link rolls the account back rather than leaving one nobody can sign in to.

## The other four hooks

| Hook | Kind | Contract |
|---|---|---|
| `sso.login.authorize` | gate | `true` permits; **a STRING denies and is shown to the user**. Open by default. Must mirror what your password login enforces. |
| `sso.two_factor.verify_url` | resolve | `{login_user}` -> URL. **REQUIRED** when any identity can hold a factor; null is a `shouldnt_happen()`. |
| `sso.login.destination` | resolve | `{login_user}` -> URL, default `/`. Return the SAME function your password login returns. |
| `sso.link.destination` | resolve | `{login_user, identity}` -> URL, default `/`. Template returns Password & Security. |

## The buttons

```html
@if (Rsx_Sso::is_enabled())            {{-- the divider is the PAGE's job --}}
    <div class="login-divider"><span>or</span></div>
    <Sso_Buttons />
@endif

<Sso_Buttons $intent="link" />         {{-- settings: connect to the signed-in identity --}}
```

Anchors, not Ajax - the ceremony is a browser navigation. Renders **nothing** when nothing is enabled. Layout-neutral: the host owns the box. Brand marks are the providers' and their guidelines govern restyling (Google's are a review requirement).

## Connected Accounts (a settings section)

```javascript
this.data.sso_enabled   = Rsx_Sso.is_enabled();
this.data.sso_providers = Rsx_Sso.enabled_providers();
this.data.sso_identities = await Rsx_Sso_Controller.identities_list();

const { url } = await Rsx_Sso_Controller.link_begin({ provider: 'google' });
window.location = url;                 // a NAVIGATION, not a fetch

this.data.sso_identities = await Rsx_Sso_Controller.identity_unlink({ id });  // returns the refreshed list
```

Hide the whole section when `!Rsx_Sso.is_enabled()`. Render one row per LIVE provider, **plus a disconnect-only row for a connection whose provider has since been switched off** - the user must be able to remove something that is really there.

## Adding a provider

```
php artisan rsx:composer require socialiteproviders/okta
```

```php
'sso' => ['custom' => ['okta' => [
    'provider' => \SocialiteProviders\Okta\Provider::class,
    'label' => 'Okta',
    'icon_file' => '/path/to/okta.svg',        // or 'icon_svg' => '<svg .../>'
    'enabled' => true,
    'client_id' => env('SSO_OKTA_CLIENT_ID'),
    'client_secret' => env('SSO_OKTA_CLIENT_SECRET'),
    'base_url' => env('SSO_OKTA_BASE_URL'),    // any extra key the adapter declares
]]],
```

**No `SocialiteWasCalled` listener and no service provider.** RSpade never calls `Socialite::driver()` by name - the bridge constructs the class through `buildProvider()` and hands the extra keys to `setConfig()` when the adapter has it. Every key that is not framework vocabulary (`enabled`, `provider`, `label`, `icon_svg`, `icon_file`, `client_id`, `client_secret`) passes through. `laravel/socialite` and `socialiteproviders/manager` are exposed framework deps so the adapter resolves against them. Generic OIDC is deliberately not a built-in.

The same seam is the **test seam**: register a fake provider under `rsx.sso.custom` and drive `begin()` / `handle_callback()` against it - no network, no credentials.

## Redirect URI, for every console

```
https://<APP_URL host>/_sso/<provider key>/callback
```

Matched exactly by providers. Apple's `client_id` is the **Services ID** (`com.example.web`), never a bundle id, and takes three more credentials because its client secret is an ES256 JWT minted offline per exchange.

---

## Gotcha catalog

- **Stateless, with OUR state.** RSpade has no Laravel session, so Socialite runs stateless and the ceremony's state lives in `_session_values` (`sso.state`, `sso.pending`) with our own expiry. Session values survive `Session::logout()`, which is what lets an anonymous visitor park one and a 2FA challenge be handed across the same seam.

- **A PKCE provider (X, and custom adapters) runs behind an in-memory session store** that is never persisted and never a cookie; the verifier is lifted out and parked in `sso.state`. Nothing above the bridge changes.

- **Apple posts back cross-site** (`response_mode=form_post`), with no session cookie. `Rsx_Csrf::enforce()` exempts exactly `/_sso/apple/callback`, path-exactly, and that leg **does no work** - it 303s to the GET leg carrying `code`, `state`, `user` (a whitelist). Never add work to it; the exemption's safety is that promise.

- **X may return `email = null`** (the `users.email` scope needs approval) and Facebook's may be declined. Every policy must survive it.

- **`email_verified` is false for Microsoft, Facebook and X.** They assert nothing of the kind. Absence is false, never "probably".

- **`provider_user_key`, not `provider_user_id`.** `SCHEMA-TYPE-01` reserves `_id` for integers; this is somebody else's opaque handle, and the spelling is identical at every layer.

- **`window.rsxapp.sso` is ABSENT when nothing is live**, not empty. Ask `Rsx_Sso.is_enabled()`; never index the key.

- **One failure, one recorder.** `Login_History::record_failure(..., STATUS_FAILED_SSO)` already feeds `Login_Throttle`. Never call `Login_Throttle::record_failure()` beside it - it halves the real budget and only a locked-out user ever finds out.

- **Impersonation refuses** every change to what an identity is connected to: `begin(intent: link)`, `link_pending()`, `unlink()`, and the Ajax endpoints. `unlink_all()` is the shell's path and is the deliberate exception.

- **A provider sign-in still faces your 2FA** unless `rsx.sso.skip_two_factor` is true. The verify URL is resolved BEFORE the challenge begins, because beginning one logs the session out.

- **Unknown key and disabled key are the same 404**; a HALF-CONFIGURED provider throws instead, naming the literal `.env` keys.

- **`intent=link` is refused when nobody is signed in**, never downgraded to a sign-in - the downgrade would sign the user in as whoever owns the provider account.

- **Switching a provider off deletes nothing.** Dormant connections stay listed (`provider_label` falls back to the key; the CLI prints `(no longer configured)`) and work again when credentials return.

- **`unlink()` does not check that a password remains.** SSO-only accounts are legitimate; that policy is the application's.

- **Never commit an enabled provider** to make a rendering test pass - it is a live misconfiguration on every install that pulls it.
