# IDE bridge (concern: `ide`)

The IDE bridge is the `/_ide/service/*` surface the VS Code extension talks to.
The 2026-07-29 security rework replaced the old unauthenticated `auth/create`
mint + request-signing scheme with a **local-file grant** (an unguessable
`ide-grant-*.token` written outside the docroot; the IDE presents its contents as
`X-Ide-Token`, verified constant-time), removed the `exec`/`command` execution
surface (replaced by a narrow `refactor` allowlist), and rewrote `rsx:prod:export`
from a blacklist to a strict whitelist.

## Source under test

- `app/RSpade/Core/Ide/Ide_Bridge_Token.php` - the booted-context token manager
  (`ensure()` / `ensure_grant_store()` / `active_secrets()` / `current_token()` /
  `bridge_dir()`). The grant is the SHARED development credential: the IDE bridge
  takes it as the `X-Ide-Token` bearer, and rsx:debug's dev-auth signs with it
  (`Core/Debug/Dev_Auth_Token`, tested in the `dispatch` concern). Hence the split -
  `ensure_grant_store()` is gated on development ONLY, `ensure()` additionally on
  `rsx.ide_integration.enabled`.
- `app/RSpade/Ide/Services/auth.php` - pre-boot grant verification (loopback
  bypass + `X-Ide-Token` constant-time compare + prod/kill-switch hard gates).
- `app/RSpade/Ide/Services/handler.php` - service router + `handle_refactor_service`
  exact-match allowlist (`rsx:refactor:rename_php_class`,
  `rename_php_class_function`, `sort_php_class_functions`).
- `app/RSpade/Commands/Rsx/Prod_Export_Command.php` - whitelist exporter.
- `app/RSpade/Core/Health/Security_Health_Checks.php` - the `Web Exposure`
  health check (probes own APP_URL for served `.env` / `.git` / bridge dir).
- `app/RSpade/Core/Providers/Rsx_Framework_Provider.php` - `ensure_ide_bridge_token()`.

## Man pages

- `vs_code_extension` - the extension + grant model + endpoint list.
- `health` - the `Web Exposure` check.
- `storage_directories` - `storage/rsx-ide-bridge/` + the export whitelist.

## Testable surface (by type)

- **php** (implemented): `Ide_Bridge_Token` create/guards/retired-artifact
  cleanup/idempotency/`current_token`; `Prod_Export_Command::_is_excluded`
  whitelist predicate (secrets/runtime state excluded, source shipped).
- **http** (manual / deferred): `auth.php` reject-without-token (401), accept
  matching `X-Ide-Token`, loopback bypass, prod hard-off; `handle_refactor_service`
  allowlist reject of a non-allowed command. These run in a pre-boot standalone
  context (`auth.php`/`handler.php` are included by `public/index.php` ahead of
  the autoloader and define their own `storage_path()`), so they are not cleanly
  reachable from an in-process `rsx:test` and are covered by manual verification.
- **http** (manual): the `Web Exposure` health rows are OK on a correctly
  configured docroot (verified via `rsx:health`).
