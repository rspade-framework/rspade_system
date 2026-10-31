---
id: credential_throttle_coverage
title: Every credential check spends the login throttle
severity: high
when: launch, change
scope: rsx/app/login/**, rsx/portal/auth/**, rsx/**/*settings*/**, rsx/**/*password*, rsx/handlers/*Sso*
related: rsx:man session, rsx:man auth, rsx:man two_factor, rsx:man sso, skill rspade:session-auth, skill rspade:two-factor
---

## The contract

Every path in the application that checks a guessable credential is covered by the login throttle: it refuses a throttled caller BEFORE any lookup or comparison, counts a failure exactly once, and renders a throttled refusal as a message rather than an error page or a "wrong password". An unthrottled credential path is an unlimited guessing oracle sitting beside the protected one - a six-digit code is a million guesses, and an attacker holding the password only needs the open door.

Read `php artisan rsx:man session` (LOGIN THROTTLE) and `rsx:man auth` first.

Covered by the framework, not re-checked (only how the application catches the exception is): `RsxAuth::verify_credentials()` and `Portal_Auth::verify_credentials()` (throttle-first, record the outcome unless `record: false`); `Rsx_Two_Factor::verify_challenge()` / `Rsx_Portal_Two_Factor::verify_challenge()` (TOTP, issued codes, recovery codes, with per-challenge and per-identity caps); `verify_passkey_login()` in both realms; the SSO callback (`Rsx_Sso` / `Rsx_Portal_Sso`); the `/apidocs` login. Callers with no client IP (CLI, tasks) are never throttled, and the throttle is inert during maintenance mode - both by design.

## Find every instance

    grep -rnE "RsxAuth::(verify_credentials|login)\(|check_password\(|Hash::check\(|password_verify\(|hash_equals\(|Login_Throttle::|Login_History::record_failure|Portal_Auth::|verify_challenge\(|verify_passkey_login\(|Auth_Throttled_Exception" rsx --include='*.php' | grep -v '^rsx/tests/'
    grep -rnE "function find_by_(token|code)|->where\('(token|code|pin|otp)" rsx --include='*.php' | grep -v '^rsx/tests/'

Then read every endpoint that accepts a secret from the caller: password and current-password fields, second-factor or backup codes, invitation and reset tokens, PINs, share or access codes. Staff and portal alike, signed-in screens included.

## What does not conform

- A credential comparison (`check_password()`, `Hash::check()`, `password_verify()`, a code lookup) with no `Login_Throttle::require_not_throttled($account)` before it, and no failure fed after it (`Login_Throttle::record_failure()`, or `Login_History::record_failure()`, which already feeds it).
- `require_not_throttled()` placed AFTER the lookup, the comparison or an account-state check - a throttled caller must learn nothing.
- A failure counted twice: `Login_History::record_failure()` and `Login_Throttle::record_failure()` on the same event halves the limit.
- `RsxAuth::verify_credentials($c, record: false)` whose caller does not then call `Login_History::record_failure()` on a miss (portal: `Portal_Auth::verify_credentials($site_id, $c, record: false)` without `Portal_Login_History::record_failure()`) - with `record: false` the throttle refuses but never counts.
- `Auth_Throttled_Exception` not caught where a sign-in method can throw it (`verify_credentials()`, `verify_challenge()`, `verify_passkey_login()`) - an anonymous form answers 500. It is a separate exception from `Two_Factor_Failed_Exception` / `Sso_Failed_Exception` and needs its own catch. So does `Auth_Password_Reset_Required_Exception`, which either `verify_credentials()` throws for a correct password on an account flagged `password_reset_required`.
- A throttled attempt reported as "invalid email or password".
- A second throttle built on `Login_History::get_failed_attempts_count*()` - those are statistics.
- A short or low-entropy secret (numeric code, PIN, short share code) checked with no throttle. A signed-in re-verification (change-password asking the current password) counts: it is an oracle for a hijacked session.

Not findings: a lookup by a high-entropy random token (`Str::random(48)` and up, `random_hash()`) delivered out of band - it cannot be guessed, so it needs no throttle; `Hash::make()` (a write, not a check).

## Report

`PASS - <one line: N credential paths, all covered>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
