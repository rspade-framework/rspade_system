# Concern: theme (dark mode)

## Domain

The per-identity theme preference: `login_users.dark_mode`, an enum column of
light (0) / dark (1) / auto (2), defaulting to auto.

**The theme is applied before first paint, by one script.** The server resolves the
stored MODE and ships it, with the class names and the app's attribute vocabulary, in
`window.rsxapp.theme`. `autotheme.js` - a synchronous script every bundle emits directly
after that block and ahead of every stylesheet - puts the result on `<html>`. Under AUTO
it asks `prefers-color-scheme` and keeps following the operating system for the life of
the page; an explicit mode ignores the OS.

**The framework owns the preference, not the appearance.** `<html>` gets two classes
(`rsx-theme-*` naming the mode, `rsx-dark` while dark is showing) plus whatever
attributes the APP declares in `config('rsx.theme.dark_mode.attributes')`. RSpade ships
no UI toolkit and no colours, so that config is the whole extension point - a Bootstrap
app declares `data-bs-theme`, another declares whatever its CSS reads, and an app that
themes purely off `rsx-dark` declares nothing.

**Staff only**, exactly like the timezone preference: `portal_users` has no such column,
so a portal request always resolves to the configured default.

## Source under test

- `system/app/RSpade/Core/Theme/Rsx_Dark_Mode.php` (resolution, storage, the vocabulary)
- `system/app/RSpade/Core/Theme/Rsx_Dark_Mode_Controller.php` (get_settings / set_dark_mode)
- `system/app/RSpade/Core/public/_rsx/autotheme.js` (applies the theme, follows the OS)
- `system/app/RSpade/Core/Js/Rsx_Dark_Mode.js` (the in-bundle API over it)
- `system/app/RSpade/Core/Bundle/Rsx_Bundle_Abstract.php` (the rsxapp theme block and the script tag)
- `system/app/RSpade/helpers.php` (`rsx_body_class`)
- `system/app/RSpade/Core/Models/Login_User_Model.php` (`$enums['dark_mode']`)

## Man pages

- `php artisan rsx:man dark_mode`

## Testable surface

- php: resolution of each mode; the load-bearing `null` for auto; the class names and
  their config overrides; the app-declared attribute sets; an app declaring nothing;
  `changed` reporting; rejection of an unknown mode; and normalization, where a null or
  non-numeric value must read as AUTO rather than casting to 0 and becoming an explicit
  light preference nobody chose.
- playwright (`playwright/autotheme.js`): the script tag's position in `<head>`; the
  theme present on `<html>` with every bundle script blocked; AUTO following a live OS
  change; an explicit mode ignoring one; `Rsx_Dark_Mode.set_mode()` on a booted page.

## Notes

The preference is a column, not session state, so it follows the person to every browser
they sign in from. `Rsx_Dark_Mode::_clear_cache()` exists for tests that change identity
mid-request; production clears it on write.
