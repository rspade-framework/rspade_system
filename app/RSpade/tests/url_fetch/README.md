# url_fetch

`Rsx_Url_Fetch` (`rsx:man url_fetch`): the destination-checked download of a URL a user can
supply - http/https only, every resolved address and every redirect hop classified (loopback,
private, link-local, CGNAT, multicast, broadcast, reserved, IPv6 forms embedding IPv4), the
connection pinned to the checked address, the body streamed to a temp file under `max_bytes`,
temp files deleted on error and at process end. `File_Attachment_Model::create_from_url()` is
built on it.

## Source under test

- `app/RSpade/Lib/Support/UrlFetch/Rsx_Url_Fetch.php` - `to_temp_file()`,
  `check_destination()`, `address_class()`.
- `app/RSpade/Lib/Support/UrlFetch/Rsx_Url_Fetch_Result.php`, `Rsx_Url_Fetch_Exception.php`.
- `app/RSpade/Core/Files/File_Attachment_Model_Abstract.php` - `create_from_url()`.
- `rsx.url_fetch.connect_timeout_seconds` (`system/config/rsx.php`).

## Fixtures

- `resource/fixture_router.php` - the router a test serves with `php -S 127.0.0.1:<free port>`
  (sized bodies with and without Content-Length, a lying Content-Length, a named CSV, a PNG,
  status codes, redirects, an endless redirect).
- `resource/fetch_and_exit.php` - a separate process that fetches and exits leaving its temp
  file in place, for the end-of-process cleanup.
- `php/Url_Fetch_Loopback_Public_Fixture.php` - `Rsx_Url_Fetch` with 127.0.0.1 counted as
  public, so the real per-hop check can be shown refusing a redirect from the fixture server
  to a private address (the box has no public server to start a chain from).

The fixture server is loopback, so a fetch that must reach it passes `allow_private: true`.
Not covered live: the connect limit expiring (needs a host that silently drops SYNs) and a
DNS answer changing between check and connect - the pin is `CURLOPT_CONNECT_TO` with an empty
host, read in the source.
