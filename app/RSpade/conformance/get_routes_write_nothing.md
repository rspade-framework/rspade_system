---
id: get_routes_write_nothing
title: No page route changes state on a GET
severity: critical
when: launch, change
scope: rsx/**/*_controller.php, rsx/**/*_Controller.php
related: rsx:man csrf, rsx:man routing, rsx:man portal, skill rspade:session-auth
---

## The contract

Every GET page route - staff `#[Route]` and portal `#[Portal_Route]` - only reads, renders, redirects or downloads; it writes nothing. CSRF enforcement (`Rsx_Csrf`, the Origin/Referer check plus the session-gated token) runs for POST only, so a state-changing GET is invisible to it: any other site can trigger it with an `<img src>`, a link, a prefetch or a redirect, riding the victim's session cookie. This one invariant is what makes POST-only CSRF complete.

Read `php artisan rsx:man csrf` and `php artisan rsx:man routing` first.

Already enforced, not re-checked here: routes are GET or POST only (`ROUTE-VERB-01`, manifest build); every Ajax endpoint is a POST and is CSRF-covered; `#[Api_Endpoint]` GETs are the separate audit `api_get_endpoints_read_only`.

## Find every instance

1. Staff page routes (a dual-method route prints one GET line and one POST line for the same action):

       php artisan rsx:routes --method=GET | grep 'type=standard' | grep 'file=rsx/'

   `type=spa` rows are client-side actions served by the SPA bootstrap; read the bootstrap controller once, not each row.

2. Portal routes. `rsx:routes` does not print them, and `methods:` defaults to `['GET']` when omitted, so list every declaration and treat a missing `methods:` as GET:

       grep -rn "#\[Portal_Route(" rsx --include='*.php' | grep -v '^rsx/tests/'

3. For each GET handler, read the method body and every function it calls on the GET path. A write is anything that persists or changes state: a model `save()`/`delete()`/`update()`/`create()`, `DB::` writes, `Session::`/`Portal_Session::` writes (`set_*`, `put_value`, `logout`, `terminate_*`, impersonation), `Task::dispatch`, a mail or SMS send, a file write, a cache or counter write.

## What does not conform

- A business-data write reachable by GET (`/items/:id/delete`, `/toggle`, `/mark-read`, an approve link) - move it to a POST `#[Route]`/`#[Portal_Route]` or an `#[Ajax_Endpoint]`.
- A dual-method route (`methods: ['GET', 'POST']`) that writes on the GET branch: every write sits behind `if ($request->is_post())` (or `isMethod('POST')`), and the GET branch only renders.
- A GET that acts on a predictable id or a "hard to guess" URL that is not a real token.

Not findings (confirm each, then pass it):

- One-time-token links: a GET acting on a single-use, high-entropy, server-issued token delivered out of band - email verification (`/login/verify`), password reset, invitation accept, the signed one-time legs of View as Client. Confirm the token is cryptographically random and consumed on use; a short or numeric code is a finding.
- An OAuth callback completing a sign-in on a GET carrying the provider's `code` and `state` (framework SSO ceremony, protected by state/PKCE).
- Session-lifecycle GETs: `/logout`, the portal logout, the portal `/impersonate/stop`, and the framework's `/_sys/logout` and `/_sys/stop-impersonating`. A forced sign-out is the accepted impact; report one only if it does more than end a session or impersonation.
- Incidental telemetry with no user-visible effect (a last-seen stamp, a view counter, a cache fill).

## Report

`PASS - <one line: N staff and M portal GET handlers read, none writes>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
