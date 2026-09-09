# search - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SEARCH-EXTRACT-PLAIN | plain-text blob extracts and is readable via the attachment | php | text blob w/ needle | status EXTRACTED, method Plain_Text_Extractor, get_extracted_text contains needle | implemented | 2026-07-16 |
| SEARCH-EXTRACT-UNSUPPORTED | a mime with no extractor -> UNSUPPORTED (not FAILED) | php | octet-stream bytes | status UNSUPPORTED | implemented | 2026-07-16 |
| SEARCH-EXTRACT-OVERCAP | plain text over max_text_bytes -> EXTRACTED, truncated + recorded (not FAILED) | php | text > cap | status EXTRACTED, no error, content capped, metadata truncated=true + original_bytes | implemented | 2026-07-21 |
| SEARCH-EXTRACT-SPREADSHEET | multi-sheet workbook -> all sheets + names + all cells (fods path) | php | 2-sheet .fods, spreadsheet mime | both sheet names AND both cell tokens present | implemented (skips if no soffice) | 2026-07-21 |
| SEARCH-EXTRACT-PRESENTATION | presentation -> slide text (fodp path, Text-encoded filter fails for Impress) | php | minimal .fodp, presentation mime | slide token present | implemented (skips if no soffice) | 2026-07-21 |
| SEARCH-EXTRACT-ENCRYPTED-PDF | password-protected PDF -> UNSUPPORTED with reason (not FAILED) | php | gs-encrypted PDF | status UNSUPPORTED, error mentions password | implemented (skips if no pdftotext/gs) | 2026-07-21 |
| SEARCH-TRUNCATION-METADATA | truncation metadata persists + roundtrips via get_metadata() | php | text > small cap | EXTRACTED, metadata truncated=true, original_bytes == true source size | implemented | 2026-07-21 |
| SEARCH-DEDUP-ONE-ROW | identical bytes share one blob and one index row (idempotent upsert) | php | two attachments, same content | one _search_indexes row; both EXTRACTED | implemented | 2026-07-16 |
| SEARCH-ROUNDTRIP | search_text() FULLTEXT resolves the attachment by content | php | extracted blob | match on needle, miss on unrelated token | implemented | 2026-07-16 |
| SEARCH-RANKED-SCORE | search_ranked() exposes a numeric, positive `relevance` on every match | php | 4 committed index rows, 2 carrying a per-run token | both matches carry relevance > 0 | implemented | 2026-09-07 |
| SEARCH-RANKED-ORDER | the builder is pre-ordered by relevance descending (term frequency ranks apart) | php | token repeated 6x vs once | the repeating row first, and its score strictly higher | implemented | 2026-09-07 |
| SEARCH-RANKED-COMPOSE | composing where() after search_ranked() keeps BOTH the score and the order | php | + indexable_type / status_id / whereIn clauses | same 2 rows, relevance present, still descending | implemented | 2026-09-07 |
| SEARCH-RANKED-SEARCH-UNCHANGED | search() is untouched: same match set, no relevance column | php | same token | identical id set; getAttributes() has no 'relevance' | implemented | 2026-09-07 |
| SEARCH-RANKED-NATURAL | NATURAL LANGUAGE mode ranks too (fixture stays under the 50% threshold) | php | same token, mode NATURAL LANGUAGE | 2 matches, repeating row first, score > 0 | implemented | 2026-09-07 |
| SEARCH-REINDEX-FAILED | rsx:search:reindex --failed re-queues failed blobs (is_indexed=0) | php | FAILED blob (deleted on-disk source) + Artisan::call | is_indexed flips 1 -> 0 | implemented | 2026-07-21 |
| SEARCH-CASCADE-DELETE | deleting the storage row (last attachment gone) removes its index row | php | extract, delete attachment | storage row gone AND index row count 0 | implemented | 2026-07-16 |
| SEARCH-EXTRACT-PDF | real pdftotext extracts a PDF text layer | php | minimal PDF w/ token | status EXTRACTED, method Pdftotext, content contains token | implemented (skips if no pdftotext) | 2026-07-16 |
| SEARCH-EXTRACT-OFFICE | real soffice extracts an Office (FODT) document | php | minimal .fodt w/ token | extracted text contains token | implemented (skips if no soffice) | 2026-07-16 |
| RESOLVE-FIRST-WINS | trigger_resolve returns the first non-null handler result (priority order) | php | data with a+b | 'from_a' | implemented | 2026-07-16 |
| RESOLVE-DECLINE | a null-returning handler passes to the next | php | data with b only | 'from_b' | implemented | 2026-07-16 |
| RESOLVE-ALL-DECLINE | all handlers decline -> null (caller runs terminal default) | php | empty data | null | implemented | 2026-07-16 |
| RESOLVE-NO-HANDLERS | an event with no handlers -> null | php | unknown event | null | implemented | 2026-07-16 |
| CHAIN-INTERCEPT-TEXT | a document.extract_text handler can return extracted text | php | marker path | string result | implemented | 2026-07-16 |
| CHAIN-INTERCEPT-UNSUPPORTED | a handler can report the unsupported contract | php | marker `_unsupported` path | ['status'=>'unsupported'] | implemented | 2026-07-16 |
| CHAIN-INTERCEPT-FAILED | a handler can report the failed contract | php | marker `_failed` path | ['status'=>'failed', ...] | implemented | 2026-07-16 |
| CHAIN-DECLINE | a non-marker path is declined | php | real-shaped path | null | implemented | 2026-07-16 |
| CHAIN-LIVE-SAFETY | a live marker-guarded fixture does NOT hijack a real blob's extraction | php | real text blob | EXTRACTED via Plain_Text_Extractor, not app_filter | implemented | 2026-07-16 |
| SEARCH-REINDEX-CLI | rsx:search:reindex selector validation + --status counts table | cli | various flags | exactly-one-selector enforced; status table renders | deferred (covered indirectly by SEARCH-REINDEX-FAILED; dedicated cli test candidate) | 2026-07-16 |
| SEARCH-KICK-ON-CREATE | find_or_create dispatches Document_Render_Service::render_pending when enabled | php | new blob | a pending _tasks row for Document_Render_Service | deferred (dispatch spawns a detached worker; assert on the queued row without spawning - candidate) | 2026-07-16 |

## Spreadsheet_Text_Extraction_Test (php, transactions off) - the words of a workbook, and only the words

A workbook is indexed for its LABELS - sheet names, headers, row captions, notes. Its numeric
content is what a full-text index serves worst: thousands of figures nobody searches by value,
diluting the terms that do matter (owner ruling 2026-09-09). This extractor also replaces
LibreOffice for these mimes, which is what lets a spreadsheet skip the PDF rendition without
costing the index.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| sheettext-01 | labels and sheet names are indexed | a two-sheet workbook | headers, row captions and BOTH sheet names present | implemented |
| sheettext-02 | a value carrying letters survives | `12 boxes` | kept - the numeric filter is conservative on purpose | implemented |
| sheettext-03 | numbers, currency and dates are excluded | plain numbers, decimals, `$2,221.75`, `2026-01-15`, `15/02/2026` | none present. A FORMATTED date or currency cell is numeric underneath and excluded by construction; these are the typed-as-text spellings that need their own handling | implemented |
