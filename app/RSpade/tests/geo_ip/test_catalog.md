# geo_ip - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| GEO-01 | Non-global or invalid addresses answer all-null with no cache write and no dispatch | php | 10.x, 127.0.0.1, ::1, fe80::1, documentation range, garbage; no database | all-null, nothing cached, no `_tasks` row | implemented (`Geo_Ip_Lookup_Test`) | 2026-11-12 |
| GEO-02 | An IPv4-mapped IPv6 address is read as IPv4 | php | `::ffff:8.8.8.8` | `8.8.8.8` | implemented | 2026-11-12 |
| GEO-03 | A database answer is cached, not-found included | php | fixture with 8.8.8.0/24 | found and all-null both in the cache | implemented | 2026-11-12 |
| GEO-04 | `label()` is the org when listed, else the known parts (alpha-3 country), null when nothing is known | php | fixture | "Google" / "Salinas, California, USA" / null | implemented | 2026-11-12 |
| GEO-05 | A cached answer wins over the database | php | seeded cache entry | the cached answer | implemented | 2026-11-12 |
| GEO-06 | A file that will not open answers all-null, uncached, never thrown | php | garbage file | all-null, no cache entry | implemented | 2026-11-12 |
| GEO-07 | No database answers all-null uncached and dispatches the update once per process | php | no file | one `_tasks` row; a second lookup adds none | implemented | 2026-11-12 |
| GEO-08 | A run created within 6 h suppresses the trigger; 7 h does not | php | inserted run rows | no dispatch / dispatch | implemented | 2026-11-12 |
| GEO-09 | A pending run or the schedule due within 5 min suppresses the trigger; an hour out does not | php | pending row, schedule row | no dispatch / dispatch | implemented | 2026-11-12 |
| GEO-10 | The download page parser reads each MMDB card's month and SHA1 for its own database, ignores CSV, SHA1-less and other-database cards | php | page snippet | `['YYYY-MM' => sha1]` | implemented (`Geo_Ip_Update_Test`) | 2026-11-12 |
| GEO-11 | Verification accepts the expected release and refuses a wrong checksum, type, build month and sanity country | php | fixture MMDBs | accepted / four refusals naming the check | implemented | 2026-11-12 |
| GEO-12 | install_from_gz swaps a verified release in and leaves nothing staged | php | fixture .gz | target replaced, lookups read it | implemented | 2026-11-12 |
| GEO-13 | A refused install leaves the installed file untouched | php | bad SHA1 | target unchanged, staged file gone | implemented | 2026-11-12 |
| GEO-14 | Unpacking refuses past its size cap | php | 4 KB gz, 1000-byte cap | refused; within the cap the SHA1 is returned | implemented | 2026-11-12 |
| GEO-15 | update() ends with no network when this month's release is installed | php | fixture built now; page seam that would fail | completed, "Current" | implemented | 2026-11-12 |
| GEO-16 | update() ends when the installed release is the latest the page lists | php | previous-month fixture + page | completed, "not published yet" | implemented | 2026-11-12 |
| GEO-17 | update() fails when the page lists neither this nor the previous month | php | page listing 2019-01 | throws "lists no MMDB release" | implemented | 2026-11-12 |
| GEO-20 | An unlisted network (an ISP) answers org null and the place still | php | Comcast fixture | org null, city/state/country set | implemented (`Geo_Ip_Lookup_Test`) | 2026-11-12 |
| GEO-21 | A country code with no countries row answers null; the mapping reads the table | php | `ZZ` fixture, `gb` | null / `GBR` | implemented | 2026-11-12 |
| GEO-22 | A listed CIDR range beats the ASN (needs no ASN record; IPv6 too); an unlisted range of a listed ASN answers the ASN's name | php | 153.61.208.1, 160.79.110.1 (AS16509 in the fixture), 52.94.236.248, 2607:6bc0::1 | Anthropic x3 + IPv6, AWS | implemented | 2026-11-12 |
| GEO-23 | The application adds, renames and (null) unlists entries; the most specific range wins | php | config override | listed / unlisted / narrower range wins | implemented | 2026-11-12 |
| GEO-24 | An invalid network list throws from `networks()` and leaves org null, uncached | php | numeric key | throws; org null; nothing cached | implemented | 2026-11-12 |
| GEO-25 | A missing ASN database leaves org null, the place answered, uncached, and triggers the update | php | city fixture only | org null, no cache entry, one run | implemented | 2026-11-12 |
| GEO-26 | ASN verification accepts the release and refuses checksum, type, build month and sanity ASN | php | ASN fixtures | accepted / four refusals | implemented (`Geo_Ip_Update_Test`) | 2026-11-12 |
| GEO-27 | One database failing does not block the other; the run fails naming only the failed one | php | city page empty, ASN current | run fails "DB-IP City Lite:", summary has the ASN's "Current" | implemented | 2026-11-12 |
| GEO-18 | The health row: INFO with no database, OK with current ones, WARN naming each expired database, its release and expiry date | php | fixtures, fixed now | INFO / OK / WARN | implemented (`Geo_Ip_Update_Test`) | 2026-11-12 |
| GEO-28 | A database 183 days past its build epoch is absent: all-null, uncached, the update dispatched; the day before it answers and is cached | php | fixtures built 2026-03-15, fixed now | empty answer, one run / full answer | implemented (`Geo_Ip_Lookup_Test`) | 2026-11-12 |
| GEO-29 | A fresh release renamed in place of an expired one answers again | php | expired then fresh fixtures | full answer | implemented | 2026-11-12 |
| GEO-30 | One expired database leaves the other answering; listed CIDR ranges never expire; ASN entries answer nothing once the ASN database expired | php | expired city, fresh ASN; then expired ASN | org Google, place null; Anthropic; AWS -> null | implemented | 2026-11-12 |
| GEO-19 | The real download, verification and swap, both databases | manual | network | installed, SHA1 matches the page | deferred (tests never touch the network; run by hand) | 2026-11-12 |
