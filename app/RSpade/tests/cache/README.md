# Concern: cache

## Domain

`RsxCache` - the redis-backed, build-key-scoped application cache - plus `Rsx_Counter`, the
transient counter store that deliberately does NOT live in it.

**One value encoding in the cache keyspace.** Everything written by `set()` /
`set_persistent()` / `remember()` is a `serialize()` payload, read back by `get()` /
`get_persistent()`. Anything else at a cache key was not written by this class, and reading
it fails loud rather than handing the caller a bogus `false`.

**The redis database map** (the `RsxCache` class header is the authority): DB 0 volatile
cache, DB 1 locks and the task worker registry, DB 2 the reduced-volatility cache (FPC, and
`_RVC_`-prefixed cache keys), DB 3 transient counters. Eviction is per redis INSTANCE
(`allkeys-lru`), never per database - see backlog B-105.

**`clear()` runs on every database transaction rollback.** A rollback can undo a write the
cache already memoized, and a stale memo hands application code a confidently wrong answer -
the `_type_refs` defect recorded in `../test_runner/issues_encountered.md`. So the flush is
unconditional (`Transaction_Rollback_Cache_Reset`), and anything that must survive it is not
cache: counters are on DB 3, and a cache key that must outlive the resets carries the
internal `_RVC_` prefix that routes it to DB 2.

`clear()` is implemented as SCAN + DEL, not `FLUSHDB`: the shipped `redis.conf` disables
`FLUSHDB` and `FLUSHALL` outright, so the old `flushDb()` call cleared nothing, silently.

**Every key is scoped on the build AND the database.** A cache key is
`cache:<Rsx_Connection_Scope::token()>:<sha1 of build key + user key>`, and a counter key is
`counter:<token>:<sha1>` - the same `(database, host)` token `RsxLocks` and
`Task_Worker_Registry` use. So the developer's cache and a test run's cache are disjoint
keyspaces, and `clear()` MATCHes only the calling scope's prefix (the realtime `rsx_rt:*`
keys and `AssetHandler`'s `rspade:public_asset:*` keys therefore survive a cache reset).
The token is read from the LIVE connection and never memoized: the boolean it replaced
(`config('database.default') === 'test'`) answered NO in an artisan child spawned with
`DB_DATABASE` at the test database, which is how that child wrote the test database's
`_type_refs` map into the developer's `type_refs_map` key.

**Transient counters** (`Rsx_Counter`) are raw numeric values on DB 3, not build-scoped (a
counter measures real time and must survive a manifest rebuild) but carrying the same
database scope. The window is FIXED: the expiry is applied by the increment that
CREATES the key and by no other, so a slow attacker cannot hold a counter alive forever.

## Source under test

- `system/app/RSpade/Core/Cache/RsxCache.php`
- `system/app/RSpade/Core/Cache/Rsx_Counter.php`
- `system/app/RSpade/Core/Database/Lifecycle/Transaction_Rollback_Cache_Reset.php`
- `system/app/RSpade/Core/Database/Rsx_Connection_Scope.php` (the scope token)

## Behavior definitions

- `system/app/RSpade/Core/REDIS_USAGE.md` (cache API overview)
- `rsx:man caching` (the database map, the rollback flush, the `_RVC_` reservation)
- `rsx:man maintenance_mode` (the degraded-mode contract - reads miss, writes drop with one
  warning per process, counters return 0 without reaching redis)

## Applicability

Redis must be running. Under maintenance mode every entry point short-circuits before
touching redis; that degraded contract is owned by the `maintenance` concern
(`Maintenance_Redis_Tolerance_Test`) and is deliberately NOT duplicated here, except for the
counter store's own copy of it.

## Testable surface

| Area | Type | Covered |
|------|------|---------|
| Serialized round trip incl. boolean false and numeric strings | php | yes |
| Corrupt payload fails loud | php | yes |
| Counter: creation window, no sliding, amount, bad window, read, reset | php | yes |
| Counter: flag value + expiry; invisible to the cache; survives `clear()` | php | yes |
| Counter degraded (maintenance) behavior | php | yes |
| `_RVC_` routing: survives `clear()`, lives on DB 2, still build-scoped | php | yes |
| Rollback flush: type ref + plain cache key | php | yes |
| Database scoping: key prefix, cross-scope invisibility, scoped `clear()`, counters | php | yes |
| Cache degraded (maintenance) behavior | php | in `maintenance` concern |
| `remember()` stampede lock | php | no - see catalog |
