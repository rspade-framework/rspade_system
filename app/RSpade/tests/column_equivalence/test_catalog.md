# Test catalog: column_equivalence

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CE-VEC-01 | the vector file is well formed: unique ids, valid descriptors, a test method per group, every kind covered | php | the file | all hold | implemented | 2026-11-17 |
| CE-VEC-02 | every vector in PHP, both argument orders, one method per group (every kind, blank, never-loosened) | php | 161 cases | all match `expect` | implemented | 2026-11-17 |
| CE-VEC-03 | every vector in the browser, both orders, `only: js` included; an undefined side throws | playwright | 161 cases | all match `expect` | implemented | 2026-11-17 |
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
| CE-JS-MODEL | `Model.same_value()` / `column_descriptor()` on a stub read the field-length script; a name that is no exported column throws | playwright | `User_Model` | `id` integer, `"23"` = 23, missing throws | implemented | 2026-11-17 |
