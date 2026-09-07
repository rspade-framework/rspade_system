# storage_isolation - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| STORAGE-ISO-DEFAULT-PATHS | default mode (override cleared) roots equal the storage_path('...') layout exactly - blob_root() is storage_path('uploads') | php | config override temporarily null | blob/thumb/rendition roots == storage_path forms; get_full_path == storage_path(get_storage_path) | implemented | 2026-08-24 |
| STORAGE-ISO-OVERRIDE-PATHS | the run's override relocates every root under storage/rsx-tmp/test-storage | php | run-active config | all roots under the override | implemented | 2026-07-21 |
| STORAGE-ISO-BLOB-LIFECYCLE | a blob authored in a run is written under the test root (never the real store); a delete unlinks the test-root file only | php | unique bytes -> store_blob + linked attachment -> delete | blob under /rsx-tmp/test-storage, absent from real store; delete removes test-root file; real store untouched | implemented | 2026-07-21 |
| STORAGE-ISO-CACHE-PATHS | thumbnail + rendition cache-path seams honor the override | php | _get_cache_path + rendition_cache_path | paths under the override roots | implemented | 2026-07-21 |
| STORAGE-ISO-REAL-STORE-UNTOUCHED | a full framework suite run adds/deletes NOTHING in the real blob store | php | before/after `find storage/uploads -type f` around a full run | empty diff | verified out-of-band (B-38 acceptance evidence); not a discrete in-suite test | 2026-07-21 |
