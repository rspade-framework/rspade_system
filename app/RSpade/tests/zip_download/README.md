# Concern: zip_download

## Domain overview & applicability

The framework serves a streamed, multi-file ZIP download of an arbitrary set of
attachments through a database-backed request flow, backed by a hand-rolled streaming ZIP
writer (`Zip_Stream`) that needs no composer or apt dependency. The writer emits each
member with constant memory (chunked read, incremental CRC-32, incremental raw deflate)
and closes each member with a data descriptor, so a source of unknown/large size streams
without buffering.

The flow is two-step: server-side app code records the file set with
`Zip_Download_Request_Model::create_request($files, $zip_name)` (structure validated
fail-loud, nothing authorized), receives an opaque `download_key`, and hands the browser
`get_download_url()`; the browser navigates to `GET /_download_zip/:key`, which streams
the archive. Requests are NOT consumed on use; they die by expiry
(`config('rsx.attachments.zip_request_retention_hours')`, default 24), enforced at serve
time (`is_expired()`) and pruned every six hours by `Zip_Download_Cleanup_Service`.

The endpoint validates EVERYTHING before streaming: the request resolves and is not
expired, every entry passes BOTH the thumbnail and download authorization gates (identical
cascade to `download_file()`, against the DOWNLOADING session), and the archive fits
inside the non-ZIP64 envelope (4GB / 65k entries). An unknown or expired key throws one
opaque invalid/expired message; a single denied file fails the whole request with one
opaque message BEFORE any bytes stream (no partial archive). During streaming, a member
whose bytes cannot be resolved (external fetch failed, blob missing) or whose stream
truncates is replaced by a zero-byte `~ERROR~<name>.inf` marker (directory prefix
preserved) and logged, keeping the archive well-formed.

This concern matters for correctness (a non-conformant archive is silently useless), for
security (the auth cascade and the opaque denial), and for the memory-safety promise of
streaming a large set.

## Source files

- `app/RSpade/Core/Files/Zip_Stream.php` - the streaming ZIP container writer
  (`#[Instantiatable]`; per-archive state). PKWARE local header + data descriptor +
  central directory + EOCD; store-vs-deflate per mime; ZIP64 defensively guarded.
- `app/RSpade/Core/Files/File_Attachment_Controller.php` -
  `download_multiple_zip()` (the endpoint) plus the pure seams
  `_sanitize_zip_entry_name`, `_dedupe_zip_names`, `_error_marker_name`,
  `_sanitize_zip_filename`, `_zip64_limit_error`.
- `app/RSpade/Core/Files/Zip_Download_Request_Model.php` - the minted download request
  (`_zip_download_requests`): `create_request()` (fail-loud structure validation),
  `find_by_download_key()`, `get_download_url()`, `is_expired()`, `get_files()`.
- `app/RSpade/Core/Files/Zip_Download_Cleanup_Service.php` - the six-hourly chunked
  retention prune of expired requests (`#[Exclusive]`).

## Man page(s)

- `man/file_upload.txt` (MULTI-FILE ZIP DOWNLOAD section)
- `Core/Files/CLAUDE.md` (endpoints list + terse paragraph)

## Testable surface

- Request model: `create_request()` persists a row with a 64-hex key and JSON-round-trips
  the file set + zip_name; the fail-loud structure matrix (empty / non-array entry /
  missing key / non-string key / non-string name / unexpected entry key) throws;
  `find_by_download_key()` hit/miss; `is_expired()` false when fresh, true when backdated
  past the window; `get_download_url()` carries the key + the `/_download_zip` prefix. (php)
- Cleanup task: seeded fresh + stale rows, run the task directly - stale deleted, fresh
  kept; the configured window is honored; a backlog clears across chunks. (php)
- Writer conformance: two-DEFLATE-member archive opens under `ZipArchive::CHECKCONS`,
  entries readable, content roundtrips, `unzip -t` passes (CRC). (php)
- Store selection: a store-mime member (image/jpeg) is CM_STORE with comp == size. (php)
- Marker: an empty entry is a valid zero-byte member. (php)
- UTF-8 name + directory prefix survive verbatim. (php)
- Large member: a 3MB source streams with a peak-memory delta far below the file size
  (constant memory). (php)
- Mixed archive (deflate + store + marker) validates end to end. (php)
- Name sanitize matrix: null/custom/leading-slash/dup-slash/backslash/traversal/control/
  empty -> expected archive name or fallback. (php - pure seam)
- Cross-set dedup: numbered suffix before the extension, directory prefix preserved,
  no-extension names, distinct names untouched. (php - pure seam)
- Error-marker naming: plain + directory-prefixed. (php - pure seam)
- Download filename sanitizer: default, suffix, path strip, quote strip. (php - pure seam)
- ZIP64 guard: within limits -> null; too many entries / single-file too large / total
  over 4GB -> error. (php - pure seam)
- Full HTTP round-trip (mint via `create_request()` then GET `/_download_zip/:key`, auth
  cascade, streaming headers incl. X-Accel-Buffering, byte-identical extraction, dedup
  over the wire, the invalid/expired-key path and the denial path with no partial bytes,
  the live marker path). (http - deferred; verified on the dev box during development)

## Documents

- `test_catalog.md` - full catalog (implemented + deferred).
