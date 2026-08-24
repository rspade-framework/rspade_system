<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the upload gate, the size ceiling, the delete()-is-retention rule and the <Attachment_Thumbnail> mandate. -->

## FILE ATTACHMENTS

Two models, one dedup boundary: `File_Storage_Model` = the physical content-addressed blob, `File_Attachment_Model` = the logical upload with its metadata and owner. Identical bytes are stored — and text-extracted, and rendered, and previewed — exactly once. The flow is always upload-unattached -> validate -> claim onto a record (`find_by_key()` + `can_user_assign_this_file()` + `attach_to()`/`add_to()`); the client transport is `Ajax.upload(form_data)`.

**The upload gate is MANDATORY.** `POST /_upload` THROWS when no `file.upload.authorize` handler is registered — an unhandled gate would be an anonymous upload endpoint. Every app ships one handler in `/rsx/handlers/` (minimum: require login).

**Never hardcode the size ceiling in a label** — "Max size 25MB" is wrong the day the limit changes and nothing breaks to tell you. One number both languages read (`config('rsx.files.max_file_size')` / `window.rsxapp.files.max_file_size`), rendered by `Ajax::max_file_size_human()` / `Ajax.max_file_size_human()`. **`0` means no framework ceiling — read it as UNLIMITED, never as "reject everything".**

**A thumbnail is rendered with `<Attachment_Thumbnail $attachment_id=... />` and nothing else** — images included, staff and portal alike. It takes `$type` (`'fit'`/`'cover'`), `$width`, `$height`, or a `$preset` (exclusive with the three), plus `$alt`; from JS it mounts as `$el.component('Attachment_Thumbnail', {attachment_id, width})`. **An app never builds a thumbnail URL** and never ships one in a payload — a producer ships `file_attachment_id` and the component fetches the record itself (batched through `File_Attachment_Model.fetch()`). The component owns the URL because the picture is a live view: the server may replace it at any time, and only the component is subscribed to hear about it. Never invoke it with a null id — render the initials/placeholder branch instead.

**`delete()` ENTERS a recoverable retention window; it does not destroy anything.** The blob is preserved and the attachment is recoverable (`get_deleted_attachments()` / `undelete()`). `force_destroy()` is the only immediate erasure, and `File_Disposal_Service` is the SOLE blob-release authority.

## DOCUMENT RENDERING, SEARCH & PREVIEW

Framework-core, no app wiring, all of it keyed on the **deduplicated blob**. An Office document is converted ONCE, by one background worker (`Document_Render_Service::render_pending`, `#[Exclusive]`, kicked at upload and swept every 10 minutes), which produces the PDF rendition that both the thumbnail and `<Document_Preview>` are served from and extracts the text in the same pass.

**Render state lives on the BLOB**: `File_Storage_Model::RENDER_STATUS_NOT_REQUIRED` (an image or PDF — nothing to convert) / `PENDING` / `RENDERED` / `FAILED` (**terminal, never auto-retried**), read as `$attachment->get_render_status()`. **So an Office thumbnail appears a few seconds AFTER the upload, not during it**: until then an extension-icon placeholder is served uncacheable, and when the render lands a realtime frame on the attachment makes every mounted `<Attachment_Thumbnail>` swap the picture in place.

**Text extraction is ASYNC — never expect text right after upload** (`get_extraction_status()` is null while pending; statuses are EXTRACTED / FAILED / UNSUPPORTED, no OCR). **`File_Attachment_Model::search_text($query)` returns a Builder the app MUST site-scope** — `_search_indexes` has no `site_id` and is cross-site by construction, the one place the framework does not scope for you.

**`<Document_Preview $attachment_id=... />`** shows "Preparing preview…" until the rendition exists, then the viewer (PDF via pdf.js, Office via the rendition, images and icons otherwise).

**Operating it**: `rsx:documents:status` (render + extraction counts, rendition cache, next worker run), `rsx:documents:failed` (render failures; extraction failures print their own remedy), `rsx:documents:rerender --all|--failed|--storage=ID|--attachment=ID`. `rsx:health` carries a "Document Render Backlog" row. **`rsx.libreoffice.timeout` (120s) is the ONE sanctioned bound in this subsystem** — it caps an external binary that can wedge, and nothing else here is timed.

Skills: `rspade:file-attachments` (claim flow, URLs, displaying files, disposal hooks, ZIP downloads), `rspade:document-preview` (the render pipeline, viewer registry, the three `document.*` resolve chains, reindex triage, deps). Details: `rsx:man file_upload`, `rsx:man thumbnails`, `rsx:man document_search`, `rsx:man documents`.
