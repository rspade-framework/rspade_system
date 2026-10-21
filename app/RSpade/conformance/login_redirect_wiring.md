---
id: login_redirect_wiring
title: The intended URL survives every sign-in hop
severity: high
when: launch, change
scope: rsx/app/login/**, rsx/portal/auth/**, rsx/main.php, rsx/portal_main.php, rsx/handlers/*Sso*
related: rsx:man login_redirect, rsx:man auth_gates, rsx:man portal, skill rspade:session-auth
---

## The contract

A visitor who opens a protected deep URL while signed out lands on that URL after signing in, in both the staff app and the portal, through every hop the sign-in takes (second factor, passkey, SSO, site selection). Every read of the `?redirect=` value goes through `Login_Redirect` - `params()` merged into each outbound login-flow route, `hidden_input()` in each login-flow form, `consume($default)` once at each terminal destination - and through nothing else. A dropped hop sends deep links to the dashboard; a second reader or validator is an open redirect.

Read `php artisan rsx:man login_redirect` in full first (THE WIRING CONTRACT, VALIDATION GUARANTEES, ROUTABILITY, PORTAL PARITY), and skill `rspade:session-auth`, reference `references/login-redirect.md`.

The framework does the capture: when an `#[Auth]` gate refuses a signed-out page request, the dispatcher redirects to `Login_Controller` / `Portal_Login_Controller` with the captured target (`Error_Screens::unauthorized()` calls `Login_Redirect::capture()`). The same capture runs when a page navigation finds the sign-in expired. The application has no rejection point to wire; it owns everything from the login page onward.

## Find every instance

1. Every sign-in surface, staff and portal:

       ls rsx/app/login rsx/portal/auth
       grep -rnE "RsxAuth::login\(|verify_challenge\(|verify_passkey_login\(|consume_pending_and_login\(|set_portal_user_id\(|function complete_sign_in|function post_login_destination|function post_auth_destination" rsx --include='*.php' | grep -v '^rsx/tests/'

   Build the flow graph for each experience: login form -> (2FA challenge | passkey | SSO hook) -> (site selection | invite completion) -> terminal destination. Include every hop in an `sso.*` handler (`grep -rn "OnEvent('\(portal\.\)\?sso\." rsx/handlers`).

2. Every `Login_Redirect` call:

       grep -rn "Login_Redirect" rsx --include='*.php' --include='*.js' --include='*.jqhtml' | grep -v '^rsx/tests/'

3. Hand-built or hand-read redirect values:

       grep -rnE "\\\$_GET\[|request\(\)->(get|query|input)\('redirect'|Route\(.*'redirect' *=>|[?&]redirect=" rsx --include='*.php' --include='*.js' --include='*.jqhtml' | grep -v '^rsx/tests/'

   An endpoint returning `['redirect' => Rsx::Route(...)]` is the form engine's navigation answer, not the login parameter - ignore those.

4. Any `pre_dispatch()` in `rsx/main.php` / `rsx/portal_main.php` that returns its own redirect to a login or interstitial page.

For each hop, read the blade/jqhtml form and the controller method that receives it.

## What does not conform

- A login-flow form (password, 2FA challenge, site picker, invite completion, portal register) that does not emit `Login_Redirect::hidden_input()`.
- A redirect between login-flow hops that does not merge `Login_Redirect::params()` into the `Rsx::Route()` / `Rsx_Portal::Route()` call.
- A terminal that hard-codes its destination instead of `Login_Redirect::consume($default)`, or calls `consume()` more than once on one path. Where one function is the terminal for every path (password, 2FA, passkey, SSO), `consume()` belongs there once.
- A redirect target that skips an owed step: invite completion, site selection and onboarding outrank the captured URL; `consume()` supplies the default, never an override.
- Any hand read of the parameter (`$_GET['redirect']`, `request()->get('redirect')`), a hand-built `redirect=` query string (including one passed to the logout route), or a second validator.
- The intended URL stashed in `Session::put_value()`, `Portal_Session::put_value()`, a cookie or browser storage - it is per-navigation state, and a stash sends one tab to another tab's target.
- An application `pre_dispatch()` that redirects a signed-out visitor to a login page without `Login_Redirect::capture($request)`.

Not findings:

- The absence of `capture()` in `main.php` / `portal_main.php` - the framework captures.
- Targets the validator drops by design: off-host, scheme-relative, `javascript:`, any `#`, backslashes, control characters, over 2000 characters, `/_` and `/api` paths, login-loop paths, the other experience's paths, a path no registered GET route handles, and a bare `/` with no query.
- Under a portal catch-all route (`#[Portal_Route('/*')]`, as the template's `Portal_Spa_Controller` declares) every under-prefix portal path is routable, so a non-existent portal path is NOT dropped. Expected; not a finding.

On an unmodified template the portal is wired end to end and the staff login is not (`rsx/app/login/CLAUDE.md`, HOW TO CUSTOMIZE): report the staff gaps as findings anyway.

## Report

`PASS - <one line: which flows were traced and found wired>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
