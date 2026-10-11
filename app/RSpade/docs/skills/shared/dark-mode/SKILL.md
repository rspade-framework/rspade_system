---
name: dark-mode
description: "The per-identity theme preference (light/dark/auto) - login_users.dark_mode, Rsx_Dark_Mode, the rsx-dark class put on <html> before first paint by autotheme.js, the app-declared theme attributes in config('rsx.theme.dark_mode.attributes'), Rsx_Dark_Mode_Controller, and Rsx_Dark_Mode.set_mode() for re-theming the page in place. Use when adding or theming dark mode, building a theme setting, styling a component for dark, wiring a UI framework's theme attribute (data-bs-theme or equivalent), or diagnosing a white flash on load, a `body.rsx-dark` selector that matches nothing, a page that stays the old theme after saving, or CSS that ignores the theme."
---

# Dark mode

RSpade owns the theme **preference** and how it reaches the page. It owns no colours, no stylesheet and no opinion about a UI toolkit — the app supplies those.

## The one thing to understand

**The theme is on `<html>` before the first paint, in every mode.** Every bundle emits a small synchronous script (`/_rsx/autotheme.js`) directly after the `window.rsxapp` block and ahead of every stylesheet. It reads `window.rsxapp.theme`, resolves auto against `prefers-color-scheme`, and sets the classes and attributes on `<html>` before the browser has anything to draw. There is no flash for a signed-in user, a signed-out visitor or a portal user, and a layout adds nothing — the script arrives with `$bundle::render()`.

| Mode | Value | What shows |
|---|---|---|
| light | 0 | light, whatever the OS says |
| dark | 1 | dark, whatever the OS says |
| auto | 2 (default) | the operating system's choice, **followed live** while the page is open |

## What lands on `<html>`

```html
<html lang="en" class="rsx-theme-dark rsx-dark" data-bs-theme="dark">
```

- `rsx-theme-light|dark|auto` — the **mode chosen**. Always present. Says nothing about what is showing.
- `rsx-dark` — **dark is showing right now**: an explicit dark preference, or auto while the OS asks for dark.
- everything else — **the app's own vocabulary**, declared in config (below).

**Style against `rsx-dark`.** It is the only class that answers "is this page dark" correctly under all three modes. `<body>` carries no theme class; `rsx_body_class()` answers the view id only.

## Wiring your UI framework

The framework sets attributes the app declares — it never hardcodes one:

```php
// rsx/resource/config/rsx.php
'theme' => ['dark_mode' => ['attributes' => [
    'dark'  => ['data-bs-theme' => 'dark'],    // or data-theme, or whatever your CSS reads
    'light' => ['data-bs-theme' => 'light'],
]]],
```

An app that themes purely off `rsx-dark` declares nothing.

## Changing it

Post to the framework controller. Do not write the column, and do not wrap it in an app endpoint — the app owns the settings *screen*, not the preference.

```javascript
const result = await Rsx_Dark_Mode_Controller.set_dark_mode({ dark_mode: Rsx_Dark_Mode.MODE_DARK });

Rsx_Dark_Mode.set_mode(result.mode);   // the page on screen switches at once
```

No reload and no navigation: `set_mode()` rewrites the classes and attributes in place, and under auto resumes following the OS. `Rsx_Dark_Mode.is_dark()` answers what is showing right now; `Rsx_Dark_Mode.mode()` the mode in effect.

## Styling

**Token-first, wherever you can.** Define the palette as CSS custom properties, redefine under the dark class, and every surface reading a token flips at once:

```scss
:root { --surface: #fff; --text: #111; }
html.rsx-dark { --surface: #10151b; --text: #e6e9ee; }
```

**Per-component overrides for what cannot be a token.** Values baked at compile time (SCSS variables, hardcoded hex) cannot be re-pointed by a runtime class:

```scss
.My_Widget {
    background: $white;

    html.rsx-dark & { background: #10151b; }   // nested: keeps ONE top-level selector
}
```

Nest it rather than opening a second top-level block, so the file stays `SCSS-SCOPE-01` clean.

**Native controls are not CSS.** Checkboxes, scrollbars and date pickers are painted by the browser: `html.rsx-dark { color-scheme: dark; }`.

## Gotchas

- **The dark class is on `<html>`, not `<body>`.** `body.rsx-dark` matches nothing.
- **`rsx-theme-auto` does not mean dark.** A rule written against the mode class is wrong for half that mode's users.
- **The server does not know what an auto user is looking at.** `Rsx_Dark_Mode::is_dark()` is null under auto.
- **Staff only.** `portal_users` has no such column (as with timezone), so a portal request always runs under the configured default — auto, unless the app changed it.
- **It is a column, not session state** — it follows the person to every browser they sign in from.

## Full reference

`php artisan rsx:man dark_mode`. Related: `rspade:scss-rules` (the enforced SCSS rules) and the app skill `theme` (the colour tokens).
