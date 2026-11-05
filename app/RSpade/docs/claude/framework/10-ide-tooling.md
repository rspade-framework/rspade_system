<!-- bucket: framework — single-source, never duplicate. True ONLY in this monorepo. -->

## IDE TOOLING

The IDE bridge (`/_ide/service/*`, pre-boot handlers in `Ide/Services/`, backing the VS Code extension in `resource/vscode_extension/`) authenticates with a **local-file grant**: rotating `ide-grant-<hex>.token` files outside the docroot (the two newest valid, `Ide_Bridge_Token::ACTIVE_GRANTS`), sent as `X-Ide-Token` and compared constant-time - possession is proof of local read access, with **no loopback exemption**. It is **DEVELOPMENT-ONLY BY CONSTRUCTION**: the pre-boot gate refuses any other `RSX_MODE`, `rsx.ide_integration.enabled` IS `Rsx::is_development()`, and no env key reopens it. Services that write or run git are POST-only, and **there is no arbitrary exec** - the only command surface is the exact-match `rsx:refactor:*` allowlist; never add a general exec endpoint. `rsx:man vs_code_extension`.
