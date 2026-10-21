---
name: jqhtml
description: "Building jqhtml components in RSX - template syntax (<Define>, interpolation, content()/slots, @click), the six-stage lifecycle (on_create, on_render, on_load, on_loaded, on_ready, on_stop) plus on_attach / on_detach / is_attached for a component entering and leaving the document, the this.args/this.data/this.state triad, component access ($sid, this.sid, this.$), reload/render/redraw/refresh/load, targeted $redrawable redraws, extends= template inheritance and abstract bases, custom events, and the complexity tiers. Use when creating or editing a .jqhtml template or its companion .js class, deciding which lifecycle hook code belongs in, fixing a component that renders stale or empty data, binding handlers that stop working after a render, choosing between reload() and render(), naming a custom event or method, building an abstract base or a datagrid with slot data, composing a page out of semantic components, or building a screen that is a live, append-only stream (a terminal's output, a log tail, a chat transcript) where re-rendering on every update is too expensive and the component must work like a jQuery plugin with no on_load() (the Standalone tier)."
---

# jqhtml Components

jqhtml is RSX's component system. A component is up to three co-located files sharing one name:

```
user_card.jqhtml     # markup - the template (REQUIRED)
User_Card.js         # logic, lifecycle, state (optional - only when behavior exists)
user_card.scss       # the component's COMPLETE look (wrapped in .User_Card)
```

Names are **Pascal_Snake_Case** (`User_Card`, `Save_Button`) - not bare PascalCase; jqhtml's own public docs use `UserCard` style, do not follow it here. A name may carry ONE leading underscore before its capital (`_Sys_Card`): that is the **framework-application prefix**, owned by `app/RSpade/Sys/` and refused in `rsx/` (`NAME-RESERVED-01`).

The manifest discovers them with zero registration, and an **undefined uppercase tag already renders** (as a div carrying the component name as a class), so a page can be scaffolded out of names before a single one exists.

Reference files in this skill, one level deep:

- `reference/template-syntax.md` - every template construct, attribute rules, the corruption gotchas
- `reference/lifecycle-and-state.md` - the six stages in full, worked state examples, loading patterns
- `reference/component-api-events.md` - DOM/component access, events, dynamic creation
- `reference/inheritance-and-slots.md` - `extends=`, abstract bases, passing data into slots
- `reference/semantic-composition.md` - designing a page as a composition of named concepts
- `reference/dynamic-tags-and-printers.md` - ADVANCED and narrow: `<{expr}>` dynamic component names and `add_object_printer()`. Both exist for declared TEXT column types (`rsx:man text_types`) and essentially nothing else - a plain conditional is the right answer everywhere a component's identity is known

The authoritative contract is `php artisan rsx:man jqhtml`; the page-composition method is `php artisan rsx:man semantic_composition`.

## The hook decision matrix

Most component bugs are code in the wrong hook. Find the row that matches your intent:

| I want to... | Hook | Why |
|---|---|---|
| Set `this.data`/`this.state` defaults | `on_create` | Before first render |
| Register a persistent component-level handler (`this.on/once`) | `on_create` | Persists across renders; `on_ready()` risks infinite loops from event replay |
| Fetch data | `on_load` | Only place `this.data` is writable post-create; NO DOM or child access here |
| Format/decorate the component's OWN just-rendered markup | `on_render` | Fires ~instantly after draw, even on a cached paint - formatting here means the cached copy already looks right; idempotent, own-template only |
| Bind a handler to the component's OWN markup (its elements, not child components, and needing nothing the load has not delivered) | `on_render` - one delegated handler on `this.$`, or `.off('x.ns').on('x.ns')` on a `$sid` element | The cached paint is interactive at once; `on_ready` would leave it dead until the load finishes |
| Call a function on a first-generation child built to be timing-indifferent (`Form_Input_Abstract.val()`) | `on_render` (cautiously) | Only if the child's own contract supports pre-ready calls; else `on_ready` |
| Interact with a child that has no such contract (call methods, read state, bind its events), or bind a handler that needs a child or the loaded data | `on_ready` | Children guaranteed ready, and `this.data` final, only here |
| Read the finalized/correct `this.data` for post-draw logic | `on_ready` | `on_render` may see a stale cached stub or default `this.data` |
| Start a socket/subscription/observer | `on_ready` | One-time, needs a stable tree |
| Teardown (sockets, observers, timers) | `on_stop` | Symmetric with `on_ready` setup |
| Work that should PAUSE while the component is out of the document and resume when it returns (a poll, a window listener) | `on_attach` / `on_detach` | Fire on every entry to / exit from the document; `on_attach` always sees a ready component and can run many times |

One exception to the last-but-one row: a **realtime** subscription is placed in `on_create()` so the first load is gated on the subscription being live - see the realtime skill.

## Designing for the cache

A component with `on_load()` is CACHED: revisit it with the same arguments and jqhtml paints the
last `this.data` at once, then runs `on_load()` and repaints only if the answer changed
(stale-while-revalidate). That is what makes an SPA revisit instant, and it is lost silently -
nothing throws. Five rules keep a component cacheable, each for a reason:

1. **Arguments identify, they do not carry.** Primitives, or small plain objects/arrays of
   primitives (keyed by CONTENT, so a template rebuilding `{status: 'open'}` every render still
   hits). A function, class instance, jQuery/DOM object, circular value or anything over 500
   bytes has no stable key: the component renders uncached and is marked
   `data-nocache="<arg>:<reason>"`, and a development console warning names the arg. Pass ids,
   not records.
2. **A function argument is legitimate - and obliges `cache_id()`.** A callback (`$on_select=`)
   is real identity content cannot express, so the component states its identity itself:
   `cache_id() { return 'contact_picker_' + this.args.client_id; }`. `cache_id()` REPLACES the
   whole key, so it names everything that changes what `on_load()` fetches - and nothing that
   does not. It carries a `CACHE CONTRACT` comment in `on_create()` (the shape is in
   `reference/lifecycle-and-state.md`): change the args and `cache_id()` must change with them,
   or the cache serves another invocation's data. Uncached is an acceptable LAST resort for a
   component with no clean identity - say so in one comment, so `data-nocache` reads as a
   decision rather than an accident. A STATIC
   class method is already keyable: RSpade tags every one with `_jqhtml_cache_id`
   (`'Class.method'`), so `$data_source=My_Controller.fetch` caches; an arrow function,
   `this.handler` or a bound method has no such tag. An object an application builds can carry
   its own `_jqhtml_cache_id` property (or `jqhtml_cache_id()` method) - defined NON-enumerable
   (`Object.defineProperty`), or it rides into any payload the object is spread into.
3. **`this.data` holds what `on_load()` fetched, and nothing a stale copy would make lie.** A
   `loading` flag, a form seed, a selection or a "settled" marker cached from the last visit
   is wrong during the next visit's revalidation: `this.state` or a plain instance property.
4. **The template is a pure function of `this.args` + `this.data`**, so the cached paint is
   correct before revalidation. Formatting of the component's own markup goes in `on_render()`
   (which runs on the cached paint), never `on_ready()` (which waits for the load): otherwise
   the cached copy flashes unformatted.
5. **Server hints `refresh()`; the user's own action `reload()`.** `refresh()` repaints only
   when the data changed, so a realtime frame with no real change costs no repaint.

Load DEDUPLICATION (identical components loading at the same moment share one `on_load()`) is
stricter: it needs primitive arguments or `_jqhtml_cache_id`, and `cache_id()` does NOT apply to
it - a deduplicated follower never revalidates, so a wrong key there would be permanently
wrong data. Non-primitive arguments therefore cache but do not share loads; that is expected.

Find every uncached component on a page: `php artisan rsx:debug /path --user=1 --eval="return
\$('[data-nocache]').map((i, e) => e.className.split(' ')[0] + ' ' + e.getAttribute('data-nocache')).get()"`.
A component with no `on_load()` is never cached and never needs to be.

## Complexity tiers

Pick the lowest tier that does the job. Reaching for a higher tier than the component needs is the most common source of unnecessary jqhtml code.

**Static** - template-only, no `.js` file. Pure display from `this.args`. Most components in a well-composed app are this tier.

**Simple** - `on_load()` fetches into `this.data`; when `this.args` change, set them and call `reload()`.

**Complex** - `on_load()` for initial/cached data, then jQuery DOM manipulation for incremental mutations after initialization (appending a row rather than repainting the list).

**Standalone (last resort, docblock-declared)** - the full contract, all of which is mandatory:

- **NO `on_load()` and no participation in the load pipeline.** One explicit fetch method the component calls itself.
- **All state lives in `this.state`.** This tier is the sanctioned exception to "render from `this.data`" - do NOT "fix" a declared Standalone component into `on_load()` conformance.
- **Imperative render into stable shell-template `$sid` containers.** The template renders a shell of empty containers once; JS paints into them. A templateless Standalone component has no `$sid` at all - use in-memory element references, **never data-\* tagging**.
- **Justified only** by one of two shapes, where a template re-render is the wrong tool:
  - **Stateful imperative DOM** - drag-and-drop, inline editing, context menus: the DOM holds state between renders that a re-render would drop.
  - **A live, frequently-updated view** - a terminal's output, a log tail, a chat transcript, a progress feed: the view grows a line at a time or changes several times a second, and repainting it for each update costs the reader their scroll position and selection on top of the work. The working test: updates arrive more often than about once a second, or the view grows by appending. Most pages are not this: a page whose content rarely changes refetches and repaints on a realtime frame (`refresh()`), and that is correct.
- **`on_ready()` is the component's `$(document).ready()`.** From it the component does everything a jQuery plugin would: its own Ajax, its own event binding on `this.$sid(...)` (namespaced and idempotent - `.off('click.ns').on('click.ns', ...)`), and its own changes to the page with `.append()` / `.hide()` / `.show()` / `.text()`. The `.jqhtml` is static markup to be manipulated, not a function of data.
- **Keeping it current is the component's own job.** A stateful-DOM component **MUST hand-roll stale-while-revalidate**: `Rsx_Storage` session tier, write-through, `deep_equal` skip when nothing changed. A stream reads from a CURSOR (the last id it has shown) so a repeated or overlapping read is harmless, and routes every read through ONE core `debounce()`. Either way the method that brings it up to date is **UNIQUELY NAMED and public** (`read_new()`, `reload_board()`) - never an override of a reserved name like `refresh()` or `reload()`.
- **Nothing may re-render it.** `reload()` on the component, or a re-render of its PARENT, destroys it and builds a new one from nothing - scrollback, cursor and handlers gone. A host that must react to something calls the component's own method.
- **Cleanup is `on_stop()`**: observers, timers a library started, anything the framework did not create. A subscription made with `this.subscribe()` stops itself.
- **The docblock declares the tier**, so the next reader knows the deviation is deliberate.

```javascript
/**
 * Kanban_Board - drag-and-drop board.
 * @tier Standalone - card positions are DOM state between renders; a template
 *       re-render would drop the drag session. State in this.state; painted
 *       into the shell $sid containers; revalidate via reload_board().
 */
```

The stream shape, worked through in full (template, class, the cursor read, the host action that embeds it): `reference/lifecycle-and-state.md`, "A component that works like a jQuery plugin". The framework's own instance is `system/app/RSpade/Core/Task/ui/Task_Output.js`, a task's output in a terminal.

## Simple components: JS inside the template

When behavior is a few lines, write it in the template and skip the `.js` file entirely.

```jqhtml
<Define:CSV_Renderer>
  <%
    if (!this.args.csv_data) throw new Error('csv_data required');   // fail loud in the template
    const rows = this.args.csv_data.split('\n').map(r => r.split(','));
    this.toggle = () => { this.args.expanded = !this.args.expanded; this.render(); };
  %>
  <table>
    <% for (let row of rows) { %>
      <tr><% for (let cell of row) { %><td><%= cell %></td><% } %></tr>
    <% } %>
  </table>
  <button @click=this.toggle>Toggle View</button>
</Define:CSV_Renderer>
```

**Inline JS** for simple transformations, loops and basic handlers. **A `.js` file** once the JS overwhelms the template, needs external data, or holds multiple methods and real state.

Validating args in the template is the encouraged fail-loud idiom: `<% if (!this.args.user_id) throw new Error('user_id required'); %>`.

## Dynamic component creation

```javascript
// Destroys the existing component (if any) and creates a new one in its place
$(selector).component('Component_Name', { arg1: value1, arg2: value2 });

// Render a component into a container this component owns
this.$sid('result_container').component('My_Component', { data: my_data, some_option: true });
```

**Use the RETURNED element.** `.component(name, args)` returns the element the component lives on - and when the component declares a tag the host does not have (a `tag="form"` component on a `<div>`) and the host is in the document, that is a NEW element: the host is replaced (attributes, id, classes, `.data()` and inner HTML copied), and the old reference is a discarded node whose `.component()` is null. Chain it - `const form = $slot.component('Rsx_Form', args).component();` - or re-query by `$sid`/id; never read the instance back through the original reference. A host not in the document keeps its tag and only warns.

**Class preservation**: only component names (a capital first letter, optionally preceded by a single underscore, no `__`) are replaced. Utility classes (`text-muted`), BEM child classes (`Parent__child`) and **all attributes** are preserved. So a container can carry layout/utility classes safely across repeated `.component()` calls.

Awaiting a dynamically created component: `await $(selector).component().ready()`. This is rarely needed inside a component (`on_ready()` already waits for children created during render) - it exists for dynamically created components and for Blade page JS reaching into a component.

## Incremental scaffolding

**Undefined components work immediately** - they render as a div with the component name as a class. So the honest way to build a page is to write the composition first and define the pieces afterwards:

```blade
<Dashboard>
  <Stats_Panel />
  <Recent_Activity />
</Dashboard>
```

Nothing breaks, the page renders, and each name becomes a real component when you get to it. Combined with automatic SCSS scoping on the component class, this is what makes semantic composition cheaper than copy-pasting markup.

## Pitfalls

Remedies included - most of these are silent, not thrown.

1. **`<Define>` IS the element, not a wrapper.** Use the `tag=""` attribute to choose the rendered element; do not wrap it in another div. `tag=""` is **never inherited** - every `<Define:>` extending a base must repeat it or silently render a `div`.
2. **`this.data` starts as `{}`.** Set defaults in `on_create()`; it is writable ONLY in `on_create()` and `on_load()` and frozen everywhere else.
3. **`on_load()` may read `this.args` and write `this.data`, and nothing else.** DOM access, `this.state` and modifying `this.args` all throw at runtime.
4. **NEVER call `this.render()` in `on_load()`** - the automatic re-render already happens when `this.data` changes.
5. **`this.state` is for UI state; `this.args` + `reload()` is for re-fetching.** Never re-fetch into `this.data` from an event handler.
6. **Use `Controller.method()`, never `$.ajax()`** - `#[Ajax_Endpoint]` methods are auto-callable from JS, and `$.ajax()` throws.
7. **`on_create`, `on_render`, `on_stop`, `on_attach` and `on_detach` must be synchronous.** `on_load`, `on_load_error`, `on_loaded` and `on_ready` may be async. A throwing `on_load()` calls `on_load_error(error)`: not overridden, the error is printed and a failed boot STOPS the component (a failed `reload()`/`refresh()` rejects); override it to write fallback `this.data` and carry on (`reference/lifecycle-and-state.md`).
8. **`this.sid()` returns a component; `$(el).component()` returns a component; `this.$sid()` returns a jQuery element.** Mixing them up produces "not a function" at the call site.
9. **`@click` goes on child elements, NOT on `<Define>`** (Define attributes are component args, not DOM attributes). For a root-element click: `<% this.$.click(() => { ... }); %>`.
10. **Wrapper components must render `<%= content() %>`** or the caller's child content is silently dropped.
11. **Never put `<% %>` inside a quoted attribute value** - `class="x<% if (c) { %>y<% } %>"` is a COMPILE ERROR (`<% %> code blocks are not allowed inside attribute values`), so this one fails loudly rather than corrupting silently. Build the value in a `<% %>` block, then `class="<%= cls %>"`. Conditional logic toggles a WHOLE attribute *between* attributes: `<% if (cond) { %>attr="val"<% } %>`.
12. **`<pre>` and `<textarea>` are raw-content elements, and both accept `<%= %>` (jqhtml >= 2.3.59).** `<pre>` additionally accepts nested markup, so `<pre><code><%= this.data.src %></code></pre>` - the standard code-block idiom - compiles, and its interior whitespace is preserved byte for byte. `<textarea>` still takes text only: a nested element inside one is a compile error, because its HTML content model is text. Neither accepts a `<% %>` code block - their content is one flat string with nowhere to write output, so build the value first and interpolate it.
13. **An unquoted `$arg=expression` ends at the first space unless it is bracketed.** `$alert=_x > 0` fails; `$alert=(_x>0)` and `$alert=(_x > 0)` both work, because the parser tracks the parentheses. Wrap any expression containing spaces, or precompute it in a `<% %>` block.
14. **`render()` destroys child DOM.** Every child element and child component is recreated, and DOM handlers on them are lost - re-register in `on_render()` (namespaced and idempotent), or bind ONE delegated handler on `this.$`, which survives the component's own re-renders. For ONE data-driven fragment, mark it `$redrawable $sid="x"` and call `this.render('x')` instead - only that element redraws.
15. **`on_render` fires more than once.** Namespace every DOM bind (`.off('click.ns').on('click.ns', ...)`) and guard every DOM injection against duplicate appends.
16. **Do not render display templates from a `this.state` copy of `this.data`.** The post-load re-render is gated on the cache serializer's text of `this.data` changing, so such a template goes stale after a save that returns identical values.
17. **Never tag imperative DOM with `data-id`.** `$sid` is template-only, `data-*` belongs to the args machinery, and the jquery layer THROWS on the setter form. Use in-memory element references (a Map) or CSS classes.
18. **Never name a custom event `create`/`load`/`loaded`/`ready`/`render`/`rendered`/`stop`**, and never shadow a component method name (`reload`/`refresh`/`render`/`redraw`/`stop`/`ready`/`rendered`/`gate_load`/`sid`/`$sid`/`closest`/`find`/`instantiator`/`on`/`once`/`trigger`/`invalidate` + the lifecycle hooks, `on_load_error` included, plus `subscribe`: RSpade assigns it to `Component.prototype` at framework-modules init, so it is present on every component whether or not realtime is enabled). Both collide silently with framework behavior.
19. **Never fire custom events with `this.$.trigger()`** - use `this.trigger()` (enforced by `JQHTML-EVENT-01`).
20. **Iterate with the page renderer, not the lint suite.** `php artisan rsx:debug /path --user=1` surfaces compile, SCSS and runtime errors immediately; run the full `rsx:check` once at the end. For live timing/ordering bugs a single render pass cannot show (double-render, hook order, slow renders), `jqhtml.enableDebugMode('basic')` in the browser console logs each phase with timestamps.
21. **An unkeyable argument silently turns caching off.** A function, class instance, DOM/jQuery object, circular value or anything over 500 bytes - see "Designing for the cache" above for the rules and `cache_id()`.
22. **A tag is a component only if it starts with a capital, optionally preceded by a single underscore.** `<user_card>` is never a component no matter what is registered - it renders as a literal unknown HTML element with no scoping, lifecycle or `Component` class (`JQHTML-CLASS-01`). `<_Sys_Card>` IS one, but the prefix is the framework's: an application may not declare it (`NAME-RESERVED-01`).
23. **No inline `<script>` or `<style>` tags in a `.jqhtml` template** (`JQHTML-INLINE-01`). Behavior goes in the companion `.js` or a `<% %>` block; styling goes in the component's `.scss`. The compiler rejects the TEXT `<script` / `</script` ANYWHERE in a template - a JS string, an attribute value, an HTML comment - because the compiled template may be inlined in a `<script>` block; split a string that must hold it (`'<' + '/script>'`). `tag="script"` / `tag="style"` is a compile error too.
24. **`on_stop()` fires for every removal through jQuery or the framework** - `.remove()`, `.empty()`, `.html(x)`, `.text(x)`, `.replaceWith()`, a parent's re-render - descendants included. It does NOT fire for NATIVE DOM removal (`element.remove()`, `innerHTML = ''`) or for jQuery `.detach()`; application code uses the jQuery and component APIs, so that is a third-party library's doing. Such a component still fires `detach`. Put cleanup in `on_stop()`; reach for `on_attach()` / `on_detach()` only for work that should pause while the component is out of the document. **Realtime needs neither**: `this.subscribe()` in `on_create()` is released on its own, with or without an `on_stop()`.
25. **`attach` is not `ready`, and `on_attach()` can run many times.** `attach` means in the document AND ready - first after `on_ready()`, then on every return to the document. One-time setup stays in `on_create()` / `on_ready()`; wait for a component with `ready()`. `is_attached()` is the live answer and is correct in `on_create()` too.
26. **The lifecycle lock: a `render()`/`reload()`/`refresh()`/`load()` called from `on_ready()` runs AFTER `on_ready()` returns** (unless awaited, which runs it at once), and `ready` fires only once every queued call has run. So an `on_ready()` that unconditionally re-renders its own component loops forever and never reaches `ready` - nor does any parent waiting on it: show loaded values from the template, never by re-rendering from `on_ready()`. Never AWAIT one inside `on_loaded()` (a permanent hang), and never call one from `on_create()`/`on_render()` (it warns and runs later). Calls are not debounced: each runs, in call order, and only an identical call still waiting merges. Full rules: `reference/lifecycle-and-state.md`, "The lifecycle lock".
27. **An ARRAY prints as the literal text `(Array)`** under `<%= %>`, `<%!= %>` and `<%br= %>` - join it (`<%= tags.join(', ') %>`) or loop. `content()` and a slot return a markup object (empty content is still `''`), which interpolation splices.
