# temp_files - test catalog

## Rsx_Temp_Files_Test (php, $requires_db_reset + no-tx) - the temp file store

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| tmp-01 | store_bytes writes under a random key, outside the blob store | bytes | 32-hex key, not the sha256; path uploads/_temp/<2>/<key>; bytes, name, sniffed type, size; nothing in _file_storage; expires in 7 days; download response; identical bytes are a second file | implemented |
| tmp-02 | store_file copies, with the lifetime the caller chose | a file, 1 day; 0 days | bytes and given type; source kept; expires within a day; 0 throws | implemented |
| tmp-03 | find and delete | live, unknown key, expired | found; null; null; delete removes row and bytes | implemented |
| tmp-04 | the sweep deletes only expired files it has rows for | expired, live, and a 400-day-old file with no row | expired deleted; live kept; the row-less file untouched | implemented |
| tmp-05 | a bad retention setting throws | 0, -1, 'seven' | RuntimeException naming the key | implemented |
