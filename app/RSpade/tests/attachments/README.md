# Concern: attachments (external handlers + thumbnail renderers)

## Domain

Related framework capabilities on the file-attachment subsystem:

1. **External byte-residency handlers (WP-A)** - an attachment's bytes may live in an external
   system (`handler_class` + `handler_ref`, nullable `file_storage_id`) and materialize on demand
   into the local content-addressed blob store. `resolve_storage()` is the single byte-access choke
   point; `store_blob`/`materialize`/`relink_storage`/`evict_blob`/`create_external` are the pinned
   public APIs. Rolls in the `mime_type` persistence fix and attachment-level `file_size`.

2. **Thumbnail renderer registry** - the "produce a raster from bytes" step of the thumbnail
   pipeline is pluggable by mime type (`config('rsx.thumbnails.renderers')`). Ships
   `Imagick_Thumbnail_Renderer` (images + PDF, byte-identical to the historic path). Office
   documents are deliberately NOT in the registry: their pixels come from the PDF rendition the
   background render worker produces (tests/documents). `has_thumbnail()` reflects the registry
   AND the blob's render state.

3. **Unparseable-image degrade + extension allowlist** - an uploaded image whose BYTES fail to
   parse (ImageMagick chokes on the CONTENT, distinct from a missing binary which still fatals) is,
   by default, ACCEPTED and DEGRADED to a generic non-previewable file (`preview_unavailable=1`,
   `file_type_id=OTHER`, `pipeline_mime()` -> `application/octet-stream`, null dims, raw `mime_type`
   column untouched). `config('rsx.attachments.reject_unparseable_images')=true` flips it to a hard
   reject (`Unparseable_Upload_Exception`, endpoint 422, orphan row cleaned up). Plus the
   developer-facing `is_allowed_extension()` allowlist helper (`allowed_extensions` config; []=all).

4. **Mandatory upload gate** - `POST /_upload` refuses to run when NO handler is registered for
   `file.upload.authorize`: it throws a `RuntimeException` (5xx - the application is misconfigured,
   the client did nothing wrong) naming `rsx:man file_upload`. `Rsx::trigger_gate()`'s general
   no-handler-default-true semantics are UNCHANGED; the endpoint asks
   `Event_Registry::has_handlers()` itself. File presence/validity are checked BEFORE the gate so
   the payload can carry the file (`file`, `filename`, `size`, `mime_type`, `extension`,
   `tmp_path`) alongside the original `request`/`user`/`params`, letting a handler inspect the real
   bytes; `user` is realm-honest (portal user on a portal request).

5. **Attachment ownership (session_id retired)** - `_file_attachments.session_id` is DROPPED.
   `can_user_assign_this_file()` keeps two structural checks - not already attached (single
   claim) + site match - and defers WHO may claim to the app's own authorization; the old
   session comparison read the STAFF facade unconditionally, so a portal upload matched only by
   accident. `created_by_ip_address` (VARCHAR(45), NULL outside a request) is audit metadata,
   never a guard. The bound the session stamp implied is now explicit: an unattached upload is
   claimable for `rsx.attachments.unattached_claim_window_hours` (24), after which
   `File_Disposal_Service::sweep_unclaimed_uploads` (every 6 hours) SOFT-deletes it into the
   normal retention window.

6. **Record-side attachment readers** - the helpers on `Rsx_Model_Abstract` that answer
   "which attachments belong to this record". `find_attachment($id_or_key, $category)` is the
   OWNERSHIP RE-VERIFICATION step an endpoint must use when the CALLER named the attachment:
   `File_Attachment_Model::find()`/`find_by_key()` are tenant-scoped and nothing more, so within
   one site they will return an attachment hanging off a DIFFERENT record. A miss and a mismatch
   are the same `null` (anti-enumeration), and only LIVE rows are found. `get_all_attachments()`
   is the category-less reader - every attachment on a record across every category, with no
   limiter - for the cases that cannot enumerate categories up front (delete cascades, exports,
   audits). On a site-scoped record every reader is additionally pinned to the current session's
   site, so they stay tenant-correct inside a `without_site_scope()` callback, which suspends the
   ordinary global scope for every site model at once.

## Source under test

- `system/app/RSpade/Core/Files/File_Attachment_Model.php` (residency APIs, ingest metadata)
- `system/app/RSpade/Core/Files/File_Storage_Model.php` (`store_blob`)
- `system/app/RSpade/Core/Files/File_Attachment_Controller.php` (upload gate, serve paths, renderer registry)
- `system/app/RSpade/Core/Events/Event_Registry.php` (`has_handlers()` + the test-handler seam)
- `system/app/RSpade/Core/Files/Rsx_Attachment_Handler_Abstract.php`
- `system/app/RSpade/Core/Files/Rsx_Thumbnail_Renderer_Abstract.php` + Imagick/LibreOffice renderers
- `system/app/RSpade/Commands/Rsx/RsxStorageCleanupCommand.php` (orphan sweep)
- `system/app/RSpade/Core/Files/File_Disposal_Service.php` (`sweep_unclaimed_uploads`)
- `system/app/RSpade/Core/Session/Session.php` (`get_client_ip()`)
- `system/app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` (the record-side readers + the
  `__attachment_query()` seam they all narrow)
- `system/app/RSpade/Core/Database/Models/Rsx_Site_Model_Abstract.php` (the tenant pin on that seam)

## Man pages

- `php artisan rsx:man file_upload`
- `php artisan rsx:man storage`
- `php artisan rsx:man libreoffice`

## Testable surface

- php: mandatory-gate refusal + enriched gate payload + denial passthrough + gate ordering,
  residency lifecycle, materialize/evict/relink round-trips, allowlist enforcement, renderer
  dispatch + icon fallback, `has_thumbnail`, LibreOffice real render (skips if soffice absent),
  unparseable-image degrade (default) + strict reject (no orphan), extension allowlist API,
  well-formed-image positive control, claim-guard semantics + created_by_ip_address stamping +
  the claim-window sweep (expired/attached/handler-backed/in-window/disabled).
  Plus the record-side readers: `find_attachment()` by id and by key, its four null cases
  (foreign record, wrong category, unattached, soft-deleted), garbage identifiers, the all-digits
  key that must not be coerced to an id, and `get_all_attachments()` spanning categories while
  excluding soft-deleted rows.
  Plus blob ingest when the row outlives its file: re-storing the bytes of a record whose blob
  was removed from disk rewrites them under that SAME record (the row and its attachments stay
  valid, and the uniquely-indexed hash is never inserted twice), corrects a stale recorded size,
  and leaves an intact blob's dedup path untouched.
- http (not yet implemented): real Content-Type headers on `/_download` / `/_inline`; thumbnail
  endpoints for external attachments over the wire.

## Fixtures

`Attachment_Fixture_Handler` (deterministic PNG bytes + fetch counter), `Attachment_Fixture_Fresh_Handler`
(freshness opt-in), `Attachment_Fixture_Renderer` (dispatch counter + throw toggle). Registered at
runtime via `config([...])` in each test's `setup()`.

Upload-gate tests use the `Event_Registry` test seam (`_set_test_handlers()` /
`_clear_test_handlers()`) instead of a fixture `#[OnEvent]` class: a fixture handler declared on
the REAL `file.upload.authorize` event would be manifest-discovered and would silently gate the
running application's uploads. Every test method clears its overrides in a `finally` block, with
`teardown()` as the class-level backstop, so the global registry is never left polluted.
