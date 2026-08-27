---
name: dark-mode
description: The per-identity theme preference (light/dark/auto) - login_users.dark_mode, Rsx_Dark_Mode, the rsx-dark body class rendered server-side before first paint, the app-declared theme attributes in config('rsx.theme.dark_mode.attributes'), Rsx_Dark_Mode_Controller, and why a saved change needs Spa.disable() rather than a reload. Use when adding or theming dark mode, building a theme setting, styling a component for dark, wiring a UI framework's theme attribute (data-bs-theme or equivalent), or diagnosing a white flash on load, a page that stays the old theme after saving, or CSS that ignores the theme.
---

# Dark mode

RSpade owns the theme **preference** and how it reaches the page. It owns no colours, no stylesheet and no opinion about a UI toolkit — the app supplies those.

## The one thing to understand

**The preference is resolved on the server and rendered into `<body>`.** A theme applied by JavaScript after load paints the wrong colour first and corrects it a frame later — that is the white flash. Rendering it in the first bytes of HTML means the page is never wrong.

Three modes, and the third is not an absence:

| Mode | Value | Resolvable server-side? |
|---|---|---|
| light | 0 | yes |
| dark | 1 | yes |
| auto | 2 (default) | **no** — `prefers-color-scheme` exists only in the browser |

So under auto the server emits the **mode** and no theme, and `Rsx_Dark_Mode.js` resolves it at boot and keeps following the OS live. An auto user can see one frame of the default; an explicit user never does.

## What lands on `<body>`

```html
<body class="Spa_App rsx-theme-dark rsx-dark" data-bs-theme="dark">
```

- `rsx-theme-light|dark|auto` — the **mode chosen**. Always present. Says nothing about what is showing.
- `rsx-dark` — **dark is active right now**. Server-rendered for an explicit dark preference; added by JS under auto when the OS asks.
- everything else — **the app's own vocabulary**, declared in config (below).

**Style against `rsx-dark`.** It is the only class that answers "is this page dark" correctly under all three modes.

## Wiring your UI framework

The framework renders attributes the app declares — it never hardcodes one:

```php
// rsx/resource/config/rsx.php
'theme' => ['dark_mode' => ['attributes' => [
    'dark'  => ['data-bs-theme' => 'dark'],    // or data-theme, or whatever your CSS reads
    'light' => ['data-bs-theme' => 'light'],
]]],
```

An app that themes purely off `rsx-dark` declares nothing. Both halves land **server-side, before first paint** — that is the point of routing it through config rather than setting it in a layout's `on_create()`.

## Changing it

Post to the framework controller. Do not write the column, and do not wrap it in an app endpoint — the app owns the settings *screen*, not the preference.

```javascript
const result = await Rsx_Dark_Mode_Controller.set_dark_mode({ dark_mode: Rsx_Dark_Mode.MODE_DARK });

if (result.changed) {
    Spa.disable();   // next navigation becomes a full page load, repainting <body>
    Flash_Alert.success('Theme saved. The app will switch over when you navigate away.');
}
```

**The page on screen cannot be re-themed** — the server painted it. `Spa.disable()` keeps the SPA working but makes the next navigation (link click *or* programmatic redirect) a real request. Prefer it to reloading outright: the user may still be editing other settings, and yanking the page away to recolour it is worse than the brief mismatch.

## Styling

**Token-first, wherever you can.** Define the palette as CSS custom properties, redefine under the dark class, and every surface reading a token flips at once:

```scss
:root { --surface: #fff; --text: #111; }
body.rsx-dark { --surface: #10151b; --text: #e6e9ee; }
```

**Per-component overrides for what cannot be a token.** Values baked at compile time (SCSS variables, hardcoded hex) cannot be re-pointed by a runtime class:

```scss
.My_Widget {
    background: $white;

    body.rsx-dark & { background: #10151b; }   // nested: keeps ONE top-level selector
}
```

Nest it rather than opening a second top-level block, so the file stays `SCSS-SCOPE-01` clean.

**Two things that are not CSS:**
- Native controls (checkboxes, scrollbars, date pickers) are painted by the browser: `body.rsx-dark { color-scheme: dark; }`.
- A custom property inherits **downward only** — redefining a token on `body` does not reach a rule that resolved it on `html`.

## Gotchas

- **`rsx-theme-auto` does not mean dark.** A rule written against the mode class is wrong for half that mode's users.
- **A theme change does not repaint the current page.** Forget `Spa.disable()` and the user sees the old theme with no sign anything happened.
- **Auto cannot be resolved server-side**, and no framework change fixes that. If the one-frame flash matters, cover it in your own CSS with a `prefers-color-scheme` query scoped to `rsx-theme-auto` — the framework will not, because the right rule depends on your palette.
- **Staff only.** `portal_users` has no such column (as with timezone), so a portal request always gets the configured default.
- **It is a column, not session state** — it follows the person to every browser they sign in from.

## Full reference

`php artisan rsx:man dark_mode`. Related: `rspade:spa` (navigation and `Spa.disable()`), `rspade:scss` (component styling).
