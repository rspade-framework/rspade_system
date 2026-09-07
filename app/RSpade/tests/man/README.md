# Concern: man

## Domain overview & applicability

The `rsx:man` documentation command: lists available man pages and renders a
requested page (exact and partial term matching, not-found handling). It is the
primary in-tool reference surface for developers and AI agents, so its listing
and lookup behavior should stay stable.

## Source files

- `app/RSpade/Commands/.../` the `rsx:man` command implementation
- `app/RSpade/man/*.txt` (the pages), `rsx/resource/man/*.txt` (project pages)

## Man page(s)

- `man/man.txt`

## Testable surface

- `rsx:man` (no arg) lists pages (e.g. includes `spa`, `jqhtml`). (cli)
- `rsx:man <term>` exact match renders the page. (cli)
- Partial / fuzzy term matching. (cli)
- Not-found term handled gracefully. (cli)
- `--agent-helper-message` flag behavior. (cli)

These are command-output assertions. Currently implemented as a shell `cli` test
(`cli/man_command.sh`); could be re-expressed as a PHP `cli` test via
`Artisan::call('rsx:man', ...)` capturing output.

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md.)
