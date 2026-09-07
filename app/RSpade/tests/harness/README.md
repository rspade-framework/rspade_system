# Concern: harness

## Domain overview & applicability

The test harness itself: `Rsx_Test_Abstract` and the `rsx:test` runner. These
self-tests guard the testing infrastructure every other concern depends on - the
`__assert_*` helpers, `__assert_throws`, session impersonation helpers, and the
per-test transaction rollback isolation. If the harness regresses, every other
suite becomes untrustworthy, so these are foundational.

## Source files

- `app/RSpade/Core/Testing/Rsx_Test_Abstract.php` - base class, assertions, flags
- `app/RSpade/Commands/Rsx/Rsx_Test_Command.php` - runner: discovery, suite split,
  per-class DB reset, transaction wrapping, deterministic ordering

## Man page(s)

- None yet (a `testing` man page is a planned topic). This concern is documented
  by `tests/CLAUDE.md` and the application tests doc.

## Testable surface

- Assertion helpers behave correctly (pass on truth, throw on failure). (php)
- `__assert_throws` returns the caught exception, fails when nothing throws,
  fails on wrong class. (php)
- Session impersonation helpers set/reset CLI context. (php)
- Per-test transaction rollback isolates methods (insert in one test not visible
  in the next). (php - default isolation)
- Per-class `$requires_db_reset` flow (reset once before a dirty class). (php -
  exercised indirectly by tasks/session cleanup; a dedicated harness test is planned)
- Nested-run isolation: `$results` / `$current_test` are declared on the abstract
  base, so late static binding gives every subclass the SAME storage. `run()` saves
  and restores both, so a test that runs another test class inside itself cannot wipe
  the caller's results. (php)

## Documents

- `test_catalog.md` - full catalog.
- (no issues_encountered.md.)
