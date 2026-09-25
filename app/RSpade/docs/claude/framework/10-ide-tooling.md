<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## IDE TOOLING

**VS Code extension** (alpha): `/system/app/RSpade/resource/vscode_extension/` - LLMDIRECTIVE folding, RSX:USE protection, namespace updates.

**IDE helper endpoints**: `/_ide/service/*` (resolve_class, format, git, git/diff, refactor) back the extension. They run pre-Laravel-boot in `system/public/index.php`; the standalone handlers live in `Ide/Services/`.

**Auth = local-file grant** (no network token minting): the framework writes ONE unguessable `storage/rsx-ide-bridge/ide-grant-<hex>.token` (dev only, outside the docroot); the IDE reads it from local disk and sends `X-Ide-Token`, verified constant-time. Possession = proof of local read access, and the token is ALWAYS required (no loopback exemption); the grant file is 0600 and a refused chmod fails loud. **DEVELOPMENT MODE AND NOWHERE ELSE, by construction**: the pre-boot gate refuses any other `RSX_MODE` before reading a token, `config('rsx.ide_integration.enabled')` IS `Rsx::is_development()`, and there is no env key that reopens it.

**The services that write or run git (`format`, `refactor`, `git`, `git/diff`, `manifest_build`) are POST-only**, and a path the IDE sends must resolve strictly inside the project (git/diff refuses a `-`-leading path and passes it after `--`). **No arbitrary exec**: the only command surface is `/refactor` — an EXACT-match allowlist of the three `rsx:refactor:*` commands with escaped args. There is no general exec endpoint, and trust does NOT rely on file permissions (unguessable name+content, not-web-served storage, plus the `rsx:health` "Web Exposure" probe that FAILs loud if `.env`/`.git`/the bridge dir is served).
