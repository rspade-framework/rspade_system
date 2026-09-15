# storage_isolation - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| STORAGE-ISO-DEFAULT-PATHS | default mode (override cleared) roots equal the path owner's own answers exactly - blob_root() is <storage>/uploads, the two caches are the tmp caches | php | files override temporarily cleared | blob root under storage/, thumbnail and rendition roots under tmp/; get_full_path == <storage>/get_storage_path() | implemented | 2026-09-15 |
| STORAGE-ISO-OVERRIDE-PATHS | the run's override relocates the BLOB store under tmp/test-storage; the two caches do not move with it, because a run has nothing to lose in a directory it can regenerate | php | run-active override | blob root under the override, caches under tmp/ | implemented | 2026-09-15 |
| STORAGE-ISO-BLOB-LIFECYCLE | a blob authored in a run is written under the test root (never the real store); a delete unlinks the test-root file only | php | unique bytes -> store_blob + linked attachment -> delete | blob under tmp/test-storage, absent from real store; delete removes test-root file; real store untouched | implemented | 2026-07-21 |
| STORAGE-ISO-CACHE-PATHS | the thumbnail and rendition cache-path seams resolve under tmp/ | php | _get_cache_path + rendition_cache_path | paths under tmp/thumbnails and tmp/renditions | implemented | 2026-09-15 |
| STORAGE-ISO-REAL-STORE-UNTOUCHED | a full framework suite run adds/deletes NOTHING in the real blob store | php | before/after `find storage/uploads -type f` around a full run | empty diff | verified out-of-band (B-38 acceptance evidence); not a discrete in-suite test | 2026-07-21 |
