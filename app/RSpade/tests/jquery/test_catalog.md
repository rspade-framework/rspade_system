# Test catalog: jquery

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.

## rsx_numeric.js (playwright) - the numeric field filter

`$(input).rsx_numeric(options)` turns a text input into a numeric field: it filters the
characters, writes the formatted form inline as the user types, and answers `.val()` with the
raw number through a `$.valHooks.text` entry, so a caller - `Text_Input._get_value()`, a plain
`$(el).val()` - never learns the filter is there.

The probe is a runtime-created `<input>` on the framework's own `/_sys` page. Every case types
with the keyboard rather than triggering events, because the filter's whole subject is the
caret and the selection.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| jquery-numeric-01 | rejected characters never reach the box | `decimals: 0`, typing `12a3.4` | `1234` displayed - only the offending characters are dropped, not the digits after them | implemented |
| jquery-numeric-02 | a long fraction is truncated, not rounded | `decimals: 2`, typing `1234.567` | display and `.val()` both `1234.56` | implemented |
| jquery-numeric-03 | separators and the prefix are inline | `{decimals: 2, commas: true, prefix: '$'}`, typing `1234567.8` | `$1,234,567.8` displayed while focused | implemented |
| jquery-numeric-04 | `.val()` is the raw number | the same box | `1234567.8` | implemented |
| jquery-numeric-05 | blur does not change the display | blurring that box | `$1,234,567.8` still displayed, `.val()` still raw | implemented |
| jquery-numeric-06 | `.val(number)` displays it formatted | `.val('9876543.21')` | `$9,876,543.21` displayed, `.val()` returns `9876543.21` | implemented |
| jquery-numeric-07 | a trailing decimal point survives | typing `1234.` | `$1,234.` - the user is still typing the fraction | implemented |
| jquery-numeric-08 | backspace at the end over a formatting character | Backspace on `$1,234.` | `$1,234` - the point goes, not a character the filter wrote | implemented |
| jquery-numeric-09 | a separator left dangling goes with its digit | a second Backspace | `$123`, never `$1,23` | implemented |
| jquery-numeric-10 | empty is the empty string | `.val('')`, and an untouched box | display `''`, `.val()` `''` | implemented |
| jquery-numeric-11 | removal leaves the raw number | `rsx_numeric(false)` on `$1,234` | `1234` in the box | implemented |
| jquery-numeric-12 | removal takes the handlers and the hook with it | typing `abc` afterwards | `1234abc` accepted and returned by `.val()` | implemented |
| jquery-numeric-13 | a second call reconfigures | applying twice, then reading the event registry | exactly one `rsx_numeric`-namespaced handler per event | implemented |
| jquery-numeric-14 | the second call's options are the ones in force | typing `1234` after reconfiguring | `$1,234` displayed, `.val()` `1234` - formatted once | implemented |
| jquery-numeric-15 | pasted text is filtered like typing | inserting `12x34567.891` in one piece | `$1,234,567.89` displayed, `.val()` `1234567.89` | implemented |
| jquery-numeric-16 | focus selects the whole value | a keyboard focus and a mouse click | the value selected in both, a drag-selection left alone | planned |
| jquery-numeric-17 | an edit mid-string keeps the caret | typing a digit into the middle of a formatted value | the cleaned value, the caret still on the digit being edited | planned |
