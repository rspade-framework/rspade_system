---
name: geo-ip
description: "Showing where an IP address is - Rsx_Geo_Ip::lookup() / label() (city, state, country from DB-IP City Lite), the ip_address__location field an endpoint ships beside an address, <Geo_Ip_Credit /> (the required CC BY attribution), the Geo_Ip_Service::update task, the 'IP Geolocation' rsx:health row. Use when a screen shows an IP address or a location, a lookup answers null, or on 'not acquired yet' or a failed Geo_Ip_Service::update run."
---

# IP geolocation

`Rsx_Geo_Ip` answers where a public IP address is, for DISPLAY. The framework downloads, verifies and refreshes the database itself - there is nothing to configure and no package to install. Contract: `rsx:man geo_ip`.

```php
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;

Rsx_Geo_Ip::lookup('8.8.8.8');  // ['city' => 'Mountain View', 'state' => 'California', 'country' => 'United States']
Rsx_Geo_Ip::label('8.8.8.8');   // 'Mountain View, California, United States' - null when nothing is known
```

## The three rules

1. **Call it whenever a location is shown; NEVER store the answer** - no column, no JSON, no history row. It may be null the first times (no database acquired yet on this box), and a later call may answer differently when the monthly database updates. Answers are cached for a day in Redis, so calling it per row per load is the intended use.
2. **Never a security control** - approximate, often wrong for mobile and corporate networks, spoofable by a VPN. Never gate, block, score or alert on it.
3. **Credit DB-IP wherever a location is shown**: `<Geo_Ip_Credit />` (in every bundle), once per region (below the table, under the field group), never per row. CC BY 4.0 requires it.

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

## What answers null

- an invalid address, or any non-global one (private, loopback, link-local, CGNAT, documentation) - at once, never cached;
- an address the database does not know - cached a day like any answer;
- no database yet (or `rsx:clean` just removed it - it lives in `tmp/geo_ip/`) - not cached, and the lookup dispatches the update (not again within 6 hours of a run, nor when one is due within 5 minutes);
- a read failure - reported to the log, never thrown, not cached.

## The update

`Geo_Ip_Service::update` - `#[Exclusive('maintenance')] #[Schedule('maintenance')] #[On_Build]`. Its first step is a no-network check ("this month's release is installed"); otherwise it reads the DB-IP download page for the release and its SHA1, downloads, unpacks (1 GB cap), verifies (SHA1, type `DBIP-City-Lite`, build month, 8.8.8.8 = US) and swaps the file in with one rename. A failure keeps the installed file and fails the run with the reason.

```bash
php artisan rsx:task:run Geo_Ip_Service update   # by hand
php artisan rsx:health                            # row "IP Geolocation": OK / INFO not acquired yet / WARN failed or stale
```
