# RSpade VS Code Extension

This directory contains the Visual Studio Code extension for the RSpade framework, providing enhanced development experience with automatic code folding, namespace management, and integrated formatting.

## Purpose

The RSpade extension enhances VS Code for RSpade framework development by:
- Updating PHP namespaces automatically when files are moved or renamed
- Integrating PHP formatting directly into VS Code (replacing RunOnSave)
- Auto-renaming files to match RSX naming conventions (optional, configurable)

## Architecture

The extension is built with TypeScript and follows VS Code extension best practices:

### Core Components

1. **extension.ts** - Main entry point that activates providers and registers commands
2. **file_watcher.ts** - Monitors file moves/renames and triggers namespace updates
3. **formatting_provider.ts** - Integrates with the main formatter for PHP formatting
4. **auto_rename_provider.ts** - Automatically renames files to match RSX naming conventions
5. **config.ts** - Centralized configuration management

### Key Features

1. **Smart File Handling**
   - Detects file moves and renames
   - Automatically updates PHP namespaces for RSX files
   - Works with drag-and-drop in VS Code explorer

2. **Integrated Formatting**
   - PHP formatting via the IDE bridge endpoint `/_ide/service/format` (POST)
   - Authenticated by a local-file grant (`X-Ide-Token` header, no Docker)
   - Runs the main formatter at `./bin/rsx-format`
   - Graceful degradation - warns but allows save if formatting fails
   - Served by standalone pre-boot handlers - works even when Laravel is broken

3. **Auto-Rename Files** (Optional - disabled by default)
   - Automatically renames files in `./rsx` to match RSX naming conventions on save
   - Supports PHP classes, JavaScript classes, .blade.php files with @rsx_id, and .jqhtml components
   - Only renames if the correct filename doesn't already exist
   - Respects short-name conventions (directory-based prefixes)
   - Files containing `@FILENAME-CONVENTION-EXCEPTION` are skipped
   - Enable via `config/rsx.php`: `'development' => ['auto_rename_files' => true]`

   **Naming Conventions Applied:**
   - **PHP classes in rsx/**: Lowercase, optional short name
   - **JS classes in rsx/**: Lowercase, snake_case for Component subclasses
   - **.blade.php with @rsx_id**: Lowercase, optional short name
   - **.jqhtml components**: snake_case (lowercase with underscores)

   **Short Names:** If class is `Foo_Bar_Baz_Bom` in directory `./rsx/app/foo/bar/`, filename can be `baz_bom.php` instead of `foo_bar_baz_bom.php`

## Building

**Always build with `./build.sh`. Never run `npm install`, `npm run compile` or
`vsce package` by hand** - the script owns the whole sequence (clean, install,
compile, lint, package, rename, clean up), and a hand-run step leaves the
directory in a state the next build has to undo.

```bash
cd system/app/RSpade/resource/vscode_extension
bash build.sh --release   # what you ship
bash build.sh             # development build, sourcemaps included
```

`--release` compiles with `--sourceMap false`. `.vscodeignore` already keeps
`*.map` out of the package either way, so in a release the maps would be written
and then discarded; omitting them leaves `out/` holding exactly what ships.

The script requires Docker (it checks for `/.dockerenv` and exits otherwise) and
takes about 30 seconds.

### What every build does to the tree

Three side effects, all of them intentional and all of them worth knowing before
you run it a few times in a row while editing:

1. **The patch version is auto-incremented** in `package.json` (`0.1.224` ->
   `0.1.225`). There is no way to build without bumping it. Four rebuilds while
   tuning a setting means four versions burned.
2. **The output is renamed to `rspade-vscode-extension-<version>.vsix`.** vsce
   emits `rspade-framework-<version>.vsix` from the package `name`; the rename
   gives it a stem matching the vendored `jqhtml-vscode-extension-<version>.vsix`
   beside it. **Every build produces a NEW filename**, so the previous `.vsix`
   must be removed from git - the build's own `rm -f *.vsix` clears the working
   directory, but the old path is still tracked:

   ```bash
   git rm --cached <old>.vsix          # if the build already deleted it on disk
   git add rspade-vscode-extension-<new version>.vsix
   ```

   Consumers glob rather than hardcode the name (`install.sh`), so the moving
   version does not strand them. **If you add a new consumer, glob it too.**
3. **`out/` and `node_modules/` are deleted and rebuilt** from scratch. `out/` is
   gitignored (the `.vsix` carries its own copy of the compiled JS), so this
   produces no git noise.

### Publishing

`system/bin/publish` mirrors this directory to `rspade_vscode` on GitHub and
commits **only when something changed**, with the message
`RSpade VS Code extension v<version> - <date>` authored by the release bot. The
version in that message is read from `package.json`, so a rebuild that only
bumped the version still produces a commit. `out/` is excluded from the mirror.

### Editing the manifest

Marketplace-facing fields live in `package.json`: `displayName`, `description`,
`keywords` (five max - they are the whole of the search discoverability),
`categories`, `license`, `homepage`, `repository`, `bugs`, plus `contributes`
(configuration, commands, menus, keybindings, languages, grammars, semantic
tokens). `CHANGELOG.md` and `README.md` are rendered on the Marketplace listing;
keep the changelog to features and behavior changes.

`.vscodeignore` decides what users download. Internal documentation
(`CLAUDE.md`, `SETUP.md`, `GOTO_DEFINITION.md`, `JQHTML_HIGHLIGHTING.md`) and the
build/install scripts are excluded deliberately - **anything added to this
directory ships unless it is listed there.**

### Testing

Press F5 in VS Code to launch a development instance with the extension loaded.
This uses `out/` directly, so run a non-`--release` build first if you want
sourcemapped stack traces.

### Known rough edges

- The linter reports errors (currently 8, mostly `no-useless-escape`). `build.sh`
  catches the failure and continues by design; they do not block packaging.
- The final "To install on your host machine" block prints a path missing the
  `system/` segment, and its escape codes are not interpreted (plain `echo`
  instead of `echo -e`). Cosmetic; the build itself uses `SCRIPT_DIR` and is
  correct.

## Configuration

Users can configure the extension through VS Code settings:
- `rspade.enableFormatOnMove` - Toggle automatic namespace updates

## Integration

The extension integrates with the existing RSpade formatting infrastructure:
- Format-on-save handled by RunOnSave extension configured in `.vscode/settings.json`
- Uses the main formatter at `./bin/rsx-format`
- PHP formatting via `./bin/formatters/php-formatter`
- JSON formatting via `./bin/formatters/json-formatter`
- Maintains compatibility with existing VS Code tasks and settings