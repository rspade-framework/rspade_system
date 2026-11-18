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

## rsx_numeric_time.js (playwright) - the time-entry mode

`$(input).rsx_numeric({time: true})` adds a colon to what may be typed and converts it to
decimal hours at the three moments the value leaves the user's hands - the getter, the
setter and blur - leaving the colon form as typed while the field is focused. That split is
what this file covers; the plain filter's own behaviour is `rsx_numeric.js`.

The probe is the same runtime-created `<input>` on `/_sys`, typed with the keyboard.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| jquery-numeric-time-01 | a colon value blurs to decimal hours | typing `1:30`, then blurring | `1.5` displayed, `.val()` `1.5` | implemented |
| jquery-numeric-time-02 | a colon with no minutes is a whole hour | typing `1:` | `1` - no fraction is padded on | implemented |
| jquery-numeric-time-03 | the hour may be omitted | typing `:30`, and `0:30` | `0.5` from both | implemented |
| jquery-numeric-time-04 | minutes at or past 60 roll into hours | typing `:90`, and `1:63` | `1.5` and `2.05` | implemented |
| jquery-numeric-time-05 | a minute that is not a clean fraction rounds to two decimals | typing `1:20` | `1.33`, display and `.val()` alike | implemented |
| jquery-numeric-time-06 | a quarter of an hour | typing `2:15` | `2.25` | implemented |
| jquery-numeric-time-07 | the setter runs the same conversion | `.val('2:15')` | `2.25` displayed, `.val()` `2.25` | implemented |
| jquery-numeric-time-08 | a colon after a decimal point is dropped | typing `1.5:2` | `1.52` | implemented |
| jquery-numeric-time-09 | a decimal point after a colon is dropped | typing `1:3.` | `1:3` | implemented |
| jquery-numeric-time-10 | a decimal is already decimal hours | typing `1.5` | `1.5`, untouched | implemented |
| jquery-numeric-time-11 | minutes are capped at two digits | typing `1:456` | `1:45` | implemented |
| jquery-numeric-time-12 | the colon survives while the field is focused | typing `1:30` without blurring | `1:30` displayed, `.val()` `1.5` at the same instant | implemented |
| jquery-numeric-time-13 | `decimals` is not the caller's to set in time mode | `{time: true, decimals: 0}`, typing `1:20` | `1.33` - two places either way | implemented |
| jquery-numeric-time-14 | an empty time box is the empty string | an untouched box | display `''`, `.val()` `''` | implemented |

## button_utils_loader.js (playwright) - the registered busy-state loader

`Button_Utils.set_loader(renderer)` replaces the markup drawn inside an element while it is
busy; everything else in the busy cycle stays the framework's. The probe is a runtime-created
`<button>` on `/_sys`; every case leaves the loader unregistered again.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| jquery-loader-01 | the framework spinner is the default | `set_submitting()` with nothing registered | `.Button_Utils__spinner` is the element's content | implemented |
| jquery-loader-02 | a registered renderer draws the busy state | a renderer returning a string | the renderer is handed the untouched element, and its markup is the content | implemented |
| jquery-loader-03 | the rest of the cycle is unchanged | the same busy element | `btn--submitting`, inline width/height equal to the pre-swap box, `border-box` | implemented |
| jquery-loader-04 | idempotence holds with a loader | a second `set_submitting()` under another renderer | content unchanged | implemented |
| jquery-loader-05 | clear restores whatever the loader was | `clear_submitting()` | original content, class removed, size unlocked | implemented |
| jquery-loader-06 | both calls chain | the return values | each returns the element it was given | implemented |
| jquery-loader-07 | a jQuery is accepted | a renderer returning `$('<span>')` | that node is the content, and clear restores the original | implemented |
| jquery-loader-08 | read at `set_submitting()` time | `click_async()` bound BEFORE `set_loader()` | the later renderer's markup is drawn; content restored on settle | implemented |
| jquery-loader-09 | `set_loader(null)` restores the framework spinner | null after a renderer | `.Button_Utils__spinner` | implemented |
| jquery-loader-10 | the argument is validated | a string, an object, `undefined`, `true` | each throws; the spinner stays in force | implemented |
| jquery-loader-11 | the renderer's return is validated | a renderer returning `undefined` | `set_submitting()` throws; the element is left untouched | implemented |

## jquery_helpers.js (playwright) - the helpers, the click override and the `$.ajax()` block

Each contract in `rsx:man jquery` installed by `Rsx_Jq_Helpers._on_framework_core_define()`,
briefly. Elements are created at run time on `/_sys`; clicks are real mouse clicks where the
default action is the subject. `.shallowFind()` is the jqhtml library's and is not here.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| jquery-helpers-01 | `.exists()` | a match, no match | `true`, `false` | implemented |
| jquery-helpers-02 | `.is_visible()` | displayed, `display:none`, inside a `display:none` parent | `true`, `false`, `false` | implemented |
| jquery-helpers-03 | `.is_in_dom()` | attached, detached | `true`, `false` | implemented |
| jquery-helpers-04 | `.is_in_viewport()` | a top element and one 6000px down, scrolled to each | in view exactly where scrolled | implemented |
| jquery-helpers-05 | `.tagname()` | a `div`, an `a` | `'div'`, `'a'` | implemented |
| jquery-helpers-06 | `.is_external()` | relative href, this origin, another host | `false`, `false`, `true` | implemented |
| jquery-helpers-07 | `:focus` | a focused and an unfocused input | matches only the focused one | implemented |
| jquery-helpers-08 | `.checkValidity()` / `.reportValidity()` | a form with an empty, then filled, required field | `false`, then `true`; a field works too | implemented |
| jquery-helpers-09 | `.requestSubmit()` | the valid form | one `submit` event; returns the set | implemented |
| jquery-helpers-10 | the validation wrappers on an empty set | `$()` | `false`, `false`; `requestSubmit()` returns the set | implemented |
| jquery-helpers-11 | `.closest_sibling()` | a near cousin and a farther match | only the nearest ancestor's match; no match is an empty jQuery | implemented |
| jquery-helpers-12 | `.click(handler)` prevents the default | a click on `<a href="#...">` | handler runs with `isDefaultPrevented()` true; the hash is unchanged; no warning | implemented |
| jquery-helpers-13 | a handler calling `preventDefault()` itself is told it is automatic | a click | one `console.warn` naming it | implemented |
| jquery-helpers-14 | `.click()` with no handler triggers | `$el.click()` | the bound handler runs | implemented |
| jquery-helpers-15 | `.click_allow_default(handler)` | a click on `<a href="#...">` | handler runs, default not prevented, the hash changes | implemented |
| jquery-helpers-16 | `.click_async()` refuses a non-function and chains | `'not a function'`; a function | throws `$.fn.click_async() requires a function handler`; returns the set | implemented |
| jquery-helpers-17 | `.click_async()` busy state | a click while the handler's promise is pending | handler ran once, default prevented, `btn--submitting`, content swapped | implemented |
| jquery-helpers-18 | `.click_async()` re-entrancy | `.trigger('click')` and a native click while busy | handler still ran once | implemented |
| jquery-helpers-19 | `.click_async()` settle | the promise resolves | class removed, content restored | implemented |
| jquery-helpers-20 | `.click_async()` on a detached element | detach while busy, then resolve | the element is left as it was; no error | implemented |
| jquery-helpers-21 | `$.ajax()` to a local URL throws | relative `{url}`, a bare url string, this origin's absolute URL | each throws `AJAX requests to localhost via $.ajax() are prohibited.` | implemented |
| jquery-helpers-22 | `$.ajax()` to `/_upload` names the upload transport | `{url: '/_upload'}` | throws naming `Ajax.upload(form_data` | implemented |
| jquery-helpers-23 | `$.ajax()` to an endpoint URL names the call to make | `/_rsx_api/Some_Controller/some_method` | the message carries `await Some_Controller.some_method(parameters)` | implemented |
| jquery-helpers-24 | `$.ajax()` to another host is jQuery's | an external URL, cancelled from `beforeSend` (the page CSP forbids the connection) | no throw; `beforeSend` reached once | implemented |
| jquery-helpers-25 | the shorthands are blocked too | `$.get()` / `$.post()` / `$.getJSON()` / `$.getScript()` / `.load()` to `/_sys/...`, `jQuery.get()` to this origin's absolute URL | each throws as jquery-helpers-21 | implemented |
| jquery-helpers-26 | `.scroll_up_to()` | an element above the viewport (window 3000px below it); the same element below the viewport; an empty set | the window scrolls up to the element's top; nothing moves; nothing moves and the set chains | implemented |
| jquery-helpers-27 | the predicates on an empty set | `$().is_in_dom()`, `$().is_in_viewport()`, `$().tagname()` | `false`, `false`, `undefined` | implemented |
