# Polymorphic Type References - Test Catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| POLY-MAP-DUAL-ALIAS | `register_morph_map()` registers each type ref under BOTH the class name and the integer id (int and numeric-string lookups both resolve) | php | `Relation::getMorphedModel()` for all three spellings | the same FQCN | implemented | 2026-08-09 |
| POLY-MAP-ORDER | `getMorphClass()` answers with the CLASS-NAME alias, not the integer - the invariant that keeps writes castable | php | `(new Site_Model)->getMorphClass()` | `'Site_Model'` | implemented | 2026-08-09 |
| POLY-MORPHTO-READ | `morphTo()` resolves a raw BIGINT discriminator to the right model | php | notification with `subject_type` type-ref id | `Site_Model` instance | implemented | 2026-08-09 |
| POLY-MORPHTO-RELATION | The relation object queries the target table (`->subject()->first()`) | php | persisted pair | `Site_Model` instance | implemented | 2026-08-09 |
| POLY-MORPHTO-CACHE | A second property read returns the cached relation, not a second query | php | two `->subject` reads | identical instance | implemented | 2026-08-09 |
| POLY-MORPHTO-NULL | An unset pair resolves to null | php | both columns null | null | implemented | 2026-08-09 |
| POLY-MORPHTO-DANGLING | A dangling id resolves to null rather than erroring | php | valid type, missing row | null | implemented | 2026-08-09 |
| POLY-ASSOCIATE | `associate()` persists the type-ref INTEGER (class-name alias -> cast) and reads back | php | `subject()->associate($site)` | integer in db, model on read | implemented | 2026-08-09 |
| POLY-DISSOCIATE | `dissociate()` clears both columns | php | `subject()->dissociate()` | both null | implemented | 2026-08-09 |
| POLY-MORPHMANY-BIND | `morphMany` binds the type-ref integer, not the class-name string (an unconverted string is coerced to 0 by MySQL and matches nothing) | php | relation bindings + rows | integer bound, child found | implemented | 2026-08-09 |
| POLY-MORPHONE | `morphOne` resolves over a type-ref column | php | persisted child | child instance | implemented | 2026-08-09 |
| POLY-MORPHMANY-SAVE | `morphMany()->save()` writes the full pair | php | `->save($child)` | integer type + parent id | implemented | 2026-08-09 |
| POLY-WHEREMORPHEDTO | `whereMorphedTo()` matches type-ref rows | php | query | row present | implemented | 2026-08-09 |
| POLY-WHEREHASMORPH | `whereHasMorph()` matches type-ref rows (qualified column path) | php | query | row present | implemented | 2026-08-09 |
| POLY-BUILDER-QUALIFIED | A TABLE-QUALIFIED type-ref column in `where()` is converted (the spelling Eloquent's own relations emit) | php | `where('portal_notifications.subject_type', 'Site_Model')` | row present | implemented | 2026-08-09 |
| POLY-BUILDER-WHERE-CONVERTS | `where('subject_type', 'Site_Model')` converts the class name to the stored id | php | query | row present | implemented | 2026-08-09 |
| POLY-WHERE-BARE | A bare type-ref column converts and the integer id (not the class name) is bound | php | `where('subject_type', 'Site_Model')` | id bound, row matched | implemented | 2026-08-18 |
| POLY-WHERE-SELF-QUALIFIED | A SELF-table-qualified column converts, and the emitted clause stays table-qualified (the lookup uses the bare name only) | php | `where('portal_notifications.subject_type', 'Site_Model')` | id bound, `` `portal_notifications`.`subject_type` `` in SQL | implemented | 2026-08-18 |
| POLY-WHERE-FOREIGN-QUALIFIER | A qualifier naming ANOTHER table is deliberately left unconverted (that column belongs to a joined table's model) | php | `where('activities.subject_type', 'Site_Model')` | class-name string still bound | implemented | 2026-08-18 |
| POLY-WHERE-ORWHERE-SHORT | `orWhere($column, $value)` converts - the delegation to where() passes a fixed arity, so the short form is converted before it is lost | php | `orWhere('subject_type','Site_Model')` bare + qualified | integer bound | implemented | 2026-08-18 |
| POLY-WHERE-NOT-SHORT | `whereNot`/`orWhereNot` short forms convert (same arity loss as orWhere) | php | `whereNot('subject_type','Site_Model')` | integer bound | implemented | 2026-08-18 |
| POLY-WHEREIN-MIXED | `whereIn` converts class names in the array and leaves integers/nulls alone | php | `['Site_Model', 999999, null]` | id + 999999 + null bound | implemented | 2026-08-18 |
| POLY-WHERE-PASSTHROUGH | Integer and null values are never touched by the conversion | php | integer value, `whereNull` | rows matched unchanged | implemented | 2026-08-18 |
| POLY-WHERE-UNRESOLVABLE-THROWS | An unresolvable class-name-shaped value on a declared type-ref column THROWS naming model, column and value - never binds the string (silent-zero defense) | php | bare + qualified + inside a whereIn array | RuntimeException naming all three | implemented | 2026-08-18 |
| POLY-JOINMORPH | `joinMorph()`/`leftJoinMorph()` produce the converted join predicate | php | builder query | matching rows | deferred (no framework caller yet) | 2026-08-09 |
| POLY-RETIRED-MORPHTO | `morphTo()` over a type ref whose class no longer exists throws naming the id, the class and "no longer exists" (was: `Class name must be a valid object or a string`) | php | notification row holding a retired id | RuntimeException naming id + class | implemented | 2026-08-18 |
| POLY-RETIRED-CAST | The cast read (`$model->subject_type`) throws instead of returning a phantom class name | php | same row | RuntimeException naming the class | implemented | 2026-08-18 |
| POLY-RETIRED-WRITE-REFUSED | `class_to_id()` refuses a retired class on the CACHE-HIT path, so no new references can accrue | php | `class_to_id('Retired_Poly_Test_Model')` | RuntimeException | implemented | 2026-08-18 |
| POLY-RETIRED-WILDCARD | `whereHasMorph($rel, '*')` over a column holding a retired id produces the same NAMED error rather than a Laravel fatal | php | wildcard morph query | RuntimeException naming the class | implemented | 2026-08-18 |
| POLY-RETIRED-INERT | A retired type ref that NOTHING references leaves boot and every unaffected morph working, and its id is registered (poison alias), never silently absent | php | retired ref + unrelated morph | morphs resolve; alias present | implemented | 2026-08-18 |
| POLY-RETIRED-FIND-ID | `find_id_by_class_name()` answers for a RETIRED class (the cleanup-migration seam) and null for an unknown one | php | retired + unknown class name | id, null | implemented | 2026-08-18 |
| POLY-RETIRED-SILENT-LOG | A retired type ref produces NO log line - the registry does not log at all (the boot warning that surfaced in every rsx:debug render is gone) | php | retired ref + Type_Ref_Registry source | no `Log::` reference | implemented | 2026-09-01 |
| POLY-RETIRED-NO-HEALTH-ROW | rsx:health declares no type-ref check at all, so a retired row can never produce a health row | php | `Health_Check_Runner::discover()` | no type-ref label, no TypeRefs fqcn | implemented | 2026-09-01 |
| POLY-RETIRED-MESSAGE | The one retired-type-ref message points at rsx:type_refs:orphans and names no prune command | php | `Retired_Type_Ref::message()` | names the report, not a prune | implemented | 2026-09-01 |
| POLY-ORPHAN-SCAN | The report counts a vanished-class id AND a dangling (unregistered) id, and ignores a resolvable one | php | 3 planted rows on portal_notifications.subject_type | count 2; class name / null labels | implemented | 2026-09-01 |
| POLY-ORPHAN-SELECT | The printed SELECT is exact and, when run, returns exactly the offending rows | php | vanished + dangling ids | literal text + 2 rows | implemented | 2026-09-01 |
| POLY-ORPHAN-COMMAND | `rsx:type_refs:orphans` prints table.column, the row count and the pasteable SELECT with the class name as a trailing comment | php | one planted orphan | all three present | implemented | 2026-09-01 |
| POLY-ORPHAN-JSON | `--json` emits `{table, column, count, type_ids, select}` | php | one planted orphan | parsed payload matches | implemented | 2026-09-01 |
| POLY-ORPHAN-CLEAN | A database with no orphans reports `[OK] No polymorphic rows point at a vanished model.` and exits 0 | php | nothing planted | clean message, exit 0 | implemented | 2026-09-01 |
| POLY-ORPHAN-EXIT-ZERO | The report exits 0 even with orphans - it is a report, not a gate | php | one planted orphan | exit 0 | implemented | 2026-09-01 |

Lint coverage for POLY-01 (the manifest-fatal string-morph rule) lives in the
`code_quality` concern: `Morph_String_Pattern_Rule_Test`.
