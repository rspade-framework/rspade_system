# revisions

Revision history: the per-model recording of every record write as a
`{field: [before, after]}` document, grouped under one transaction per dispatch run.

This concern covers the whole subsystem: the storage engine (the compression format and
the dictionary it is written against, plus the two `migrate` seams that maintain them),
the recording path in the model layer, the transaction facade, the two models, the
retention task, and the REVISION-01 declaration rule.

## Source under test

- `app/RSpade/Core/Revisions/Revision_Codec.php` - `encode()` / `decode()`. The two-byte
  self-describing prefix (codec byte, dictionary-id byte), the size rule that picks a
  codec, the raw escape, and the two refusals on read.
- `app/RSpade/Core/Revisions/Revision_Dictionary.php` - `build_tokens()`,
  `build_bytes()`, `regenerate_if_stale()`, `current()`, `bytes_for()`, `_reset_cache()`.
- `app/RSpade/Commands/Migrate/Maint_Migrate.php` - the post-migrate dictionary step in
  `execute_migrations()` and the cache clear on both success tails.
- `app/RSpade/Commands/Migrate/Migrate_Restore_Command.php` - the cache clear after a
  restore.
- `system/database/migrations/2026_08_28_122844_create_revisions_tables.php` -
  `_transactions`, `_revisions`, `_revision_dictionaries`.
- `app/RSpade/Core/Revisions/Revision.php` - the transaction facade: `transaction_id()`
  (the writer that mints), `current_transaction()`, `current_revisions()`, `describe()`,
  `without()`, `for_transaction()`, `transactions_for()`, `_record()`, and the
  `_reset_request_state()` / `_snapshot_request_state()` unit boundaries.
- `app/RSpade/Core/Revisions/Transaction_Model.php`, `Revision_Model.php` - the two models,
  their type-ref pairs, their enums, and `Revision_Model::diff()`.
- `app/RSpade/Core/Revisions/Revision_Parent_Registry.php` - `#[Revision_Parent]` discovery.
- `app/RSpade/Core/Revisions/Revision_Cleanup_Service.php` - the retention task.
- `app/RSpade/Core/Database/Models/Rsx_Model_Abstract.php` - `$revisions`,
  `$revision_exclude`, `revisions()`, `revisions_including_children()`, the differ and the
  two write-effect hooks, `_resolve_context_actor()`, and the `toArray()` system-column strip.
- `app/RSpade/CodeQuality/Rules/Manifest/RevisionParent_CodeQualityRule.php` - REVISION-01.
- `config('rsx.revisions')` - `retention_days`, `dictionary_max_age_days`.

## Behavior of record

- **Every stored payload is self-describing.** Byte 0 is the codec, byte 1 is the
  `_revision_dictionaries.id` it was compressed against (0 for none). A row written by an
  older build decodes from its own prefix and nothing else.
- **The codec is chosen by size, from measurements** (`docs.dev/revisions/`): under 1 KB
  deflate only (at 138 bytes deflate+dict produced 45 B against zstd+dict's 60 B); 1-3 KB
  compress with both and keep the smaller; above 3 KB zstd (at 35 KB zstd produced 884 B
  against deflate's 1177 B). Write-path levels are deflate 6 and zstd 3 - zstd level 19
  measured 10.8 ms per document and is never used on a write.
- **RAW is an escape, not a mode.** If nothing shrinks the input it is stored verbatim.
  A compressor is never allowed to commit a payload larger than its input.
- **Unknown codec byte or unknown dictionary id THROWS.** There is nothing to degrade to:
  the revision genuinely cannot be read.
- **The dictionary is derived, never authored** - JSON structural tokens, every column
  name in `information_schema`, every `$enums` label - and ordered COLDEST FIRST,
  HOTTEST LAST, because zlib prices a match by its distance and the dictionary is loaded
  nearest-last.
- **Dictionary rows are append-only.** A regeneration never invalidates an old revision,
  because the old revision names the old row. Ids are capped at 255 by the one-byte
  prefix field, and minting past it is refused.
- **A revision is written on the SAME connection, immediately.** No `afterCommit`. A
  database transaction that rolls back takes its revisions with it, because they describe
  writes that also rolled back.
- **The transaction is minted LAZILY**, on the first revisioned write of a unit of work, so
  a request that changed nothing leaves nothing behind. A unit of work is one web request,
  one API request, one batched or nested Ajax call, one task, one test - each of those
  resets the facade.
- **A create records every non-null column as `[null, value]`**; an update records only what
  changed; a delete (soft or hard) records an EMPTY document, because a delete does not
  change fields. A restore is recorded as its own operation.
- **Excluded always**: `updated_at` and the `updated_by` pair (they move on every write) and
  every `_`-prefixed system column. Excluded by declaration: `$revision_exclude`.
- **Bulk**: `where()->update()` records one revision per affected record (it runs `save()`);
  `raw_bulk()`, `DB::table()` and raw SQL record nothing - the same rule realtime follows.
- **REVISION-01 is a manifest-build FATAL** on an incoherent `#[Revision_Parent]`: a child
  that records nothing, an attribute that is not on a belongsTo, or a parent that records
  nothing. Every failure mode is silent at runtime, which is why it is fatal.
- **`retention_days` 0 means KEEP FOREVER** and is the default; the FK cascade means only
  `_transactions` is ever deleted.
- **`migrate` maintains both.** The post-migrate step (after the initial user, skipped
  under `--framework-only`) builds a dictionary when the current one is missing or past
  `rsx.revisions.dictionary_max_age_days`; both success tails and `migrate:restore` clear
  the build-scoped cache.

## Testable surface

| Area | Type | Covered |
|------|------|---------|
| Prefix format, per-codec round trips, raw escape, binary fidelity | php | yes |
| Size rule (small / both / large bands), zstd-absent branch | php | yes |
| Decode refusals (codec byte, dictionary id, truncated payload) | php | yes |
| Dictionary vocabulary (columns, enum labels) and token order | php | yes |
| `regenerate_if_stale()` decisions, the 255 ceiling, cache reset | php | yes |
| `migrate` / `migrate:restore` seams | php (source structure) | yes |
| The four operations (create / update / delete / undelete) and their diffs | php | yes |
| Exclusions: `$revision_exclude`, `_`-prefixed, `updated_*` | php | yes |
| Transaction grouping, sequences, `revision_count`, source, actor, `describe()` | php | yes |
| Unit boundaries (`_reset_request_state`) | php | yes |
| The root pair and `#[Revision_Parent]` | php | yes |
| `Revision::without()` and the `$revisions = false` fast path | php | yes |
| Bulk semantics (`update()` per row, `raw_bulk()` none) and DB rollback | php | yes |
| `toArray()` system-column strip and `$neverExport` on the blob | php | yes |
| Retention task (0 = forever, N, cascade, chunked drain) | php | yes |
| REVISION-01 (all three checks + the exception marker) | php | yes |
| Reset seams in the live dispatchers (web / API / Ajax / task) | - | covered by the facade tests; the call sites themselves are single lines |
