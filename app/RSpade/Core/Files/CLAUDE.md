# Core/Files — pointer

This directory's documentation now lives in the man pages and the skill tier; this file
is a pointer, not a second copy.

- **Uploading / claiming / the upload gate / size ceiling**: `rsx:man file_upload`
- **Deletion, retention, blob release, disposal hooks**: `rsx:man file_disposal`
- **Thumbnails, `<Attachment_Thumbnail>`, the render state machine, the renderer registry**: `rsx:man thumbnails`
- **The render pipeline in operation (`rsx:documents:status|failed|rerender`)**: `rsx:man documents`
- **Text extraction, full-text search, `<Document_Preview>`, PDF renditions**: `rsx:man document_search`
- **Headless soffice, invoked only by the render worker**: `rsx:man libreoffice`
- Skills: `rspade:file-attachments` (working with attachments), `rspade:document-preview`

`Document_Render_Service` is the single background worker behind all of it: one soffice
run per document produces the PDF rendition that feeds BOTH the thumbnail and
`<Document_Preview>`, and extracts the text in the same pass. Render state lives on the
blob (`File_Storage_Model::RENDER_STATUS_*`); FAILED is terminal. Nothing converts inside
a web request.

Historical note with no man home (WP-A external byte residency), preserved verbatim:

> Rolled-in mime fix: serving reads `$attachment->mime_type` (the old `$storage->mime_type` never existed). `file_size` is authoritative and works without a resident blob (`get_size()` reads the column).
