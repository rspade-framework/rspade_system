<!-- single-source: never duplicate into another fragment. -->

## DEVELOPER WORKFLOW

**Command index** (depth lives in the named skill or man page):

| Command | What it does |
|---|---|
| `rsx:check` | Code-quality pass; run before commits. An app adds its own `APP-` rules as `rsx/` classes extending `CodeQualityRule_Abstract` (`rsx:man coding_standards`). |
| `rsx:debug /path` | Render a route through Playwright with full JS execution. Skill: `rspade:rsx-debug`. |
| `rsx:test` | Run the test suite (class name, `--filter=`, `--group=` narrow it). Skill: `rspade:rsx-testing`. |
| `rsx:man <topic>` | Framework documentation. **With no argument it lists every topic** — never work from a memorized list. `--tag=<tag>` lists the topics on a subject (repeat to narrow), `--tags` the tag vocabulary (`rsx:man man`). |
| `rsx:health [--json]` | Verify dependencies, services, environment. **Exit 1 iff a FAIL; WARN/INFO advisory.** |
| `rsx:heal <target>` · `--list` | Run the remedy a `rsx:health` row names. **Creates only what is definitionally absent**; anything present-but-wrong is REFUSED, never overwritten. |
| `db:query "SQL" [--json] [--table]` | Execute MySQL directly. Prints at most 25 rows (a SELECT with no `LIMIT` gets `LIMIT 25`); page with `LIMIT 25, 25`. |
| `rsx:database_and_storage_reset` | Drop every table and delete every stored file, then migrate to the fresh-install state. Refuses without `--yes` (plus `--force` under a sealed build). **Never run it unasked.** `rsx:man migrations`. |
| `rsx:refactor:rename_php_class` · `:rename_php_class_function` · `:sort_php_class_functions` | The three AST-based editor refactors. |
| Owned elsewhere | `rsx:build` · `rsx:clean` · `migrate` · `rsx:db:rebuild_provision_cache_snapshot` · `rsx:mail:test` · `rsx:logrotate` (`rsx:man logrotate`) · `rsx:app:module:create` · `rsx:search:reindex` · `rsx:maintenance:enable`/`:disable` · `rsx:ajax` · `rsx:overrides:list` |

**Conformance audits** (`rsx:conformance:list [--when=launch]` · `:show <id>` · `:for <path>`) are prompts for contracts no rule enforces, **run ONLY on request (one read-only agent per audit), never automatically; before writing or changing code, `rsx:conformance:for <path>` names the audits that govern it - read them and build to them.** Skill `rspade:conformance-audits`.

**Testing — never switch to the production DB, and never mark a failing test as passing.** Tests run against a separate database (`DB_TEST_DATABASE`, default `rspade_test`) and the runner REFUSES when that equals the dev database.

**Debugging output**: `rsx_dump_die(...$values)` (PHP var_dump/die with location + stack trace); `console_debug('CHANNEL', ...values)` (channel-filtered by `CONSOLE_DEBUG_FILTER`, both languages, stripped in production).

Details: `rsx:man testing` (assertion API, selector algebra, DB baselines).
