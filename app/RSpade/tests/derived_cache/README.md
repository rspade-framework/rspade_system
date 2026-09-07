# Concern: derived_cache

## Domain

`App\RSpade\Core\Cache\File_Content_Cache` - the ONE way a per-source-file DERIVED ARTIFACT
is cached on disk, and the tree it owns:

```
storage/rsx-tmp/derived/<namespace>/<hash><variant>.<ext>
```

**One key scheme.** `<hash>` is `_rsx_file_hash_for_build()`, the framework's single
file-identity helper - development: absolute path + size + mtime (cheap, local); production
and debug: the project-RELATIVE path plus the content, so a derived name is identical in two
byte-identical checkouts and a sealed build stays deterministic. A cache does not get an
opinion about this. The one exception is spelled as such: `put_for_hash()` / `get_for_hash()`
take an EXPLICIT hash, which the PHP reflection cache uses because it already keyed on the
manifest's own sha1 and that key was right.

**The variant is the premise.** Anything the artifact depends on beyond the source bytes - a
compile target, a toolchain fingerprint, a parser version - goes in `$variant`. That is what
makes a toolchain upgrade retire the entries it invalidates instead of serving the old tool's
work under a key that never moved; the jqhtml parser upgrade that kept being served from a
cache keyed on a path and an mtime is the incident that rule comes from.

**Nothing expires on a clock.** `sweep($namespace, $live_hashes)` removes entries whose hash
is in no live set, and is called ONCE, from the manifest build's Phase 7, because that is the
only moment the framework holds a complete answer to "which source files still exist".
Building the manifest just to prune would cost more than the bytes it reclaims. An EMPTY live
set removes nothing: "I could not work out what is live" and "nothing is live" are different
statements. `rsx:clean` wipes `rsx-tmp` wholesale and needs no wiring at all.

**Writes are atomic.** `put()` goes through `file_put_contents_safe()`, which stages and
renames, so a reader never sees a half-written entry and a build killed mid-write leaves no
torn file behind for the next build to serve as a hit.

## Why this concern exists

Before the consolidation the framework had nine hand-rolled per-source-file caches: four
different answers to "what is the key" (md5 of the absolute path, the escaped relative path,
the build hash, the manifest sha1), four different staleness tests (mtime comparison, mtime
in the filename, size+mtime inside the document, none at all), one directory each, and one
private `cleanup_old_cache()`. 7885 transient files after a build, 96% of them one entry per
source file. This concern is the regression suite for the single replacement: the properties
each of those schemes had to get right on its own, proven once.

## What is NOT here

Per-BUNDLE artifacts (`bundle_*.js`, `scss_*.css`) are not derived-cache entries - they are
keyed on a whole include set, not on one source file, and they stay where they are. Verdict
caching ("this file already passed this check") is the other half of the same cleanup and
lives in `Validation_Ledger`, tested in the `code_quality` concern.

## Running

```bash
php artisan rsx:test --framework --group=derived_cache
```
