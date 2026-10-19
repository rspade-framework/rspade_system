# Concern: conformance

## Domain overview & applicability

Conformance audits are markdown review prompts for contracts an application's code must
honour that no lint rule can check. Each file is a header (`id`, `title`, `severity`,
`when`, `scope`, optional `related`) between two `---` lines, then a four-section prompt
body. The framework library ships in `app/RSpade/conformance/`; an application adds its
own in `rsx/resource/conformance/`. The two directories are ONE namespace: a repeated id
throws, and an application audit never replaces a framework one. A `CLAUDE.md` in either
directory is the directory's description and is not read as an audit.

Audits are run by agents on a developer's request, never by the framework, so what is
testable here is the library reader and the three commands - not the outcome of any
audit.

## Source files

- `app/RSpade/Core/Conformance/Conformance_Audits.php` - the reader: `directories()`
  (and the `$_directories_for_tests` seam), `all()`, `find()`, `for_path()`, the strict
  header parser and `glob_regex()`
- `app/RSpade/Commands/Rsx/Conformance_List_Command.php` - `rsx:conformance:list
  [--when=] [--severity=] [--json]`
- `app/RSpade/Commands/Rsx/Conformance_Show_Command.php` - `rsx:conformance:show <id>`
- `app/RSpade/Commands/Rsx/Conformance_For_Command.php` - `rsx:conformance:for <path>`
- `app/RSpade/conformance/*.md` - the shipped library

## Man pages

- `man/conformance_audits.txt`
- `man/launch_checklist.txt` (points at the launch audits)

## Testable surface

- The shipped library parses, every audit with a non-empty prompt. (php - no DB)
- A complete header is read into its fields: `when`, `scope` and `related` split on
  commas; the body follows the closing `---`. (php - no DB)
- A malformed header throws, naming the defect: no header, id not equal to the file
  name, an unknown severity or `when` value, a missing required key, an unknown key.
  (php - no DB)
- An id declared in both homes throws. (php - no DB)
- `CLAUDE.md` in an audit directory is skipped. (php - no DB)
- Scope globs: `**` crosses directories and `**/` matches none; `*` stays within one
  segment; the extension is anchored. (php - no DB)
- The commands: `list` orders framework first, `--severity` is a floor, `--when` filters
  by occasion, `--json` prints headers; `show` prints the prompt; `for` names only the
  audits whose scope covers the path. (cli, in-process via `Artisan::call()`)
