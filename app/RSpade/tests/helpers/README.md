# Concern: helpers

## Domain overview & applicability

The global formatting helpers in `app/RSpade/helpers.php` that output a human-facing
string, starting with `bytes_to_human()` - the ONE byte formatter every CLI command and
report prints through (rsx:man helpers). Its JS twin lives in `Core/Js/functions.js`.

## Source files

- `app/RSpade/helpers.php` - `bytes_to_human()`

## Man page(s)

- `rsx:man helpers`

## Testable surface

- The unit ladder (B, KB, MB, GB, TB, PB), the precision argument, numeric strings, and the
  non-numeric answer `---`. (php)

## Documents

- `test_catalog.md` - full catalog.
