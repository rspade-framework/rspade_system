---
name: document-preview
description: Previewing, rendering and searching inside documents in RSX - the ONE background render worker (Document_Render_Service) that produces a PDF rendition and extracts text in a single pass, the render_status_id state machine on the blob (NOT_REQUIRED/PENDING/RENDERED/FAILED), the <Document_Preview> component with its "Preparing preview..." state and preview_loaded/page_changed events, the server-side viewer registry and how to override it, the three document.* resolve chains (extract_text, preview_rendition, thumbnail_render), EXTRACTED/FAILED/UNSUPPORTED extraction statuses, search_text() with mandatory site scoping, and rsx:documents:status/failed/rerender plus rsx:search:reindex triage. Use when showing a PDF or Office document in a page, searching inside uploaded files, plugging in a custom viewer or converter, or debugging a document that shows a placeholder icon, a preview stuck on "Preparing preview...", or missing extracted text.
---

# Document preview and search

Every part of this system keys its work on the **DEDUPLICATED blob** (`File_Storage_Model`), not on the attachment: identical bytes are converted once, extracted once, and thumbnailed once, however many attachments point at them. Framework-core — there is nothing to wire up.

---

## The pipeline: one worker, three products

`Document_Render_Service::render_pending` — `#[Exclusive]`, `#[Schedule('every 10 minutes')]`, kicked the moment a convertible document is uploaded — is the **only** thing that runs `soffice`. Per blob, in one pass: it produces the PDF **rendition**, extracts the **text** from it, and settles the blob's render state; the **thumbnail** is later rasterized from that same rendition. Nothing converts inside a web request.

**Render state lives on the blob**, `_file_storage.render_status_id`, read as `$attachment->get_render_status()`:

| State | Meaning |
|---|---|
| `NOT_REQUIRED` (1) | Nothing to convert — an image, a plain PDF, a zip. The default. |
| `PENDING` (2) | Queued. The queue IS this column; there is no side table. |
| `RENDERED` (3) | Rendition on disk, `rendered_at` set, text extracted. |
| `FAILED` (4) | **Terminal.** `render_error` holds the reason; the sweeper never retries. |

An attachment flips its blob to `PENDING` on create/relink when its `pipeline_mime()` matches `config('rsx.preview.convertible')`. Dedup falls out: a blob already PENDING/RENDERED/FAILED is left alone, so re-uploading the same bytes converts nothing.

**The consequence every page must know: an Office document's picture, preview and text all appear a few seconds AFTER the upload, not during it.** Until then the thumbnail is an extension-icon placeholder (served `no-store`, never written to the cache) and the preview says "Preparing preview…". When the render lands, the worker emits a realtime frame on every attachment referencing the blob and both components swap themselves in place — no reload, no polling. On a realtime-disabled box the placeholder simply persists until the next navigation.

Operating it is `rsx:documents:status | :failed | :rerender` — see **Reindex and rerender triage** below and `rsx:man documents`.

---

## Showing a document

```jqhtml
<Document_Preview $attachment_id=this.data.doc.id />
```

Args: `$attachment_id` (**required** — the component throws without it), `$page` (initial page for paginated viewers, default 1), `$width`/`$height` (px; omitted = fill the container).

**States.** The component subscribes to the attachment in `on_create()` and reads `render_status_id` from `get_preview_info()`, so it repaints itself when the render lands:

- `PENDING` → **"Preparing preview…"**. No rendition URL is returned, so the viewer is never asked to load one early.
- `FAILED` → a failed state. `render_error` is **never** sent to the browser; the operator reads it with `rsx:documents:failed`.
- `RENDERED` / `NOT_REQUIRED` → `urls.rendition` is present and the viewer loads it.

A thumbnail of the same document is `<Attachment_Thumbnail $attachment_id=... />` and follows the identical placeholder-then-swap story — see `rspade:file-attachments` §5 and `rsx:man thumbnails`.

Public methods, delegating to the active viewer (no-ops before it is ready): `set_page(n)`, `get_page()`, `get_pages()`.

**Events OUT** — listen on the `Document_Preview` itself; it re-emits the active viewer's events:

```javascript
on_ready() {
    this.sid('preview').on('preview_loaded', (c, d) => this.$sid('count').text(d.pages));
    this.sid('preview').on('page_changed',   (c, d) => this.$sid('page').text(d.page));
}
```

`preview_loaded` carries `{pages, width, height, ratio}`; `page_changed` carries `{page}`.

**It is `preview_loaded`, NOT `loaded`.** `loaded` is a reserved jqhtml lifecycle event, fired internally with no payload — a custom event of the same name collides with it. This is the worked instance of the reserved-event rule in the jqhtml docs; if you add your own viewer, name its events the same way.

**Degraded uploads**: when an image's bytes could not be parsed, the attachment carries `preview_unavailable` and the component renders a "Preview unavailable" card instead of instantiating a viewer. Nothing throws.

---

## Viewer resolution (server-side) and overriding it

`Document_Preview` calls `File_Preview_Controller::get_preview_info()` in `on_load()`; the SERVER picks the viewer by mime from `config('rsx.preview.viewers')` — an ordered fnmatch map, first match wins, terminating in `'*' => 'Icon_Viewer'` so every mime resolves to something:

```php
'application/pdf'  => 'Pdf_Viewer',          // + Office mimes (via a PDF rendition)
'image/*'          => 'Image_Viewer',
'*'                => 'Icon_Viewer',
```

Register your own in `rsx/resource/config/rsx.php` under the same key:

```php
'preview' => ['viewers' => [
    'application/vnd.custom.cad' => 'Cad_Viewer',   // simple, manifest-resolved component name
] + config('rsx.preview.viewers')],
```

The three built-ins are rendered by the template; **any other name is instantiated dynamically** into the `$sid="viewer"` host with `{url, extension, file_name, page}`. So a custom viewer is an ordinary jqhtml component that takes those args and — if it wants the page UI to work — implements `set_page`/`get_page`/`get_pages` and fires `preview_loaded`/`page_changed`.

**PDF renditions**: `/_preview/pdf/:key` serves the PDF pdf.js actually loads, and it is **serve-only — it never converts**. A PDF blob is served as-is; a mime listed in `rsx.preview.convertible` is served from the cached rendition at `storage/rsx-renditions/{blob-hash}.pdf` (LRU-swept to `rsx.preview.quota_max_bytes`) **but only when the blob is RENDERED** — otherwise it 404s naming the render state. Anything else is 415. A RENDERED blob whose rendition was LRU-evicted re-queues itself and 404s for that one request, rather than showing an error over a cache eviction. The route is **dual-gated** (`file.thumbnail.authorize` AND `file.download.authorize`), so your file-access hooks apply to previews exactly as they do to downloads. pdf.js itself is lazy-served from `/_preview/pdfjs.mjs` (+ `pdf_worker.mjs`) out of the committed `node_modules` and is **never bundled**.

---

## The three `document.*` resolve chains

`Rsx::trigger_resolve` chains: **first non-null handler wins; `null` means decline and fall through to the framework default.** (The event mechanism itself is `rspade:event-hooks`.)

| Chain | Overrides | Return contract |
|---|---|---|
| `document.extract_text` | how a blob becomes text | `null` \| `string` (the text) \| `['status'=>'unsupported']` \| `['status'=>'failed','error'=>...]` |
| `document.preview_rendition` | how an attachment becomes a PDF | `null` \| `Response` (takeover) \| `['path'=>abs pdf]` \| `['unsupported'=>true]` (415) |
| `document.thumbnail_render` | how bytes become a raster | `null` \| WebP bytes (string) \| `['unsupported'=>true]` (generic icon) |

```php
#[OnEvent('document.preview_rendition')]
public static function convert_cad($data)
{
    if ($data['attachment']->file_extension !== 'dwg') {
        return null;                                   // decline - framework pipeline continues
    }
    return ['path' => Cad_Service::to_pdf($data['attachment'])];
}
```

Anything outside the contract fails loud. `preview_rendition` runs AFTER the auth gates; `thumbnail_render` runs BEFORE the framework renderer registry.

**Where they run now.** `extract_text` fires **inside the render worker's pass**, not in a worker of its own, and its `path` is always the SOURCE BLOB (the rendition shortcut is applied only after the chain declines). `thumbnail_render` still runs FIRST — ahead of both the rendition path and the renderer registry — and its `path` is the source blob too, so a handler that takes over an Office mime behaves exactly as it always did; it is **not fired at all** for a PENDING or FAILED blob, which serve the placeholder without entering the render path. `preview_rendition`'s contract is unchanged; only the framework path behind it became serve-only, so a handler that converts on demand still works.

---

## Text extraction lifecycle

**Extraction is asynchronous — never expect text right after an upload.**

The queue is the `_file_storage.is_indexed` flag: there is **no stored PENDING status**, because the absence of a `_search_indexes` row IS "queued". The render worker above drains it — one blob per iteration, the lowest id owing either a rendition or an extraction. (`Search_Index_Service::index_pending` no longer exists; `Search_Index_Service::extract_storage($storage, ?$rendition_path)` remains as the unit of work.)

**Two master switches, governed separately.** `config('rsx.search.enabled')` governs extraction and is also the worker's kick switch. `config('rsx.libreoffice.enabled')` governs rendering; with it off, PENDING rows simply **wait** (they are not lost — the sweeper renders them the day soffice arrives) while PDFs and plain text keep extracting normally.

**Word-processing documents no longer run soffice for text**: `pdftotext` reads the rendition the same pass just produced. Spreadsheets and presentations keep their own `fods`/`fodp` conversion, because flat XML is the only route that reads every sheet and slide. Net: one soffice run for a Writer document, two for a sheet or deck — it used to be three for all of them.

Rows are terminal, one of three:

- **EXTRACTED** — text is stored (capped at `rsx.search.max_text_bytes`, 2MB; over-cap is TRUNCATED and recorded, never silently dropped and never FAILED).
- **FAILED** — an extractor ran and genuinely failed (missing blob, broken binary).
- **UNSUPPORTED** — no configured extractor matched the mime. Images and archives land here, **not** in FAILED. There is no OCR.

That distinction is the point: do not build retry or alerting logic around images. `config('rsx.search.extractors')` is the fnmatch mime map (PDF → pdftotext, Office/RTF → LibreOffice, `text/*`/csv/json → plain).

**A scanned PDF is the trap.** Its mime is `application/pdf`, so it matches pdftotext, runs successfully, and produces **EXTRACTED with EMPTY text** — a page of images has no text layer and there is no OCR to fall back on. It is not FAILED and not UNSUPPORTED. If "the document has no searchable text" matters to your UI, check the content, not just the status:

```php
$status = $attachment->get_extraction_status();          // null = still pending
$text   = $attachment->get_extracted_text();             // '' for an indexed-but-textless blob
if ($status === Search_Index_Model::STATUS_EXTRACTED && $text === '') { /* scanned/textless */ }
```

`get_extraction_status()` reads the column directly and never materializes external bytes; `get_extracted_text()` routes through the blob.

---

## Searching inside documents

```php
File_Attachment_Model::search_text($query, $mode = 'BOOLEAN')   // returns an Eloquent Builder
```

**You MUST add site scoping.** `_search_indexes` has **no `site_id`** — it is keyed on the deduplicated blob and is therefore cross-site by construction. Forgetting this is a tenant-isolation defect, not a cosmetic one:

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function search_documents(Request $request, array $params = [])
{
    return File_Attachment_Model::search_text($params['q'])
        ->where('site_id', Session::get_site_id())          // MANDATORY
        ->where('fileable_type', 'Project_Model')
        ->result_set()
        ->map(fn($a) => ['id' => $a->id, 'name' => $a->file_name]);
}
```

Dedup means one matching blob can back several attachments, so the builder can yield multiple rows per blob — exactly what a Files index wants. `$mode` is `'BOOLEAN'` or `'NATURAL LANGUAGE'` (MySQL FULLTEXT semantics).

---

## Reindex and rerender triage

**Two different failures, two different commands.** A RENDER failure is `soffice` unable to produce a PDF (`_file_storage.render_status_id = FAILED`, reason in `render_error`); an EXTRACTION failure is an extractor unable to pull text (a `_search_indexes` row with status FAILED). `rsx:documents:failed` prints both tables with each one's remedy underneath, so a wrong guess costs nothing.

`rsx:documents:*` — the render half (`rsx:man documents`):

| Command | Use it when |
|---|---|
| `rsx:documents:status` | first: render + extraction counts, rendition cache, next worker run |
| `rsx:documents:failed [--limit=N]` | a document shows the icon forever — the reason is printed (`--limit=0` = all) |
| `rsx:documents:rerender --failed` | after fixing the cause of render failures |
| `rsx:documents:rerender --storage=ID` / `--attachment=ID` | one document; open pages are notified immediately |
| `rsx:documents:rerender --all` | you changed how documents convert. Every blob that ever entered the pipeline — never the NOT_REQUIRED images |

Exactly one selector, or it exits 1. `rerender` **deletes each rendition file first** — without that, `render_storage()` short-circuits on the file already on disk and re-render is a no-op.

`rsx:search:reindex` — the text half, exactly ONE selector:

| Flag | Use it when |
|---|---|
| `--status` | first: report index counts, re-queue nothing |
| `--attachment=<id>` | one document looks wrong (materializes external bytes) |
| `--storage=<id>` | you already know the blob id |
| `--failed` | a dependency was broken/missing and has been fixed |
| `--below-version=<N>` | an extractor changed; `rsx.search.extractor_version` was bumped |
| `--all` | last resort — the whole corpus |

Start with `--status`. A large `UNSUPPORTED` count is usually correct (images); a large `FAILED` count means a missing binary.

---

## Dependencies

`libreoffice poppler-utils fonts-liberation fonts-dejavu-core` — verified by `php artisan rsx:health` (exit 1 iff a FAIL; WARN/INFO are advisory). No poppler → PDFs go FAILED. No LibreOffice → Office extraction fails and Office previews return 415. Missing fonts → conversions render with substituted glyphs.

**There is no concurrency semaphore any more.** One `#[Exclusive]` worker is the sole caller of soffice, so invocations are serialized by construction. `rsx.libreoffice.max_concurrent`, `rsx.libreoffice.slot_wait_timeout`, `rsx.preview.rendition_timeout` and `rsx.search.timeout` were all removed on 2026-08-22 — **delete any override you carry.**

**ONE owner-sanctioned timeout lives here — do not "fix" it.** `rsx.libreoffice.timeout` (120s) bounds a single external document-binary invocation, `soffice` or `pdftotext`. It qualifies under the no-timeout mandate because those binaries WEDGE rather than merely running slowly, the worker is single-threaded (so one wedged document would block every later one forever), and expiry degrades to a working outcome: the blob is recorded FAILED and the extension icon is served. It is not a cap on our own work, and lowering it will start failing legitimate renders. The full justification is written beside the key in `config/rsx.php`.

---

## Troubleshooting

- **Nothing renders, no error** — the attachment id is wrong, or the mime resolved to `Icon_Viewer` (no viewer registered for it).
- **Handler never fires on `loaded`** — you listened for the reserved lifecycle event; it is `preview_loaded`.
- **Preview stuck on "Preparing preview…"** — the blob is PENDING. `rsx:documents:status`: if the count never falls the worker is not running (`rsx:task:process`, `rsx:health` "Document Render Backlog") or `rsx.libreoffice.enabled` is false.
- **A document shows the extension icon forever** — that IS the correct picture for PENDING or FAILED. `rsx:documents:status` says which; `rsx:documents:failed` names the reason. FAILED is terminal by design.
- **404 from `/_preview/pdf/:key`** — not an error condition: the blob is not RENDERED yet and the body names the state. The viewer never requests it in that state.
- **415 from `/_preview/pdf/:key`** — the mime is not in `rsx.preview.convertible`.
- **403 on a preview that a download allows** — the route is dual-gated; check `file.thumbnail.authorize`, not just `file.download.authorize`.
- **`get_extracted_text()` returns null forever** — extraction is off (`rsx.search.enabled`), or the cron is not running; confirm with `rsx:search:reindex --status`.
- **Extracted text is empty for a PDF** — scanned/image-only PDF (see above). No OCR exists.
- **Search returns another tenant's files** — missing `where('site_id', ...)`.

- **Text is pending right after an upload** — correct, not a symptom. The whole pipeline is asynchronous.

Details: `php artisan rsx:man document_search` · `documents` · `libreoffice` · `thumbnails`. Related: `rspade:file-attachments`, `rspade:event-hooks`, `rspade:background-tasks`, `rspade:jqhtml`.
