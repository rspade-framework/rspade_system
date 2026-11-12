# geo_ip - IP geolocation (Rsx_Geo_Ip, Geo_Ip_Service)

**Domain.** `Rsx_Geo_Ip::lookup()` / `label()` answer city, state, alpha-3 country and the
notable network (`org`, from the ASN database and the `rsx.geo_ip.networks` list of ASNs and
CIDR ranges) for a public IP address, from the DB-IP City Lite and ASN Lite MMDB files in
`tmp/geo_ip/`, caching every complete answer in Redis for a day. `Geo_Ip_Service::update`
(maintenance schedule, `#[On_Build]`, and triggered by a lookup that finds a database missing)
downloads each monthly release, verifies it and swaps it in, the two databases independently.
Applies to every install; nothing here depends on the template application.

**Source.** `Lib/Geo/Ip/Rsx_Geo_Ip.php`, `Lib/Geo/Ip/Geo_Ip_Service.php`,
`Lib/Geo/Ip/Geo_Ip_Health_Checks.php`, `Lib/Geo/Ip/ui/Geo_Ip_Credit.jqhtml`.
**Man page.** `rsx:man geo_ip`.

**Testable surface.**
- lookup order: non-global short-circuit, cache hit, cached not-found, uncached read failure,
  uncached no-database answer, a missing ASN database leaving only org null;
- the answer: alpha-3 country (unmapped -> null), org from a listed ASN or CIDR range (range
  beats ASN, most specific wins), application additions / renames / null removals, an invalid
  list, label() preferring org;
- the trigger: once per process, the 6 h recent-run and 5 min due rules;
- the data lifetime: a database 183 days past its build epoch is absent (part null, uncached,
  update triggered), a fresh release revives it, listed ranges never expire, and the health
  row WARNs naming the expired database - time fixed through `Rsx_Geo_Ip::_set_now_for_tests()`;
- the update's pure parts, per database: download-page parsing, verification refusals, install
  swap and rollback, the unpack size cap, the no-network "current" exits, one database failing
  without blocking the other.

**Never the network.** Fixture MMDB files are written by `php/Geo_Ip_Mmdb_Fixture.php` (a
minimal IPv4 MMDB encoder); the database path and the download page are swapped through
`Rsx_Geo_Ip::_set_database_files_for_tests()` and `Geo_Ip_Service::_set_download_page_for_tests()`.
The real download is exercised by hand (`php artisan rsx:task:run Geo_Ip_Service update`).
