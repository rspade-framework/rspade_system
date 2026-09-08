# manifest - test catalog

| Test | Kind | Status | What it proves |
|---|---|---|---|
| `Manifest_Fixture_Build_Test::test_fixture_tree_is_indexed` | php | implemented | A tree of one PHP class, one JS class, one jqhtml component, one blade view and one stylesheet appears in the saved index, with the class map, the JS class map and the jqhtml component registry all naming it |
| `Manifest_Fixture_Build_Test::test_editing_one_file_reparses_only_that_file` | php | implemented | After an edit, exactly one indexed file's hash moved |
| `Manifest_Fixture_Build_Test::test_adding_a_file_indexes_it` | php | implemented | A file added between builds enters the index |
| `Manifest_Fixture_Build_Test::test_removing_a_file_drops_it` | php | implemented | A file removed between builds leaves the index |
| `Manifest_Memory_Gate_Test::test_cold_build_of_the_reference_tree_is_under_128mb` | php | implemented | THE BUDGET. A cold build of the production scan list peaks under 128 MB |
| `Manifest_Memory_Gate_Test::test_cold_build_of_a_five_times_tree_is_under_256mb` | php | implemented | THE BUDGET, at scale. A synthetic tree of five times the reference file count peaks under 256 MB - peak tracks the INDEX, not the files parsed |
| `Manifest_Incremental_Modules_Test::test_an_incremental_js_edit_matches_a_cold_build` | php | implemented | THE INCREMENTAL CONTRACT. Every derived section and every file record after a one-file JS edit is byte-identical to a cold build of the same tree |
| `Manifest_Incremental_Modules_Test::test_an_incremental_remove_matches_a_cold_build` | php | implemented | The removal half: a file that left the tree leaves no row behind in any module's section |
| `Manifest_Model_Introspection_Test::test_consecutive_passes_issue_no_schema_queries` | php | implemented | Two consecutive model-module passes over an unchanged tree issue ZERO `SHOW COLUMNS` |
| `Manifest_Model_Introspection_Test::test_the_registry_survives_a_pass_unchanged` | php | implemented | A pass over an unchanged tree leaves the model registry exactly as it found it |
| `Manifest_Model_Introspection_Test::test_every_model_row_carries_its_fingerprint` | php | implemented | Every model row records the model-file + migration fingerprint its columns were introspected for |
| `Manifest_Stub_Rewrite_Test::test_a_no_change_rebuild_rewrites_no_stub` | php | implemented | A no-change rebuild rewrites no generated JS stub - no mtime moves, so no bundle recompiles |
| restart safety: a bounded restart loop throws naming the last reason | php | not implemented | Needs a build whose passes genuinely fight; W4/W5 own the restart rework's own coverage |
| `_set_manifest_is_bad()` writes the flag and never a partial index | php | not implemented | Phase 4 (`tests/manifest/` build-out) |
| `Manifest_Fixture_Build_Test::test_two_builds_of_an_unchanged_tree_are_byte_identical` | php | implemented | No timestamp in the index body, in any mode: two builds of an unchanged tree write identical bytes and the same build key |
| `Manifest_Fixture_Build_Test::test_the_build_writes_both_halves_of_the_index` | php | implemented | The build writes `manifest_index.php` and `manifest_files.php`; a class's method map is in the COLD half, and `file_index` still names it |
| `Index_Reference_Rule_Test::test_no_top_level_index_duplicates_a_file_record` | php | implemented | THE REFERENCE RULE. No index holds an array that also appears verbatim inside a `files` record |
| `Index_Reference_Rule_Test::test_hot_index_holds_method_maps_only_for_models_tasks_and_stubs` | php | implemented | The hot index carries no method map nothing on the request path reads |
| `Index_Reference_Rule_Test::test_file_records_do_not_repeat_their_path` | php | implemented | The path is the key; a record does not repeat it as a value |
| `Index_Reference_Rule_Test::test_by_target_indexes_are_not_persisted` | php | implemented | `routes_by_target` is derived at load, never written, and IS present in a loaded manifest |
| `Index_Reference_Rule_Test::test_route_rows_reference_their_auth_surface` | php | implemented | A route row names an indexed surface instead of carrying its own gate list |
| `Index_Reference_Rule_Test::test_new_indexes_answer_through_their_accessors` | php | implemented | `blade_views`, `attribute_index`, `models_by_table`, `file_index` exist and their accessors read them |
| `Manifest_Cold_Isolation_Test::test_booting_does_not_load_the_cold_half` | php | implemented | THE SPLIT'S POINT. A full boot merges the cold half zero times |
| `Manifest_Cold_Isolation_Test::test_an_ajax_call_does_not_load_the_cold_half` | php | implemented | An Ajax dispatch is answered entirely by the hot index |
| `Manifest_Cold_Isolation_Test::test_a_model_fetch_does_not_load_the_cold_half` | php | implemented | A model fetch is answered entirely by the hot index - which is why models and their ancestors stay hot |
| `Manifest_Cold_Isolation_Test::test_get_all_loads_the_cold_half_once` | php | implemented | Asking for the whole tree merges it, once, and returns every indexed file |
