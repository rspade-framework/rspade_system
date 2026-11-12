# geo_ip - IP geolocation (Rsx_Geo_Ip, Geo_Ip_Service)

**Domain.** `Rsx_Geo_Ip::lookup()` / `label()` answer city, state and country for a public IP
address from the DB-IP City Lite MMDB file in `tmp/geo_ip/`, caching every real answer in Redis
for a day. `Geo_Ip_Service::update` (maintenance schedule, `#[On_Build]`, and triggered by a
lookup that finds no database) downloads the monthly release, verifies it and swaps it in.
Applies to every install; nothing here depends on the template application.

**Source.** `Lib/Geo/Ip/Rsx_Geo_Ip.php`, `Lib/Geo/Ip/Geo_Ip_Service.php`,
`Lib/Geo/Ip/Geo_Ip_Health_Checks.php`, `Lib/Geo/Ip/ui/Geo_Ip_Credit.jqhtml`.
**Man page.** `rsx:man geo_ip`.

**Testable surface.**
- lookup order: non-global short-circuit, cache hit, cached not-found, uncached read failure,
  uncached no-database answer;
- the trigger: once per process, the 6 h recent-run and 5 min due rules;
- the update's pure parts: download-page parsing, verification refusals, install swap and
  rollback, the unpack size cap, the no-network "current" exits.

**Never the network.** Fixture MMDB files are written by `php/Geo_Ip_Mmdb_Fixture.php` (a
minimal IPv4 MMDB encoder); the database path and the download page are swapped through
`Rsx_Geo_Ip::_set_database_file_for_tests()` and `Geo_Ip_Service::_set_download_page_for_tests()`.
The real download is exercised by hand (`php artisan rsx:task:run Geo_Ip_Service update`).
