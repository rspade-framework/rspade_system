# env - test catalog

One row per test worth having (implemented AND not). Status is one of
`implemented`, `deferred`, `blocked`, `planned`.

## Env_Hostname_Guard_Test (php)

Covers Rsx_Env_Hostname_Guard's pure core. The PHP runner is CLI, where check()
bails, so the request wrapper's end-to-end behavior is proven by E2E curl in the
ticket verification (see EHG-E2E below), not a persistent test. The guard now
consults only the APP_URL host, matched exactly.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| EHG-01 | exact APP_URL host match passes | php | request host == APP_URL host | no mismatch | implemented | 2026-07-22 |
| EHG-02 | APP_URL host mismatch caught, names both hosts | php | request != APP_URL host | mismatch var=APP_URL, env+request hosts | implemented | 2026-07-22 |
| EHG-03 | old suffix rule no longer applies (exact only) | php | sub.domain of APP_URL host | mismatch; exact host still passes | implemented | 2026-07-22 |
| EHG-04 | loopback-VALUED APP_URL not skipped | php | APP_URL=https://localhost, real request host | 1 entry; mismatch | implemented | 2026-07-22 |
| EHG-05 | empty / absent APP_URL declares nothing | php | APP_URL='' and [] | 0 entries | implemented | 2026-07-22 |
| EHG-06 | hostless APP_URL fails loud | php | APP_URL='/ws' | RuntimeException | implemented | 2026-07-22 |
| EHG-07 | loopback hosts recognized (localhost/127.*/::1) | php | various | true; real host false | implemented | 2026-07-22 |
| EHG-08 | normalize strips port, lowercases, handles IPv6 brackets | php | HOST:port / [::1]:port | bare lowercased host | implemented | 2026-07-22 |
| EHG-09 | comparison is case-insensitive | php | mixed-case request + APP_URL | no mismatch | implemented | 2026-07-22 |

## App_Url_Test (php)

Covers Rsx_App_Url's pure transforms (the $HOSTNAME resolution + https invariant).
The boot seams (patch_environment / enforce_https_from_env) read/write the process
env and are proven E2E (see AU-E2E below).

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| AU-01 | resolve substitutes `$HOSTNAME` (no-brace) | php | https://$HOSTNAME + host | https://host | implemented | 2026-07-22 |
| AU-02 | resolve substitutes `${HOSTNAME}` (brace) | php | https://${HOSTNAME} + host | https://host | implemented | 2026-07-22 |
| AU-03 | resolve passes a no-token value through | php | literal url | unchanged | implemented | 2026-07-22 |
| AU-04 | resolve strips a trailing slash | php | .../ (token + literal) | no trailing slash | implemented | 2026-07-22 |
| AU-05 | resolve is idempotent | php | resolve(resolve(x)) | == resolve(x) | implemented | 2026-07-22 |
| AU-06 | enforce_https accepts https | php | https url | no throw | implemented | 2026-07-22 |
| AU-07 | enforce_https rejects http | php | http url | RuntimeException (SSL termination) | implemented | 2026-07-22 |
| AU-08 | enforce_https rejects http://localhost (no exemption) | php | http://localhost | RuntimeException | implemented | 2026-07-22 |
| AU-09 | enforce_https rejects empty/missing | php | '' | RuntimeException | implemented | 2026-07-22 |

## Env_Heal_Test (php)

Covers the developer-bootstrap heal (`Rsx_Env_Symlink::full_heal` / `boot_heal`).
Every test builds a throwaway project layout (`<tmp>/.env(.dist)`,
`<tmp>/system/.env(.dist)`, `<tmp>/storage/rsx-tmp`) and drives the healer at it
through the path seam; the real .env files are never touched.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| EH-01 | a missing .env is recreated byte-identical from the ROOT .env.dist | php | no .env, root dist present | .env == dist bytes; symlink restored | implemented | 2026-08-23 |
| EH-02 | no root .env.dist refuses, names the convention + recovery, and NEVER seeds from system/.env.dist | php | system dist only | RuntimeException; no .env created | implemented | 2026-08-23 |
| EH-03 | a populated APP_KEY is carried over untouched | php | dist with a real key | key byte-identical; nothing minted | implemented | 2026-08-23 |
| EH-04 | a blank APP_KEY in development is minted exactly once, reported once, idempotent | php | dist with APP_KEY= | one base64: key of 32 bytes; one line; one action | implemented | 2026-08-23 |
| EH-05 | boot_heal short-circuits on a fresh stamp | php | healthy layout + future-dated stamp | status skipped; .env untouched | implemented | 2026-08-23 |
| EH-06 | boot_heal runs on a stale stamp and refreshes it | php | new key in dist + old stamp | key synced; stamp newer than .env | implemented | 2026-08-23 |
| EH-07 | a failed copy is loud, never a silent no-op | php | .env path occupied by a directory | throws; no .env left behind | implemented | 2026-08-23 |
| EH-08 | key sync reaches both files, never overwrites a value, and is idempotent | php | system dist + root dist keys | keys appended; existing values kept; second run adds nothing | implemented | 2026-08-23 |
| EH-09 | sync precedes validation - a required key arriving via sync does not throw | php | REDIS_HOST only in system dist | no throw; key present in .env | implemented | 2026-08-23 |
| EH-10 | credential validation names the missing key | php | .env without DB_DATABASE | RuntimeException naming DB_DATABASE | implemented | 2026-08-23 |
| EH-11 | empty DB_PASSWORD and a passwordless Redis are legal | php | both blank | no throw | implemented | 2026-08-23 |
| EH-12 | outside development nothing is synced and nothing is minted | php | RSX_MODE=production | .env byte-identical | implemented | 2026-08-23 |
| EH-13 | outside development an empty APP_KEY is refused, not replaced | php | RSX_MODE=production, APP_KEY= | RuntimeException; key still empty | implemented | 2026-08-23 |
| EH-14 | .env.encrypted with NO .env is refused: never seeded from the dist over an encrypted file | php | .env.encrypted only | RuntimeException naming env:decrypt; no .env; dist untouched | implemented | 2026-08-23 |
| EH-15 | that refusal does not depend on a mode (with no .env the mode is unknowable) | php | .env.encrypted only, dist says production | RuntimeException; no .env | implemented | 2026-08-23 |
| EH-16 | .env.encrypted BESIDE a real .env changes nothing: .env wins, sync still runs, the snapshot is untouched | php | both present | live key kept; new dist key synced; .encrypted bytes unchanged | implemented | 2026-08-23 |

## Env_Path_Test (php)

Where Laravel believes the environment file lives. `base_path()` is `system/` - a git
submodule the framework pull resets and cleans - so `bootstrap/app.php` calls
`useEnvironmentPath(<project root>)`, and `Env_Decrypt_Command` overrides stock
`env:decrypt`'s independent `base_path()` OUTPUT default. No .env file is read,
written or named: the assertions are about paths and command resolution.

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| EP-01 | environmentPath() is the project root, deliberately not base_path() | php | booted app | dirname(base_path()) | implemented | 2026-08-23 |
| EP-02 | environmentFilePath() is the root .env | php | booted app | `<root>/.env` | implemented | 2026-08-23 |
| EP-03 | env:decrypt resolves to the framework override, and stays listed | php | Artisan::all() | Env_Decrypt_Command; not hidden | implemented | 2026-08-23 |
| EP-04 | its default output path is the root .env (the asymmetry stock Laravel has) | php | no options | `<root>/.env` | implemented | 2026-08-23 |
| EP-05 | --path still overrides the default directory | php | --path=<tmp> | `<tmp>/.env` | implemented | 2026-08-23 |
| EP-06 | --filename still overrides the default name, relative to the new directory | php | --filename=.env.recovered | `<root>/.env.recovered` | implemented | 2026-08-23 |

## Mode-derived Laravel config (`Mode_Derived_Config_Test`)

RSX_MODE is the single mode switch; `config/app.php` derives `app.env` and `app.debug`
from it and the APP_ENV / APP_DEBUG env keys are read nowhere. The tests re-`require`
the SHIPPED `config/app.php` with RSX_MODE forced across putenv/`$_ENV`/`$_SERVER`,
so the real expression is exercised and no file is touched.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| MDC-01 | development derives a local environment with debug on | php | RSX_MODE=development | env `local`, debug true | implemented | 2026-08-23 |
| MDC-02 | the sealed DIAGNOSTIC build keeps debug on and stays local | php | RSX_MODE=debug | env `local`, debug true | implemented | 2026-08-23 |
| MDC-03 | production IS the production environment, debug off | php | RSX_MODE=production | env `production`, debug exactly false | implemented | 2026-08-23 |
| MDC-04 | THE POINT: a stale APP_ENV/APP_DEBUG in the environment moves neither value | php | both keys set against the mode | env `local`, debug true | implemented | 2026-08-23 |
| MDC-05 | the BOOTED container agrees with the running mode (the derivation is what actually reached config()) | php | live `config('app.*')` vs `Rsx::get_mode()` | both follow the mode | implemented | 2026-08-23 |

## Deferred / planned

| ID | Purpose | Type | Status | Last updated |
|----|---------|------|--------|--------------|
| EHG-E2E | check() bails on loopback + throws on a mismatched non-loopback Host header | http | deferred (PHP runner is CLI; proven by ticket curl verification) | 2026-07-22 |
| EH-E2E | the pre-boot wiring: artisan, index.php and the container entrypoint all reach the same heal through bootstrap/rsx_env_heal.php | http | deferred (runs before the framework exists; verified by direct invocation) | 2026-08-23 |
| AU-E2E | patch_environment resolves $HOSTNAME into config('app.url') before config load; enforce_https_from_env fails readably on http (web + CLI) | http | deferred (boot-phase; proven by ticket curl + boot script) | 2026-07-22 |
