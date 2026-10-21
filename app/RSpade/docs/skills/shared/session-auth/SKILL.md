---
name: session-auth
description: "Working with RSpade sessions and the login flow - Session facade reads/writes, RsxAuth::verify_credentials() and login history, the password_reset_required refusal (Auth_Password_Reset_Required_Exception, rsx:users:force_password_reset), account-state enforcement, "where you're signed in" screens over user sessions (get_sessions_for_user(), get_user_session_id(), Audit_Session::list_page_for_user_session()), self vs admin termination guards, session lifetime limits (rsx.sessions.staff / .portal max_length_minutes and max_idle_minutes, ajax_grace_minutes, unused_session_days, the session_expired envelope, Rsx.set_session_expired_handler()), realm-tagged session values (put_value staff/portal/guest), token rotation, get_session_id() being null in commands and tasks, the concurrent cap, and web impersonation (begin_impersonation/stop_impersonation). Use when building a login or logout page, a \"where you're signed in\" screen, an admin sign-this-user-out button, an account-status check, a \"you have been signed out\" dialog, an idle or absolute session timeout, or a \"log in as user\" feature, or resetting a password or sign-in email from the CLI (rsx:users:password:set, rsx:users:email:set) - and see the csrf and login-redirect references for those two subsystems."
---

# Sessions and Login

`Session` is a static facade. **Never Laravel Auth, never `$_SESSION`.** The always-on fragment carries the API surface and the mandates; this skill is the how-to for the flows built on them.

`Portal_Session` is the portal twin over the same browser - see `rspade:portal-core`. `rsx:man session` is two parts: BASIC (the programmer contract) and ADVANCED (the three records, rotation, lifetimes, machine sessions, dispatcher tasks, internals).

**The model in three lines.** `_sessions` is the BROWSER (cookie, CSRF token, session values, two pointers: the current staff and portal sign-in). `_user_sessions` is "where you're signed in" - one row per browser x account x site x impersonator, reused when that account signs in there again, stamped with a sign-out reason when it ends. `_audit_sessions` is the acting context a record of an act points at. All three are system tables: never query them.

---

## Reading identity

```php
Session::is_logged_in();          // bool
Session::has_session();           // bool - does a session exist? CREATES NOTHING
Session::get_user();              // User_Model (this site's membership) or null
Session::get_user_id();
Session::get_login_user();        // Login_User_Model (cross-site identity) or null
Session::get_login_user_id();
Session::get_site();
Session::get_site_id();           // 0 if not set
Session::has_api_access();        // bool - may this identity use the external API?
```

Two identity models, and the distinction matters: `Login_User_Model` is the person (one login, valid across sites); `User_Model` is their membership in the CURRENT site (role, per-site enablement). Roles and permissions hang off the membership.

`has_api_access()` reads `users.is_api_access_enabled` and is the one predicate every API seam asks - `Api_Dispatcher` before it establishes a Bearer identity, the docs console before it renders, the key-management endpoints before they write. Its JS twin is `Permission.has_api_access()`. It answers for a Bearer-key identity too, which runs on the key's own session row (not a browser).

**None of the readers create a session.** `get_session()`, `get_session_id()`, `put_value()` and a sign-in do (`set_site_id()` with nobody signed in only declares the request's tenant). If you only want to know whether a session exists, ask `has_session()` - `get_session_id()` would have created one while you were asking.

**`get_session_id(): ?int` is NULL where there is no browser** - a command, a task, a test (each runs a row-less VIRTUAL session) - and in a `#[Run_As_Dispatcher]` task it is the dispatching browser. A column of yours storing it is nullable, and a screen showing it tolerates null. `get_user_session_id()` names the current sign-in (staff, or portal on a portal request; an API key's on an API call) - what a dispatched task binds to.

---

## The login flow

```php
#[Route('/login', methods: ['GET','POST'])]
#[Auth('public')]
public static function index(Request $request, array $params = [])
{
    if ($request->is_post()) {
        Rsx_Turnstile::validate($request);       // FIRST statement of the POST branch

        // Checks the pair and signs NOBODY in. record: false - whether this is a
        // SUCCESS is not known until the account question below is answered.
        $login_user = RsxAuth::verify_credentials([
            'email'    => $params['email'] ?? '',
            'password' => $params['password'] ?? '',
        ], record: false);

        if ($login_user === null) {
            Login_History::record_failure($params['email'] ?? '', Login_History::STATUS_FAILED_PASSWORD);
            Flash_Alert::error('Invalid credentials.');
            return redirect(Rsx::Route('Login_Controller', Login_Redirect::params()));
        }

        // YOUR account-state policy goes here - the framework has no opinion
        if ($login_user->status_id !== Login_User_Model::STATUS_ACTIVE) {
            Flash_Alert::error('This account is not active.');
            return redirect(Rsx::Route('Login_Controller'));
        }

        RsxAuth::login($login_user);             // the ONLY way into a staff session
        Login_History::record_success($login_user->id, $login_user->email);
        Session::set_site_id($site_id);

        return redirect(Login_Redirect::consume(Rsx::Route('Dashboard_Index_Action')));
    }

    return rsx_view('Login_Index');
}
```

### `RsxAuth::verify_credentials()` records the outcome itself

`RsxAuth::verify_credentials(array $credentials, bool $record = true): ?Login_User_Model` checks an email + password pair and answers the identity or null. **It signs nobody in and touches no session** - `RsxAuth::login($identity)` is the only way into a staff session, so anything a sign-in still owes (a second factor, your own account-state question) runs BETWEEN the two, with the visitor still signed out; there is never a session to undo on a refusal. It classifies and records SUCCESS / FAILED_NOT_FOUND / FAILED_PASSWORD / FAILED_DISABLED - **it is the only place that can tell those apart**. So **never call `Login_History::record_success()` beside it** with the default `record: true`; you would record twice. `record: false` is the opt-out for a check that is not yet a login - one that still owes a second step, a fixture, a harness - and then YOU record the outcome when it is known.

### A password that must be reset - the second thrown refusal

`login_users.password_reset_required` (and `portal_users.password_reset_required`) refuses a PASSWORD sign-in until the password changes. The app sets it (`$identity->require_password_reset(); $identity->save();`) or an operator runs `rsx:users:force_password_reset --user=<id|email>` / `--portal-user=<id>` (`--clear` lifts it; no email is sent, no session ended). The framework CLEARS it in the same save that writes a new password hash - unless that save assigns the flag itself (a temporary password that must be replaced).

`verify_credentials()` asks it LAST - after the password matched and an active membership was found - and THROWS `Auth_Password_Reset_Required_Exception` (`->identity`, `getMessage()` = "Your password must be reset before you can sign in."). A wrong password or a disabled membership on a flagged identity is still the plain null. The refusal is a `_login_history` ROW (`STATUS_FAILED_PASSWORD_RESET_REQUIRED`, `Login_History::record_refusal()`) **even under `record: false`** - nothing can turn it into a sign-in - and is never counted against the throttle. Catch it beside `Auth_Throttled_Exception` and point at your reset flow:

```php
try {
    $login_user = RsxAuth::verify_credentials($credentials, record: false);
} catch (Auth_Throttled_Exception $e) {
    $error = $e->getMessage();
} catch (Auth_Password_Reset_Required_Exception $e) {
    $error = $e->getMessage() . ' Use "Forgot your password?" to choose a new one.';
}
```

A passkey or SSO sign-in is NOT refused - it never presents the password. Uncaught, the exception is an error page, but only for an account somebody flagged. `rsx:man session`, PASSWORD RESET REQUIRED.

`RsxAuth::login($identity, $touch_last_login = true)`: `false` skips the `last_login` stamp - pass it whenever the login is not a real login (impersonation, a harness). **In CLI the stamp never happens at all.**

The second factor itself is `Rsx_Two_Factor` (TOTP, passkeys, recovery codes): `verify_credentials($credentials, record: false)` is the password stage, `begin_challenge()` parks the identity (nobody is signed in), and `verify_challenge()` is what logs in and records - it is throttle-first and writes `STATUS_FAILED_2FA` itself. Skill `rspade:two-factor`; `rsx:man two_factor`.

**A step a signed-in user still owes is the application's.** Enrolling a factor, accepting terms, an onboarding wizard: the login function works out what is outstanding once, at sign-in, and parks it with `Session::put_value()`; `Main::pre_dispatch()` redirects page requests to the step's screen while the value is set (leaving login, the step's own routes and logout alone). The user is signed in throughout - an outstanding step is not an access level, and the gates decide what they may do as on any request.

### Preference variables - a small fact about a person

`$user->get_variable($key, $default = null)` / `set_variable($key, $value)` / `forget_variable($key)` / `has_variable($key)` on `User_Model`, `Login_User_Model` and `Portal_User_Model`: a JSON key/value store beside the record, for a step completed or skipped, a prompt dismissed, "do not show this again". Reached for the current person as `Session::get_login_user()->set_variable(...)` (the IDENTITY, on every site - "declined a passkey") or `Session::get_user()->set_variable(...)` (this SITE's record - "skipped connecting a storage account").

- **Never in the payload** - not `toArray()`, not `fetch()`, not the user object on every page; read on demand, one key at a time. No expiry, no revisions, no realtime. `null` is a value (`has_variable()` true). A soft delete keeps them.
- **A preference expected to exist for the life of the account is a COLUMN** - dark mode, timezone, notification settings: every user has a value, it is read constantly, it deserves a type, a default and a migration. If you query a variable across users, sort by it, or read it on every request, it has become a column.
- **`Session::put_value()` is for one browser session** and vanishes with it (a wizard's progress, the outstanding sign-in steps); a preference variable is for what should still be true on another device next month.

`rsx:man user_preference_variables`.

### Login history APIs, and their window

**A sign-in history GRID asks `Login_History::list_page($query)`** - `login_user_id` (required), `filter` (email, IP, user agent), `sort` (`created_at|email|ip_address|status`), `order`, `offset`, `limit` (1..200) - and gets `{rows, total, offset, limit}`, rows in `present_record()`'s shape. Invalid input throws. `get_history_for_user($id, $limit)` is the newest few, for a summary. Never query `_login_history`. **The portal's twin is `Portal_Login_History`** - the same API and statuses over `_portal_login_history` (scope key `portal_user_id`), written by `Portal_Auth::verify_credentials()`, `Rsx_Portal_Two_Factor` and `Rsx_Portal_Sso`; never query that table either.

```php
Login_History::get_failed_attempts_count($email);     // within the failure window ONLY
Login_History::get_failed_attempts_count_by_ip($ip);  // same
```

Successes, post-password refusals (`record_refusal()`) and passkey enrollment outcomes (written by `Rsx_Two_Factor`) are `_login_history` rows, pruned on `rsx.sessions.login_history_retention_days` (default 365). **Failures are ephemeral - no row at all**: a per-email and a per-IP counter expiring on `rsx.sessions.login_throttle.window_minutes` (default 15), plus one `Log::warning` line. `/login` is anonymous-reachable, and a persisted failure row was an unauthenticated INSERT anyone on the internet could drive.

Consequences you must design around: **a 30-day failed-attempt count is not answerable** - shrink such a stat to the window, or state the window in the label. And these counts are a **statistic, not the enforcement** - never build a second throttle on them (below).

### Brute-force throttling is the framework's, and it is already on

`Login_Throttle` counts FAILURES per CLIENT IP and locks the address out; `RsxAuth::verify_credentials()` calls `require_not_throttled()` as its **first statement**, before the lookup, and `Login_History::record_failure()` feeds it - so every failure you record (a bad second factor, a disabled account) counts, and an app writes nothing to inherit the protection.

```php
try {
    $login_user = RsxAuth::verify_credentials($credentials);
} catch (Auth_Throttled_Exception $e) {
    $error = $e->getMessage();     // "You're doing that too fast" - render it as the form error
}
```

**It THROWS rather than returning false**, because "we did not check" is not "those credentials are wrong" - a false would report an invalid password to a user whose password may be correct. `$e->retry_after_seconds` is for your logs and headers; the message deliberately tells the caller nothing else.

A password is verified by `RsxAuth::verify_credentials()` or, on the portal, `Portal_Auth::verify_credentials($site_id, ...)` - both throttle-first. A path that checks some OTHER guessable secret (a PIN, an access code) and records to neither history does both halves itself - `Login_Throttle::require_not_throttled()` before the check, `Login_Throttle::record_failure()` on the miss. **Never pair `record_failure()` with a `Login_History::record_failure()` on the same event** - that counts twice.

Config `rsx.sessions.login_throttle`: `enabled` (true), `attempts` (10), `window_minutes` (15), `lockout_minutes` (15). **A caller with no client IP is never throttled** - CLI, tasks and tests have no remote party to throttle. The client IP is `Session::get_client_ip()` = `$request->ip()`: X-Forwarded-For counts only through a trusted proxy (loopback plus `rsx.http.trusted_proxies`, empty by default), so **a box behind a load balancer must list it** or every visitor shares one bucket. Never read a forwarding header yourself. **Fail closed**: a cache error propagates and the login fails loud; only maintenance mode (redis deliberately stopped) leaves the throttle inert.

### Declaring a site without a session

`Session::set_temporary_site_id($site_id)` declares the tenant for the rest of the script and **writes nothing**: no `_sessions` row, no cookie, no change to an existing row - a browser mid-session has its own tenant back after `clear_temporary_site_id()`. `get_site_id()` answers the declared value and `has_session()` answers true meanwhile; it outranks the stored session and is refused inside an external API request. Use it where code must run in a site's context and no session should come into being (a first-run request, a webhook that names its tenant); `set_site_id()` is the call that changes a signed-in user's tenant. Staff realm and site only - the portal declares its site with `Portal_Session::set_site_id()`.

### Account state is APPLICATION vocabulary; site membership is the FRAMEWORK's

`verify_credentials()` checks a live identity, the password, and site membership - and **nothing else**. `login_users.status_id` / `is_activated` / `is_verified` mean whatever your app decides. Enforce your statuses in **two** places:

1. Your login function (above), between `verify_credentials()` and `login()` - so a bad-state login never starts, and nobody has to be logged back out.
2. `Main::pre_dispatch()` - return non-null to halt, which ejects a session whose account went bad AFTER sign-in. It runs for bearer-key calls too - `/api/vN` and the file-serving routes (a non-null return there is a 403 `account_refused`; branch on `Session::is_api_request()` before `Session::logout()`, which a headless API identity cannot call). It also runs once per Ajax CALL (batched or not, `$params['controller']`/`['action']` naming the endpoint): non-null refuses that call only - return `response_unauthorized('Your account is suspended.')` to give it your reason; anything else (a redirect) answers `unauthorized` "This request was refused.". An interstitial must therefore let the fixing screen's own calls through (decide on `Rsx_Request_Channel::current()` or the endpoint). **`init()` is a bootstrap hook and cannot eject anybody.**

**`users.is_enabled` and `sites.is_enabled` are NOT yours - delete every check you wrote on them.** They are the framework's switches - one membership, one whole site - and a membership is usable (**active**) only when both are on: `User_Model::is_active()` for a row, the `->active()` scope for a query. The Default site (id 0) can never be disabled (the save throws). Enforced twice:

- **At login.** No active membership on any site -> `verify_credentials()` answers null, indistinguishable from a wrong password, with `Login_History::STATUS_FAILED_DISABLED` in the audit trail. `RsxAuth::has_enabled_membership($login_user)` is the public predicate. Every other door runs through `RsxAuth::login()`, which **returns false without touching the session** for the same reason - so the second factor, a federated sign-in and the `rsx:debug` dev-auth harness are all refused too.
- **At every request.** `Session::enforce_enabled_membership()` runs ahead of the `#[Auth]` gates in the staff dispatcher and for every Ajax call: a missing or inactive `users` row for (login user, site) - its own switch off, or its SITE disabled or deleted - **logs the session out** and answers `response_auth_required()` - a login redirect for a page, the `auth_required` envelope for an Ajax call. The **effective** identity is checked, so disabling an account also ends an impersonation of it.

A listing of "which sites may I use" therefore restates neither column: it filters with `->active()`, the same definition the framework enforces, so it never offers a site the framework would refuse, and `Site_Unauthorized`-style screens are reached only where the app itself declares the requested site.

---

## "Where you're signed in" - user sessions

```php
$sessions = Session::get_sessions_for_user();                              // current account
$sessions = Session::get_sessions_for_user($login_user_id, $site_id);      // one site's view
$sessions = Session::get_sessions_for_user($login_user_id, null, true);    // admin: impersonations too
$info     = Session::get_current_session_info();                           // this browser's staff sign-in, or null
```

Every entry is a **USER SESSION**: one browser x account x site (x impersonator). The same account signing in again on that browser REUSES its entry (`sign_in_count` grows, `first_seen` stays, `signed_in_at` is this sign-in); another account on the same browser is a different entry and **never appears in this account's list**. Answered: every one still signed in, plus those signed out within `rsx.sessions.unused_session_days` (30) - so a screen can show "Signed out 2 days ago - Sign-in expired". Never a `rsx:debug` harness browser. An API key of the account that has been used is one entry (`is_api`) - the terminate functions do not end it; revoking the key does.

Fields: `id` (the user session - what the terminate functions take), `session_id` (the browser), `site_id`, `user_id`, `ip_address`, `user_agent`, `user_agent_parsed`, `device_summary`, `last_active`, `created_at`, `signed_in_at`, `first_seen`, `signed_out_at`, `sign_out_reason` (a label), `is_signed_in`, `is_current`, `is_impersonation`, `impersonator_id`, `impersonation_started_at`, `sign_in_count`, `type_id`, `is_api`. The address and agent are the browser's, refreshed at each sign-in on it.

**Show a bounded window, cut in ONE place.** The whole set is returned (a user cannot end a session they were not shown); a screen lists the entries active within a window - the template keeps 3 months and decides `can_sign_out` (signed in, not `is_api`, not `is_current`) in `rsx/lib/sessions/session_listing.php`, which its user-management, Password & Security and portal settings screens all use. An endpoint handed an id accepts it only if it is one of the listed entries.

**A detail screen per entry** pages that sign-in's audit sessions - every address and user agent it acted from: `Audit_Session::list_page_for_user_session(['user_session_id' => $id, 'filter' => ..., 'sort' => 'last_seen_at'|'first_seen_at'|'ip_address', 'order', 'offset', 'limit'])` -> `{rows, total, offset, limit}`. Ungated: check the id belongs to the account first. Rows appear only when something asked for the acting context (a revision-recorded write, a dispatched task) - browsing adds none.

**An impersonation is not one of the user's sessions** - it is somebody else acting as them from somebody else's browser - and is left out unless `$include_impersonations` asks (third argument staff, second portal), for an ADMINISTRATOR's view that labels `is_impersonation`. `Portal_Session::get_sessions_for_user($portal_user_id, $include_impersonations)` is the portal twin.

### Terminating - pick by whose sign-ins, and who is asking

All of these take USER SESSION ids and end only SIGNED-IN user sessions of the staff realm; a portal sign-in on the same browser is untouched (the portal twins below end those).

```php
// SELF, one sign-in on ANOTHER browser. Another account's id matches nothing -> false.
// Anything on THIS browser is refused - that is logout()'s job.
Session::terminate_session($user_session_id): bool

// SELF, everything else - "sign out everywhere else".
// Also forgets every other browser's two-factor trust.
Session::terminate_all_other_sessions(): int

// ANOTHER USER - the ADMIN primitives, authorized first
Session::terminate_session_for_user($login_user_id, $user_session_id): bool
Session::terminate_all_sessions_for_user($login_user_id, $except_user_session_id = null): int

// ANY IDENTITY, ANY SITE - the DEVELOPER primitive (the /_sys panel); null = every
// sign-in except those on the caller's own browser. Authorized by Session::is_developer() alone.
Session::developer_terminate_sessions($login_user_id, $user_session_id = null): int

// FRAMEWORK-INTERNAL, NO AUTHORIZATION - for a caller with no acting user (reason "Ended by the system")
Session::_deactivate_sessions_for_user($login_user_id): int

// PORTAL twins (a portal account's own; the last unguarded, for an authorized admin action)
Portal_Session::terminate_session($user_session_id): bool
Portal_Session::terminate_all_other_sessions(): int
Portal_Session::terminate_all_sessions_for_user($portal_user_id, $except_user_session_id = null): int
```

**A password change needs NONE of these.** Saving a new password through `Login_User_Model` / `Portal_User_Model` ends every OTHER signed-in sign-in of that identity in its realm by itself (the models' `updated` hook; reason `SIGN_OUT_PASSWORD_CHANGED`, "Password changed"): the browser making the change is spared, a command/task/API call (no browser) ends every web sign-in, impersonations of the identity end with it, and an API key's session (`REALM_API`) is never touched. So a change-password endpoint just saves the hash - never call `terminate_all_other_sessions()` beside it. A raw-SQL password write skips this. `rsx:man session`, PASSWORD CHANGES.

**What ending a sign-in does** (one path for every sign-out): the user session is stamped `signed_out_at` + a reason (`Session::SIGN_OUT_*`; `sign_out_reason_label()`), the browser's pointer is cleared, that REALM's session values are cleared, the impersonations riding a staff sign-in end with it, and the browser gets a realtime refresh push. **The other browser's token is NOT rotated** - its row now grants nothing a guest would not get, and its next sign-in rotates it. The browser row itself stays (a browser that has had a sign-in is never deleted).

**The authorization rule on the two cross-user functions - two ways to be authorized, only two:**

1. **The target IS the actor** - managing your own sign-ins needs no role.
2. **The actor's role may administer the target's** - `Permission::can_admin_role()` against the target's per-site `users.role_id`. That list is equal-or-lower ("my role and the roles below it"), so **a SUPERIOR is refused by construction and a PEER is permitted** - two Site Admins may sign each other out, as they may edit each other. A target with no `users` row on the acting site is refused too.

`developer_terminate_sessions()` consults no role: a caller that is not a developer is refused by the same throw, and it never ends a sign-in on the caller's own browser (0).

**REFUSAL THROWS `AjaxUnauthorizedException` (403); ABSENCE returns false/0** ("no such signed-in user session of that account"). **Never conflate them** - that conflation is what hid a broken admin-terminate button for months: the endpoint read "false" as "nothing to do" and reported success while every call was actually being refused. Your endpoint still declares its own `#[Auth]` gate; the target rule is the framework's.

The rule **assumes a single site** (a role is per-site, a sign-in identity is cross-site); multi-tenant termination is not supported yet (B-92).

Each staff user session a terminate function ends fires an event:

```php
#[OnEvent('session.terminated')]
public static function on_terminated(array $payload) {
    // {actor_login_user_id, target_login_user_id, user_session_id, session_id (the browser), scope: 'self'|'admin'|'internal'}
}
```

**The event stream is not a record of every sign-out** - the cap, the lifetimes and the sweeps fire none. The user sessions themselves are that record.

---

## Attributing a logged act: the audit session

**Never store `Session::get_session_id()` on a record of something somebody did** - it is the browser, whoever is signed in on it changes, and it is null in a command or a task. Store `Session::get_audit_session_id()`: a permanent row per user session x site x address x user agent (a guest's act: per browser), which also exists for an API call and for a `#[Run_As_Dispatcher]` task (the requester's), and is NULL in a command or a plain task. Read one back with `Session::get_audit_session($id)`; show them in a grid with `Audit_Session::join($query, 'table.audit_session_id')`. **Skill `rspade:audit-session` owns the detail.**

`get_session_id()` is for the opposite job: state that belongs to the BROWSER and is deleted with it. A table of your own keyed by it declares `FOREIGN KEY (session_id) REFERENCES _sessions (id) ON DELETE CASCADE` (the one reference an application makes to a system table), with the column NULLABLE when a command or task may write the row.

## Session lifetime

**Never implement "Remember Me" or a timeout of your own**: the cookie is long-lived and config ends a sign-in. The defaults (`system/config/rsx.php`), overridden in `rsx/resource/config/rsx.php`:

```php
'sessions' => [
    'staff'  => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],   // 7 days / 8 hours
    'portal' => ['max_length_minutes' => 10080, 'max_idle_minutes' => 480],
    'ajax_grace_minutes' => 120,
    'unused_session_days' => 30,       // a browser unused this long is made inactive
    'guest_expiry_minutes' => 43200,   // a never-signed-in browser unused this long is deleted
],
```

To keep longer sign-ins, set both staff (and portal) numbers higher there - e.g. `['max_length_minutes' => 43200, 'max_idle_minutes' => 43200]` for 30 days each.

- **`max_length_minutes`** - from the sign-in. The hard ceiling; the person signs in again (second factor included). **This is the one that bounds a hijacked session** - a thief is never idle.
- **`max_idle_minutes`** - from the last PAGE VIEW: a page rendering its bundle, or a completed SPA navigation (the browser reports those itself). **Ajax, downloads, thumbnails and API calls are not page views**, so a polling tab does not keep a sign-in alive.
- `0` = no limit. Both are measured on the USER SESSION. A page view also stamps `last_seen` on the account (`users`, `login_users`, `portal_users`), at most once a minute.

**Enforced on every request, before the gates - you write nothing:**

| request | past a limit |
|---|---|
| page navigation | signed out (reason "Sign-in expired" / "Signed out after inactivity"), redirected to sign-in with the URL captured, flash says why |
| SPA navigation | same judgement, through the page-view report - no grace |
| Ajax / thumbnail / download | keeps working for `ajax_grace_minutes` (so the open form can still save), then signs out and answers the `session_expired` envelope |

The hourly `Session_Cleanup_Service::cleanup_sessions` signs out the sign-ins whose browser never comes back, once past a limit PLUS the grace, so a "where you're signed in" list stops showing them as signed in. A browser unused `unused_session_days` is made inactive (at its next lookup, or by the daily maintenance task) and what was signed in on it is signed out ("Browser unused for 30 days"); its next visit starts a new session.

**The one thing an application supplies is the dialog.** The framework announces a lost sign-in ONCE per page - `session_expired`, or `auth_required` on a page rendered signed in (ended elsewhere) - to the handler you register; with none it reloads the page:

```javascript
static on_app_modules_define() {
    Rsx.set_session_expired_handler((info) => {
        // an undismissable dialog showing info.message, whose button does window.location.reload()
    });
}
```

The reload is the whole "return to login": the server redirects and brings the user back to this URL afterwards. Worked example: `system/app/RSpade/resource/reference_app/lib/modal/modal.js`.

### Tokens rotate at every sign-in and sign-out

Every sign-in and sign-out on the browser making the request gives its ONE row a new session token and CSRF token, in place - so a copied or planted cookie is worthless across a sign-in, and a forced re-login actually evicts whoever else held it. The row keeps the hash of the token it replaced: **an Ajax call or sub-resource still carrying it is refused** (`session_expired`, cause `rotated`; sub-resource 401) and sent no cookie, while a PAGE NAVIGATION carrying it is an ordinary guest (a browser that missed its new cookie recovers). Consequences:

- `get_session_id()` is the SAME number across sign-ins on one browser; `get_user_session_id()` is what changes per account.
- The CSRF token changes. A sign-in made by an Ajax call is handed the new token automatically; a hand-built form rendered before the sign-in must be re-rendered.
- Site switches, impersonation begin/stop and View as Client do NOT rotate - nobody re-authenticated.

**The concurrent cap** (`rsx.sessions.max_web_sessions_per_user`, default 25 per realm; 0/null disables) signs out an account's least recently active signed-in user sessions beyond the cap at every sign-in, on browsers only (never a harness or API row), impersonations not counted. Silent: no event, no push.

### Commands, tasks and the harness

**A command, a task and a test run a VIRTUAL session**: no row, no audit session, `get_session_id()` / `get_audit_session_id()` null, the identity setters write in-memory only (never `last_login`), `put_value()` throws. A worker blanks the context after every run; `Task::internal()` restores its caller's. A task that is a person's request moved off the request thread declares `#[Run_As_Dispatcher]` and runs FROZEN as that sign-in (skill `rspade:background-tasks`). `rsx:debug` reuses one Playwright row per account and site while its sign-in is within the limits - nothing purges them, and they never appear in a session list (skill `rspade:rsx-debug`). An API key runs on one session row of its own (skill `rspade:external-api`).

---

## Web impersonation ("log in as user")

An impersonation is a USER SESSION of its own on the impersonator's browser (the target, the current site, the impersonator) that the staff pointer names while it lasts; the impersonator's own sign-in stays signed in behind it, suspended. Contrast the portal's read-only "View as Client" (`Portal_Session::begin_impersonation_from_staff()` - a PORTAL user session on the staff member's browser, through a linked-session handshake when the portal has its own host; skill `rspade:portal-core`).

```php
Session::begin_impersonation(int $target_login_user_id);  // throws if not logged in,
                                                          // already impersonating, or self
Session::stop_impersonation(): bool;                      // signs it out, points back; false if not impersonating
Session::is_impersonating(): bool;
Session::get_impersonator_login_user_id(): ?int;
Session::get_impersonator_login_user(): ?Login_User_Model;
Session::get_impersonation_started_at(): ?string;
```

**Full read/write** - there is no read-only mandate here (that is a portal-only constraint). **Authorization is the app's job**; core only provides the mechanism, so gate the entry point yourself.

It runs on the impersonator's clocks (no new sign-in, no rotation, `last_login` untouched). `logout()` - and a limit passing, and another account signing in - **ends both** the impersonation and the impersonator's own sign-in; `stop_impersonation()` is the graceful return. A site switch moves the impersonation and keeps the sign-in behind it. `rsx.sessions.impersonation_max_days` (7) ends old ones daily.

Client side, `window.rsxapp.impersonation` is `{impersonator_login_user_id, impersonator_email, started_at}` or `null` - the impersonator named by login identity; a richer display name is the app's to resolve.

The framework's own entry point is the `/_sys` panel's "Sign in as this user" (developers only): it stores the developer's site under the session value `sys.impersonation.return_site`, moves the sign-in to the chosen membership's site, begins, and `GET /_sys/stop-impersonating` (`Rsx.Route('_Sys_Impersonation_Controller::stop')`, published into every bundle) restores both. **A `Main::init()` that declares the staff site on every request overrides the chosen site on the next request** - and an impersonated identity with no active membership on the declared site signs out the impersonation AND the developer's own sign-in. `rsx:man sys_panel`, IMPERSONATION.

```javascript
if (window.rsxapp.impersonation) { /* render the "viewing as" banner + a Stop control */ }
```

---

## Session values

Small values that belong to a **browser** rather than to a user or a record — a parked second-factor challenge, a half-finished wizard, a dismissed banner. **They are not storage**: they last no longer than the sign-in they belong to. Real per-browser data goes in a table of yours keyed on `get_session_id()`; anything a user should have on another device goes on the user (preference variables above).

```php
Session::put_value('wizard.step', 3);                                    // the request's realm
Session::put_value('draft', $data, Rsx_Time::add(Rsx_Time::now_iso(), 3600));
Session::put_value('banner.dismissed', true, null, 'guest');             // survives every sign-out
Session::get_value('wizard.step', $default_when_absent);
Session::forget_value('wizard.step');
```

- **Every value has a REALM**: `'staff'`, `'portal'` or `'guest'`; null = the request's experience (`'portal'` on a portal request, `'staff'` otherwise - even before anybody signs in, so a value parked before a sign-in survives it). **A realm's sign-out clears that realm's values** - logout, a limit, an ending from elsewhere, another account signing in over it. A site switch and an impersonation starting/ending keep them. `'guest'` values are the browser's own and survive every sign-out. Read and forget in the realm you wrote; `Portal_Session::put_value()` / `get_value()` / `forget_value()` are the `'portal'` realm.
- **`begin_challenge()` signs the realm out FIRST**, so a value written before it is cleared - write what must ride a second-factor challenge AFTER `begin_challenge()` (the template's login controllers park their invite code / client id that way), or in `'guest'`.
- **`put_value()` is a WRITER** and creates a session for a guest; **`get_value()`/`forget_value()` are READERS** and never do. No session -> `get_value()` returns the default.
- **Where there is no browser they refuse**: `put_value()` THROWS in a command, a task and an API request ("session values belong to a browser"); all three throw inside a `#[Run_As_Dispatcher]` task. A test acts as a browser with `__as_browser()`.
- **Expiry is optional with no default.** Pass one only when a value must die *sooner* than its realm keeps it. An expired value reads as absent immediately; `Session_Values_Cleanup_Service` only reclaims space.
- Values are JSON-encoded, so `false` round-trips as `false`. `put_value()` is an upsert. `reset()` clears every realm.

## Tenant context

`Session::set_site_id(int $id)` moves the browser's staff sign-in to that site - the user session for the new site is found or created and made current, the old one signed out "Switched site", carrying the sign-in's clocks (no rotation, no lifetime restart). With nobody signed in it is a request-scoped declaration that writes nothing. Everything site-scoped follows it (the model global scope, mail, settings, time zones, throttling). It is the STAFF app's tenancy only; a portal request's site comes from the app's own declaration (`rspade:portal-core`).

A site-switcher endpoint validates membership itself - the framework will not check that the user belongs to the site you name:

```php
$membership = User_Model::where('login_user_id', Session::get_login_user_id())
    ->where('site_id', $params['site_id'])->active()->first();
if (!$membership) { return response_unauthorized(); }
Session::set_site_id((int) $params['site_id']);
```

---

## Operator credential repair (CLI)

When nobody can sign in to fix an account through a screen, an operator sets the credentials from the command line. `--user` takes a `login_users.id` or the current email address, exactly as `rsx:users:2fa:*` and `rsx:users:sso:*` do.

- `rsx:users:password:set --user=<id|email>` prompts twice with no echo; `--password-stdin` reads the first line of stdin for a script; `--password=` works but lands in shell history and the process list. It **ends every web session of the identity** (the model's password-change consequence; an API key's session is left alone), and enforces no policy beyond "not empty".
- `rsx:users:email:set --user=<id|email> --email=<new>` moves the sign-in address and every membership (`users` row, any site, trashed included) that showed the OLD address, refusing an address any other identity holds - a soft-deleted one included.

Never write a one-off script or raw SQL for either: a hand-written `UPDATE login_users SET password` skips the session termination, and a hand-moved email leaves the memberships behind. Contract and error codes: `rsx:man auth` (OPERATOR COMMANDS).

---

## Troubleshooting

- **"A session was created where I only wanted to check for one."** Something called `get_session_id()`/`get_session()`/`put_value()`. Use `has_session()` for the question.
- **A column storing `get_session_id()` fails NOT NULL from a task or command.** There is no browser there; make the column nullable (and if the row records an act, store `get_audit_session_id()` instead).
- **"session values belong to a browser" / "this task runs as the user who dispatched it".** A command, task or dispatcher task touched session values or tried to change identity. Pass what it needs as parameters.
- **A value parked during login is gone after the challenge.** It was written before `begin_challenge()`, whose sign-out cleared the realm. Write it after.
- **An admin "sign out" button that always reports success but changes nothing.** The classic refusal-vs-absence conflation: the call was throwing `AjaxUnauthorizedException` (or returning false for a genuinely absent row) and the endpoint treated both as "done". Let the throw propagate; treat false as "nothing matched".
- **A user is still signed in after their APP status changed.** `verify_credentials()` only guards the sign-in. Enforce your statuses in `Main::pre_dispatch()` too, and terminate their sessions when you change them (`users.is_enabled` needs neither - the framework ends those sign-ins itself).
- **Failed-attempt count reads zero for a user you know just failed.** The window (`login_throttle.window_minutes`, default 15) elapsed, or the cache was restarted. Both are expected - the counters are ephemeral, and they are a statistic rather than the enforcement.
- **A login form reports "invalid email or password" for a correct password.** The attempt was throttled and the `Auth_Throttled_Exception` was swallowed into the same branch as a credential miss. Catch it separately and show `$e->getMessage()`.
- **A test or command "cannot log in any more".** It cannot be the throttle: CLI has no client IP and is never throttled. Clear a real lockout with `Login_Throttle::reset($ip)`.
- **A user reports being signed out "for no reason".** Their entry in `get_sessions_for_user()` carries the reason: a limit (8 hours idle / 7 days by default), ended from another device or by an administrator, the session cap, another account signing in on that browser, a membership disabled.
- **A "where you're signed in" list shows another person's sign-in on a shared computer.** It cannot - entries are per account. Two accounts on one browser are two user sessions, each listed only for its own account.
- **A page that polls forever still gets signed out.** Correct: Ajax is not a page view. Idle means nobody navigated.
- **Ajax calls fail the CSRF check right after a custom sign-in.** The page is using a token from before the sign-in rotated it. Sign in through an Ajax endpoint (the envelope updates the token) or reload.
- **A test signs in but `last_login` moves.** Pass `$touch_last_login = false` to `RsxAuth::login()` for harness logins.

---

## Two subsystems with their own references

- **CSRF** - fully automatic; there is nothing to write. `references/csrf.md`.
- **Intended-URL redirect** - `Login_Redirect`, the one sanitizer; wire every hop. `references/login-redirect.md`.

Details: `php artisan rsx:man session`, `rsx:man auth`. Related: `rspade:auth-gates`, `rspade:permissions-acl`, `rspade:portal-core`, `rspade:turnstile`.
