---
id: datetime_display_and_columns
title: Dates render through the formatters, and calendar facts are DATE columns
severity: medium
when: launch, change
scope: rsx/**/*.php, rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man time, rsx:man datetime_inputs, rsx:man csv_exports, skill rspade:date-time
---

## The contract

Two halves. (A) Every rendered date or datetime goes through an `Rsx_Time` (moments) or `Rsx_Date` (calendar dates) formatter, so it reads in the viewer's resolved zone: the user's preference, then the site's `sites.timezone`, then the server timezone (`RSX_SERVER_TIMEZONE`, UTC when blank). A raw attribute prints a UTC ISO string; `new Date(x).toLocale...()` prints the browser's zone instead of the configured one. (B) A calendar fact - a birth date, hire date, invoice date, due date - is a DATE column; stored as DATETIME it becomes midnight UTC and reads as the previous day west of UTC, in every screen, export and email, and no formatter can repair it.

Read `php artisan rsx:man time` in full first, and `rsx:man datetime_inputs`.

Already enforced, not re-checked: `JQHTML-DATETIME-01` (rsx:check) - an `_at`/`_date` identifier printed in a `.jqhtml` `<%= %>` / `<%!= %>` segment with no `Rsx_Time.`/`Rsx_Date.`; `EMAIL-TEMPLATE-01` (rsx:check) - a raw `_at`/`_date` read in a blade under an `/emails/` directory; `MODEL-FETCH-DATE-01` - formatting inside `fetch()`; `MODEL-CARBON-01` - `$casts` with `date`/`datetime`/`timestamp`. Do re-read every `@JQHTML-DATETIME-01-EXCEPTION` and `@EMAIL-TEMPLATE-01-EXCEPTION` marker: the rule takes the marker on trust.

## Find every instance

Audit A - display the rules cannot reach:

    grep -rn "JQHTML-DATETIME-01-EXCEPTION\|EMAIL-TEMPLATE-01-EXCEPTION" rsx
    grep -rnE '\$[A-Za-z_]+->[a-z0-9_]*(_at|_date)\b' rsx --include='*.php' | grep -v '^rsx/tests/' | grep -v '^rsx/resource/' | grep -v 'Rsx_Time::\|Rsx_Date::'
    grep -rnE '[`+].*\b[a-z0-9_]*(_at|_date)\b' rsx --include='*.js' | grep -v 'Rsx_Time\.\|Rsx_Date\.'
    grep -rnE 'new Date\(|toLocale(Date|Time)?String|Intl\.DateTimeFormat|\.slice\(0, ?10\)' rsx --include='*.js' --include='*.jqhtml'
    grep -rnE '<%!?=.*\b[a-z0-9_]*(_at|_date)\b.*\b[a-z0-9_]*(_at|_date)\b' rsx --include='*.jqhtml'
    grep -rnE '\$[a-z_]+=[^ >]*\b[a-z0-9_]*(_at|_date)\b' rsx --include='*.jqhtml' | grep -v 'Rsx_Time\.\|Rsx_Date\.'
    grep -rnE '<%[^=!-].*\b[a-z0-9_]*(_at|_date)\b' rsx --include='*.jqhtml' | grep -v 'Rsx_Time\.\|Rsx_Date\.'
    grep -rnE '(_at|_date)\b' rsx --include='*.php' | grep '/emails/'
    grep -rniE 'build_csv|Csv\\?Writer|->download\(|Response::file|page\.pdf' rsx --include='*.php' --include='*.js' | grep -v '^rsx/tests/'

The PHP grep is noisy (every model read): triage for values that reach a string, a Blade echo or a browser payload. The two-identifier jqhtml grep finds a mixed expression the rule passes (`Rsx_Time.format_timestamp(a_at) + ' / ' + b_at`); the next two find component arguments and statement-built strings the rule does not scan.

Audit B - column types:

    php artisan db:query "SELECT table_name, GROUP_CONCAT(CONCAT(column_name, ' ', data_type) ORDER BY column_name SEPARATOR ', ') AS temporal_columns FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type IN ('datetime','timestamp','date') AND table_name NOT LIKE '\_%' AND column_name NOT IN ('created_at','updated_at','deleted_at') GROUP BY table_name ORDER BY table_name LIMIT 0, 25" --table

`db:query` displays at most 25 rows: repeat with `LIMIT 25, 25`, `LIMIT 50, 25` ... until a page comes back short. `_`-prefixed system tables are excluded. For each DATETIME/TIMESTAMP column, read the code that WRITES it - the name is a hint, not the answer.

## What does not conform

- A temporal value printed, concatenated or sent to the browser as display text without the formatter matching its column type: DATETIME -> `Rsx_Time.*`, DATE -> `Rsx_Date.*`. The wrong class is a finding too (they throw on each other).
- `new Date()`, `toLocaleDateString()`, `Intl.DateTimeFormat`, string slicing or offset arithmetic used to display a value in application code.
- An email or export datetime formatted in the process's zone. Format server-side, preferably in the email class's `data()` (the value freezes into the queue row), passing the recipient's zone explicitly (`Rsx_Time::format_timestamp($t, $zone)`) or the tenant's (`Rsx_Time::get_site_timezone()`) - a queued or task render has no signed-in viewer to resolve. A CSV cell gets an explicit zone too (`rsx:man csv_exports` has none of its own).
- A calendar fact in a DATETIME/TIMESTAMP column. Report the column AND its pipeline, each item on its own line: the migration to DATE; the model (the DATE cast then yields `YYYY-MM-DD`); `fetch()` and every payload shape; the input (native `type="date"`, never `datetime-local`); the display (`Rsx_Date`); and every other reader - comparisons, sorts, filters, exports, emails, reports, API responses. A column reported without its pipeline is an incomplete finding.
- A raw-SQL or `DB::table()` write of a local-zone datetime string into a moment column (runtime sessions are UTC, so `NOW()` agrees with PHP writes; a hand-formatted local string does not).

Not findings: a DATETIME holding a genuine moment (`created_at`, `sent_at`, `last_login_at`, `expires_at`, `published_at`); a non-temporal identifier that merely ends in `_at`/`_date`; the framework's own formatters using `Intl` internally.

## Report

`PASS - <one line: display sweep clean, N temporal columns classified>`, or one finding per line:

    file:line - what is wrong - the conforming fix

For a column finding use `table.column` in place of `file:line`, one line per pipeline item. No other prose.
