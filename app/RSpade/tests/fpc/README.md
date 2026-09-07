# Concern: fpc

## Domain overview & applicability

Full Page Cache: the edge proxy that caches rendered responses and serves them
without hitting PHP, keyed and invalidated per the FPC rules. Correctness here is
observable only over real HTTP (cache hit/miss headers, TTL, bypass conditions),
so its tests are `http` type, not PHP.

## Source files

- FPC proxy + cache layer (see `man fpc` for the components and Redis `fpc:*` keys)
- Related: `app/RSpade/Core/SSR/`, the FPC config

## Man page(s)

- `man/fpc.txt`

## Testable surface

- HTTP cache behavior: hit/miss headers, cache key, TTL, bypass for
  authenticated/uncacheable responses, invalidation. (http - live FPC proxy on
  the configured port; SKIPs cleanly when the proxy isn't running)
- Cache key derivation and bypass-rule logic that can run in-process. (php) - planned

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md.)

## Notes

`http/fpc_cache_behavior.sh` was migrated from the previous `tests/fpc/` bash
layout. It curls the FPC proxy and asserts cache headers; it self-skips if the
proxy is unavailable. Run via the shell runner, not `rsx:test`.
