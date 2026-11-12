# Lib/SignIn/Sso — federated sign-in (Google, Microsoft, Facebook, Apple, X)

**Wiring a provider or the sign-in policy? Skill `rspade:sso` and `rsx:man sso`.** This file
says what is in this DIRECTORY, why it is shaped this way, and what must stay true when
editing it. Second factors are the sibling `Lib/SignIn/TwoFactor/`, built on the same split.

## The shape: one engine, two realm facades

`Rsx_Sso_Abstract` is the engine, written ONCE for both realms; an application never names it.

| Facade | Owner of a connection | Table | Ceremony URLs | Hooks |
|---|---|---|---|---|
| `Rsx_Sso` | `Login_User_Model` | `_sso_identities` | `/_sso/<provider>/begin`, `/callback` | `sso.*` |
| `Rsx_Portal_Sso` | `Portal_User_Model` (per site) | `_portal_sso_identities` | the same under the portal's prefix and host | `portal.sso.*` |

The provider REGISTRY (which providers exist, credentials, brand marks) and the provider layer
are realm-agnostic and shared - one Google client id serves both realms - but **a portal
ceremony can never resolve into a staff sign-in, or the reverse**: different session keys,
different identity tables, different session facades, different hooks. The portal realm is OFF
until `rsx.sso.portal_enabled`, so switching a provider on for staff never puts a button on the
client portal.

**The framework owns the CEREMONY; the application owns the POLICY.** State, PKCE, the token
exchange, the throttle and the one `STATUS_FAILED_SSO` record are here. What happens to a
proven identity connected to no local account, whether a sign-in is allowed, where it lands
and what second factor it owes are the application's answers through hooks that all FAIL
CLOSED (`sso.identity.unlinked`, `sso.login.authorize`, `sso.two_factor.verify_url`,
`sso.login.destination`, `sso.link.destination`). An unconnected identity is parked
half-authenticated (`pending()`) and redeemed with `link_pending()` /
`consume_pending_and_login()` - nothing is signed in until then.

**A provider is live because its `enabled` flag is true and for no other reason**; one enabled
with a missing credential THROWS naming the literal `.env` keys.

## What is here

- `Rsx_Sso_Abstract.php` — the engine and its docblock (the flow and the reasoning): the
  registry, `enabled_providers()`, `handle_callback()`, the pending identity, account
  management (`identities_list()`, `unlink()`, `unlink_all()`; linking refuses while
  impersonating).
- `Rsx_Sso.php`, `Rsx_Portal_Sso.php` — the facades; the portal one adds its switch, its own
  callback URL (a second redirect URI per provider console, `callback_url()`) and site scoping.
- `Socialite_Bridge.php` — the ONLY class that knows `laravel/socialite` exists. RSpade has no
  Laravel session, so Socialite runs `stateless()` and RSpade mints, parks and verifies its own
  state; the class docblock records why each Socialite path is safe.
- `Rsx_Sso_Controller.php`, `Rsx_Portal_Sso_Controller.php` — the ceremony routes
  (`#[Auth('public')]` in the gate sense only: the parked state is the guard) and the Connected
  Accounts Ajax endpoints. Portal ceremony routes are `#[Portal_Route]` because the portal's
  site is declared in portal dispatch.
- `Sso_Identity_Model(_Abstract)`, `Portal_Sso_Identity_Model(_Abstract)` — "this identity is
  also that provider subject". No tokens stored, ever. `(provider_key, provider_user_key)` is
  UNIQUE (per site on the portal) - the security property the subsystem rests on.
  `provider_key` is a registry string, not an enum (owner ruling): providers are open-ended.
- `Sso_Failed_Exception.php` — a user-safe sentence and nothing else; any detail would be an
  enumeration oracle. Configuration mistakes are RuntimeExceptions instead.
- `Rsx_Sso.js`, `<Sso_Buttons />` (+ `sso_buttons.scss`, `resource/icons/`) — the roster read
  from `window.rsxapp.sso` (absent means off); buttons are anchors, and render NOTHING when no
  provider is enabled. The "or" divider is the page's job.

## Invariants

- **Never match an unverified provider email onto a local account** - that is account takeover.
- A provider sign-in still faces the local second factor unless `rsx.sso.skip_two_factor`.
- An access token is never stored; RSpade asks a provider "who is this?" and nothing else.

## References

`rsx:man sso` · skills `rspade:sso`, `rspade:two-factor`, `rspade:event-hooks` · siblings
`Lib/SignIn/TwoFactor/`, `Core/Session/` (session values, `Login_History`), `Core/Auth/`.
