<!-- single-source: never duplicate into another fragment. -->

## WHAT IS RSPADE

**RSpade** = **R**apid **S**ingle **P**age **A**pplication **D**evelopment **E**nvironment - a B2B SaaS framework with a production-ready starter template. **Visual Basic-like development for PHP/Laravel**: RSX apps run in the RSpade runtime the way VB6 apps ran in the VB6 runtime - framework complexity hidden, everything just works, no build steps and no config hell. **The opinionated nature and simple API is the goal.**

**Important**: RSpade is built on Laravel but **diverges significantly. Do not assume Laravel patterns work in RSX without verification.** **Terminology**: **RSpade** = the complete framework | **RSX** = application code in `/rsx/`.

### The build is invisible

**Change file -> changes are live, like a VB6 compile.** RSpade is INTERPRETED: Edit -> Save -> Refresh browser (< 1 second). **Bundles compile JIT on web request, so "bundle not compiled" is NEVER the answer** - if changes aren't reflected, the problem is elsewhere (caching, wrong file, syntax error).

**NEVER run build/compile commands** unless explicitly instructed: `npm run compile` / `npm run build` (they don't exist), `bin/publish` (releases, not testing), `rsx:build` / `rsx:clean` / `rsx:bundle:compile` / `rsx:manifest:build` (automatic) - ANY "build", "compile" or "publish" command. **NEVER run `rsx:clean` or a `--clean` flag** - caches auto-invalidate; the only exception is an explicit request.
