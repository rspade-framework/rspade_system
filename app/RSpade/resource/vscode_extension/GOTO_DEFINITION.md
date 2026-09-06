# Go to Definition - JavaScript to PHP Navigation

## Overview

The RSpade VS Code extension now supports "Go to Definition" navigation from JavaScript class references to their corresponding PHP implementations. This feature is particularly useful when working with RSX's Internal API system, where JavaScript code calls PHP methods through auto-generated stubs.

## How It Works

When you right-click on an identifier and select "Go to Definition", the extension will:

### For JavaScript Class References
1. Detect if the identifier looks like an RSX class (contains underscore, starts with capital letter)
2. Query the IDE helper endpoint (`/_idehelper`) to resolve the PHP file location
3. If a method is being called (e.g., `Demo_Index_Controller.hello_world()`), navigate directly to that method
4. Open the PHP file at the exact line where the class or method is defined

### For Bundle Aliases in PHP
1. Detect if you're in a bundle's `'include'` array
2. Check if the string is a bundle alias (lowercase single word like 'bootstrap5', 'jquery')
3. Query the IDE helper to resolve the alias to its bundle class
4. Open the bundle PHP file at the class definition

## Technical Implementation

### IDE Helper Endpoint

The endpoint `/_ide/service/*` (implemented in `/app/RSpade/Ide/Services/handler.php`) accepts:
- `class` parameter: The PHP class name to resolve
- `method` parameter (optional): The specific method to navigate to

Returns JSON with:
- `found`: Whether the class was found
- `file`: Relative path to the PHP file
- `line`: Line number where the class/method is defined
- `metadata`: Additional information about the class

### VS Code Extension

The `RspadeDefinitionProvider` (in `/app/RSpade/Extension/src/definition_provider.ts`):
- Implements VS Code's `DefinitionProvider` interface
- Registers for JavaScript and TypeScript files
- Makes HTTPS requests to the IDE helper endpoint
- Returns a `Location` object pointing to the PHP file

## Usage Examples

### JavaScript to PHP Navigation

```javascript
// In rsx/app/demo/demo_index.js
console.log(await Demo_Index_Controller.hello_world());
//                 ^^^^^^^^^^^^^^^^^^^^^ Right-click here
//                                       Select "Go to Definition"
//                                       Opens demo_index_controller.php at line 48
```

### Bundle Alias Navigation

```php
// In rsx/app/frontend/frontend_bundle.php
public static function define(): array
{
    return [
        'include' => [
            'bootstrap5',   // <- Right-click here, Go to Definition
                           //    Opens Bootstrap5_Bundle.php
            'jquery',      // <- Opens Jquery_Bundle.php
            'lodash',      // <- Opens Lodash_Bundle.php
        ],
    ];
}
```

## Auth check names

Every quoted name inside a PHP `#[Auth('a', 'b')]` or a JavaScript `@auth('a')` is
a separate target. The extension asks the bridge's `definition` service with
`{type: 'auth_check', identifier, realm}` and lands on the `#[Auth_Check]` method
that answers the name.

**The realm is inferred exactly as the manifest infers it** (see the AJAX REALM
block in `Core/Auth/Auth_ManifestSupport.php`): a class-level
`#[Auth_Realm('staff'|'portal'|'any')]` if the file declares one, else the portal
realm when the file lives under `rsx/portal/` or `system/app/RSpade/Core/Portal/`,
else staff. Staff and portal are separate registries, so the realm is what decides
which `Permission` lineage answers; `any` tries staff and then portal.

A hover on the same name reports `Class::method` and the realm.

## .Class_Name selectors

A leading-dot PascalCase-with-underscore token - `$(".Backend_Index")`,
`.closest('.Foo_Bar')`, a `.Foo_Bar` rule in SCSS - and a bare `class="Foo_Bar"`
attribute in a `.jqhtml` or `.blade.php` resolve through
`{type: 'css_class', identifier}`. A jqhtml component answers first and answers
with BOTH files, template before class, so VS Code offers the choice; a Blade view
whose `@rsx_id` matches answers second.

Only `/^[A-Z][A-Za-z0-9]*(_[A-Z][A-Za-z0-9]*)+$/` qualifies. `.btn-primary`,
`.card`, `.foo_bar` and a BEM child like `.Client_Card__header` are not component
names and are never touched; a name that matches nothing produces no link,
silently.

## Man pages and skills

`rsx:man topic`, `php artisan rsx:man topic`, `man topic`, `see also a, b`,
`topic(7)`, `topic.txt`, `rspade:skill-name` (bare, backticked, or after the word
`skill`), and inside a man page's own `SEE ALSO` section the compact
`topic - description` row and the multi-column `rsx:man a    rsx:man b` grid.

**Resolution is LOCAL - no bridge call.** The topic must exist as
`rsx/resource/man/<topic>.txt` (which wins) or `system/app/RSpade/man/<topic>.txt`;
a skill as `rsx/resource/skills/<name>/SKILL.md` (which wins) or
`system/app/RSpade/docs/skills/{shared,framework,app}/<name>/SKILL.md`. The listing
is cached and refreshed by a file watcher. In code files the reference must be
inside a comment; a man page is prose throughout, so no restriction applies there.

## One recognizer per construct

The patterns above live in `src/rspade_recognizers.ts`, which imports nothing from
`vscode`. The semantic-token providers and the definition providers call the SAME
function for the SAME ranges, so a token that is coloured is exactly a token that
can be followed, and there is no second copy of a pattern to keep in step. Because
the module is vscode-free it is tested with plain node:

```bash
node ./out/test/run_recognizer_tests.js
```

## The extension builds no file index

Resolution is always a question put to the IDE bridge, which answers from the
RSpade manifest (`storage/rsx-build/manifest_data.php`). The extension never walks
the tree to build an index of its own, so what it can resolve is exactly what the
manifest holds. The manifest skips every directory named `resource/`, which means
a vendored copy of the reference app under
`system/app/RSpade/resource/reference_app/` is never a definition target - `F12`
lands on the real class, not on the vendored copy of it.

The few places that do enumerate workspace files (the `**/*.php` file watcher, the
one `findFiles` lookup for `Rsx.js`) pass no exclude argument, so VS Code applies
the workspace's own `files.exclude` / `files.watcherExclude` settings. Excluding a
directory in `.vscode/settings.json` is therefore enough to keep the extension out
of it; there is no second list to maintain.

## Configuration

The base URL for the IDE helper can be configured in VS Code settings:

```json
{
  "rspade.baseUrl": "https://rspade.claude.dev.hanson.xyz"
}
```

## Security

- The IDE helper endpoint is only available in development mode
- Production environments return a 403 Forbidden error
- No sensitive information is exposed through this endpoint

## Installation

After building the extension with `./build.sh`, install the generated `.vsix` file:

1. In VS Code: Extensions → ... → Install from VSIX
2. Or via command line: `code --install-extension rspade-framework.vsix`

## Limitations

- Only works for RSX class names (containing underscores, starting with capital letter)
- Requires the RSpade manifest to be up-to-date
- The PHP file must exist and be indexed in the manifest