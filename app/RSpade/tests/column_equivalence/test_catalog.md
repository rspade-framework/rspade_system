# Test catalog: column_equivalence

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CE-VEC-01 | the vector file is well formed: unique ids, valid descriptors, a test method per group, every kind covered | php | the file | all hold | implemented | 2026-11-17 |
| CE-VEC-02 | every vector in PHP, both argument orders, one method per group (every kind, blank, never-loosened, a number on a DECIMAL text-type column, a DATE with a midnight tail) | php | 181 cases | all match `expect` | implemented | 2026-11-17 |
| CE-VEC-03 | every vector in the browser, both orders, `only: js` included; an undefined side throws | playwright | 181 cases | all match `expect` | implemented | 2026-11-17 |
| CE-DIRTY-DECIMAL | `1`, `1.0`, `'1'` over `'1.00'` on a DECIMAL(8,2) are not dirty and the save is a no-op in every respect | php | assignments | not dirty; row bytes, revisions, realtime, lifecycle unchanged | implemented | 2026-11-17 |
| CE-DIRTY-INSTANT | an instant in ISO, zoned and second-only forms over a DATETIME(3) is not dirty | php | assignments | no-op | implemented | 2026-11-17 |
| CE-DIRTY-JSON | a reordered JSON document, `''` over NULL, money / boolean in other shapes, an empty save | php | assignments | no-op | implemented | 2026-11-17 |
| CE-DIRTY-NEVER-LOOSENED | NULL over a value, 0 over NULL on a DECIMAL, false over NULL on a boolean, `''` over NULL on blank_distinct are dirty | php | assignments | dirty | implemented | 2026-11-17 |
| CE-DIRTY-REAL-CHANGE | a real change writes, stamps, records the storage form (`'1.50'`, `0`) and fires realtime + after_update() | php | `hours = 1.5`, `is_flag = false` | as stated | implemented | 2026-11-17 |
| CE-REV-CREATE-STORAGE-FORM | a create records storage forms and leaves out a value the same as NULL | php | new record | `[null, '2.00']`, `[null, 1]`, no `name` | implemented | 2026-11-17 |
| CE-UNSTAMPED-REVISION-EXCLUDE | a save changing only revision_exclude columns writes the value, leaves updated_at / updated_by, records nothing | php | `last_seen_at` | as stated; mixed with an edit it stamps | implemented | 2026-11-17 |
| CE-SAME-VALUE | `Model::same_value()` per kind, blank_distinct, never_export compared normally, type ref name vs id; unknown column throws | php | values | as stated | implemented | 2026-11-17 |
| CE-DESCRIPTORS | descriptors come from the declaration and the schema (scale, fsp, nullability, blank_distinct) | php | fixture model | as stated | implemented | 2026-11-17 |
| CE-STORAGE-FORM | `storage_form()` per kind; an unreadable value is returned unchanged | php | values | as stated | implemented | 2026-11-17 |
| CE-DESCRIPTOR-REFUSALS | an unknown kind, a text_type with no type, throw | php | descriptors | LogicException | implemented | 2026-11-17 |
| CE-MEANINGFUL-CHANGES | the reader helper drops equal pairs and now-unrecorded columns, keeps `[null, '0.00']` on money, redacted pairs and vanished columns | php | a stored diff | as stated | implemented | 2026-11-17 |
| CE-MEANINGFUL-UNKNOWN-MODEL | a model the manifest does not know (simple name or FQCN) returns its diff as stored, 0 removed | php | a stored diff | unchanged | implemented | 2026-11-17 |
| CE-MEANINGFUL-UNITS | a type-ref pair and a fact group are kept whole when one pair changed, dropped whole when none did; a missing half is not invented | php | stored diffs | as stated | implemented | 2026-11-17 |
| CE-MEANINGFUL-REMOVED | the `$removed` count for a mixed diff, an all-real diff and an empty one | php | stored diffs | 4, 0, 0 | implemented | 2026-11-17 |
| CE-TEXT-TYPE-NUMBER | a text-type DECIMAL(12,4) column's descriptor carries scale 4 (also to the browser); a number side is read at the scale | php | `rate` | `'60.0000'` = 60 = 60.0, `'0.7250'` = 0.725, 61 and NULL/0 differ | implemented | 2026-11-17 |
| CE-DATE-MIDNIGHT | a DATE with a midnight tail is that date (same_value, storage form, a stored pair dropped by meaningful_changes); a non-midnight tail differs | php | `due_on` | as stated | implemented | 2026-11-17 |
| CE-RECORDED-NOTHING | `recorded_dirty()` / `recorded_changes()` are `[]` for updated_at + revision_exclude and for a derived-only `rederive()` | php | `last_seen_at`, `hours_label` | `[]`, no revision | implemented | 2026-11-17 |
| CE-RECORDED-MIXED | a mixed save names the recorded columns only and matches the revision it wrote; a create matches its create revision and never names the derived column | php | name / hours / token / owner_id | as stated | implemented | 2026-11-17 |
| CE-JS-MODEL | `Model.same_value()` / `column_descriptor()` on a stub read the field-length script; a name that is no exported column throws | playwright | `User_Model` | `id` integer, `"23"` = 23, missing throws | implemented | 2026-11-17 |
