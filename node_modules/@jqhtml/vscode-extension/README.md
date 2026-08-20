# JQHTML VS Code Extension

Syntax highlighting and language support for JQHTML template files.

## Features

### Syntax Highlighting

Full syntax highlighting for all JQHTML constructs:

- **Component Definitions**: `<Define:ComponentName>`
- **Template Expressions**: `<%= expression %>`
- **Control Flow**: `<% if (condition) { %> ... <% } %>`
- **Slots**: `<#slotname>` with let:prop support
- **Data Bindings**: `:property="value"`
- **Event Handlers**: `@click="handler"`
- **Special Attributes**: `$sid="name"`, `$property="value"`
- **Components**: `<MyComponent />`
- **Comments**: `<%-- comment --%>`

### Language Configuration

- **Auto-closing pairs**: Automatically close tags, brackets, and quotes
- **Bracket matching**: Highlight matching brackets and tags
- **Code folding**: Fold component definitions
- **Smart indentation**: Handles control flow
- **Comment toggling**: Use standard VS Code shortcuts to toggle comments

### Code Snippets

14 snippets for common patterns:

| Prefix | Description |
|--------|-------------|
| `define` | Component definition |
| `definecomp` | Component with structure |
| `if{` | If statement (brace style) |
| `for{` | For loop (brace style) |
| `exp` | Expression `<%= %>` |
| `$id` | Scoped ID attribute |
| `:prop` | Property binding |
| `@event` | Event handler |
| `slot` | Named slot |
| `slotprop` | Slot with props |
| `slotself` | Self-closing slot |
| `comment` | Comment block |
| `comp` | Component usage |
| `compslot` | Component with slot content |

## Installation

### From Marketplace (when published)

1. Open VS Code
2. Go to Extensions (Ctrl+Shift+X / Cmd+Shift+X)
3. Search for "JQHTML"
4. Click Install

### From Source (Development)

1. Clone the JQHTML repository
2. Navigate to `packages/vscode-extension`
3. Run `npm install`
4. Run `npm run compile`
5. Copy the folder to VS Code extensions directory:
   - Windows: `%USERPROFILE%\.vscode\extensions`
   - macOS/Linux: `~/.vscode/extensions`
6. Restart VS Code

### Using .vsix Package

1. Package the extension: `vsce package`
2. In VS Code: Extensions → ... → Install from VSIX
3. Select the generated .vsix file

## Usage

The extension automatically activates for `.jqhtml` files. Features include:

### Syntax Highlighting

All JQHTML syntax is highlighted with semantic colors:

```jqhtml
<Define:UserCard>
  <div class="user-card" $sid="card">
    <h2><%= this.data.name %></h2>

    <% if (this.data.isAdmin) { %>
      <span class="admin">Admin</span>
    <% } %>

    <button @click="handleClick">Click Me</button>

    <% for (const skill of this.data.skills) { %>
      <div class="skill"><%= skill %></div>
    <% } %>
  </div>
</Define:UserCard>
```

### IntelliSense

Basic HTML tag and attribute completion is provided through VS Code's built-in HTML support. In addition, the extension ships custom, JQHTML-aware providers:

- **Go to Definition** - Jump from a component tag, `$` attribute reference, `extends=""` attribute, or `Slot:` name to where it's defined, backed by a workspace-wide component index.
- **Hover** - Hover over a component name for JQHTML-specific information, using the same component index.

### Code Folding

Component definitions can be folded at the `<Define:>` level:

```
▼ <Define:MyComponent>
  ...
  </Define:MyComponent>
```

### Formatting Support

The extension ships a custom, JQHTML-aware document formatter (not VS Code's generic HTML formatter) that understands `<%-- --%>` comments, `<% %>` code blocks, self-closing tags, and JQHTML's indentation rules. Run it via **Format Document** or your usual format-on-save setting.

### Laravel Blade Support

The extension also registers a `blade` language (for `.blade.php` files) and injects JQHTML component highlighting into it, so JQHTML components used inside Laravel Blade templates get highlighted too. This includes:

- Component tag names and the `tag=""` attribute highlighted via a dedicated semantic tokens provider
- Blade-aware indentation/auto-indent rules
- Auto-spacing inside Blade tags as you type - `{{` expands to `{{ | }}`, `{!!` to `{!! | !!}`, and `{{--` to `{{-- | --}}` (cursor at `|`)

Two settings control this behavior:

| Setting | Default | Description |
|---------|---------|--------------|
| `jqhtml.enableBladeSupport` | `true` | Enable JQHTML component highlighting in Laravel Blade (`.blade.php`) files |
| `jqhtml.enableBladeAutoSpacing` | `true` | Automatically add spaces inside Blade tags when typing |

## Configuration

The extension sets these defaults for JQHTML files:

```json
{
  "[jqhtml]": {
    "editor.wordWrap": "on",
    "editor.quickSuggestions": {
      "other": true,
      "comments": false,
      "strings": true
    }
  }
}
```

You can override these in your VS Code settings.

## Theme Support

The extension uses standard TextMate scopes and works with all VS Code themes. For best results, use a theme with good HTML/JavaScript support.

### Scope Reference

- `entity.name.class.component.jqhtml` - Component names
- `meta.tag.slot.jqhtml` - Slot tags (header, row, footer, etc.)
- `keyword.control.slot.jqhtml` - Slot `:` prefix (`Slot:name`) and the `$` prefix on special attributes
- `keyword.control.flow.jqhtml` - Control flow keywords (if, for, etc.)
- `meta.attribute.special.jqhtml` - Special (`$`) attribute rule
- `punctuation.definition.attribute.binding.jqhtml` - `:` prefix
- `punctuation.definition.attribute.event.jqhtml` - `@` prefix

## Known Issues

### Bracket Matching Errors with Split Control Flow

When using control flow split across multiple `<% %>` blocks, VS Code may show bracket matching errors:

```jqhtml
<% if (condition) { %>
  <div>Content</div>
<% } else if (otherCondition) { %>  ⚠️ VS Code shows bracket error here
  <div>Other content</div>
<% } %>
```

**Why this happens:** VS Code's bracket matcher can't track bracket state across separate template blocks. It sees a closing `}` without a matching opening `{` in the same block.

**Solution:** The extension automatically disables bracket colorization for `.jqhtml` files:

```json
{
  "[jqhtml]": {
    "editor.bracketPairColorization.enabled": false,
    "editor.guides.bracketPairs": false
  }
}
```

These visual errors don't affect functionality.

### Other Known Issues

- Complex nested template expressions may not highlight perfectly
- Some edge cases in mixed HTML/JavaScript contexts

## Contributing

Contributions are welcome! The extension source is in the JQHTML repository under `packages/vscode-extension`.

### Development

1. Clone the repository
2. Open in VS Code
3. Run `npm install`
4. Press F5 to launch a new VS Code window with the extension
5. Open a `.jqhtml` file to test

### Testing

Testing is currently manual only (there is no automated test suite - `npm test` is a stub). Create test files in `test-files/` to verify syntax highlighting:

```bash
# Manual testing
# 1. Launch extension (F5)
# 2. Open test files
# 3. Verify highlighting
```

## License

MIT License - See LICENSE file in the JQHTML repository

## Changelog

### 2.0.0
- Initial release
- Full JQHTML v2 syntax support
- Brace-style control flow
- Slot syntax with let:prop
- Data binding and event handlers
- Component highlighting
- Code snippets