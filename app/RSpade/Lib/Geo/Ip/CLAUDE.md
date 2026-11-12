# Lib/Geo/Ip - IP geolocation

**Using it? `rsx:man geo_ip`, skill `rspade:geo-ip`.** This file says what is in this directory
and what must stay true when editing it.

## What is here

- `Rsx_Geo_Ip.php` - `lookup()` / `label()`: the lookup order (non-global -> all-null at once;
  RsxCache persistent hit; each database answers its part - city/state/country from the city
  database, the ASN from the ASN database - and `network_name()` resolves `org`; cached one day
  only when every part answered, not-found included; a missing or EXPIRED database or a failed
  part -> that part null, UNCACHED), the data validity window (`DATA_VALIDITY_DAYS`,
  `validity_ends()`, `is_expired()`, `now()` with its `_set_now_for_tests()` seam), the per-process readers keyed by database (each reopened when its
  file's inode/mtime/size changes), the alpha-2 -> alpha-3 country memo (`country_alpha3()`,
  the countries table), the notable-network list (`networks()`: config `rsx.geo_ip.networks`,
  ASN and CIDR keys, validated; a CIDR range beats the ASN, longest prefix wins, matched by
  Symfony `IpUtils::checkIp()`) and the update trigger (`maybe_trigger_update()`: not within
  6 h of a run, not when a pending run or the schedule is due within 5 min, once per process).
- `Geo_Ip_Service.php` - the `update` task (`#[Exclusive('maintenance')] #[Schedule('maintenance')]
  #[On_Build]`) over `DATABASES` (city, asn), each by `update_database()`: no-network "this month
  is installed" check, the DB-IP download page parsed for the release and its SHA1, curl
  download + zlib unpack (1 GB caps) staged beside the target, `verify_candidate()`, one rename.
  The databases are independent: the run records a failure, updates the other, then throws
  naming every failure.
- `Geo_Ip_Health_Checks.php` - the "IP Geolocation" `rsx:health` row: both databases and the
  network list, the worst state of the parts (OK / INFO / WARN, never FAIL; an expired database
  is WARN naming it, its release and its expiry date).
- `ui/Geo_Ip_Credit.jqhtml` - the CC BY attribution line; `ui/` is in `Core_Bundle`, the PHP here
  is server-only.

## Invariants (owner rulings)

- **DB-IP City Lite and ASN Lite only**, read with the PURE-PHP `maxmind-db/reader` - never the
  libmaxminddb C extension (the one memory-unsafe parser of downloaded data).
- **The files live in `tmp/`** via `Rsx_Project_Paths::geo_ip_city_database_file()` /
  `geo_ip_asn_database_file()` alone; `rsx:clean` removes them and `#[On_Build]` brings them back.
- **The notable-network list is DEFAULT-DENY and lives in ONE place**: the `geo_ip.networks`
  default in `system/config/rsx.php` (an app extends it by deep-merge; null unlists). ISPs,
  mobile carriers and backbones are never listed; every ASN entry is checked against the ASN
  database, every range against its owner's published list (source URL + date beside it).
  Listed ranges never expire; Anthropic's are refreshed before each release
  (docs.dev/audits/framework_internal_audit_checklist.md).
  Keys are strings (`'AS16509'`, a CIDR) because the config merge appends numeric keys.
- **Answers are never stored as data** (MySQL, a JSON or meta field, a history row, a cached
  payload) - not by the framework, not by an application. A message composed for a person (an
  email, an SMS, a PDF for sending) may print the location resolved when it is composed, with
  the DB-IP credit (`rsx:man geo_ip`, rule 1 and 3). No
  framework table has a location column, and no framework reader (`Ip_Address`, the session
  listings, `Login_History`, `Audit_Session`) answers one: a screen's endpoint adds
  `ip_address__location` itself (the template's `Session_Listing::row()`, the `/_sys` Users and
  Portal Users screens).
- **A lookup never throws.** The one catch is the owner-ruled containment in `lookup()`: report
  through the exception handler, leave the part null, cache nothing. The update's per-database
  catch (owner ruling: one database never blocks the other) rethrows at the end of the run.
  Everything else fails loud.
- **No timeout of our own** on the download: heartbeats per chunk, and the task silence limit
  ends a stall. The caps are SIZE bounds. Never add `CURLOPT_TIMEOUT`.
- Verification is all-or-nothing before the swap: SHA1 (published for the UNPACKED file), type
  (`DBIP-City-Lite` / `DBIP-ASN-Lite (compat=GeoLite2-ASN)`), build epoch in the release month
  (UTC), `8.8.8.8` -> `US` / AS15169.
- **Data lifetime (owner ruling 2026-11-12)**: no source is assumed to last. A failed update
  keeps the installed file answering; a database answers only until `DATA_VALIDITY_DAYS` (183,
  defined ONCE, in `Rsx_Geo_Ip`) after its own build epoch, then `lookup()` treats it as ABSENT
  - the missing-file path: part null, uncached, update triggered. It is a validity window on
  the data, never a timeout; never measure it from the download time, never add a second one.
- **No display component**: `label()` is the display string (org, else "City, State, USA");
  endpoints ship it as `ip_address__location` and screens render that string.

## Tests

`tests/geo_ip/` - fixture MMDB files (city- and ASN-shaped) written by `Geo_Ip_Mmdb_Fixture` (a
minimal IPv4 MMDB encoder), the database paths and download pages swapped through
`_set_*_for_tests()` seams; no test touches the network or the installed files.
