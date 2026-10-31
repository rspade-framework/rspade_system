---
name: security-settings
description: "Designing and building an application's Settings > Security screen in RSpade, staff or portal - the sections (change password, two-factor / authenticator app / passkeys / recovery codes, connected accounts, where you're signed in, API keys), which framework endpoint and component serves each (Rsx_Two_Factor_Controller credentials_list / credential_remove / recovery_regenerate, <Totp_Enrollment>, <Passkey_Register>, Rsx_Sso_Controller identities_list / link_begin / identity_unlink, Session::get_sessions_for_user() / terminate_session(), Api_Key_Model minting), and the 'confirm it's you' gate in front of every action that adds or removes a way into the account - Rsx_Password_Confirmation::require_confirmed(), Rsx_Password_Confirmation.status() / verify() / verify_passkey() / clear(), <Password_Confirmation_Form>, an app dialog with a try limit, clearing in on_stop(), rsx.sessions.password_confirmation_minutes. Use when building or changing a security, password, two-factor, passkey, sessions or API-keys settings screen, gating a sensitive action of your own behind a password confirmation, or when hitting 'Your password confirmation has timed out. Refresh the page and try again.' or 'You cannot confirm the password of an account you are impersonating.'"
---

# The Settings > Security screen

One screen where a signed-in user manages how they get into their account. Every section is
served by the framework - endpoints, components, rules - and the application lays them out.
The template's screens are the worked examples, and are what a fork already has:

- staff: `system/app/RSpade/resource/reference_app/app/frontend/settings/password_security/`
  and `.../settings/api_keys/`
- portal: `system/app/RSpade/resource/reference_app/portal/settings/Portal_Settings_Security.*`

## The sections, and what serves each

| Section | Data | Actions | Detail |
|---|---|---|---|
| Change password | - | an `<Rsx_Form>` to your own endpoint: current password checked throttle-first (`Login_Throttle::require_not_throttled($email)`), a miss recorded with `Login_History::record_failure()`, the new hash saved through `Login_User_Model` (which signs every OTHER browser out itself) | `rsx:man session` |
| Two-factor | `Rsx_Two_Factor_Controller.credentials_list()` - factors, recovery codes left, whether codes are issued | `<Totp_Enrollment>` / `<Passkey_Register>` hosted in a dialog, listening for `'enrolled'` / `'registered'`; `credential_remove({id})`; `recovery_regenerate()` (show the codes once) | skill `rspade:two-factor`, `rsx:man two_factor` |
| Connected accounts | `Rsx_Sso.is_enabled()` / `enabled_providers()` (no fetch), `Rsx_Sso_Controller.identities_list()` | `link_begin({provider})` then `window.location = result.url`; `identity_unlink({id})` | skill `rspade:sso` |
| Where you're signed in | your own endpoint over `Session::get_sessions_for_user()` | `Session::terminate_session()` / `terminate_all_other_sessions()` | skill `rspade:session-auth` |
| API keys | your own grid over the identity's keys | mint (shown once), scopes, read-only, revoke; every endpoint re-asks `Session::has_api_access()` | skill `rspade:external-api` |

Portal: the same, through `Rsx_Portal_Two_Factor_Controller` / `Rsx_Portal_Sso_Controller`
(the components pick the realm from the page) and `Portal_Session`. Impersonation (staff
impersonation, View as Client) is refused by every mutating endpoint.

## Confirm it's you

**Every action that adds or removes a way into the account asks for the password again
first** - setting up an authenticator, registering or removing a passkey, regenerating
recovery codes, connecting or disconnecting a provider, minting an API key. A stolen session
could otherwise make itself permanent. The framework ENFORCES it on its own endpoints (the
list is in `rsx:man password_confirmation`); the application ASKS for it before calling them.

```javascript
// one line in front of every gated button
if (!await this.alive(Password_Confirmation_Modal.require())) return;
await this.alive(Rsx_Two_Factor_Controller.credential_remove({ id: credential_id }));
```

```php
// the first statement of a sensitive endpoint of your own
Rsx_Password_Confirmation::require_confirmed();
```

The parts:

- **`<Password_Confirmation_Form>`** (framework) - password with Turnstile, or "Use a passkey
  instead"; fires `'confirmed'`; `submit()` resolves true/false. Embed it; never re-implement
  the check.
- **The dialog** (application) - the template's `Password_Confirmation_Modal.require()` in
  `rsx/lib/password_confirmation/`: answers true at once while a confirmation is live, else
  `Modal.show()` hosting the form, Confirm calling `form.submit()`, the form's `'confirmed'`
  event (a passkey) closing it, THREE refused passwords closing it with a notice. The user
  stays signed in. Copy it; change the try limit or the copy as you like.
- **Clear on the way out** - `on_stop() { Rsx_Password_Confirmation.clear(); }` on every
  screen with gated buttons. The server's 5-minute expiry
  (`rsx.sessions.password_confirmation_minutes`) bounds it regardless.
- **A component that calls a gated endpoint itself** (`<Passkey_Register>`,
  `<Totp_Enrollment>`, `<Sso_Buttons $intent="link">`) is shown only AFTER the dialog: put it
  behind a button that awaits `require()` and then renders it, as the portal screen's "Add a
  sign-in method" does. Mounted unconditionally, its own button reaches the endpoint
  unconfirmed and is refused.
- **Changing the password is not gated** - it already asks for the current password.

## Pitfalls

- **"Your password confirmation has timed out. Refresh the page and try again."** - a gated
  endpoint was reached without a live confirmation. Either the button is not gated (add the
  `require()` line), or the user sat on an open enrollment longer than the configured minutes
  (expected; the message tells them what to do).
- **A test of a gated endpoint fails with that message** - the test's subject is the endpoint,
  not the confirmation: set `Rsx_Password_Confirmation::$force_confirmed_for_tests = true` in
  `setup()` and `null` in `teardown()`.
- **The dialog shows no password field** - an SSO-only or invited account with no password; it
  confirms with a passkey or not at all.
- **Counting tries on the server** - don't. The try limit is the dialog's; the server's limit is
  the sign-in throttle, which counts every refused confirmation against the account.

Contract: `rsx:man password_confirmation`. Related: `rsx:man session` (LOGIN THROTTLE),
`rsx:man two_factor`, `rsx:man sso`, `rsx:man external_api`.
