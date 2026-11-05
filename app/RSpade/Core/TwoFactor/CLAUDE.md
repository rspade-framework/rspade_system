# Core/TwoFactor — second factors, passkeys and recovery codes

**Adding a second factor to a sign-in? Skill `rspade:two-factor`, `rsx:man two_factor` and
`rsx:man two_factor_codes`.** This file says what is in this DIRECTORY, why it is shaped this
way, and what must stay true when editing it. Federated sign-in is the sibling `Core/Sso/`.

## The shape: one engine, two realm facades

`Rsx_Two_Factor_Abstract` is the engine, written ONCE for both authentication realms; an
application never names it. It talks to one of two facades that bind it to a realm:

| Facade | Identity | Credentials table | Session | Records into |
|---|---|---|---|---|
| `Rsx_Two_Factor` | `Login_User_Model` (the cross-site identity) | `_two_factor_credentials` | `Session` | `Login_History` |
| `Rsx_Portal_Two_Factor` | `Portal_User_Model` (one site) | `_portal_two_factor_credentials` | `Portal_Session` | `Portal_Login_History` |

Both expose exactly the same API; what differs is answered through the realm hooks at the
bottom of the engine. **The realm boundary is the TABLE boundary**: a staff credential can
never satisfy a portal challenge because the portal realm never reads the staff table, and
the two realms' pending values sit under different session keys. Credentials belong to the
login IDENTITY, never to a site membership - a second factor proves who holds the browser,
and enrolling a phone once per tenant would be wrong.

**The framework owns the ceremony; the application owns the destination.** The engine mints,
stores, verifies, caps and records; the login function decides WHEN a challenge is owed and
WHAT it accepts (`begin_challenge($identity, $accepts)`), and the verification ENDPOINT -
where a passed challenge goes - is the application's. Nobody is signed in while a challenge
is pending: `verify_challenge()` is what signs in.

## What is here

- `Rsx_Two_Factor_Abstract.php` — the engine and its docblock (the login flow, the rulings):
  `is_enabled()`, enrollment, `begin_challenge()` / `verify_challenge()` (throttle first,
  per-challenge and per-identity caps), the emailed code (`issue_code()`, `issued_link()`),
  trusted devices (`trust_this_device()`, `device_is_trusted()` - the framework never skips a
  challenge itself), passwordless passkey sign-in (`begin_passkey_login()` /
  `verify_passkey_login()`, which owes no second factor), recovery codes, the operator path
  behind `rsx:users:2fa:*`. Enrollment and removal refuse while impersonating.
- `Rsx_Two_Factor.php`, `Rsx_Portal_Two_Factor.php` — the facades (the portal one adds
  admission: `can_login()` and the request's site).
- `Totp.php` — RFC 6238, hand-written and pure; SHA1 / 6 digits / 30 s are FIXED, because
  authenticator apps ignore anything else. `verify()` answers clock drift and replay.
- `Passkeys.php` — WebAuthn over `lbuchs/webauthn`: the relying-party id is the bare
  hostname (a passkey enrolled on one host does not work on another), attestation `none`.
- `Recovery_Codes.php` — bcrypt-hashed, shown exactly once; every call names its realm.
- `Two_Factor_Credential_Model(_Abstract)`, `Portal_Two_Factor_Credential_Model(_Abstract)` —
  one table per realm holding all three kinds by `type_id` (same ids in both); an unconfirmed
  row never satisfies a challenge. No `fetch()`, ever - reached only through a facade.
- `Two_Factor_Failed_Exception.php` — a USER-SAFE message (never which check failed, never
  attempts remaining) plus `reason()` (a `REASON_*` constant) for code. Distinct from
  `Auth_Throttled_Exception` ("we did not check").
- `Rsx_Two_Factor_Controller.php`, `Rsx_Portal_Two_Factor_Controller.php` — the browser's
  endpoints, identical names per realm, force-included in every bundle because the challenge,
  enrollment and passkey prompts live in different bundles.
- `Rsx_Email_Link_Controller.php` — where an emailed link lands; GET only READS (mail
  scanners fetch every link) and hands the token to the page in the URL fragment.
- Components: `<Two_Factor_Challenge $controller $method>`, `<Passkey_Sign_In $controller
  $method>`, `<Totp_Enrollment>`, `<Passkey_Register>`, plus `Rsx_Two_Factor.js` (the
  ArrayBuffer/base64url translation and the WebAuthn ceremonies; a dismissed prompt is null,
  not an error). Each picks its realm from the page (`Rsx_Portal.is_portal()`).

## References

`rsx:man two_factor`, `rsx:man two_factor_codes`, `rsx:man password_confirmation` · skills
`rspade:two-factor`, `rspade:security-settings`, `rspade:session-auth` · siblings `Core/Sso/`,
`Core/Session/` (login history, session values), `Core/Auth/` (`RsxAuth`, `Login_Throttle`),
`Core/Confirmation/` (the "confirm it's you" step enrollment requires).
