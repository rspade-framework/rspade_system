# Test catalog: sso

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-09-04.

## Sso_Providers_Test (php, no transactions - pure configuration) - the roster

Activity is CONFIGURED, not derived: nothing about the mode, the hostname or APP_URL makes a
provider live, only `enabled` does. The other half of that posture is that a half-configured
enable THROWS - silently skipping a provider whose credentials are missing hides an
operator's mistake behind a login page with one fewer button, which nobody notices until a
user asks where the Google button went.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-cfg-01 | nothing is live until something is switched on | shipped config | `enabled_providers() === []`, `is_enabled()` false | implemented |
| sso-cfg-02 | only public fields reach a template | one custom provider enabled | exactly `{key,label,begin_url,icon_svg}`, no credential | implemented |
| sso-cfg-03 | a missing brand mark is an empty string, not an error | provider with no icon file | `icon_svg === ''` | implemented |
| sso-cfg-04 | a custom provider supplies its own mark | `icon_svg` in the entry | that markup, verbatim | implemented |
| sso-cfg-05 | a half-configured enable names the literal env key | google enabled, secret blank | RuntimeException containing `SSO_GOOGLE_CLIENT_SECRET` | implemented |
| sso-cfg-06 | Apple reports every missing signing credential at once | apple enabled, three blanks | message names TEAM_ID, KEY_ID and PRIVATE_KEY | implemented |
| sso-cfg-07 | a DISABLED provider with no credentials is silent | google disabled, nulls | `[]`, no throw | implemented |
| sso-cfg-08 | unknown and disabled keys are refused identically | `provider('google')`, `provider('nonesuch')` | RuntimeException from both | implemented |
| sso-cfg-09 | a custom entry naming no installed class throws with the config path | bogus FQCN | RuntimeException containing `rsx.sso.custom.broken.provider` | implemented |
| sso-cfg-10 | extra keys pass through to the adapter, framework keys do not | custom entry + base_url, realm | `extra === {base_url, realm}` | implemented |
| sso-cfg-11 | the Apple CSRF-exemption constant matches the computed path | both spellings | equal, and `/_sso/apple/callback` | implemented |
| sso-cfg-12 | the pending window comes from config, not a constant | `pending_expires_at()` | within 2s of `pending_window_minutes` | implemented |

## Sso_Ceremony_Test (php, default isolation) - the ceremony end to end

Driven through `Fake_Sso_Provider`, which is reachable only via `rsx.sso.custom` - so every
row below is also a test of the custom-provider seam. No network, no credentials.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-cer-01 | the state is parked AND carried in the authorize URL | `begin('fake')` | URL state === parked state; provider and intent parked; client_id, redirect_uri, response_type correct | implemented |
| sso-cer-02 | each ceremony mints a fresh 32-byte state | two `begin()` calls | different, 64 hex chars | implemented |
| sso-cer-03 | a state nobody parked redeems nothing | callback with a forged state | redirect `/login`, not signed in, nothing pending | implemented |
| sso-cer-04 | A STATE IS SINGLE USE - forgotten before anything is verified | the same state twice | parked value gone after the first; the second parks nothing | implemented |
| sso-cer-05 | one provider's response cannot redeem another's ceremony | fake's state at fake_two's callback | nothing redeemed, not signed in | implemented |
| sso-cer-06 | an expired ceremony is a working outcome, not a throw | parked value expired 60s ago | nothing redeemed, not signed in | implemented |
| sso-cer-07 | an unlinked identity is parked and authenticates nobody | unknown subject + handler returning a path | redirect to that path, NOT signed in, `pending()` shape correct, handler saw the same array | implemented |
| sso-cer-08 | `email_verified` is a CLAIM, never an inference | raw payload asserting it | true; false when absent (sso-cer-07) | implemented |
| sso-cer-09 | FAIL CLOSED with no handler | `sso.identity.unlinked` = [] | redirect `/login`, pending discarded, failure counted once | implemented |
| sso-cer-10 | a declining handler is the same as none | handler returning null | redirect `/login`, nothing pending | implemented |
| sso-cer-11 | redemption links, signs in, records once, stamps the link | `consume_pending_and_login()` | destination from the hook, signed in, one success row, `last_login_at` set | implemented |
| sso-cer-12 | the default destination is `/` | no destination handler | `/` | implemented |
| sso-cer-13 | a linked identity signs straight in | second ceremony, same subject | destination, signed in, no application involvement | implemented |
| sso-cer-14 | the authorize gate can deny a linked identity | handler returning a message | redirect `/login`, not signed in, denial counted EXACTLY once | implemented |
| sso-cer-15 | THE 2FA PROPERTY: a provider sign-in still faces the local factor | enrolled identity | redirect to the verify URL, NOT signed in, challenge pending | implemented |
| sso-cer-16 | `skip_two_factor` is the opt-out | same, with the flag on | signed in, no challenge left pending | implemented |

## Sso_Linking_Test (php, default isolation) - connections

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-lnk-01 | link_pending writes the row and consumes the pending identity | pending + identity | row with key/subject/email snapshot; `pending()` null | implemented |
| sso-lnk-02 | nothing pending is a user-safe refusal | no pending value | Sso_Failed_Exception | implemented |
| sso-lnk-03 | THE TAKEOVER REFUSAL: one provider account, one local identity | same subject, second identity | Sso_Failed_Exception 'already connected'; still exactly one row | implemented |
| sso-lnk-04 | re-linking the SAME identity refreshes the snapshot | same subject, new email | same row id, new email | implemented |
| sso-lnk-05 | an expired pending identity cannot be redeemed | pending expired 60s ago | `pending()` null, link refused | implemented |
| sso-lnk-06 | abandon_pending leaves nothing redeemable | cancel | `pending()` null | implemented |
| sso-lnk-07 | identities_list is metadata only | one connection | exactly the seven display fields; label from config | implemented |
| sso-lnk-08 | a connection to a since-disabled provider is still listed | provider switched off | still listed; the key stands in for the label | implemented |
| sso-lnk-09 | removal is scoped to its own identity, and a stranger's call is a no-op | another identity's row id | unchanged, then removed by the owner | implemented |
| sso-lnk-10 | unlink_all removes every connection | two connections | none left | implemented |
| sso-lnk-11 | unlink refuses while impersonating | impersonated session | RuntimeException, nothing removed | implemented |
| sso-lnk-12 | unlink_all does NOT consult impersonation - it is the operator path | impersonated session | removed; the asymmetry is deliberate | implemented |
| sso-lnk-13 | a link ceremony requires a signed-in identity | anonymous `begin(intent: link)` | RuntimeException 'signed-in identity' | implemented |
| sso-lnk-14 | a link ceremony refuses while impersonating | impersonated session | RuntimeException 'impersonating', before the browser leaves | implemented |

## Sso_Controller_Test (php, default isolation) - the HTTP surface's own decisions

Rsx_Sso_Controller is thin, so what is pinned here is the handful of judgements it makes
BEFORE delegating - and every one is a security judgement. The Apple rows are the other end
of the promise `Rsx_Csrf::enforce()`'s exemption docblock makes: that leg 303s and does
nothing else, so nothing an unauthenticated cross-site caller sends is ever acted on.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-ctl-01 | a live key produces the provider redirect with the ceremony parked | `begin` on the fake provider | 302 to the authorize URL; state parked, intent login | implemented |
| sso-ctl-02 | an unknown key and a DISABLED key are the same 404, with the same message | `nonesuch`, then `google` | both 404, identical message | implemented |
| sso-ctl-03 | a missing route segment is refused, never resolved as the empty string | no `:provider` | 404 | implemented |
| sso-ctl-04 | THE 404 DOES NOT SWALLOW A MISCONFIGURATION | google enabled, secret blank | RuntimeException naming `SSO_GOOGLE_CLIENT_SECRET` | implemented |
| sso-ctl-05 | `intent=link` is REFUSED when nobody is signed in, never downgraded to a sign-in | anonymous | 403; nothing parked | implemented |
| sso-ctl-06 | `intent=link` is refused while impersonating, before the browser leaves | impersonated session | 403 naming impersonation | implemented |
| sso-ctl-07 | the Apple POST leg 303s to its own path as a GET, carrying the ceremony | POST code/state/user | 303; code, state and the one-shot user blob on the query | implemented |
| sso-ctl-08 | it re-emits a WHITELIST, so the exemption cannot smuggle a parameter | POST + intent/redirect/fake_identity | exactly `code` and `state` survive | implemented |
| sso-ctl-09 | IT DOES NO WORK - it resolves no provider and touches no ceremony | POST to an unknown key | 303 (no 404); nothing parked | implemented |
| sso-ctl-10 | the route segment is re-encoded rather than trusted into a Location header | `a b/c` | `/_sso/a%20b%2Fc/callback` | implemented |
| sso-ctl-11 | `link_begin` hands back a URL rather than redirecting an XHR | signed in, fake provider | `/_sso/fake/begin?intent=link` | implemented |
| sso-ctl-12 | `link_begin` refuses a provider that is not live | `google` | ERROR_VALIDATION | implemented |
| sso-ctl-13 | the mutating endpoints refuse while impersonating | impersonated session | RuntimeException from both | implemented |
| sso-ctl-14 | `identity_unlink` with nothing named is a refusal, not a delete of row zero | no id | ERROR_VALIDATION | implemented |
| sso-ctl-15 | `identities_list` answers for the SIGNED-IN identity and reads no argument | a foreign id in params | the signed-in identity's list | implemented |

## sso_http_surface.sh (http) - what only the dispatcher and the CSRF seam decide

Calling a controller method directly is what happens AFTER the gate has passed and AFTER
`Rsx_Csrf::enforce()` has allowed the request, so these five run over real HTTP. Steps 3 and
4 are a matched PAIR on purpose: the same cross-site POST, one path apart, with opposite
outcomes.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-http-01 | an unknown provider key is refused | GET `/_sso/nonesuch/begin` | 404 | implemented |
| sso-http-02 | a configured-but-DISABLED key is the same refusal, and never a 500 | GET `/_sso/google/begin` | 404 | implemented |
| sso-http-03 | THE APPLE EXEMPTION: a cross-site POST passes the origin check and 303s to the GET leg | POST + `Origin: appleid.apple.com` | 303, Location carries code, state and user | implemented |
| sso-http-04 | THE EXEMPTION IS PATH-EXACT | the identical POST to `/_sso/google/callback`, `/_sso/apple/callbackx`, `/_sso/apple/begin` | all rejected, "CSRF token mismatch", none 303 | implemented |
| sso-http-05 | the settings endpoints refuse a logged-out caller AT THE GATE | anonymous POSTs | `unauthorized`, and the endpoint body is never reached | implemented |

Cross-referenced from `tests/csrf/test_catalog.md` (the exempt-path section), because the
exemption lives in `Rsx_Csrf` and the proof lives here.

## Deferred - the begin redirect over real HTTP

| ID | Purpose | Reason |
|----|---------|--------|
| sso-http-def-01 | `/_sso/<key>/begin` 302s to a real provider over HTTP | a bash test cannot enable a provider in the running server's config, and committing an enabled provider to make a test pass would be a live misconfiguration. The redirect SHAPE is pinned in `sso-ctl-01` and `sso-cer-01` through the custom-provider seam instead; what only HTTP can decide (the 404 refusals, the CSRF exemption, the gates) is pinned above. |

## Sso_Cli_Test (cli, default isolation) - the operator path

`rsx:users:sso:dump` and `rsx:users:sso:unlink`, run in-process through `Artisan::call()`.
What is pinned is the OPERATOR'S TRUST IN THE OUTPUT: both commands make a claim nothing
contradicts until somebody tries to sign in, so a connection missing from a dump, or an id
reported disconnected that belonged to another identity, is discovered at a login screen and
not here. The dormant-provider row is the one that would otherwise be missed - switching a
provider off deletes nothing, and a terminal must say so rather than printing a bare key that
reads like a live connection.

Neither command prompts. That is the deliberate difference from `rsx:users:2fa:remove`:
removing a second factor destroys a seed nobody can recover, while a disconnection is
re-created by pressing "Continue with ..." once.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sso-cli-01 | dump round-trips a connection, by id and by email, in both output forms | one connection | envelope carries exactly the seven metadata fields; the configured label; human output opens with the house `User:` header | implemented |
| sso-cli-02 | `--id` disconnects exactly one, `--all` takes the rest, and an empty `--all` is still a success | two connections | `removed` / `removed_all` / `none`; the untargeted connection survives | implemented |
| sso-cli-03 | EXACTLY ONE of `--id` and `--all` - neither is defaulted to either meaning | neither, then both | `invalid_options` naming both flags; nothing removed | implemented |
| sso-cli-04 | a connection whose provider is no longer configured is named as such | a row keyed to a disabled provider | `provider_label` null in JSON, `(no longer configured)` for a human; still listed | implemented |
| sso-cli-05 | zero connections is a SUCCESSFUL answer - a state dump, not an assertion | unconnected identity | exit 0, `identities === []`, `[OK] No connected accounts` | implemented |
| sso-cli-06 | the human form reports what went and what is left | two connections, `--id` | `[OK] Disconnected 1 ...` plus `1 connected account remaining.` | implemented |
| sso-cli-07 | THE OWNERSHIP CHECK: another identity's id is refused, never a silent no-op | a stranger's connection id | `identity_not_found` naming `rsx:users:sso:dump`; both identities keep their rows | implemented |
| sso-cli-08 | an unresolvable `--user` is a non-zero exit in BOTH forms | unknown email, unknown id | `user_not_found` / `[ERROR]`, exit 1 from both commands | implemented |
| sso-cli-09 | `--user` is required and never defaulted to identity 1 | no `--user` | `user_required` naming the flag; identity 1 untouched | implemented |

## Planned - rendering

Verified by hand with `rsx:debug` when the template surfaces were built, and still planned as
suite rows. There is NO playwright harness precedent for an auth-flow concern - `two_factor`,
the nearest neighbour and the subsystem these screens sit beside, has php/cli/http and no
playwright directory - so writing the first one is a harness decision rather than a test, and
it is not made here.

All three ALSO need a provider switched on, which means writing an enabled provider into
committed config: a live misconfiguration on every install that pulls it, for the sake of a
rendering assertion. The verification actually performed used a TEMPORARY config edit,
reverted in the same session.

| ID | Purpose | Type | Status |
|----|---------|------|--------|
| sso-pw-01 | the login page renders the provider buttons and the `or` divider when a provider is enabled, and neither when none is | playwright | planned (verified by hand: 1 button, 1 divider with google enabled; 0 and 0 with none) |
| sso-pw-02 | the settings Connected Accounts section renders live, connected and no-longer-offered rows | playwright | planned (verified by hand at all three states; the endpoints it drives are pinned in `sso-ctl-11`..`sso-ctl-15`) |
| sso-pw-03 | the `sso` rsxapp key is PRESENT with a provider enabled, and ABSENT with none | playwright | planned (verified by hand: `("sso" in window.rsxapp)` false with none, true with google) |

`sso-pw-03` has no php seam: the key is assembled inside `Rsx_Bundle_Abstract::render()`
during a real web request, and a CLI test cannot make one.

## Deferred

| ID | Purpose | Reason |
|----|---------|--------|
| sso-def-01 | a real token exchange against Google/Apple/X | needs real developer-console applications and live credentials; an owner-operated follow-up, not a suite row |
| sso-def-02 | Apple's ES256 client-secret JWT minted from a real `.p8` | the minting was proved offline in the W1 spike (header `kid`, `iss` from the team id, ES256, no network); pinning it here would require shipping a private key in the repository |
| sso-def-03 | X's PKCE verifier round-trip through the in-memory session shim | proved in the W1 spike against the real `XProvider` (challenge === S256(verifier), a wrong state refused by Socialite itself); a suite row would have to re-implement the shim to observe it |
