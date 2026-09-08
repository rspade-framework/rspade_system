<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## FRAMEWORK TESTING

`rsx:test` runs the APPLICATION suite (under `/rsx/`); **`rsx:test --framework` runs the framework suite instead** - the tests under `app/RSpade/tests/`. The two never mix in one run; every other selector (class name, `--filter=`, `--group=`) narrows within whichever suite is selected.

**The test trees (`app/RSpade/tests`, `app/RSpade/temp`, `rsx/tests`) are in the manifest ONLY while `rsx:test` runs** - `Manifest::scan_directories()` appends them under `Rsx_Test_Abstract::suite_is_running()` - so a plain build carries no fixture class or route, and the first ordinary request after a run rebuilds to drop them again.

**Framework tests live in `app/RSpade/tests/<concern>/`**, one directory per concern, each owning a README and a test catalog. Inside a concern the tests are split by execution kind: `php/`, `cli/`, `asset/`, `http/`, `playwright/`. A `--group=<concern>` selector matches that directory name exactly.

**Bash tests** in `/system/app/RSpade/tests/` run against `rspade_test` like the PHP ones: deterministic, zero manual intervention.

**The FULL framework suite runs in parallel docker containers automatically** on this dev box (any subset, or `--sequential`, runs in one process), and **its verdict is cached by manifest build key plus a fingerprint of `system/bin`, `node_modules` and the docker resource dir** - a second full run with no source change replays the recorded result and says so - mechanics: `/system/bin/rsx-testd/CLAUDE.md`.

**Cadence**: the conduct fragment's rule. Per change: a smoke test (`rsx:debug`), no test run. The test you wrote or changed: run that class alone. The FULL framework suite (or the affected groups): ONCE, at the end of a major phase or before a release - never as per-edit housekeeping, never as "proof" for a patch you were handed.

Full structure, conventions, and per-kind harness details: `/system/app/RSpade/tests/CLAUDE.md`.

**The framework suite SHIPS** (`bin/publish` copies `app/RSpade/tests`), and downstream it is documented as an administrator's integrity audit of the installed software and environment - run with `rsx:test --framework`, long, rarely needed, never a development step. Docker mode is this box's; downstream the suite runs sequentially.
