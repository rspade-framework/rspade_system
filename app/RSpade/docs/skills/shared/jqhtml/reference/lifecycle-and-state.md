# jqhtml Lifecycle and State

## The six stages

1. **`on_create()`** - set defaults (sync): `this.data.rows = []; this.data.loading = true;`
2. **render** - the template executes (top-down: parent before children)
3. **`on_render()`** - fires after render, BEFORE children are ready (top-down, sync)
4. **`on_load()`** - fetch data into `this.data` (bottom-up, parallel siblings, async)
5. **`on_loaded()`** - runs on the real component (not a detached proxy)
6. **`on_ready()`** - all children guaranteed ready (bottom-up, async)

Plus **`on_stop()`** - teardown when the component is destroyed (sync) - and **`on_attach()`** / **`on_detach()`** - the root entered / left the document (sync; see "In and out of the document").

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

**Own-template only by default**: read, format, and bind handlers to raw HTML nodes owned directly by this component (no other component boundary between them and the root). **This is where formatting and own-markup handlers belong** - it runs on the cached paint, so the cached copy looks right and responds to clicks at once; the same code in `on_ready()` would leave it unformatted and dead until the load finished. A handler that needs a child component or the FINAL loaded data goes in `on_ready()`. Because `on_render` re-fires, **any DOM handler bind MUST be idempotent**:

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

## A component that works like a jQuery plugin (the Standalone tier, stream shape)

The lifecycle - fetch in `on_load()`, render from `this.data`, refetch and repaint when a realtime frame arrives - is right for almost every screen, because almost every screen shows content that rarely changes. It fails one kind: a view that GROWS BY APPENDING. A terminal showing a task's live output receives a line at a time; rendering it from data would rebuild the entire scrollback for each line, throw away where the reader had scrolled to, and drop any text they had selected.

For that kind of screen the component is written the way a jQuery plugin is written, and the lifecycle is deliberately not used:

| The usual component | The jQuery-plugin shape |
|---|---|
| `on_load()` fetches into `this.data` | NO `on_load()`; the component calls its own endpoint when it wants to |
| the template is a function of `this.data` | the template is STATIC markup, drawn once |
| `on_ready()` wires what the render produced | `on_ready()` is `$(document).ready()`: everything starts here |
| a realtime frame -> `refresh()` -> repaint | a realtime frame -> read what is new -> `.append()` it |
| state the display depends on is `this.data` | all of it is `this.state` (and plain instance fields for element references) |
| `reload()` when args change | never reloaded; a public method of its own changes what it shows |

**The template** is a shell with named containers and nothing data-driven:

```jqhtml
<%--
Log_Tail - the live tail of one log, appended as lines arrive.
$log_id (required) - which log.
--%>
<Define:Log_Tail tag="div">
    <div class="Log_Tail__toolbar">
        <button type="button" class="btn btn-sm btn-secondary" $sid="pause">Pause</button>
        <button type="button" class="btn btn-sm btn-secondary" $sid="clear">Clear</button>
        <span class="Log_Tail__status" $sid="status"></span>
    </div>
    <div class="Log_Tail__empty" $sid="empty">Waiting for output...</div>
    <div class="Log_Tail__lines" $sid="lines"></div>
</Define:Log_Tail>
```

**The class** does its own loading, its own events and its own DOM changes:

```javascript
/**
 * Log_Tail - the live tail of one log.
 *
 * @tier Standalone - an append-only stream. Lines are APPENDED as they arrive; the history
 *       is never re-rendered, because that would rebuild the whole scrollback per line and
 *       lose the reader's place. No on_load(); state in this.state; on_ready() starts it;
 *       brought up to date by read_new().
 */
class Log_Tail extends Component {
    on_create() {
        if (!this.args.log_id) {
            throw new Error('Log_Tail requires $log_id');
        }

        this.state.last_id = 0;       // the cursor: the newest line already on screen
        this.state.paused = false;
        this.state.count = 0;

        // ONE debounce for every read: the first runs at once, reads asked for while one is in
        // flight coalesce into a single follow-up, and two never overlap.
        this._read_soon = debounce(() => this.__read(), 250);
    }

    // The component's $(document).ready(): the static template is on the page, start working.
    on_ready() {
        const that = this;

        // Events: namespaced and idempotent, on the template's own elements.
        this.$sid('pause').off('click.log_tail').on('click.log_tail', function () {
            that.set_paused(!that.state.paused);
        });
        this.$sid('clear').off('click.log_tail').on('click.log_tail', function () {
            that.$sid('lines').empty();
            that.$sid('empty').show();
            that.state.count = 0;
        });

        // The first read, then one more for every frame. The cursor makes a repeated read
        // harmless, which is what lets the subscription's resync and the first read overlap.
        this.read_new();
        this.subscribe('Log_Line_Topic', { log_id: int(this.args.log_id) }, () => that.read_new());
    }

    /** Bring the tail up to date. The public, uniquely-named way to make this component look. */
    read_new() {
        if (!this.state.paused) {
            this._read_soon();
        }
    }

    set_paused(paused) {
        this.state.paused = paused;
        this.$sid('pause').text(paused ? 'Resume' : 'Pause');
        if (!paused) {
            this.read_new();           // catch up on what arrived meanwhile
        }
    }

    // Its own Ajax: everything after the cursor, appended. Nothing is re-rendered.
    async __read() {
        const lines = await Log_Controller.lines_after({ log_id: int(this.args.log_id), after: this.state.last_id });

        if (this._stopped || !lines.length) {
            return;
        }

        const $lines = this.$sid('lines');
        const el = $lines[0];
        // Follow the output only if the reader is at the bottom; never yank them down.
        const at_bottom = el.scrollHeight - el.scrollTop - el.clientHeight < 4;

        for (const line of lines) {
            $lines.append($('<div class="Log_Tail__line">').text(line.text));   // .text(): escaped
            this.state.last_id = line.id;
        }

        this.state.count += lines.length;
        this.$sid('empty').hide();
        this.$sid('status').text(this.state.count + ' lines');

        if (at_bottom) {
            el.scrollTop = el.scrollHeight;
        }
    }

    on_stop() {
        // Read after an await above: a read that returns after the component is gone must not
        // touch the page. The subscription stops itself; nothing else here needs releasing.
        this._stopped = true;
    }
}
```

**The host** treats it as a black box. An action that embeds it keeps its OWN `on_load()` for the page's ordinary data, and must not repaint around the stream:

```javascript
class Logs_View_Action extends Spa_Action {
    on_create() {
        // refresh(), never reload(): reload() re-renders this action, which destroys the
        // Log_Tail inside it and builds a new, empty one.
        this.subscribe(Log_Model, this.args.id, () => this.refresh());
    }

    async on_load() {
        this.data.log = await Log_Model.fetch(this.args.id);   // the header, the metadata
    }
}
```

If the action's own data changes often enough that `refresh()` would still repaint the region holding the stream, the ACTION is the stream and takes this shape itself - no `on_load()`, a static template, everything from `on_ready()`.

**What you give up, and must therefore do by hand**: the loading state (show and hide your own placeholder, as `$sid="empty"` above), error display for your own Ajax calls, the stale-data protection `on_load()` gets from the framework (the cursor is the stream's answer; a stateful-DOM component uses the stale-while-revalidate contract in the tier's rules), and cleanup of anything the framework did not create. That is the price, and it is why this shape is the last resort and is declared in the docblock: the next reader must know the missing `on_load()` is the design, not an omission.

**Do not reach for it** because a component "has a lot of jQuery", needs one incremental update (that is the Complex tier: `on_load()` plus DOM manipulation afterwards), or to avoid learning the lifecycle. The test is the cost of a re-render: if repainting the view for every change is acceptable, the lifecycle is the right tool.

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

**Why it matters.** A component with `on_load()` is cached under a key built from its name and
arguments. Revisit it with the same key and jqhtml paints the last `this.data` IMMEDIATELY, then
runs `on_load()` and repaints only if the answer differs (stale-while-revalidate). That is what
makes an SPA revisit instant instead of a spinner, and losing it throws nothing: the component
still works, it just refetches behind a loading state every time. Cacheability is therefore a
design goal for every component that loads, decided by four things the author controls -
arguments, `this.data`, the template and the hook formatting lives in (SKILL.md, "Designing for
the cache"). A component with no `on_load()` is never cached and never needs to be.

**How the key is built.** Plain data is keyed by CONTENT: null, booleans, numbers, strings,
Dates, arrays and plain objects in any nesting (so `$filters={status: 'open'}` rebuilt every
render hits the same entry). It DECLINES - never silently degrades - on a function, symbol,
class instance, Map/Set, DOM or jQuery object, circular structure, invalid Date, or anything
over 500 bytes: the element gets `data-nocache="<arg>:<reason>"` and, in development, the
console says `[JQHTML] <Name> cannot be cached: arg $x is not keyable because ...`. A key that
dropped a callback would let two different argument sets share an entry and serve the wrong
content, which is worse than not caching. A value carrying `_jqhtml_cache_id` (property) or
`jqhtml_cache_id()` (method) is keyed by that instead - and RSpade stamps `'Class.method'` on
every static class method, so `$data_source=My_Controller.fetch` stays keyable while an arrow
function, `this.handler` or a bound method does not.

**`cache_id()` - stating identity by hand.** A component whose arguments cannot be keyed (a
callback is legitimate: `$on_select=this.picked`) defines `cache_id()`, and its return value
REPLACES the whole key (`<Name>::<cache_id>`): name everything that changes what `on_load()`
fetches, and nothing that does not. A throwing `cache_id()` disables caching with its own
warning. Because the contract is easy to break later, the component says so where the next
reader looks:

```javascript
on_create() {
    // =====================================================================
    // CACHE CONTRACT - READ BEFORE CHANGING THIS COMPONENT'S ARGS
    // $on_select is a callback, so jqhtml cannot derive this component's
    // identity; cache_id() below supplies it. IF THE ARGS THIS COMPONENT
    // ACCEPTS EVER CHANGE, cache_id() MUST CHANGE TO MATCH - otherwise the
    // cache serves another invocation's data.
    // =====================================================================
    this.data.rows = [];
}

cache_id() {
    return 'contact_picker_' + this.args.client_id;
}
```

Uncached is an acceptable LAST resort for a component with no clean identity: one comment
saying why, so the `data-nocache` marker reads as a decision. An application object carrying
its own `_jqhtml_cache_id` defines it NON-enumerable
(`Object.defineProperty(obj, '_jqhtml_cache_id', {value: id, enumerable: false})`) - otherwise
`Object.assign`/spread copies it into whatever Ajax payload the object is merged into.

**Deduplication is stricter, on purpose.** Identical components loading at the same moment share
ONE `on_load()` - but only when every argument is a primitive or carries `_jqhtml_cache_id`;
content keys and `cache_id()` do not apply. A deduplicated follower adopts the leader's data with
no revalidation, so a wrong key there would be permanently wrong data and redundant requests are
the cheaper failure. A plain-object argument therefore caches but does not share loads: expected,
not a defect.

**Finding uncached components.** Every component that loads and declined renders with
`data-nocache`; an empty sweep is the healthy state:

```
php artisan rsx:debug /path --user=1 --eval="return $('[data-nocache]').map((i, e) => e.className.split(' ')[0] + ' ' + e.getAttribute('data-nocache')).get()"
```

**What a cache hit means for the hooks.** The cached paint runs `on_render()` with cached
`this.data`; `on_ready()` waits for the revalidated load. So formatting and own-markup handlers
in `on_render()` make the cached copy look and behave right at once, and anything that must see
FINAL data belongs in `on_ready()`. Anything in `this.data` that is not a load result - a
`loading` flag, a form seed - comes back from the cache describing the previous visit: keep it
in `this.state` or an instance property (see "The state triad").

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

The preferred way to handle events on the component's own markup: ONE handler on the root,
delegated to the children, bound in `on_render()` - namespaced and idempotent. The root element
survives the component's own re-renders, so the handler is never lost, and binding in
`on_render()` makes the cached paint interactive:

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
- **`stop()`** - destroy the component and all children: fires `detach` (and `on_detach()`) first when attached, then `on_stop()`, then `stop`. You rarely call it - **every jQuery removal stops the components it removes** (`.remove()`, `.empty()`, `.html(x)`, `.text(x)`, `.replaceWith()`, `.replaceAll()`), as does a parent's re-render. NATIVE DOM removal (`element.remove()`, `innerHTML = ''`) and jQuery `.detach()` do NOT stop anything; a natively removed component still fires `detach`.

### In and out of the document

A component can be alive and not in the document - built on an element not yet inserted, or removed without being stopped.

- **`on_attach()`** - the root is in the document AND the component is ready. First runs after `on_ready()` and the `ready` handlers, then every time the root returns. Sync. **Can run many times** - one-time setup stays in `on_create()` / `on_ready()`.
- **`on_detach()`** - the root left the document, or the component is being stopped while attached (it runs before `on_stop()`). Sync.
- **`is_attached()`** - in the document right now and not stopped. A live answer, correct in `on_create()` too - so it can be true before the first `attach` has fired.
- Events **`attach`** / **`detach`** are the same moments for outside code; they strictly alternate, and a one-step move (`appendTo` elsewhere) is not a transition.

**Where cleanup goes**: `on_stop()` by default. `on_attach()` / `on_detach()` only for work that should PAUSE while out of the document and resume on return (a poll, a window listener):

```javascript
on_attach() { this.state.timer = setInterval(() => this.redraw(), 1000); }
on_detach() { clearInterval(this.state.timer); }
```

Realtime needs none of it: `this.subscribe()` in `on_create()` is open only while the component is in the document and is released when it stops. **`attach` is not `ready`** - wait for a component with `ready()`.

`render()` and `reload()` invalidate the sticky `ready` state first, so a `.ready()`/`.on('ready')` registered mid-cycle waits for the NEW render instead of resolving against the old one.

**`render()` destroys child DOM**: all child elements and child components are recreated. DOM event handlers on children are lost and must be re-registered in `on_render()` (namespaced, idempotent) - or bound once, delegated, on `this.$`, which survives.
