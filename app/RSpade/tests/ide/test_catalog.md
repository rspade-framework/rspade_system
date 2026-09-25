# IDE bridge - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| token_create | `ensure()` creates exactly one `ide-grant-*.token` holding a JSON grant document | php | fresh temp bridge dir | 1 token, `secret` matches `/^[0-9a-f]{64}$/` | implemented | 2026-08-25 |
| token_app_url | the grant carries the SERVER-resolved `app_url`, never the literal `$HOSTNAME` | php | fresh temp bridge dir | equals `config('app.url')`, no `$HOSTNAME` | implemented | 2026-08-25 |
| token_replace_unparseable | a file that is not a grant document is replaced outright (name + secret re-rolled) | php | dir pre-seeded with junk `.token` | stale gone, 1 fresh 64-hex secret | implemented | 2026-08-25 |
| token_guards | `ensure()` drops `index.php` (404) + `.htaccess` (deny) guards | php | fresh temp bridge dir | both files present, `.htaccess` denies | implemented | 2026-07-29 |
| token_clear_retired | `ensure()` removes retired `auth-*.json` + `domain.txt` | php | dir pre-seeded with both | both gone after ensure() | implemented | 2026-07-29 |
| token_idempotent | second `ensure()` keeps the same token name + content | php | two ensure() calls | 1 token, stable name/content | implemented | 2026-07-29 |
| token_current | `current_token()` returns the document's secret, null before | php | before/after ensure() | null then the on-disk `secret` | implemented | 2026-08-25 |
| token_rotate_pair | rotation keeps the PREVIOUS grant valid (a rotation is never a client-visible failure) | php | ensure() then rotate() | 2 active, the first secret still among them | implemented | 2026-08-25 |
| token_rotate_cap | repeated rotation never accumulates beyond ACTIVE_GRANTS | php | ensure() + 5 rotations | exactly 2 grants on disk | implemented | 2026-08-25 |
| token_rotate_fresh | a rotation mints a genuinely new secret and reports the file | php | ensure() then rotate() | mode=rotated, newest secret differs | implemented | 2026-08-25 |
| token_ensure_no_disturb | `ensure()` mints nothing while any grant exists (rotated pair untouched) | php | ensure(), rotate(), ensure() | file set identical | implemented | 2026-08-25 |
| token_cronless | THE CRON-LESS GUARANTEE: one grant survives indefinitely with no rotation | php | ensure() x10, no rotate | same secret throughout | implemented | 2026-08-25 |
| store_without_bridge | the GRANT STORE is ensured with `rsx.ide_integration.enabled` false (rsx:debug's key does not depend on the bridge's switch) | php | enabled=false, `ensure()` then `ensure_grant_store()` | 0 grants then 1 | implemented | 2026-09-06 |
| store_guards | `ensure_grant_store()` writes the static-serve guards itself - a file holding a secret is never web-servable | php | enabled=false, `ensure_grant_store()` | index.php + .htaccess present | implemented | 2026-09-06 |
| active_secrets_order | `active_secrets()` is newest-first and capped at ACTIVE_GRANTS; a retired secret disappears | php | ensure() + 2 rotations | [] -> [a] -> [b,a] -> [c,b] | implemented | 2026-09-06 |
| active_secrets_mode | `active_secrets()` is [] outside development - no development credential exists there | php | `Rsx::_testing_set_mode(debug/production)` | [] in both | implemented | 2026-09-06 |
| token_bridge_dir | `bridge_dir()` honors `rsx.ide_integration.bridge_path` | php | config override | equals base_path(config) | implemented | 2026-07-29 |
| auth_reject_no_token | `auth.php` returns 401 when no `X-Ide-Token`, from every address - there is no loopback exemption | http | tokenless GET /_ide/service/health, Host: localhost, from loopback | 401 | implemented (`http/ide_bridge_hardening.sh`) | 2026-09-25 |
| auth_accept_token | `auth.php` accepts a request whose `X-Ide-Token` matches the grant's `secret` | http | header = document `secret` | 200 from service | implemented (`http/ide_bridge_hardening.sh`) | 2026-09-25 |
| services_post_only | format, refactor, git, git/diff and manifest_build refuse GET | http | GET each with a valid token | 405 | implemented (`http/ide_bridge_hardening.sh`) | 2026-09-25 |
| git_diff_paths | git/diff refuses an option-shaped path (and its `--output` writes nothing) and a path outside the project, and serves a project path | http | `--output=/tmp/...`, `../html2/x.php`, `../../etc/passwd`, `rsx/../../outside.php`; `system/artisan` | "Invalid file path" each, no file written; success | implemented (`http/ide_bridge_hardening.sh`) | 2026-09-25 |
| grant_file_mode | the grant file is written 0600, and a refresh over a widened file restores it | php | ensure(); chmod 0666; ensure() | 600 both times | implemented (`Ide_Bridge_Token_Test`) | 2026-09-25 |
| IDE-GATE-SEALED | the pre-boot gate refuses debug and production before any token is read - the bridge is development-only by construction | php | `php -r` child per mode with RSX_MODE in its environment | 403 body "IDE services are development-only"; never "Authentication required" | implemented | 2026-09-15 |
| IDE-GATE-DEV | development falls through the mode gate to the credential question | php | same child, RSX_MODE=development | "Authentication required" | implemented | 2026-09-15 |
| IDE-GATE-NO-OPT-IN | the RSX_IDE_SERVICES_ENABLED opt-in is retired - the gate reads no such key and an environment carrying one changes nothing | php | auth.php source + a production child | key absent; still refused | implemented | 2026-09-15 |
| refactor_allowlist | `handle_refactor_service` rejects a non-allowlisted command | http | command not in allowlist | 403 not allowed | deferred (pre-boot standalone; manual) | 2026-07-29 |
| web_exposure_ok | `Web Exposure` health rows are OK on a correct docroot | http | `rsx:health` | OK rows, exit 0 | deferred (manual via rsx:health) | 2026-07-29 |

Notes:
- `auth.php` / `handler.php` are included by `system/public/index.php` BEFORE the
  autoloader boots and define their own `storage_path()`; they cannot be exercised
  cleanly in-process from `rsx:test`. Their behavior is covered by manual
  verification (the feature was manually verified before this close-out).
