# Test catalog: time

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-09-01.

## Rsx_Date_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| time-date-01 | format() human form | "2025-12-24" | "Dec 24, 2025" | implemented |
| time-date-02 | add_days crosses month/year | +8 / -24 from 2025-12-24 | 2026-01-01 / 2025-11-30 | implemented |
| time-date-03 | diff_days is signed | (24->31)/(31->24) | 7 / -7 | implemented |
| time-date-04 | month boundaries | 2025-12-24 | start 12-01, end 12-31 | implemented |
| time-date-05 | week runs Mon..Sun | 2025-12-24 (Wed) | 12-22 .. 12-28 | implemented |
| time-date-06 | weekend detection | Sat / Wed | true / false | implemented |
| time-date-07 | component extractors | 2025-12-24 / 25 | day/month/year, dow_human Thursday, month_human December | implemented |
| time-date-08 | is_date rejects datetime | date vs datetime | true / false | implemented |
| time-date-09 | parse throws on datetime | "...T10:00:00Z" | InvalidArgumentException | implemented |

## Rsx_Time_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| time-t-07 | diff_seconds is signed FROM start TO end | 00:00 -> 02:00 / reversed | 7200 / -7200 | implemented |
| time-t-08 | diff_seconds antisymmetry | (a,b) vs (b,a) | equal magnitude, opposite sign | implemented |
| time-t-09 | seconds_since / seconds_until against now() | now-3600 / now+3600 / now | ~3600 / ~3600 / ~0 (5s tolerance) | implemented |
| time-t-10 | diff_seconds truncates a fractional gap toward zero with NO deprecation | 3984.950448 s forward and backward, E_DEPRECATED handler armed | 3984 / -3984, handler never called (Carbon 3 float narrowed by an explicit (int)) | implemented |

## Rsx_Timezone_Preference_Test (php, DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| time-tz-01 | set_user_timezone persists the zone and reports the change | signed-in user, Europe/Berlin | login_users.timezone written, returns true | implemented |
| time-tz-02 | Re-setting the same zone is not a change | Europe/Berlin twice | second call returns false | implemented |
| time-tz-03 | timezone_auto-only change persists without a zone change | same zone, auto false then true | auto persisted, returns false | implemented |
| time-tz-04 | Unknown identifier is refused and writes nothing | "Mars/Olympus_Mons" | InvalidArgumentException, zone unchanged | implemented |
| time-tz-05 | Portal request is refused (no personal zone there) | set_portal_request(true) | RuntimeException (shouldnt_happen) mentioning portal | implemented |
| time-tz-06 | The setter invalidates the resolution cache | get, then set a new zone, then get | second get returns the new zone in-process | implemented |
| time-tz-07 | timezone_options shape | none | 400+ entries, "America/Chicago (UTC-0[56]:00)", UTC+00:00, ksorted | implemented |
| time-tz-08 | Endpoints refuse an anonymous caller | no session | AjaxUnauthorizedException on both endpoints | implemented |
| time-tz-09 | Endpoints serve a signed-in staff user | impersonated user | [{value,label}] options; set_timezone changed=true; get_settings reflects it | implemented |
| time-tz-10 | set_timezone returns per-field validation errors | missing / unknown zone | AjaxFormErrorException carrying details['timezone'] | implemented |


## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| time-t-01 | Rsx_Time now/parse/format_date/time/datetime | php | not yet authored | planned |
| time-t-02 | Rsx_Time relative() / is_past/future/today | php | not yet authored | planned |
| time-t-03 | Rsx_Time duration_to_human / diff_seconds / add / subtract | php | not yet authored | planned |
| time-t-04 | Rsx_Time to_iso/to_ms/to_database serialization | php | not yet authored | planned |
| time-t-05 | timezone resolution (user/site/config) | php | needs site/user context fixtures | planned |
| time-t-06 | JS parity of Rsx_Date / Rsx_Time | playwright | JS surface, needs browser harness | planned |
