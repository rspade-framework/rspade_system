---
id: form_input_contract
title: Every form input keeps the value contract, and every form host uses it
severity: high
when: launch, change
scope: rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man form_input, rsx:man form_conventions, rsx:man spa, skill rspade:form-input-contract, skill rspade:form-engine, skill rspade:navigation-guard, skill form-components
---

## The contract

An input is a component extending `Form_Input_Abstract` that owns ONE named value. The base class owns `val()`, buffering, focus, the events and change tracking; the input implements `_get_value()` / `_set_value()`, calls `_mark_ready()` at the earliest moment a written value STICKS, and announces user edits with `_notify_input(value)`. In return a host may write and read the value at ANY moment - before an option list has been fetched, before a library has loaded, before the DOM exists - and get the same result, and `Rsx_Form.dirty()` can tell a user's change from a load.

A broken input fails quietly. A value written before the options arrive vanishes and the field submits blank (blank is a value, so the record's column is cleared). An input that fills itself in a tick after it was marked ready reads as changed with nobody touching it, so the form says "unsaved changes" on a page nobody edited and the navigation guard asks on every exit. A hand-fired `'input'` turns a load into a user edit. None of it throws.

Read `php artisan rsx:man form_input` (THE FOUR MEMBERS, BUFFERING, LIFECYCLE, FOCUS, EVENTS, CHANGED, NAMING, THE _validate() EXCEPTION, VALUE COERCION), `rsx:man form_conventions` (VALUES AND INPUT ACCESS, UNSAVED CHANGES, KEYSTROKES WIN) and `rsx:man spa` (THE NAVIGATION GUARD) first, then `rsx/theme/components/inputs/CLAUDE.md` and skill `form-components` (the shipped inputs are application code; where they record a divergence they win).

No rule enforces any of this at build time.

## Find every instance

Inputs:

    grep -rlnE "extends (Form_Input_Abstract|[A-Za-z_]+_Input)\b" rsx --include='*.js' | grep -v '^rsx/tests/'

Read each one whole, with its `.jqhtml` and every class it extends.

Form hosts:

    grep -rlnE "<Rsx_Form|\.Rsx_Form|Modal\.form\(|set_navigation_guard" rsx --include='*.js' --include='*.jqhtml' | grep -v '^rsx/tests/'

Then PROVE each input in a browser: render a page that uses it and run, through `php artisan rsx:debug <path> --user=<id> --eval="..."`, a probe that mounts or finds the input and checks (1) `val(x)` called before readiness reads back `x` after it, with options or a library still loading; (2) the form is clean after load with nobody touching anything - `$('.Rsx_Form').first().component().dirty()` is false - and stays clean a few seconds later; (3) typing a value and typing it back leaves the form clean. A picker with a server-fed option list is the case that matters most: set a value whose option is not yet loaded.

## What does not conform

An input:

- Overrides `val()` or `focus()` (implement `_get_value()`/`_set_value()`; override `_focus()`).
- Overrides `on_create()` without calling `super.on_create()`.
- Calls `_mark_ready()` before a `_set_value()` would stick - before TomSelect or another library is constructed, or before a server-fed option list has arrived - or never calls it on some path (an empty option list, a failed fetch, a disabled state).
- Has a `_set_value()` that lands the value LATER: selecting it only when an Ajax label lookup resolves, after a `setTimeout`, or once a library calls back. Until then `_get_value()` reports the old value - the form reads the field as changed, and a submit in that window sends the old value. The fix is a value readable at once (add a placeholder option and select it, then fill in the label) or `_mark_ready()` later.
- Has a `_get_value()` that changes with no user action and no `val()` call: a library reformatting its content after a tick, a filter or formatter installed AFTER `_mark_ready()` (so the buffered value is read once raw and then filtered - install it first), an option list replaced under a selected value, a re-render of the input dropping what was set.
- Becomes ready before its option list can hold the value it was given, and drops that value (a dependent picker - a State list waiting for its Country) - instead of keeping and reporting the value until the list can show it.
- A composite whose `_get_value()` reads child components that may still be loading, rather than its own state.
- Writes the user's own change with `this.val()` (an upload, a removal, a picker's dependent reset caused by the user): `val()` makes the value the new baseline, so the change reads as none. The form is `_set_value()` then `_notify_input()`.
- A keydown handler that prevents the keystroke and rewrites the box, or a blur reformat that changes the value, with no native `input` event dispatched - the edit is never announced.
- Re-creates a wrapped library in `on_ready()` (which `reload()` runs again) without destroying the previous instance.
- Has a `_set_value()` whose result depends on whether the input has initialized (timing indifference is the contract).
- Fires `'input'` or `'val'` by hand, or calls `_notify_input()` for anything but a user edit - from `_set_value()`, on load, on option arrival, from a programmatic write.
- Misses a user path: an edit (typing, picking, clearing, removing a tag, adding or removing a repeater row, paste, a date picker, a drag) that changes the value without `_notify_input()`.
- Renders a value into its template (`value="<%= ... %>"`, `selected`/`checked` from `this.data`), reads its value from `this.data` (only a fetched option list lives there), or adds the `.Form_Input_Abstract` class, a `data-name` or a marker class (`class="Widget"`) by hand.
- Rejects one spelling of an equal value: a discrete input that fails `val(3)` where its option is `"3"`, a checkbox that is unchecked by `1` or `true`.
- Treats `null`, `0`, `false` or `''` as "no value" in its buffering, `_set_value()` or argument defaults (`value || ''` turns `0` into blank; `this.args.x || '0'` makes `''` impossible) - `??` is the spelling.
- Uses `_validate()` for a required, format, length or range rule (it exists only for a constraint the server cannot be sent).
- Edits a declared text column type without `static ACCEPTS`, or wraps a bare string into the type to be accommodating.
- Draws rows imperatively once instead of from state in `on_render()`, so a re-render loses them.
- Implements its own change tracking or a baseline instead of the base class's `is_changed()`; overrides `_comparable_value()` to hide a real change.

A form host:

- Drives the navigation guard from `'input'` or `'submitted'` rather than the form's `'dirty'` event, or hand-rolls "has anything changed" (snapshotting `vals()`, a `this._changed` flag, comparing fields itself).
- Wires `'dirty'` after the form is ready without reading `form.dirty()` as it wires.
- Leaves the guard on in `on_stop()`, or has a Cancel link that does not clear it.
- Reaches into an input's markup (`.find('input').val()`, `.find('select')`, `.focus()` on an inner element) instead of `form.input(name).val()` / `.focus()`.
- Writes `$name` on a `<Form_Field>` instead of the input, sets `$value` on an input, or omits `$max_length` on a `Text_Input`.
- Waits for an input to be ready (`await`, a `setTimeout`, polling for options) before calling `val()`.
- Disables a Save button until the form is dirty by any means other than `'dirty'`.

Not findings:

- A composite input (a repeater) whose inner inputs are not in the form's `vals()`: serialization is shallow by design and the composite owns them.
- An input whose `_get_value()` returns a different spelling than it was given (`5` in, `'5'` out), so long as it is consistent once ready - the baseline is read back through `_get_value()`.
- `_comparable_value()` overridden to drop noise that is genuinely not a change (an id regenerated per render), with a comment saying so.
- Framework inputs under `system/` (out of scope).

## Report

`PASS - <one line: N inputs and M form hosts read, P inputs probed in a browser, all conform>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
