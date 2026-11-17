# Thumbnails — pointer

A thumbnail is rendered with `<Attachment_Thumbnail $attachment_id=... />` and nothing
else; an app never builds a thumbnail URL. The caching system behind it (two-tier
dynamic/preset cache, filename format, LRU tracking, quota enforcement, WebP output, the
placeholder rules, the render state machine and the renderer registry, and the
`rsx:thumbnails:*` commands) is documented in:

- `rsx:man thumbnails` — the full contract, including THE ATTACHMENT_THUMBNAIL COMPONENT
  and the RENDER PIPELINE state machine
- `rsx:man documents` — operating the render worker (`rsx:documents:status|failed|rerender`)
- `rsx:man file_upload` — `has_thumbnail()`, the routes, the upload payload
- `rsx:man libreoffice` — headless soffice, invoked only by `Document_Render_Service`
- Skills: `rspade:file-attachments` (§5 Displaying files), `rspade:document-preview`

Every thumbnail the pipeline draws is upright (`autoOrient()` first) and metadata-free
(`stripImage()`, the ICC profile kept); cache names carry the renderer revision
(`_r{N}`, `File_Attachment_Controller::THUMBNAIL_RENDER_REVISION`), and a width-only
dynamic request is named `{type}_{w}x_...`, apart from the same width and height. A
face-centred cover (`?face=1`, `$face=true`) cuts around the face `Face_Detection` found,
stored on the blob (`_file_storage.face_x/face_y`; `-1,-1` none, NULL unchecked) and
cached as `cover-face_...`; an unavailable detector serves the plain crop with
`max-age=1800`. `rsx:man thumbnails`, FACE-AWARE CROPS.

An Office document's pixels come from the PDF rendition the background worker produced,
so a fresh upload serves an extension-icon placeholder (uncacheable) until the render
lands and a realtime frame makes the component swap the image in place.

See `Core/Files/CLAUDE.md` for the sibling pointer and the one preserved WP-A note.
