---
name: site-locks
description: "Writing code under the per-site reader/writer locks - #[Writer] / #[Reader] / #[Manual_Lock] on Ajax, API, companion and route surfaces, Site_Lock::write() / read() / bypass() / bypass_all_sites() sections in tasks, commands, scripts and hooks, read-then-write, RsxAuth::enter_site(), #[Site_Lock_Exempt], the SITE_LOCK_EXEMPT constant, rsx:site_lock:endpoints. Use when an endpoint, task or command saves a site-scoped model, converting code to the locks, signing in or switching site, or on 'Site lock enforcement: ... without site N's write lock', 'Refused to take the WRITE lock on site N while holding its READ lock', 'Site lock rule 1', 'Site lock rule 5', 'refused inside Ajax', 'Site lock held too long' or SITE-LOCK-01."
---

# Site Locks

Every site has ONE readers-writer lock. Readers run together; a writer runs **alone** on the site inside one transaction. Contract: `rsx:man site_locks`. The primitive underneath: skill `rspade:locks-and-subprocesses`.

**The guarantee you design against**: inside a `#[Writer]` endpoint or a `Site_Lock::write()` section nothing else changes the site. Read a record, decide, write - no re-check, no optimistic retry, no lock of your own. A reader sees data that is not changing while it reads. This is a contract, not an incidental detail: write code that relies on it.

**The enforcement**: inside an endpoint, route, hook, task, command or script, a `save()` / `delete()` / bulk write of a `Site_Scoped` model fatals unless the process holds that site's write lock or is in a bypass. Nothing acquires a lock because a write happened.

---

## 1. Endpoints: readers by default, `#[Writer]` when it writes

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
#[Writer]                                  // declares intent: "this endpoint writes"
public static function save(Request $request, array $params = [])
{
    $errors = static::__validate($params);
    if ($errors) {
        return response_form_error('Please correct the errors below.', $errors);
    }

    $client = Client_Model::find($params['id']);
    $client->name = $params['name'];
    $client->save();

    return ['id' => $client->id];
}
```

| Surface | Default | Declare |
|---|---|---|
| `#[Ajax_Endpoint]`, `#[Api_Endpoint]`, `#[Companion_Endpoint]` | reader | `#[Writer]` when it saves, deletes or dispatches; `#[Manual_Lock]` to sign in / switch site or for multi-section work |
| model `fetch()` / fetchable relationship | reader | nothing (a fetch reads) |
| `#[Route]`, `#[SPA]`, `#[Portal_Route]` | none (no lock) | `#[Writer]` when the action writes, `#[Reader]` for a consistent multi-query read |

- **A page route with no mode still enforces**: its action that writes a site-scoped model fatals. Declare `#[Writer]`, or wrap the write in `Site_Lock::write()`.
- **What commits**: a writer that RETURNS commits - including a returned `response_form_error()` / `response_form_question()` / API error response, so **validate before the first write**. A THROW rolls the whole request back.
- **A `#[Writer]` GET `#[Api_Endpoint]`** is refused by `API-GET-PURE-01`.
- `Task::dispatch()` from a writer: the run starts after the commit, or never (rollback).
- **Return right away.** Every request holds its site's lock for its whole handler; a slow reader holds back every writer and every reader queued behind it. A pass over thousands of records, an export, a big aggregation: validate, `Task::dispatch()`, return the run's handle; the feature exposes its own status and result endpoints.

Inventory: `php artisan rsx:site_lock:endpoints --app --mode=reader` - walk the readers that save, delete or dispatch, and mark them `#[Writer]`. `*` marks a declared mode.

## 2. Read-then-write: take the write lock FIRST

There is **no upgrade** from read to write - two upgrading readers would wait for each other forever. Code that reads and then writes based on what it read does the read **inside** the write lock:

```php
// WRONG - a reader that discovers it must write
#[Ajax_Endpoint]
public static function claim(Request $request, array $params = [])
{
    $slot = Slot_Model::find($params['id']);     // read under the READ lock
    if ($slot->claimed_by === null) {
        Site_Lock::write(Session::get_site_id(), fn () => ...);   // FATAL: no upgrade
    }
}

// RIGHT - the endpoint is a writer; the read is inside the write lock
#[Ajax_Endpoint]
#[Writer]
public static function claim(Request $request, array $params = [])
{
    $slot = Slot_Model::find($params['id']);
    if ($slot->claimed_by !== null) {
        return response_error(Ajax::ERROR_VALIDATION, 'Already claimed');
    }
    $slot->claimed_by = Session::get_user_id();
    $slot->save();                               // nobody claimed it in between

    return ['claimed' => true];
}
```

**Nesting is counted**: a `Site_Lock::write()` inside a writer (or a write section) just runs - no second lock, no second transaction. A `Site_Lock::read()` inside a write is free. A writer endpoint called through `Ajax::internal()` from a writer runs inside the outer transaction; from a READER it fatals.

## 3. Tasks, commands, scripts, hooks: one section per item

No implicit lock - and `#[Writer]` / `#[Reader]` / `#[Manual_Lock]` there fail the build (`SITE-LOCK-01`). They manage their own:

```php
#[Task('Send due invoices')]
public static function send_due(Task_Instance $task, array $params = [])
{
    $site_id = (int) $params['site_id'];
    Session::set_temporary_site_id($site_id);
    try {
        // List unlocked; the list may be stale by the time each item runs.
        $ids = Invoice_Model::where('status_id', Invoice_Model::STATUS_DUE)->pluck('id');

        foreach ($ids as $id) {
            $task->heartbeat();
            if ($task->is_stop_requested()) {
                break;
            }
            Site_Lock::write($site_id, function () use ($id) {
                $invoice = Invoice_Model::find($id);       // RE-READ inside the section
                if ($invoice === null || $invoice->status_id !== Invoice_Model::STATUS_DUE) {
                    return;                                 // changed or gone since the list
                }
                $invoice->status_id = Invoice_Model::STATUS_SENT;
                $invoice->save();
            }, "Send invoice {$id}");                       // the revision unit's description
        }
    } finally {
        Session::clear_temporary_site_id();
    }
}
```

- **One section per item, never one around the run** - a section holds the site against every web writer until it commits.
- Each section commits its own transaction and is its own revision unit.
- **Change site only between sections.** One site's lock at a time per process.
- The same shape applies to an artisan command's `handle()`, a `system/script.php` script, a `#[Run_As_Dispatcher]` task, and `init()` / `pre_dispatch()` / `unhandled_route()`.
- **A hook hands over clean**: a lock it still holds or a transaction it left open is released / rolled back and the request fails naming it.

**`Site_Lock::bypass($site_id, '<reason>', fn () => ...)`** - writes permitted WITHOUT the lock, no transaction opened, reason logged. For a pass that tolerates concurrent writers and must not hold the site: a queue drain, a retention sweep, a per-request "last activity" stamp from a hook. **`bypass_all_sites('<reason>', fn)`** for a pass that walks every tenant outside the site scope. A bypass gives up the guarantee for what it writes - never use one for a read-then-write.

**A child process** (`Rsx_Artisan` only) is its own enforcement window: it takes its own `Site_Lock::write()`; the lock group grants it instantly while the parent holds the site. It cannot see the parent's uncommitted writes.

## 4. Signing in and switching site

The site never changes inside a request handler or under a lock. The bare setters (`Session::set_site_id()`, `set_temporary_site_id()`, `clear_temporary_site_id()`, `impersonate()`, `reset_impersonation()`, `Portal_Session::set_site_id()`) throw there. **`RsxAuth::enter_site($site_id)`** is the one way: no lock held -> that site's write section -> session site set -> the sign-in's site-scoped records -> commit -> release.

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
#[Manual_Lock]                     // no automatic lock: enter_site() takes the target site's
public static function select_site(Request $request, array $params = [])
{
    // ... check the membership ...
    RsxAuth::enter_site((int) $params['site_id']);

    return ['redirect' => Rsx::Route('Dashboard_Index_Action')];
}
```

- A **page route** that signs in declares no lock mode and calls `enter_site()` directly.
- `Main::init()` may set the site (no lock is held there).
- Every staff sign-in in the template finishes in `Login_Controller::complete_sign_in()`; the portal's site comes from its URL, so a portal sign-in wraps its writes in `Site_Lock::write(Portal_Session::get_site_id(), ...)` and never switches site.
- `Rsx_Initial_User::create()` runs the `user.initial.created` handlers inside one write section on the founding site: a handler's writes to that site need no lock of their own.

## 5. `#[Manual_Lock]`

No automatic lock. The handler's first raw `RsxLocks::site_write_lock()` begins a **request transaction**, committed when the handler returns (rolled back on a throw) **even if the lock was released earlier** - which is logged (`Site lock released early: ...`) because the open transaction's row locks still block other writers and rule 1 refuses another site lock until the handler returns. Prefer `Site_Lock::write()` sections (and `enter_site()`) inside a manual handler: each commits at its own end. A manual request that took a write lock is a writer request (its `_transactions` row, its request history).

## 6. `#[Site_Lock_Exempt('<reason>')]` - bookkeeping only

On a `Site_Scoped` model class: its writes are never checked.

- **Only analytics or session bookkeeping** - a record of what happened that no user edits, typically written on every request (a "last viewed" column, a view counter).
- **Never business data. Never a table whose values are READ to decide a value written into business data** - it is written concurrently with everything, so a decision taken from it is a race.
- When in doubt it is business data: use `#[Writer]`, or a reasoned `bypass()` at the one call site.

No framework model declares it; the framework's bookkeeping (sessions, sign-in history, `_transactions`, `_tasks`, the API log) is not site-scoped models.

**Commands**: `const SITE_LOCK_EXEMPT = '<reason>'` is for provisioning only - migrations, seeders, the test runner, the database reset. Never a command that edits tenant data.

## 7. Site 0 and the limits

- **Site 0 is a site like any other**: a request with no tenant holds `SITE_0`'s lock, and a site-0 row needs site 0's write lock (an app may run sessionless with its data in `site_id = 0`).
- **Raw writes are not seen**: `DB::table()`, `DB::statement()`, `->raw_bulk()` bypass the enforcement. Keep them inside the same section as the model writes they belong with.
- **Never `Site_Lock::write()` inside `DB::transaction()`** (rule 1) - the section opens the transaction.
- **`Form_Edit::run()` inside a writer** nests as a savepoint; its "never inside your own transaction" warning does not apply there.
- Tests: fixture code is unchecked (`rsx:test` is exempt); every endpoint, task and command a test drives takes its real locks.

## 8. Troubleshooting (literal text)

- **`Site lock enforcement: Client_Model save on site 3 by Ajax Clients_Controller::save without site 3's write lock.`** - the surface is a reader (or a task/command/hook with no section). Endpoint: `#[Writer]`. Elsewhere: `Site_Lock::write(3, fn () => ...)` per item, re-reading inside; or a reasoned `bypass()`.
- **`Refused to take the WRITE lock on site 3 while holding its READ lock`** - no upgrade. Make the endpoint `#[Writer]` (section 2), or move the read inside one `Site_Lock::write()`. Also: a `#[Writer]` called via `Ajax::internal()` from a reader.
- **`Site lock rule 1: refused to acquire the WRITE lock on site 3 inside 1 open database transaction(s) the lock layer did not open.`** - lock first; `Site_Lock::write()` opens the transaction. In `#[Manual_Lock]`: a write lock released early leaves the request transaction open.
- **`Site lock rule 5: refused to lock site 4 while holding the lock on site 3`** - finish site 3's section first.
- **`Session::set_site_id() refused inside Ajax X::y: the framework manages a request's site`** - `#[Manual_Lock]` + `RsxAuth::enter_site()`.
- **`RsxAuth::enter_site(4) refused: this process holds the READ lock on site 3`** - the endpoint still has an automatic lock: declare `#[Manual_Lock]`.
- **`<hook> returned still holding the ... and/or with N database transaction(s) still open`** - a hook must release what it takes; `Site_Lock::write()` does both.
- **`Invalid site lock mode: ... SITE-LOCK-01: declares #[Writer] on a #[Task] method.`** - attributes belong on Ajax/API/MVC-route/companion surfaces only.
- **`SITE-LOCK-01: declares #[Reader] on #[Ajax_Endpoint], which is already a reader by default.`** - remove it.
- **`... gives no reason`** (`#[Site_Lock_Exempt]`, `the SITE_LOCK_EXEMPT constant`, `Site_Lock::bypass()`) - give one; it is logged / read by the next developer.
- **`Site lock held too long: WRITE lock on site 7 held by ... for 3.412s (waited ...)`** in the log - `rsx.locks.warn_write_hold_seconds` / `warn_read_hold_seconds` (default 2, 0 disables). Move the slow work to a task. "waited" large and "held" small = a slow queue, not a slow holder.
- **Everything waits, nothing errors** - a slow writer holds the site. `node system/bin/rsx-lockd/lockd.js dump` names the holder (`rspade:locks-and-subprocesses`).

Related: `rsx:man site_locks`, `rsx:man request_history` (`_request_ref` replay on writers), skills `rspade:background-tasks`, `rspade:form-engine`, `rspade:session-auth`.
