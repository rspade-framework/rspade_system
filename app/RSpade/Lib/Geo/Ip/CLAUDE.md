# Lib/Geo/Ip - IP geolocation

**Using it? `rsx:man geo_ip`, skill `rspade:geo-ip`.** This file says what is in this directory
and what must stay true when editing it.

## What is here

- `Rsx_Geo_Ip.php` - `lookup()` / `label()`: the lookup order (non-global -> all-null at once;
  RsxCache persistent hit; database answer cached one day, not-found included; no usable
  database or a read failure -> all-null UNCACHED), the per-process reader (reopened when the
  file's inode/mtime/size changes) and the update trigger (`maybe_trigger_update()`: not within
  6 h of a run, not when a pending run or the schedule is due within 5 min, once per process).
- `Geo_Ip_Service.php` - the `update` task (`#[Exclusive('maintenance')] #[Schedule('maintenance')]
  #[On_Build]`): no-network "this month is installed" check, the DB-IP download page parsed for
  the release and its SHA1, curl download + zlib unpack (1 GB caps) staged beside the target,
  `verify_candidate()`, one rename.
- `Geo_Ip_Health_Checks.php` - the "IP Geolocation" `rsx:health` row (OK / INFO / WARN, never FAIL).
- `ui/Geo_Ip_Credit.jqhtml` - the CC BY attribution line; `ui/` is in `Core_Bundle`, the PHP here
  is server-only.

## Invariants (owner rulings)

- **DB-IP City Lite only**, read with the PURE-PHP `maxmind-db/reader` - never the libmaxminddb
  C extension (the one memory-unsafe parser of downloaded data).
- **The file lives in `tmp/`** via `Rsx_Project_Paths::geo_ip_database_file()` alone; `rsx:clean`
  removes it and `#[On_Build]` brings it back.
- **Answers are never stored in MySQL** - not by the framework, not by an application. No
  framework table has a location column, and no framework reader (`Ip_Address`, the session
  listings, `Login_History`, `Audit_Session`) answers one: a screen's endpoint adds
  `ip_address__location` itself (the template's `Session_Listing::row()`, the `/_sys` Users and
  Portal Users screens).
- **A lookup never throws.** The one catch is the owner-ruled containment in `lookup()`: report
  through the exception handler, answer all-null, cache nothing. Everything else fails loud.
- **No timeout of our own** on the download: heartbeats per chunk, and the task silence limit
  ends a stall. The caps are SIZE bounds. Never add `CURLOPT_TIMEOUT`.
- Verification is all-or-nothing before the swap: SHA1 (published for the UNPACKED file), type
  `DBIP-City-Lite`, build epoch in the release month (UTC), `8.8.8.8` -> `US`.

## Tests

`tests/geo_ip/` - fixture MMDB files written by `Geo_Ip_Mmdb_Fixture` (a minimal IPv4 MMDB
encoder), the database path and download page swapped through `_set_*_for_tests()` seams; no
test touches the network or the installed file.
