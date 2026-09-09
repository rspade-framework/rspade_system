# preview - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| PREVIEW-VIEWER-RESOLUTION | viewer_for_mime maps pdf/office->Pdf_Viewer, image->Image_Viewer, unknown/null->Icon_Viewer | php | assorted mimes | expected viewer per mime | implemented | 2026-07-16 |
| PREVIEW-CACHE-PATH | rendition cache path is content-addressed on the blob hash | php | storage with hash | storage/rsx-renditions/{hash}.pdf | implemented | 2026-07-16 |
| PREVIEW-INFO-PDF | get_preview_info returns viewer + mime + type-safe URLs behind the thumbnail gate | php | seeded PDF + staff session | viewer Pdf_Viewer, urls carry key/extension | implemented (skips if no seeded user) | 2026-07-16 |
| PREVIEW-THUMB-INTERCEPT | a document.thumbnail_render handler's WebP bytes are served verbatim | php | marker source path | fixture WebP bytes returned | implemented | 2026-07-16 |
| PREVIEW-THUMB-LIVE-SAFETY | a live marker-guarded thumbnail fixture does NOT hijack a real (non-marker) thumbnail | php | text/plain attachment, real-shaped path | non-empty WebP, NOT the fixture bytes | implemented | 2026-07-16 |
| PREVIEW-RENDITION-INTERCEPT | a document.preview_rendition handler can report the unsupported contract | php | marker file_name attachment | ['unsupported'=>true] | implemented | 2026-07-16 |
| PREVIEW-RENDITION-DECLINE | a non-marker attachment is declined so the framework pipeline runs | php | ordinary file_name attachment | null | implemented | 2026-07-16 |
| PREVIEW-PDFJS-200 | /_preview/pdfjs.mjs streams the vendored pdf.js module bytes | http | GET /_preview/pdfjs.mjs | 200 text/javascript | implemented | 2026-07-16 |
| PREVIEW-WORKER-200 | /_preview/pdf_worker.mjs streams the vendored pdf.js worker bytes | http | GET /_preview/pdf_worker.mjs | 200 text/javascript | implemented | 2026-07-16 |
| PREVIEW-PDFJS-HEAD-200 | HEAD on a Response::file route succeeds with no body (regression for the BinaryFileResponse HEAD 500 - setContent('') threw on a file response) | http | HEAD /_preview/pdfjs.mjs | 200 text/javascript, empty body | implemented | 2026-07-21 |
| PREVIEW-UNKNOWN-KEY | an unknown rendition key never serves content | http | GET /_preview/pdf/{bogus} | not 200, not application/pdf (surfaces as a 500 dev error page, mirroring /_inline/:key) | implemented | 2026-07-16 |
| PREVIEW-RENDITION-DOCX-200 | a convertible document renders to a cached PDF over HTTP | http | GET /_preview/pdf/{docx key} + staff cookie | 200 application/pdf, cached file created | deferred (web server uses DEV db; seeding + auth cookie over HTTP would touch the dev db - covered in-process by the docx conversion verification + PREVIEW-CACHE-PATH) | 2026-07-16 |
| PREVIEW-RENDITION-BIN-415 | a non-convertible file has no rendition | http | GET /_preview/pdf/{bin key} + staff cookie | 415 | deferred (same reason as PREVIEW-RENDITION-DOCX-200; the 415 path is verified in-process) | 2026-07-16 |
| PREVIEW-RENDITION-CACHE-HIT | a second rendition request serves the cached PDF (touch, no reconvert) | php | two renditions of one docx | second serves cached file, mtime touched | deferred (needs soffice; in-process verification confirms cache creation - dedicated cache-hit test is a candidate) | 2026-07-16 |
| PREVIEW-CLEANUP-LRU | File_Rendition_Service.cleanup_renditions evicts oldest to quota | php | over-quota rendition dir | oldest files removed to quota | planned | 2026-07-16 |
| PREVIEW-SAMPLE-IMPORT | the import_sample_documents migration seeds the two sample attachments (PDF + DOCX) and links them to the first client when one exists | php | test-DB baseline (post-migration) | two attachments present; fileable linkage matches client-existence | MOVED to the application suite - the migration is the application's (`rsx/resource/migrations`), so the test is `rsx/tests/Sample_Document_Import_Test.php` | 2026-09-08 |
| PREVIEW-DOCUMENT-PREVIEW-SPA | /dev/document_preview renders the sample PDF via pdf.js (canvas, N>1 pages), paginates, and shows extracted text | playwright | sample PDF selected + manual index run | canvas nonzero, "Page 1 of N" N>1, next advances, textarea non-empty | implemented, NOT RUNNABLE AS SHIPPED - its route is `#[Auth('closed')]`; open the surface locally to run it (B-99) | 2026-08-24 |
| PREVIEW-TEXT-PENDING | an un-indexed blob reads as 'pending' with no text (absence of an index row IS the queue) | php | fresh .txt attachment, never extracted | status pending, text null | implemented | 2026-09-01 |
| PREVIEW-TEXT-AVAILABLE | an extracted blob returns its text verbatim | php | .txt attachment + extract_storage() | status available, text contains the marker | implemented | 2026-09-01 |
| PREVIEW-TEXT-UNSUPPORTED | a mime no extractor claims is 'unsupported', not an error | php | .bin attachment + extract_storage() | status unsupported, text null | implemented | 2026-09-01 |
| PREVIEW-TEXT-FAILED | a recorded FAILED extraction reads as 'error' (terminal - the component must not wait on it) | php | attachment whose blob is unlinked, then extract_storage() | status error, text null | implemented | 2026-09-01 |
| PREVIEW-TEXT-DEGRADED | preview_unavailable answers 'error' WITHOUT consulting the index (a text-free extraction of unparseable bytes would otherwise read 'available') | php | extracted attachment marked preview_unavailable | status error, text null | implemented | 2026-09-01 |
| PREVIEW-TEXT-CONTENT-GATE | get_extracted_text runs the CONTENT cascade: a refusing file.download.authorize denies the text while get_preview_info (thumbnail gate only) still answers | php | download gate returning false | ERROR_UNAUTHORIZED from get_extracted_text; get_preview_info returns its payload | implemented | 2026-09-01 |
| PREVIEW-DOCUMENT-PREVIEW-FIT | $fit="contain" scales the whole page into the host's BOUNDED box, where the default width fit overflows it vertically | playwright | /dev/document_preview: sample PDF, Fit toggle width -> contain | width fit canvas css height > frame height; contain fit canvas css height <= frame height and width within the frame | implemented, NOT RUNNABLE AS SHIPPED - its route is `#[Auth('closed')]`; open the surface locally to run it (B-99) | 2026-09-01 |
| PREVIEW-DOCUMENT-PREVIEW-RESIZE | growing the host RE-RASTERS the page instead of stretching the bitmap (the debounced ResizeObserver) | playwright | /dev/document_preview: sample PDF under contain, host grown to 1200x900 | canvas BACKING size (canvas.width/height) grows after the debounce settles | implemented, NOT RUNNABLE AS SHIPPED - its route is `#[Auth('closed')]`; open the surface locally to run it (B-99) | 2026-09-01 |
| PREVIEW-TEXT-PREVIEW-SWAP | "(Extracting Text...)" swaps to the extracted text over realtime, with no reload | playwright | /dev/document_preview: Reset Extraction, then Extract Now inline | notice "(Extracting Text...)" appears, then .Document_Text_Preview__text is non-empty and the notice is gone, page never navigates | implemented, NOT RUNNABLE AS SHIPPED - its route is `#[Auth('closed')]`; open the surface locally to run it (B-99) | 2026-09-01 |

## Spreadsheet_Preview_Test (php, transactions off) - a workbook previews as a grid

A spreadsheet has no pages, so a PDF of one is LibreOffice's PRINT view - page breaks through
the data, no gridlines, no headers - faithful to a print-out and unrecognisable as a grid.
Workbooks route to `Spreadsheet_Viewer` over an HTML rendition PhpSpreadsheet produces
in-process (owner ruling 2026-09-09).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sheetprev-01 | spreadsheet mimes route to the grid viewer | xls / xlsx / ods mimes | `Spreadsheet_Viewer`, and a .docx still resolves to `Pdf_Viewer` - the registry is an ordered fnmatch map and the generic Office patterns would otherwise take them | implemented |
| sheetprev-02 | the rendition is a grid carrying the values | a two-row workbook | a `<table>` with the headers, labels AND the numbers - unlike the search index, a preview shows the figures | implemented |
| sheetprev-03 | hostile cell content cannot execute | cells containing a script element, an onerror attribute and a javascript: anchor | no script element, no event-handler attribute, no anchor at all; the strings survive as escaped TEXT, which is what the cells genuinely contain | implemented |
