---
name: time-value-design
description: "Designing how an application stores the dates and times its users record - an INSTANT (UTC DATETIME), a CALENDAR DATE (DATE), or an INTENDED DAY with an optional actual time and the zone it was entered in - plus the day-and-clock helpers Rsx_Time::local_date(), local_clock(), combine_date_and_time(), day_start_utc() and day_after_utc(). Use when adding a date or time column, logging work or hours, building a calendar, a timecard, a timesheet or a due date, writing a report grouped by day, a scheduled sweep that reasons about \"today\", or when evening work shows on the next day, a date moved a day for some viewers, or substr($time, 0, 10) / SQL DATE() appears on an instant."
---

# Time value design

**Every RSpade application is recommended to follow these conventions.** The contract, with the
worked shapes and the dead ends in full, is `rsx:man time_value_design`.

## First: which of the three kinds is the new column?

| The value | Kind | Store as |
|---|---|---|
| A moment: a meeting, `created_at`, a timer start | **Instant** | UTC `DATETIME` |
| A day with no zone: a due date, a holiday, an invoice date | **Calendar date** | `DATE` |
| The day work belongs to, maybe with when it actually happened | **Intended day (+ time)** | `DATE` + optional UTC `DATETIME`s + the entered-in `timezone` |

Ask "would two viewers in different zones ever need to see different days?" Yes for an instant's
date; never for a calendar date; and for the third kind the DAY never moves while the TIME is
placed per viewer.

## The rules that cost data when missed

1. **Totals, timecards, quotas, per-day views and billing read the DAY column** - never a date
   derived from the time.
2. **A record with a clock time stores the zone the clock was ENTERED in** (`timezone`, NULL
   exactly when no time was given, `Rsx_Time::is_timezone()` on save).
3. **An edit form seeds and saves the clock in the RECORDED zone**, never the editor's:
   `Rsx_Time::local_clock($entry->started_at, $entry->timezone)` in, the same zone back out.
4. **Re-applying the same instant keeps the recorded zone.**
5. **"Today" in a task is the SITE's day** (`Rsx_Date::today()` has no viewer there): code about a
   person resolves their zone, code about a record uses the record's zone. A check that depends on
   a person's midnight runs hourly and judges each record in its own zone - cron is server-zone
   wall clock.
6. **Never `substr($time, 0, 10)`, never SQL `DATE()` of an instant** - that is the UTC date.
   `Rsx_Time::local_date($time, $zone)`.
7. **A date window over instants converts each bound per date**: `day_start_utc($from, $zone)`
   inclusive, `day_after_utc($to, $zone)` exclusive, wrapped in `Rsx_Time::to_database()` for a
   query binding.
8. **Store a date as a date.** No floating midnight with a flag, no placeholder 06:00 with "don't
   print the clock" - both are dead ends with real damage behind them (the man page lists them).

## The helpers (PHP and JS, same names, same answers)

```php
Rsx_Time::local_date('2026-10-27T04:00:00Z', 'America/Chicago');               // '2026-10-26'
Rsx_Time::local_clock('2026-10-27T04:00:00Z', 'America/Chicago');              // '23:00'
Rsx_Time::combine_date_and_time('2026-10-26', '23:00', 'America/Chicago');     // '2026-10-27T04:00:00.000Z'
Rsx_Time::day_start_utc('2026-11-01', 'America/Chicago');                      // '2026-11-01T05:00:00.000Z'
Rsx_Time::day_after_utc('2026-11-01', 'America/Chicago');                      // '2026-11-02T06:00:00.000Z' (a 25-hour day)
```

A null clock combines to null; a clock a spring-forward jump skips is the instant after it; an
ambiguous fall-back clock is its first occurrence. The JS twins take any named zone - a calendar
drawn in a user's chosen zone never falls back to the device's.

## Auditing existing data

`CONVERT_TZ()` with a named zone returns NULL where MySQL's zone tables are not loaded, so a
"rows whose local day disagrees" query silently reports zero. Audit in PHP with `local_date()`.
