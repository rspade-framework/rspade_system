# Concern: sso

## Domain overview & applicability

Federated sign-in: "Continue with Google/Microsoft/Facebook/Apple/X" on a login page, and
the connected-accounts list behind a settings screen. `Rsx_Sso` is the ONLY class
application code touches; `Socialite_Bridge`, `Sso_Identity_Model` and `Rsx_Sso_Controller`
are implementation behind it, and no application ever sees a Socialite object.

The division of labour IS the design, and most of what is tested here follows from it. The
framework owns the CEREMONY - state, PKCE, the token exchange, the throttle, the failure
record, and the shape of the identity that comes out. The APPLICATION owns POLICY - whether
an unknown Google account may create an account here, which addresses it may match, where a
signed-in user lands - and it says so through event hooks (`sso.identity.unlinked`,
`sso.login.authorize`, `sso.two_factor.verify_url`, `sso.login.destination`,
`sso.link.destination`). A framework
that guessed at any of those would be inventing "create an account", which is the one
decision it must never make for an application. So the FAIL-CLOSED default is a first-class
property here, not an edge case.

The second central property is that an identity provider's word is not a login. A proven
provider account that is connected to no local identity is parked as HALF-AUTHENTICATED
data with its own expiry, exactly as `Rsx_Two_Factor` parks a passed password, and nothing
is signed in until the application redeems it. A provider sign-in also still faces this
application's own second factor unless the install explicitly opted out.

Adjacent pieces this concern leans on but does not own: `Session` (the session-value store
that survives a logout, and the CLI identity/impersonation seams), `Login_History`
(`STATUS_FAILED_SSO`), `Login_Throttle` (the brute-force budget, fed exactly once per
failure by way of `Login_History::record_failure()`), `Rsx_Two_Factor` (the challenge a
federated sign-in still faces), `Rsx_Csrf` (the Apple `form_post` exemption), and
`Event_Registry` (the five hooks, and the `_set_test_handlers()` seam these tests drive
them through).

## Source files

- `app/RSpade/Core/Sso/Rsx_Sso.php` - the facade: the roster, the ceremony, the pending
  identity, linking and removal
- `app/RSpade/Core/Sso/Socialite_Bridge.php` - the only class that knows Socialite exists;
  carries the stateless/PKCE/Apple spike findings in its class docblock
- `app/RSpade/Core/Sso/Sso_Identity_Model.php` - the `_sso_identities` model
- `app/RSpade/Core/Sso/Sso_Failed_Exception.php` - the user-safe failure
- `app/RSpade/Core/Sso/Rsx_Sso_Controller.php` - the routes (`/_sso/:provider/begin`,
  `/_sso/:provider/callback`) and the settings Ajax surface
- `app/RSpade/Core/Sso/Rsx_Sso.js` - the browser's reader over `window.rsxapp.sso`
- `app/RSpade/Core/Sso/Sso_Buttons.jqhtml|.js|sso_buttons.scss` - the "Continue with ..."
  buttons, rendered as anchors to `begin_url`
- `app/RSpade/Core/Sso/resource/icons/*.svg` - the brand marks, read into `icon_svg`
- `app/RSpade/Core/Bundle/Rsx_Bundle_Abstract.php` - the conditional `sso` rsxapp key
  (absent when no provider is live), and `BundleCompiler.php` / `Core_Bundle.php` - the
  force-included controller stub and the component include line
- `app/RSpade/Core/Session/Rsx_Csrf.php` - the Apple callback exemption
- `app/RSpade/Core/Session/Login_History.php` - `STATUS_FAILED_SSO`
- `app/RSpade/Commands/Rsx/Sso_Dump_Command.php`, `Sso_Unlink_Command.php` and
  `Sso_Cli_Support.php` - the operator commands (`rsx:users:sso:dump|unlink`); the support
  class delegates its JSON envelope to `Api_Key_Cli_Support`, as `Two_Factor_Cli_Support` does
- `database/migrations/2026_09_04_110416_create_sso_identities_table.php`
- Config: `config/rsx.php`, the `sso` block (`providers`, `custom`, `skip_two_factor`,
  `pending_window_minutes`)

The APPLICATION half, which the framework deliberately does not own, is `rsx/handlers/`
(the five hooks), the login page's `<Sso_Buttons />`, and the settings screen's Connected
Accounts section.

## Man page(s)

- `man/sso.txt`

## Testable surface

- The roster: nothing live by default, exactly the public fields reaching a template, the
  icon fallback, and the HALF-CONFIGURED ENABLE that throws naming the literal `.env` keys
  (all four of Apple's at once). Plus the deliberate indistinguishability of an unknown key
  and a disabled one. (php)
- The custom-provider seam, tested continuously rather than once: `Fake_Sso_Provider` is
  reachable ONLY through `rsx.sso.custom`, so every ceremony test is also a test of the
  seam. Its own row covers extra-key pass-through and the refusal of an entry naming no
  installed class. (php)
- The state: parked before the redirect and carried in the URL, freshly minted per
  ceremony, SINGLE USE (forgotten on the way in, before anything is verified), compared
  with `hash_equals`, and bound to the provider it was started for. Plus the window, which
  is a SECURITY window - an abandoned ceremony stops being redeemable and expiry is a
  working outcome. (php)
- The unlinked branch: parked, handed to the application, authenticating nobody; the
  identity shape the hook receives; `email_verified` as a CLAIM and never an inference; and
  FAIL CLOSED with no handler or an all-declining one. (php)
- Redemption: `link_pending()` writes the row and consumes the pending value,
  `consume_pending_and_login()` signs in and writes exactly one success row and stamps the
  link, and the destination hook chooses the landing page (`/` by default). (php)
- The linked branch: a second ceremony signs straight in with no application involvement,
  and the `sso.login.authorize` gate can deny it - which is where an application enforces
  its own account vocabulary, so that a federated sign-in is not a way around the checks a
  password sign-in performs. (php)
- The local second factor: an enrolled identity is NOT signed in by a provider - it lands on
  the verify page with a challenge pending - and `skip_two_factor` is the opt-out. (php)
- Connections: the takeover refusal (one provider account, at most one local identity),
  re-linking the same identity as a snapshot refresh, metadata-only listing, a connection to
  a since-disabled provider still listed and removable, removal scoped to its own identity,
  and the impersonation refusals - including the deliberate asymmetry that `unlink_all()`
  does NOT consult impersonation, because its caller is a shell. (php)
- Failure accounting: a failure is recorded ONCE, so `Login_Throttle` counts it once. The
  denied-gate path is the one that can double count and is pinned by a per-email counter
  read. (php)
- The HTTP surface's own decisions, which are all security judgements made BEFORE the facade
  is reached: an unknown key and a disabled one answering the same 404 while a HALF-CONFIGURED
  one still throws through it; `intent=link` refused rather than downgraded to a sign-in; and
  the Apple `form_post` leg, which re-emits a whitelist of three parameters, resolves no
  provider and reads no session - the promise `Rsx_Csrf::enforce()`'s exemption is written on.
  (php)
- What only real HTTP can decide: the CSRF exemption's PATH-EXACTNESS, tested as a matched
  pair one path apart, and the `#[Auth]` gates on the settings endpoints, which the dispatcher
  evaluates and nothing else does. (http)
- The operator path: `rsx:users:sso:dump` reporting a connection's metadata (and a connection
  whose provider is no longer configured AS SUCH, rather than as a live one), zero connections
  exiting 0 because a dump is a state answer and not an assertion, and `rsx:users:sso:unlink`
  requiring EXACTLY ONE of `--id`/`--all` and refusing another identity's connection id rather
  than reporting the facade's no-op as a disconnection. (cli)
- NOT covered by a suite row: the rendered buttons on a real page, the settings section's
  three row states, and the presence/absence of the `sso` rsxapp key. All three were verified
  by hand with `rsx:debug` behind a temporary config edit; they are catalogued as `planned`
  because writing the first playwright harness for an auth-flow concern is a harness decision
  (`two_factor`, the nearest neighbour, has none) and because a suite row would need an
  enabled provider committed to config. (playwright, planned)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).

## Where the application boundary sits

`Rsx_Sso::handle_callback()` ends at a REDIRECT the application chose, never at a page the
framework rendered - and with no application handler it fails closed rather than inventing
one. That is the boundary in one sentence, and the fail-closed rows are the tests that will
notice if it ever moves.

## Notes for whoever runs these

There is NO NETWORK anywhere in this concern, and there are no provider credentials. Every
ceremony runs through `php/Fake_Sso_Provider.php`, which overrides `user()` and reads the
identity it should assert off the callback request, so no token exchange is ever attempted
while everything above it - the state check, the window, the branches, the hooks - runs
exactly as it does for a real provider.

The cli tier runs in-process through `Artisan::call()`, so its commands write on the test
connection and the per-test transaction rolls every connection back. It seeds
`_sso_identities` rows DIRECTLY rather than running a ceremony to get them - the ceremony is
the php tier's subject, and a CLI test that depended on it would fail twice for one cause.

`setup()` and `teardown()` are per-CLASS, not per-test, so every test here restores the
config keys it changed (in a `finally`) and starts from an anonymous session. A leaked
half-configured provider is a particularly nasty failure, because it makes
`enabled_providers()` throw inside tests that are about something else entirely.

The http tier runs against the DEV server and dev database, like `tests/two_factor/http` -
but it writes nothing and needs no account: every assertion is about a refusal, a redirect or
a gate. It deliberately does NOT try to enable a provider, because a bash test cannot reach
the running server's config and committing an enabled provider to make a test pass would be a
live misconfiguration. The begin-redirect shape is pinned in the php tier instead.

Live validation against real Google/Apple/X developer-console applications is an
owner-operated follow-up; it needs real consoles and cannot live in this suite.
