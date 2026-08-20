# JQHTML VS Code Extension - LLM Reference

## Overview
The JQHTML VS Code extension provides syntax highlighting, snippets, and language support for `.jqhtml` template files. It enables developers to work efficiently with JQHTML templates in Visual Studio Code.

## Features

### Syntax Highlighting
- Full syntax highlighting for JQHTML template syntax
- JavaScript expression highlighting within `<% %>` blocks
- HTML structure highlighting
- Component definition and invocation highlighting
- Special attribute (`$`, `@`) recognition

### Language Configuration
- Auto-closing pairs for JQHTML tags
- Bracket matching for template blocks
- Comment toggling support (HTML-style comments)
- Indentation rules for nested templates

### Code Snippets

#### Component Definition
- `jqhtml:component` - Creates a new component definition
- `jqhtml:define` - Define block with component name
- `jqhtml:slot` - Slot definition snippet

#### Control Flow
- `jqhtml:for` - For loop with colon syntax
- `jqhtml:if` - If statement with colon syntax
- `jqhtml:each` - Each iteration helper

#### Data Binding
- `jqhtml:bind` - Data binding expression `<%= %>`
- `jqhtml:raw` - Raw output expression `<%! %>`
- `jqhtml:stmt` - Statement block `<% %>`

### File Association
- Automatically recognizes `.jqhtml` files
- Sets appropriate language mode for syntax highlighting
- Configures editor settings optimized for template editing

## Grammar Scopes

### Top-Level Scopes
- `source.jqhtml` - Root scope for JQHTML files
- `meta.component.jqhtml` - Component definition blocks
- `meta.tag.jqhtml` - Component invocation tags

### Expression Scopes
- `meta.expression.escaped.jqhtml` - `<%= %>` expressions
- `meta.expression.raw.jqhtml` - `<%! %>` expressions
- `meta.statement.jqhtml` - `<% %>` statement blocks

### Attribute Scopes
- `meta.attribute.dollar.jqhtml` - `$` prefixed attributes
- `meta.attribute.event.jqhtml` - `@` prefixed event handlers
- `meta.attribute.regular.jqhtml` - Standard HTML attributes

## Extension Configuration

### Recommended Settings
```json
{
  "[jqhtml]": {
    "editor.wordWrap": "on",
    "editor.quickSuggestions": {
      "other": true,
      "comments": false,
      "strings": true
    },
    "editor.autoClosingBrackets": "always",
    "editor.autoClosingQuotes": "always"
  }
}
```

### File Associations
```json
{
  "files.associations": {
    "*.jqhtml": "jqhtml"
  }
}
```

## Integration with JQHTML Toolchain

### Parser Integration
The extension works seamlessly with:
- `@jqhtml/parser` - For template compilation
- `@jqhtml/webpack-loader` - For build-time processing
- `@jqhtml/core` - For runtime component handling

### Development Workflow
1. Create `.jqhtml` template files with full syntax support
2. Use snippets for rapid component development
3. Leverage syntax highlighting for error prevention
4. Build with webpack-loader for production

## Language Server Protocol (Future)
Future versions may include:
- Semantic token support
- Go-to-definition for components
- Auto-completion for component names
- Template validation and diagnostics
- Hover information for JQHTML syntax

## Known Patterns

### Component Registration
```jqhtml
<Define:MyComponent as="div">
  <!-- component template -->
</Define:MyComponent>
```

### Component Usage
```jqhtml
<MyComponent $sid="instance" @click="handleClick">
  <#slot>Content here</#slot>
</MyComponent>
```

### Control Flow
```jqhtml
<% for (let item of this.data.items): %>
  <div $sid="item_<%= item.id %>">
    <%= item.name %>
  </div>
<% endfor; %>
```

## VS Code API Usage
- Contributes grammar through TextMate format
- Registers language configuration
- Provides snippet completion
- Sets editor defaults for `.jqhtml` files

## Extension Publishing
- Published as `@jqhtml/vscode-extension` npm package
- Includes `.version` file for version tracking
- Compatible with VS Code 1.74.0 and later
- MIT licensed