# Test Catalog: zip_download

Full catalog of tests worth having for the streamed multi-file ZIP download (implemented
and deferred). Implemented php tests live in `php/Zip_Stream_Test.php` (writer),
`php/Zip_Download_Naming_Test.php` (endpoint seams, no database),
`php/Zip_Download_Request_Test.php` (the minted request model) and
`php/Zip_Download_Cleanup_Test.php` (the retention task) - the latter two use the DB.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| ZS-01 | Two DEFLATE members: valid archive + readable | php | 2 text files | ZipArchive CHECKCONS ok, 2 entries | implemented | 2026-07-23 |
| ZS-02 | Two DEFLATE members: content + CRC | php | 2 text files | content roundtrips, `unzip -t` ok | implemented | 2026-07-23 |
| ZS-03 | Store-mime member is stored | php | image/jpeg 20k | CM_STORE, comp==size, roundtrip | implemented | 2026-07-23 |
| ZS-04 | Empty marker entry is zero-byte | php | add_empty_entry | 1 entry, size 0, valid | implemented | 2026-07-23 |
| ZS-05 | UTF-8 name + directory prefix | php | `proyecto/informe_espanol_n~.txt` | name preserved, roundtrip | implemented | 2026-07-23 |
| ZS-06 | Large member constant memory | php | 3MB source | peak delta < 2MB, roundtrip, `unzip -t` ok | implemented | 2026-07-23 |
| ZS-07 | Mixed archive end to end | php | deflate + store + marker | 3 entries, `unzip -t` ok | implemented | 2026-07-23 |
| ZN-01 | Entry name null uses fallback | php | (null), `report.pdf` | `report.pdf` | implemented | 2026-07-23 |
| ZN-02 | Custom directory prefix kept | php | `docs/renamed.pdf` | `docs/renamed.pdf` | implemented | 2026-07-23 |
| ZN-03 | Leading slash stripped | php | `/a/b.txt` | `a/b.txt` | implemented | 2026-07-23 |
| ZN-04 | Duplicate slashes collapsed | php | `a//b///c.txt` | `a/b/c.txt` | implemented | 2026-07-23 |
| ZN-05 | Backslash degrades to fallback | php | `a\b.txt` | fallback | implemented | 2026-07-23 |
| ZN-06 | Traversal degrades to fallback | php | `../etc/passwd` | fallback | implemented | 2026-07-23 |
| ZN-07 | Control char degrades to fallback | php | `a\nb.txt` | fallback | implemented | 2026-07-23 |
| ZN-08 | Empty after cleaning uses fallback | php | `///` | fallback | implemented | 2026-07-23 |
| ZN-09 | Fallback reduced to bare filename | php | (null), `/evil/../x.pdf` | `x.pdf` | implemented | 2026-07-23 |
| ZN-10 | Dedup numbered suffix before ext | php | `report.pdf` x3 | `report.pdf`, `report (2).pdf`, `report (3).pdf` | implemented | 2026-07-23 |
| ZN-11 | Dedup preserves directory prefix | php | `d/a.txt` x2 | `d/a.txt`, `d/a (2).txt` | implemented | 2026-07-23 |
| ZN-12 | Dedup name without extension | php | `README` x2 | `README`, `README (2)` | implemented | 2026-07-23 |
| ZN-13 | Distinct names unchanged | php | `a.txt`,`b.txt`,`c.txt` | unchanged | implemented | 2026-07-23 |
| ZN-14 | Error marker plain name | php | `q2.pdf` | `~ERROR~q2.pdf.inf` | implemented | 2026-07-23 |
| ZN-15 | Error marker directory prefix | php | `reports/q2.pdf` | `reports/~ERROR~q2.pdf.inf` | implemented | 2026-07-23 |
| ZN-16 | Zip filename default when empty | php | null / `''` | `download.zip` | implemented | 2026-07-23 |
| ZN-17 | Zip filename suffix added | php | `myfiles` | `myfiles.zip` | implemented | 2026-07-23 |
| ZN-18 | Zip filename path stripped | php | `/etc/foo.zip` | `foo.zip` | implemented | 2026-07-23 |
| ZN-19 | Zip filename quotes stripped | php | `a"b.zip` | `ab.zip` | implemented | 2026-07-23 |
| ZN-20 | ZIP64 within limits -> null | php | `[100,200,300]` | null | implemented | 2026-07-23 |
| ZN-21 | ZIP64 too many entries | php | 65001 entries | error message | implemented | 2026-07-23 |
| ZN-22 | ZIP64 single file too large | php | `[0xFFFFFFFF]` | error message | implemented | 2026-07-23 |
| ZN-23 | ZIP64 total over 4GB | php | `[2e9, 2e9+1]` | error message | implemented | 2026-07-23 |
| ZR-01 | create_request persists row + hex key + files roundtrip | php | 2 entries + zip_name | id set, 64-hex key, zip_name stored, get_files() equals input | implemented | 2026-07-23 |
| ZR-02 | create_request allows null zip_name | php | 1 entry, null | zip_name null | implemented | 2026-07-23 |
| ZR-03 | create_request rejects empty array | php | `[]` | RuntimeException | implemented | 2026-07-23 |
| ZR-04 | create_request rejects non-array entry | php | `['x']` | RuntimeException | implemented | 2026-07-23 |
| ZR-05 | create_request rejects missing key | php | `[{name}]` | RuntimeException | implemented | 2026-07-23 |
| ZR-06 | create_request rejects non-string key | php | `[{key:123}]` | RuntimeException | implemented | 2026-07-23 |
| ZR-07 | create_request rejects non-string name | php | `[{key,name:42}]` | RuntimeException | implemented | 2026-07-23 |
| ZR-08 | create_request rejects unexpected entry key | php | `[{key,extra}]` | RuntimeException | implemented | 2026-07-23 |
| ZR-09 | find_by_download_key hit | php | minted key | resolves same row | implemented | 2026-07-23 |
| ZR-10 | find_by_download_key miss | php | 64 zeros | null | implemented | 2026-07-23 |
| ZR-11 | is_expired false when fresh | php | just created | false | implemented | 2026-07-23 |
| ZR-12 | is_expired true when backdated past window | php | created_at -25h | true | implemented | 2026-07-23 |
| ZR-13 | get_download_url carries key + prefix | php | minted request | contains `/_download_zip` + key | implemented | 2026-07-23 |
| ZC-01 | Cleanup deletes expired keeps fresh (default 24h) | php | rows at 30h,30h,1h | 2 deleted, fresh survives, retention_hours=24 | implemented | 2026-07-23 |
| ZC-02 | Cleanup respects configured window | php | window 6h, rows at 10h,1h | old deleted, fresh survives | implemented | 2026-07-23 |
| ZC-03 | Cleanup clears a backlog across chunks | php | 12 stale rows, chunk 5 | all deleted | implemented | 2026-07-23 |
| ZH-01 | Authed round-trip streams a valid zip | http | mint via create_request + GET key + staff cookie | 200 application/zip, `unzip -t` ok, byte-identical | deferred (verified on dev box; needs a minted staff session) | 2026-07-23 |
| ZH-02 | Custom path + dedup honored over the wire | http | request with a custom name + colliding names | archive names match | deferred (verified on dev box) | 2026-07-23 |
| ZH-03 | Denied file -> 500, no partial bytes | http | one bogus key in the request | 500 error page, no zip magic | deferred (verified on dev box) | 2026-07-23 |
| ZH-04 | Missing blob -> `~ERROR~` marker | http | attachment whose blob was moved aside | marker entry present, archive valid | deferred (verified on dev box) | 2026-07-23 |
| ZH-05 | Unknown/expired key -> 500 invalid/expired, no zip | http | bogus key; backdated request | 500 error page, no zip magic | deferred (verified on dev box) | 2026-07-23 |

## Notes

- ZH-01..ZH-04 require a logged-in STAFF session (the template app's
  `file.download.authorize` handler allows any staff user); minting one over HTTP is not
  yet part of the shared `_lib` harness, so the http rows are deferred. All four were
  exercised end to end on the dev box during development (byte-identical `cmp`, denial
  with no partial zip, and a live moved-blob marker), and the branch logic behind them is
  pinned in-process by the ZS-* and ZN-* rows.
- ZS-06's memory assertion uses `memory_get_peak_usage()` delta across the 3MB stream; a
  buffering (non-streaming) implementation would spike peak by roughly the file size, so
  a sub-2MB delta is the streaming signal.
