<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## FRAMEWORK TESTING

`rsx:test` runs the APPLICATION suite (under `/rsx/`); **`rsx:test --framework` runs the framework suite instead** - the tests under `app/RSpade/tests/`. The two never mix in one run; every other selector (class name, `--filter=`, `--group=`) narrows within whichever suite is selected.

**The test trees (`app/RSpade/tests`, `app/RSpade/temp`, `rsx/tests`) are in the manifest ONLY while `rsx:test` runs** - `Manifest::scan_directories()` appends them under `Rsx_Test_Abstract::suite_is_running()` - so a plain build carries no fixture class or route, and the first ordinary request after a run rebuilds to drop them again.

**Framework tests live in `app/RSpade/tests/<concern>/`**, one directory per concern, each owning a README and a test catalog. Inside a concern the tests are split by execution kind: `php/`, `cli/`, `asset/`, `http/`, `playwright/`. A `--group=<concern>` selector matches that directory name exactly.

**Bash tests** in `/system/app/RSpade/tests/` run against `rspade_test` like the PHP ones: deterministic, zero manual intervention.

**EVERY `rsx:test` invocation runs in sibling docker containers when the gate passes** - both suites, any selector, a single class included (one class is one container: consistency, not speed). **The gate asks about the BOX, never about the invocation**: a development container, `docker info`, the dev image BUILT (every invocation, from cache, never a manual step) and version-matched, and a trivial container that actually runs. Any check failing prints ONE line and the run continues **sequentially** in-process - never an error; `--sequential` forces that path. **The verdict is cached by manifest build key + a fingerprint of `system/bin`, `node_modules` and the docker resource dir + a hash of the SELECTOR** (suite plus normalised class list), so a subset's verdict replays only for that same subset. Mechanics: `/system/bin/rsx-testd/CLAUDE.md`; the operator's view, including the host capability flags the nested daemon needs: `rsx:man testing`.

**Cadence**: the conduct fragment's rule. Per change: a smoke test (`rsx:debug`), no test run. The test you wrote or changed: run that class alone. The FULL framework suite (or the affected groups): ONCE, at the end of a major phase or before a release - never as per-edit housekeeping, never as "proof" for a patch you were handed.

Full structure, conventions, and per-kind harness details: `/system/app/RSpade/tests/CLAUDE.md`.

**The framework suite SHIPS** (`bin/publish` copies `app/RSpade/tests`), and downstream it is documented as an administrator's integrity audit of the installed software and environment - run with `rsx:test --framework`, long, rarely needed, never a development step. Downstream the same gate applies: a development container started with the capabilities nested docker needs dispatches exactly as this box does, and every other box runs sequentially.
