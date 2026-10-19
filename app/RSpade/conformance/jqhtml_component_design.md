---
id: jqhtml_component_design
title: jqhtml components are cacheable, use the right hooks and the right tier
severity: medium
when: launch, change
scope: rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man jqhtml, skill rspade:jqhtml, skill rspade:rsx-debug, skill rspade:realtime, conformance reload_vs_refresh
---

## The contract

A jqhtml component is built so that a revisited screen paints instantly from cache and responds at once, re-renders only when something changed, and never loses the user's place. Every defect this audit looks for degrades that silently: the component still works, so nothing throws, no test fails and manual testing passes - the page is just slower, flickers, drops a scroll position or a selection, or shows stale state. That is why it is audited and not linted.

The canonical design, and the reasons for it, are in skill `rspade:jqhtml` - "The hook decision matrix", "Designing for the cache" and "Complexity tiers" - with the depth in its `reference/lifecycle-and-state.md` and the contract in `php artisan rsx:man jqhtml` (CACHING AND CACHEABLE DESIGN, COMPONENT COMPLEXITY TIERS, EVENT HANDLER PLACEMENT). Read the skill before judging a component, and point every finding's fix at the section that governs it. `reload()` versus `refresh()` has its own audit (`reload_vs_refresh`) and is not repeated here.

## Find every instance

    grep -rlE "extends (Component|Spa_Action|Spa_Layout|[A-Za-z_]+_Abstract)\b" rsx --include='*.js' | grep -v '^rsx/tests/'

Read each class with its `.jqhtml` (and the class it extends). Then sweep the rendered pages: for every SPA route in `rsx/` (`grep -rn "@route(" rsx --include='*.js'`), as a user who can open it,

    php artisan rsx:debug <path> --user=<id> --eval="return $('[data-nocache]').map((i, e) => e.className.split(' ')[0] + ' ' + e.getAttribute('data-nocache')).get()"

and note the console's `[JQHTML] <Name> cannot be cached` warnings. Every entry is an uncached component that loads; each must be either fixed or justified (below). A portal route is swept with `--portal --portal-user=<id>`.

## What does not conform

Cacheability - a component with `on_load()` that cannot paint from cache, or paints wrong from it:

- **An unkeyable argument with no `cache_id()`.** A `data-nocache` entry whose argument is an arrow function, `this.handler`, a bound method, a record object, a jQuery/DOM object or a large payload. The component refetches behind a loading state on every revisit. Fix: pass ids or small plain data instead of objects; pass a static `Controller.method` (keyable) instead of a closure; or define `cache_id()` (skill `rspade:jqhtml`, "Designing for the cache").
- **A `cache_id()` that misses part of the identity** - it omits an argument that changes what `on_load()` fetches, so two different invocations share one entry and the cache paints the wrong record until revalidation. Or one that includes noise (a timestamp, a random value), which never hits.
- **A `cache_id()` with no `CACHE CONTRACT` comment in `on_create()`** - the next person to add an argument has no warning that the key must change with it.
- **A deliberately uncached component with no comment saying why** - indistinguishable from an accident.
- **An application object carrying an enumerable `_jqhtml_cache_id`** - it rides into any Ajax payload the object is spread into; it must be defined non-enumerable.
- **`this.data` holding something that is not a load result**: a `loading`/`saving`/`settled` flag, a form seed, a selection, a mode, a timer. The cache restores it from the previous visit and it lies during the revalidation. It belongs in `this.state` or an instance property.
- **A template that is not a pure function of `this.args` + `this.data`** - it reads `this.state` copies of loaded data, globals, `Date.now()`, or DOM set by earlier code - so the cached paint shows something other than what the load will show, or goes stale after a save that returns identical values.
- **Formatting the component's own markup in `on_ready()`** (number/date formatting, toggling classes from data, truncation, building a list imperatively from `this.data`) - the cached paint shows unformatted markup until the load finishes, then jumps. It belongs in `on_render()` (idempotent) or in the template itself.

Hooks - code in the wrong place:

- **A handler on the component's own markup bound in `on_ready()`** when it needs no child component and no final data - the cached copy on screen ignores clicks until the load finishes. It belongs in `on_render()`, preferably ONE delegated handler on `this.$`.
- **A DOM bind in `on_render()` that is not idempotent** - no `.off('x.ns')` before `.on('x.ns')`, so handlers stack and fire twice on the second render. Or one guarded by a one-shot `this._wired` flag, which double-binds after a parent repaint and suppresses a rebind the root needed.
- **Touching a child component in `on_render()`** whose API is not documented as timing-indifferent (only `Form_Input_Abstract.val()` / `focus()` are), or binding a child component's events anywhere but `on_ready()`.
- **A persistent `this.on(...)` registered in `on_ready()`** - event replay can loop when the handler re-renders; it belongs in `on_create()`.
- **Fetching outside `on_load()`** - an Ajax call in `on_create()`, `on_render()`, `on_ready()` or an event handler that writes the result into the display (save handlers and Standalone components excepted). It bypasses the cache, deduplication and the post-load render; a refetch is `reload()`.
- **Independent calls awaited in sequence in `on_load()`**, or an `await` inside a loop over independent items - one `Promise.all`, with a `.catch` on every non-fatal branch so a failing side list cannot blank the record.
- **A loading indicator drawn imperatively** (in `on_load()` - which throws - or once in `on_ready()`): it is wiped by the post-load re-render or shown after loading is over. Loading state is drawn from state in `on_render()` or the template.
- **Cleanup missing from `on_stop()`** for something the framework did not create (an observer, an interval, a library instance, a document-level handler), or work that should pause off-page done without `on_attach()`/`on_detach()`.

Tier - the wrong shape for the job:

- **A live view re-rendered per update**: a component or action whose content changes more than about once a second or grows by appending (a terminal, a log tail, a transcript, a live progress feed) that runs `on_load()` and repaints - on a realtime frame or a timer - for each update. It costs the work of the whole view per line and the reader's scroll position and selection. It is the Standalone stream shape: static template, no `on_load()`, `on_ready()` as `$(document).ready()`, cursor reads through one `debounce()`, `.append()` (skill `rspade:jqhtml`, "A component that works like a jQuery plugin").
- **A Standalone component that is not justified** - no `on_load()` and imperative painting for a component that is neither stateful-DOM nor a live stream. It gives up the cache and the loading/error handling for nothing; it belongs in the Simple or Complex tier.
- **A Standalone component missing its contract**: no `@tier Standalone` docblock; a refresh method named `refresh`/`reload`/`render`; a stateful-DOM component with no hand-rolled stale-while-revalidate; a stream with no cursor (a repeated read appends duplicates); an Ajax read that touches the page after `on_stop()`.
- **A host that re-renders a Standalone child** - calls `reload()` (or re-renders) the parent around a stream or stateful-DOM component, which destroys it and builds an empty one.
- **A Complex-tier component whose incremental DOM edits are the only record of a change** - the next render (its own or a parent's) wipes them because `this.data` never learned of it. The next load must deliver the same state.

Template and API misuse that the above often travels with:

- Reserved method or event names shadowed (`refresh`, `reload`, `render`, `ready`, `loaded`, ...); custom events fired with `this.$.trigger()`.
- Imperative DOM tagged with `data-*` attributes or selected by `data-sid`/`data-cid`; `$sid` targets rendered conditionally.
- `.html()` with interpolated data, or markup built by string concatenation from data, where `.text()` or a template belongs.

Not findings:

- A component with no `on_load()` that carries `data-nocache` - it is never cached and the marker does not appear for it in practice; recheck the class.
- A plain-object argument that caches but does not share concurrent loads (deduplication needs primitives) - expected.
- A declared Standalone component rendering from `this.state` and binding in `on_ready()` - that is its contract.
- A handler in `on_ready()` that needs a child component or the final loaded data.
- Framework components under `system/` (out of scope).

## Report

`PASS - <one line: N components read, M routes swept, all conform>`, or one finding per line:

    file:line - what is wrong - the conforming fix (and the skill section that governs it)

No other prose.
