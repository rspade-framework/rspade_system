---
name: document-preview
description: "Previewing, rendering and searching inside documents in RSX - the ONE background render worker (Document_Render_Service) that produces a PDF rendition and extracts text in a single pass, the render_status_id state machine on the blob (NOT_REQUIRED/PENDING/RENDERED/FAILED), the worker's queue (process_due_at, charged attempts, retries, rsx.documents.stage_seconds / kill_seconds / max_attempts / retry_after_minutes / soft_retry_max_minutes), soft failures (soft_failure() checks, OUTCOME_SOFT_FAILED, the uncharged backoff, retry on view with render_retrying), the per-type renderer registry rsx.documents.renderers with Rsx_Document_Renderer_Abstract, the Rsx_Mime_Map precedence every mime registry shares, Document_Render_Service::process_storage(), mark_work_owed() / requeue_render(), the <Document_Preview> component with its \"Preparing preview...\" state and preview_loaded/page_changed events, its extracted-text sibling <Document_Text_Preview> with its \"(Extracting Text...)\" and \"(Document Text Unavailable)\" notices, the server-side viewer registry (Pdf_Viewer / Image_Viewer / Text_Viewer / Icon_Viewer) and how to override it, should_show_text_preview() and can_open_inline() as the two presentation questions an attachment answers, the three document.* resolve chains (extract_text, preview_rendition, thumbnail_render), EXTRACTED/FAILED/UNSUPPORTED extraction statuses, search_text() with mandatory site scoping, and rsx:documents:status/failed/rerender plus rsx:search:reindex triage. Use when showing a PDF or Office document in a page, searching inside uploaded files, plugging in a custom viewer, renderer, extractor or converter, replacing the framework's preview pipeline for a file type, showing a .txt / .csv / .log as itself, deciding whether to show extracted text beside a preview or offer an \"Open in Browser\" link, or debugging a document that shows a placeholder icon, a preview stuck on \"Preparing preview...\", a text panel stuck on \"(Extracting Text...)\", missing extracted text, a failure reading \"render exceeded 20 seconds\", \"text extraction exceeded 20 seconds\", \"killed after 60 seconds\", \"is not installed or not executable\", \"the document sandbox image ... is not present\" or \"the source file is missing or unreadable\" (a soft fail - tool or source unavailable), the boot error \"rsx.preview.convertible is no longer read by the framework\", or the LogicException \"runs only in a command-line process\" from render_storage() / extract_storage()."
---

# Document preview and search

Every part of this system keys its work on the **DEDUPLICATED blob** (`File_Storage_Model`), not on the attachment: identical bytes are converted once, extracted once, and thumbnailed once, however many attachments point at them. Framework-core — there is nothing to wire up.

---

## The pipeline: one worker, three products

`Document_Render_Service::render_pending` — `#[Exclusive]`, `#[Schedule('every 10 minutes')]`, kicked the moment a document is uploaded — is the **only** thing that runs `soffice`. Per blob, in one pass, in a **child process of its own**: it produces the **rendition** through the type's renderer (`rsx.documents.renderers` — a PDF for Office documents, an HTML grid for workbooks), extracts the **text**, and settles the blob's render state; the **thumbnail** is later rasterized from that same PDF. Nothing converts inside a web request.

**Render state lives on the blob**, `_file_storage.render_status_id`, read as `$attachment->get_render_status()`:

| State | Meaning |
|---|---|
| `NOT_REQUIRED` (1) | Nothing to convert — an image, a plain PDF, a zip. The default. |
| `PENDING` (2) | A rendition is owed and no attempt has reported yet. Shown as "Preparing preview…". |
| `RENDERED` (3) | Rendition on disk, `rendered_at` set. |
| `FAILED` (4) | The last attempt failed — or a **soft** check found the box unable to run it; `render_error` holds the reason. **The worker retries it** (below); whether another attempt is coming is the queue's answer, not this value's. Shown as "Preview could not be generated" — or "Preparing preview…" while a retry this view started is under way. |

An attachment flips its blob to `PENDING` on create/relink when a renderer in `config('rsx.documents.renderers')` matches its `pipeline_mime()` — that map is what makes a type previewable by rendition. Dedup falls out: a blob already PENDING/RENDERED/FAILED is left alone, so re-uploading the same bytes converts nothing — and never hands a FAILED blob fresh attempts.

### The work queue: one document cannot stop the rest

The worker's queue is ONE column on the blob, `_file_storage.process_due_at` — `NULL` = nothing for the worker to do; a time = the blob owes work (a rendition, its text, or both) and may be attempted from then. `process_attempt_count`, `process_last_attempt_at` and `process_soft_failure_count` sit beside it. The drain takes the earliest due blob until none is due. A document is input the application does not control, so four rules bound what a bad one can cost (`config('rsx.documents')`):

| Rule | Key (default) | What you see |
|---|---|---|
| The attempt is **charged before it starts** — count + 1, due time pushed one retry interval ahead | `retry_after_minutes` (30) | a blob whose worker died is still counted, and is no longer first in line |
| One **child process per document**, killed with everything it started | `kill_seconds` (60) | `killed after 60 seconds` |
| Each **stage** in the child is bounded — render and text extraction separately | `stage_seconds` (20) | `render exceeded 20 seconds` / `text extraction exceeded 20 seconds` |
| A fixed number of attempts, then FAILED for good | `max_attempts` (3) | `rsx:documents:failed`: `3 of 3`, `none - out of attempts` |

Render and extraction share the one queue entry and the one counter. Owed work that is switched off (a renderer's `is_enabled()` — `rsx.libreoffice.enabled` for soffice — or `rsx.search.enabled`) is *deferred* one interval with no attempt charged. After the last attempt only an operator puts the blob back (`rsx:documents:rerender`, `rsx:search:reindex` — each resets the attempts).

**A soft failure is recorded, not charged.** Before charging, the pass asks the renderer and extractor it would run their `soft_failure()` check — tool installed and executable, an operator's own sandbox image present (the framework's is built on first use), source readable; a check only LOOKS. A reason → FAILED with that reason, **no attempt, no child**, checked again after 30 min, doubling to `soft_retry_max_minutes` (10080 = a week), forever (`OUTCOME_SOFT_FAILED`). A pass that runs restarts the backoff. Checks are opt-in per renderer/extractor; everything else is **hard** (3 attempts, 30 min apart) — an encrypted or corrupt document included.

**Opening the preview retries what may now succeed** (`Document_Render_Service::attend_view()`, from `get_preview_info()`): an evicted rendition is re-queued; a soft-failed document is re-checked on **every** view and, when the check passes, made due and the worker kicked; a hard-failed one is made due only when its last counted attempt is at least `retry_after_minutes` old. The worker charges that attempt — **a view never grants one**, never runs a tool in the request, and never revives a blob whose attempts are spent or whose renderer is switched off. Thumbnails never trigger it. The payload's `render_retrying` makes the widget say "Preparing preview…"; a realtime-frame refetch passes `attend: false` so a failed retry cannot loop.

**A long backlog is drained in paced passes.** The pass sets no timeout and reports before every document, so the task system's silence limit never touches a working pass. After `drain_yield_after_minutes` (20), between two documents and only while work is still due, it sets its own next run `drain_resume_after_minutes` (10) ahead (`$task->run_again_in()`), says how many documents are still waiting (`Yielding after 20 minute(s): ... 1840 still waiting.`) and returns **successfully** - a voluntary yield so other scheduled work gets a turn. These two are pacing, not timeouts: nothing is cut short, nothing fails, every blob still due stays queued. A pass that empties the queue never yields; an upload's kick still starts one at once.

`stage_seconds` and `kill_seconds` are **owner-sanctioned timeouts — do not "fix" them.** They bound a parse of one uploaded file, never the queue or the worker's pass; expiry is a recorded FAILED attempt and the failed-document preview, and the uploaded file is untouched. The number is set by how long the queue may stand still, not by how slow a conversion may be: a site whose ordinary documents need longer raises `stage_seconds` in `rsx/resource/config/rsx.php`, keeping `kill_seconds` above twice that. The full justification is written beside the keys in `config/rsx.php`.

### Touching the queue from application code

```php
// Process one blob NOW and wait for it - callable from a web request.
$result = Document_Render_Service::process_storage($storage);
// ['outcome' => OUTCOME_DONE|RETRY|FAILED|WAITING|SOFT_FAILED|TAKEN, 'reason', 'attempt',
//  'max_attempts', 'soft_failures', 'next_attempt_at', 'render_label']

$storage->requeue_render();          // PENDING, artifacts cleared, due now, attempts reset, worker kicked
$storage->requeue_render(false);     // ... without kicking the worker

$storage->is_indexed = 0;            // say WHAT is owed ...
$storage->save();
$storage->mark_work_owed();          // ... then that it IS: due now, attempts reset

$storage->is_render_failed_for_good();   // FAILED and out of the queue
$storage->is_soft_failed();              // waiting out a soft backoff
Document_Render_Service::soft_failure_for($storage);   // what the next pass's soft checks say, recording nothing
```

- **`Document_Render_Service::render_storage()` and `Search_Index_Service::extract_storage()` are command-line only.** They are the unit of work the child runs, and from a web request they throw `LogicException` ("… runs only in a command-line process …"). `process_storage()` is the route: it charges the attempt, runs the document in the child and settles the row.
- **Writing `render_status_id = PENDING` or `is_indexed = 0` alone queues nothing** — the worker reads `process_due_at`. Use the methods above; a set UPDATE writes `File_Storage_Model::work_owed_values()` beside the columns that say what is owed.
- `process_storage()` on a blob that is not in the queue answers `OUTCOME_DONE` and runs nothing — re-queue it first.

Worked example: `system/app/RSpade/resource/reference_app/app/dev/document_preview/document_preview_controller.php` (reset + process) and `.../app/dev/attachment_thumbnail/attachment_thumbnail_controller.php`.

**The consequence every page must know: an Office document's picture, preview and text all appear a few seconds AFTER the upload, not during it.** Until then the thumbnail is an extension-icon placeholder (served `no-store`, never written to the cache) and the preview says "Preparing preview…". When the render lands, the worker emits a realtime frame on every attachment referencing the blob and both components swap themselves in place — no reload, no polling. On a realtime-disabled box the placeholder simply persists until the next navigation.

Operating it is `rsx:documents:status | :failed | :rerender` — see **Reindex and rerender triage** below and `rsx:man documents`.

---

## Showing a document

```jqhtml
<Document_Preview $attachment_id=this.data.doc.id />
```

Args: `$attachment_id` (**required** — the component throws without it), `$page` (initial page for paginated viewers, default 1), `$width`/`$height` (px; omitted = fill the container), `$fit` (`"width"` default, or `"contain"` — anything else throws).

**`$fit` is how the page is scaled inside the space you gave it.** `width` scales to the frame's width, so a portrait page in a wide short pane overflows and the frame scrolls. `contain` scales by `min(frame_width/page_width, frame_height/page_height)` so the whole page is visible, and sizes the canvas in explicit CSS px rather than `width:100%`.

```jqhtml
<Document_Preview $attachment_id=this.data.doc.id $fit="contain" $height=800 />
```

**`contain` requires a host with a bounded height** — that is what it divides by. Give it `$height`, or mount it in an element that has a real height. With a `height:auto` host it **falls back to the width fit** rather than rendering at a zero scale. Only paginated viewers act on `$fit`; `Image_Viewer` and `Icon_Viewer` already fit both axes in CSS.

**Resizing re-renders.** A paginated viewer watches its frame with a `ResizeObserver` and re-rasterizes the current page when the box changes, so a pane that grows gets a sharp page instead of a stretched bitmap. The callback is debounced (200 ms) so a drag or an animated collapse issues one re-render, not thirty; an unchanged box renders nothing.

**States.** The component subscribes to the attachment in `on_create()` and reads `render_status_id` from `get_preview_info()`, so it repaints itself when the render lands:

- `PENDING` → **"Preparing preview…"**. No rendition URL is returned, so the viewer is never asked to load one early.
- `FAILED` with `render_retrying` → **"Preparing preview…"**: this view set a retry in motion; the worker's frame brings the outcome.
- `FAILED` → a failed state. `render_error` is **never** sent to the browser; the operator reads it with `rsx:documents:failed`.
- `RENDERED` / `NOT_REQUIRED` → `urls.rendition` is present and the viewer loads it.

**The same subscription follows a REPLACED file.** `urls.rendition` and `urls.inline` are the model builders' URLs and carry the attachment's content version (`?v=<storage id>-<rendered_at or 0>`, `rspade:file-attachments` §5), so after `relink_storage()` — or a re-render — `get_preview_info()` answers a different payload, `refresh()` repaints, the viewer loads the new URL and `preview_loaded` reports again. That holds for a PDF and an image exactly as for a converted document. A viewer you register is handed the versioned `url`; pass it on untouched — a URL rebuilt from the key is served `no-store`.

A thumbnail of the same document is `<Attachment_Thumbnail $attachment_id=... />` and follows the identical placeholder-then-swap story — see `rspade:file-attachments` §5 and `rsx:man thumbnails`.

Public methods, delegating to the active viewer (no-ops before it is ready): `set_page(n)`, `get_page()`, `get_pages()`.

**Events OUT** — listen on the `Document_Preview` itself; it re-emits the active viewer's events:

```javascript
on_ready() {
    this.sid('preview').on('preview_loaded', (c, d) => this.$sid('count').text(d.pages));
    this.sid('preview').on('page_changed',   (c, d) => this.$sid('page').text(d.page));
}
```

`preview_loaded` carries `{attachment_id, state, render_status, pages, width, height, ratio}`; `page_changed` carries `{page}`.

**`width`/`height`/`ratio` describe the page currently rendered, not the document.** `preview_loaded` is emitted again after every page change (ahead of `page_changed`), so on a mixed-orientation file they change as the reader pages through it — read `ratio` as "the shape of the page you are looking at". `pages` is 1 for an unpaged viewer and 0 when no viewer is showing a document; text, markdown, grid and icon viewers have no geometry and omit the three.

**It is `preview_loaded`, NOT `loaded`.** `loaded` is a reserved jqhtml lifecycle event, fired internally with no payload — a custom event of the same name collides with it. This is the worked instance of the reserved-event rule in the jqhtml docs; if you add your own viewer, name its events the same way.

**Degraded uploads**: when an image's bytes could not be parsed, the attachment carries `preview_unavailable` and the component renders a "Preview unavailable" card instead of instantiating a viewer. Nothing throws. **A record that cannot be read** (deleted, or not visible to this user) paints the same card with nothing to name — a state, never a thrown error.

---

## `preview_loaded`: every preview widget always reports

**`<Attachment_Thumbnail>`, `<Document_Preview>` (and every viewer it hosts) and `<Document_Text_Preview>` each emit `preview_loaded` when what they depict is on screen — and every one of them ALWAYS eventually emits it, whatever it ended up showing.** A broken image, a generic type icon, a record that does not exist, a degraded upload and a waiting notice all count as having rendered. So a host never guards against the event not coming, and **never reaches into a widget's `<img>` or canvas to find out for itself.**

```javascript
on_ready() {
    this.sid('thumb').on('preview_loaded', (c, d) => {
        this.$sid('spinner').toggle(d.state === 'preparing');
        if (d.state === 'loaded') this.fit_to(d.natural_width, d.natural_height);
    });
}
```

Every payload carries `attachment_id` (so one handler can serve several widgets), `state`, and — on the thumbnail and `Document_Preview` — `render_status` (the blob's `RENDER_STATUS_*` id, `'none'` with no resident blob, `'unavailable'` with no record). Then each widget's own keys:

| Widget | Adds |
|---|---|
| `Attachment_Thumbnail` | `variant` (`'thumbnail'`, or `'original'` when `$allow_animated` showed the upload itself; `null` with no image), `natural_width` / `natural_height` of the image actually served (0 with none) |
| `Document_Preview` | `pages`, `width`, `height`, `ratio` |
| `Document_Text_Preview` | `length` (characters; 0 with no text showing) |

**`state` is one vocabulary of four values:**

| `state` | Means | Thumbnail | Document_Preview | Document_Text_Preview |
|---|---|---|---|---|
| `loaded` | the widget's final content is on screen | the picture decoded — the raster, or the type icon of a file with no raster | a viewer's document / image / text / grid; the `Icon_Viewer` icon | the text |
| `preparing` | a notice or placeholder the widget **replaces by itself** — it reports again when it does | the extension icon of a PENDING render | "Preparing preview…"; a viewer's own waiting notice | `(Loading...)`, `(Extracting Text...)` |
| `failed` | the content should have shown and could not | the `<img>` errored; the icon of a FAILED render | "Preview could not be generated"; pdf.js could not load or parse; the image errored; the extraction or markdown render failed | `(Document Text Unavailable)` because the extraction failed |
| `unavailable` | nothing to show, nothing went wrong | no record (deleted / not visible) | the "Preview unavailable" card — degraded upload, or no record | `(Document Text Unavailable)` because there is no record, the type carries no text, or the document contains none |

A host that only needs "has it resolved" ignores `state`; one that needs the content checks `state === 'loaded'`; one driving its own spinner clears it on anything but `preparing`.

**A late subscriber is still told.** jqhtml events are sticky — `on()` registered after the event runs at once with the last payload — so the picture that was cached and decoded before your `on_ready()` ran is delivered anyway. There is no `img.complete` fork to write.

**It reports again whenever the content changes** — the realtime swap out of a placeholder, a `reload()` after `$attachment_id` changed, a page change. A widget that replaces what is on screen withdraws its stored report first, so a subscriber arriving while the next content loads waits for that content's report and is never handed the previous one.

**`natural_width` on a thumbnail is the SERVED image's width**, not the upload's, unless `variant` is `'original'`: a 4000px photo behind a 600px thumbnail reports 600. For the upload's own size read the attachment's `width` / `height`.

**Not covered**: a fault that stops a lifecycle outright (a transport error under the thumbnail's record fetch, a custom viewer that throws) is an ordinary error, not a state; and `Spreadsheet_Viewer` cannot see its sandboxed iframe fail, so a grid request that fails in transit still reports `loaded`. Contract: `rsx:man document_search` (THE PREVIEW_LOADED EVENT), `rsx:man thumbnails`.

---

## Showing a document's text

```jqhtml
<Document_Text_Preview $attachment_id=this.data.doc.id />
```

The sibling of `Document_Preview`: same attachment, same subscribe-then-swap story, but it renders the **extracted text** instead of the picture. Args are `$attachment_id` (**required** - it throws without it) and `$width`/`$height` (px; omitted = fill the container). It has no public methods and one event, `preview_loaded` `{attachment_id, state, length}`, emitted for every state below (see **`preview_loaded`** above).

Four states, one of which is always painted:

| State | When | Renders |
|---|---|---|
| loading | the first fetch has not answered | `(Loading...)` |
| `pending` | no index row yet - extraction is queued | `(Extracting Text...)` |
| `available` | text was extracted | the text, newlines preserved |
| `error` / `unsupported` | the last extraction attempt FAILED, a degraded upload, a file type carrying no text, an extraction that yielded nothing, or a gate refusal | `(Document Text Unavailable)` |

The three unavailable causes collapse into one notice on purpose: they are the same answer to a reader. An operator tells them apart with `rsx:search:reindex --status` and `rsx:documents:failed`. The event keeps one distinction the notice drops: a failed extraction is `failed`, text that simply does not exist is `unavailable`.

**The text pane is `Text_Viewer`'s, by contract.** Both components include the framework mixin `rsx-preview-text-pane` (monospace, 13px, `pre-wrap`, colour inherited on a transparent surface - in scope in every SCSS compilation, nothing to import; skill `rspade:scss-rules`), so an extraction reads exactly as a text file does under `<Document_Preview>`. Style the frame you mount it into (its size, its surface), not the text:

```jqhtml
<div class="my-panel__text">
    <Document_Text_Preview $attachment_id=this.data.doc.id />
</div>
```

Behind it is `File_Preview_Controller::get_extracted_text`, returning `{status, text}` in the same vocabulary as `GET /api/v1/files/:key/text`. It runs the **content** gate cascade - `file.thumbnail.authorize` **then** `file.download.authorize`, the pair `/_download` runs - not the metadata gate `get_preview_info` runs, because extracted text is often the whole document. Text is returned uncapped up to `config('rsx.search.max_text_bytes')`.

---

## Viewer resolution (server-side) and adding a viewer

`Document_Preview` calls `File_Preview_Controller::get_preview_info()` in `on_load()`; the SERVER picks the viewer by mime from `config('rsx.preview.viewers')` — an fnmatch map resolved by the shared **mime-map precedence** (below), with `'*' => 'Icon_Viewer'` as the fallback so every mime resolves to something:

```php
'application/pdf'  => 'Pdf_Viewer',          // + Office mimes (via a PDF rendition)
'application/vnd.ms-excel*' => 'Spreadsheet_Viewer',   // + the other workbook mimes
'image/*'          => 'Image_Viewer',
'text/markdown'    => 'Markdown_Viewer',     // exact mime - beats the text/* glob
'text/*'           => 'Text_Viewer',         // the file's own characters
'*'                => 'Icon_Viewer',
```

**`Markdown_Viewer` shows a `.md` file as the DOCUMENT its author wrote** - GitHub-flavoured headings, tables, task lists, fenced code, autolinks - rather than as its source characters. Its entry is the exact mime, which beats the `text/*` glob that would otherwise render it as raw text. The markup comes from `File_Preview_Controller::get_markdown_html`, which runs the same **content** gate cascade as `get_extracted_text`.

**It waits on nothing**, which makes it the odd one out among these viewers: there is no rendition on disk and no background worker, the parse happens inside the request over a source capped at `rsx.search.max_text_bytes`, and the endpoint's answer is always final. A source past the cap is reported (`truncated: true`), never silently shortened.

**TWO INDEPENDENT SANITISING LAYERS produce the markup**, in `Markdown_Rendition::render()`, and the pair is deliberate - they fail differently:

1. **The parser, configured closed.** `league/commonmark` with `html_input => 'strip'` (a raw `<script>` or `<div>` in the source is DISCARDED, not passed through) and `allow_unsafe_links => false` (no `javascript:` href is emitted), plus a `max_nesting_level`. This layer is configuration: a regression in it is silent.
2. **HTMLPurifier over the OUTPUT**, with an explicit allowlist of exactly the elements GFM emits - `h1`-`h6`, `p`/`br`/`hr`, `em`/`strong`/`del`/`code`/`pre`, `blockquote`, lists incl. the disabled task-list `input`, `a[href|title]` and `img[src|alt|title]` on http/https/mailto, and the table elements with `align`. No `class`, no `style`, no `id`. It does not care what produced the markup, and an element that appears from anywhere is dropped by DEFAULT.

Because the markup is sanitised at the source, the component interpolates it with `<%!= %>` and nothing re-sanitises client-side. **Never hand-roll a second markdown path** - `Markdown_Rendition::render()` is the framework's one call.

**`Text_Viewer` reads the EXTRACTION, not the inline URL** — and that is the design point, not an implementation detail. The text pipeline has already decoded the file's charset and capped the content at `rsx.search.max_text_bytes`; fetching the raw bytes would hand the browser an undecoded string of unknown encoding and unbounded length, and would need its own realtime wiring to notice the pipeline finishing. Reading the extraction gets a decoded, bounded string and the PENDING -> EXTRACTED swap for free. The cost is honest and on screen: a just-uploaded file shows `(Extracting Text...)` for a second or two. `(This file is empty)` is a **distinct** state from `(Text Unavailable)` — an empty file is a fact about the file, not a fault in the preview.

Because it has a viewer, **`text/*` classifies as `FILE_TYPE_DOCUMENT`** (`FILE_TYPE_TEXT` survives as an enum value nothing assigns).

Register your own in `rsx/resource/config/rsx.php` under the same key — for a mime the framework does not cover, or to take one away from the framework's viewer:

```php
'preview' => ['viewers' => [
    'application/vnd.custom.cad' => 'Cad_Viewer',   // simple, manifest-resolved component name
]],
```

An application entry beats every framework entry for the mimes it matches (see **Replacing a framework pipeline** below); `'*'` is only the fallback.

The built-ins are rendered by the template; **any other name is instantiated dynamically** into the `$sid="viewer"` host with `{attachment_id, url, extension, file_name, page, fit}` (the framework's own `Spreadsheet_Viewer` and `Markdown_Viewer` take this path and read only `attachment_id`). So a custom viewer is an ordinary jqhtml component that takes those args and — if it wants the page UI to work — implements `set_page`/`get_page`/`get_pages` and fires `page_changed`.

**A viewer reports for itself.** It triggers `preview_loaded` `{state, pages, …}` when its content is on screen (`loaded`), when it could not show it (`failed`), and for a notice it will replace by itself (`preparing`, then again when it has) — and it has reported *something* by the time it is ready. Asynchronous work is either awaited in an `async on_ready()` (jqhtml holds `ready` until it returns — `Pdf_Viewer` does this) or announced with `preparing` first (`Spreadsheet_Viewer` does this while its iframe loads). `Document_Preview` adds `attachment_id` and `render_status`, and reads a payload with no `state` as `loaded`.

```javascript
class Cad_Viewer extends Component {
    async on_ready() {
        try {
            await this.draw(this.args.url);
            this.trigger('preview_loaded', { pages: 1, state: 'loaded' });
        } catch (e) {
            this.$sid('error').show();
            this.trigger('preview_loaded', { pages: 0, state: 'failed' });
        }
    }
}
```

**A viewer that never reports still does not strand the host**: once a dynamically instantiated viewer is `ready` and has said nothing, `Document_Preview` reports `loaded` for it (`pages` from `get_pages()`, else 1). That is a lifecycle fact, not a timer — and it is a safety net, not the contract: a viewer that fills itself in after `ready` without saying `preparing` is reported `loaded` early. A viewer whose lifecycle **throws** never becomes ready; that is a console error and nothing is reported for it.

**PDF renditions**: `/_preview/pdf/:key` serves the PDF pdf.js actually loads, and it is **serve-only — it never converts**. A PDF blob is served as-is; a mime whose `rsx.documents.renderers` renderer produces a PDF (and is switched on) is served from the cached rendition at `tmp/renditions/{blob-hash}.pdf` (LRU-swept to `rsx.preview.quota_max_bytes`) **but only when the blob is RENDERED** — otherwise it 404s naming the render state. Anything else is 415. A RENDERED blob whose rendition was LRU-evicted re-queues itself and 404s for that one request, rather than showing an error over a cache eviction. The route is **dual-gated** (`file.thumbnail.authorize` AND `file.download.authorize`), so your file-access hooks apply to previews exactly as they do to downloads. pdf.js itself is lazy-served from `/_preview/pdfjs.mjs` (+ `pdf_worker.mjs`) out of the committed `node_modules` and is **never bundled**.

## Showing extracted text BESIDE a preview

Ask the attachment, never the extraction status: **`$attachment->should_show_text_preview()`**, carried on both `get_preview_info()` and `get_extracted_text()` (the second so a caller already holding the text needs no second round trip). It is false when there is no extracted text, when the preview ALREADY IS the text (`text/*`, markdown included — a pane beside it repeats every character), and when the extraction is not legible to a human (a spreadsheet flattens to an undelimited run of cell values). The last two are mime globs in `config('rsx.preview.text_preview_suppressed')`.

**It is advice about display, never authorization** — extraction still runs for every one of these, the text stays searchable, and `get_extracted_text()` returns it whatever the flag says.

**`$attachment->can_open_inline()`** is the neighbouring question an "Open in Browser" link asks, and it is NOT the same one: it reads `config('rsx.preview.browser_inline')` — what a BROWSER can display in a tab of its own — so it disagrees with the viewer registry on purpose (a `.docx` previews here because the worker converts it, and downloads in a browser tab). It cannot come from `file_type_id` either, since `text/*`, Word and Excel are all `FILE_TYPE_DOCUMENT` and disagree. It ships to JS as a derived property alongside `is_image` / `is_video` / `is_document` (`rsx:man model`, DERIVED PROPERTIES).

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

Anything outside the contract fails loud. `preview_rendition` runs AFTER the auth gates; `thumbnail_render` runs BEFORE the framework renderer registry. **Prefer a registry entry** (next section) for anything decided by TYPE: the queue sees a renderer's switch and soft check, never a chain's code — the soft check cannot run an `extract_text` handler, so it asks the registry's extractor for the type.

**Where they run.** `extract_text` fires **inside the render worker's child process** (command line, never a web request) and **inside the text-extraction stage bound**: the handler shares `rsx.documents.stage_seconds` (20s) with the rest of the extraction, and one still running at the bound is interrupted, the attempt recorded FAILED and retried. A handler that calls a slow external service answers inside the bound, or the site raises it. Its `path` is always the SOURCE BLOB (the rendition shortcut is applied only after the chain declines). `thumbnail_render` still runs FIRST — ahead of both the rendition path and the renderer registry — and its `path` is the source blob too, so a handler that takes over an Office mime behaves exactly as it always did; it is **not fired at all** for a PENDING or FAILED blob, which serve the placeholder without entering the render path. `preview_rendition`'s contract is unchanged; only the framework path behind it became serve-only, so a handler that converts on demand still works.

---

## Replacing a framework pipeline for a file type

Four mime-keyed registries — `rsx.documents.renderers`, `rsx.search.extractors`, `rsx.preview.viewers`, `rsx.thumbnails.renderers` — resolve through ONE matcher, `Rsx_Mime_Map::match()`. **Precedence, lowest to highest: framework broad < framework specific < application broad < application specific.** An application entry matching the mime beats every framework entry however broad its glob; inside a layer an exact mime beats a glob, a glob with more literal characters beats a shorter one, and a tie goes to the later line. `'*'` is the fallback only. Line order means nothing else. So taking a type away from the framework is ONE line in `rsx/resource/config/rsx.php`.

| Seam | Replaces |
|---|---|
| `rsx.documents.renderers` | how the worker makes the rendition (cached file, thumbnail source, what `/_preview/pdf` serves) — and whether the type gets one at all |
| `rsx.preview.viewers` | which component `<Document_Preview>` shows. A new PDF-rendered type needs `'Pdf_Viewer'` here too, or it falls to `Icon_Viewer` |
| `rsx.search.extractors` | how its text is extracted |
| `rsx.thumbnails.renderers` | the raster step for a type whose picture does NOT come from a rendition |
| `document.extract_text` / `.preview_rendition` / `.thumbnail_render` | a per-file / per-request decision (above) |

A type the framework does not know must also reach the pipeline as its mime: add its extension to `rsx.files.document_mime_by_extension`. Contract tables: `rsx:man documents` (MIME MAP PRECEDENCE, RENDERERS, REPLACING A FRAMEWORK PIPELINE FOR A FILE TYPE).

## Writing and registering a custom renderer (and a soft-fail check)

A renderer extends `Rsx_Document_Renderer_Abstract` **directly** (it is `#[Monoprogenic]` — extending `Libreoffice_Document_Renderer` is refused; call its `render()` to reuse it). All static:

- `render($source_path, $target_path, ?$extension)` — write the rendition (create the directory, publish by rename). The source is the extensionless blob; `$extension` is an attachment's. Runs in the worker's child under the render stage bound — spawn binaries with `Document_Stage_Bound::run_process($argv)`. **Any throw is HARD** (charged); it cannot report soft.
- `rendition_format()` — `'pdf'` (default; feeds `Pdf_Viewer` and the thumbnail). `'html'` is reserved to the framework spreadsheet renderer.
- `is_enabled()` — false = deferred, uncharged, never retried from a view.
- `soft_failure($source_path): ?string` — **the opt-in.** Null = proceed; a string = recorded FAILED, not charged, checked again on the backoff and on every view. **Only look**: never run the tool, build an image or parse the file. `static::source_unreadable($path)` and `Document_Soft_Checks` cover the common checks.

```php
class Cad_Document_Renderer extends Rsx_Document_Renderer_Abstract
{
    const BINARY = '/usr/local/bin/dwg2pdf';

    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        ensure_directory(dirname($target_path));
        $tmp = $target_path . '.tmp.' . bin2hex(random_bytes(4));
        $process = Document_Stage_Bound::run_process([self::BINARY, $source_path, $tmp]);
        if (!$process->isSuccessful() || !is_file($tmp) || !rename($tmp, $target_path)) {
            @unlink($tmp);
            throw new \Exception('dwg2pdf failed: ' . trim($process->getErrorOutput()));
        }
    }

    public static function soft_failure(string $source_path): ?string
    {
        if (!is_executable(self::BINARY)) {
            return 'dwg2pdf is not installed or not executable';
        }
        return static::source_unreadable($source_path);
    }
}
```

A text extractor opts in the same way: `Rsx_Text_Extractor_Abstract` carries the same `soft_failure()` and `source_unreadable()`, and `extract($source_path, $mime)` throws for hard or `Rsx_Extraction_Unsupported_Exception` for "impossible by design" (worked `Cad_Text_Extractor`: `rsx:man document_search`, CUSTOM EXTRACTORS).

Registration, all in `rsx/resource/config/rsx.php`:

```php
'files'     => ['document_mime_by_extension' => ['dwg' => 'application/x-dwg']],
'documents' => ['renderers'  => ['application/x-dwg' => 'Cad_Document_Renderer']],
'preview'   => ['viewers'    => ['application/x-dwg' => 'Pdf_Viewer']],
'search'    => ['extractors' => ['application/x-dwg' => 'Cad_Text_Extractor']],
```

Uploaded while `dwg2pdf` is missing → FAILED "dwg2pdf is not installed or not executable", **no attempt spent**; install it and the next check — or the next person to open the preview — renders it. A drawing the tool cannot convert fails hard, three attempts. The document sandbox wraps only soffice/pdftotext; your converter runs on the host unless you contain it.

---

## Text extraction lifecycle

**Extraction is asynchronous — never expect text right after an upload.**

Two facts, kept apart: `_file_storage.is_indexed` says whether text is **owed**, and the `_search_indexes` row says what the **last attempt found**. There is **no stored PENDING status** — a blob with no row has not been attempted. The render worker above extracts in the same pass as the rendition, under the same queue and the same attempt counter. (`Search_Index_Service::extract_storage($storage, ?$rendition_path)` is the unit of work the worker's child runs — command line only.)

**Two master switches, governed separately.** `config('rsx.search.enabled')` governs extraction and is also the worker's kick switch. `config('rsx.libreoffice.enabled')` governs rendering through soffice; with it off, PENDING rows stay queued, **deferred** (offered again every retry interval, nothing charged — the first pass after soffice arrives renders them) while PDFs and plain text keep extracting normally.

**Word-processing documents no longer run soffice for text**: `pdftotext` reads the rendition the same pass just produced. Spreadsheets and presentations keep their own `fods`/`fodp` conversion, because flat XML is the only route that reads every sheet and slide. Net: one soffice run for a Writer document, two for a sheet or deck — it used to be three for all of them.

A row is the outcome of the last attempt, one of three:

- **EXTRACTED** — text is stored (capped at `rsx.search.max_text_bytes`, 2MB; over-cap is TRUNCATED and recorded, never silently dropped and never FAILED).
- **FAILED** — an extractor ran and genuinely failed (missing blob, broken binary), ran past the stage bound, or died with its process. With `is_indexed = 0` beside it the worker will retry; with `is_indexed = 1` the attempts are spent and only `rsx:search:reindex` re-queues it.
- **UNSUPPORTED** — no configured extractor matched the mime. Images and archives land here, **not** in FAILED. There is no OCR.

That distinction is the point: do not build retry or alerting logic around images. `config('rsx.search.extractors')` is the fnmatch mime map (PDF → pdftotext, workbooks → PhpSpreadsheet, other Office/RTF → LibreOffice, `text/*`/csv/json → plain), resolved by the shared precedence. A word-processing document with a PDF rendition is always read by pdftotext from that rendition, ahead of the map.

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

**Two different failures, two different commands.** A RENDER failure is the renderer unable to produce the rendition (`_file_storage.render_status_id = FAILED`, reason in `render_error`); an EXTRACTION failure is an extractor unable to pull text (a `_search_indexes` row with status FAILED). `rsx:documents:failed` prints both tables with each one's remedy underneath, so a wrong guess costs nothing — after a first table of **soft** failures (tool or source unavailable), whose only remedy is fixing what the reason names.

`rsx:documents:*` — the render half (`rsx:man documents`):

| Command | Use it when |
|---|---|
| `rsx:documents:status` | first: the work queue (due now / waiting for a retry / could not run: tool or source unavailable / deferred / failed, out of attempts), render + extraction counts, rendition cache, next worker run |
| `rsx:documents:failed [--limit=N]` | a document shows the icon forever — the reason is printed with the attempts charged and the next attempt time, or `none - out of attempts` (`--limit=0` = all) |
| `rsx:documents:rerender --failed` | after fixing the cause of render failures — every FAILED blob, due now with a full set of attempts |
| `rsx:documents:rerender --storage=ID` / `--attachment=ID` | one document; open pages are notified immediately |
| `rsx:documents:rerender --all` | you changed how documents convert. Every blob that ever entered the pipeline — never the NOT_REQUIRED images |

Exactly one selector, or it exits 1. `rerender` **deletes each rendition file first** — without that, the worker short-circuits on the file already on disk and re-render is a no-op. It renders nothing itself: it re-queues with the attempts reset and kicks the worker.

`rsx:search:reindex` — the text half, exactly ONE selector:

| Flag | Use it when |
|---|---|
| `--status` | first: report index counts, re-queue nothing |
| `--attachment=<id>` | one document looks wrong (materializes external bytes) |
| `--storage=<id>` | you already know the blob id |
| `--failed` | a dependency was broken/missing and has been fixed (re-queued blobs get a full set of attempts) |
| `--below-version=<N>` | an extractor changed; `rsx.search.extractor_version` was bumped |
| `--all` | last resort — the whole corpus |

Start with `--status`. A large `UNSUPPORTED` count is usually correct (images); a large `FAILED` count means a missing binary.

---

## Dependencies

`libreoffice poppler-utils fonts-liberation fonts-dejavu-core` — verified by `php artisan rsx:health` (exit 1 iff a FAIL; WARN/INFO are advisory). No poppler → PDFs go FAILED. No LibreOffice → Office extraction fails and Office previews return 415. Missing fonts → conversions render with substituted glyphs.

**The binaries can be sandboxed, and on a site that accepts outside uploads they should be.** `LIBREOFFICE_SANDBOX=docker` (`rsx.libreoffice.sandbox`) routes every soffice and pdftotext spawn through `Document_Sandbox::command()`, which wraps it in one throwaway `docker run --rm` per file - no network, read-only root, `--cap-drop ALL`, no-new-privileges, memory and pid caps, tmpfs `/tmp`, the worker's own uid:gid, and the per-invocation work dir as the ONLY mount (bound at its own absolute path, so the argv is identical in both modes). The host then needs no LibreOffice and no poppler-utils; what it needs is a docker daemon the worker's user can reach. The image is the framework's own minimal converter image `rspade/rspade-docconvert` (soffice, pdftotext and fonts on a bare distribution base, nothing else). It is **built on first use** - the first sandboxed document pays for the build - or up front with `php artisan rsx:heal document-sandbox-image`. An image of your OWN is never built: until you pull it, every document needing soffice or pdftotext soft-fails ("the document sandbox image ... is not present", nothing spent) and is processed at the first check after it arrives. `rsx:health` carries a **Document Sandbox** row - for `none`, a WARN in every mode once any live portal user exists (somebody outside the organisation can upload), otherwise INFO in development and WARN on a sealed box - and five rows (client, daemon, image, one real hardened run, memory cap) when it is on, of which an absent *framework* image is a WARN. **Not covered**: PhpSpreadsheet (a workbook is read in-process, so there is no process to contain), and **PDF thumbnails** - Imagick rasterises page 1 through Ghostscript inside the PHP process, on the host in either mode, contained only by the shipped ImageMagick policy; take it over through the `document.thumbnail_render` chain if uploaded PDFs must never be parsed on the host. Details: `rsx:man libreoffice`.

**No key under `rsx.libreoffice` bounds a conversion.** `soffice` and `pdftotext` carry no limit of their own: each runs inside one of the worker's two stages and is bounded with it, by `rsx.documents.stage_seconds` (see **The work queue** above). Under the sandbox, an expired stage also kills the named container, because the process PHP stops is only the docker client.

---

## Troubleshooting

- **Nothing renders, no error** — the attachment id is wrong, or the mime resolved to `Icon_Viewer` (no viewer registered for it).
- **Handler never fires on `loaded`** — you listened for the reserved lifecycle event; it is `preview_loaded`.
- **`preview_loaded` fired but there is no picture** — it reports every outcome; check `state` (`preparing`, `failed` and `unavailable` are reports too).
- **A custom viewer is reported `loaded` before its content appears** — it reached `ready` without reporting; await the work in `async on_ready()` or trigger `preparing` first.
- **Preview stuck on "Preparing preview…"** — the blob is PENDING (or FAILED with a retry this view started) and no attempt has reported. `rsx:documents:status`: if "Due now" never falls the worker is not running (`rsx:task:process`, `rsx:health` "Document Render Backlog"); PENDING with nothing due means `rsx.libreoffice.enabled` is false and the blobs are deferred.
- **A document shows the extension icon forever** — that IS the correct picture for PENDING or FAILED. `rsx:documents:status` says which; `rsx:documents:failed` names the reason and whether the worker will try again by itself. Out of attempts → fix the cause, then `rsx:documents:rerender --failed`.
- **`render exceeded 20 seconds` on every attempt** — the document needs longer than the stage bound on this hardware. If it is an ordinary document for the site, raise `rsx.documents.stage_seconds` (and `kill_seconds` above twice that), then rerender.
- **`… runs only in a command-line process …`** — `render_storage()` / `extract_storage()` called from a web request. Call `Document_Render_Service::process_storage($storage)`.
- **You set `is_indexed = 0` or PENDING and nothing happens** — the blob is not in the queue. `mark_work_owed()` / `requeue_render()`.
- **404 from `/_preview/pdf/:key`** — not an error condition: the blob is not RENDERED yet and the body names the state. The viewer never requests it in that state.
- **415 from `/_preview/pdf/:key`** — no `rsx.documents.renderers` entry matches the pipeline mime, its renderer makes HTML (a workbook: `/_preview/sheet/:key`), or it is switched off.
- **"… is not installed or not executable" / "the document sandbox image … is not present" / "the source file is missing or unreadable"** — a SOFT failure: no attempt spent. Fix what it names; the next check or the next preview view processes it. Nothing to re-queue.
- **Your viewer / extractor / thumbnail renderer entry is ignored** — check the pipeline mime the type actually resolves to (`rsx.files.document_mime_by_extension`); an application entry always beats a framework one for a mime it matches. For extraction, a word-processing document with a rendition is read by pdftotext ahead of the map.
- **Boot error "rsx.preview.convertible is no longer read by the framework"** — move each pattern into `rsx.documents.renderers` (`'pattern' => 'Libreoffice_Document_Renderer'`).
- **403 on a preview that a download allows** — the route is dual-gated; check `file.thumbnail.authorize`, not just `file.download.authorize`.
- **"File reads are disabled: no file.… gate handler is registered"** — both read gates are mandatory (`Rsx_File_Gates`); register a handler for the one named, even a bare `return true` for public files.
- **`get_extracted_text()` returns null forever** — extraction is off (`rsx.search.enabled`), or the cron is not running; confirm with `rsx:search:reindex --status`.
- **Extracted text is empty for a PDF** — scanned/image-only PDF (see above). No OCR exists.
- **Search returns another tenant's files** — missing `where('site_id', ...)`.
- **Text is pending right after an upload** — correct, not a symptom. The whole pipeline is asynchronous.

Details: `php artisan rsx:man document_search` · `documents` · `libreoffice` · `thumbnails`. Related: `rspade:file-attachments`, `rspade:event-hooks`, `rspade:background-tasks`, `rspade:jqhtml`.
