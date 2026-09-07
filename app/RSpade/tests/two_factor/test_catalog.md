# Test catalog: two_factor

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-09-02.

## Totp_Test (php, no transactions - pure logic) - RFC 6238 correctness and the rules verify() adds

Totp is hand-written, so the RFC's own vectors are what makes that defensible. The vectors publish
8-digit codes; RSpade issues 6, which is the same truncation reduced modulo 10^6, so the expected
value is the last six digits. The drift and replay tests derive their timesteps from the current
clock because verify() reads time() itself and has no injection seam - a clock seam on a TOTP
verifier would be an attack surface, so the tests bend instead.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-totp-01 | code_for() matches every published SHA1 vector | RFC 6238 Appendix B, T=59..20000000000 | the last 6 digits of each published code | implemented |
| tfa-totp-02 | a code is always a zero-padded 6-character string | 200 consecutive timesteps | string, length 6, all digits | implemented |
| tfa-totp-03 | a seed is 160 bits of CSPRNG output in the base32 alphabet | generate_secret() | 32 chars, [A-Z2-7], decodes to 20 bytes, never repeats | implemented |
| tfa-totp-04 | base32 round-trips binary and tolerates human formatting | 0..37 byte inputs; lower case, spaced, padded | identical decode | implemented |
| tfa-totp-05 | a corrupt seed throws rather than decoding to plausible bytes | 'ABCD1EFG' | RuntimeException naming base32 | implemented |
| tfa-totp-06 | the Key URI Format: issuer twice, raw-url-encoded, fixed parameters | secret + 'alice+tag@example.com' + 'Acme Inc' | otpauth://totp/Acme%20Inc:alice%2Btag%40example.com?..SHA1..6..30 | implemented |
| tfa-totp-07 | the current timestep is accepted and RETURNED for persistence | code for floor(time()/30) | that timestep, not a bare true | implemented |
| tfa-totp-08 | +-1 step of clock skew is accepted | codes at now-1 and now+1 | each accepted, reporting its own step | implemented |
| tfa-totp-09 | +-2 steps is refused - a skew tolerance, not a grace period | codes at now-2 and now+2 | false, false | implemented |
| tfa-totp-10 | THE REPLAY RULE: a spent timestep is refused | the same code twice, floor carried forward | accepted then false | implemented |
| tfa-totp-11 | the replay floor covers the whole drift window beneath it | code at now-1 with floor = now | false; now+1 still reachable | implemented |
| tfa-totp-12 | malformed codes are refused before the HMAC runs | empty, 5, 7 digits, alphabetic, spaced, signed, valid+digit | false x7; surrounding whitespace still accepted | implemented |
| tfa-totp-13 | a code from another seed never verifies | other seed's live code | false | implemented |

## Recovery_Codes_Test (php, default isolation) - minting, hashed storage, consume-once

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-rec-01 | ten distinct codes from the unambiguous alphabet (no 0/O/1/I) | generate() | 10 unique XXXX-XXXX matching [A-HJ-NP-Z2-9] | implemented |
| tfa-rec-02 | two sets never collide | two generate() calls | empty intersection | implemented |
| tfa-rec-03 | rows are born CONFIRMED and store a bcrypt hash, never the plaintext | store_for() | 10 rows, confirmed_at set, secret starts $2y$, plaintext absent | implemented |
| tfa-rec-04 | store_for() REPLACES - a superseded sheet stops working | two sets stored in sequence | 10 rows not 20; old code refused, new accepted | implemented |
| tfa-rec-05 | a code is consumed exactly once, by deletion | the same code twice | true then false; remaining drops by exactly 1 | implemented |
| tfa-rec-06 | every code in the set redeems and the set empties exactly | all 10 in turn | 10 successes, remaining 0, nothing left | implemented |
| tfa-rec-07 | formatting differences never refuse a valid code | lower case, no hyphen, spaced and padded | all accepted | implemented |
| tfa-rec-08 | a code is scoped to its own identity | another identity's code | refused here, and their row is not spent | implemented |
| tfa-rec-09 | garbage is refused and spends nothing | '', '   ', '-', 'ZZZZ-ZZZZ', prose | false x5, remaining unchanged | implemented |
| tfa-rec-10 | an identity with no set has none remaining | fresh identity | 0, and consume() is false not an error | implemented |

## Two_Factor_Enrollment_Test (php, default isolation) - enrollment through the facade

Identity is declared through `Session::set_login_user_id()`, which in CLI sets a static and creates
nothing; the session ROW arrives on demand at the first `Session::put_value()`. Every test calls
`static::__reset_session()` first, so the row minted inside one test's transaction is not left
cached after that transaction rolls back. Impersonation uses the raw CLI seam
`Session::cli_set_impersonator_login_user_id()`, cleared in teardown.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-enr-01 | begin parks a seed on the session and writes NO row | begin_totp_enrollment() | {secret, otpauth_uri, qr_svg}; 0 rows; is_enabled false; seed in the session value | implemented |
| tfa-enr-02 | the QR code is inline-embeddable SVG | the returned qr_svg | starts '<svg', no '<?xml', contains '</svg>' | implemented |
| tfa-enr-03 | a live code confirms; the seed round-trips through Crypt; codes are minted | begin then confirm with a live code | 1 confirmed row, seed encrypted and decryptable, counter = consumed timestep, 10 codes, pending forgotten | implemented |
| tfa-enr-04 | a WRONG code leaves NO confirmed row | a well-formed code 5000 steps out | Two_Factor_Failed_Exception; 0 rows of any kind; no codes | implemented |
| tfa-enr-05 | a failed confirmation KEEPS the parked seed so a typo is retryable | wrong code then right code | seed still parked; the retry enrolls | implemented |
| tfa-enr-06 | confirming with nothing parked is refused | confirm with no begin | Two_Factor_Failed_Exception naming expiry | implemented |
| tfa-enr-07 | enrollment REFUSES while impersonating - no authentication backdoor | is_impersonating true | RuntimeException from all three entry points; nothing written | implemented |
| tfa-enr-08 | enrollment refuses with nobody signed in | anonymous session | RuntimeException | implemented |
| tfa-enr-09 | list_credentials() is metadata only and leaks no secret | an enrolled identity | exactly 6 metadata keys; the seed appears nowhere in the payload | implemented |
| tfa-enr-10 | regenerate replaces the set; the old sheet dies immediately | regenerate_recovery_codes() | 10 new codes, empty intersection, old refused, new accepted | implemented |
| tfa-enr-11 | removing the LAST factor cascades to the recovery codes | remove_credential() on the only factor | is_enabled false, remaining 0, 0 rows of any kind | implemented |
| tfa-enr-12 | remove_credential is scoped to its own identity | another identity's credential id | the victim's factor survives | implemented |
| tfa-enr-13 | remove_all clears every credential | remove_all() | is_enabled false, remaining 0, list empty | implemented |
| tfa-enr-14 | recovery codes ALONE are not a second factor | codes stored, no factor | is_enabled false, list empty, remaining 10 | implemented |

## Two_Factor_Challenge_Test (php, default isolation) - the half-authenticated state and its redemption

Two stores, so two isolation strategies (the same shape as tests/session): success rows are database
writes rolled back with the per-test transaction, while the throttle and failure counters are redis
keys outside both the transaction and the process. Every test uses a unique email; teardown clears
the shared per-IP throttle through `Login_Throttle::reset('CLI')` and deletes the per-email counters
it created.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-chal-01 | THE CENTRAL PROPERTY: begin_challenge parks the identity and LOGS OUT | begin_challenge() | is_logged_in false, has_session true, pending readable with has_totp | implemented |
| tfa-chal-02 | the pending address is masked | challenge_pending() | not the address, contains '*', domain intact, first character kept | implemented |
| tfa-chal-03 | no pending challenge reads as null and cannot be verified | anonymous session | null; verify throws naming expiry | implemented |
| tfa-chal-04 | abandon discards the pending state | abandon_challenge() | pending null, nobody signed in | implemented |
| tfa-chal-05 | an EXPIRED window cannot be redeemed, and expiry is a working outcome | expires_at aged into the past | pending null; a CORRECT code still throws; nobody signed in | implemented |
| tfa-chal-06 | the minted window is the configured one | challenge_expires_at() | within 2s of challenge_window_minutes | implemented |
| tfa-chal-07 | a wrong code is refused, counted ONCE, and the challenge survives for a retry | '000000' | exception, email counter +1, not signed in, pending intact, 0 success rows | implemented |
| tfa-chal-08 | another identity's TOTP code does not answer this challenge | their live code | exception, not signed in | implemented |
| tfa-chal-09 | an empty answer is refused | [] | exception, not signed in | implemented |
| tfa-chal-10 | the throttle budget locks an address out and THROWS | budget failures against an explicit IP | Auth_Throttled_Exception, retry_after_seconds > 0 | implemented |
| tfa-chal-11 | each failed challenge reaches record_failure exactly ONCE (no double count) | 3 wrong codes | the email counter increments by exactly 1 each time | implemented |
| tfa-chal-12 | a correct code signs in, clears the pending value, writes exactly ONE success row | a live unspent code | identity returned and signed in, pending gone, 1 success row | implemented |
| tfa-chal-13 | a used code cannot be replayed - the timestep is persisted | the same code at a second challenge | counter advanced, last_used_at stamped, second attempt throws | implemented |
| tfa-chal-14 | a passkey assertion answers the challenge | a real assertion from the fixture | signed in, pending gone, 1 success row, has_passkey true / has_totp false | implemented |
| tfa-chal-15 | ANOTHER identity's passkey does not answer this challenge (the ownership check) | attacker's genuine key against the victim's challenge | exception, not signed in, no success row | implemented |
| tfa-chal-16 | a recovery code answers the challenge and is spent | codes[0], then again | signed in, remaining 9, 1 success row; the second attempt throws | implemented |
| tfa-chal-17 | a live TOTP code never burns a recovery code (TOTP is tried first) | a live code with codes present | remaining still 10 | implemented |
| tfa-chal-18 | verify_challenge stamps last_login through RsxAuth::login() | a live code over real HTTP | last_login bumped | implemented (http - `two_factor_login_flow.sh`; unreachable from php, where `Session::set_login_user_id()` returns from its CLI branch before the stamp) |
| tfa-chal-19 | verify_challenge's OWN throttle call refuses a locked-out client | wrong codes over real HTTP until the budget is spent | Auth_Throttled_Exception surfaced to the screen as itself | implemented (http - `two_factor_login_flow.sh`; unreachable from php, where `Session::get_client_ip()` is null by design) |

## Passkeys_Test (php, default isolation) - WebAuthn against a simulated authenticator

`php/Webauthn_Authenticator_Fixture.php` is a software FIDO2 authenticator: a real P-256 keypair, a
real CBOR attestation object and real ECDSA assertions, which `lbuchs/webauthn` verifies exactly as
it verifies a hardware key. It is marked `#[Instantiatable]` because each instance genuinely is a
distinct device holding its own keypair. The library ships no recorded fixture data, so without this
the whole WebAuthn path would have to be taken on faith.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-pk-01 | the rpId is the BARE hostname - no scheme, no port, lower case | relying_party_id() | no ':' or '/', lower cased, derived from Rsx::get_hostname() | implemented |
| tfa-pk-02 | creation args have the required shape and the challenge is stored SERVER-SIDE | begin_passkey_registration() | rp.id, rp.name, base64url user handle, challenge, residentKey 'required'; session value equals the issued challenge | implemented |
| tfa-pk-03 | an already-registered credential is excluded from a second registration | a second begin | excludeCredentials contains the enrolled key | implemented |
| tfa-pk-04 | a real attestation enrolls a confirmed passkey and mints recovery codes | fixture attestation | confirmed row, credential_key matches, PEM public key, label kept, 10 codes, challenge spent | implemented |
| tfa-pk-05 | a SECOND passkey does not reissue recovery codes | a second registration | returns null, remaining unchanged, both keys listed | implemented |
| tfa-pk-06 | a registration with no ceremony in flight is refused | attestation, no begin | Two_Factor_Failed_Exception naming expiry | implemented |
| tfa-pk-07 | an incomplete response is refused before any crypto runs | {clientDataJSON} only | Two_Factor_Failed_Exception naming incompleteness | implemented |
| tfa-pk-08 | assertion args offer exactly this identity's confirmed keys | assertion_options() | rpId correct, one allowCredentials entry, the enrolled key | implemented |
| tfa-pk-09 | THE FULL ROUND TRIP: a real signature verifies | register then assert, counter 7 | the credential is returned, counter 7, last_used_at stamped | implemented |
| tfa-pk-10 | THE ANTI-CLONING CHECK: a counter that does not advance is refused | the same counter twice | WebAuthnException; the stored counter does not regress | implemented |
| tfa-pk-11 | a challenge is single use, spent even by a FAILED ceremony | a stranger's key, then the genuine one on the same challenge | both refused; the session value is cleared by the failure | implemented |
| tfa-pk-12 | an unknown credential is refused WITHOUT disclosing that it is unknown | a stranger's key | Two_Factor_Failed_Exception; message says neither 'unknown' nor 'not found' | implemented |
| tfa-pk-13 | an UNCONFIRMED passkey cannot assert | confirmed_at nulled | Two_Factor_Failed_Exception | implemented |
| tfa-pk-14 | base64url round-trips binary and is URL safe | '', "\0", high bytes, 64 random bytes | no +/= in the output, exact round trip | implemented |
| tfa-pk-15 | base64url_decode refuses garbage rather than decoding plausible bytes | '!!!! not base64 !!!!' | Two_Factor_Failed_Exception naming malformedness | implemented |

## Two_Factor_Controller_Test (php, default isolation) - the Ajax surface

`Rsx_Two_Factor_Controller` is the browser's whole view of the subsystem. The facade is already
pinned by the two tests above, so this class covers only what the CONTROLLER decides: which gate each
endpoint declares, the wire shape of each payload, which failures become a user-safe
`Error_Response` and which are left to surface, and the impersonation refusal the controller adds on
top of the facade's own.

Endpoints are called as STATIC METHODS, which is what the dispatcher does once the gate has passed -
so `#[Auth]` is NOT evaluated by these calls and the declarations are asserted separately through
`Auth_Gates::surface_gates()`, which reads the same manifest index the dispatcher enforces from.
Enforcement itself is the http row below; the two halves are untestable together in-process.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-ctl-01 | the gate split: enrollment demands a login, the challenge is public | surface_gates() for all 9 endpoints | ['is_logged_in'] x7, ['public'] x2 | implemented |
| tfa-ctl-02 | begin then confirm over the endpoints, as the component drives them | totp_begin then a live code | {secret, otpauth_uri, qr_svg}; nothing enrolled by begin alone; 10 codes; is_enabled true | implemented |
| tfa-ctl-03 | a wrong code is a VALIDATION error with a user-safe message, not an exception | '000000' | Error_Response, ERROR_VALIDATION, non-empty reason | implemented |
| tfa-ctl-04 | blank is a value: an empty code is refused before the facade is asked | absent, '', '   ' | ERROR_VALIDATION x3; 0 rows written | implemented |
| tfa-ctl-05 | an enrollment endpoint reached with nobody signed in fails LOUDLY | anonymous session | RuntimeException from all three entry points (never a friendly response) | implemented |
| tfa-ctl-06 | credentials_list answers the whole settings screen in one call, metadata only | an enrolled identity | exactly {credentials, recovery_codes_remaining, is_enabled}; 6 metadata keys; the seed appears nowhere | implemented |
| tfa-ctl-07 | removal returns the REFRESHED state and the codes cascade | credential_remove on the only factor | is_enabled false, 0 credentials, 0 codes remaining | implemented |
| tfa-ctl-08 | a removal naming no credential is refused, and the factor survives | absent, 0, 'x' | ERROR_VALIDATION x3; is_enabled still true | implemented |
| tfa-ctl-09 | removal REFUSES while impersonating - no authentication backdoor | is_impersonating true | RuntimeException; the victim keeps their factor | implemented |
| tfa-ctl-10 | regenerate replaces the sheet and returns the new plaintext set | recovery_regenerate | 10 codes, empty intersection with the previous set | implemented |
| tfa-ctl-11 | a malformed attestation is refused before any crypto runs | absent, null, a string | ERROR_VALIDATION x3 | implemented |
| tfa-ctl-12 | the creation args reach the browser in navigator.credentials.create() shape | passkey_register_begin | publicKey with a challenge, a user handle and pubKeyCredParams | implemented |
| tfa-ctl-13 | nothing pending reads as NULL, not an error | anonymous session | null | implemented |
| tfa-ctl-14 | a pending challenge answers exactly what the screen renders, address MASKED | begin_challenge then challenge_state | exactly {email_masked, has_totp, has_passkey}; has_totp true; not the raw address | implemented |
| tfa-ctl-15 | passkey options with nothing pending is a user-safe refusal, not an exception | anonymous session | Error_Response, ERROR_VALIDATION, non-empty reason | implemented |
| tfa-ctl-16 | with a challenge pending the options endpoint answers a ceremony shape | begin_challenge then challenge_passkey_options | publicKey with a non-empty challenge | implemented |

## Two_Factor_Cli_Test (cli, default isolation) - the operator commands

`rsx:users:2fa:setup|dump|remove` are the bootstrap and recovery path: the web flow can only
enroll the SIGNED-IN identity, which leaves the first account on a fresh box and a user who has
lost both their phone and their code sheet with no path at all. Run in-process through
`Artisan::call()` - they write ordinary rows on this connection, so the per-test transaction rolls
every credential back. tfa-cli-01 carries the PRINTED seed into a real `verify_challenge()` rather
than into `Totp::verify()`: the command skips the ceremony that normally proves a seed before it is
stored, so what needs pinning is the operator's claim to the user, not the verifier.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-cli-01 | setup writes a CONFIRMED factor and the printed secret answers a real challenge | setup --user, then begin/verify_challenge with a live code | 1 confirmed row labelled 'CLI setup', counter 0, 10 codes stored, the challenge signs the identity in | implemented |
| tfa-cli-02 | a second setup is REFUSED, not stacked, and names the way out | setup twice | exit 1, 'totp_already_enrolled' naming rsx:users:2fa:remove; still exactly 1 row | implemented |
| tfa-cli-03 | dump reads the seed back DECRYPTED with the URI rebuilt - the escape hatch | dump after setup, by id and by email | the same secret and otpauth_uri, confirmed_at set, last_used_at null, counter 0, 10 codes remaining | implemented |
| tfa-cli-04 | an unenrolled identity is a successful empty answer, never an error | dump on a fresh identity | exit 0, credentials [], remaining 0, is_enabled false, '[OK] No two-factor credentials' | implemented |
| tfa-cli-05 | remove (all) leaves the identity signing in with a password alone | remove --force | action removed_all, 10 codes removed, 0 rows of any kind survive | implemented |
| tfa-cli-06 | remove --id removes that credential and the recovery codes cascade with it | remove --id of the only factor | action removed, that id reported, 10 codes removed, is_enabled false | implemented |
| tfa-cli-07 | THE OWNERSHIP CHECK: another identity's id is refused, where the facade no-ops | remove --id of a victim's credential | exit 1, 'credential_not_found'; both identities keep their factor | implemented |
| tfa-cli-08 | --json cannot prompt, so it refuses without --force and destroys nothing | remove --json, no --force | exit 1, 'confirmation_required', the factor survives | implemented |
| tfa-cli-09 | an unresolvable --user fails loudly in BOTH output forms | an unknown email and an unknown id | exit 1 either way; 'user_not_found' in JSON, '[ERROR]' in the human form | implemented |
| tfa-cli-10 | --user is REQUIRED and never defaulted to identity 1 | all three commands with no --user | exit 1, 'user_required' naming the flag; identity 1 untouched | implemented |

## two_factor_endpoint_gates.sh (http) - the gate split, ENFORCED

The php tier can only assert what each endpoint DECLARES. This one proves the dispatcher acts on it,
which matters here more than usual: the two gate populations are opposites and getting either
backwards fails silently - enrollment would become anonymous, or the challenge screen would become
unreachable by the very logged-out session it exists to serve. Nothing is written: `totp_begin` parks
a seed in a session value and creates no row, and the script deliberately stops short of confirming.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-http-01 | challenge_state is genuinely PUBLIC over real HTTP | anonymous POST | 200 envelope, _success true, value null | implemented |
| tfa-http-02 | totp_begin is refused AT THE GATE, not by the facade | anonymous POST | _success false, error_code 'unauthorized'; the facade's own message never appears | implemented |
| tfa-http-03 | totp_begin ACCEPTS a signed-in caller - the other side of the split | login + csrf + POST | _success true, payload carries qr_svg | implemented |

## Login_Verify_Endpoint_Test (php, default isolation) - the APPLICATION's verification endpoint

`Rsx_Two_Factor::verify_challenge()` is deliberately not a framework endpoint: where a signed-in
user lands is application logic, so the app owns the endpoint and `<Two_Factor_Challenge>` is
pointed at it with `$controller` / `$method`. The template app's
`Rsx\App\Login\Login_Controller::verify_2fa` is therefore the only worked example of that
contract, and this class pins it. Endpoints are called as STATIC METHODS - what the dispatcher
does once the gate has passed - so the Ajax envelope is not applied and the assertions are on the
raw return.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-app-01 | a live code signs the pending identity in and answers the ONE key the component follows | pending challenge + unspent code | {redirect} non-empty, signed in as the pending identity, pending value spent | implemented |
| tfa-app-02 | one enabled membership sets the site and lands on the dashboard | single site user row | redirect equals Rsx::Route('Dashboard_Index_Action') | implemented |
| tfa-app-03 | no membership anywhere lands on the unauthorized screen, which owns the logout | no users row | redirect equals Rsx::Route('Site_Unauthorized_Controller') | implemented |
| tfa-app-04 | THE INVITE SURVIVES THE CHALLENGE - it rides the session because the component posts only {code} | parked invite code + live code | redirect is the accept-invite URL with the code; the parked value is consumed | implemented |
| tfa-app-05 | a wrong code is a user-safe ERROR_VALIDATION, nobody is signed in, and the challenge stays retryable | '000000' | Error_Response, ERROR_VALIDATION, non-empty reason, not logged in, pending intact | implemented |
| tfa-app-06 | blank is a value: an empty answer is refused the same way | [] | ERROR_VALIDATION, not logged in | implemented |
| tfa-app-07 | an expired/absent window is a sentence on the screen, never a 500 | anonymous session | ERROR_VALIDATION, non-empty reason, not logged in | implemented |

## two_factor_login_flow.sh (http) - the two-stage login end to end

The only tier that can see either half of what it pins: the CLI login branch returns before the
last_login stamp, and CLI has no client IP for the throttle to refuse. It drives the template
app's real login form and real verification endpoint with curl. It WRITES - it enrolls a factor
on the dev default identity and removes it again - and it spends the per-IP budget for
127.0.0.1, which its exit trap resets unconditionally.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-http-04 | a correct password stops at the challenge instead of entering the app | POST /login with a live factor enrolled | 302 to /login/verify; /dashboard still refuses the half-authenticated session | implemented |
| tfa-http-05 | the challenge screen renders and carries a CSRF token | GET /login/verify | 200 hosting `<Two_Factor_Challenge>`, window.rsxapp.csrf present | implemented |
| tfa-chal-18 | a live code completes the login, stamps last_login, and writes exactly ONE success row | POST verify_2fa with a live code | {redirect}, /dashboard 200, last_login changed, success rows +1 | implemented |
| tfa-chal-19 | the ambient throttle refuses a locked-out client, as itself and never as a wrong code | wrong codes until the budget is spent | a refusal saying "too fast" before the 25th attempt; no wrong code ever succeeds | implemented |

## Deferred / planned

Nothing is currently deferred. tfa-chal-18 and tfa-chal-19 were deferred while
`verify_challenge()` had no HTTP path at all - the application owns the verification endpoint and
none existed. The template app now ships one, and both rows are implemented above.
