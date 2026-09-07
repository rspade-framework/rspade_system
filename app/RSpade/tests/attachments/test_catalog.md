# Test Catalog: attachments

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| ATT-01 | Plain attachment unchanged: metadata populated, thumbnail renders WebP, delete sweeps storage | php | resident PNG | mime/file_size set, WebP, storage removed on delete | implemented | 2026-07-02 |
| ATT-02 | External attachment metadata works with no resident blob | php | create_external | get_size/mime from columns, fetch_count 0 | implemented | 2026-07-02 |
| ATT-03 | First byte access materializes once; key + URLs stable | php | external + resolve_storage x2 | fetch_count 1, blob linked, key stable | implemented | 2026-07-02 |
| ATT-04 | First thumbnail request materializes + renders | php | external + _render_thumbnail_data | materialized, WebP produced | implemented | 2026-07-02 |
| ATT-05 | evict -> cleanup sweeps orphan blob -> re-materialize | php | evict + rsx:storage:cleanup | storage row+file gone, re-fetch on next access | implemented | 2026-07-02 |
| ATT-06 | relink to new content re-extracts dims/mime; hash changes (cache self-invalidates) | php | relink 8x8 -> 16x16 | width 16, file_size updated, hash differs | implemented | 2026-07-02 |
| ATT-07 | Unregistered handler_class on a byte path fails loud; nothing served | php | forced bogus handler | RuntimeException | implemented | 2026-07-02 |
| ATT-08 | evict on a handler-less attachment throws | php | plain + evict_blob | Rsx_Caller_Exception | implemented | 2026-07-02 |
| ATT-09 | create_external + add_to appears in fileable listing | php | add_to + forModel | listing has the attachment | implemented | 2026-07-02 |
| ATT-10 | apply_serve_freshness evicts stale, re-materializes fresh | php | fresh handler + stale flag | evicted then re-fetched | implemented | 2026-07-02 |
| REN-01 | Registered renderer is dispatched for its mime | php | fixture mime | render_count 1, WebP | implemented | 2026-07-02 |
| REN-02 | Renderer failure falls back to extension icon (no throw escapes) | php | fixture throws | icon WebP, attempt logged | implemented | 2026-07-02 |
| REN-03 | has_thumbnail reflects the registry (image/* true) | php | various mimes | true/true/false | implemented | 2026-07-02 |
| REN-04 | image/* maps to the Imagick renderer (byte-identical path) | php | image/jpeg | Imagick_Thumbnail_Renderer | implemented | 2026-07-02 |
| REN-05 | LibreOffice master switch: disabled -> office mime has no renderer | php | toggle rsx.libreoffice.enabled | LibreOffice / null | implemented | 2026-07-02 |
| REN-06 | LibreOffice actually rasterizes a document (when soffice present) | php | txt source | Imagick raster, width > 0 | implemented | 2026-07-02 |
| DEG-01 | Unparseable image (default) degrades: preview_unavailable, OTHER, null dims, pipeline octet-stream, is_image/is_preview_available false, raw mime_type kept | php | truncated PNG via create_from_upload | degraded generic file, serve mime intact | implemented | 2026-08-03 |
| DEG-02 | Strict mode rejects the unparseable image AND leaves no orphan attachment row | php | truncated PNG + reject_unparseable_images=true | Unparseable_Upload_Exception, row count unchanged | implemented | 2026-08-03 |
| DEG-03 | is_allowed_extension: []=allow-all; populated list is case-insensitive + dot-tolerant + denies others | php | config allowlist matrix | true/false per case | implemented | 2026-08-03 |
| DEG-04 | Positive control: a well-formed PNG still processes normally (not flagged, IMAGE, dims, is_image) | php | valid PNG via create_from_upload | preview_unavailable=0, IMAGE, 8x8 | implemented | 2026-08-03 |
| GATE-01 | Mandatory gate: NO file.upload.authorize handler registered -> upload throws RuntimeException naming rsx:man file_upload, stores nothing | php | Event_Registry override to [] | RuntimeException, attachment count unchanged | implemented | 2026-08-09 |
| GATE-02 | The misconfiguration message names the gate and says uploads are DISABLED (not a bad request) | php | Event_Registry override to [] | message contains file.upload.authorize + "disabled" | implemented | 2026-08-09 |
| GATE-03 | Registered handler returning true lets the upload proceed unchanged | php | allow handler + PNG | 200, success, key + file_name returned | implemented | 2026-08-09 |
| GATE-04 | Gate payload keeps request/user/params AND carries file/filename/size/mime_type/extension/tmp_path; tmp_path yields the real bytes | php | capturing handler + PNG | all keys correct, tmp_path bytes match | implemented | 2026-08-09 |
| GATE-05 | Handler returning a response halts the upload with that exact response; nothing stored | php | handler returning 403 JSON | 403 + handler payload, attachment count unchanged | implemented | 2026-08-09 |
| GATE-06 | Gate fires only AFTER file presence/validity: a fileless POST is 400 and never reaches a handler | php | POST with no file | 400, handler not invoked | implemented | 2026-08-09 |
| OWN-01 | Claim guard passes for an unattached same-site row; _file_attachments.session_id no longer exists | php | fresh upload + SHOW COLUMNS | claimable, no session_id column | implemented | 2026-08-09 |
| OWN-02 | Single claim: an already-attached row can never be re-claimed | php | attach_to then guard | false | implemented | 2026-08-09 |
| OWN-03 | Tenant isolation: a row from another site is not claimable | php | session site switched | false | implemented | 2026-08-09 |
| OWN-04 | No request context -> no IP stamped, no error, no session minted | php | create_from_string in CLI | get_client_ip() null, column NULL | implemented | 2026-08-09 |
| OWN-05 | created_by_ip_address round-trips a full-length IPv6 address | php | 39-char IPv6 write | value read back intact | implemented | 2026-08-09 |
| OWN-06 | Claim-window sweep soft-deletes an expired unattached upload into RETENTION (blob still pinned) | php | backdated 48h, window 24h | deleted_at set, destroyed_at null, storage row alive | implemented | 2026-08-09 |
| OWN-07 | Sweep spares attached rows, in-window rows, and handler-backed rows | php | three fixtures, one sweep | none soft-deleted | implemented | 2026-08-09 |
| OWN-08 | Window of 0 disables the sweep entirely | php | window 0, year-old row | swept 0, row alive | implemented | 2026-08-09 |
| RDR-01 | find_attachment resolves by numeric id and by 64-hex key | php | attached row, both spellings | the same row both ways | implemented | 2026-08-27 |
| RDR-02 | An attachment of ANOTHER record is not found, even by a valid same-tenant id | php | other record + valid id | null | implemented | 2026-08-27 |
| RDR-03 | Right record, wrong category is not found | php | mismatched category | null | implemented | 2026-08-27 |
| RDR-04 | An unattached upload is not found through any record | php | unclaimed row | null | implemented | 2026-08-27 |
| RDR-05 | A soft-deleted attachment is not found (live records only) | php | attached then deleted | null | implemented | 2026-08-27 |
| RDR-06 | Garbage identifiers return null, never a foreign row | php | id 0, non-key string, unused key | null each | implemented | 2026-08-27 |
| RDR-07 | An all-digits 64-char key is read as a KEY, not coerced to an id | php | key of 64 sevens | resolves to the right row | implemented | 2026-08-27 |
| RDR-08 | get_all_attachments spans every category; equals the union of the per-category readers | php | 2 in one category, 1 in another | all = 3 = union | implemented | 2026-08-27 |
| RDR-09 | get_all_attachments excludes soft-deleted rows | php | delete one of N | count drops by one | implemented | 2026-08-27 |
| ATT-HTTP-01 | Real Content-Type on /_download and /_inline for old+new rows | http | GET endpoints | correct Content-Type header | planned | 2026-07-02 |
| ATT-HTTP-02 | Thumbnail endpoints materialize + serve external attachments over the wire | http | GET /_thumbnail | 200 image/webp | planned | 2026-07-02 |
| STO-01 | Row present, file missing: the bytes are rewritten under the SAME storage record (no duplicate-hash insert) | php | store_blob, unlink the blob, store_blob the same bytes | same id + hash, file restored, exactly one `_file_storage` row | implemented | 2026-08-22 |
| STO-02 | The repair corrects a size that no longer describes the bytes | php | row size forced to 1, blob unlinked, re-store | same id, size = strlen(bytes) | implemented | 2026-08-22 |
| STO-03 | Positive control: an INTACT blob still dedups and is not rewritten | php | store_blob twice over identical bytes | same id, blob mtime unchanged | implemented | 2026-08-22 |
