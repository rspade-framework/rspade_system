# password_confirmation - test catalog

## Password_Confirmation_Test (php) - the confirmation and the endpoints it guards

Each test acts as a browser (`$acts_as_browser`): the confirmation is a session value.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| pc-01 | nothing is confirmed until the password is given; a wrong one confirms nothing | php | signed-in staff, wrong then right password | false/throws until the right password, then confirmed | implemented | 2026-10-29 |
| pc-02 | options() describes what can confirm | php | staff with a password, no passkey | has_password, !has_passkey, !confirmed, minutes 5 | implemented | 2026-10-29 |
| pc-03 | the confirmation belongs to its identity | php | confirm, then another identity signs in on the browser | not confirmed | implemented | 2026-10-29 |
| pc-04 | clear() ends it | php | confirm, clear | not confirmed | implemented | 2026-10-29 |
| pc-05 | it ends when its expiry passes | php | expires_at moved into the past | not confirmed | implemented | 2026-10-29 |
| pc-06 | impersonation can neither give nor use one | php | impersonating | confirm_password and require_confirmed throw "impersonating" | implemented | 2026-10-29 |
| pc-07 | a gated endpoint refuses until confirmed, then runs | php | credential_remove via Ajax::internal | "timed out" before; its own "No credential was named" after | implemented | 2026-10-29 |
| pc-08 | the identity's own passkey confirms; another key does not | php | WebAuthn fixture keys | stranger false, own true | implemented | 2026-10-29 |
| pc-09 | the portal confirms its own user, separately from staff | php | staff confirmed, then a portal request | not confirmed in the portal until its user confirms | implemented | 2026-10-29 |
| pc-10 | the confirmation over HTTP | http | signed-in totp_begin, verify, totp_begin | refused, confirmed, payload | implemented (`two_factor/http/two_factor_endpoint_gates.sh`) | 2026-10-29 |
| pc-11 | a throttled account cannot confirm | php | - | Auth_Throttled_Exception | deferred (needs a client address; CLI has none) | 2026-10-29 |
