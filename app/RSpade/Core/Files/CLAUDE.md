# Core/Files — pointer

This directory's documentation now lives in the man pages and the skill tier; this file
is a pointer, not a second copy.

- **Uploading / claiming / the upload gate / size ceiling**: `rsx:man file_upload`
- **File URLs, the content version (`content_version()`, `?v=`) and the cache policy every attachment-addressed route applies through `File_Attachment_Controller::apply_content_version_cache_policy()`**: `rsx:man file_upload`, FILE ACCESS
- **Deletion, retention, blob release, disposal hooks, the per-blob `file_blob:<hash>` lock (`File_Blob_Locks`)**: `rsx:man file_disposal`
- **Declared blob references (`#[Blob_Reference]` read by `File_Blob_References`, the `Blob_Referencing` write-side trait, the "Blob References" health row) and the central blob API (`File_Storage_Model::store_blob()` / `store_bytes()` / `read_bytes()` / `read_stream()` / `download_response()` / `inline_response()`)**: `rsx:man file_disposal`
- **Thumbnails, `<Attachment_Thumbnail>`, the render state machine, the renderer registry**: `rsx:man thumbnails`
- **The render pipeline in operation (`rsx:documents:status|failed|rerender`)**: `rsx:man documents`
- **Text extraction, full-text search, `<Document_Preview>` / `<Document_Text_Preview>`, PDF renditions, the `preview_loaded` event every preview widget emits (state vocabulary, viewer contract)**: `rsx:man document_search`
- **Headless soffice, invoked only by the render worker, and the document sandbox that can contain it (`Document_Sandbox`)**: `rsx:man libreoffice`
- **The temp file store (`Rsx_Temp_Files`, `Temp_File_Model`, `Temp_File_Cleanup_Service`) - pipeline output under `uploads/_temp/`, outside the blob store**: `rsx:man temp_files`
- **Keep-forever (`deleted_retention_days = 0`) releases no blob anywhere (`File_Disposal_Service::blob_release_enabled()`); `rsx:files:unreferenced_blobs` (`File_Blob_Audit`) lists what stays**: `rsx:man file_disposal`
- Skills: `rspade:file-attachments` (working with attachments), `rspade:document-preview`

`Document_Render_Service` is the single background worker behind all of it: one soffice
run per document produces the PDF rendition that feeds BOTH the thumbnail and
`<Document_Preview>`, and extracts the text in the same pass. Render state lives on the
blob (`File_Storage_Model::RENDER_STATUS_*`). Nothing converts inside a web request.

The worker's queue is one column on the blob, `_file_storage.process_due_at` (NULL = the
worker has nothing to do; a time = owed work, attemptable from then), with
`process_attempt_count` beside it - THE WORK QUEUE section of `File_Storage_Model_Abstract`
holds every write of the two. Each document runs in a child process of its own
(`rsx:documents:process_one`, hidden) spawned by `Document_Render_Service::process_storage()`
through `Rsx_Artisan::run_bounded()`; the attempt is charged before the child starts, each
stage inside it is bounded by `Document_Stage_Bound`, and a blob gets
`rsx.documents.max_attempts` attempts a retry interval apart before it stays FAILED for an
operator. `render_storage()` is the unit of work the child runs - command line only.

Both models here are a BASE PLUS A SHELL. `File_Attachment_Model_Abstract` and
`File_Storage_Model_Abstract` carry every member - `$table`, the enum constants, the
relationships, `fetch()`, the hooks - and `File_Attachment_Model.php` /
`File_Storage_Model.php` beside them are three-line concretes that declare nothing. An
application customizes one by declaring `class File_Attachment_Model extends
File_Attachment_Model_Abstract` under `rsx/models/`: the manifest shadows the shell (left in
place, never loaded), the app's class becomes the model for the whole tree, and it keeps inheriting
everything the framework adds to the base. Edit the ABSTRACT when changing framework
behaviour - the shell is never where a member goes, and the override pass refuses an app
class that copies the base instead of extending it. See `rsx:man class_override`.

Historical note with no man home (WP-A external byte residency), preserved verbatim:

> Rolled-in mime fix: serving reads `$attachment->mime_type` (the old `$storage->mime_type` never existed). `file_size` is authoritative and works without a resident blob (`get_size()` reads the column).
