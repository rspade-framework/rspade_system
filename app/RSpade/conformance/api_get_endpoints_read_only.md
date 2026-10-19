---
id: api_get_endpoints_read_only
title: External API GET endpoints write nothing, helpers included
severity: high
when: launch, change
scope: rsx/app/api/**, rsx/**/*api*.php
related: rsx:man external_api, skill rspade:external-api
---

## The contract

Every `#[Api_Endpoint]` answering GET is a pure read - and so is everything it calls. An API key may be minted read-only (`_api_keys.read_only`), and the dispatcher refuses its every non-GET request with 403 `read_only_key`; that is how an operator hands a reporting job, a dashboard or a third-party tool a credential promised to change nothing. One GET that mutates hollows out every read-only key ever issued, silently.

Read `php artisan rsx:man external_api` (GET IS FOR READS, POST IS FOR WRITES; READ-ONLY KEYS) first.

Already enforced, not re-checked: `API-GET-PURE-01` fails the manifest build when a GET handler's OWN BODY contains a write shape. It matches about 45 call shapes - the `GET_MUTATION_TOKENS` list in `system/app/RSpade/Core/Api/Api_Endpoint_ManifestSupport.php`; read it so you know what is already caught - case-insensitively over tokens (comments and strings neither trigger nor evade it). It also fails a handler whose body it cannot locate, an endpoint declaring both GET and POST, and an `@API-GET-PURE-01-EXCEPTION` with no rationale. What it cannot see is this audit.

## Find every instance

    php artisan rsx:routes | grep 'type=api' | grep 'method=GET' | grep 'file=rsx/'
    grep -rn "API-GET-PURE-01-EXCEPTION" rsx

For each GET handler, read the body and follow EVERY function, method and helper it calls, to the end, including model methods and accessors it triggers.

## What does not conform

- A write anywhere under a GET handler outside its own body: a `save()`, `delete()`, `update()`, `create()`, relation write, `DB::` write or `raw_bulk()` inside a helper, service or model method it calls.
- A dynamic call the rule cannot read (`$model->$method()`, `call_user_func`) that resolves to a write.
- A write the token list does not name: a queue push other than `Task::dispatch`, a mail or SMS send (`->send()`, `Rsx_Mail`, `Rsx_Sms`), a realtime emit, a revision written, a session write, a file write, a counter or cache write that is visible to users.
- An `@API-GET-PURE-01-EXCEPTION` whose rationale is not TRUE: the waived write must be invisible everywhere but telemetry.

Not findings: incidental bookkeeping with no user-visible effect - a request log row, a last-used stamp, a view counter, a cache fill - carried under a truthful exception rationale.

The question for each handler: after this request, would a caller who never made it see anything different on the application's own screens? Yes means it belongs on POST.

## Report

`PASS - <one line: N GET endpoints read, helpers followed, none writes>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
