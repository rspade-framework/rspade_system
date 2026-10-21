# Test catalog: two_factor

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-21.

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

## Recovery_Codes_Switch_Test (php, default isolation) - rsx.two_factor.recovery_codes

Off means nothing mints, on any path; it changes nothing about what an identity already holds.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-rcs-01 | the default issues codes, and both realms read the one key | default config, then false | true/true, then false/false | implemented |
| tfa-rcs-02 | a non-boolean value fails loud | 'false', 0, null | RuntimeException naming the key | implemented |
| tfa-rcs-03 | off: a TOTP enrollment confirms the factor and mints nothing | confirm_totp_enrollment() | null, factor live, zero code rows | implemented |
| tfa-rcs-04 | off: the totp_confirm endpoint answers recovery_codes null | the Ajax endpoint | {recovery_codes: null} | implemented |
| tfa-rcs-05 | off: a first passkey mints nothing | confirm_passkey_registration() | null, passkey live, zero code rows | implemented |
| tfa-rcs-06 | off: the operator setup mints nothing, in both output forms | rsx:users:2fa:setup, --json and human | recovery_codes null / a line saying none are issued | implemented |
| tfa-rcs-07 | off: regeneration throws and leaves an existing sheet intact | regenerate_recovery_codes() | RuntimeException, ten codes still held | implemented |
| tfa-rcs-08 | off: the one storage writer refuses in both realms | Recovery_Codes::store_for() | RuntimeException, zero rows | implemented |
| tfa-rcs-09 | off: a held code is still reported and still accepted | a code issued while on, answered while off | has_recovery_codes true, signed in, one code spent | implemented |
| tfa-rcs-10 | off: an identity with no codes is not offered the answer | enrolled while off | has_recovery_codes false | implemented |
| tfa-rcs-11 | credentials_list reports the switch beside the held count | on, then off | issues_recovery_codes true/false, count 10 both times | implemented |
| tfa-rcs-12 | recovery codes can be removed without touching a factor | remove_recovery_codes(), rsx:users:2fa:remove --recovery-codes | codes gone, factor kept, action removed_recovery_codes | implemented |
| tfa-rcs-13 | --recovery-codes with --id is refused | both options | exit 1, options_conflict, nothing removed | implemented |
| tfa-rcs-14 | turned back on: no backfill, the next enrollment mints | enroll off, switch on, regenerate, enroll another | 0 held, then a sheet on request, then ten at enrollment | implemented |

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
| tfa-chal-20 | THE PER-CHALLENGE CAP: the wrong answer that reaches challenge_max_failures destroys the challenge | max wrong codes, then a correct one; then a fresh challenge | below the cap 'That code is not valid.' and pending survives; at the cap 'Please sign in again.', pending null, the correct code then 'expired'; a fresh challenge signs in and the identity count is cleared | implemented |
| tfa-chal-21 | THE PER-IDENTITY CAP spans challenges, counts every answer kind, refuses a correct code, and clear_failures() lifts it | identity_max 4 / challenge_max 2: a TOTP-shaped, a recovery-shaped, an empty assertion and another wrong code over two challenges, then a live code | is_locked after 4; the live code refused with the account message, nobody signed in, pending discarded; after clear_failures the live code signs in | implemented |
| tfa-chal-22 | a cap or window below one fails loud | each key set to 0 | the accessor throws | implemented |

## Two_Factor_Issued_Code_Test (php, default isolation) - what a challenge accepts, and the issued code

`begin_challenge($identity, $accepts)` names the answer kinds a challenge takes and `verify_challenge()` enforces it;
`issue_code()` returns the identity's one live code - minting six digits when it has none - for the application to deliver (an emailed sign-in code - `rsx:man two_factor_codes`).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-ISS-01 | `$accepts` is validated before the sign-out | `[]`, `['sms']`, `[TOTP]` for an identity with none; then `[ISSUED_CODE, TOTP]` | three InvalidArgumentExceptions, nothing parked; then accepts `[issued_code]`, has_issued_code, codes_issued 0 | implemented |
| TFA-ISS-02 | an answer of a kind not accepted is refused, even a correct one | identity with TOTP, challenge `[ISSUED_CODE]`, a live TOTP code | "not valid", not signed in, has_totp false | implemented |
| TFA-ISS-03 | an issued code signs in, is stored encrypted, and is consumed | `[ISSUED_CODE]`, issue_code(), verify | six digits, codes_issued 1, pending_identity() is the identity, no digits in the session value, the store holds them only Crypt-encrypted, signed in, challenge spent, no live code left | implemented |
| TFA-ISS-04 | a resend returns the same code and counts the send | three issue_code() calls | reader null before, the same digits each time, codes_issued 3, issued_code_expires_at() unchanged, the code signs in | implemented |
| TFA-ISS-08 | a resend does not extend the absolute life | a planted code with 60 s left, a fresh challenge, issue_code() | the planted digits re-sent, the expiry unmoved | implemented |
| TFA-ISS-09 | an expired code is replaced by a new mint | a planted code with 1 s left, polled until the store expires it | a new code with a window of its own; the planted digits refused; the new code signs in | implemented |
| TFA-ISS-10 | a code issued in one session verifies in another session of the same identity | issue, act as a second browser, begin again | a different session, codes_issued 0, reader non-null, the same digits re-sent, signed in | implemented |
| TFA-ISS-11 | another identity's code never verifies | owner issues; another identity's challenge | the other holds no code; "not valid"; the owner's code untouched | implemented |
| TFA-ISS-12 | abandon_challenge() leaves the identity's code | issue, abandon | the live code is still the issued one | implemented |
| TFA-ISS-13 | the reader is null without an issued-code challenge | nothing pending; a TOTP-only challenge over a live code | null both times | implemented |
| TFA-ISS-14 | locking the identity destroys its code | identity_max_failures 2, two wrong codes | the code survives the first, the identity locks and the code is gone after the second | implemented |
| TFA-ISS-05 | issue_code() refuses without an accepting challenge | nothing pending; then a default challenge | Two_Factor_Failed_Exception "expired"; RuntimeException "does not accept an issued code" | implemented |
| TFA-ISS-06 | wrong issued codes spend the challenge's attempt cap | challenge_max_failures wrong codes | "not valid" until the last, then "sign in again", the challenge and its code are gone, the next challenge starts with no code | implemented |
| TFA-ISS-07 | the portal facade issues and verifies on its own realm | portal user, `[ISSUED_CODE]` | the staff realm sees no challenge; a resend re-sends the same digits; the code signs the portal user in | implemented |

## Two_Factor_Email_Link_Test (php, default isolation) - the email link, its landing route, and the verification effect

`issued_link($return_url)` answers the browser-bound link that goes in the same email as the issued code; the
landing route `/_email_link/:token` (`Rsx_Email_Link_Controller`) only reads; `{email_link}` answers the
challenge as `ANSWER_ISSUED_CODE`; a passed emailed answer sets `is_verified` (`rsx:man two_factor_codes`).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-LINK-01 | the link needs its code | nothing pending; a default challenge; an issued-code challenge with no code; a fragment / empty return URL | "expired"; "does not accept an issued code"; "Call issue_code() first"; InvalidArgumentException | implemented |
| TFA-LINK-02 | a resend answers the same link and nothing secret is stored | issue_code() + issued_link() twice; then a fresh challenge | an absolute signed URL, identical on resend; the store holds a binding (return URL, challenge id) without the token; a new challenge gets a new link for the same code | implemented |
| TFA-LINK-03 | the link is bound to its browser | state in the minting browser, then in another | SAME_BROWSER with the return URL; OTHER_BROWSER; there, {email_link} is a wrong answer and the code signs in | implemented |
| TFA-LINK-04 | the link signs in, consumes code and link, verifies the address, leaves the completed marker | an identity with is_verified 0 answering {email_link} | signed in, is_verified 1, no live code, no binding, state EXPIRED_OR_USED, `_challenge_completed_state()` and the challenge_state endpoint answer {completed, challenge_id, by_email_link: true} | implemented |
| TFA-LINK-05 | a typed code consumes the link and verifies the address too | {code} | is_verified 1, the link EXPIRED_OR_USED, by_email_link false | implemented |
| TFA-LINK-06 | a wrong or dead link is a counted wrong answer | 64 zeros; then the real token after the code was replaced | "not valid" twice, the identity's failure count +1 each, the challenge survives the first, nobody signed in | implemented |
| TFA-LINK-07 | the route redirects the owning browser and spends nothing | the signed URL opened twice | 302 to the return URL + #rsx_email_link=<token>, no-referrer; challenge pending, code live, nobody signed in, nothing counted | implemented |
| TFA-LINK-08 | the route shows another browser a notice and spends nothing | the URL from a second browser; a forged signature; the token in the portal realm | 409 with the different-browser text, nothing counted, code live; 410; EXPIRED_OR_USED | implemented |
| TFA-LINK-09 | the portal facade: a portal-host link that admits an unverified portal user | portal user with is_verified false, [ISSUED_CODE] | link under the portal prefix; SAME_BROWSER there, EXPIRED_OR_USED in the staff realm; {email_link} signs in, is_verified true, completed state | implemented |

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
| tfa-ctl-01 | the gate split: enrollment demands a login, the challenge is public | surface_gates() for all 10 endpoints | ['is_logged_in'] x7, ['public'] x3 (challenge_state, challenge_passkey_options, challenge_abandon) | implemented |
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
| tfa-ctl-14 | a pending challenge answers exactly what the screen renders, address MASKED | begin_challenge then challenge_state | exactly {challenge_id, email, email_masked, accepts, has_totp, has_passkey, has_recovery_codes, has_issued_code, codes_issued}, challenge_id a non-empty string; has_totp + has_recovery_codes true; email null; not the raw address | implemented |
| tfa-ctl-15 | passkey options with nothing pending is a user-safe refusal, not an exception | anonymous session | Error_Response, ERROR_VALIDATION, non-empty reason | implemented |
| tfa-ctl-16 | with a challenge pending the options endpoint answers a ceremony shape | begin_challenge then challenge_passkey_options | publicKey with a non-empty challenge | implemented |
| tfa-ctl-17 | challenge_shows_full_email puts the full address in the challenge state | config true; begin_challenge then challenge_state | email = the address; email_masked still masked | implemented |
| tfa-ctl-18 | has_recovery_codes is false once no unspent code is left | recovery rows deleted; challenge_state | has_totp true, has_recovery_codes false | implemented |
| tfa-ctl-19 | challenge_abandon forgets the pending challenge; a second call is a no-op | begin_challenge; challenge_abandon twice | null; nothing pending; not signed in | implemented |

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
| tfa-cli-10 | --user is REQUIRED and never defaulted to identity 1 | every command with no --user | exit 1, 'user_required' naming the flag; identity 1 untouched | implemented |
| tfa-cli-11 | unlock lifts a second-factor lock and leaves the factors alone | identity_max 1, one wrong code, then unlock --json, then a live code | was_locked true; is_locked false; is_enabled true; the live code signs in | implemented |

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

## The verification endpoint (php) - MOVED TO THE APPLICATION SUITE

`Rsx_Two_Factor::verify_challenge()` is deliberately not a framework endpoint: where a signed-in
user lands is application logic, so the app owns the endpoint and `<Two_Factor_Challenge>` is
pointed at it with `$controller` / `$method`. Its tests therefore live with the endpoint, in
`rsx/tests/Two_Factor_Login_Verify_Test.php` - the framework suite is an integrity audit of an
INSTALLED application and cannot assume any application endpoint exists. The rows are kept here
because the CONTRACT they pin is the framework component's: one argument shape in, {redirect}
out, and every failure a user-safe ERROR_VALIDATION.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-app-01 | a live code signs the pending identity in and answers the ONE key the component follows | pending challenge + unspent code | {redirect} non-empty, signed in as the pending identity, pending value spent | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-02 | one enabled membership sets the site and lands on the dashboard | single site user row | redirect equals Rsx::Route('Dashboard_Index_Action') | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-03 | no membership anywhere lands on the unauthorized screen, which owns the logout | no users row | redirect equals Rsx::Route('Site_Unauthorized_Controller') | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-04 | THE INVITE SURVIVES THE CHALLENGE - it rides the session because the component posts only {code} | parked invite code + live code | redirect is the accept-invite URL with the code; the parked value is consumed | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-05 | a wrong code is a user-safe ERROR_VALIDATION, nobody is signed in, and the challenge stays retryable | '000000' | Error_Response, ERROR_VALIDATION, non-empty reason, not logged in, pending intact | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-06 | blank is a value: an empty answer is refused the same way | [] | ERROR_VALIDATION, not logged in | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |
| tfa-app-07 | an expired/absent window is a sentence on the screen, never a 500 | anonymous session | ERROR_VALIDATION, non-empty reason, not logged in | implemented (app suite - `rsx/tests/Two_Factor_Login_Verify_Test.php`) |

## two_factor_login_flow.sh (http) - the two-stage login end to end

The only tier that can see either half of what it pins: the CLI login branch returns before the
last_login stamp, and CLI has no client IP for the throttle to refuse. It drives the
application's real login form and real verification endpoint with curl. It WRITES - it enrolls a
factor on the dev default identity and removes it again - and it spends the per-IP budget for
127.0.0.1, which its exit trap resets unconditionally.

Because every surface below `verify_challenge()` belongs to the application, the script PROBES
`_ajax/Login_Controller/verify_2fa` first and SKIPS when no such endpoint is declared. That is
the whole reason it may stay in the framework tree: what it pins is a framework property, and
the only path to it is application code.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-http-04 | a correct password stops at the challenge instead of entering the app | POST /login with a live factor enrolled | 302 to /login/verify; the application's landing page still refuses the half-authenticated session | implemented |
| tfa-http-05 | the challenge screen renders and carries a CSRF token | GET /login/verify | 200 hosting `<Two_Factor_Challenge>`, window.rsxapp.csrf present | implemented |
| tfa-chal-18 | a live code completes the login, stamps last_login, and writes exactly ONE success row | POST verify_2fa with a live code | {redirect}, the landing page 200, last_login changed, success rows +1 | implemented |
| tfa-chal-19 | the ambient throttle refuses a locked-out client, as itself and never as a wrong code | wrong codes until the budget is spent | a refusal saying "too fast" before the 25th attempt; no wrong code ever succeeds | implemented |

## Passkey_Login_Test (php, default isolation) - passwordless sign-in, staff realm

A passkey as the FIRST and only credential: begin_passkey_login() + verify_passkey_login(),
against the simulated authenticator. A user-verified passkey is a complete sign-in (framework
ruling), so the success case also asserts that no second-factor challenge is owed.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-pwl-01 | the begin names no identity and requires user verification | begin_passkey_login() | no allowCredentials, userVerification 'required', rpId the hostname, nobody signed in | implemented |
| tfa-pwl-02 | the passwordless challenge parks under its own key | a second-factor ceremony challenge already parked | both challenges present, the second-factor one untouched | implemented |
| tfa-pwl-03 | a verified passkey signs its owner in outright | registered key, UV assertion | the credential's owner signed in, no challenge pending, one success row, challenge spent | implemented |
| tfa-pwl-04 | presence without user verification is refused | assertion with UP but not UV | Two_Factor_Failed_Exception, not signed in | implemented |
| tfa-pwl-05 | an unknown credential answers with the one sentence | a key never registered | "could not sign you in", not signed in | implemented |
| tfa-pwl-06 | the challenge is single use | the same assertion twice | first signs in, second refused | implemented |
| tfa-pwl-07 | a disabled membership is refused and counted exactly once | valid key, users.is_enabled = 0 | refused, per-email failures +1, no success row | implemented |
| tfa-pwl-08 | a passwordless sign-in supersedes a pending password-stage challenge | begin_challenge() then a passkey sign-in | signed in, nothing left pending | implemented |

## Portal_Two_Factor_Test (php, default isolation) - the portal realm boundary

Rsx_Portal_Two_Factor, run as a portal request declaring its site. The engine is the staff
one; what is pinned here is the REALM: separate tables, prefixed user handles, the declared
site, the portal's admission rule and "View as Client".

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-portal-01 | a portal enrollment writes only the portal table, under a prefixed handle | portal passkey registration | user.id = 'portal-<id>', row in _portal_two_factor_credentials, none in the staff table | implemented |
| tfa-portal-02 | "View as Client" cannot enroll | impersonator set | RuntimeException from passkey and TOTP enrollment | implemented |
| tfa-portal-03 | the facade refuses an identity of the other realm | Login_User_Model to Rsx_Portal_Two_Factor | RuntimeException naming Portal_User_Model | implemented |
| tfa-portal-04 | a portal passkey signs its portal user in, passwordless | portal key, UV assertion | Portal_User_Model returned, portal signed in, staff not | implemented |
| tfa-portal-05 | a staff passkey cannot sign in on the portal | staff key on a portal ceremony | refused, nobody signed in | implemented |
| tfa-portal-06 | a portal passkey cannot sign in as staff | portal key on a staff ceremony | refused, nobody signed in | implemented |
| tfa-portal-07 | a passkey of another site's portal user is refused | key enrolled on site B, ceremony on site A | refused | implemented |
| tfa-portal-08 | the portal's admission rule refuses a suspended portal user | valid key, status suspended | refused | implemented |
| tfa-portal-09 | the portal second-factor challenge answers with a portal passkey | begin_challenge() + challenge assertion | signed in to the portal, nothing pending in either realm | implemented |
| tfa-portal-10 | the attempt caps hold on the portal's OWN counters | caps 2/2, two wrong codes on a portal challenge | challenge destroyed, the portal user locked, the staff identity with the same id not locked | implemented |
| tfa-portal-11 | the challenge records into the PORTAL history | a wrong code, then a passkey answer | the wrong code is a portal failure count and no row; the pass is one SUCCESS row with the site; no staff row | implemented |
| tfa-portal-12 | passwordless sign-in records into the portal history | a sign-in, then the same key for a suspended portal user | SUCCESS is the newest row; the refusal is no row and one portal failure count | implemented |

## Passkey_Ceremony_Test (php, default isolation) - the ceremony timeout, the challenge window, enrollment outcomes

Both realms. The timeout is a browser UI hint the framework must set (the library's own default
is 20 s, too short for the cross-device QR flow); the challenge window is derived from it; every
enrollment ceremony gets exactly one recorded outcome after its begun row.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tfa-cer-01 | every staff ceremony carries the 300 s hint | registration, second-factor assertion, passwordless options | publicKey.timeout = 300000 on all three | implemented |
| tfa-cer-02 | every portal ceremony carries the 300 s hint | the same three through Rsx_Portal_Two_Factor / Passkeys | publicKey.timeout = 300000 on all three | implemented |
| tfa-cer-03 | the challenge window is derived and never shorter than the prompt | challenge_window_seconds() | timeout + margin, > timeout, margin > 0, <= 600 | implemented |
| tfa-cer-04 | the stored challenge and the enrollment marker expire on that window | begin_passkey_registration() | both _session_values rows expire ~360 s out | implemented |
| tfa-cer-05 | a completed enrollment writes begun then enrolled | begin + valid attestation | [passkey_enroll_begun, passkey_enrolled], marker spent | implemented |
| tfa-cer-06 | a refused confirmation writes failed and still throws | begin + incomplete attestation | Two_Factor_Failed_Exception; passkey_enroll_failed row with the reason; marker spent | implemented |
| tfa-cer-07 | a new begin supersedes the pending enrollment | begin twice | [begun, abandoned ("Superseded"), begun] | implemented |
| tfa-cer-08 | a new begin records an EXPIRED pending enrollment | begin, expire the marker, begin | abandoned row naming the expired challenge and its begun time | implemented |
| tfa-cer-09 | a late confirmation is abandoned, not failed | begin, expire, confirm a valid attestation | 'expired' exception; [begun, abandoned ("after the challenge expired")] | implemented |
| tfa-cer-10 | the session-values sweep records an expired enrollment once | begin, expire, Session_Values_Cleanup_Service::cleanup_expired_values() | abandoned row with the begin's IP, marker deleted, a second pass records 0 | implemented |
| tfa-cer-11 | the sweep leaves a live enrollment alone | begin, record_expired_passkey_enrollments() | the marker remains | implemented |
| tfa-cer-12 | the portal writes its outcomes as rows of its own history | portal begin twice | _portal_login_history [begun, abandoned, begun] with the site, readable through Portal_Login_History; _login_history count unchanged | implemented |

## Deferred / planned

Nothing is currently deferred. tfa-chal-18 and tfa-chal-19 were deferred while
`verify_challenge()` had no HTTP path at all - the application owns the verification endpoint and
none existed. The template app now ships one, and both rows are implemented above.

## playwright/endpoint_resolution.js (playwright, the /_sys panel) - $controller resolves through the manifest

Both components take the application's controller as a string; a bundle's classes are lexical
globals, never window properties, so the lookup is `Manifest.get_class_by_name()`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-RES-01 | `<Two_Factor_Challenge>` reaches its endpoint | a probe method on the `Spa_Session_Controller` stub that throws "probe reached" | the component reports "probe reached" | implemented |
| TFA-RES-02 | `<Passkey_Sign_In>` gets past resolution | the same probe | `_sign_in()` does not throw "could not resolve" (the headless ceremony then fails on screen) | implemented |
| TFA-RES-03 | an unknown controller is still reported by name | `No_Such_Controller::x` | "could not resolve the endpoint No_Such_Controller::x" | implemented |

## Failure reasons and the exhausted-challenge event (Two_Factor_Challenge_Test)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-RSN-01 | each failure carries its reason | nothing pending; wrong answers up to the cap; one more | WINDOW_EXPIRED; WRONG_ANSWER each time; CHALLENGE_SPENT on the last; WINDOW_EXPIRED after | implemented |
| TFA-RSN-02 | a locked identity carries the locked reason | identity cap reached, a correct code on a new challenge | IDENTITY_LOCKED | implemented |
| TFA-EVT-01 | an exhausted challenge fires the realm's event once, with the identity | wrong answers to the cap, a recording handler on both realms' events | nothing below the cap; one `two_factor.challenge.spent` with the identity, email and failure count; never the portal's | implemented |

## code_entry.js (playwright) - the typed answer is `<Code_Input>`

Both screens against a scripted controller on `/_sys`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-CODE-01 | a six-digit challenge shows the boxes alone | `has_totp` only | six boxes, no recovery box, no switch link, cursor in box 1 | implemented |
| TFA-CODE-02 | a blank answer is not posted | Verify with empty boxes; Enter with two digits | no call to the endpoint | implemented |
| TFA-CODE-03 | Enter in a complete row verifies | six digits, Enter | `{code}` posted once | implemented |
| TFA-CODE-04 | a refusal resets the answer | the endpoint rejects | message shown, boxes marked and emptied, cursor in box 1 | implemented |
| TFA-CODE-05 | recovery codes get a plain box on request | `has_totp` + `has_recovery_codes`, the switch link, a typed code | boxes first; the link swaps in the plain box (focused) and back; the typed code is posted trimmed | implemented |
| TFA-CODE-06 | a recovery-only challenge | `has_recovery_codes` only | the plain box alone | implemented |
| TFA-CODE-07 | enrollment uses the boxes | `<Totp_Enrollment>` | six boxes, cursor in them; a blank Confirm posts nothing | implemented |
| TFA-CODE-08 | a refused enrollment code | `totp_confirm` rejects | message shown, boxes emptied, cursor back | implemented |

A blank answer spends nothing server-side either: `Two_Factor_Challenge_Test::test_a_blank_answer_is_refused_and_spends_nothing`
(no input, `''` and whitespace, repeated past the challenge's failure cap - no failure row, the
challenge still pending, the identity not locked).
| 2FA-TRUST-01 | trust_this_device() refuses unless the identity passed a second factor in this request, and for another identity than the one that passed | php | no verification; a verification for user A, trust for B | RuntimeException "only valid in the request" | implemented (`Two_Factor_Trusted_Device_Test`) | 2026-10-19 |
| 2FA-TRUST-02 | a realm whose window is 0 refuses to trust and never answers trusted | php | staff window 0 | trust throws "trusted browsers are off"; device_is_trusted false | implemented | 2026-10-19 |
| 2FA-TRUST-03 | a trusted browser answers for that identity on that browser (its _sessions row), filtered by what earned it, until trusted_until; the cleanup removes expired rows | php | emailed-code verification, trust, another browser, expiry | true / issued_code true / totp false / other browser false / expired false / cleanup 1 | implemented | 2026-10-21 |
| 2FA-TRUST-04 | the realms are separate: staff trust is not portal trust for the same id; turning a realm to 0 stops it answering | php | staff trust, portal check, staff window 0 | false, false | implemented | 2026-10-19 |
| 2FA-TRUST-05 | the portal realm trusts on its own; a portal verification mints no staff trust; a changed portal password ends trust | php | portal emailed-code verification | staff trust throws; portal trusted; after set_password false | implemented | 2026-10-19 |
| 2FA-TRUST-06 | a changed staff password and remove_all() each end trust | php | trust, password change; trust, remove_all | false each time | implemented | 2026-10-19 |
| 2FA-TRUST-07 | forget_trusted_devices(except_this_device) keeps this browser and forgets the others | php | two trusted devices | 1 removed; this kept; the other forgotten | implemented | 2026-10-19 |

## control_classes.js (playwright) - the components' own control classes and `$unstyled_controls`

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| 2FA-CONTROLS-01 | a control carries its own class beside the theme's | `Totp_Enrollment` mounted on `/_sys` | Confirm has `btn btn-primary` and `Totp_Enrollment__confirm` | implemented |
| 2FA-CONTROLS-02 | `$unstyled_controls` drops the theme classes | the same with `$unstyled_controls` | Confirm has only `Totp_Enrollment__confirm` | implemented |
| 2FA-CONTROLS-03 | the digit boxes are `Code_Input`'s either way | both mounts | six `Code_Input__box` | implemented |

## email_link.js (playwright, the /_sys panel) - `<Two_Factor_Challenge>` and the email link

Driven against a scripted controller; the engine and the route's answers are `Two_Factor_Email_Link_Test`.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| TFA-LINK-B-01 | the fragment is taken and posted once, with no code sent | a page at #rsx_email_link=<token>, codes_issued 0, an endpoint that refuses | the hash no longer carries the token; one post of exactly {email_link}; no send; the code boxes with the server's message | implemented |
| TFA-LINK-B-02 | two tabs, one browser: the other tab swaps | the link tab's answer passes | the link tab follows the redirect; the code tab shows "You have signed in using the email link." + Continue and no boxes; Continue goes to that redirect | implemented |
| TFA-LINK-B-03 | a tab showing a different challenge ignores the announcement | another challenge_id | unchanged | implemented |
| TFA-LINK-B-04 | the visibility backstop | challenge_state answers {completed}; then a CSRF (unauthorized) refusal | "You have signed in using the email link."; "This sign-in continued in another tab." | implemented |
| TFA-LINK-B-05 | the realtime session-refresh claim | a frame while waiting; a broadcast after it; stop; a frame on a screen with nothing pending | one claim held; swap to "continued in another tab" with no reload; upgraded to the exact message; claim released; the idle screen hands the frame back (proceed) | implemented |
| TFA-LINK-B-06 | the landing route's notice page on both hosts, from another browser context | a bad signature at /_email_link/ and /_portal/_email_link/ | 410 with "This link has expired or was already used. Sign in again." | implemented |
