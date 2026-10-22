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
| `Manifest_Model_Registry_Test::test_building_the_registry_issues_no_statement_at_all` | php | implemented | One model-module pass issues ZERO statements of any kind - the registry is code facts, so a build needs no database |
| `Manifest_Model_Registry_Test::test_a_pass_reproduces_the_registry_the_index_carries` | php | implemented | The registry is derived whole from the class map: a pass over a corrupted section reproduces exactly what the index carries |
| `Manifest_Model_Registry_Test::test_a_row_is_the_class_its_file_and_its_declared_table` | php | implemented | Every row is exactly `fqcn, file, table, class` with `table` equal to the model's own declaration, and the index records no applied-migrations fingerprint |
| `Manifest_Model_Registry_Test::test_a_model_whose_table_does_not_exist_is_registered` | php | implemented | A fixture model whose table nothing creates is in the registry, in `models_by_table` and answers `is_php_model_class()` |
| `Manifest_Build_No_Database_Test::test_a_manifest_build_completes_with_no_reachable_database` | php | implemented | `rsx:manifest:build` in a child pointed at a database host nothing answers on (scratch roots) exits 0, builds, and reports no connection error |
| `Manifest_Build_No_Database_Test::test_the_same_host_still_fails_a_process_that_is_not_a_build` | php | implemented | The control: a non-build command against the same host fails at boot with the connection error |
| `Manifest_Build_No_Database_Test::test_a_deferred_morph_map_is_registered_before_the_first_statement` | php | implemented | `Type_Ref_Registry::register_morph_map_on_first_query()` registers nothing by itself, and the map is in place - both aliases - once the process issues its first statement |
| `Manifest_Build_No_Database_Test::test_a_cold_build_of_the_whole_tree_issues_no_statement` | php | implemented | A cold development build of the whole tree, in a child against scratch roots, issues ZERO database statements while building, and the model stubs it generates carry no column lengths |
| `Manifest_State_Key_Test::test_the_state_key_is_the_code_key_combined_with_the_applied_migrations` | php | implemented | `get_state_key()` equals `md5(get_build_key() . Rsx_Fingerprint::applied_migrations())`, 32 hex, distinct from the code key |
| `Manifest_State_Key_Test::test_a_migration_moves_the_state_key_and_leaves_the_code_key` | php | implemented | A real row in the migrations table (removed afterwards) changes the state key and not the code key |
| `Manifest_State_Key_Test::test_the_code_key_never_touches_the_database` | php | implemented | `get_build_key()` issues no statement |
| `Manifest_State_Key_Test::test_the_state_key_is_one_query_then_a_memo` | php | implemented | The first ask is exactly one statement (no table-existence probe); later asks issue none |
| `Manifest_State_Key_Test::test_the_memo_holds_until_it_is_reset` | php | implemented | A process keeps the key it computed across a migrations row until `reset_state_key()` |
| `Manifest_State_Key_Test::test_a_missing_migrations_table_is_the_no_migrations_fingerprint` | php | implemented | With the migrations table name pointed at a table that does not exist, the fingerprint is `md5('')` from at most one statement |
| `Manifest_State_Key_Test::test_any_other_query_failure_propagates` | php | implemented | A failure that is not "no such table" (a syntax error) throws from the fingerprint and from `get_state_key()` |
| `Manifest_State_Key_Test::test_an_unreachable_database_answers_the_code_key_and_logs_it` | php | implemented | THE DEGRADED PATH. With the default connection pointed at a port nothing listens on, the state key is the code key alone and one log line says so |
| `Manifest_State_Key_Test::test_booting_computes_no_state_key` | php | implemented | A child `rsx:manifest:get_build_key` reports `MANIFEST_STATE_KEY_COMPUTATIONS=0` |
| `Manifest_State_Key_Test::test_a_process_that_asks_computes_it_once` | php | implemented | A child `rsx:manifest:get_state_key` reports exactly one computation |
| `Manifest_State_Key_Test::test_the_asset_channel_computes_no_state_key` | php | implemented | `AssetHandler::serve_build_artifact()` for a `/_compiled/` path computes no state key and issues no statement |
| `Manifest_Parameter_Default_Test::test_a_constant_default_naming_a_missing_class_is_not_evaluated` | php | implemented | The scanner records a parameter as optional and never evaluates its default, so a constant default naming a class the build cannot load yet (an overridden core model) cannot abort the build |
| `Manifest_Stub_Rewrite_Test::test_a_no_change_rebuild_rewrites_no_stub` | php | implemented | A no-change rebuild rewrites no generated JS stub - no mtime moves, so no bundle recompiles |
| `Unindexed_Framework_Walk_Test::test_the_walk_prunes_every_excluded_directory_at_any_depth` | php | implemented | `Manifest_Scanner::_scan_directory_for_classes()` over a scratch tree finds only the classes outside every `resource/`, `public/`, hidden, `vendor/` and `node_modules/` directory, at any depth |
| `Unindexed_Framework_Walk_Test::test_the_unindexed_class_map_never_reads_the_framework_resource_tree` | php | implemented | A REAL tree inside `app/RSpade/resource/` (the shape of the vendored reference app) contributes no class to `Manifest_Indexer::_unindexed_framework_classes()`, while the unscanned Commands subtree still does |
| restart safety: a bounded restart loop throws naming the last reason | php | not implemented | Needs a build whose passes genuinely fight; W4/W5 own the restart rework's own coverage |
| `_set_manifest_is_bad()` writes the flag and never a partial index | php | not implemented | Phase 4 (`tests/manifest/` build-out) |
| `Manifest_Fixture_Build_Test::test_two_builds_of_an_unchanged_tree_are_byte_identical` | php | implemented | No timestamp in the index body and a ksorted top-level section map, in any mode: a cold build and the incremental build after it write identical bytes and the same build key |
| `Manifest_Fixture_Build_Test::test_the_build_writes_both_halves_of_the_index` | php | implemented | The build writes `manifest_index.php` and `manifest_files.php`; a class's method map is in the COLD half, and `file_index` still names it |
| `Manifest_Fixture_Build_Test::test_a_fixed_file_is_indexed_as_fixed` | php | implemented | A file Php_Fixer rewrote mid-build (a `\Rsx\` FQCN) is indexed as it now is: no pre-fix `rsx_fqcn_violations`, the post-fix hash, and the next build stays clean |
| `Manifest_Fixture_Build_Test::test_the_bad_flag_discards_every_file_record` | php | implemented | With `manifest_is_bad` raised, a stale finding planted in a fingerprint-matching record (either half of the index) is re-read, not carried forward, and the completed build clears the flag |
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

## Manifest_Derived_Section_Recovery_Test (php, transactions off) - a derived section repairs itself

THE INCIDENT this pins: the standard route table was found EMPTY against a fully populated
file index. Every page answered 404 and all eight bundles failed to compile, and no ordinary
rebuild fixed it - the section was carried forward and only rows belonging to CHANGED files
were re-derived, so an unchanged tree re-derived nothing. Touching one controller restored
exactly that controller's routes. `rsx:manifest:build --force` was the only cure, and it
works solely by making every file dirty at once.

The rule (owner ruling 2026-09-09): the changed-set contract is for EXPENSIVE work. A module
that only regroups data already in the manifest derives its section in full, every build.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| mds-01 | an emptied route table is rebuilt with nothing dirty | live manifest, `routes` set to `[]`, `Route_ManifestSupport::rebuild()` | the standard row count returns to what it was | implemented |
| mds-02 | the other request-serving sections rebuild too | `portal_routes` and `auth` emptied, both portal modules plus Auth rebuilt | both counts return - an empty surfaces map is what turned the outage into a 500 once routes were back | implemented |
| mds-03 | the request-serving modules derive in FULL | Route, Portal_Route, Spa, Auth | each is a `Full_ManifestSupport_Abstract` - a named list, so moving one back onto the changed set has to delete this line | implemented |
| mds-04 | every registered module declares a kind | `config('rsx.manifest_support')` | each extends a support base | implemented |
| `Manifest_Boot_Is_Idle_Test::test_the_index_records_generated_entries_the_owner_recognises` | php | implemented | The index records generated (stub) entries and the path owner classifies every one of them as generated, so the exemption below is exercised on this tree |
| `Manifest_Boot_Is_Idle_Test::test_a_child_boot_does_not_rewrite_the_index` | php | implemented | SETTLE-THEN-ASSERT: a first child boot may rebuild (absorbing whatever earlier classes changed in the scanned trees); a second child boot on the tree unchanged since then leaves the index file's inode and mtime untouched - a generated entry absent from the source scan is not a deleted file |
| `Manifest_Freshness_Decision_Test::test_an_index_with_generated_entries_matching_the_scan_is_fresh` | php | implemented | THE DECISION, pure: `Manifest_Store::stale_reason()` over a synthetic index carrying a generated stub entry and a scan that matches it answers fresh (null) |
| `Manifest_Freshness_Decision_Test::test_a_changed_source_file_is_stale` | php | implemented | A scanned source file whose size, or mtime, differs from its recorded entry is stale |
| `Manifest_Freshness_Decision_Test::test_a_removed_source_file_is_stale` | php | implemented | An indexed source file the scan no longer returns is stale ("Deleted file ...") |
| `Manifest_Freshness_Decision_Test::test_a_new_or_vanished_scanned_file_is_stale` | php | implemented | A scanned file recorded nowhere is new; a scanned file gone from disk is deleted - both stale |
| `Manifest_Freshness_Decision_Test::test_the_live_entry_wins_over_the_index_entry` | php | implemented | The live `files` entry takes precedence over the `file_index` entry for the same path |
| `Manifest_Build_Key_Mode_Test::test_the_same_tree_keys_differently_in_each_mode` | php | implemented | THE KEY IDENTIFIES THE BUILD: `Manifest_Store::_compute_hash()` over one synthetic body answers three different keys for development, debug and production - a development build after a production seal cannot reuse the seal's key (a downstream field report, 2026-10-05) |
| `Manifest_Build_Key_Mode_Test::test_the_same_tree_in_the_same_mode_is_the_same_key` | php | implemented | The cluster contract holds within a mode: an mtime-only difference keys identically in every mode, a content change still moves the key |
| `Manifest_Build_Key_Mode_Test::test_an_index_from_another_mode_is_stale` | php | implemented | THE DECISION, pure: `Manifest_Store::index_mode_stale_reason()` accepts an index recorded in the running mode and refuses one recorded in another mode or in none |
| `Manifest_Build_Key_Mode_Test::test_the_live_index_key_is_computed_for_its_mode` | php | implemented | The loaded index carries the running mode, and its key is exactly `_compute_hash()` of its body (both halves) in that mode |
| `Manifest_Build_Key_Mode_Test::test_a_development_boot_refuses_a_production_index` | php | implemented | THE TRANSITION: with the live index's mode stamp swapped to production in memory, `_validate_cached_data()` refuses it and empties the data, so `init()` rebuilds in full rather than incrementally finding nothing changed |
| `Manifest_Build_Key_Mode_Test::test_the_portal_settings_stamp_moves_the_key` | php | implemented | The client portal settings fingerprint is a `_compute_hash()` input: two stamps over one body answer two keys |
| `Manifest_Build_Key_Mode_Test::test_an_index_from_other_portal_settings_is_stale` | php | implemented | THE DECISION, pure: `index_portal_env_stale_reason()` accepts a matching stamp and refuses another or none |
| `Manifest_Build_Key_Mode_Test::test_the_fingerprint_follows_each_portal_setting` | php | implemented | `Rsx_Portal_Url::fingerprint()` moves with each of rsx.tenancy, PORTAL_ENABLED, PORTAL_URL, PORTAL_CUSTOM_DOMAINS and is a function of them |
| `Manifest_Build_Key_Mode_Test::test_a_development_boot_refuses_an_index_from_other_portal_settings` | php | implemented | With the live index's portal stamp swapped in memory, `_validate_cached_data()` refuses it and empties the data (a full rebuild re-runs Portal_Url_ManifestSupport) |
| sealed boot refuses changed portal settings | cli | deferred (needs a sealed box and an .env edit) | `Manifest::init()` production branch throws PORTAL_ENV_CHANGED_MESSAGE naming `rsx:build --force` when the sealed index's `portal_env` differs from the live fingerprint |
| `Autoloader_Include_Guard_Test::test_a_declared_class_is_never_included_again` | php | implemented | A class loaded first under another spelling of its path is not required again by the seam - no redeclare |
| `Autoloader_Include_Guard_Test::test_a_helper_file_is_matched_without_case` | php | implemented | A classless file is matched against the include table without case |
| `Autoloader_Include_Guard_Test::test_the_include_table_match_ignores_case_only` | php | implemented | The pure matcher: case-insensitive on the path, exact otherwise |
| `Autoloader_Include_Guard_Test::test_an_unloaded_file_is_included` | php | implemented | A file nobody loaded is loaded, as before |
| `Scss_Wrapper_Class_Test::test_a_stylesheet_of_several_top_level_rules_has_no_wrapper` | php | implemented | `.A { } .B { }`, `.A { } html.x .A { }`, a comment between two rules, an at-rule after the first rule, a stray or missing close: no `scss_wrapper_class` - the close of the first rule is found by depth, never by counting braces |
| `Scss_Wrapper_Class_Test::test_a_stylesheet_held_in_one_rule_is_wrapped` | php | implemented | Nested rules, a brace in a comment or a string, interpolation, `url()`, comments before or after and variable declarations around the one rule: wrapped |
| `Scss_Wrapper_Class_Test::test_only_a_component_shaped_class_is_a_wrapper` | php | implemented | The accepted selector shape: `.` + optional `_` + a capital; a lowercase class, an id, an element, a compound or a list is no wrapper |
| `Repeated_Attribute_Test::test_an_identical_repeat_on_a_method_is_refused` | php | implemented | `#[Exclusive('g')]` twice (and a bare `#[Exclusive]` twice) on one method is a FATAL naming the attribute and the method (`Manifest_Scanner::_refuse_repeated_attributes()`). |
| `Repeated_Attribute_Test::test_the_same_attribute_with_different_arguments_is_allowed` | php | implemented | Two groups, bare beside a group, two routes, and a positional beside a named spelling all pass. |
| `Repeated_Attribute_Test::test_every_kind_of_declaration_is_checked` | php | implemented | A repeat on a property, a constant and a parameter is refused, each named. |
| `Repeated_Attribute_Test::test_the_class_itself_is_checked` | php | implemented | A repeat on the class declaration is refused. |
