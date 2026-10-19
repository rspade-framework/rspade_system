---
id: unbounded_queries
title: Every multi-row read has a ceiling or iterates
severity: high
when: launch, change, periodic
scope: rsx/**/*.php
related: rsx:man model, skill rspade:bounded-result-sets, rsx:man tasks
---

## The contract

Every database read in application code that can return more than one row has a known maximum and something that enforces it - or it iterates the whole set a page at a time. "However many rows the table holds" is a defect however small the table is today: a session-list read that fetched every row and sliced to 20 in PHP hydrated 62,617 rows, exhausted the PHP process and took the php-fpm pool down with it. A read is only as bounded as the writes behind it, so tables that grow per request, login, import or tick need a retention policy too. And a function that promises "these records" returns all of them - a LIMIT that silently truncates is the same bug in the other direction.

**What this audit is about: sets that grow with use.** The reads that need a ceiling are the ones whose size is decided by the data and by user input - "the list of contacts", "the history of changes to this record", "this client's projects", a search result, an activity feed. Every such list served to a web request (a page, an Ajax endpoint, an API endpoint, a DataGrid) pages through the set; it never returns "all of them" because today's table is small. A set that is CONSTRAINED BY WHAT IT IS needs no pagination and is not a finding: the possible statuses of a record, an enum's options, a record's handful of configured settings, the fixed roles or permissions - it returns several rows, but nothing a user does makes it grow. Developer tooling is out of scope (`db:query` prints at most 25 rows by design).

Read `php artisan rsx:man model` (RESULT SETS, `$unbounded`) and skill `rspade:bounded-result-sets` first.

## Find every instance

1. Reads that materialize a set (multi-line chains need the surrounding lines read; `->get(` with arguments is included):

       grep -rnE -e '->get\(' -e '::all\(\)' -e '->pluck\(' -e '->cursor\(' rsx --include='*.php' | grep -v '^rsx/resource/' | grep -v '^rsx/tests/'

   Also read any collection that is then cut down in PHP: `array_slice`, `->take(`, `->slice(`, a counter in a `foreach`.

2. Models and their growth declaration:

       grep -L 'unbounded' rsx/models/*.php
       grep -l 'public static $unbounded = true' rsx/models/*.php

3. Writers: every table the application inserts into per request, per login, per import or per scheduled run (`->save()` on a new model in a controller, handler, task or service), and the retention task (`#[Task]` + `#[Schedule('maintenance')]`) that deletes its old rows - including session-scoped tables of your own.

4. Optional empirical check: `[DB-UNBOUNDED]` warnings in the application log (`storage/logs/laravel.log`, or under `RSX_STORAGE_PATH`). They fire in development and debug modes only, only from `->get()`, and only when a result exceeds `rsx.database.result_set_warn_threshold` (default 1000; 0 disables) - `pluck()`, `all()` and smaller results never warn.

## What does not conform

Assign each read one shape; a read that fits none is a finding.

1. Single row - `first()` / `find()`.
2. True limit - the caller genuinely wants the N most recent/top, the `->limit()` is in the SQL, and the reason is stated at the call site when it is not obvious.
3. Iteration - `->result_set()` (an `Rsx_Result_Set`: foreach reaches every record, one keyset page resident), or `chunkById()` / `lazyById()`. `result_set()` walks by primary key and OVERRIDES any `orderBy`, so an ordered "N most recent" list keeps the ordered, limited query instead.
4. Constrained by what it is - the row count is fixed by the codebase, a standard or the record's own shape, and no user activity grows it (a record's possible statuses, enum options, countries, roles, permissions, a record's configured settings).
5. Paginated list - a set that grows with use, served to a web request one page at a time (`->limit()`/`->offset()` or a keyset cursor from the request, a DataGrid, an API page with a next cursor), with a maximum page size enforced on the server whatever the request asks for.
6. Enforced ceiling - something else caps it; name where. A convention is not a ceiling.

Findings:

- A list that grows with use - contacts, a record's change history, notes, files, an activity feed, search results - returned whole to a page, Ajax endpoint or API endpoint instead of a page at a time, or paged with a page size the request controls without a server-side maximum.
- Fetch-then-slice: `->get()` then `array_slice` / `->take()` / a counted `foreach`.
- A `->limit()` that silently truncates a set the caller needs whole (an export, a "process all") - it becomes iteration, or fails loud naming the cap.
- A large id set plucked into PHP to feed `whereIn()` - pass the builder as a sub-query.
- A bounded reader whose `ORDER BY` column has no supporting index (a LIMIT bounds memory, not the scan).
- A model whose table grows with customer activity (logs, audit trails, events, messages, notifications, queues, uploads, core business tables) without `public static $unbounded = true;`. Do NOT ask for it on human-authored low-volume tables, fixtures, join tables always narrowed to one parent, or 1:1 detail tables. The test: could a reasonable query here return thousands?
- A per-activity table with no retention policy and no scheduled task applying it.

Nothing enforces any of this in application code: `DB-UNBOUNDED-01` reads framework code only and the runtime tripwire only warns.

## Report

`PASS - <one line: N reads classified, M growing tables have retention>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
