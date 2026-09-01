---
name: scss-rules
description: "The SCSS rules RSpade enforces regardless of what an application's theme looks like - the single-class wrap (SCSS-SCOPE-01), BEM child classes prefixed with the exact PascalCase component name, no nested component selectors, no <style> tags and no hand-injected stylesheets, rsx/lib being non-visual, SCSS-SID-01 and SCSS-ANIM-01, and how SCSS reaches the browser through a module bundle's include order. Use when writing or moving an SCSS file, naming a BEM child class, splitting a stylesheet, wondering why a component renders unstyled or why editing a partial changed nothing, or responding to SCSS-SCOPE-01, SCSS-SID-01 or SCSS-ANIM-01."
---

# SCSS rules the framework enforces

Template-side counterpart: app skill `theme` (ships in `rsx/resource/skills/`).

This skill is the part that does not change when an application redesigns itself: where a
rule may live, what it must be wrapped in, how it is named, and how it reaches the
browser. Colours, spacing tokens, breakpoints and Bootstrap overrides are the
application's - app skill `theme`.

## Component-first

**Every styled element is a component with scoped SCSS.** No generic classes scattered
across files. If you are copy-pasting markup, extract a component; reusable structures
become a shared component with slots, one-off structures a page-specific component.

**A page's or action's own SCSS should be near-empty** - the look lives in the components
the page composes. A growing page stylesheet is the signal that a component wants
extracting. `php artisan rsx:man semantic_composition`.

## Where a rule may live

| Location | Purpose | Scoping |
|---|---|---|
| `rsx/app/` | feature components | MUST wrap in the component class |
| `rsx/theme/components/` | shared components | MUST wrap in the component class |
| `rsx/theme/` outside `components/` | primitives, variables, framework-CSS overrides | global |
| `rsx/lib/` | non-visual utilities | no styles at all |

## The single-class wrap - SCSS-SCOPE-01

An SCSS file in `rsx/app/` or `rsx/theme/components/` must be **fully enclosed in one
top-level class selector** matching its associated component class or Blade `@rsx_id`,
and the **filename must match** the associated file (different extension only).

```scss
// dashboard_index_action.scss
.Dashboard_Index_Action {
    padding: 24px;

    .card { margin-bottom: 16px; }
}
```

Components render with `class="Component_Name"` on their root automatically, so the
wrapper is the component's own name.

**Supplemental files** may carry a different filename once a primary file with the
matching name exists - the way to split a large stylesheet by breakpoint or feature. They
use the **same wrapper class**:

```
frontend_spa_layout.scss          # primary (required)
frontend_spa_layout_mobile.scss   # supplemental, same wrapper
```

**Styling another component from inside this one's SCSS is flagged too** - a nested
component selector creates hidden coupling and scatters a component's look across files.
Style it in its own file.

**There are NO exemptions.** A file that genuinely cannot follow the convention must be
moved out of those directories (`rsx/theme/base/`, say) - and that is a decision to raise,
not to take.

**Variables-only files** (only `$var: value;` declarations, no selectors) are valid with
no wrapper. Variables may also be declared above the wrapper in a component file to share
them.

## BEM child classes

Child classes use the **exact PascalCase component name** as prefix:

```scss
.DataGrid_Kanban {
    &__loading { }
    &__board   { }
}
```

```html
<div class="DataGrid_Kanban__loading">   <!-- correct -->
<div class="datagrid-kanban__loading">   <!-- WRONG: no styles, silently -->
```

**No kebab-case.** A kebab-cased BEM class does not match the compiled CSS, so the
element simply renders unstyled with nothing to report it. This is the single most common
cause of "my component has no styles".

## Two more lint rules

- **SCSS-SID-01** - never write a selector against `$sid` / `data-sid`. `$sid` is the
  component's own JS handle, not a styling hook; give the element a BEM class.
- **SCSS-ANIM-01** - no animations, transforms or element movement. Serious business
  tools: hover effects only on interactive elements, and nothing moves. (`.min.css` /
  `.min.scss` are skipped.)

## No `<style>`, and no hand-injected stylesheet

Markup carries no `<style>` block and no inline event handlers; SCSS files are the only
styling channel. An external stylesheet is **declared** in a `*.externals.php` beside the
feature and loaded with `Rsx.load_external('id')` - never appended by hand, because the
Content-Security-Policy whitelist derives from that declaration.
Skill `rspade:external-resources`.

## How SCSS reaches the browser

SCSS is compiled per MODULE BUNDLE from the directories the bundle includes, in
declaration order - so a variables file must be included **before** the directories that
consume it:

```php
'include' => [
    'rsx/theme/variables.scss',  // first
    'rsx/theme',                 // then directories
    'rsx/app/frontend',
],
```

A component whose directory no bundle includes reaches no page - that, not a missing
build step, is why a new component can render unstyled. Skill `rspade:bundles`.

Composition itself (slots, `content('name')`, extracting a component) is jqhtml:
`rspade:jqhtml`.

Details: `php artisan rsx:man scss`, `rsx:man semantic_composition`, `rsx:man zindex`.
Related: app skill `theme` (tokens, breakpoints, Bootstrap overrides, dark mode),
`rspade:bundles`, `rspade:external-resources`.
