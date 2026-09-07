# documents - the async document render pipeline

## Domain

Every heavy document operation in RSpade - the headless-LibreOffice PDF rendition (which
feeds BOTH the `Document_Preview` viewer and the Office-document thumbnail) and the text
extraction that makes a document searchable - runs in ONE background worker,
`Document_Render_Service`. Nothing converts inside a web request.

The state that worker drives off lives on the BLOB (`_file_storage.render_status_id`),
because both products of a render - the rendition (`storage/rsx-renditions/{hash}.pdf`)
and the thumbnail cache key - are already content-addressed on the deduplicated blob hash.
N attachments over identical bytes therefore share one render.

Shipped in the Async Document Rendering epic: Phase 1 (schema, model state, the worker),
Phase 2 (render-state-aware serving - the thumbnail endpoint, the rendition endpoint and the
preview-info payload), Phase 3 (the client half - `File_Attachment_Model::fetch()`, the
`<Attachment_Thumbnail>` component, and `Document_Preview`'s waiting states) and Phase 4 (the
operator commands `rsx:documents:status|failed|rerender`).

## Source under test

- `app/RSpade/Core/Files/Document_Render_Service.php` - the `#[Task] #[Exclusive]`
  10-minute worker (`render_pending`), the `render_storage()` unit of work, the
  thumbnail-cache purge, the realtime notification, the `#[Health_Check]` and
  `get_statistics()`.
- `app/RSpade/Core/Files/File_Storage_Model.php` - the `render_status_id` enum
  (NOT_REQUIRED / PENDING / RENDERED / FAILED), `rendered_at`, `render_error`, and the
  idempotent `request_render()`.
- `app/RSpade/Core/Files/File_Attachment_Model.php` - `fetch()` / `portal_fetch()` (the gated ORM
  read behind `File_Attachment_Model.fetch(id)` in JavaScript, with the blob embedded), the create /
  `relink_storage()` hook that queues a convertible blob, `is_convertible_mime()`, `get_render_status()`, the `?v=`
  cache-buster on `get_thumbnail_url*()`, and render-aware `has_thumbnail()`.
- `app/RSpade/Core/Search/Search_Index_Service.php` - `extract_storage()` with its optional
  `$rendition_path` (a Writer document is extracted from the rendition by `pdftotext`
  instead of a second soffice run).
- `app/RSpade/Core/Files/File_Preview_Controller.php` - serves the rendition of a RENDERED blob
  or 404s naming the render state; re-queues a blob whose rendition was evicted; it no longer
  converts. `get_preview_info()` exports `render_status_id` and withholds `urls.rendition` until
  there is one.
- `app/RSpade/Core/Files/File_Attachment_Controller.php` - the render-state branch in
  `__generate_and_serve_thumbnail()` (placeholder with `no-store` and NO cache write for
  PENDING/FAILED, rendition-sourced raster for RENDERED) and the `$rendition_path` argument to
  `_render_thumbnail_data()`.
- `app/RSpade/Commands/Thumbnails/Thumbnails_Generate_Command.php` - the warm pass skips blobs
  whose render is pending or failed rather than caching an icon under the real key.
- `app/RSpade/Core/Preview/Attachment_Thumbnail.{jqhtml,js}` - THE thumbnail component: subscribes
  in `on_create`, fetches in `on_load`, swaps in place on a frame.
- `app/RSpade/Core/Files/File_Attachment_Model.js` - `thumbnail_url()`, the ONLY place a thumbnail
  URL is spelled in JavaScript.
- `app/RSpade/Core/Preview/Document_Preview.{jqhtml,js}` - the PENDING / FAILED waiting states and
  the same subscribe-and-swap.
- `app/RSpade/Commands/Documents/` - the operator surface: `rsx:documents:status` (render,
  extraction, rendition-cache and worker-schedule state), `rsx:documents:failed` (the terminal
  failures of both halves, with their reasons and their two different remedies) and
  `rsx:documents:rerender` (the ONLY way a RENDERED or FAILED blob re-enters the queue - it deletes
  the rendition first, or `render_storage()` would short-circuit on the file already on disk).
- `rsx/app/dev/attachment_thumbnail/` - the dev page the Playwright test drives.
- Migration: `2026_08_22_090059_add_render_status_to_file_storage` (columns, index, backfill).

## Man pages that define behavior

`rsx:man thumbnails`, `rsx:man document_search`, `rsx:man libreoffice`, `rsx:man file_upload`,
`rsx:man config_rsx`. (Updated in the epic's documentation phase.)

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| The blob render state machine (queue on create, dedup, idempotent request, terminal FAILED) | php | implemented |
| `render_storage()` outcomes (missing file, rendition short-circuit, real soffice, extraction folded in) | php | implemented |
| Side effects of RENDERED (thumbnail-cache purge, realtime emission) | php | implemented |
| The drain loop's queue selection and termination | php | partially implemented (FAILED not re-queued) |
| Status-aware serving (placeholder `no-store`, rendition-sourced thumbnails, `?v=` changes, rendition 404 states, preview-info shape) | php | implemented |
| `File_Attachment_Model::fetch()` / `portal_fetch()` (payload shape, gate denial, no byte access) | php | implemented |
| `<Attachment_Thumbnail>` placeholder -> real-raster swap without reload | playwright | implemented |
| `rsx:documents:status` / `:failed` / `:rerender` | cli | implemented |

## Running

```bash
php artisan rsx:test --framework --group=documents
```

```bash
node system/app/RSpade/tests/documents/playwright/attachment_thumbnail_swap.js
```

The real-binary test skips itself when `Libreoffice::find_soffice()` returns null.

The Playwright test drives `/dev/attachment_thumbnail`, and **that route now ships CLOSED**: the
template's dev showcase declares `#[Auth('closed')]`, which nothing satisfies. The test reports the
closed gate rather than pretending to pass, so it cannot run as shipped. Running it means opening
that surface in your own tree (swap `'closed'` for a check of your own on
`Attachment_Thumbnail_Controller` and its action). Tracked in the backlog - see
`docs.dev/backlog/BACKLOG.md`, "Dev showcase is closed".
