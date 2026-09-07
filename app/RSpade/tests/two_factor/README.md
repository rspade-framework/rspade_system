# Concern: two_factor

## Domain overview & applicability

Second-factor authentication: the credentials an identity may add on top of its password,
and the login challenge that redeems them. Three factor kinds share one table and one
facade - an authenticator app (TOTP, RFC 6238), a passkey (WebAuthn), and the recovery
codes that back both up. `Rsx_Two_Factor` is the ONLY class application code touches;
`Totp`, `Passkeys`, `Recovery_Codes` and `Two_Factor_Credential_Model` are implementation
behind it.

The subsystem's central property is the shape of the login flow. A login function verifies
the password with `RsxAuth::attempt(record: false, touch_last_login: false)`, and if the
identity has a factor it calls `Rsx_Two_Factor::begin_challenge()`, which parks the pending
identity in a session value and LOGS THE SESSION BACK OUT. Between the two steps the
browser is NOT authenticated - a half-authenticated session carrying a "needs 2FA" flag
would be a flag every surface in the application had to remember to honour, and forgetting
it anywhere would make the second factor optional. `verify_challenge()` is the method that
redeems the parked state, and it is the one that logs the user in and records the success.

That flow rests on `_session_values` rows surviving a logout (the `_sessions` row is not
deleted, only its identity cleared), on the challenge window being a SECURITY window rather
than an operation timeout, and on every failure reaching `Login_Throttle` exactly once by
way of `Login_History::record_failure()`. All three are pinned here.

Adjacent pieces this concern leans on but does not own: `Session` (the session-value store
and the CLI identity/impersonation seams), `Login_History` (`STATUS_FAILED_2FA` and the
success rows), `Login_Throttle` and `Auth_Throttled_Exception` (the brute-force budget),
and `RsxAuth::login()` (which records nothing, by design, so `verify_challenge()` owns its
own history row).

## Source files

- `app/RSpade/Core/TwoFactor/Rsx_Two_Factor.php` - the facade: enrollment, the challenge, removal
- `app/RSpade/Core/TwoFactor/Totp.php` - RFC 6238 codes and RFC 4648 base32, pure and static
- `app/RSpade/Core/TwoFactor/Passkeys.php` - the WebAuthn wrapper over `lbuchs/webauthn`
- `app/RSpade/Core/TwoFactor/Recovery_Codes.php` - minting, bcrypt storage, consume-once
- `app/RSpade/Core/TwoFactor/Two_Factor_Credential_Model.php` - the `_two_factor_credentials` model
- `app/RSpade/Core/TwoFactor/Two_Factor_Failed_Exception.php` - the user-safe failure
- `app/RSpade/Core/TwoFactor/Rsx_Two_Factor_Controller.php` - the Ajax surface: enrollment,
  the settings list, and the two public challenge endpoints
- `app/RSpade/Core/TwoFactor/Rsx_Two_Factor.js` - the browser half of the passkey ceremonies
  (base64url <-> ArrayBuffer, `navigator.credentials.create/get`)
- `app/RSpade/Core/TwoFactor/Totp_Enrollment.*` / `Passkey_Register.*` /
  `Two_Factor_Challenge.*` - the three components, reaching every bundle via `Core_Bundle`
- `app/RSpade/Core/Bundle/Core_Bundle.php` (the directory include) and
  `app/RSpade/Core/Bundle/BundleCompiler.php` (the controller stub force-include)
- `app/RSpade/Commands/Rsx/Two_Factor_Setup_Command.php` / `Two_Factor_Dump_Command.php` /
  `Two_Factor_Remove_Command.php` - the operator commands (`rsx:users:2fa:setup|dump|remove`)
- `app/RSpade/Commands/Rsx/Two_Factor_Cli_Support.php` - their shared `--user` resolution and
  envelope shaping, delegating the JSON envelope to `Api_Key_Cli_Support`
- `database/migrations/2026_09_02_123023_create_two_factor_credentials_table.php`
- Config: `config/rsx.php`, the `two_factor` block (`issuer`, `challenge_window_minutes`)

The APPLICATION half of the flow, which the framework deliberately does not own:

- `rsx/app/login/login_controller.php` - the two-stage login: `index()` verifies the
  password with recording and the last_login stamp suppressed and issues the challenge,
  `verify()` renders the challenge screen, `verify_2fa()` is the verification endpoint
  `<Two_Factor_Challenge>` posts to, and `two_factor_setup()` is the forced-enrollment
  interstitial
- `rsx/app/login/login_verify.blade.php` / `login_two_factor_setup.blade.php` +
  `login_two_factor_setup.js` - the two screens
- `rsx/main.php` - `pre_dispatch()` bounces a `users.is_2fa_required` identity with no
  factor to the interstitial (exempting impersonation and `Session::TYPE_PLAYWRIGHT`)
- `rsx/app/frontend/settings/password_security/` - the settings screen over
  `credentials_list` / `credential_remove` / `recovery_regenerate`

## Man page(s)

- `man/two_factor.txt`

## Testable surface

- TOTP correctness against RFC 6238 Appendix B's SHA1 vectors, the base32 codec, and the
  Key URI Format the authenticator apps parse. (php - pure, no DB)
- `Totp::verify()`'s security rules: the +-1 clock-skew window, the refusal beyond it, the
  REPLAY FLOOR that refuses a spent timestep, and code-shape rejection before the HMAC
  runs. (php - pure, no DB)
- Recovery codes: the unambiguous alphabet, bcrypt storage, replace-not-append, consume-once
  by deletion, per-identity scoping, and formatting tolerance on redemption. (php)
- TOTP enrollment through the facade: a parked seed writes no row, a live code confirms and
  encrypts the seed with `Crypt`, a wrong code leaves NO confirmed row, and the QR code is
  inline-embeddable SVG. (php)
- Who may enroll: refused while impersonating (via the CLI seam
  `Session::cli_set_impersonator_login_user_id()`), and refused with nobody signed in. (php)
- Listing and removal: `list_credentials()` is metadata only and leaks no secret, removing
  the LAST factor cascades to the recovery codes, and removal is scoped to its own
  identity. (php)
- The login challenge: `begin_challenge()` logs out while the session row survives, the
  address is masked, an expired window reads as absent and cannot be redeemed, a wrong
  answer is counted exactly once, a correct answer signs in and writes exactly one success
  row, and a used TOTP code cannot be replayed. (php)
- The throttle budget and its `Auth_Throttled_Exception`, driven with an EXPLICIT ip.
  `Session::get_client_ip()` is null in CLI by design, so the ambient call inside
  `verify_challenge()` cannot be tripped from php; the end-to-end refusal is proved over
  real HTTP instead. (php + http)
- WebAuthn end to end against a SIMULATED AUTHENTICATOR
  (`php/Webauthn_Authenticator_Fixture.php` - a real P-256 keypair, real CBOR, real ECDSA
  signatures, verified by the library exactly as a hardware key would be): ceremony arg
  shape, the server-side single-use challenge, a full register/assert round trip, the
  anti-cloning signature-counter check, and the refusal of an unknown or unconfirmed
  credential without disclosing which. (php)
- Cross-identity refusals: another identity's TOTP code, recovery code or passkey never
  answers this challenge. (php)
- The Ajax surface: which gate each endpoint declares (enrollment demands a login, the
  challenge endpoints are public because the session is deliberately logged out), the wire
  shape of every payload, which failures become a user-safe `Error_Response` and which are
  left to surface loudly, and the impersonation refusal the controller adds to removal on top
  of the facade's own. (php)
- That the gate split is ENFORCED, not merely declared - only the dispatcher evaluates
  `#[Auth]`, so the accept and refuse sides are proved over real HTTP. (http)
- The APPLICATION's verification endpoint (`Login_Controller::verify_2fa`), which is the
  only worked example of the contract `<Two_Factor_Challenge>` is written against: `{code}`
  or `{assertion}` in, `{redirect}` out, and every failure - a wrong code, an empty answer,
  an expired window, the throttle refusal - a user-safe `ERROR_VALIDATION` rather than an
  exception the challenge screen cannot render. Plus the destination it computes: an invite
  parked across the challenge wins, one enabled membership lands on the dashboard, none
  lands on the unauthorized screen. (php)
- The whole two-stage login over real HTTP: a correct password stops at the challenge
  instead of entering the app, a live code completes the login, `last_login` is stamped
  (unreachable from CLI - `Session::set_login_user_id()` returns from its CLI branch before
  the stamp), exactly ONE success row is written for the two steps, and the ambient
  `Login_Throttle` call inside `verify_challenge()` refuses a locked-out client with a
  message that reaches the screen as itself. (http)
- The OPERATOR path (`rsx:users:2fa:setup|dump|remove`), which bypasses the session-bound
  enrollment ceremony entirely: the seed `setup` prints answers a real login challenge for
  that identity, a second seed is refused rather than stacked, `dump` reads the seed back
  decrypted, and `remove --id` REFUSES a credential belonging to somebody else where the
  facade would no-op. Plus what every framework CLI command owes a script - a required
  `--user` that is never defaulted, and a non-zero exit on both output forms. (cli)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).

## Where the application boundary sits

`Rsx_Two_Factor::verify_challenge()` is deliberately NOT a framework endpoint - the application
owns the verification endpoint, because the post-login destination is application logic. The
template app now ships one (`Login_Controller::verify_2fa`), so the method finally has an HTTP
path and the two rows that were deferred on its absence - tfa-chal-18 (last_login over HTTP)
and tfa-chal-19 (the ambient throttle refusal) - are implemented in
`http/two_factor_login_flow.sh`.

That script therefore tests a FRAMEWORK property through APPLICATION code, which is the only
way this particular property can be tested at all. If the template's login flow is rewritten,
the script is what will say so.
