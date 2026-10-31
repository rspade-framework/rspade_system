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
| time-tz-03 | null makes the user automatic | explicit zone, a browser cookie, then set null | column NULL, resolves the cookie's zone, returns true | implemented |
| time-tz-04 | Unknown identifier is refused and writes nothing | "Mars/Olympus_Mons" | InvalidArgumentException, zone unchanged | implemented |
| time-tz-05 | Portal request is refused (no personal zone there) | set_portal_request(true) | RuntimeException (shouldnt_happen) mentioning portal | implemented |
| time-tz-06 | The setter invalidates the resolution cache | get, then set a new zone, then get | second get returns the new zone in-process | implemented |
| time-tz-07 | timezone_options shape | none | 400+ entries, "America/Chicago (UTC-0[56]:00)", UTC+00:00, ksorted | implemented |
| time-tz-08 | Endpoints refuse an anonymous caller | no session | AjaxAuthRequiredException (auth_required) on both endpoints | implemented |
| time-tz-09 | Endpoints serve a signed-in staff user | impersonated user | [{value,label}] options; set_timezone changed=true; get_settings reflects it; '' saves automatic and resolves the browser's zone | implemented |
| time-tz-10 | set_timezone returns per-field validation errors | unknown zone | AjaxFormErrorException carrying details['timezone'] | implemented |
| time-res-01 | An explicit user zone beats the browser cookie | explicit Berlin, cookie Tokyo | Berlin | implemented |
| time-res-02 | An automatic user follows the browser | NULL, cookie Tokyo | Tokyo | implemented |
| time-res-03 | cookie > site > server > UTC | each tier removed in turn | Tokyo, Denver, Sydney, UTC | implemented |
| time-res-04 | An invalid value at each tier falls through, the error value too | Mars stored, garbage / error cookie, Atlantis site | the next tier each time | implemented |
| time-res-05 | An older browser spelling maps to PHP's equivalent | cookie Asia/Calcutta | Asia/Kolkata | implemented |
| time-res-06 | A portal request starts at the cookie | explicit staff zone, portal request | the cookie's zone; explicit_user_timezone() null | implemented |
| time-res-07 | An API request never reads a cookie | API identity, cookie set | site zone automatic, explicit zone explicit | implemented |
| time-res-08 | The cache follows the cookie and the site | cookie changed without a clear; site switched | the new answers | implemented |
| time-int-01 | A first visit gets the interstitial | signed out, no cookie, GET text/html | 200 no-store page: CSP nonce, cookie spellings, marked reload URL, noscript refresh | implemented |
| time-int-02 | A valid cookie answers true | cookie Chicago | true | implemented |
| time-int-03 | The error value and the marker render without a cookie (no loop) | error cookie; ?_rsx_tz=1 with no cookie | false, false | implemented |
| time-int-04 | A command is never asked | no browser | false | implemented |
| time-int-05 | An explicit-zone user is never asked | explicit Berlin | false | implemented |
| time-int-06 | Only a GET document navigation wanting HTML is concerned | POST, */*, JSON, image, iframe | false each | implemented |
| time-int-07 | Ajax and the API are never concerned | Ajax channel; API identity | false, false | implemented |
| time-int-08 | The JS cookie spellings match the PHP constants | Rsx_Timezone_Cookie.js | name, error, ages, marker identical | implemented |
| time-store-01 | timezone_auto is gone; NULL is automatic | login_users columns | timezone present, timezone_auto absent | implemented |
| time-store-02 | Each default_user_timezone form applies on create | automatic / server / site / an identifier | NULL / server zone / site zone / itself | implemented |
| time-store-03 | An explicit create value wins; an update is untouched | explicit Berlin, explicit NULL, a later save | as given; NULL stays NULL | implemented |
| time-store-04 | An unknown default throws | Mars/Olympus_Mons | RuntimeException naming RSX_DEFAULT_USER_TIMEZONE | implemented |
| time-api-01 | An API payload localizes every DATETIME in the key holder's zone | API identity, Chicago | __localized with offset, __localized_tz, NULL gets nulls, helper identical to serializer | implemented |
| time-api-02 | An Ajax payload carries UTC alone | no API identity | no companions | implemented |
| time-api-03 | encode_for_output handles null and a database-format value | null; '2026-10-29 10:13:03' in Tokyo | three nulls; +09:00 | implemented |
| time-api-04 | A DATE column gets no companions | fixture table with DATETIME + DATE, API identity | DATETIME localized, DATE untouched | implemented |
| time-js-01 | An automatic user's page writes the browser zone; the marker is stripped | /_sys in America/Chicago | rsx_tz=America/Chicago, rendered Chicago, no ?_rsx_tz | implemented (playwright/timezone_cookie.js) |
| time-js-02 | An explicit-zone user's cookie is cleared and never watched | simulated preference | cookie absent, no watch | implemented (playwright) |
| time-js-03 | A browser with no zone writes the error value | Intl replaced | error_reading_browser_timezone | implemented (playwright) |
| time-js-04 | A zone change updates the cookie, disables the SPA, freezes the zone | browser_value() replaced | cookie Tokyo, Spa disabled, envelope zone not adopted | implemented (playwright) |
| time-js-05 | An unchanged zone does nothing | same zone | no change | implemented (playwright) |


## Server_Timezone_Test (php, DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| time-srv-01 | the process clock is UTC | - | PHP default zone UTC; MySQL session +00:00 | implemented |
| time-srv-02 | the server timezone is the configured zone; blank/null is UTC | config set / '' / null | the zone; UTC; UTC | implemented |
| time-srv-03 | an unknown server timezone throws naming the key | 'Central' | RuntimeException mentioning RSX_SERVER_TIMEZONE | implemented |
| time-srv-04 | a new site without a timezone takes the server timezone | site saved with no zone | sites.timezone = server zone | implemented |
| time-srv-05 | a new site given a timezone keeps it | site saved with Europe/Berlin | Europe/Berlin | implemented |
| time-srv-06 | the system timezone reads TZ first, then the host files | TZ set / ':'-prefixed / unset | the TZ zone; a known zone | implemented |
| time-srv-07 | the log tap stamps records in the system timezone | TZ=America/Chicago; tap a logger | logger timezone America/Chicago | implemented |
| time-srv-08 | every log channel taps System_Timezone_Log_Tap | config('logging.channels') | each but 'null' lists the tap | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| time-t-01 | Rsx_Time now/parse/format_date/time/datetime | php | not yet authored | planned |
| time-t-02 | Rsx_Time relative() / is_past/future/today | php | not yet authored | planned |
| time-t-03 | Rsx_Time duration_to_human / diff_seconds / add / subtract | php | not yet authored | planned |
| time-t-04 | Rsx_Time to_iso/to_ms/to_database serialization | php | not yet authored | planned |
| time-t-05 | timezone resolution (user/site/config) | php | needs site/user context fixtures | planned |
| time-t-06 | JS parity of Rsx_Date / Rsx_Time | playwright | JS surface, needs browser harness | planned |
