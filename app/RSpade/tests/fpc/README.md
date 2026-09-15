# Concern: fpc

## Domain overview & applicability

Full Page Cache: the edge proxy that caches rendered responses and serves them
without hitting PHP, keyed and invalidated per the FPC rules. Correctness here is
observable only over real HTTP (cache hit/miss headers, TTL, bypass conditions),
so its tests are `http` type, not PHP.

## Source files

- FPC proxy + cache layer (see `man fpc` for the components and Redis `fpc:*` keys)
- `Core/FPC/Rsx_FPC.php` - the clear surfaces, plus `MARKER_HEADER` / `marker_value()`:
  the response header the dispatcher stamps and the Node proxy keys its Redis write on,
  whose VALUE carries the route's own TTL (seconds, or `none`).
- `Core/Dispatch/Route_ManifestSupport.php` - bakes `fpc` and `fpc_ttl_mins` onto the
  route row from `#[FPC(ttl: N)]`; `Core/Manifest/Manifest_Store.php` validates the
  argument at build time.
- `Commands/Rsx/Fpc_Clear_Command.php` - `rsx:fpc:clear [--url=]`.
- Related: `app/RSpade/Core/SSR/`, the FPC config (`proxy_port` and nothing else - there
  is no master switch and no TTL key; caching is declared per route)

## Man page(s)

- `man/fpc.txt`

## Testable surface

- HTTP cache behavior: hit/miss headers, cache key, TTL, bypass for
  authenticated/uncacheable responses, invalidation. (http - live FPC proxy on
  the configured port; SKIPs cleanly when the proxy isn't running)
- The per-route TTL declaration, end to end from the attribute to the marker value the
  proxy reads, plus the `rsx:fpc:clear` lever. (php - `Fpc_Ttl_Marker_Test`, which reads
  the manifest rows of the two real routes in `Fpc_Ttl_Fixture_Controller`)
- Cache key derivation and bypass-rule logic that can run in-process. (php) - planned

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md.)

## Notes

`http/fpc_cache_behavior.sh` was migrated from the previous `tests/fpc/` bash
layout. It curls the FPC proxy and asserts cache headers; it self-skips if the
proxy is unavailable. Run via the shell runner, not `rsx:test`.
