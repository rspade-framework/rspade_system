# preview - document preview rendition + preview/thumbnail filter chains

## Domain

The framework-core document PREVIEW surface consumed by the `Document_Preview` component
(built in Batch 4): a cached soffice->PDF rendition endpoint for pdf.js, the lazily-loaded
pdf.js module byte routes, server-side viewer resolution (`get_preview_info`), and the two
app-interceptable resolve chains that make the pipeline pluggable:

- `document.preview_rendition` - override/extend how an attachment becomes a PDF rendition.
- `document.thumbnail_render` - override/extend the thumbnail bytes (inserted at the top of
  `File_Attachment_Controller::_render_thumbnail_data`).

Shipped in the Document Pipeline epic, Batch 3 (rendition endpoint + filter chains + cache).

## Source under test

- `app/RSpade/Core/Files/File_Preview_Controller.php` - `/_preview/pdf/:key` rendition
  (dual auth gate cascade -> `document.preview_rendition` resolve -> framework pipeline:
  PDF passthrough / cached soffice->PDF rendition / 415); `/_preview/pdfjs.mjs` +
  `/_preview/pdf_worker.mjs` (lazy pdf.js bytes, 404-with-remediation until installed);
  `get_preview_info` (thumbnail-gated viewer + mime + type-safe URLs); `get_extracted_text`
  (the {status, text} payload `<Document_Text_Preview>` reads, behind the CONTENT gate
  cascade - thumbnail AND download, because extracted text is the document's content);
  `viewer_for_mime()`; `rendition_cache_path()`.
- `app/RSpade/Core/Files/File_Attachment_Controller.php` - the `document.thumbnail_render`
  resolve chain inserted at the top of `_render_thumbnail_data` (string = WebP bytes,
  `['unsupported'=>true]` = icon, null = fall through to the renderer registry).
- `app/RSpade/Core/Files/File_Rendition_Service.php` - `#[Schedule('*/30 * * * *')]`
  `cleanup_renditions` (LRU to `rsx.preview.quota_max_bytes`) + `get_statistics()`.
- `app/RSpade/Core/Files/Libreoffice.php` - shared `find_soffice()`. Conversion itself belongs
  to `Document_Render_Service` (tests/documents); there is no concurrency semaphore any more.
- Config `rsx.preview.*` (viewers, convertible, quota_max_bytes).

## Behavior defined by

`php artisan rsx:man document_search` (authored in Batch 6). Until then the plan doc
(`hashed-whistling-pumpkin.md`, BATCH 3) and the CR
(`docs.dev/external_requests/2026_07_16_document_preview_rendering.md`) are the contract.

## Testable surface

- **php** - viewer resolution by mime, rendition cache-path derivation, `get_preview_info`
  behind the thumbnail gate, the `get_extracted_text` status vocabulary
  (available/pending/error/unsupported) and its content-gate cascade, the thumbnail-intercept chain (fixture bytes served verbatim +
  the live-fixture safety property that a real thumbnail is NOT hijacked), and the
  preview_rendition chain (marker intercept + non-marker decline).
- **http** - the pdf.js module routes (404-with-remediation now; Batch 4 flips to 200) and
  that an unknown rendition key never serves content. The seeded-PDF (200 application/pdf)
  and .bin (415) rendition rows are DEFERRED to php in-process tests: the live web server
  uses the DEV database, so seeding a real attachment + minting a staff cookie over HTTP
  would require touching the dev DB (forbidden).
- **playwright** - the Document_Preview / Document_Text_Preview components + demo page.
  `document_preview_spa.js` covers the pdf.js viewer and pagination;
  `document_preview_fit.js` covers the `$fit` geometry - `contain` fitting the whole page
  into the demo page's bounded host, and the debounced ResizeObserver RE-RASTERING (the
  canvas BACKING size grows) when the host grows;
  `document_text_preview_swap.js` covers the "(Extracting Text...)" -> text swap arriving
  over a realtime frame with no reload (Reset Extraction / Extract Now on the demo page).
  NOTE: all three drive `/dev/document_preview`, and the template now ships its whole dev
  showcase CLOSED (`#[Auth('closed')]`). They report the closed gate rather than passing
  vacuously, so running them means opening that surface in your own tree. See B-99 in
  `docs.dev/backlog/BACKLOG.md`.

## Fixtures are LIVE in dev

`#[OnEvent]` fixtures are manifest-discovered and register on the real resolve chains. Both
are marker-guarded so they are inert for real files:

- `Preview_Thumbnail_Render_Fixture_Handler` intercepts ONLY source paths containing
  `rsx_test_thumb_intercept` (real blob paths are content hashes and never match) - asserted
  by `Preview_Thumbnail_Intercept_Test::test_non_marker_source_renders_via_registry`.
- `Preview_Rendition_Fixture_Handler` intercepts ONLY an attachment whose `file_name`
  contains `rsx_test_rendition_intercept`. The rendition payload carries no path, so the
  guard is the file_name (a real file literally named with the marker is an accepted,
  documented test seam - the same marker philosophy as the search fixtures).
