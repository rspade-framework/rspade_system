# Advanced: dynamic component tags and value printers

Two constructs that exist for **one** purpose in RSpade: rendering and editing a column
whose TEXT TYPE is declared on the model (`rsx:man text_types`). Read the "when NOT to"
in each section first — outside declared text types there is essentially no case for
either, and reaching for one where an ordinary conditional belongs makes a template
harder to read and invisible to rename refactoring.

---

## Dynamic component tags

A component's name may come from an expression:

```
<{expression} $arg=value attr="literal" />

<{expression} $arg=value>
    content
</{expression}>
```

The expression evaluates at render time with the same semantics as an unquoted argument
value (`$foo=this.data.bar`) and must yield a component name string.

### When NOT to use it — which is nearly always

A dynamic tag gives up everything a literal tag gives you: it is not greppable, rename
refactoring cannot see inside it, and the call site no longer states what can appear
there. If the set of components is **known**, write a conditional:

```
[OK]  <% if (this.data.compact) { %>
          <Compact_Card $item=_item />
      <% } else { %>
          <Full_Card $item=_item />
      <% } %>

[NO]  <{this.data.compact ? 'Compact_Card' : 'Full_Card'} $item=_item />
```

Two known components is a conditional. Three is still a conditional.

### When TO use it

When the component's identity is genuinely **data declared somewhere else** — the one
shipped case being "this column's declared text type says which component edits it":

```
<Form_Field $label="Description">
    <{Project_Model.editor_for('description')} $name="description" />
</Form_Field>
```

A form that hardcoded `<Wysiwyg_Input>` would defeat the entire text-type feature:
changing the column's declaration would then require finding and editing every form that
touches it.

**Ask the MODEL, not the value.** The editor is a property of the COLUMN, and at the
moment a form renders there is frequently no value to ask — an add form holds `null` in
every field, and an edit form is still fetching. The column's type is known
synchronously; the value is not.

### Rules

- **The closing tag is required** for the non-self-closing form, and its expression must
  match the opening tag's **as text**. The check is lexical and happens at compile time,
  so a mismatch is a build error. It is there for the reader, exactly as `</div>` is.
- **Name validation is identical to a literal tag**: non-empty, letters/digits/underscores,
  starting with a capital or a single underscore then a capital.
- **A valid name that names nothing renders the ordinary placeholder** — the same as an
  undefined literal tag, which is a jqhtml feature.
- **Content works normally** and reaches `content()`. A dynamic component is not a second
  kind of component.

### The failure mode

Because any valid name is accepted and unknown names become placeholders, an expression
yielding a **valid-but-wrong** name renders an empty placeholder and raises nothing.
`'Wysiwig_Input'` is a perfectly legal component name. Derive the name from a declared
source; never compose it from string fragments.

---

## Value printers — interpolating an object

`<%= %>` renders primitives. An **object** reaching an interpolation goes to a chain of
registered printers, which decide what it looks like.

This is how a framework hands templates **typed values** instead of pre-stringified ones:
the value carries its own encoding and the template interpolates it without choosing an
escape, a sanitizer or a component.

```javascript
<%= this.data.project.description %>    // a Rich_Text object, not a string
```

### When NOT to use it

**Do not register a printer to format things.** It is not a text-transform hook, not a
place for date or currency formatting, and not a display-logic seam. `Formatters` and
`Rsx_Time` exist for that and are callable from a template directly. A printer exists so
a VALUE TYPE can render itself; if you are reaching for one to avoid typing a function
call, it is the wrong tool.

In RSpade there is exactly **one** registration, made by `Rsx_Text_Abstract`, and it
covers every text type by delegating to the type. An application adds a printer only when
it has value objects of its own.

### Registering

```javascript
jqhtml.add_object_printer(function (value) {
    if (!(value instanceof My_Value)) {
        return undefined;              // decline - try the next printer
    }

    return 'plain text';               // a string, OR
    return {                           // a component descriptor
        component: {
            name:  'My_Value_Display',
            args:  { value: value },
            attrs: { class: 'my-value' },
        },
    };
});
```

### Rules

- **Printers form a chain** in registration order. `undefined` declines; the first
  printer to return anything else handles the value and the rest are not called. That is
  what lets an application register beside the framework without either knowing about
  the other.
- **Primitives never enter the chain**, and arrays keep their existing coercion. Ordinary
  interpolation costs exactly what it always did.
- **A returned string is treated as a string literal at that site**: escaped by `<%= %>`,
  raw under `<%!= %>`, escaped-with-breaks under `<%br= %>`. A printer cannot opt out of
  escaping — a type needing markup returns a descriptor and lets its component own that.
- **A descriptor carries no content.** `args` and `attrs` are separate buckets, neither
  taking a `$` sigil (the `$` is template syntax, not part of an argument name).
- **Attribute position never consults printers.** `<div title="<%= value %>">` keeps
  ordinary coercion, which lets a value type refuse to be flattened by throwing from
  `toString()`.
- **An unhandled object throws**, naming its constructor. `[object Object]` is never an
  intentional render.

### The two render targets

A live page renders through the type's PRINTER component. A server-generated document —
an email, a PDF — calls the type's `to_html()`. These are two **targets**, not two
implementations, and neither is written twice.

A type whose display is genuinely plain text may override `static print(value)` to return
a string instead of mounting a component, which matters in a long list where a component
per cell is a component per row.

---

## See also

`rsx:man text_types` - the feature both of these exist for
`rsx:man jqhtml` - ADVANCED: DYNAMIC COMPONENT TAGS, ADVANCED: VALUE PRINTERS
`resource/reference_app/app/frontend/projects/edit/Projects_Edit_Action.jqhtml` - the shipped dynamic-tag example
`resource/reference_app/theme/components/view/rich_text_display/` - a shipped printer component
