---
id: site_lock_hold_discipline
title: Every site lock is held for a moment, and only as strong as the work needs
severity: high
when: launch, change
scope: rsx/**/*_controller.php, rsx/**/*_Controller.php, rsx/services/**, rsx/handlers/**, rsx/companions/**, rsx/lib/**, rsx/main.php, rsx/portal_main.php
related: rsx:man site_locks, rsx:man tasks, rsx:man external_api, rsx:man file_upload, skill rspade:site-locks, skill rspade:background-tasks
---

## The contract

A site's write lock stops every other writer on that site, and every reader queued behind it. So a write lock is held for a MOMENT and only around writing; a read lock is let go before anything is transmitted; a view, audit or telemetry record takes no lock at all; and an error answer that is meant to undo the request undoes it. Nothing reports a violation: a writer that holds the site across an HTTP call works perfectly in development and serializes the whole tenant in production, and a writer that returns an error after writing half a batch silently keeps the half.

Read `php artisan rsx:man site_locks` first - HOLDING A LOCK WELL is this audit's rulebook (its seven rules), with THE ARCHITECTURE PRINCIPLE and THE REQUEST BOUNDARY.

Already enforced, not re-checked here: a site-scoped write without write authority fatals; the read-to-write upgrade is refused; a hook, endpoint, task or command that leaks a lock, a transaction or a write exception fails; `SITE-LOCK-01` keeps the mode attributes on surfaces. A hold past `rsx.locks.warn_write_hold_seconds` is logged after the fact - this audit finds the ones that WILL be, from the algorithm. Do not run anything; estimate from the code and from how much data a real tenant has.

## Find every instance

    php artisan rsx:site_lock:endpoints --app --mode=writer
    php artisan rsx:site_lock:endpoints --app --mode=manual
    grep -rn "Site_Lock::write(\|Site_Lock::read(\|RsxLocks::site_write_lock\|Site_Lock::bypass" rsx
    grep -rln "#\[Task" rsx/services
    grep -rn "response_error\|response_form_error\|Rsx_Api::" rsx --include=*.php

For each `#[Writer]` endpoint, each write section and each `#[Manual_Lock]` handler, read the body to the end, following every helper, model method and service it calls while the lock is held.

## What does not conform

- **A write lock held across a call to another system**: an HTTP client (Guzzle, an SDK - Graph, Stripe, a storage provider), a token refresh, a mail or SMS send that is not queued, `Rsx_Artisan::run()` or any subprocess it waits for, a `sleep`, a remote file fetch (`resolve_storage()` on an externally held attachment). The shape it should have: `#[Manual_Lock]`; read what the call needs in a `Site_Lock::read()` section; call with no lock; write the result in a short `Site_Lock::write()`. A value the call made authoritative (a rotated refresh token, an id the provider assigned) is written in its OWN section immediately after the call - flag one written in a section that a later failure can roll back.
- **A write lock held across a long loop or a large read**: a pass over every row of a table that grows with the tenant, an aggregation, building a file, encoding or compression. It is a task (THE ARCHITECTURE PRINCIPLE), or its reading moves to a read section and its writing to one short section per item.
- **A writer only because it records something**: an endpoint whose only site-scoped writes are a view record, an audit sentence, an "exported" / "downloaded" action-log row, a recent-views upsert, a last-activity stamp. It should be a reader recording under `Site_Lock::write_exception()`. Check the exclusions before recommending it: if the row is READ to decide a later write (a once-only notice ledger, a "first time" flag) or is business data, it stays a writer (or the decision moves to a task / a unique key) - say which.
- **A bypass doing a write exception's job**: `Site_Lock::bypass()` around a view, audit or telemetry record (a bypass is for a pass that tolerates concurrent writers; it logs every call).
- **A read that is then transmitted under the lock**: an export, a download, a ZIP, a PDF, a CSV built and returned from a writer or a reader that holds its lock while the bytes are produced. The surface declares `#[Manual_Lock]`, reads inside `Site_Lock::read()`, ends it, then produces and returns the response (a `StreamedResponse` callback already runs after the release - flag one that builds the whole payload before returning instead).
- **An error answered after writing, where the intent was to undo**: a `#[Writer]` (or a write section) that writes, then `return response_error(...)` / `response_form_error(...)` / `Rsx_Api::...` on a later failure - typically inside a loop or after a `catch`. Unless keeping the partial work is the stated intent (a failed attempt that records itself), it should `throw new Rollback_Response(<that answer>)`.
- **A write section around a whole task run** instead of one per item that re-reads its record inside; a section around a `Task::internal()` call or a nested loop.
- **A hold that would plausibly trip `rsx.locks.warn_write_hold_seconds`** (default 2 s) with real data, for any reason above or another - name the dominant cost.

For each finding report: the surface (file and method), why it holds the lock longer than a moment (the call, loop or payload responsible), and the finer-grained shape - which part needs no lock, which a read section, which a short write section, which a write exception, which a task, and whether the error path should be a `Rollback_Response`.
