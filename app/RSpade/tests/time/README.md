# Concern: time

## Domain overview & applicability

Date/time handling. RSpade uses two strictly-separated string-based classes:
`Rsx_Date` (calendar dates, no timezone, "YYYY-MM-DD") and `Rsx_Time`
(datetimes with timezone, ISO 8601). Both have identical PHP and JS APIs. Dates
flow through models, queries, JSON, and UI as strings, so the parsing,
formatting, arithmetic, and boundary rules must be exact and deterministic.

## Source files

- `app/RSpade/Core/Time/Rsx_Date.php` - calendar date logic (covered here)
- `app/RSpade/Core/Time/Rsx_Time.php` - datetime logic (duration math + the user
  timezone PREFERENCE surface covered; the rest planned)
- `app/RSpade/Core/Time/Rsx_Timezone_Controller.php` - the Ajax preference surface
- Casts: `Rsx_Date_Cast`, `Rsx_DateTime_Cast`

## Man page(s)

- `man/time.txt`

## Testable surface

- `Rsx_Date`: format, add_days (boundary crossing), signed diff_days, month/week
  boundaries (week = Mon..Sun), weekend detection, component extractors,
  is_date type-guard, parse rejecting datetimes. (php, no DB) - IMPLEMENTED
- `Rsx_Time`: now/parse/format_*, relative, comparison, duration, arithmetic,
  serialization, timezone resolution, component extractors. (php, no DB) - PLANNED
- Timezone PREFERENCE: `Rsx_Time::set_user_timezone()` (validation, portal refusal,
  cache invalidation, changed-flag semantics), `timezone_options()`, and the
  `Rsx_Timezone_Controller` endpoints incl. their auth gate. (php, DB) - IMPLEMENTED
- JS parity of both classes. (playwright/js) - PLANNED

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md - no divergence found in the Rsx_Date surface tested.)
