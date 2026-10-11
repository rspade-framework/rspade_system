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

The worker's QUEUE is one column on the same row, `_file_storage.process_due_at`: NULL means
the worker has nothing to do for the blob, a time means it owes work and may be attempted from
then. `process_attempt_count` counts the attempts charged since the blob entered the queue. One
document that cannot be processed must never stop the documents behind it, and four rules see
to that: the attempt is charged (count + 1, due time one retry interval ahead) BEFORE the work
starts; the work runs in a child process of its own (`rsx:documents:process_one`) that the
parent kills, with everything it started, after `rsx.documents.kill_seconds`; each stage inside
the child - render, text extraction - is bounded by `rsx.documents.stage_seconds`; and after
`rsx.documents.max_attempts` failed attempts the blob leaves the queue FAILED until an operator
re-queues it. A failed attempt reads as FAILED at once, so no screen waits on it.

Shipped in the Async Document Rendering epic: Phase 1 (schema, model state, the worker),
Phase 2 (render-state-aware serving - the thumbnail endpoint, the rendition endpoint and the
preview-info payload), Phase 3 (the client half - `File_Attachment_Model::fetch()`, the
`<Attachment_Thumbnail>` component, and `Document_Preview`'s waiting states) and Phase 4 (the
operator commands `rsx:documents:status|failed|rerender`).

## Source under test

- `app/RSpade/Core/Files/Document_Render_Service.php` - the `#[Task] #[Exclusive]`
  10-minute worker (`render_pending`, which takes the earliest due blob until none is due),
  `process_storage()` (charge the attempt, run the child under the kill limit, read the row back
  and settle it: done / retry / failed / waiting / taken), the `render_storage()` unit of work
  the child runs, the thumbnail-cache purge, the realtime notification, the `#[Health_Check]`,
  `queue_statistics()` and `get_statistics()`.
- `app/RSpade/Core/Files/File_Storage_Model_Abstract.php` - the `render_status_id` enum
  (NOT_REQUIRED / PENDING / RENDERED / FAILED), `rendered_at`, `render_error`, the idempotent
  `request_render()`, and THE WORK QUEUE: `work_owed_values()` / `mark_work_owed()` (the one
  statement of "this blob owes work"), `work_due_query()`, the guarded `charge_work_attempt()`,
  `close_work()` and `defer_work()`.
- `app/RSpade/Core/Files/Document_Stage_Bound.php` (+ `Document_Stage_Timeout_Exception`) - the
  per-stage wall-clock bound (a pcntl alarm whose handler throws; command line only) and
  `run_process()`, the one way an external document binary is run.
- `app/RSpade/Core/Console/Rsx_Artisan.php` - `run_bounded()`: an artisan child under a hard
  limit, killed with its whole process group (`timeout --signal=KILL`), `KILLED_AT_LIMIT`.
- `app/RSpade/Commands/Documents/Documents_Process_One_Command.php` - the hidden child command.
- `app/RSpade/Core/Files/File_Attachment_Model.php` - `fetch()` / `portal_fetch()` (the gated ORM
  read behind `File_Attachment_Model.fetch(id)` in JavaScript, with the blob embedded), the create /
  `relink_storage()` hook that queues a convertible blob, `is_convertible_mime()`, `get_render_status()`, the `?v=`
  cache-buster on `get_thumbnail_url*()`, and render-aware `has_thumbnail()`.
- `app/RSpade/Core/Files/Document_Sandbox.php` - THE one place a document binary's command
  line is built: `mode()` / `is_docker()` / `command()` / `hardening_flags()` /
  `container_name()` / `kill()`, plus `ensure_image()` / `is_framework_image()` (the framework's
  own converter image `rspade/rspade-docconvert` is BUILT on first use; an operator's own image
  is theirs to pull), and the `#[Health_Check('Document Sandbox')]` that reports the posture and,
  under `docker`, proves the client / daemon / image / one hardened run / the memory cap, beside
  its `#[Health_Heal('document-sandbox-image')]`. Every soffice and pdftotext spawn in the
  framework goes through it.
- `app/RSpade/Core/Search/Search_Index_Service.php` - `extract_storage()` with its optional
  `$rendition_path` (a Writer document is extracted from the rendition by `pdftotext`
  instead of a second soffice run).
- `app/RSpade/Core/Files/Markdown_Rendition.php` - markdown to sanitised HTML, the framework's
  one markdown-to-HTML call: league/commonmark (core + GFM) configured closed, then HTMLPurifier
  over the output with an explicit GFM allowlist. Two independent layers, because they fail
  differently.
- `app/RSpade/Core/Preview/Markdown_Viewer.{jqhtml,js}` - the text/markdown viewer. It waits on no
  worker: the parse happens inside the request, so it has no PENDING state.
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
- `app/RSpade/Commands/Documents/` - the operator surface: `rsx:documents:status` (the work
  queue, render, extraction, rendition-cache and worker-schedule state), `rsx:documents:failed`
  (the failures of both halves, with their reasons, the attempts charged and the next attempt or
  that none is coming, and their two different remedies) and `rsx:documents:rerender` (the
  operator's reset: PENDING, due now, attempts back to 0 - it deletes the rendition first, or
  `render_storage()` would short-circuit on the file already on disk).
- `rsx/app/dev/attachment_thumbnail/` - the dev page the Playwright test drives.
- Migration: `2026_08_22_090059_add_render_status_to_file_storage` (columns, index, backfill).
- Migration: `2026_10_11_190325_add_process_queue_to_file_storage` (`process_due_at`,
  `process_attempt_count`, `idx_process_due`, backfill).

## Man pages that define behavior

`rsx:man thumbnails`, `rsx:man document_search`, `rsx:man libreoffice`, `rsx:man file_upload`,
`rsx:man config_rsx`. (Updated in the epic's documentation phase.)

## Testable surface

| Area | Type | Notes |
|------|------|-------|
| The document sandbox (the command seam, the converter image and its build-on-first-use, the health rows, a real containerised render) | php | implemented (the two container tests skip where a hardened container will not start) |
| The blob render state machine (queue on create, dedup, idempotent request) | php | implemented (`Document_Render_Test`) |
| `render_storage()` outcomes (missing file, rendition short-circuit, a FAILED render run again, real soffice, extraction folded in) | php | implemented (`Document_Render_Test`) |
| Side effects of RENDERED (thumbnail-cache purge, realtime emission) | php | implemented |
| The work queue: born due, earliest-due order, the index the query reads, the up-front charge and its guard, deferral of switched-off work, re-queueing | php | implemented (`Document_Work_Queue_Test`) |
| Attempts: retry spacing, permanent failure at the third, the abandoned last attempt settled without running, a blob outside the queue never processed | php | implemented (`Document_Work_Queue_Test`) |
| The child process: success, a failing document passed by the drain, a crashed child, the hard kill reaping a grandchild | php | implemented (`Document_Work_Queue_Test`; real children, misbehaviour from `Document_Worker_Fixture_Handler`) |
| Paced passes: a drain that yields with work remaining and sets its own next run; no yield when the queue empties; the pacing config | php | implemented (`Document_Work_Queue_Test`; the twenty minutes are staged by setting `rsx.documents.drain_yield_after_minutes` to 0) |
| The stage bound: overrun, disarm, external binary stopped, both stages recorded FAILED | php | implemented (`Document_Stage_Bound_Test`, in-process with a one-second limit) |
| A hard kill under the docker sandbox; `process_storage()` from a php-fpm request | php / http | planned |
| Status-aware serving (placeholder `no-store`, rendition-sourced thumbnails, `?v=` changes, rendition 404 states, preview-info shape) | php | implemented |
| `File_Attachment_Model::fetch()` / `portal_fetch()` (payload shape, gate denial, no byte access) | php | implemented |
| `<Attachment_Thumbnail>` placeholder -> real-raster swap without reload | playwright | implemented |
| `rsx:documents:status` / `:failed` / `:rerender`, and the hidden child command's registration | cli | implemented |
| Markdown rendering and sanitisation (`Markdown_Rendition`), the text/markdown viewer entry, and `get_markdown_html` end to end | php | implemented |

## Running

```bash
php artisan rsx:test --framework --group=documents
```

```bash
node system/app/RSpade/tests/documents/playwright/attachment_thumbnail_swap.js
```

The real-binary test skips itself when `Libreoffice::find_soffice()` returns null.

`Document_Work_Queue_Test` spawns real child processes (one PHP boot per attempt) and its
hard-kill test waits out a kill limit of at least eight seconds; it empties the queue of
whatever the baseline carried before every test. `Document_Worker_Fixture_Handler` sits on the
real `document.extract_text` chain and acts only on a blob whose bytes begin with one of its
markers.

The Playwright test drives `/dev/attachment_thumbnail`, and **that route now ships CLOSED**: the
template's dev showcase declares `#[Auth('closed')]`, which nothing satisfies. The test reports the
closed gate rather than pretending to pass, so it cannot run as shipped. Running it means opening
that surface in your own tree (swap `'closed'` for a check of your own on
`Attachment_Thumbnail_Controller` and its action). Tracked in the backlog - see
`docs.dev/backlog/BACKLOG.md`, "Dev showcase is closed".
