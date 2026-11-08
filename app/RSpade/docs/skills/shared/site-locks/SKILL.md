---
name: site-locks
description: "Writing code under the per-site reader/writer locks - #[Writer] / #[Reader] / #[Manual_Lock] on Ajax, API, companion and route surfaces, Site_Lock::write() / read() / bypass() / bypass_all_sites() sections in tasks, commands, scripts and hooks, Site_Lock::write_exception() for a view / audit / telemetry record, Site_Lock::held_mode() / in_write_exception() / in_bypass(), Rollback_Response, read-then-write, holding a lock well (one discrete operation per write section with a status marker between them, no network call under a write lock, read-release-transmit, an export paged one read section per keyset page, a mass edit / import / reassignment releasing the lock per item, block or chunked statement and reporting errors at the end, a dry run before an important bulk operation, reversible steps when atomicity is critical), RsxAuth::enter_site(), #[Site_Lock_Exempt], the SITE_LOCK_EXEMPT constant, rsx:site_lock:endpoints. Use when an endpoint, task or command saves a site-scoped model, a writer does more than one update, a bulk / selection action (delete selected, select all, mark all read), an import, a reassignment or an export loops over records, a reader needs to record something, a writer calls an external API or streams, an error answer must undo the write, converting code to the locks, signing in or switching site, or on 'Site lock enforcement: ... without site N's write lock', 'Refused to take the WRITE lock on site N while holding its READ lock', 'Site lock rule 1', 'Site lock rule 5', 'refused inside Ajax', 'Site lock held too long', 'Site_Lock write exception(s) still open' or SITE-LOCK-01."
---

# Site Locks

Every site has ONE readers-writer lock. Readers run together; a writer runs **alone** on the site inside one transaction. Contract: `rsx:man site_locks`. The primitive underneath: skill `rspade:locks-and-subprocesses`.

**The guarantee you design against**: inside a `#[Writer]` endpoint or a `Site_Lock::write()` section nothing else changes the site. Read a record, decide, write - no re-check, no optimistic retry, no lock of your own. A reader sees data that is not changing while it reads. This is a contract, not an incidental detail: write code that relies on it.

**The enforcement**: inside an endpoint, route, hook, task, command or script, a `save()` / `delete()` / bulk write of a `Site_Scoped` model fatals unless the process holds that site's write lock, is in a bypass, or has a write exception open. Nothing acquires a lock because a write happened.

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
- **What commits**: a writer that RETURNS commits - including a returned `response_form_error()` / `response_form_question()` / API error response, so **validate before the first write**. A THROW rolls the whole request back. **To answer an error AND undo** (a batch that fails part-way): `throw new Rollback_Response(response_form_error(...))` (API: `Rollback_Response(Rsx_Api::validation_error(...))`) - rolled back, then rendered exactly as the returned value (section 9).
- **A `#[Writer]` GET `#[Api_Endpoint]`** is refused by `API-GET-PURE-01`.
- `Task::dispatch()` from a writer: the run starts after the commit, or never (rollback).
- **`#[Writer]` is for ONE discrete operation** - a save, a status change, one business operation on one record. A write lock stops every other user on the site, so code that does more is engineered to be as little invasive as it can be: each discrete operation in its own short `Site_Lock::write()` section under `#[Manual_Lock]`, designed for the gaps between them (section 8, rule 1).
- **Return right away.** Every request holds its site's lock for its whole handler; a slow reader holds back every writer and every reader queued behind it. Anything whose cost grows with the tenant's data - an export or report over tens of thousands of records, an import, a reassignment, a mass edit: validate, `Task::dispatch()`, return the run's handle; the browser follows it with `Rsx_Task.watch()` (realtime) and fetches the result from the feature's own endpoint, so the UI never freezes.

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

- **The main user-facing case is the BULK WRITE** - a mass edit (an action a user applies to a selection: delete the ticked rows, "select all" then approve, mark all read), a mass import, a mass reassignment. It is this shape as a `#[Run_As_Dispatcher]` task, never a `#[Writer]` looping over the selection: section 8, rules 8-9.
- **One section per item, never one around the run** - a section holds the site against every web writer until it commits.
- Each section commits its own transaction and is its own revision unit.
- **Change site only between sections.** One site's lock at a time per process.
- The same shape applies to an artisan command's `handle()`, a `system/script.php` script, a `#[Run_As_Dispatcher]` task, and `init()` / `pre_dispatch()` / `unhandled_route()`.
- **A hook hands over clean**: a lock it still holds or a transaction it left open is released / rolled back and the request fails naming it.

**`Site_Lock::bypass($site_id, '<reason>', fn () => ...)`** - writes permitted WITHOUT the lock, no transaction opened, reason logged. For a pass that tolerates concurrent writers and must not hold the site: a queue drain, a retention sweep. (A per-request "last activity" stamp is telemetry: a write exception, section 9.) **`bypass_all_sites('<reason>', fn)`** for a pass that walks every tenant outside the site scope. A bypass gives up the guarantee for what it writes - never use one for a read-then-write.

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

## 6. `#[Site_Lock_Exempt('<reason>')]` - view, audit and telemetry tables only

On a `Site_Scoped` model class: its writes are never checked.

- **Only append-only view, audit and telemetry records** - what happened, which no user edits, typically written on every request (a document-view log, an audit trail, a download history, a view counter).
- **Never business data. Never a table whose values are READ to decide a value written into business data** - it is written concurrently with everything, so a decision taken from it is a race.
- The attribute is for a table that is ONLY ever such a record. A telemetry write into a table that also holds business data (or whose classification depends on the caller) takes `Site_Lock::write_exception()` at the call site (section 9). When in doubt it is business data: `#[Writer]`.

No framework model declares it; the framework's bookkeeping (sessions, sign-in history, `_transactions`, `_tasks`, the API log) is not site-scoped models.

**Commands**: `const SITE_LOCK_EXEMPT = '<reason>'` is for provisioning only - migrations, seeders, the test runner, the database reset. Never a command that edits tenant data.

## 7. Site 0 and the limits

- **Site 0 is a site like any other**: a request with no tenant holds `SITE_0`'s lock, and a site-0 row needs site 0's write lock (an app may run sessionless with its data in `site_id = 0`).
- **Raw writes are not seen**: `DB::table()`, `DB::statement()`, `->raw_bulk()` bypass the enforcement. Keep them inside the same section as the model writes they belong with.
- **Never `Site_Lock::write()` inside `DB::transaction()`** (rule 1) - the section opens the transaction.
- **`Form_Edit::run()` inside a writer** nests as a savepoint; its "never inside your own transaction" warning does not apply there.
- Tests: fixture code is unchecked (`rsx:test` is exempt); every endpoint, task and command a test drives takes its real locks.

## 8. Holding a lock well

A write lock stops every other user on the site, so code that writes is **engineered to be as little invasive as it can be**. The ten rules (`rsx:man site_locks`, HOLDING A LOCK WELL; audit `rsx:conformance:show site_lock_hold_discipline`):

1. **A write lock is held for ONE discrete operation, for a moment.** More than a simple update -> split into discrete operations, each its own short `Site_Lock::write()` section (`#[Manual_Lock]`), reading in read sections or none. Splitting gives up atomicity and the no-re-check guarantee BETWEEN sections, so: (a) steps a business rule requires together share ONE section; (b) each section re-reads and re-decides; (c) the data is valid between sections - a status / step marker on the record says how far it got, so a failure leaves something a person can see and a re-run can finish.
2. **Never across a call to another system** (HTTP API, mail relay, a subprocess that waits). `#[Manual_Lock]`, make the call with no lock, then a short `Site_Lock::write()` with what it returned. A value the call made authoritative (a rotated OAuth refresh token) gets its OWN section immediately, so no later failure rolls it back.
3. **A view / audit / telemetry record uses a write exception** (section 9) or an exempt model - never a writer.
4. **Release before you transmit.** Read inside `Site_Lock::read()` (`#[Manual_Lock]` surface), end it, then stream. Every response is SENT after the request lock is released; an Ajax value is also serialized after it, an API body is built inside it. A `StreamedResponse` callback runs at `send()` - no lock, no enforcement window.
5. **Export- and data-processing-shaped work is a task**, and **a long read is paged, one `Site_Lock::read()` section per page** - an export need not be one consistent read across the run. Page by KEYSET (`where('id', '>', $last)->orderBy('id')->limit(n)`), never OFFSET (rows written between pages shift it: skipped or repeated rows). Each page is consistent; the whole is not a snapshot - one that must reconcile is rule 10.
6. **Read-then-write is a writer from its start** (section 2), unless the write is telemetry.
7. **Returning an error commits; throw `Rollback_Response` to undo.**
8. **A bulk write - mass edit, mass import, mass reassignment - releases the write lock per item** (one `Site_Lock::write()` per record, re-reading it inside), **per small block** where per-item overhead dominates and a block is still a moment, **or per chunked statement** - never one lock across the selection. So: (a) it is NOT all-or-nothing - each item commits, a failing item does not stop the run, its error is COLLECTED and the run reports at its END applied / skipped / refused / failed per item with the reason; something that must be atomic across records is a single business operation, small enough for a moment's lock, or rule 10; (b) more than a handful of items is a `#[Run_As_Dispatcher]` + `#[Task_Access('dispatcher')]` task - the endpoint validates, dispatches, returns the handle, the UI follows the run; (c) each item goes through the record's own writer (model, hooks, action log); (d) each section re-reads and re-decides - changed, vanished or no-longer-permitted items are skipped and reported; (e) a set-based statement is chunked, a section per chunk, followed by `Realtime::bulk_changed()` in that section (a raw write re-runs no `#[Emitter]`).
9. **An important bulk operation shows a dry run first** (hard to undo or consequential: an import that creates or overwrites, a reassignment of ownership, a mass delete, money). The same task with a dry-run flag decides every item exactly as the real run would, writes nothing (read sections only), and reports will apply / skip / refuse and why; the person confirms; the real run is a NEW dispatch that RE-DECIDES every item - the dry run is a forecast, never a plan to replay.
10. **One long write lock only when atomicity is critical** (a posting that must balance, a renumbering never seen half-done). Then either (a) **make it fast** - read, validate, compute and render BEFORE the lock; inside, only the writes, set-based where possible; or (b) **split it into reversible steps**, each its own section, undoing the completed steps (newest first, each in its own section) when a later one fails - each undo re-reads current state (others may have acted on the intermediate state), reverses only what is still the operation's own, and reports what it could not. Never a long lock because splitting was inconvenient.

```php
// Rule 1: a multi-step writer, split into discrete operations with a status marker between them.
#[Ajax_Endpoint]
#[Auth('can_approve_orders')]
#[Manual_Lock]
public static function approve(Request $request, array $params = [])
{
    $site_id = Session::get_site_id();
    $answer = Site_Lock::write($site_id, function () use ($params) {
        $order = Order_Model::find($params['id']);
        if ($order->status_id !== Order_Model::STATUS_PENDING) {
            return response_error(Ajax::ERROR_VALIDATION, 'Already decided');
        }
        $order->status_id = Order_Model::STATUS_APPROVED;      // the marker: how far it got
        $order->save();
    }, 'Approve order');
    if ($answer !== null) {
        return $answer;
    }

    $pdf = Invoice_Pdf::render($params['id']);                 // slow, reads only: no lock

    Site_Lock::write($site_id, function () use ($params, $pdf) {
        $order = Order_Model::find($params['id']);             // re-read, re-decide
        if ($order->status_id !== Order_Model::STATUS_APPROVED) {
            return;
        }
        $order->attach_invoice($pdf);
        $order->status_id = Order_Model::STATUS_INVOICED;
        $order->save();
        (new Order_Invoice_Email($order))->to($order->contact)->send();   // queued: a write
    }, 'Attach invoice');

    return ['ok' => true];
}
```

```php
// Rule 5: an export task reads one keyset page per read section; the lock is released between pages.
$last_id = 0;
do {
    $rows = Site_Lock::read($site_id, fn () => Invoice_Model::where('id', '>', $last_id)
        ->orderBy('id')->limit(1000)->get());
    foreach ($rows as $row) {
        $writer->insertOne($row->export_row());                 // no lock held
    }
    $last_id = $rows->last()?->id ?? $last_id;
    $task->heartbeat();
} while ($rows->count() === 1000 && !$task->is_stop_requested());
```

```php
// A writer that calls a provider: no lock across the call, the rotated token committed at once.
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
#[Manual_Lock]
public static function sync_drive(Request $request, array $params = [])
{
    $site_id = Session::get_site_id();
    $account = Site_Lock::read($site_id, fn () => Drive_Account_Model::find($params['id']));

    $result = Drive_Client::fetch($account);                 // no lock held
    Site_Lock::write($site_id, fn () => Drive_Account_Model::store_token($account->id, $result->token));

    Site_Lock::write($site_id, fn () => Drive_Folder_Model::apply($account->id, $result->folders));

    return ['folders' => count($result->folders)];
}
```

```php
// Rule 8, set-based: one section per chunk, re-reading what still qualifies. A handful of
// chunks (one user's unread notifications) may stay a #[Manual_Lock] endpoint; a set that
// grows with the tenant is a task.
do {
    $found = Site_Lock::write($site_id, function () use ($user_id) {
        $ids = Notification_Model::where('user_id', $user_id)->whereNull('read_at')
            ->orderBy('id')->limit(500)->pluck('id')->all();
        if ($ids) {
            Notification_Model::whereIn('id', $ids)->whereNull('read_at')->raw_bulk()->update(['read_at' => now()]);
            Realtime::bulk_changed();
        }
        return count($ids);
    }, 'Mark notifications read');
} while ($found === 500);
```

The per-item shape is section 3 run as a `#[Run_As_Dispatcher]` task; the reference app's worked example is `rsx/services/mass_edit_service.php` (the datagrid mass deletes: endpoint validates and dispatches, task reads the selection once, one section per record re-checking it is still selected and the person may still edit, per-item stdout, `{selected, deleted, skipped, refused}` state, a summary) with `Mass_Edit_Progress_Modal` following the run in the browser. Skill `rspade:background-tasks` has the reporting side.

A client library that must never run under a write lock asks `Site_Lock::held_mode() === 'write'` and refuses. `held_mode(?int $site_id = null)` is `'read'` / `'write'` / `null` (no site = THE site this process holds a lock on); `in_write_exception()`, `in_bypass(?int $site_id = null)`.

## 9. Write exceptions and `Rollback_Response`

```php
// A reader that records - it stays a reader.
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function open_document(Request $request, array $params = [])
{
    $doc = Document_Model::find($params['id']);
    Site_Lock::write_exception('record the document view', fn () => Document_View_Model::record($doc));

    return $doc->for_viewer();
}

// The token form, for an acquire and a release in different places - release in a finally.
$token = Site_Lock::acquire_write_exception('record the download');
try { ... } finally { Site_Lock::release_write_exception($token); }
```

- **A state, not a lock**: nothing in rsx-lockd, blocks nobody. Valid anywhere: a reader, a writer, a section, a `#[Manual_Lock]` handler holding nothing, a hook, a task, a command, a `file.*.authorize` handler.
- **Inside a writer / write section** the record rides the transaction - a later throw rolls it back. **Anywhere else** it commits at once (autocommit) and survives a later throw.
- Counted (nested; released in any order); reason mandatory (DEBUG log only); the site never changes while one is open; it is NOT a lock, so `Site_Lock::write()` inside a reader's exception is still an upgrade and refused.
- An endpoint / hook / task / command that ends with one open: released, and it FAILS naming it.
- **Not for business data, nor a row read to decide a later write** (a "notify the first time" ledger: two concurrent opens both see "not yet" and both send) - that is a task, or a unique key. Whatever the record triggers that writes business data goes to `Task::dispatch()`.

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
#[Writer]
public static function import_rows(Request $request, array $params = [])
{
    foreach ($params['rows'] as $index => $row) {
        $problem = Import_Row::problem($row);
        if ($problem !== null) {
            // Rolls back every row written so far; the client gets the ordinary validation envelope.
            throw new Rollback_Response(response_form_error("Row {$index}: {$problem}"));
        }
        Import_Row::save($row);
    }

    return ['imported' => count($params['rows'])];
}
```

`Rollback_Response` (`App\RSpade\Core\Response`) carries the value the handler would have returned - a coded result for Ajax, an `Rsx_Api::*` response for the API and companion endpoints, either for a page route. Rolled back, released, `rsx.post_dispatch` not fired, rendered exactly as returned (same `_duration_ms` / `_completed_at`), nothing recorded in the request history (the `_request_ref` stays reusable). Through `Ajax::internal()` it surfaces as the coded exception (`AjaxFormErrorException`...).

## 10. Troubleshooting (literal text)

- **`Site lock enforcement: Client_Model save on site 3 by Ajax Clients_Controller::save without site 3's write lock.`** - the surface is a reader (or a task/command/hook with no section). Endpoint: `#[Writer]`. Elsewhere: `Site_Lock::write(3, fn () => ...)` per item, re-reading inside; or a reasoned `bypass()`.
- **`Refused to take the WRITE lock on site 3 while holding its READ lock`** - no upgrade. Make the endpoint `#[Writer]` (section 2), or move the read inside one `Site_Lock::write()`. Also: a `#[Writer]` called via `Ajax::internal()` from a reader, or a gate handler opening a section inside a reader / file route. Only RECORDING something? `Site_Lock::write_exception()`.
- **`<surface> ended with 1 Site_Lock write exception(s) still open ('...'). They have been released.`** - release the token in a finally, or use the scoped form.
- **`... refused inside a Site_Lock write exception`** - the site never changes while one is open.
- **`Site lock rule 1: refused to acquire the WRITE lock on site 3 inside 1 open database transaction(s) the lock layer did not open.`** - lock first; `Site_Lock::write()` opens the transaction. In `#[Manual_Lock]`: a write lock released early leaves the request transaction open.
- **`Site lock rule 5: refused to lock site 4 while holding the lock on site 3`** - finish site 3's section first.
- **`Session::set_site_id() refused inside Ajax X::y: the framework manages a request's site`** - `#[Manual_Lock]` + `RsxAuth::enter_site()`.
- **`RsxAuth::enter_site(4) refused: this process holds the READ lock on site 3`** - the endpoint still has an automatic lock: declare `#[Manual_Lock]`.
- **`<hook> returned still holding the ... and/or with N database transaction(s) still open`** - a hook must release what it takes; `Site_Lock::write()` does both.
- **`Invalid site lock mode: ... SITE-LOCK-01: declares #[Writer] on a #[Task] method.`** - attributes belong on Ajax/API/MVC-route/companion surfaces only.
- **`SITE-LOCK-01: declares #[Reader] on #[Ajax_Endpoint], which is already a reader by default.`** - remove it.
- **`... gives no reason`** / **`requires a reason`** (`#[Site_Lock_Exempt]`, `the SITE_LOCK_EXEMPT constant`, `Site_Lock::bypass()`, `Site_Lock::write_exception()`) - give one; it is logged / read by the next developer.
- **`Site lock held too long: WRITE lock on site 7 held by ... for 3.412s (waited ...)`** in the log - `rsx.locks.warn_write_hold_seconds` / `warn_read_hold_seconds` (default 2, 0 disables). Move the slow work to a task, or split the hold (section 8). "waited" large and "held" small = a slow queue, not a slow holder.
- **Everything waits, nothing errors** - a slow writer holds the site. `node system/bin/rsx-lockd/lockd.js dump` names the holder (`rspade:locks-and-subprocesses`).

Related: `rsx:man site_locks`, `rsx:man request_history` (`_request_ref` replay on writers), skills `rspade:background-tasks`, `rspade:form-engine`, `rspade:session-auth`.
