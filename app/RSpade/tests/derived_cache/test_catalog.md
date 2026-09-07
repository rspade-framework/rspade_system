# Test catalog: derived_cache

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| DC-01 | The path is `derived/<namespace>/<hash><variant>.<ext>`, and a separator in a variant cannot escape the namespace directory | php | source file + variant `_modern_abc`, then `_../../etc` | `<hash>_modern_abc.js` under `rsx-tmp/derived/<ns>/`; no `..` in the escaped form | implemented | 2026-09-07 |
| DC-02 | put/get/forget roundtrip: what was stored comes back byte for byte, what was forgotten is gone | php | store `DERIVED-B` | miss -> hit -> miss | implemented | 2026-09-07 |
| DC-03 | The `*_for_hash` twins address an entry under an EXPLICIT hash (the shape the reflection cache uses) | php | `put_for_hash('a-manifest-sha1', ...)` | read back; unknown hash misses | implemented | 2026-09-07 |
| DC-04 | A CHANGED source misses - the derived NAME moves with the file, so there is no staleness comparison to get wrong | php | rewrite content and bump mtime | `get()` returns null | implemented | 2026-09-07 |
| DC-05 | The optional source-mtime guard refuses an entry older than its source (the extra test two migrated caches had) | php | age the entry behind the source | hit without the guard, miss with it | implemented | 2026-09-07 |
| DC-06 | Variant separation: two variants of one source are two entries, and a moved fingerprint is a MISS rather than a stale hit | php | `_modern_v1`, `_es5_v1`, `_modern_v2` | own artifact each; null for the unseen variant | implemented | 2026-09-07 |
| DC-07 | `sweep()` removes only entries whose hash is in no live set, keeps EVERY variant of a live hash, and treats an empty live set as "unknown" | php | one live + one dead source, three entries | 0 removed for `[]`; 1 removed for `[live]`; both live variants survive | implemented | 2026-09-07 |
| DC-08 | A partial write is never visible - the entry is REPLACED by rename, not truncated in place | php | 200KB artifact overwritten by a different 200KB artifact | inode changes; full new content, no truncated tail | implemented | 2026-09-07 |
| DC-09 | The manifest build's Phase 7 sweep removes entries for files the manifest no longer knows | php | delete a source, rebuild | entry gone from every namespace | planned - needs a full-rebuild harness | 2026-09-07 |
