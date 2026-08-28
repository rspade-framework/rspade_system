<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## SYSTEM COLUMNS (THE `_` PREFIX)

**A `_`-prefixed COLUMN is a system column, exactly as a `_`-prefixed TABLE is a system table.** The framework owns it, the application does not read it, and three seams enforce that: `Rsx_Model_Abstract::toArray()` strips it from every payload, the generated JS model stub skips it in `field_length()` (the client can never hold the column, so it can never need its length), and the revision differ excludes it from every diff. **`__` (double) is untouched** — `__MODEL` and `__details` are the framework keys `toArray()` itself adds, and they are the payload's contract with the JS ORM.

**So when a framework feature needs per-record state on an APP table, name it `_foo`** — that one character is the whole declaration, and it costs no config, no exclusion list and no app cooperation. Conversely, never name an app-facing column with a leading underscore: it will silently never reach the browser. Documented downstream in `rsx:man database_schema_architecture` (column conventions) and `rsx:man revisions`.
