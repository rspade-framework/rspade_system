<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## FRAMEWORK TESTING

`rsx:test` runs the APPLICATION suite (under `/rsx/`); **`rsx:test --framework` runs the framework suite instead** - the tests under `app/RSpade/tests/`. The two never mix in one run; every other selector (class name, `--filter=`, `--group=`) narrows within whichever suite is selected.

**Framework tests live in `app/RSpade/tests/<concern>/`**, one directory per concern, each owning a README and a test catalog. Inside a concern the tests are split by execution kind: `php/`, `cli/`, `asset/`, `http/`, `playwright/`. A `--group=<concern>` selector matches that directory name exactly.

**Bash tests** in `/system/app/RSpade/tests/` run against `rspade_test` like the PHP ones: deterministic, zero manual intervention.

**The FULL framework suite runs in parallel docker containers automatically** on this dev box (any subset, or `--sequential`, runs in one process), and **its verdict is cached by manifest build key plus a fingerprint of `system/bin`, `node_modules` and the docker resource dir** - a second full run with no source change replays the recorded result and says so - mechanics: `/system/bin/rsx-testd/CLAUDE.md`.

**Cadence**: the FULL framework suite is for the end of a major change or before a release. For ordinary development, running the group(s) of the affected subsection (`--group=<concern>`) is sufficient - the conduct fragment's rule.

Full structure, conventions, and per-kind harness details: `/system/app/RSpade/tests/CLAUDE.md`.

**The framework suite SHIPS** (`bin/publish` copies `app/RSpade/tests`), and downstream it is documented as an administrator's integrity audit of the installed software and environment - run with `rsx:test --framework`, long, rarely needed, never a development step. Docker mode is this box's; downstream the suite runs sequentially.
