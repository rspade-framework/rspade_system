---
name: geo-ip
description: "Showing where an IP address is and whose network it is - Rsx_Geo_Ip::lookup() / label() (city, state, alpha-3 country from DB-IP City Lite; org from DB-IP ASN Lite and the curated rsx.geo_ip.networks list of ASNs and CIDR ranges), the ip_address__location field an endpoint ships beside an address, <Geo_Ip_Credit /> (the required CC BY attribution), the Geo_Ip_Service::update task, the 'IP Geolocation' rsx:health row. Use when a screen shows an IP address or a location, a lookup answers null, a cloud address shows a town instead of its provider, adding a network to rsx.geo_ip.networks, or on 'not acquired yet', 'EXPIRED' (a database past Rsx_Geo_Ip::DATA_VALIDITY_DAYS) or a failed Geo_Ip_Service::update run."
---

# IP geolocation

`Rsx_Geo_Ip` answers where a public IP address is - and, for a NOTABLE network (cloud, hosting, CDN, security proxy, AI), whose network it is - for DISPLAY. The framework downloads, verifies and refreshes both databases (DB-IP City Lite and ASN Lite) itself - there is nothing to install. Contract: `rsx:man geo_ip`.

```php
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;

Rsx_Geo_Ip::lookup('8.8.8.8');      // ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => 'Google']
Rsx_Geo_Ip::label('8.8.8.8');       // 'Google' - the org when the network is listed
Rsx_Geo_Ip::label('81.2.69.142');   // 'London, England, GBR' - else the place; null when nothing is known
```

`country` is ISO 3166-1 **alpha-3** (mapped through the countries table; an unmapped code is null). `org` supplements the place - city/state/country are answered either way - and `label()` is the display that chooses. The answer always has those four keys.

## The three rules

1. **Call it whenever a location is shown; NEVER store the answer as data** - no column, no JSON or meta field, no history or audit row, no cached payload: nothing the application reads back. **A message composed for a person - an email, an SMS, a PDF generated for sending - MAY print the location resolved when it is composed** (it states what was true when sent, like its timestamp; nothing reads it back), with the DB-IP credit in the footer: HTML `IP geolocation by <a href="https://db-ip.com">DB-IP</a>`, plain text `IP geolocation by DB-IP (https://db-ip.com)`; a null answer prints the address alone. It may be null the first times (no database acquired yet on this box), and a later call may answer differently when the monthly database updates. Answers are cached for a day in Redis, so calling it per row per load is the intended use.
2. **Never a security control** - approximate, often wrong for mobile and corporate networks, spoofable by a VPN. Never gate, block, score or alert on it.
3. **Credit DB-IP wherever a location or network name is shown**: `<Geo_Ip_Credit />` (in every bundle), once per region (below the table, under the field group), never per row. CC BY 4.0 requires it.

## Shipping it on a screen

The ENDPOINT resolves the location on every load and ships it beside the address under the `__` derived-field convention; the template renders both:

```php
'ip_address' => $row['ip_address'],
'ip_address__location' => $row['ip_address'] !== null ? Rsx_Geo_Ip::label($row['ip_address']) : null,
```

```html
<td><Ip_Address_Display $ip_address=row.ip_address $location=row.ip_address__location /></td>
...
</Record_Table>
<Geo_Ip_Credit />
```

`Ip_Address_Display` is the template application's display component (`rsx/theme/components/view/ip_address_display/`); the reference screens are the session lists (`rsx/lib/sessions/session_listing.php` ships the field for every one of them) and the user-management session activity table.

**No framework reader answers a location** - `Session::get_sessions_for_user()`, `Login_History` / `Portal_Login_History`, `Audit_Session` and `Ip_Address::describe()` / `join()` carry the address only, and no table has a location column. The endpoint adds `ip_address__location` for the rows it serves (for a paged grid, the rows of the page - never a sort or search key).

## The notable-network list

`config('rsx.geo_ip.networks')`, shipped in the framework's `config/rsx.php` (the one place the curated entries live) - **default-deny**: an unlisted network answers `org` null, and ISPs, mobile carriers and backbones are never listed. A key is an ASN (`'AS16509' => 'AWS'`) or an IPv4/IPv6 CIDR range (`'160.79.104.0/21' => 'Anthropic'`). **A listed range containing the address wins over its ASN, the most specific range among several** - ranges cover networks the ASN database gives to someone else or omits (Anthropic's published ranges: part are in Amazon's ASN, part are not in the database at all). An application adds, renames (same key) or unlists (`null`) entries in `rsx/resource/config/rsx.php`:

```php
'geo_ip' => ['networks' => [
    'AS64500' => 'Example Cloud',
    '203.0.113.0/24' => 'Example Cloud',
    'AS15169' => null,
]],
```

A malformed key or name is reported by every lookup (org null, uncached) and turns the health row WARN; `Rsx_Geo_Ip::networks()` throws naming it.

## What answers null

- an invalid address, or any non-global one (private, loopback, link-local, CGNAT, documentation) - at once, never cached;
- an address the database does not know - cached a day like any answer;
- a database not acquired yet (or `rsx:clean` just removed it - both live in `tmp/geo_ip/`) - its part is null (the other database still answers, and a listed range still answers `org`), not cached, and the lookup dispatches the update (not again within 6 hours of a run, nor when one is due within 5 minutes);
- a database **EXPIRED** - more than 183 days (`Rsx_Geo_Ip::DATA_VALIDITY_DAYS`) after its own release (the build epoch in the file, not the download date) - is treated exactly as a missing one: its part null, not cached, the update dispatched;
- a read failure - reported to the log, never thrown, that part null, not cached.

## Data lifetime

Every source can stop working, so the data degrades and the address never does: a failed update keeps the installed release answering, the update keeps retrying, and the health row WARNs; six months after a release with no successful update since, that database is absent and the screen shows the bare IP address. A later successful update revives it. The 183 days is an owner-ruled validity window on the DATA, never a timeout - a healthy box replaces each file monthly. A cached answer outlives its database by at most the cache's one day. Listed CIDR ranges need no database and never expire; ASN entries answer nothing without the ASN database.

## The update

`Geo_Ip_Service::update` - `#[Exclusive('maintenance')] #[Schedule('maintenance')] #[On_Build]` - updates both databases, independently (one failing never blocks the other; the run then fails naming it). Per database, the first step is a no-network check ("this month's release is installed"); otherwise it reads the DB-IP download page for the release and its SHA1, downloads, unpacks (1 GB cap), verifies (SHA1, type `DBIP-City-Lite` / `DBIP-ASN-Lite (compat=GeoLite2-ASN)`, build month, 8.8.8.8 = US / AS15169) and swaps the file in with one rename. A failure keeps the installed file.

```bash
php artisan rsx:task:run Geo_Ip_Service update   # by hand
php artisan rsx:health                            # row "IP Geolocation" (both databases + the list): OK / INFO not acquired yet / WARN expired, failed, stale or invalid list
```
