# jqhtml Lifecycle and State

## The six stages

1. **`on_create()`** - set defaults (sync): `this.data.rows = []; this.data.loading = true;`
2. **render** - the template executes (top-down: parent before children)
3. **`on_render()`** - fires after render, BEFORE children are ready (top-down, sync)
4. **`on_load()`** - fetch data into `this.data` (bottom-up, parallel siblings, async)
5. **`on_loaded()`** - runs on the real component (not a detached proxy)
6. **`on_ready()`** - all children guaranteed ready (bottom-up, async)

Plus **`on_stop()`** - teardown when the component is destroyed (sync).

**Double-render**: if `on_load()` modifies `this.data`, the component renders twice (defaults -> populated). `on_ready()` fires once, after the final render.

**The full sequence, spelled out** (owner-stated, 2026-08-27): `on_create` (no DOM,
sync) -> render + `on_render` (sync — with no loaded data, or with cached data on a
cache hit) -> `on_load` (async, runs to completion) -> if the loaded data differs from
what rendered, render + `on_render` again (sync) -> wait for every child component to
reach its own ready state -> `on_ready`.

**`on_ready` means FULLY LOADED AND READY — a loading indicator there is a
contradiction in terms.** By the time it fires, `on_load()` has completed and every
child is ready; anything that must exist *while* loading (an overlay, a spinner, a
disabled state) is wired in `on_render()` — driven by STATE, because renders rebuild
the DOM and an imperatively-drawn indicator silently dies on the post-load re-render.
The shape: a flag set in `on_create()`/cleared when data lands, and `on_render()`
draws or removes the indicator to match. (`Rsx_Form.set_loading()` /
`_sync_loading_overlay()` is the reference implementation.)

## on_render - the full contract

Fires after render, BEFORE children are ready (top-down, sync). **May fire more than once**: a cached-stub render, the post-`on_load()` re-render, and every `render()`/`redraw()`/`reload()`.

**Own-template only by default**: read, format, and bind handlers to raw HTML nodes owned directly by this component (no other component boundary between them and the root). Because it re-fires, **any DOM handler bind MUST be idempotent**:

```javascript
on_render() {
    this.$sid('row').off('click.mycmp').on('click.mycmp', () => this._open());
}
```

The `create -> render -> on_render` chain is synchronous and recurses through the whole subtree until it hits a component with an async `on_load()`. So a parent CAN touch a first-generation child in its own `on_render()` - **but only if that child's API is deliberately timing-indifferent** (safe before AND after the child loads; `Form_Input_Abstract.val()` buffers when not-ready). A child with no such contract must wait for `on_ready()`.

**Cached-component caveat**: a dynamic child's cache-hit state is NOT exposed by any API or event, so never assume `on_render` sees final data. The child may have painted nothing yet, or painted a stale-while-revalidate cache stub that `on_load()` will still reconcile. If your logic needs finalized `this.data`, it belongs in `on_ready()`.

## on_loaded

Runs on the real component, not the detached proxy. `this.data` is frozen (read-only) there, but `this.$`, `this.state` and `this.args` are accessible. Use it sparingly, for one-off post-load setup that needs `this.$`/`this.state` access.

**Render from `this.data` directly.** Clone `this.data` -> `this.state` only when the user is about to edit and later save (see Display vs. edit). The post-load re-render is gated on `JSON.stringify(this.data)` changing, so a template reading from a `this.state` copy of `this.data` goes stale on a reload that returns identical values.

## The state triad

**`on_load()` runs on a DETACHED PROXY and may only touch `this.args` and `this.data`**
- calling any method or reaching the DOM from it throws
(`[JQHTML] Cannot access ... during on_load()`). Imperative post-load work belongs in
`on_loaded()` (the real component) or `on_ready()`; render-coupled work belongs in
`on_render()` driven by state.

**`this.args`** - component arguments (read-only in `on_load()`, modifiable everywhere else)
**`this.data`** - the MAYBE-CACHED result of `on_load()` (writable ONLY in `on_create()`
and `on_load()`; frozen everywhere else - the framework THROWS on any other write)
**`this.state`** - developer-owned scratch, initialized `{}`; no framework semantics,
no caching, writable anywhere EXCEPT inside `on_load()` (the detached proxy reaches
only `args` and `data`)

**What `this.data` actually IS - and when NOT to use it.** `this.data` is not "the
component's data"; it is specifically the cached-able output of the load cycle. On a
repeat invocation of the same component the framework may serve a CACHED copy of it
and render before `on_load()` revalidates - that is the feature. Two consequences:

1. Anything you put in `this.data` may come back STALE on a later visit, describing
   the previous life of the component. A flag like `record_loading: false` cached
   from a finished visit LIES during the next visit's in-flight revalidation.
2. Anything that is not genuinely a load result - form seed values, in-progress
   selections, UI mode flags - does not belong there. Use `this.state`: it is born
   fresh per instance, carries no caching semantics, and the framework never touches
   it. (Form seeds in particular: `<Rsx_Form $data=this.state.form_data>` - a form
   silently re-seeded from a cache is exactly the bug class the form contract exists
   to prevent.)

Decision rule: **"did `on_load()` fetch this, and is a stale cached copy acceptable
until revalidation?" -> `this.data`. Everything else -> `this.state` (cross-render)
or a plain instance property (per-instance, e.g. a settled/loading flag that must
never be cached).**
**`this.state`** - arbitrary component state, no framework meaning (modifiable anytime)

**Quick guide:**
- Loading from an API? -> `this.data` in `on_load()`
- Need a reload with different params? -> modify `this.args`, call `reload()` (PREFERRED)
- Reload would cost unnecessary requests or lose user input? -> track it in `this.state`
- UI state (toggles, selections)? -> `this.state`
- No dynamic data loads at all? -> `this.state` for everything

```javascript
// WITH Ajax data
class Users_List extends Component {
    on_create() {
        this.data.users = [];                                    // defaults
    }
    async on_load() {
        this.data.users = await User_Controller.fetch({filter: this.args.filter});
    }
    on_ready() {
        this.args.filter = 'new';                                // change filter ->
        this.reload();                                           // re-run on_load()
    }
}

// WITHOUT Ajax data
class Toggle_Button extends Component {
    on_create() {
        this.state = {is_open: false};
    }
    on_ready() {
        this.$.on('click', () => { this.state.is_open = !this.state.is_open; });
    }
}
```

## on_load() restrictions (enforced at runtime)

```javascript
async on_load() {
    // [OK] ALLOWED - read this.args, write this.data
    this.data = await Controller.method({filter: this.args.filter});

    // [NO] FORBIDDEN - no DOM access, no this.state, no modifying this.args
    this.$sid('element').text();   // Runtime error
    this.state.count = 5;          // Runtime error
    this.args.filter = 'new';      // Runtime error
}
```

**NEVER call `this.render()` in `on_load()`** - the automatic re-render happens.

## Loading pattern

```javascript
async on_load() {
    const result = await Product_Controller.list({page: 1});
    this.data.products = result.products;
    this.data.loaded = true;      // simple flag, set at the END
}
```

```jqhtml
<% if (!this.data.loaded) { %>
    Loading...
<% } else { %>
    <%-- show data --%>
<% } %>
```

Setting the flag last means a partially-populated `this.data` can never paint as if it were complete. (Page-level actions use the richer three-state loading/error/content pattern - see the crud-patterns skill.)

## Display vs. edit (architectural rule)

**`this.data` is the source of truth for display** - render templates from `this.data` directly, never from a `this.state` copy of it.

**`this.state` is the editor buffer**: when the user starts editing data the component owns, clone `this.data` -> `this.state`, mutate `this.state` freely, then save through a controller and call `this.reload()` to refetch `this.data` and discard `this.state`.

```javascript
// Editor pattern - clone on first edit, save+reload discards state
async on_load() { this.data.values = await Foo_Controller.list(); }
_ensure_editing() { if (!this.state.edits) this.state.edits = clone(this.data.values); }
async _save() { await Foo_Controller.save_all(this.state.edits); this.state.edits = null; this.reload(); }
// Template: <% const rows = this.state.edits || this.data.values; %>
```

The framework skips the post-`on_load` re-render when `JSON.stringify(this.data)` is unchanged, so a display path that reads from `this.state` will not refresh after a save that returns identical values.

**Exception**: a component whose docblock declares the **Standalone** tier renders from `this.state` by design - do not "fix" it into `on_load()` conformance.

## AJAX placement

Component AJAX belongs in `on_load()` (save handlers excepted). **Never re-fetch into `this.data` from an event handler** - call `this.reload()` instead. `reload()` restores `this.data` to the `on_create()` snapshot then re-runs `on_load()` only - **`on_create()` is NOT called again** - which keeps the framework's caching, freezing and re-render heuristics aligned with what the template sees.

```javascript
async add_item() {
    await Controller.add({name: 'Test'});
    this.reload();   // refreshes this.data via on_load(), reattaches handlers via on_ready()
}
```

The server round-trip is intentional: it is what guarantees the component shows what the server actually stored.

**EXCEPTION**: realtime/subscription callbacks use `refresh()`, not `reload()` - see the realtime skill for why (a content-free notification must not strobe the page). `refresh()` is equally correct for ordinary polling.

## Two hooks the six stages do not cover

**`gate_load(promise)`** - called in `on_create()` (repeatable; all gates awaited together), it holds
this component's FIRST `on_load()` until the gates settle. First paint still happens immediately - a
gate delays data, never render. Gates are one-shot: `reload()`/`refresh()` never re-await them, and
either one called while gated releases the wait. A rejected gate is logged and the load proceeds.
This is the mechanism behind "gate the first load on a live subscription" - never hand-roll a delay.

**`on_viewport_resize(viewport_width)`** - not a stage, a notification. Fires (sync) after every
`on_render()`, after every `on_ready()`, and on window resize debounced 30ms; the argument is
`window.innerWidth`. **Never bind `$(window).on('resize')` yourself** - the framework owns one
listener for the whole page and there is nothing to unbind. Prefer CSS media/container queries;
override only when layout needs real measurement (canvas sizing, chart redraw, virtual scrolling).

## Caching and deduplication

The skill's `on_render` cache-stub warnings presuppose this model:

- Components with the same name and the same args loading at the same time share **one** `on_load()`
  call. Automatic, always on. Fewer network requests than component instances is expected.
- **Caching and deduplication key differently, and this is deliberate.** CACHING keys plain data by
  CONTENT (jqhtml >= 2.3.54): null, booleans, numbers, strings, Dates, arrays and plain objects, in
  any nesting, so `$filters={status:'open'}` rebuilt on every render still hits the same entry. It
  DECLINES - never silently degrades - on a function, a class instance, a DOM/jQuery object, a
  circular structure, or anything over 500 bytes, marking the element
  `data-nocache="<arg>:<reason>"`. A key that dropped a callback would let two different arg sets
  share an entry and serve the wrong content, which is worse than not caching.
- DEDUPLICATION is stricter and does NOT use content keys: it needs primitive args, or a
  `_jqhtml_cache_id` property on the object. A deduplicated follower skips `on_load()` entirely with
  no revalidation, so a wrong key there is permanently wrong data - redundant requests are the
  cheaper failure. This is why a non-primitive arg still opts out of shared loads (SKILL.md
  pitfall 21) even though it now caches.
- `cache_id()` can be overridden to control the persisted cache key. It does **not** affect
  deduplication, which always keys off raw `this.args`.

## Parallel loads

**Independent server calls in one `on_load()` go under a single `Promise.all`.** A chain of
sequential `await`s is what an author writes when nothing says otherwise, and it costs real
wall-clock time: four serial round-trips take four times one round-trip with the loading state
on screen the whole while.

Every **non-fatal** branch carries its own `.catch` so one failure cannot reject the batch. The
record the page IS about stays fatal - no `.catch` on it.

```javascript
async on_load() {
    const [record, notes, tags] = await Promise.all([
        Record_Controller.get({id: this.args.id}),                        // fatal: the page IS this record
        Note_Controller.list({record_id: this.args.id}).catch(() => []),  // non-fatal: degrade to empty
        Tag_Controller.list({record_id: this.args.id}).catch(() => []),
    ]);
    this.data.record = record;
    this.data.notes = notes;
    this.data.tags = tags;
}
```

**Why the per-branch `.catch` is not optional** (verified 2026-09-02 against jqhtml core 2.3.59):
an `on_load()` that rejects aborts the whole load. `this.data` stays at its `on_create()` defaults
- including the branches that SUCCEEDED - the component never reaches `on_loaded()`/`on_ready()`,
and the rejection surfaces through the unhandled-exception handler. A sidebar list that 500s must
not blank the record the page exists to show.

**The one genuine exception - sequence only when the second call's ARGUMENTS come from the first
call's RESULT:**

```javascript
async on_load() {
    const project = await Project_Controller.get({id: this.args.id});
    const client = await Client_Controller.get({id: project.client_id});   // needs project.client_id
    this.data.project = project;
    this.data.client = client;
}
```

**The false dependency: "parallel loads, then a join."** Results that are merged, counted or
cross-referenced *after* they all arrive are not dependent - combining them after the fact is
exactly what `Promise.all` produces. The test is the ARGUMENTS, never the result.

**The loop shape is the same mistake, better hidden.** An `await` inside a `for` over independent
items is N serial round-trips:

```javascript
// [NO] one round-trip per item, in series
for (const id of this.args.ids) {
    this.data.rows.push(await Row_Controller.get({id: id}));
}

// [OK] map to promises, await the batch
this.data.rows = await Promise.all(this.args.ids.map((id) => Row_Controller.get({id: id})));
```

A per-item loop is also the hint that a BATCH endpoint belongs on the server: one call taking a
list of ids beats N parallel calls.

## What survives a re-render

Verified 2026-09-02 by live probe against jqhtml core 2.3.59. "Own `render()`" covers `render()`,
`redraw()` and the automatic post-`on_load()` re-render.

| | own `render()` | `reload()` | parent re-render |
|---|---|---|---|
| component instance | same | same | **destroyed, new one** |
| `this.$` element identity | same | same | **new element** |
| plain instance property | kept | kept | gone with the instance |
| `this.state` | kept | kept | gone with the instance |
| `this.data` | kept | **rebuilt** [1] | rebuilt on the new instance |
| handler bound on `this.$` | **kept** | **kept** | gone with the element |
| handler bound on a child element | lost | lost | lost |
| `$sid` child elements | recreated | recreated | recreated |

A render clears the root element's `innerHTML` and re-executes the template: the root element
object is the SAME object, every descendant is a new one. That single fact explains the last three
rows - a handler on `this.$` survives, a handler on `this.$sid('x')` does not.

A parent's `render()` calls `_stop()` on every descendant component and then clears its own DOM, so
the child is a NEW INSTANCE afterwards, running `on_create()` -> `on_load()` again (subject to the
component cache). `this.state` and any plain instance property die with the old instance.

`stop()` is the outlier: it leaves the DOM and the instance in place - it halts the lifecycle and
fires `on_stop()`. State and handlers are still there; the component just stops responding to
lifecycle calls.

[1] `reload()` restores `this.data` to the `on_create()` snapshot and then lets `on_load()` write
it. A component that overrides NO `on_load()` skips the fetch entirely and `reload()` degenerates
into a plain re-render, leaving `this.data` untouched.

## Delegated handlers

Bind delegated handlers **namespaced and idempotent**, on the component root:

```javascript
on_render() {
    this.$.off('click.mycmp').on('click.mycmp', '.My_Cmp__row', (event) => this._open($(event.currentTarget)));
}
```

**Never guard a bind with a one-shot instance flag** - `if (!this._wired) { this._wired = true; ... }`.
The flag lives on the INSTANCE; the handler lives on the ELEMENT, and the survival table above shows
those lifetimes are not the same one. A parent repaint hands the successor a fresh flag, so a handler
bound on anything that OUTLIVED the instance - `document`, the SPA layout, any surviving ancestor -
gets bound a second time and now fires twice; meanwhile on the component's own `render()` the flag
suppresses a rebind the surviving root element genuinely needed. The `.off('.ns').on('.ns')` pair
needs no flag at all: it is idempotent by construction and correct in both worlds.

**Content you hand to another component is still yours.** Everything written in a `<Slot:x>`
body - or in any content passed to a child - resolves against the DEFINING component: `<%= %>`
expressions, template locals, `$sid` ids (the definer's cid is baked in at compile time) and
handler expressions alike. `@click=this.method` in a slot body runs your method with `this` =
your component, never the component the markup is rendered inside. Write `this.method` and
`this.$sid('x')` directly; a `Spa.action()` detour from inside a slot body is never needed.

## Re-render methods

- **`reload()`** - reset `this.data` to `on_create()` defaults -> `on_load()` -> `render()` -> `on_ready()`. Use when `this.args` changed or data must be refetched. Debounced: rapid repeated calls coalesce into ONE execution.
- **`refresh()`** - `reload()` that SKIPS the re-render and `on_ready()` when `this.data` came back unchanged. The right tool for any polling or interval refresh, not just realtime callbacks - it is what prevents flicker on every tick.
- **`load()`** - re-runs `on_load()` only: no render, no `on_ready()`. Returns `true`/`false` for whether `this.data` changed, so you decide what to redraw next.
- **`render()` / `redraw()`** - re-execute the template -> wait for children -> `on_ready()`. Does NOT re-run `on_load()`. UI-only updates.
- **`render('sid')`** - re-render ONLY the element carrying that `$sid`, which must be marked `$redrawable` in the template. Child DOM elsewhere is untouched - use for counters, badges and live fragments instead of a full `render()`.
- **`stop()`** - destroy the component and all children; calls `on_stop()` if defined. `on_stop()` is NOT guaranteed to run when a node is removed outside the framework.

`render()` and `reload()` invalidate the sticky `ready` state first, so a `.ready()`/`.on('ready')` registered mid-cycle waits for the NEW render instead of resolving against the old one.

**`render()` destroys child DOM**: all child elements and child components are recreated. DOM event handlers on children are lost and must be re-registered in `on_render()` (namespaced, idempotent) or `on_ready()`.
