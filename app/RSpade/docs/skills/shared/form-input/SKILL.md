---
name: form-input
description: Writing a custom form input on the Form_Input_Abstract contract - _get_value()/_set_value(), _mark_ready() and value buffering, _notify_input() for user edits, the data-name attribute, and why an input never validates. Use when building an input widget, extending an existing input, wiring an input's events, or hitting "must implement _get_value()", "must implement _set_value()", "child input has no data-name attribute", a value that never appears, or a value lost on re-render.
---

# The form input contract

An input is a component that owns **ONE named value**. `Form_Input_Abstract`
(framework core, `Core/Forms/Form_Input_Abstract.js`) owns the whole value contract —
buffering, `val()`, events, the `data-name` attribute — so a concrete input only
describes how its value maps to its DOM. An application never carries a copy of the base
class.

## Minimal implementation

```javascript
class My_Custom_Input extends Form_Input_Abstract {
    _get_value()      { return this.$sid('input').val(); }
    _set_value(value) { this.$sid('input').val(value ?? ''); }

    on_render() {
        this._mark_ready();

        const that = this;
        this.$sid('input').on('input', function () {
            that._notify_input(that._get_value());
        });
    }
}
```

```jqhtml
<Define:My_Custom_Input>
    <input type="text" $sid="input" class="form-control" />
</Define:My_Custom_Input>
```

That is all of it. No `on_create()` for buffering state, no `val()` method, no manual
`trigger('val')`, no hand-triggered event pair, no `class="Widget"` (the
`.Form_Input_Abstract` class is stamped automatically).

**NEVER override `val()`.** It is where buffering and events live; different read/write
behaviour is expressed through `_get_value()`/`_set_value()`. Missing either throws
`My_Custom_Input must implement _get_value()`.

A class that overrides `on_create()` **must** call `super.on_create()` — that is where
buffering state initializes and `data-name` is stamped.

## Timing indifference is the contract

`val(value)` produces identical results whether called before initialization, after it,
or at any point between. The value is buffered and applied at `_mark_ready()`.

**If a caller needs `await component.ready()` or a timing trick to make `val()` work, the
component is broken** — and so is a `_set_value()` whose behaviour depends on
initialization state.

The pending slot uses a **boolean** flag, so `null`, `0`, `false` and `''` all buffer
faithfully. `null` is a real value (most nullable columns), never a sentinel. While a
value is pending, `val()` as a getter returns it — so a form serialized before every
input finished initializing still reports what was set.

## `_mark_ready()`: as early as it will stick

Initialize in `on_render()` whenever the input's DOM is self-contained; use `on_ready()`
only when initialization genuinely depends on child components or an async library.

| Input | Call `_mark_ready()` |
|---|---|
| plain text input | `on_render()` |
| TomSelect-backed | after the library instantiates |
| server-fed select | after the options arrive |
| Quill editor | inside the library's ready callback |

```javascript
on_ready() {
    const that = this;
    quill_ready(function () {
        that._initialize_quill();
        that._mark_ready();          // AFTER the library is usable
    });
}
```

**Renders rebuild the DOM.** An input that draws its own rows in JavaScript draws them
from **state** in `on_render()`, and every user edit writes back to that state — anything
drawn once imperatively vanishes on the next render. Worked example:
`system/app/RSpade/resource/reference_app/theme/components/inputs/tag_list/tag_list_input.js`.

## Events

| Event | Fires when |
|---|---|
| `'input'` | the USER changed the value through the UI |
| `'val'` | the value changed by ANY path (user or programmatic `val(v)`) |

`'input'` is fired **only** through `_notify_input(value)`, which fires `'input'` then
`'val'`, in that order. **Hand-triggering the pair is a defect**: the ordering and the
pairing belong to the base class, and a call site that fires only one of them silently
breaks every listener expecting the other.

`_mark_ready()` applying a buffered value fires `'val'` (the value did change). With
nothing buffered it fires **nothing** — readiness is not a value change, and a listener
wired to "on change" must not fire on load.

jqhtml replays an already-fired event to a late `.on()`, so a `'val'` listener fires
immediately with the current value and then on every change:

```javascript
this.sid('country').on('input', (c, v) => this.reload_states(v));  // user only
this.sid('amount').on('val',   (c, v) => this.update_total(v));    // all changes
```

From a form, reach one input with `form.input(name)`, not a selector.

## `$name` and `data-name`

`$name` is required on every input that participates in a form. The base class stamps it
as `data-name` on the component root — that attribute is how `Rsx_Form` discovers inputs
and how the error renderer targets them. **`data-name` is a live contract attribute**,
not a debug attribute: never hand-write it, never strip it.

`$name` goes on the INPUT, never on `Form_Field`. A field without one raises
*"Form_Field_Abstract child input has no data-name attribute."*

## Do NOT write client-side validation

An input has a `_validate(value)` seam, and **almost every input keeps the inherited
no-op forever**.

Validation lives on the server, once. A client check that duplicates a server rule
**masks the absence of the server rule**: blank never reaches the endpoint, the missing
server validator is never exercised, and the gap surfaces only when an API caller or a
script hits the endpoint directly — silently, in production. The round trip is fast
enough that pressing Submit and rendering the server's message IS the responsive UX.

`_validate()` exists ONLY for a constraint whose invalid state cannot even be
**expressed** to the server — a pick-at-most-two multiselect where a third selection is
unrepresentable in the payload, a structured value that cannot serialize when malformed.
It returns `null` or a message string, rendered through the same pipeline as a server
error. Never for required, format, length or range. And even where it is legitimate,
prefer interaction design: the best pick-at-most-two multiselect simply refuses the third
click and needs no message at all.

## Value coercion

An input must accept both string and numeric values: a select whose options are `"3"`,
`"5"`, `"9"` must work with `val(3)` and `val('3')` alike.

- **selects / discrete values** — convert to string before comparing.
- **text inputs** — jQuery's `.val()` handles it.
- **checkboxes** — accept `1`, `'1'` and `true` as checked.

```javascript
class Checkbox_Input extends Form_Input_Abstract {
    on_create() {
        super.on_create();
        this.checked_value = this.args.checked_value || '1';
        this.unchecked_value = this.args.unchecked_value || '0';
    }
    _get_value() {
        return this.$sid('input').prop('checked') ? this.checked_value : this.unchecked_value;
    }
    _set_value(value) {
        this.$sid('input').prop('checked',
            value === this.checked_value || value === '1' || value === 1 || value === true);
    }
}
```

## Extending another input

```javascript
class Select_Ajax_Input extends Select_Input {
    async on_load() {
        this.data.select_values = await Controller.get_options();
    }
    on_ready() {
        super.on_ready();      // parent instantiates TomSelect and _mark_ready()s
    }
    // _get_value() / _set_value() inherited
}
```

Adding behaviour to a parent's setter overrides `_set_value()` and calls
`super._set_value(value)` — it never becomes a `val()` override.

## Template rules

- Render elements **empty**. No `value` attribute, ever — the form writes the value.
- Inputs have no `on_load()`, so `this.data` is always `{}`.
- `Text_Input` requires `$max_length`: `Model.field_length('col')`, a number, or `-1`.
  Subclasses (`Phone_Text_Input`, `Currency_Input`) are exempt.

## Common mistakes

| Mistake | Consequence |
|---|---|
| Overriding `val()` | Buffering and events bypassed |
| Forgetting `_mark_ready()` | A buffered value is never applied; the field stays blank |
| `_mark_ready()` too early | The write lands before the widget can hold it |
| Hand-triggering `'input'`+`'val'` | Half the listeners break, silently |
| `on_create()` without `super.on_create()` | No buffering state, no `data-name` |
| Using `this.data` | Always `{}` |
| A required/format/length check | That rule is the server's |
| Treating `null` as "no value" | `val(null)` is a legitimate write |
| Drawing rows once, imperatively | They vanish on the next render |

Details: `php artisan rsx:man form_input`, `rsx:man form_conventions`. Related skills:
`rspade:forms`, `rspade:jqhtml`.
