<!-- single-source: never duplicate into another fragment. -->

## JQHTML COMPONENTS

**Comments in `.jqhtml` are ALWAYS `<%-- --%>`** — never `<!-- -->` (still parsed for
jqhtml constructs, JS inside executes, and it ships to the DOM) and never bare `//` in
markup. Full syntax table and the empty-body pitfall: the `rspade:jqhtml` skill's
`reference/template-syntax.md`.


RSX's component system. A component is up to three co-located files sharing one name: `Foo.jqhtml` (markup), optional `Foo.js` (class holding lifecycle hooks/logic/state), `Foo.scss` (its complete look). No registration — the manifest discovers them. Invoked from a template as `<Foo $arg=value>content</Foo>`, or from JS as `$(sel).component('Foo', {arg: v})`.

**`<Define>` IS the element**, not a wrapper: `<Define:My_Button tag="button" class="btn">`. Interpolation `<%= safe %>` / `<%!= html %>` / `<% js %>`; `<%= content() %>` renders caller content; `@click=this.handler` binds child elements.

**Three state buckets, never mixed**: `this.args` (arguments in; read-only inside `on_load()`), `this.data` (Ajax-loaded and the source of truth for display; writable ONLY in `on_create()`/`on_load()`, frozen otherwise), `this.state` (arbitrary UI/editor state, writable anywhere).

**Lifecycle hooks** in order: `on_create()` (sync defaults) -> `on_render()` (own markup; may fire repeatedly) -> `on_load()` (async fetch; DOM, `this.state` and `this.args` writes THROW here) -> `on_loaded()` -> `on_ready()` (children ready) -> `on_stop()`. Outside that order, `on_attach()` / `on_detach()` fire each time the root enters / leaves the document (`on_attach` first after `on_ready`, and possibly many times): for work that should PAUSE while off-page - **cleanup otherwise goes in `on_stop()`, which runs for every jQuery or framework removal**.

**Access**: `this.$` (element), `this.$sid('x')` (child element tagged `$sid=` in the template — template-only, never set from JS), `this.sid('x')` (child component instance), `$(sel).component()`. Events: `this.trigger(name, data)` / `child.on(name, cb)`.

**Re-render**: `reload()` restores `this.data` to its `on_create()` snapshot then re-runs `on_load()` -> render (`on_create()` is NOT called again; use when `this.args` changed); `render()`/`redraw()` re-executes the template only; `stop()` destroys. **A PARENT's re-render destroys and rebuilds its children** — a child instance, its `this.state` and its handlers go with it, so bind handlers namespaced-and-idempotent (`.off('click.ns').on('click.ns', ...)`), never behind a one-shot `this._wired` flag. Ajax belongs in `on_load()` — never refetch from a handler, and **independent server calls in one `on_load()` run in parallel under a single `Promise.all` (each non-fatal branch carrying its own `.catch`)**; sequence ONLY when a later call's ARGUMENTS come from an earlier call's RESULT.

**A screen that is a LIVE STREAM is written like a jQuery plugin, not through the lifecycle.** The load-then-render cycle suits a page whose content rarely changes: a realtime frame refetches and repaints it. It is the wrong tool for a view that grows by appending - a terminal's output, a log tail, a chat transcript - where re-rendering the whole history for every new line is the cost. Such an action or component (the **Standalone** tier; its docblock says so) has **NO `on_load()`**: the `.jqhtml` is static markup drawn once, **`on_ready()` is its `$(document).ready()`**, and from there the code does its own Ajax, binds its own events on `this.$sid(...)`, and changes the page with `.append()` / `.hide()` / `.show()`; its state is `this.state`, never `this.data`.

```javascript
/** @tier Standalone - a log tail: lines are appended, the history is never re-rendered. */
class Log_Tail extends Component {
    on_create() { this.state.last_id = 0; }
    async on_ready() {                                    // the component's $(document).ready()
        this.$sid('clear').off('click.log').on('click.log', () => this.$sid('lines').empty());
        this.subscribe('Log_Topic', {}, () => this.read_new());   // each frame APPENDS, nothing reloads
        this.read_new();
    }
    async read_new() {
        const lines = await Log_Controller.lines_after({after: this.state.last_id});
        for (const line of lines) { this.$sid('lines').append($('<div>').text(line.text)); this.state.last_id = line.id; }
    }
}
```

Never `reload()` such a component or re-render its parent around it - either rebuilds it from nothing. Skill `rspade:jqhtml`, the Standalone tier, has the full contract and a worked example.

**Compose pages from named components** — an Action template reads as component invocations, not `<div class>` soup; each component owns its whole look in its own SCSS. **A view template past ~325 lines is decomposed into page-private REGION components** — skill `rspade:view-decomposition`.

Skill `rspade:jqhtml` (references `template-syntax`, `lifecycle-and-state`, `component-api-events`, `semantic-composition`): syntax gotchas, hook decision matrix, editor pattern, reserved names, complexity tiers. `rsx:man jqhtml`.
