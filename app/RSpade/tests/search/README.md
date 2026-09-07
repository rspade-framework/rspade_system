# search - document text extraction & full-text search

## Domain

The framework-core document text-extraction pipeline: it pulls text out of documents
(PDF, Office, plain text) into `_search_indexes`, keyed on the deduplicated
`_file_storage` blob so identical bytes are extracted exactly once. An app resolves
"attachments whose text matches X" via `File_Attachment_Model::search_text()`.

Shipped in the Document Pipeline epic, Batch 2 (extraction core).

## Source under test

- `app/RSpade/Core/Search/Search_Index_Model.php` - the vessel (re-parented to
  `Rsx_Model_Abstract`; `status_id` enum EXTRACTED/FAILED/UNSUPPORTED; `$realtime_silent`); `search()` for filtering and `search_ranked()` for ranking - the latter selects MySQL's
  score as `relevance` and pre-orders by it).
- `app/RSpade/Core/Search/Search_Index_Service.php` - the `extract_storage()` unit of work
  (filter chain -> rendition shortcut -> registry -> UNSUPPORTED terminal; LibreOffice
  master-switch gate; always sets `is_indexed=1`). The QUEUE and the worker belong to
  `Document_Render_Service` (tests/documents).
- `app/RSpade/Core/Search/Rsx_Text_Extractor_Abstract.php` + `Pdftotext_Text_Extractor`,
  `Libreoffice_Text_Extractor`, `Plain_Text_Extractor`.
- `app/RSpade/Core/Files/File_Storage_Model.php` - `is_indexed` queue flag, prompt kick on
  `find_or_create()` (now `Document_Render_Service::kick()`), `get_search_index()`.
- `app/RSpade/Core/Files/File_Attachment_Model.php` - `get_extracted_text()`,
  `get_extraction_status()` (no materialize), static `search_text()`.
- `app/RSpade/Core/Files/Libreoffice.php` - shared `find_soffice()`.
- `app/RSpade/Core/Rsx.php` - `trigger_resolve()` (first-non-null-wins resolve primitive).
- `Commands/Rsx/Search_Reindex_Command.php` - `rsx:search:reindex` (re-queue / `--status`).
- Migrations: `_search_indexes` (+status_id/error/extractor_version, -site_id), `_file_storage`
  (+is_indexed).

## Behavior defined by

`php artisan rsx:man document_search` (authored in Batch 6). Until then the plan doc
(`docs.dev` / `hashed-whistling-pumpkin.md`, BATCH 2) and the CR
(`docs.dev/external_requests/2026_07_16_document_text_extraction.md`) are the contract.

## Testable surface

- **php** - status classification, dedup single-row, FULLTEXT `search_text()` roundtrip
  (requires committed rows -> `$requires_db_reset`), relevance ranking via `search_ranked()`
  (score exposed, ordering, composition, `search()` unchanged - also committed rows, written
  straight into `_search_indexes` since `indexable_id` carries no FK), reindex re-queue, the
  filter-chain intercept contract + the live-fixture safety property, `trigger_resolve`
  semantics, and the real pdftotext / soffice extractors (probe-and-skip).
- **cli** - `rsx:search:reindex` selector validation and `--status` table (covered indirectly by
  the reindex php test via `Artisan::call`; a dedicated cli test is a candidate).
- **playwright / http** - none (no framework UI surface; the app owns all search surfaces).

## Fixtures are LIVE in dev

`#[OnEvent]` fixtures are manifest-discovered and register on the real event chain. Both
fixtures are guarded so they are inert in production use:
`Rsx_Trigger_Resolve_Fixture_Handler` uses a private event name no framework code triggers;
`Search_Extract_Text_Fixture_Handler` intercepts ONLY paths containing `rsx_test_intercept`
(real blob paths are content hashes and never match) - this is itself asserted by
`Search_Filter_Chain_Test::test_real_file_uses_framework_extractor_not_fixture`.
