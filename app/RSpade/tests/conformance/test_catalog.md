# Test Catalog: conformance

Full catalog of tests worth having for the conformance-audit library. Implemented tests
live in `php/Conformance_Audits_Test.php`; each fixture audit is written to a per-process
temp directory installed through `Conformance_Audits::$_directories_for_tests`. No
database.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CF-01 | The shipped framework library parses (`test_the_shipped_library_parses`) | php | the real `app/RSpade/conformance/` and `rsx/resource/conformance/` | no throw; every audit has a non-empty body | implemented | 2026-10-19 |
| CF-02 | A complete header is read into its fields (`test_a_complete_header_is_read`) | php | header with all six keys, two `when` values, two scope globs | severity, `when`, `scope`, `related` arrays and the body as written | implemented | 2026-10-19 |
| CF-03 | A malformed header throws, naming the defect (`test_a_bad_header_throws`) | php | no header; id unlike file name; severity `urgent`; when `sometimes`; no `scope`; unknown key `owner` | `RuntimeException` with the matching message for each | implemented | 2026-10-19 |
| CF-04 | An id is unique across the framework and application homes (`test_an_id_is_unique_across_both_homes`) | php | the same id in both directories | `RuntimeException` "declared twice" | implemented | 2026-10-19 |
| CF-05 | A directory's `CLAUDE.md` is not read as an audit (`test_a_directory_claude_md_is_not_an_audit`) | php | one framework audit plus a header-less `application/CLAUDE.md` | `all()` answers only the audit | implemented | 2026-10-19 |
| CF-06 | Scope globs match as the man page documents (`test_scope_globs_match_as_documented`) | php | `rsx/**/*.php`, `rsx/*.php`, `rsx/**/*_input.js` against nested, flat and wrong-extension paths | `**` crosses and may match no directory; `*` stays in a segment; extension anchored | implemented | 2026-10-19 |
| CF-07 | The three commands list, filter, show and match (`test_the_commands_list_show_and_match`) | cli | one framework audit (critical, launch, `*.php`) and one application audit (low, change, `*.js`) | list orders framework first; `--severity=high` keeps the critical one; `--when=change` keeps the application one; `show` prints the prompt; `for` on a `.php` path names only the `.php` audit | implemented | 2026-10-19 |
| CF-08 | A non-JSON `rsx:conformance:list` renders the table with an origin column | cli | the CF-07 fixtures, no `--json` | table rows carry `framework` / `application` | planned | 2026-10-19 |
| CF-09 | `rsx:conformance:show` on an unknown id throws; `:for` on a path no audit covers says so | cli | `show no_such_id`; `for docs/readme.txt` | exception naming the id; "No conformance audit covers this file." with exit 0 | planned | 2026-10-19 |
