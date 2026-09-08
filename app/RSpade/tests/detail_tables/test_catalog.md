# detail_tables - test catalog

One row per test (implemented or not). Type: php/cli/asset/http/playwright.
Framework mechanics are covered by the `detail_tables` PHP suite (runtime fixtures);
codegen-spanning + JS runtime are proven against the Party demo (real tables at
manifest-build time): app suite `Party_Test`, plus `rsx:debug` eval / stub inspection.

| ID | Purpose (what it proves) | Type | Status | Where |
|----|--------------------------|------|--------|-------|
| DT-01 | Helper emits surrogate id + UNIQUE FK + CASCADE | php | implemented | Detail_Table_Helper_Test |
| DT-02 | Detail columns nullable by default; explicit NOT NULL honored | php | implemented | Detail_Table_Helper_Test |
| DT-03 | Audit columns + optional soft-delete columns present | php | implemented | Detail_Table_Helper_Test |
| DT-04 | FK column derivation + explicit override | php | implemented | Detail_Table_Helper_Test |
| DT-05 | Emitted DDL composes with SqlQueryTransformer | php | implemented | Detail_Table_Helper_Test |
| DT-06 | Detail model parent_key() derivation + for_parent() | php | implemented | Detail_Read/Write_Test (fixtures) |
| DT-10 | Resolver: value->class, accessor name, absent type, value->accessor | php | implemented | Detail_Resolver_Test (a synthetic map in names belonging to nothing - the resolver reads a map, never a class) |
| DT-11 | DETAIL-01 validates the map (parent_model points back, one discriminator) | cli | implemented | rsx:check (fixtures + Party) |
| DT-20 | toArray() embeds active detail under __details; absent type embeds nothing | php | implemented | Detail_Read_Test |
| DT-21 | Wrong-type accessor throws (PHP) | php | implemented | Detail_Read/Write_Test |
| DT-22 | preload_details() avoids N+1 across a set | php | implemented | Detail_Read_Test |
| DT-30 | Accessor vivifies a new detail with the parent FK pre-set; save() does not auto-create | php | implemented | Detail_Write_Test |
| DT-31 | Required detail fields enforced at the endpoint/validation layer (not the ORM) | php | implemented | Party_Test (app) |
| DT-32 | Discriminator immutable; delete base cascades detail | php | implemented | Detail_Write_Test |
| DT-40 | JS stub has embedded-resolving accessor + map; resolves from the embed | playwright | implemented | rsx:debug eval (Party) |
| DT-41 | JS wrong-type accessor throws locally | playwright | implemented | rsx:debug eval (Party) |
| DT-50 | field_length() spans detail columns | cli | implemented | Party stub (base-party-model.js) |
| DT-51 | Manifest base columns merged with detail (source_table tagged) | php | implemented | Party stub field_length / docblock |
| DT-52 | rsx:constants:regenerate docblock spans both tables with (detail: <table>) | cli | implemented | party_model.php docblock |
| DT-53 | Same-name base/detail column emits a build warning | cli | planned | code in Model_ManifestSupport; no negative fixture yet |
| DT-60 | End-to-end: controller writes base+detail atomically; name composed; edit-in-place | php | implemented | Party_Test (app) |
