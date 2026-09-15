# Test catalog: fpc

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: http / php. Last updated: 2026-06-16.

## http/fpc_cache_behavior.sh (http - live FPC proxy)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fpc-http-01 | cache MISS then HIT for a cacheable route | two GETs | second served from cache (hit header) | implemented |
| fpc-http-02 | cache headers present and well-formed | GET | expected FPC headers | implemented |
| fpc-http-03 | proxy-unavailable safe skip | proxy down | test SKIPs, not fails | implemented |

(See the script for the exact assertions; migrated from the prior bash suite.)

## php/Fpc_Ttl_Marker_Test.php (php)

The per-route lifetime: `#[FPC(ttl: 5)]` on a route, 300 seconds on the wire. Read where
the chain actually lives - the manifest rows of the two real routes in
`Fpc_Ttl_Fixture_Controller`, and the marker value the dispatcher stamps from them.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fpc-ttl-01 | a declared TTL is baked onto the route row, in the minutes the attribute spelled | `#[FPC(ttl: 5)]` route row | `fpc` true, `fpc_ttl_mins` 5 | implemented |
| fpc-ttl-02 | a bare `#[FPC]` declares no TTL - zero, not null | `#[FPC]` route row | `fpc` true, `fpc_ttl_mins` 0 | implemented |
| fpc-ttl-03 | a route without the attribute carries no lifetime either - caching is opted into, never defaulted | every other route row | no `fpc_ttl_mins` key | implemented |
| fpc-ttl-04 | five minutes reaches the proxy as 300 seconds - minutes in the attribute, seconds on the wire | `marker_value(5)` | `'300'` | implemented |
| fpc-ttl-05 | no declaration reaches the proxy as `none`, a word rather than a zero | `marker_value(0)` | `MARKER_NO_EXPIRY` === `'none'` | implemented |
| fpc-ttl-06 | the TTL rides on the SAME header that says "cache this", and the proxy no longer reads a TTL from the environment | `MARKER_HEADER` + fpc-proxy.js source | `X-RSpade-FPC`; no `FPC_TTL_MINS` | implemented |
| fpc-clear-01 | `rsx:fpc:clear` reports what it cleared and for which build | `Artisan::call` | exit 0, names the build key | implemented |
| fpc-clear-02 | `--url` clears one page and names it; a page that was not cached is not a failure | `--url=/test-fpc/ttl` | exit 0, names the path | implemented |
| fpc-clear-03 | it removes the entry the proxy would have written - the key format is a contract between the two | seeded `fpc:{build_key}:{sha1}` in DB 2 | key gone after the command | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| fpc-p-01 | authenticated / uncacheable responses bypass cache | http | needs auth fixture over HTTP | planned |
| fpc-p-02 | TTL expiry causes re-render | http | time-dependent (the declaration side is covered by fpc-ttl-01..06) | planned |
| fpc-p-03 | invalidation clears the right `fpc:*` keys | http | needs invalidation trigger | planned |
| fpc-p-04 | cache-key derivation / bypass-rule unit logic | php | the in-process decision logic, isolated from HTTP | planned |
