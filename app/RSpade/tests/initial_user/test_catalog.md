# initial_user - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| IU-01 | Both halves are created with id 1 even when the AUTO_INCREMENT counter has advanced past it | php | initial account removed, a probe row inserted+deleted on both tables, then `create()` | `users`.id = 1 and `login_users`.id = 1 read back from the database | implemented | 2026-08-24 |
| IU-02 | A second initial user is an impossible condition, not a case to handle | php | `create()` while the baseline account exists | RuntimeException containing "already has a row with id 1" | implemented | 2026-08-24 |
| IU-03 | `is_needed()` answers the caller's setup check off `login_users` | php | baseline present, then removed | false, then true | implemented | 2026-08-24 |
| IU-04 | `user.initial.created` fires once with the documented payload | php | `create(..., source: first_run)` with a recording fixture handler | one payload: user id 1, login_user id 1, site_id, source = first_run | implemented | 2026-08-24 |
| IU-05 | A handler in `/rsx/handlers/` runs on creation | php | `create()` | moved to the application suite (`rsx/tests/Initial_User_Handler_Test`) - what an application's handlers write is its own contract; discovery itself is proved by `Initial_User_Fixture_Handler` (IU-04) | moved | 2026-09-08 |
| IU-06 | The event fires for the TEST BASELINE too, so handler rows are part of every test's starting state | php | committed baseline (no setup) | moved to the application suite (`rsx/tests/Initial_User_Handler_Test`) - the rows asserted are the application's | moved | 2026-09-08 |
| IU-07 | A caller-chosen role is not overruled by a handler | php | `create(..., role_id: <an id from User_Model::role_id__enum_ids()>)` | the stored role is the one the caller chose | implemented | 2026-09-08 |
| IU-08 | The first-run setup screen creates the account through `Rsx_Initial_User` | playwright | fresh database, browse any URL in development | wizard renders, submits, account is id 1 | deferred - the screen requires an EMPTY login_users on a live server, which no in-suite database can offer while the suite's own baseline account exists | 2026-08-24 |
| IU-09 | The post-migrate step refuses blank `RSPADE_DEFAULT_*` outside development and the test database | cli | migrate with blank credentials in production mode | migrate fails, reporting the two missing keys | deferred - environment-dependent (mode + database identity); the same two rules the retired migration always had | 2026-08-24 |
| IU-10 | The test provisioning migrate never seeds an env account of its own (`--_no-initial-user`) | cli | provisioning run with `RSPADE_DEFAULT_*` configured | the baseline user is the account at id 1 | deferred - would require a provisioning run under two different env states; the flag is unconditional in `run_migrate_subprocess()` | 2026-08-24 |
| IU-11 | The env seed is a no-op once an account exists (the property that makes running it after every migrate harmless) | php | `create_from_env_if_needed()` with the baseline present | null, nothing written | implemented | 2026-08-24 |
| IU-12 | The env seed runs end to end on an empty database, and any account it produces is id 1 | php | initial account removed, then `create_from_env_if_needed()` | null (declined) or a user with id 1 - asserted both ways, since RSPADE_DEFAULT_* is a property of the box | implemented | 2026-08-24 |

## First_User_Setup_Token_Test (php, no transactions) - the first-user screen's double-submit token

`Rsx_First_User_Setup::token_for()`: the token the browser already holds is reused when
well-formed, so the favicon request a browser fires beside the page no longer rotates the
cookie underneath the open form (the 2026-09-14 field report; the pre-boot APP_URL screen had
the identical defect, pinned in the `env` concern's `First_Run_Token_Test`).

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| iu-token-01 | a well-formed cookie is reused | cookie = 32 hex | same value returned | implemented |
| iu-token-02 | parallel renders agree | mint, then render with that cookie | identical token | implemented |
| iu-token-03 | no cookie mints | no cookie | 32 lowercase hex | implemented |
| iu-token-04 | minting is random | two mints | differ | implemented |
| iu-token-05 | malformed cookies replaced | empty, short, uppercase, non-hex, too long, newline | never reused; fresh well-formed token | implemented |
| iu-token-06 | only this screen's cookie counts | the APP_URL screen's cookie present | not reused | implemented |
