# Test catalog: fpc

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: http / php. Last updated: 2026-10-01.

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

## php/Fpc_Cache_Key_Test.php (php)

The key is `fpc:{build_key}:{host}:{sha1(path?sorted_query)}`: one application answers on
several hosts (APP_URL's, a client portal on its own host), so the request Host - lowercased,
port dropped - is part of every key. The key is a contract between `Rsx_FPC` and
`system/bin/fpc-proxy.js`, so the proxy's own `compose_cache_key()` is run and compared.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| fpc-key-01 | the same path on two hosts is two keys | `cache_key()` app vs portal host | different; documented format | implemented |
| fpc-key-02 | the host is compared without case or port | `App.Example.TEST:8080`, `[::1]:6200` | same key as the bare host | implemented |
| fpc-key-03 | query parameters are sorted | `?b=2&a=1` vs `?a=1&b=2` | same key | implemented |
| fpc-key-04 | PHP and the proxy compute the same key | four host/url cases through `node -e require(fpc-proxy.js)` | identical | implemented |
| fpc-clear-03 | a full URL clears that host only; a bare path (`rsx:fpc:clear --url=/x`) clears it on every host and leaves other paths | seeded proxy keys in DB 2 for two hosts + another path | per row | implemented |

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| fpc-p-01 | authenticated / uncacheable responses bypass cache | http | needs auth fixture over HTTP | planned |
| fpc-p-02 | TTL expiry causes re-render | http | time-dependent (the declaration side is covered by fpc-ttl-01..06) | planned |
| fpc-p-03 | invalidation clears the right `fpc:*` keys | http | needs invalidation trigger | planned |
| fpc-p-04 | bypass-rule unit logic (the key derivation is Fpc_Cache_Key_Test) | php | the in-process decision logic, isolated from HTTP | planned |
