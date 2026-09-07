# Concern: theme (dark mode)

## Domain

The per-identity theme preference: `login_users.dark_mode`, an enum column of
light (0) / dark (1) / auto (2), defaulting to auto.

**The design is server-first.** An explicit light or dark preference is resolved in
PHP and rendered onto `<body>` in the first bytes of HTML, so a dark-mode user never
sees a white page while the SPA shell boots. AUTO is the deliberate exception and
cannot be resolved server-side - `prefers-color-scheme` exists only in the browser -
so the server emits the mode, states no theme, and `Rsx_Dark_Mode.js` resolves it at
boot and follows the OS live.

**The framework owns the preference, not the appearance.** It renders two classes
(`rsx-theme-*` naming the mode, `rsx-dark` when dark is actually active) plus whatever
attributes the APP declares in `config('rsx.theme.dark_mode.attributes')`. RSpade ships
no UI toolkit and no colours, so that config is the whole extension point - a Bootstrap
app declares `data-bs-theme`, another declares whatever its CSS reads, and an app that
themes purely off `rsx-dark` declares nothing.

**Staff only**, exactly like the timezone preference: `portal_users` has no such column,
so a portal request always resolves to the configured default.

## Source under test

- `system/app/RSpade/Core/Theme/Rsx_Dark_Mode.php` (resolution, storage, the body contract)
- `system/app/RSpade/Core/Theme/Rsx_Dark_Mode_Controller.php` (get_settings / set_dark_mode)
- `system/app/RSpade/Core/Js/Rsx_Dark_Mode.js` (resolves AUTO, follows the OS)
- `system/app/RSpade/helpers.php` (`rsx_body_class`, `rsx_body_attributes`)
- `system/app/RSpade/Core/SPA/Spa_App.blade.php` (renders the body tag)
- `system/app/RSpade/Core/Models/Login_User_Model.php` (`$enums['dark_mode']`)

## Man pages

- `php artisan rsx:man dark_mode`
- `php artisan rsx:man spa` (DISABLING SPA NAVIGATION - how a saved change takes effect)

## Testable surface

- php: resolution of each mode; the load-bearing `null` for auto; the two body classes
  and which appears when; app-declared attributes rendered for the active theme and
  suppressed under auto; an app declaring nothing; `changed` reporting; rejection of an
  unknown mode; and normalization, where a null or non-numeric value must read as AUTO
  rather than casting to 0 and becoming an explicit light preference nobody chose.
- playwright (not yet implemented): the server-rendered body tag for an explicit
  preference; AUTO resolved client-side under an emulated OS scheme, and following a
  live change; `Spa.disable()` armed after a save so the next navigation is a full load.

## Notes

The preference is a column, not session state, so it follows the person to every browser
they sign in from. `Rsx_Dark_Mode::_clear_cache()` exists for tests that change identity
mid-request; production clears it on write.
