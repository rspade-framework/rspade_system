# Concern: jquery

The framework's extensions to jQuery, installed by `Rsx_Jq_Helpers._on_framework_core_define()`
and available on every page: the `.click()` override and `.click_async()` busy-state, the
existence/visibility/traversal helpers, the `$.ajax()` block, `.width_group()`, and
`$(input).rsx_numeric()` - the ONE numeric field filter, which every numeric input in an
application invokes instead of filtering digits by hand, in its plain and its `time` form.

## Source under test

- `system/app/RSpade/Core/Js/Rsx_Jq_Helpers.js` - the helpers, the `$.ajax()` block, and
  `$.fn.rsx_numeric()` with its `$.valHooks.text` entry and the two pure helpers
  `_numeric_read()` / `_numeric_format()`, plus `_numeric_value()` and the hours:minutes
  conversion `_numeric_time_to_decimal()`
- `system/app/RSpade/Core/Js/Width_Group.js` - the width-group family
- `system/app/RSpade/Core/Ui/Button_Utils.js` - the busy-state engine behind `.click_async()`

## Behavior defined by

`rsx:man jquery` (the contract), `rsx:man width_group`, and skill `rspade:jquery-extensions`.

## Applicability note

These are BROWSER behaviours: they read the caret, the selection, the focus, the event
namespace registry and the live DOM. A synthetic `.trigger('input')` carries no caret and a
jsdom stub carries no selection model, so the coverage here is playwright, driving real
keystrokes at a real input.

A browser test cannot see the test tree at all - the trees enter the manifest only while
`rsx:test` runs, while a playwright script drives the ordinary web server. So a test here
uses the framework's own `/_sys` page and creates whatever element it needs at runtime in
the browser; nothing application-side is involved, and the tests run in any install.

## Testable surface

| Area | Type | Status |
|---|---|---|
| `rsx_numeric`: characters the options reject never reach the box | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: a fraction longer than `decimals` is truncated, never rounded | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: separators and the prefix are written inline while typing | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: `.val()` is the raw number both ways | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: blur reformats and does not change what is displayed | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: backspace at the end over a formatting character | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: an empty box and `.val('')` both answer `''` | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric(false)` leaves an ordinary text input holding the raw number | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: a second call reconfigures rather than double-binding | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: pasted text is filtered like typing | playwright | implemented (`rsx_numeric.js`) |
| `rsx_numeric`: `time` accepts a colon and converts it to decimal hours | playwright | implemented (`rsx_numeric_time.js`) |
| `rsx_numeric`: `time` leaves the colon form as typed while the field is focused | playwright | implemented (`rsx_numeric_time.js`) |
| `rsx_numeric`: `time` forces two decimal places whatever `decimals` says | playwright | implemented (`rsx_numeric_time.js`) |
| `rsx_numeric`: `.` and `:` are mutually exclusive in one value | playwright | implemented (`rsx_numeric_time.js`) |
| `rsx_numeric`: focus selects the whole value, mouse and keyboard alike | playwright | planned |
| `rsx_numeric`: an edit mid-string keeps the caret where the user left it | playwright | planned |
| `.click()` calls `preventDefault()`, `.click_allow_default()` does not | playwright | planned |
| `.click_async()` busy-state, re-entrancy guard, detached-node settle | playwright | planned |
| `$.ajax()` to a local URL throws, an external one passes through | playwright | planned |
| `.width_group()` sizing, resize recalculation, pruning | playwright | planned |
