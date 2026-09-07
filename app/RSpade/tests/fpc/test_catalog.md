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

## Planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| fpc-p-01 | authenticated / uncacheable responses bypass cache | http | needs auth fixture over HTTP | planned |
| fpc-p-02 | TTL expiry causes re-render | http | time-dependent | planned |
| fpc-p-03 | invalidation clears the right `fpc:*` keys | http | needs invalidation trigger | planned |
| fpc-p-04 | cache-key derivation / bypass-rule unit logic | php | the in-process decision logic, isolated from HTTP | planned |
